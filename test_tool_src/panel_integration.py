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

from PyQt5.QtCore import QObject, pyqtSignal, QTimer, QThread, QMutex, QWaitCondition

# Try to import the API client from the test tool directory
try:
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    from api_client import TestPanelClient, Config, RetestItem, SubmitResult, ReportLog, UserInfo, VersionsResult
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
    commit_hash: Optional[str] = None  # Fix commit (for 'fixed' type)
    notes: Optional[str] = None  # Admin notes explaining what to retest
    report_id: Optional[int] = None  # Associated report ID
    report_revision: Optional[int] = None  # Report revision when retest was requested
    tested_commit_hash: Optional[str] = None  # Commit hash the test was originally submitted against


class ApiWorker(QThread):
    """
    Background worker thread for API operations.

    Handles all network requests in a separate thread to prevent
    the UI from freezing during API calls.
    """

    # Signals for operation results - each emits (operation_id, success, result_or_error)
    tests_result = pyqtSignal(str, bool, object)  # get_tests result
    versions_result = pyqtSignal(str, bool, object)  # get_versions result
    user_info_result = pyqtSignal(str, bool, object)  # get_user_info result
    logs_result = pyqtSignal(str, bool, object)  # get_report_logs result
    log_download_result = pyqtSignal(str, bool, object)  # download_report_log result
    log_delete_result = pyqtSignal(str, bool, object)  # delete_report_log result
    connection_result = pyqtSignal(str, bool, str)  # test_connection result
    flags_result = pyqtSignal(str, bool, object)  # check_flags result
    acknowledge_result = pyqtSignal(str, bool, object)  # acknowledge_flag result
    hash_check_result = pyqtSignal(str, bool, object)  # check_hashes result
    retests_result = pyqtSignal(str, bool, object)  # get_retest_queue result
    generic_result = pyqtSignal(str, bool, object)  # generic operation result

    # Signal for worker errors
    error_occurred = pyqtSignal(str, str)  # (operation_id, error_message)

    def __init__(self, parent=None):
        super().__init__(parent)
        self._task_queue = []
        self._mutex = QMutex()
        self._condition = QWaitCondition()
        self._stop = False
        self._client = None

    def set_client(self, client):
        """Set the API client to use for requests."""
        self._client = client

    def stop(self):
        """Stop the worker thread."""
        self._mutex.lock()
        self._stop = True
        self._condition.wakeAll()
        self._mutex.unlock()
        self.wait()

    def queue_task(self, operation: str, operation_id: str, **kwargs):
        """
        Queue a task for background execution.

        Args:
            operation: The operation name (e.g., 'get_tests', 'get_versions')
            operation_id: A unique ID to identify this request in callbacks
            **kwargs: Arguments to pass to the operation
        """
        self._mutex.lock()
        self._task_queue.append({
            'operation': operation,
            'operation_id': operation_id,
            'kwargs': kwargs
        })
        self._condition.wakeAll()
        self._mutex.unlock()

        # Start thread if not running
        if not self.isRunning():
            self.start()

    def run(self):
        """Main worker loop - processes tasks from the queue."""
        while True:
            self._mutex.lock()

            # Wait for tasks or stop signal
            while not self._task_queue and not self._stop:
                self._condition.wait(self._mutex)

            if self._stop:
                self._mutex.unlock()
                break

            if self._task_queue:
                task = self._task_queue.pop(0)
            else:
                task = None

            self._mutex.unlock()

            if task:
                self._process_task(task)

    def _process_task(self, task: dict):
        """Process a single task."""
        operation = task['operation']
        operation_id = task['operation_id']
        kwargs = task['kwargs']

        if not self._client:
            self.error_occurred.emit(operation_id, "API client not configured")
            return

        try:
            if operation == 'get_tests':
                result = self._client.get_tests(
                    enabled_only=kwargs.get('enabled_only', True),
                    client_version=kwargs.get('client_version'),
                    use_cache=kwargs.get('use_cache', True)
                )
                self.tests_result.emit(operation_id, result.success, result)

            elif operation == 'get_versions':
                result = self._client.get_versions(
                    enabled_only=kwargs.get('enabled_only', True),
                    include_notifications=kwargs.get('include_notifications', False),
                    use_cache=kwargs.get('use_cache', True)
                )
                self.versions_result.emit(operation_id, result.success, result)

            elif operation == 'get_user_info':
                result = self._client.get_user_info()
                self.user_info_result.emit(operation_id, result.success if result else False, result)

            elif operation == 'get_report_logs':
                report_id = kwargs.get('report_id')
                logs = self._client.get_report_logs(report_id)
                self.logs_result.emit(operation_id, True, logs)

            elif operation == 'download_report_log':
                log_id = kwargs.get('log_id')
                decompress = kwargs.get('decompress', True)
                content = self._client.download_report_log(log_id, decompress)
                self.log_download_result.emit(operation_id, content is not None, content)

            elif operation == 'delete_report_log':
                log_id = kwargs.get('log_id')
                success = self._client.delete_report_log(log_id)
                self.log_delete_result.emit(operation_id, success, None)

            elif operation == 'test_connection':
                connected = self._client.test_connection()
                msg = "Connected" if connected else "Connection failed"
                self.connection_result.emit(operation_id, connected, msg)

            elif operation == 'check_flags':
                result = self._client.check_flags()
                self.flags_result.emit(operation_id, result.get('success', False), result)

            elif operation == 'acknowledge_flag':
                flag_type = kwargs.get('flag_type')
                flag_id = kwargs.get('flag_id')
                success = self._client.acknowledge_flag(flag_type, flag_id)
                self.acknowledge_result.emit(operation_id, success, None)

            elif operation == 'check_hashes':
                hashes = kwargs.get('hashes')
                tester = kwargs.get('tester')
                test_type = kwargs.get('test_type')
                commit_hash = kwargs.get('commit_hash')
                result = self._client.check_hashes(hashes, tester, test_type, commit_hash)
                self.hash_check_result.emit(operation_id, result.success, result)

            elif operation == 'get_retest_queue':
                client_version = kwargs.get('client_version')
                items = self._client.get_retest_queue(client_version)
                self.retests_result.emit(operation_id, True, items)

            elif operation == 'find_report_id':
                tester = kwargs.get('tester')
                client_version = kwargs.get('client_version')
                test_type = kwargs.get('test_type')
                report_id = self._client.find_report_id(tester, client_version, test_type)
                self.generic_result.emit(operation_id, report_id is not None, report_id)

            else:
                self.error_occurred.emit(operation_id, f"Unknown operation: {operation}")

        except Exception as e:
            logger.error(f"Error in API worker ({operation}): {e}")
            self.error_occurred.emit(operation_id, str(e))


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

    # Signal emitted when new flags are found (for background polling)
    # Emits (count: int, flags: list)
    flag_notification = pyqtSignal(int, list)

    # Default config file locations relative to test tool
    CONFIG_FILENAMES = [
        'test_panel_config.json',
        'panel_config.json',
        'api_config.json',
        'config.json',
    ]

    # Additional signals for async operations
    tests_loaded = pyqtSignal(bool, object)  # (success, TestsResult or error string)
    versions_loaded = pyqtSignal(bool, object)  # (success, VersionsResult or error string)
    user_info_loaded = pyqtSignal(bool, object)  # (success, UserInfo or error string)
    logs_loaded = pyqtSignal(bool, object)  # (success, list of ReportLog or error string)
    log_downloaded = pyqtSignal(bool, object)  # (success, content or error string)
    log_deleted = pyqtSignal(bool, str)  # (success, message)
    hashes_checked = pyqtSignal(bool, object)  # (success, HashCheckResult or error string)
    operation_error = pyqtSignal(str)  # Error message

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

        # Background API worker for non-blocking operations
        self._worker: Optional[ApiWorker] = None
        self._operation_counter = 0  # For generating unique operation IDs
        self._pending_callbacks = {}  # Maps operation_id to callback function

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

    # =========================================================================
    # Background Worker Management
    # =========================================================================

    def _get_operation_id(self) -> str:
        """Generate a unique operation ID for tracking async requests."""
        self._operation_counter += 1
        return f"op_{self._operation_counter}_{datetime.now().strftime('%H%M%S%f')}"

    def _ensure_worker(self):
        """Ensure the background worker is initialized and running."""
        if self._worker is None:
            self._worker = ApiWorker(self)
            # Connect worker signals to handlers
            self._worker.tests_result.connect(self._on_tests_result)
            self._worker.versions_result.connect(self._on_versions_result)
            self._worker.user_info_result.connect(self._on_user_info_result)
            self._worker.logs_result.connect(self._on_logs_result)
            self._worker.log_download_result.connect(self._on_log_download_result)
            self._worker.log_delete_result.connect(self._on_log_delete_result)
            self._worker.connection_result.connect(self._on_connection_result)
            self._worker.flags_result.connect(self._on_flags_result)
            self._worker.acknowledge_result.connect(self._on_acknowledge_result)
            self._worker.hash_check_result.connect(self._on_hash_check_result)
            self._worker.retests_result.connect(self._on_retests_result)
            self._worker.generic_result.connect(self._on_generic_result)
            self._worker.error_occurred.connect(self._on_worker_error)

        if self._client:
            self._worker.set_client(self._client)

    def _stop_worker(self):
        """Stop the background worker thread."""
        if self._worker:
            self._worker.stop()
            self._worker = None

    # Worker signal handlers
    def _on_tests_result(self, operation_id: str, success: bool, result):
        """Handle tests result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)
        self.tests_loaded.emit(success, result)

    def _on_versions_result(self, operation_id: str, success: bool, result):
        """Handle versions result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)
        self.versions_loaded.emit(success, result)

    def _on_user_info_result(self, operation_id: str, success: bool, result):
        """Handle user info result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)
        self.user_info_loaded.emit(success, result)

    def _on_logs_result(self, operation_id: str, success: bool, result):
        """Handle report logs result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)
        self.logs_loaded.emit(success, result)

    def _on_log_download_result(self, operation_id: str, success: bool, result):
        """Handle log download result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)
        self.log_downloaded.emit(success, result)

    def _on_log_delete_result(self, operation_id: str, success: bool, result):
        """Handle log delete result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)
        msg = "Log deleted successfully" if success else "Failed to delete log"
        self.log_deleted.emit(success, msg)

    def _on_connection_result(self, operation_id: str, success: bool, message: str):
        """Handle connection test result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, message)
        self.connection_status.emit(success, message)

    def _on_flags_result(self, operation_id: str, success: bool, result):
        """Handle flags check result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)
        if success and result.get('count', 0) > 0:
            self.flag_notification.emit(result['count'], result.get('flags', []))

    def _on_acknowledge_result(self, operation_id: str, success: bool, result):
        """Handle flag acknowledge result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)

    def _on_hash_check_result(self, operation_id: str, success: bool, result):
        """Handle hash check result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)
        self.hashes_checked.emit(success, result)

    def _on_retests_result(self, operation_id: str, success: bool, result):
        """Handle retests result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)
        if success and result:
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
                    report_revision=item.report_revision,
                    tested_commit_hash=item.tested_commit_hash
                )
                for item in result
            ]
            self._last_retests = notifications
            self.retest_notification.emit(notifications)

    def _on_generic_result(self, operation_id: str, success: bool, result):
        """Handle generic operation result from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(success, result)

    def _on_worker_error(self, operation_id: str, error_message: str):
        """Handle error from worker."""
        callback = self._pending_callbacks.pop(operation_id, None)
        if callback:
            callback(False, error_message)
        self.operation_error.emit(error_message)
        logger.error(f"Worker error ({operation_id}): {error_message}")

    # =========================================================================
    # Async API Methods (Non-blocking)
    # =========================================================================

    def get_tests_async(self, enabled_only: bool = True, client_version: str = None,
                        callback: Callable[[bool, Any], None] = None):
        """
        Get tests from the API asynchronously (non-blocking).

        Args:
            enabled_only: If True, only return enabled tests
            client_version: Optional client version for template-specific tests
            callback: Optional callback function(success, result)

        Result is emitted via tests_loaded signal.
        """
        if self._offline_mode:
            if callback:
                callback(False, "Offline mode - restart to reconnect")
            return

        if not self.is_configured:
            if callback:
                callback(False, "Not configured")
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task(
            'get_tests',
            operation_id,
            enabled_only=enabled_only,
            client_version=client_version
        )

    def get_versions_async(self, enabled_only: bool = True, include_notifications: bool = False,
                           callback: Callable[[bool, Any], None] = None):
        """
        Get versions from the API asynchronously (non-blocking).

        Args:
            enabled_only: If True, only return enabled versions
            include_notifications: If True, include notifications for each version
            callback: Optional callback function(success, result)

        Result is emitted via versions_loaded signal.
        """
        if self._offline_mode:
            if callback:
                callback(False, "Offline mode - restart to reconnect")
            return

        if not self.is_configured:
            if callback:
                callback(False, "Not configured")
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task(
            'get_versions',
            operation_id,
            enabled_only=enabled_only,
            include_notifications=include_notifications
        )

    def get_user_info_async(self, callback: Callable[[bool, Any], None] = None):
        """
        Get user info from the API asynchronously (non-blocking).

        Args:
            callback: Optional callback function(success, result)

        Result is emitted via user_info_loaded signal.
        """
        if self._offline_mode:
            if callback:
                callback(False, "Offline mode - restart to reconnect")
            return

        if not self.is_configured:
            if callback:
                callback(False, "Not configured")
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task('get_user_info', operation_id)

    def get_report_logs_async(self, report_id: int, callback: Callable[[bool, Any], None] = None):
        """
        Get report logs from the API asynchronously (non-blocking).

        Args:
            report_id: The report ID
            callback: Optional callback function(success, result)

        Result is emitted via logs_loaded signal.
        """
        if self._offline_mode:
            if callback:
                callback(False, "Offline mode - restart to reconnect")
            return

        if not self.is_configured:
            if callback:
                callback(False, "Not configured")
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task('get_report_logs', operation_id, report_id=report_id)

    def download_report_log_async(self, log_id: int, decompress: bool = True,
                                   callback: Callable[[bool, Any], None] = None):
        """
        Download a report log asynchronously (non-blocking).

        Args:
            log_id: The log file ID
            decompress: If True, decompress and return as string
            callback: Optional callback function(success, content)

        Result is emitted via log_downloaded signal.
        """
        if self._offline_mode:
            if callback:
                callback(False, "Offline mode - restart to reconnect")
            return

        if not self.is_configured:
            if callback:
                callback(False, "Not configured")
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task('download_report_log', operation_id, log_id=log_id, decompress=decompress)

    def delete_report_log_async(self, log_id: int, callback: Callable[[bool, Any], None] = None):
        """
        Delete a report log asynchronously (non-blocking).

        Args:
            log_id: The log file ID to delete
            callback: Optional callback function(success, result)

        Result is emitted via log_deleted signal.
        """
        if self._offline_mode:
            if callback:
                callback(False, "Offline mode - restart to reconnect")
            return

        if not self.is_configured:
            if callback:
                callback(False, "Not configured")
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task('delete_report_log', operation_id, log_id=log_id)

    def test_connection_async(self, callback: Callable[[bool, str], None] = None):
        """
        Test the API connection asynchronously (non-blocking).

        Args:
            callback: Optional callback function(success, message)

        Result is emitted via connection_status signal.
        """
        if self._offline_mode:
            if callback:
                callback(False, "Offline mode - restart to reconnect")
            self.connection_status.emit(False, "Offline mode - restart to reconnect")
            return

        if not self.is_configured:
            if callback:
                callback(False, "Not configured")
            self.connection_status.emit(False, "Not configured")
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task('test_connection', operation_id)

    def check_flags_async(self, callback: Callable[[bool, Any], None] = None):
        """
        Check for flags asynchronously (non-blocking).

        Args:
            callback: Optional callback function(success, result)

        Result is emitted via flag_notification signal if flags found.
        """
        if self._offline_mode:
            if callback:
                callback(False, {'success': False, 'count': 0, 'flags': []})
            return

        if not self.is_configured:
            if callback:
                callback(False, {'success': False, 'count': 0, 'flags': []})
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task('check_flags', operation_id)

    def acknowledge_flag_async(self, flag_type: str, flag_id: int,
                                callback: Callable[[bool, Any], None] = None):
        """
        Acknowledge a flag asynchronously (non-blocking).

        Args:
            flag_type: 'retest' or 'fixed'
            flag_id: The flag's ID
            callback: Optional callback function(success, result)
        """
        if self._offline_mode:
            if callback:
                callback(False, None)
            return

        if not self.is_configured:
            if callback:
                callback(False, None)
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task('acknowledge_flag', operation_id, flag_type=flag_type, flag_id=flag_id)

    def check_hashes_async(self, hashes: dict, tester: str, test_type: str,
                           commit_hash: str = None, callback: Callable[[bool, Any], None] = None):
        """
        Check report hashes asynchronously (non-blocking).

        Args:
            hashes: Dict mapping version_id to content hash
            tester: Tester name
            test_type: Test type (WAN, LAN, WAN/LAN)
            commit_hash: Optional commit hash
            callback: Optional callback function(success, result)

        Result is emitted via hashes_checked signal.
        """
        if self._offline_mode:
            if callback:
                from api_client import HashCheckResult
                callback(False, HashCheckResult(success=False, error="Offline mode"))
            return

        if not self.is_configured:
            if callback:
                from api_client import HashCheckResult
                callback(False, HashCheckResult(success=False, error="Not configured"))
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task(
            'check_hashes',
            operation_id,
            hashes=hashes,
            tester=tester,
            test_type=test_type,
            commit_hash=commit_hash
        )

    def check_retests_async(self, client_version: str = None,
                            callback: Callable[[bool, Any], None] = None):
        """
        Check for retests asynchronously (non-blocking).

        Args:
            client_version: Optional filter by client version
            callback: Optional callback function(success, result)

        Result is emitted via retest_notification signal if retests found.
        """
        if self._offline_mode:
            if callback:
                callback(False, [])
            return

        if not self.is_configured:
            if callback:
                callback(False, [])
            return

        self._ensure_worker()
        operation_id = self._get_operation_id()
        if callback:
            self._pending_callbacks[operation_id] = callback

        self._worker.queue_task('get_retest_queue', operation_id, client_version=client_version)

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
                        report_revision=item.report_revision,
                        tested_commit_hash=item.tested_commit_hash
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

    def check_flags_lightweight(self) -> dict:
        """
        Lightweight flag check for periodic polling in background thread.

        This is designed to be called frequently without freezing the UI.
        Returns unacknowledged flags for the current user.

        Returns:
            Dict with 'success', 'count', and 'flags' keys
        """
        if self._offline_mode:
            return {'success': False, 'count': 0, 'flags': []}

        if not self.is_configured:
            return {'success': False, 'count': 0, 'flags': []}

        try:
            return self._client.check_flags()
        except Exception as e:
            logger.debug(f"Flag check failed: {e}")
            return {'success': False, 'count': 0, 'flags': [], 'error': str(e)}

    def acknowledge_flag(self, flag_type: str, flag_id: int) -> bool:
        """
        Acknowledge a flag notification so it won't show again.

        Args:
            flag_type: 'retest' or 'fixed'
            flag_id: The flag's ID

        Returns:
            True if acknowledged successfully
        """
        if self._offline_mode:
            return False

        if not self.is_configured:
            return False

        try:
            return self._client.acknowledge_flag(flag_type, flag_id)
        except Exception as e:
            logger.error(f"Error acknowledging flag: {e}")
            return False

    def check_hashes(self, hashes: dict, tester: str, test_type: str,
                     commit_hash: str = None):
        """
        Check if report hashes exist on the server.

        This is used to determine which reports need to be submitted:
        - 'skip': Report exists with matching hash, no need to submit
        - 'update': Report exists but hash differs, submit as new revision
        - 'create': Report doesn't exist, submit as new report

        Args:
            hashes: Dict mapping version_id to content hash
            tester: Tester name
            test_type: Test type (WAN, LAN, WAN/LAN)
            commit_hash: Optional commit hash

        Returns:
            HashCheckResult with per-version results
        """
        if self._offline_mode:
            from api_client import HashCheckResult
            return HashCheckResult(success=False, error="Offline mode - restart to check hashes")

        if not self.is_configured:
            from api_client import HashCheckResult
            return HashCheckResult(success=False, error="Not configured")

        return self._client.check_hashes(hashes, tester, test_type, commit_hash)

    def get_cached_retests(self) -> List[RetestNotification]:
        """Get the last fetched retests without making a new request."""
        return self._last_retests

    def submit_session(self, session_file: str = 'session_results.json',
                      async_submit: bool = True, queue_if_offline: bool = True) -> Optional[SubmitResult]:
        """
        Submit a session results file to the API.

        When offline, the submission will be queued for later if queue_if_offline is True.

        Args:
            session_file: Path to session_results.json
            async_submit: If True, submit in background thread
            queue_if_offline: If True, queue submission when offline for later retry

        Returns:
            SubmitResult if sync, None if async (result via signal)
        """
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
                args=(session_file, queue_if_offline),
                daemon=True
            )
            thread.start()
            return None
        else:
            # Synchronous submit
            return self._do_submit(session_file, queue_if_offline)

    def _do_submit(self, session_file: str, queue_if_offline: bool = True) -> SubmitResult:
        """Perform the actual submission."""
        try:
            result = self._client.submit_report(session_file, verbose=True, queue_if_offline=queue_if_offline)
            return result
        except Exception as e:
            return SubmitResult(success=False, error=str(e))

    def _async_submit(self, session_file: str, queue_if_offline: bool = True):
        """Background thread submission handler."""
        result = self._do_submit(session_file, queue_if_offline)

        if result.success:
            message = f"Report #{result.report_id} submitted successfully"
            self.submission_complete.emit(True, message, result.report_id)
        else:
            # Check if it was queued for later
            error_msg = result.error or "Unknown error"
            if "queued" in error_msg.lower():
                # Partial success - queued for later
                self.submission_complete.emit(True, f"📤 {error_msg}", None)
            else:
                self.submission_complete.emit(False, error_msg, None)

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
                    report_revision=item.report_revision,
                    tested_commit_hash=item.tested_commit_hash
                )
                for item in items
            ]
        except Exception as e:
            logger.error(f"Error getting retests for version: {e}")
            return []

    def get_tests(self, enabled_only: bool = True, client_version: str = None):
        """
        Get test types and categories from the API.

        When offline, returns cached tests if available.

        Args:
            enabled_only: If True, only return enabled tests
            client_version: Optional client version string to get version-specific template tests

        Returns:
            TestsResult object with tests grouped by category, or None if not configured
        """
        if not self.is_configured:
            logger.warning("Cannot get tests: not configured")
            return None

        try:
            # The client handles offline mode internally and returns cached data
            return self._client.get_tests(enabled_only, client_version, use_cache=True)
        except Exception as e:
            logger.error(f"Error getting tests from API: {e}")
            return None

    def get_versions(self, enabled_only: bool = True, include_notifications: bool = False):
        """
        Get client versions from the API.

        When offline, returns cached versions if available.

        Args:
            enabled_only: If True, only return enabled versions
            include_notifications: If True, include notifications for each version

        Returns:
            VersionsResult object with list of ClientVersion objects, or None if not configured
        """
        if not self.is_configured:
            logger.warning("Cannot get versions: not configured")
            return None

        try:
            # The client handles offline mode internally and returns cached data
            return self._client.get_versions(enabled_only, include_notifications, use_cache=True)
        except Exception as e:
            logger.error(f"Error getting versions from API: {e}")
            return None

    def has_cached_data(self) -> bool:
        """Check if cached data is available for offline use."""
        if not self.is_configured:
            return False
        try:
            return self._client.has_cached_data()
        except Exception:
            return False

    def is_api_online(self) -> bool:
        """Check if the API is currently online (based on last connection status)."""
        if not self.is_configured:
            return False
        try:
            return self._client.is_online()
        except Exception:
            return False

    def get_pending_submissions_count(self) -> int:
        """Get the number of pending submissions waiting to be sent."""
        if not self.is_configured:
            return 0
        try:
            return self._client.get_pending_submissions_count()
        except Exception:
            return 0

    def get_pending_submissions(self) -> list:
        """Get list of pending submissions waiting to be sent."""
        if not self.is_configured:
            return []
        try:
            return self._client.get_pending_submissions()
        except Exception:
            return []

    def retry_pending_submissions(self) -> dict:
        """
        Retry sending all pending submissions.

        Returns:
            Dict with 'succeeded' and 'failed' counts
        """
        if not self.is_configured:
            return {'succeeded': 0, 'failed': 0}
        try:
            return self._client.retry_pending_submissions()
        except Exception as e:
            logger.error(f"Error retrying pending submissions: {e}")
            return {'succeeded': 0, 'failed': 0}

    def clear_pending_submission(self, submission_id: str) -> bool:
        """Remove a pending submission from the queue."""
        if not self.is_configured:
            return False
        try:
            return self._client.clear_pending_submission(submission_id)
        except Exception:
            return False

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
        if r.tested_commit_hash is not None:
            html += f'<br><b>Test with commit revision: {r.tested_commit_hash}</b>'
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
