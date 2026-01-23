#!/usr/bin/env python3
"""
Test Panel Integration Module for Steam Emulator Test Tool

This module provides seamless integration between the PyQt5 test tool
and the Test Panel API. It handles:
- Config loading from test tool directory
- Periodic background checking for retests (startup + every 10 minutes)
- Report submission to the API
- PyQt5 signal-based notifications for pending retests

Usage:
    from panel_integration import PanelIntegration

    # In your Controller class __init__:
    self.panel = PanelIntegration(self)
    self.panel.retest_notification.connect(self.on_retests_found)
    self.panel.start_monitoring()

    # To submit results:
    result = self.panel.submit_session('session_results.json')
"""

import os
import sys
import json
import threading
import logging
from urllib.parse import urlparse, urlunparse
from typing import Optional, List, Callable, Any
from datetime import datetime
from dataclasses import dataclass

from PyQt5.QtCore import QObject, pyqtSignal, QTimer

# Try to import the API client from the test tool directory
try:
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    from api_client import TestPanelClient, Config, RetestItem, SubmitResult, ReportLog, UserInfo
except ImportError:
    # Fallback: define minimal classes if import fails
    TestPanelClient = None
    Config = None
    RetestItem = None
    SubmitResult = None
    ReportLog = None
    UserInfo = None

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger('PanelIntegration')

API_URL_ENDPOINT_SUFFIXES = (
    '/api/submit.php',
    '/api/retests.php',
    '/api/tests.php',
    '/api/user.php',
    '/api/logs.php',
    '/api/notifications.php',
)


def normalize_api_url(api_url: str) -> str:
    """Normalize API base URL and strip any trailing endpoint path."""
    if not api_url:
        return ''
    value = api_url.strip()
    if not value:
        return ''

    parsed = urlparse(value)
    if not parsed.scheme:
        parsed = urlparse(f"https://{value}")

    path = (parsed.path or '').rstrip('/')
    lower_path = path.lower()
    if lower_path.endswith('/api'):
        path = path[:-4]
    else:
        for suffix in API_URL_ENDPOINT_SUFFIXES:
            if lower_path.endswith(suffix):
                path = path[:-len(suffix)]
                break
    path = path.rstrip('/')

    return urlunparse((parsed.scheme, parsed.netloc, path, '', '', ''))


@dataclass
class RetestNotification:
    """A retest notification to display to the user."""
    type: str  # 'retest' or 'fixed'
    test_key: str
    test_name: str
    client_version: str
    reason: str
    latest_revision: bool
    commit_hash: Optional[str] = None
    notes: Optional[str] = None  # Admin notes explaining what to retest
    report_id: Optional[int] = None  # Associated report ID
    report_revision: Optional[int] = None  # Report revision when retest was requested


class PanelIntegration(QObject):
    """
    Integrates the Test Panel API with the PyQt5 test tool.

    Emits signals when retests are found, allowing the UI to
    display notifications without blocking the main thread.
    """

    # Signal emitted when pending retests are found
    # Emits a list of RetestNotification objects
    retest_notification = pyqtSignal(list)

    # Signal emitted when a submission completes
    # Emits (success: bool, message: str, report_id: int or None)
    submission_complete = pyqtSignal(bool, str, object)

    # Signal emitted on connection status change
    # Emits (connected: bool, message: str)
    connection_status = pyqtSignal(bool, str)

    # Default config file locations relative to test tool
    CONFIG_FILENAMES = [
        'test_panel_config.json',
        'panel_config.json',
        'api_config.json',
        'config.json',
    ]

    def __init__(self, controller=None, parent=None, auto_load_config=False):
        """
        Initialize the panel integration.

        Args:
            controller: Optional reference to the test tool Controller
            parent: Optional QObject parent
            auto_load_config: If True, try to auto-load config from JSON file (default: False)
        """
        super().__init__(parent)
        self.controller = controller
        self._client: Optional[TestPanelClient] = None
        self._config_path: Optional[str] = None
        self._check_timer: Optional[QTimer] = None
        self._check_interval = 600  # 10 minutes in seconds
        self._last_retests: List[RetestNotification] = []
        self._is_monitoring = False
        self._offline_mode = False  # When True, all API calls are blocked until restart

        # Only try to load config automatically if explicitly requested
        if auto_load_config:
            self._auto_load_config()

    def _find_config_file(self) -> Optional[str]:
        """Search for config file in standard locations."""
        # Get the current working directory (where the app is run from)
        cwd = os.getcwd()
        # Get the test tool directory (where the module file is located)
        tool_dir = os.path.dirname(os.path.abspath(__file__))

        # Search locations - prioritize current working directory
        search_paths = [
            cwd,  # Current working directory (highest priority)
            tool_dir,  # Same directory as test tool module
            os.path.join(cwd, '..'),  # Parent of current directory
            os.path.join(tool_dir, '..'),  # Parent of module directory
            os.path.expanduser('~'),  # Home directory
        ]

        # Remove duplicates while preserving order
        seen = set()
        unique_paths = []
        for p in search_paths:
            normalized = os.path.normpath(p)
            if normalized not in seen:
                seen.add(normalized)
                unique_paths.append(p)

        for directory in unique_paths:
            for filename in self.CONFIG_FILENAMES:
                path = os.path.join(directory, filename)
                if os.path.isfile(path):
                    return path

        return None

    def _auto_load_config(self) -> bool:
        """Try to automatically load config from standard locations."""
        if TestPanelClient is None:
            logger.warning("API client module not available")
            return False

        config_path = self._find_config_file()
        if config_path:
            return self.load_config(config_path)

        logger.info("No config file found. Use create_config() to create one.")
        return False

    def load_config(self, path: str) -> bool:
        """
        Load configuration from a file.

        Args:
            path: Path to config JSON file

        Returns:
            True if config loaded successfully
        """
        if TestPanelClient is None:
            logger.error("API client module not available")
            return False

        try:
            self._client = TestPanelClient.from_config_file(path)
            self._config_path = path
            self._check_interval = self._client.config.check_interval
            logger.info(f"Config loaded from: {path}")
            return True
        except FileNotFoundError:
            logger.error(f"Config file not found: {path}")
            return False
        except ValueError as e:
            logger.error(f"Invalid config: {e}")
            return False
        except Exception as e:
            logger.error(f"Error loading config: {e}")
            return False

    def create_config(self, api_url: str, api_key: str,
                     path: Optional[str] = None, save_to_file: bool = False) -> Optional[str]:
        """
        Create/configure the panel with API settings.

        Args:
            api_url: Base URL of the test panel API
            api_key: User's API key
            path: Optional path to save config (defaults to test tool dir)
            save_to_file: If True, save config to JSON file (default: False)

        Returns:
            Path to created config file if saved, None otherwise
        """
        if TestPanelClient is None:
            logger.error("API client module not available")
            return None

        api_url = normalize_api_url(api_url)
        if not api_url:
            logger.error("API URL is required")
            return None

        # Create client directly with the provided settings
        try:
            from api_client import Config
            config = Config(
                api_url=api_url.rstrip('/'),
                api_key=api_key,
                check_interval=600,
                auto_check_retests=True,
                timeout=30
            )
            self._client = TestPanelClient(config)
            self._check_interval = config.check_interval
            logger.info(f"Panel configured with API URL: {api_url}")
        except Exception as e:
            logger.error(f"Error creating client: {e}")
            return None

        # Optionally save to file for backwards compatibility
        if save_to_file:
            if path is None:
                tool_dir = os.path.dirname(os.path.abspath(__file__))
                path = os.path.join(tool_dir, 'test_panel_config.json')

            config_data = {
                'api_url': api_url.rstrip('/'),
                'api_key': api_key,
                'check_interval': 600,
                'auto_check_retests': True,
                'timeout': 30
            }

            with open(path, 'w', encoding='utf-8') as f:
                json.dump(config_data, f, indent=2)

            logger.info(f"Config saved to: {path}")
            self._config_path = path
            return path

        return None

    @property
    def is_configured(self) -> bool:
        """Check if the client is configured and ready."""
        return self._client is not None

    @property
    def is_monitoring(self) -> bool:
        """Check if periodic monitoring is active."""
        return self._is_monitoring

    @property
    def is_offline(self) -> bool:
        """Check if offline mode is active (no API calls until restart)."""
        return self._offline_mode

    def set_offline_mode(self, offline: bool = True) -> None:
        """
        Enable or disable offline mode.

        When offline mode is enabled, all API calls are blocked until the
        application is restarted (cold start). This prevents unnecessary
        network requests when the user chooses to work offline.

        Args:
            offline: True to enable offline mode, False to disable
        """
        self._offline_mode = offline
        if offline:
            # Stop monitoring when going offline
            self.stop_monitoring()
            logger.info("Offline mode enabled - all API calls blocked until restart")
        else:
            logger.info("Offline mode disabled")

    def test_connection(self) -> bool:
        """
        Test the API connection.

        Returns:
            True if connected successfully
        """
        if self._offline_mode:
            self.connection_status.emit(False, "Offline mode - restart to reconnect")
            return False

        if not self.is_configured:
            self.connection_status.emit(False, "Not configured")
            return False

        try:
            connected = self._client.test_connection()
            if connected:
                self.connection_status.emit(True, "Connected")
            else:
                self.connection_status.emit(False, "Connection failed")
            return connected
        except Exception as e:
            self.connection_status.emit(False, str(e))
            return False

    def start_monitoring(self, interval_seconds: Optional[int] = None):
        """
        Start periodic monitoring for retests.

        Performs an immediate check on startup, then checks
        periodically based on the configured interval.

        Args:
            interval_seconds: Optional override for check interval
        """
        if self._offline_mode:
            logger.warning("Cannot start monitoring: offline mode is active")
            return

        if not self.is_configured:
            logger.warning("Cannot start monitoring: not configured")
            return

        if interval_seconds:
            self._check_interval = interval_seconds

        # Stop any existing timer
        self.stop_monitoring()

        # Do immediate check
        self._check_for_retests()

        # Setup periodic timer (QTimer runs on the main thread)
        self._check_timer = QTimer(self)
        self._check_timer.timeout.connect(self._check_for_retests)
        self._check_timer.start(self._check_interval * 1000)  # Convert to ms

        self._is_monitoring = True
        logger.info(f"Started monitoring (interval: {self._check_interval}s)")

    def stop_monitoring(self):
        """Stop periodic monitoring for retests."""
        if self._check_timer:
            self._check_timer.stop()
            self._check_timer = None
        self._is_monitoring = False
        logger.info("Stopped monitoring")

    def _check_for_retests(self):
        """Check for pending retests and emit signal if found."""
        if self._offline_mode:
            return

        if not self.is_configured:
            return

        try:
            items = self._client.get_retest_queue()

            if items:
                # Convert to notification objects
                notifications = [
                    RetestNotification(
                        type=item.type,
                        test_key=item.test_key,
                        test_name=item.test_name,
                        client_version=item.client_version,
                        reason=item.reason,
                        latest_revision=item.latest_revision,
                        commit_hash=item.commit_hash,
                        notes=item.notes,
                        report_id=item.report_id,
                        report_revision=item.report_revision
                    )
                    for item in items
                ]

                self._last_retests = notifications
                self.retest_notification.emit(notifications)
                logger.info(f"Found {len(notifications)} pending retest(s)")
        except Exception as e:
            logger.error(f"Error checking retests: {e}")

    def check_retests_now(self) -> List[RetestNotification]:
        """
        Check for retests immediately (synchronously).

        Returns:
            List of RetestNotification objects
        """
        self._check_for_retests()
        return self._last_retests

    def get_cached_retests(self) -> List[RetestNotification]:
        """Get the last fetched retests without making a new request."""
        return self._last_retests

    def submit_session(self, session_file: str = 'session_results.json',
                      async_submit: bool = True) -> Optional[SubmitResult]:
        """
        Submit a session results file to the API.

        Args:
            session_file: Path to session_results.json
            async_submit: If True, submit in background thread

        Returns:
            SubmitResult if sync, None if async (result via signal)
        """
        if self._offline_mode:
            error = "Offline mode - restart to submit"
            if async_submit:
                self.submission_complete.emit(False, error, None)
                return None
            return SubmitResult(success=False, error=error)

        if not self.is_configured:
            result = SubmitResult(success=False, error="Not configured")
            if async_submit:
                self.submission_complete.emit(False, "Not configured", None)
                return None
            return result

        # Resolve path relative to test tool if needed
        if not os.path.isabs(session_file):
            tool_dir = os.path.dirname(os.path.abspath(__file__))
            session_file = os.path.join(tool_dir, session_file)

        if not os.path.exists(session_file):
            error = f"Session file not found: {session_file}"
            if async_submit:
                self.submission_complete.emit(False, error, None)
                return None
            return SubmitResult(success=False, error=error)

        if async_submit:
            # Submit in background thread
            thread = threading.Thread(
                target=self._async_submit,
                args=(session_file,),
                daemon=True
            )
            thread.start()
            return None
        else:
            # Synchronous submit
            return self._do_submit(session_file)

    def _do_submit(self, session_file: str) -> SubmitResult:
        """Perform the actual submission."""
        try:
            result = self._client.submit_report(session_file, verbose=True)
            return result
        except Exception as e:
            return SubmitResult(success=False, error=str(e))

    def _async_submit(self, session_file: str):
        """Background thread submission handler."""
        result = self._do_submit(session_file)

        if result.success:
            message = f"Report #{result.report_id} submitted successfully"
            self.submission_complete.emit(True, message, result.report_id)
        else:
            self.submission_complete.emit(False, result.error or "Unknown error", None)

    def get_retests_for_version(self, client_version: str) -> List[RetestNotification]:
        """
        Get retests filtered by client version.

        Args:
            client_version: The client version to filter by

        Returns:
            List of RetestNotification objects for that version
        """
        if self._offline_mode:
            return []

        if not self.is_configured:
            return []

        try:
            items = self._client.get_retest_queue(client_version)
            return [
                RetestNotification(
                    type=item.type,
                    test_key=item.test_key,
                    test_name=item.test_name,
                    client_version=item.client_version,
                    reason=item.reason,
                    latest_revision=item.latest_revision,
                    commit_hash=item.commit_hash,
                    notes=item.notes,
                    report_id=item.report_id,
                    report_revision=item.report_revision
                )
                for item in items
            ]
        except Exception as e:
            logger.error(f"Error getting retests for version: {e}")
            return []

    def get_tests(self, enabled_only: bool = True, client_version: str = None):
        """
        Get test types and categories from the API.

        Args:
            enabled_only: If True, only return enabled tests
            client_version: Optional client version string to get version-specific template tests

        Returns:
            TestsResult object with tests grouped by category, or None if not configured
        """
        if self._offline_mode:
            logger.warning("Cannot get tests: offline mode is active")
            return None

        if not self.is_configured:
            logger.warning("Cannot get tests: not configured")
            return None

        try:
            return self._client.get_tests(enabled_only, client_version)
        except Exception as e:
            logger.error(f"Error getting tests from API: {e}")
            return None

    def get_user_info(self) -> Optional[str]:
        """
        Get the username of the authenticated user from the API.

        Returns:
            Username string if successful, None otherwise
        """
        if self._offline_mode:
            logger.warning("Cannot get user info: offline mode is active")
            return None

        if not self.is_configured:
            logger.warning("Cannot get user info: not configured")
            return None

        try:
            result = self._client.get_user_info()
            if result and result.success:
                return result.username
            else:
                error = result.error if result else "Unknown error"
                logger.warning(f"Failed to get user info: {error}")
                return None
        except Exception as e:
            logger.error(f"Error getting user info from API: {e}")
            return None

    def get_user_info_full(self):
        """
        Get the full user info including revisions from the API.

        Returns:
            UserInfo object if successful, None otherwise
        """
        if self._offline_mode:
            logger.warning("Cannot get user info: offline mode is active")
            return None

        if not self.is_configured:
            logger.warning("Cannot get user info: not configured")
            return None

        try:
            result = self._client.get_user_info()
            if result and result.success:
                return result
            else:
                error = result.error if result else "Unknown error"
                logger.warning(f"Failed to get user info: {error}")
                return None
        except Exception as e:
            logger.error(f"Error getting user info from API: {e}")
            return None

    def get_report_logs(self, report_id: int) -> List:
        """
        Get list of log files attached to a report.

        Args:
            report_id: The report ID

        Returns:
            List of ReportLog objects (without data)
        """
        if self._offline_mode:
            logger.warning("Cannot get report logs: offline mode is active")
            return []

        if not self.is_configured:
            logger.warning("Cannot get report logs: not configured")
            return []

        try:
            return self._client.get_report_logs(report_id)
        except Exception as e:
            logger.error(f"Error getting report logs: {e}")
            return []

    def download_report_log(self, log_id: int, decompress: bool = True) -> Optional[str]:
        """
        Download a specific log file.

        Args:
            log_id: The log file ID
            decompress: If True, decompress and return as string

        Returns:
            Log content as string, or None on error
        """
        if self._offline_mode:
            logger.warning("Cannot download log: offline mode is active")
            return None

        if not self.is_configured:
            logger.warning("Cannot download log: not configured")
            return None

        try:
            return self._client.download_report_log(log_id, decompress)
        except Exception as e:
            logger.error(f"Error downloading log: {e}")
            return None

    def save_report_log(self, log_id: int, output_path: str) -> bool:
        """
        Download and save a log file to disk.

        Args:
            log_id: The log file ID
            output_path: Path to save the log file

        Returns:
            True if successful
        """
        if self._offline_mode:
            logger.warning("Cannot save log: offline mode is active")
            return False

        if not self.is_configured:
            logger.warning("Cannot save log: not configured")
            return False

        try:
            return self._client.save_report_log(log_id, output_path)
        except Exception as e:
            logger.error(f"Error saving log: {e}")
            return False

    def delete_report_log(self, log_id: int) -> bool:
        """
        Delete a log file from a report.

        Args:
            log_id: The log file ID to delete

        Returns:
            True if successful
        """
        if self._offline_mode:
            logger.warning("Cannot delete log: offline mode is active")
            return False

        if not self.is_configured:
            logger.warning("Cannot delete log: not configured")
            return False

        try:
            return self._client.delete_report_log(log_id)
        except Exception as e:
            logger.error(f"Error deleting log: {e}")
            return False

    def find_report_id(self, tester: str, client_version: str, test_type: str):
        """
        Find the report ID for a given tester, client version, and test type.

        Args:
            tester: The tester's username
            client_version: The client version ID
            test_type: The test type (WAN or LAN)

        Returns:
            The report ID if found, None otherwise
        """
        if self._offline_mode:
            logger.warning("Cannot find report: offline mode is active")
            return None

        if not self.is_configured:
            logger.warning("Cannot find report: not configured")
            return None

        try:
            return self._client.find_report_id(tester, client_version, test_type)
        except Exception as e:
            logger.error(f"Error finding report: {e}")
            return None

    @staticmethod
    def compress_log_file(file_path: str) -> dict:
        """
        Compress a log file for inclusion in a report submission.

        Args:
            file_path: Path to the log file

        Returns:
            Dict with log metadata suitable for inclusion in attached_logs array
        """
        if TestPanelClient is None:
            logger.error("API client module not available")
            return {}

        try:
            return TestPanelClient.compress_log_file(file_path)
        except Exception as e:
            logger.error(f"Error compressing log file: {e}")
            return {}


# Convenience function to integrate with existing test tool
def setup_panel_integration(controller) -> Optional[PanelIntegration]:
    """
    Convenience function to set up panel integration with the test tool.

    Usage in main.py Controller.__init__:
        from panel_integration import setup_panel_integration
        self.panel = setup_panel_integration(self)

    Args:
        controller: The test tool Controller instance

    Returns:
        PanelIntegration instance or None if not available
    """
    if TestPanelClient is None:
        logger.warning(
            "API client not available. Install requests: pip install requests"
        )
        return None

    panel = PanelIntegration(controller)

    if panel.is_configured:
        # Start monitoring automatically if configured
        panel.start_monitoring()
        logger.info("Panel integration initialized and monitoring started")
    else:
        logger.info(
            "Panel integration ready but not configured. "
            "Create test_panel_config.json to enable API features."
        )

    return panel


# Example callback handler for the test tool
def show_retest_dialog(retests: List[RetestNotification], parent=None):
    """
    Show a dialog with pending retests.
    Can be connected to PanelIntegration.retest_notification signal.

    Args:
        retests: List of RetestNotification objects
        parent: Parent widget for the dialog
    """
    from PyQt5.QtWidgets import QMessageBox, QTextEdit, QVBoxLayout, QDialog, QPushButton, QHBoxLayout

    if not retests:
        return

    dialog = QDialog(parent)
    dialog.setWindowTitle(f"Pending Retests ({len(retests)})")
    dialog.setMinimumSize(550, 400)

    layout = QVBoxLayout(dialog)

    text = QTextEdit()
    text.setReadOnly(True)

    # Build HTML content with admin notes
    html = """
    <style>
        .retest-item { margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-radius: 5px; }
        .retest-header { font-weight: bold; font-size: 14px; margin-bottom: 5px; }
        .retest-meta { font-size: 12px; color: #666; }
        .admin-notes { background: #fff3cd; border-left: 3px solid #ffc107; padding: 8px; margin-top: 8px; font-size: 12px; }
        .admin-notes-label { font-weight: bold; color: #856404; font-size: 11px; text-transform: uppercase; }
        .warning { color: #d32f2f; font-weight: bold; }
    </style>
    <h3>The following tests need retesting:</h3>
    """

    for r in retests:
        icon = "🔄" if r.type == "retest" else "✅"
        html += f'<div class="retest-item">'
        html += f'<div class="retest-header">{icon} Test {r.test_key}: {r.test_name}</div>'
        html += f'<div class="retest-meta">'
        html += f'Version: {r.client_version}<br>'
        html += f'Reason: {r.reason}'
        if r.report_id:
            revision_info = f" (revision {r.report_revision})" if r.report_revision is not None else ""
            html += f'<br>Report ID: #{r.report_id}{revision_info}'
        if r.report_revision is not None:
            html += f'<br><b>Test with revision: {r.report_revision}</b>'
        if r.latest_revision:
            html += '<br><span class="warning">⚠️ Please use latest emulator revision</span>'
        if r.commit_hash:
            html += f'<br>Fix commit: <code>{r.commit_hash}</code>'
        html += '</div>'

        # Display admin notes if present
        if r.notes:
            html += '<div class="admin-notes">'
            html += '<div class="admin-notes-label">📝 Admin Notes:</div>'
            # Escape HTML and convert newlines
            notes_escaped = r.notes.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
            notes_html = notes_escaped.replace('\n', '<br>')
            html += f'{notes_html}'
            html += '</div>'

        html += '</div>'

    text.setHtml(html)
    layout.addWidget(text)

    # Add OK button
    button_layout = QHBoxLayout()
    ok_button = QPushButton("OK")
    ok_button.clicked.connect(dialog.accept)
    button_layout.addStretch()
    button_layout.addWidget(ok_button)
    layout.addLayout(button_layout)

    dialog.exec_()


if __name__ == '__main__':
    # Test the integration module standalone
    from PyQt5.QtWidgets import QApplication
    import sys

    app = QApplication(sys.argv)

    panel = PanelIntegration()

    if panel.is_configured:
        print("Config loaded successfully")
        print(f"Testing connection...")

        if panel.test_connection():
            print("Connection successful!")

            print("Checking for retests...")
            retests = panel.check_retests_now()

            if retests:
                print(f"\nFound {len(retests)} pending retest(s):")
                for r in retests:
                    print(f"  - Test {r.test_key}: {r.test_name}")
                    print(f"    Version: {r.client_version}")
                    print(f"    Reason: {r.reason}")
            else:
                print("No pending retests.")
        else:
            print("Connection failed!")
    else:
        print("Not configured. Create a config file:")
        print("  panel.create_config('http://localhost/test_api', 'sk_your_key')")
