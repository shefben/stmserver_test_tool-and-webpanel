import sys
import os
import re
import json
import base64
import gzip
import html as html_lib
import shutil
import io
import tokenize
import keyword
import configparser
import hashlib
from datetime import datetime, timedelta
from PyQt5 import QtWidgets, QtCore
from PyQt5.QtWidgets import (QApplication, QWidget, QVBoxLayout, QLabel, QLineEdit,
                             QPushButton, QFileDialog, QCheckBox, QStackedWidget, QListWidget,
                             QListWidgetItem, QHBoxLayout, QTextEdit, QMessageBox, QScrollArea, QFrame,
                             QButtonGroup, QRadioButton, QDialog, QGroupBox, QComboBox, QShortcut)
from PyQt5.QtCore import QDate, QUrl, Qt
from PyQt5.QtGui import QPixmap, QImage, QDesktopServices, QTextCursor, QColor, QKeySequence
from versions import VERSIONS

# Try to import panel integration (optional - only if configured)
try:
    from panel_integration import PanelIntegration, show_retest_dialog, normalize_api_url
    PANEL_AVAILABLE = True
except ImportError:
    PANEL_AVAILABLE = False
    PanelIntegration = None

    def normalize_api_url(api_url):
        return (api_url or '').strip()

# Try to import version-related dataclasses from api_client
try:
    from api_client import ClientVersion, VersionNotification, VersionsResult
    API_CLIENT_AVAILABLE = True
except ImportError:
    API_CLIENT_AVAILABLE = False
    ClientVersion = None
    VersionNotification = None
    VersionsResult = None

# API-loaded versions list (populated from API when available)
# Format matches the VERSIONS list: list of dicts with id, packages, steam_date, steam_time, skip_tests
API_VERSIONS = None  # None means not loaded; empty list means loaded but empty


def get_active_versions():
    """Get the currently active versions list (API versions if loaded, else fallback)."""
    global API_VERSIONS
    if API_VERSIONS is not None and len(API_VERSIONS) > 0:
        return API_VERSIONS
    return VERSIONS


def get_version_notifications_for_display(version_id, commit_hash=None):
    """Get notifications for a version formatted for display.

    Returns a list of notification dicts with 'name', 'message', 'created_at' keys,
    sorted oldest first for stacking (oldest at bottom, newest at top).
    """
    global API_VERSIONS
    if API_VERSIONS is None:
        return []

    # Find the version in API_VERSIONS
    version = next((v for v in API_VERSIONS if v.get('id') == version_id), None)
    if not version:
        return []

    notifications = version.get('notifications', [])
    if not notifications:
        return []

    # Filter by commit hash if provided
    result = []
    for n in notifications:
        if isinstance(n, dict):
            n_commit = n.get('commit_hash')
        else:
            n_commit = getattr(n, 'commit_hash', None)

        # Include if no commit hash filter on notification, or if it matches
        if not n_commit or (commit_hash and n_commit == commit_hash):
            if isinstance(n, dict):
                result.append(n)
            else:
                result.append({
                    'id': n.id,
                    'name': n.name,
                    'message': n.message,
                    'commit_hash': n.commit_hash,
                    'created_at': n.created_at
                })

    return result


# QTextEdit subclass that handles pasting images from the clipboard and
# inserts them as base64-embedded <img> tags so they persist in toHtml().
class ImageTextEdit(QTextEdit):
    CODE_BLOCK_STYLE = (
        "background:#111;"
        "color:#eee;"
        "font-family:Consolas,'Courier New',monospace;"
        "font-size:12px;"
        "line-height:1.4;"
        "padding:8px;"
        "border-radius:6px;"
        "white-space:pre-wrap;"
    )
    PY_TOKEN_STYLES = {
        "keyword": "color:#c678dd;",
        "string": "color:#98c379;",
        "comment": "color:#5c6370;font-style:italic;",
        "number": "color:#d19a66;",
        "operator": "color:#56b6c2;",
    }

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        # allow clicking links to open externally (data: URLs will open in browser)
        try:
            self.setOpenExternalLinks(True)
        except Exception:
            pass

    def insertFromMimeData(self, source):
        # if clipboard contains text with fenced code blocks (```), convert to HTML code blocks
        if source.hasText():
            txt = source.text()
            if '```' in txt:
                pattern = re.compile(r'```(?:\w*\n)?(.*?)```', re.S)
                out = []
                last = 0
                for m in pattern.finditer(txt):
                    before = txt[last:m.start()]
                    if before:
                        out.append(html_lib.escape(before).replace('\n', '<br>'))
                    code = m.group(1)
                    out.append(self.build_code_block(code))
                    last = m.end()
                tail = txt[last:]
                if tail:
                    out.append(html_lib.escape(tail).replace('\n', '<br>'))
                html = ''.join(out)
                try:
                    self.textCursor().insertHtml(html)
                    return
                except Exception:
                    pass

        # if clipboard contains image data, embed it as base64 PNG
        if source.hasImage():
            image = source.imageData()
            try:
                # full image bytes
                ba_full = QtCore.QByteArray()
                buf_full = QtCore.QBuffer(ba_full)
                buf_full.open(QtCore.QIODevice.WriteOnly)
                image.save(buf_full, 'PNG')
                b64_full = ba_full.toBase64().data().decode('ascii')

                # create thumbnail (previously max width 250). Reduce thumbnail size by 75% => 25% of original max
                base_max_w = 250
                scale_factor = 0.50
                max_w = int(base_max_w * scale_factor)
                if image.width() > max_w:
                    thumb = image.scaledToWidth(max_w, QtCore.Qt.SmoothTransformation)
                else:
                    thumb = image
                ba_thumb = QtCore.QByteArray()
                buf_thumb = QtCore.QBuffer(ba_thumb)
                buf_thumb.open(QtCore.QIODevice.WriteOnly)
                thumb.save(buf_thumb, 'PNG')
                b64_thumb = ba_thumb.toBase64().data().decode('ascii')

                # insert clickable thumbnail which links to the full embedded image
                # inline style uses the reduced thumbnail dimensions for display
                img_html = f'<a href="data:image/png;base64,{b64_full}"><img src="data:image/png;base64,{b64_thumb}" style="max-width:{max_w}px;max-height:{int(200*scale_factor)}px;" /></a>'
                # add a small spacer after image
                img_html += '<br/>'
                self.textCursor().insertHtml(img_html)
            except Exception:
                # fallback to default behaviour
                super().insertFromMimeData(source)
            return
        # otherwise default handling (text/html, plain text)
        super().insertFromMimeData(source)

    def mouseReleaseEvent(self, event):
        try:
            # only consider anchors directly under the click position
            href = self.anchorAt(event.pos())
            if href:
                # handle embedded data:image links by showing in a dialog
                if href.startswith('data:image'):
                    try:
                        b64 = href.split(',', 1)[1]
                        data = base64.b64decode(b64)
                        img = QImage.fromData(data)
                        pix = QPixmap.fromImage(img)
                        dlg = QDialog(self)
                        dlg.setWindowTitle('Image')
                        v = QVBoxLayout(dlg)
                        lbl = QLabel()
                        lbl.setPixmap(pix)
                        lbl.setScaledContents(True)
                        # limit initial size
                        max_w = min(pix.width(), 1000)
                        max_h = min(pix.height(), 800)
                        lbl.setMaximumSize(max_w, max_h)
                        v.addWidget(lbl)
                        # clicking the image also closes the dialog
                        def _close_on_click(e):
                            try:
                                dlg.accept()
                            except Exception:
                                pass
                        lbl.mousePressEvent = _close_on_click
                        btn = QPushButton('Close')
                        btn.clicked.connect(dlg.accept)
                        v.addWidget(btn)
                        dlg.exec_()
                        return
                    except Exception:
                        pass
                # fallback to opening external URLs
                try:
                    QDesktopServices.openUrl(QUrl(href))
                    return
                except Exception:
                    pass
        except Exception:
            pass
        super().mouseReleaseEvent(event)

    def keyReleaseEvent(self, event):
        super().keyReleaseEvent(event)
        if event.text() != '`':
            return
        self._convert_fenced_block_at_cursor()

    def wrap_selection_in_codeblock(self):
        cursor = self.textCursor()
        if not cursor.hasSelection():
            return
        sel = cursor.selection().toPlainText()
        # replace selection with highlighted HTML code block
        block = self.build_code_block(sel)
        cursor.insertHtml(block)

    def _convert_fenced_block_at_cursor(self):
        cursor = self.textCursor()
        pos = cursor.position()
        text = self.toPlainText()
        if pos < 3 or text[pos - 3:pos] != '```':
            return
        open_idx = text.rfind('```', 0, pos - 3)
        if open_idx == -1:
            return
        code_text = text[open_idx + 3:pos - 3]
        cursor.beginEditBlock()
        cursor.setPosition(open_idx)
        cursor.setPosition(pos, QTextCursor.KeepAnchor)
        cursor.insertHtml(self.build_code_block(code_text))
        cursor.endEditBlock()

    def build_code_block(self, code_text):
        highlighted = self.highlight_python(code_text)
        return f"<pre style=\"{self.CODE_BLOCK_STYLE}\"><code>{highlighted}</code></pre>"

    def highlight_python(self, code_text):
        try:
            tokens = tokenize.generate_tokens(io.StringIO(code_text).readline)
            out = []
            for ttype, tstring, _, _, _ in tokens:
                escaped = html_lib.escape(tstring)
                style = ""
                if ttype == tokenize.COMMENT:
                    style = self.PY_TOKEN_STYLES["comment"]
                elif ttype == tokenize.STRING:
                    style = self.PY_TOKEN_STYLES["string"]
                elif ttype == tokenize.NUMBER:
                    style = self.PY_TOKEN_STYLES["number"]
                elif ttype == tokenize.NAME and keyword.iskeyword(tstring):
                    style = self.PY_TOKEN_STYLES["keyword"]
                elif ttype == tokenize.OP:
                    style = self.PY_TOKEN_STYLES["operator"]
                if style:
                    out.append(f"<span style=\"{style}\">{escaped}</span>")
                else:
                    out.append(escaped)
            return ''.join(out)
        except Exception:
            return html_lib.escape(code_text)


# Fallback tests list (used if API is not available)
# This matches the test definitions in includes/test_keys.php
FALLBACK_TESTS = [
    ("1", "Run the Steam.exe", "Client downloads, updates and presents the Welcome window"),
    ("2", "Create a new account", "Account is created and automatically logged into, no errors in the Steam client logs. Email is sent if SMTP is enabled"),
    ("2a", "Steam Subscriber Agreement displayed", "The SSA is shown during account creation"),
    ("2b", "Choose unique account name", "Wizard proceeds to the email address page"),
    ("2c", "Choose in-use account name", "Wizard shows alternative account names"),
    ("2d", "Enter a unique email address", "Wizard proceeds to security question"),
    ("2e", "Enter an existing email address", "Wizard prompts to find an existing account"),
    ("2f", "Steam account information is displayed", "Information is correct and all images displayed"),
    ("3", "Log into an existing account", "Client logs in and the main window is displayed"),
    ("4", "Log into an existing account made in an earlier client version", "Client logs in and the main window is displayed"),
    ("5", "Change password", "Client will only change password with correct information. Email is sent if SMTP is enabled"),
    ("6", "Change secret question answer", "Client will only change secret answer with correct information. Email is sent if SMTP is enabled"),
    ("7", "Change email address", "Email address on account is changed. Email is sent if SMTP is enabled"),
    ("8", "Add a non-Steam game", "Game shortcut is displayed in the My Games window"),
    ("9", "Purchase a game via Credit Card", "Purchase wizard shows and completes the transaction. My Games list updates with the added game(s). Check login still works"),
    ("10", "Activate a product on Steam", "CD-Key activation wizard shows and adds the game(s) to the My Games list. Check login still works"),
    ("11", "Download a game", "Game downloads and displays as installed in the My Games list"),
    ("12a", "GoldSrc Steam server browser", "Steam server browser shows running GoldSrc multiplayer games and/or HLTV sessions"),
    ("12b", "GoldSrc in-game server browser", "In-game server browser shows running GoldSrc multiplayer games"),
    ("12c", "GoldSrc Steam ticket validation", "GoldSrc server validates Steam ticket successfully"),
    ("12d", "Source Steam server browser", "Steam server browser shows running Source multiplayer games and/or HLTV sessions"),
    ("12e", "Source in-game server browser", "In-game server browser shows running Source multiplayer games"),
    ("12f", "Source Steam ticket validation", "Source server validates Steam ticket successfully"),
    ("13", "Account retrieval", "Account can be accessed via several methods"),
    ("14a", "Forgot password using email", "Email is sent if SMTP is enabled; this requires the correct validation code. Non-SMTP should accept any code"),
    ("14b", "Forgot password using CD key", "Password is reset when provided with a CD key registered on the account"),
    ("14c", "Forgot password using secret question", "Password is reset when provided with the correct secret question answer"),
    ("15", "Add a subscription", "Subscription list updates and game appears in My Games"),
    ("16", "Remove a subscription", "The My Games list is updated with the removal of the game(s)"),
    ("17", "Delete user", "The user is removed from the server"),
    ("18", "Tracker Friends - Login", "Tracker Friends service accepts login and displays friends list"),
    ("19", "Tracker Friends - Add Friend", "Friend is added and appears in friends list"),
    ("20", "Tracker Friends - Chat", "Chat messages can be sent and received between friends"),
    ("21", "Tracker Friends - Change Status", "User status is updated for all users"),
    ("22", "Tracker Friends - Play Minigame", "Minigame launches and can be played with friends"),
    ("23", "Tracker Friends - Remove Friend", "Friend is removed from friends list"),
    ("24", "CM Friends - Login", "CM Friends service accepts login and displays friends list"),
    ("25", "CM Friends - Add Friend", "Friend is added and appears in friends list"),
    ("26", "CM Friends - Chat", "Chat messages can be sent and received between friends"),
    ("27", "CM Friends - Change Status", "User status is updated for all users"),
    ("28", "CM Friends - Remove Friend", "Friend is removed from friends list"),
]

# Active tests list - will be populated from API or fallback
# Format: list of tuples (test_key, test_name, test_description)
TESTS = list(FALLBACK_TESTS)

STATUS_OPTIONS = ["", "Working", "Semi-working", "Not working", "N/A"]


def compute_version_hash(results_data: dict, attached_logs: list = None) -> str:
    """
    Compute a hash of the version results and attached logs to detect changes.

    Args:
        results_data: The results dict for a single version (test results)
        attached_logs: Optional list of attached log dicts for this version

    Returns:
        SHA256 hash string of the data
    """
    # Create a deterministic representation of the data
    data_to_hash = {
        'results': results_data,
        'logs': attached_logs or []
    }
    # Sort keys to ensure consistent ordering
    json_str = json.dumps(data_to_hash, sort_keys=True, ensure_ascii=True)
    return hashlib.sha256(json_str.encode('utf-8')).hexdigest()


def find_and_compress_log_files(emulator_path, packages, max_hours=13):
    """
    Find log files matching the given Steam/SteamUI package versions.
    Log files are named: test_log_<date>_<time>_stv-<steam_ver>_stuiv-<steamui_ver>.log

    Args:
        emulator_path: Path to the emulator folder
        packages: List of package strings like ["Steam_14", "SteamUI_51"]
        max_hours: Maximum age of log files in hours (default: 13)

    Returns:
        dict with 'success', 'logs' (list of compressed log data), 'error' (if any)
    """
    if not emulator_path or not os.path.isdir(emulator_path):
        return {'success': False, 'logs': [], 'error': 'Invalid emulator path'}

    logs_dir = os.path.join(emulator_path, 'logs')
    if not os.path.isdir(logs_dir):
        return {'success': False, 'logs': [], 'error': 'Logs folder not found'}

    # Extract Steam and SteamUI version numbers from packages
    steam_ver = None
    steamui_ver = None
    for pkg in packages:
        if pkg.startswith('Steam_'):
            steam_ver = pkg.split('_', 1)[1]
        elif pkg.startswith('SteamUI_'):
            steamui_ver = pkg.split('_', 1)[1]
        elif pkg.startswith('Platform_'):
            # Platform packages use the same as SteamUI for matching
            steamui_ver = pkg.split('_', 1)[1]

    if steam_ver is None or steamui_ver is None:
        return {'success': False, 'logs': [], 'error': 'Could not extract Steam/SteamUI versions from packages'}

    # Build the pattern to match log files
    # Format: test_log_<date>_<time>_stv-<steam_ver>_stuiv-<steamui_ver>.log
    # Date format: 2026-01-20, Time format: 10-30-45
    pattern = re.compile(
        r'^test_log_(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})_stv-' +
        re.escape(steam_ver) + r'_stuiv-' + re.escape(steamui_ver) + r'\.log$'
    )

    now = datetime.now()
    cutoff = now - timedelta(hours=max_hours)

    found_logs = []

    try:
        for filename in os.listdir(logs_dir):
            match = pattern.match(filename)
            if match:
                date_str = match.group(1)  # 2026-01-20
                time_str = match.group(2)  # 10-30-45

                # Parse the datetime from filename
                try:
                    file_datetime = datetime.strptime(f"{date_str}_{time_str}", "%Y-%m-%d_%H-%M-%S")
                except ValueError:
                    continue

                # Check if within the time window
                if file_datetime >= cutoff and file_datetime <= now:
                    file_path = os.path.join(logs_dir, filename)
                    found_logs.append((file_datetime, filename, file_path))
    except OSError as e:
        return {'success': False, 'logs': [], 'error': f'Error reading logs directory: {e}'}

    if not found_logs:
        return {'success': False, 'logs': [], 'error': f'No matching log files found for Steam_{steam_ver}/SteamUI_{steamui_ver} within the last {max_hours} hours'}

    # Sort by datetime (newest first) and compress the logs
    found_logs.sort(key=lambda x: x[0], reverse=True)

    compressed_logs = []
    for file_datetime, filename, file_path in found_logs:
        try:
            # Read the log file
            with open(file_path, 'r', encoding='utf-8', errors='replace') as f:
                log_content = f.read()

            # Compress with gzip
            compressed = gzip.compress(log_content.encode('utf-8'))

            # Base64 encode for JSON transport
            b64_compressed = base64.b64encode(compressed).decode('ascii')

            compressed_logs.append({
                'filename': filename,
                'datetime': file_datetime.strftime("%Y-%m-%d %H:%M:%S"),
                'size_original': len(log_content),
                'size_compressed': len(compressed),
                'data': b64_compressed
            })
        except Exception as e:
            # Skip files that can't be read
            continue

    if not compressed_logs:
        return {'success': False, 'logs': [], 'error': 'Could not read/compress any matching log files'}

    return {'success': True, 'logs': compressed_logs, 'error': None}


class IntroPage(QWidget):
    def __init__(self, parent=None):
        super().__init__(parent)
        layout = QVBoxLayout()

        self.name_input = QLineEdit()
        # Commit dropdown - populated from API revisions
        self.commit_input = QComboBox()
        self.commit_input.setEditable(True)  # Allow manual entry if needed
        self.commit_input.setInsertPolicy(QComboBox.NoInsert)  # Don't add typed values to list
        self.commit_input.setMinimumWidth(350)
        self.commit_input.addItem("")  # Empty default option
        # Button to show revision notes
        self.revision_notes_btn = QPushButton("Notes")
        self.revision_notes_btn.setToolTip("View revision notes for the selected commit")
        self.revision_notes_btn.setEnabled(False)
        self.revision_notes_btn.clicked.connect(self.show_revision_notes)
        # Store revisions data for lookup
        self._revisions = []  # List of Revision objects
        self.path_input = QLineEdit()
        self.browse_btn = QPushButton("Browse")
        self.wan_cb = QCheckBox("WAN")
        self.lan_cb = QCheckBox("LAN")
        self.next_btn = QPushButton("Start Testing")
        self.restart_btn = QPushButton("Restart All Tests")
        # make restart button visually prominent (red)
        self.restart_btn.setStyleSheet("background-color:#c0392b;color:white;")

        # API settings
        self.api_url_input = QLineEdit()
        self.api_url_input.setPlaceholderText("https://nuwon.net/test_api")
        self.api_key_input = QLineEdit()
        self.api_key_input.setPlaceholderText("Enter your API key (session key)")
        self.load_from_api_cb = QCheckBox("Load tested results from API")
        self.load_from_api_cb.setToolTip("Check this to load your previously tested results from the Test Panel API")

        layout.addWidget(QLabel("Tester name:"))
        layout.addWidget(self.name_input)
        layout.addWidget(QLabel("Commit revision:"))
        # Horizontal layout for commit dropdown and notes button
        commit_layout = QHBoxLayout()
        commit_layout.addWidget(self.commit_input, 1)
        commit_layout.addWidget(self.revision_notes_btn)
        layout.addLayout(commit_layout)
        layout.addWidget(QLabel("Path to emulator folder (where emulator.ini resides):"))
        ph = QHBoxLayout()
        ph.addWidget(self.path_input)
        ph.addWidget(self.browse_btn)
        layout.addLayout(ph)
        hl = QHBoxLayout()
        hl.addWidget(self.wan_cb)
        hl.addWidget(self.lan_cb)
        layout.addLayout(hl)

        # Connect commit selection change to enable/disable notes button
        self.commit_input.currentIndexChanged.connect(self._on_commit_changed)

        # API settings section
        layout.addWidget(QLabel(""))  # spacer
        api_group = QGroupBox("Test Panel API Settings")
        api_layout = QVBoxLayout()
        api_layout.addWidget(QLabel("API URL:"))
        api_layout.addWidget(self.api_url_input)
        api_layout.addWidget(QLabel("API Key (Session Key):"))
        api_layout.addWidget(self.api_key_input)
        api_layout.addWidget(self.load_from_api_cb)
        api_group.setLayout(api_layout)
        layout.addWidget(api_group)

        # place Start and Restart with spacing between them
        btn_h = QHBoxLayout()
        btn_h.addWidget(self.next_btn)
        btn_h.addStretch()
        btn_h.addWidget(self.restart_btn)
        layout.addLayout(btn_h)
        layout.addStretch()
        self.setLayout(layout)

        self.browse_btn.clicked.connect(self.browse)

    def browse(self):
        d = QFileDialog.getExistingDirectory(self, "Select emulator folder")
        if d:
            self.path_input.setText(d)

    def _on_commit_changed(self, index):
        """Handle commit selection change - enable/disable notes button."""
        # Enable notes button only if a valid revision is selected (not empty, and in our list)
        current_text = self.commit_input.currentText().strip()
        if current_text and index > 0:
            # Check if we have revision data for this commit
            sha = self._get_sha_from_display(current_text)
            rev = self._get_revision_by_sha(sha)
            self.revision_notes_btn.setEnabled(rev is not None)
        else:
            self.revision_notes_btn.setEnabled(False)

    def _get_sha_from_display(self, display_text):
        """Extract the SHA from a display string like 'abc1234 - 2026-01-21 10:30:45'."""
        if not display_text:
            return ''
        # SHA is the first part before the dash
        parts = display_text.split(' - ', 1)
        return parts[0].strip() if parts else display_text.strip()

    def _get_revision_by_sha(self, sha):
        """Find a revision by its SHA."""
        if not sha or not self._revisions:
            return None
        for rev in self._revisions:
            if rev.sha == sha or rev.sha.startswith(sha) or sha.startswith(rev.sha[:8]):
                return rev
        return None

    def populate_revisions(self, revisions):
        """Populate the commit dropdown with revision data.

        Args:
            revisions: List of Revision objects (from api_client.py)
        """
        self._revisions = revisions or []

        # Remember current selection
        current_text = self.commit_input.currentText()

        # Clear and repopulate
        self.commit_input.clear()
        self.commit_input.addItem("")  # Empty default option

        for rev in self._revisions:
            # Format: "sha - datetime"
            display = f"{rev.sha[:12]} - {rev.datetime}" if rev.datetime else rev.sha[:12]
            self.commit_input.addItem(display)

        # Try to restore previous selection
        if current_text:
            idx = self.commit_input.findText(current_text)
            if idx >= 0:
                self.commit_input.setCurrentIndex(idx)
            else:
                # Maybe just the SHA was saved - try to find it
                sha = self._get_sha_from_display(current_text)
                for i in range(self.commit_input.count()):
                    if self.commit_input.itemText(i).startswith(sha[:8]):
                        self.commit_input.setCurrentIndex(i)
                        break

    def show_revision_notes(self):
        """Show a dialog with revision notes for the selected commit."""
        current_text = self.commit_input.currentText().strip()
        if not current_text:
            return

        sha = self._get_sha_from_display(current_text)
        rev = self._get_revision_by_sha(sha)

        if not rev:
            QMessageBox.information(self, "No Notes", "No revision notes available for this commit.")
            return

        # Build the dialog content
        dlg = QDialog(self)
        dlg.setWindowTitle(f"Revision Notes - {rev.sha[:8]}")
        dlg.setMinimumSize(500, 400)
        layout = QVBoxLayout()

        # Date/time
        layout.addWidget(QLabel(f"<b>Date:</b> {rev.datetime or 'Unknown'}"))

        # Commit message
        layout.addWidget(QLabel("<b>Commit Message:</b>"))
        notes_edit = QTextEdit()
        notes_edit.setPlainText(rev.notes or 'No commit message')
        notes_edit.setReadOnly(True)
        notes_edit.setMaximumHeight(150)
        layout.addWidget(notes_edit)

        # Files changed
        files = rev.files or {}
        added = files.get('added', [])
        removed = files.get('removed', [])
        modified = files.get('modified', [])

        if added:
            layout.addWidget(QLabel(f"<b>Files Added ({len(added)}):</b>"))
            added_list = QListWidget()
            added_list.addItems(added)
            added_list.setMaximumHeight(80)
            layout.addWidget(added_list)

        if removed:
            layout.addWidget(QLabel(f"<b>Files Removed ({len(removed)}):</b>"))
            removed_list = QListWidget()
            removed_list.addItems(removed)
            removed_list.setMaximumHeight(80)
            layout.addWidget(removed_list)

        if modified:
            layout.addWidget(QLabel(f"<b>Files Modified ({len(modified)}):</b>"))
            modified_list = QListWidget()
            modified_list.addItems(modified)
            modified_list.setMaximumHeight(80)
            layout.addWidget(modified_list)

        # Close button
        close_btn = QPushButton("Close")
        close_btn.clicked.connect(dlg.accept)
        layout.addWidget(close_btn)

        dlg.setLayout(layout)
        dlg.exec_()

    def get_commit_sha(self):
        """Get just the SHA part from the commit dropdown (without the datetime)."""
        current_text = self.commit_input.currentText().strip()
        return self._get_sha_from_display(current_text)

    def set_commit_sha(self, sha):
        """Set the commit dropdown to show a specific SHA."""
        if not sha:
            self.commit_input.setCurrentIndex(0)
            return

        # Try to find the SHA in the dropdown
        for i in range(self.commit_input.count()):
            item_text = self.commit_input.itemText(i)
            if item_text.startswith(sha[:8]) or sha.startswith(self._get_sha_from_display(item_text)[:8]):
                self.commit_input.setCurrentIndex(i)
                return

        # Not found in dropdown - set the text directly (editable combo)
        self.commit_input.setCurrentText(sha)

    def get_metadata(self):
        # Get the commit SHA (just the hash, not the datetime display)
        commit_sha = self.get_commit_sha()
        return {
            'tester': self.name_input.text(),
            'commit': commit_sha,
            'emulator_path': self.path_input.text(),
            'WAN': self.wan_cb.isChecked(),
            'LAN': self.lan_cb.isChecked(),
            'api_url': self.api_url_input.text(),
            'api_key': self.api_key_input.text(),
            'load_from_api': self.load_from_api_cb.isChecked(),
        }

class VersionPage(QWidget):
    def __init__(self, parent=None, controller=None):
        super().__init__(parent)
        self.controller = controller
        layout = QVBoxLayout()
        self.list_widget = QListWidget()
        self.export_btn = QPushButton("Export Results")
        self.reload_btn = QPushButton("Reload session")
        self.upload_btn = QPushButton("Upload to Panel")
        self.upload_btn.setStyleSheet("background-color:#27ae60;color:white;")
        self.retests_btn = QPushButton("Check Retests")
        self.retests_btn.setStyleSheet("background-color:#3498db;color:white;")
        layout.addWidget(QLabel("Select a Steam version to test:"))
        layout.addWidget(self.list_widget)
        hl = QHBoxLayout()
        hl.addWidget(self.reload_btn)
        hl.addWidget(self.retests_btn)
        hl.addStretch()
        hl.addWidget(self.upload_btn)
        hl.addWidget(self.export_btn)
        layout.addLayout(hl)
        self.setLayout(layout)
        self.populate()
        self.list_widget.itemClicked.connect(self.on_item_clicked)
        self.export_btn.clicked.connect(self.export)
        self.reload_btn.clicked.connect(self.controller.load_session)
        self.upload_btn.clicked.connect(self.upload_to_panel)
        self.retests_btn.clicked.connect(self.check_retests)
        # Update button states based on panel availability
        self._update_panel_buttons()

    def populate(self):
        self.list_widget.clear()
        # Get pending retests from panel if available
        retest_versions = set()
        if self.controller.panel and self.controller.panel.is_configured:
            try:
                cached_retests = self.controller.panel.get_cached_retests()
                for r in cached_retests:
                    retest_versions.add(r.client_version)
            except Exception:
                pass

        for v in get_active_versions():
            vid = v['id']
            # Calculate completion percentage
            skip = set(v.get('skip_tests', []))
            total_tests = len([t for t in TESTS if t[0] not in skip])
            saved_results = self.controller.session.get('results', {}).get(vid, {})
            completed_tests = sum(1 for t in TESTS if t[0] not in skip and saved_results.get(t[0], {}).get('status', ''))
            pct = int((completed_tests / total_tests * 100)) if total_tests > 0 else 0

            # Build display text with percentage
            display_text = f"{vid}  [{pct}%]"
            item = QListWidgetItem(display_text)
            item.setData(QtCore.Qt.UserRole, vid)  # Store actual version id

            # Check if this version needs retesting (red text)
            if vid in retest_versions:
                item.setForeground(QColor('#e74c3c'))  # Red text for retests
                font = item.font()
                font.setBold(True)
                item.setFont(font)
            # Mark completed visually (highlight most recent)
            elif self.controller.last_completed_version == vid:
                item.setBackground(QColor('#fff3cd'))
            elif self.controller.session.get('completed', {}).get(vid):
                item.setBackground(QColor('#d4edda'))  # Light green for completed
                if pct == 100:
                    item.setForeground(QColor('#155724'))  # Dark green text

            self.list_widget.addItem(item)

    def _update_panel_buttons(self):
        """Update panel button states based on availability."""
        if PANEL_AVAILABLE and self.controller.panel and self.controller.panel.is_configured:
            self.upload_btn.setEnabled(True)
            self.retests_btn.setEnabled(True)
            self.upload_btn.setToolTip("Upload results to Test Panel")
            self.retests_btn.setToolTip("Check for pending retests")
        else:
            self.upload_btn.setEnabled(False)
            self.retests_btn.setEnabled(False)
            tip = "Panel not configured - create test_panel_config.json" if PANEL_AVAILABLE else "Panel integration not available"
            self.upload_btn.setToolTip(tip)
            self.retests_btn.setToolTip(tip)

    def upload_to_panel(self):
        """Upload session results to the test panel, only uploading changed reports."""
        if not self.controller.panel or not self.controller.panel.is_configured:
            QMessageBox.warning(self, "Not Configured",
                "Panel not configured. Create test_panel_config.json with your API URL and key.")
            return

        # Save session first to ensure latest data
        self.controller.save_session()

        # Determine which versions have changed since last upload
        results = self.controller.session.get('results', {})
        attached_logs = self.controller.session.get('attached_logs', {})
        upload_hashes = self.controller.session.get('upload_hashes', {})

        changed_versions = []
        for vid, vid_results in results.items():
            vid_logs = attached_logs.get(vid, [])
            current_hash = compute_version_hash(vid_results, vid_logs)
            last_hash = upload_hashes.get(vid)

            if current_hash != last_hash:
                changed_versions.append(vid)

        if not changed_versions:
            QMessageBox.information(self, "No Changes",
                "No changes detected since last upload.\n\n"
                "All reports are already up to date on the panel.")
            return

        # Build a filtered session with only changed versions
        filtered_session = {
            'meta': self.controller.session.get('meta', {}),
            'results': {vid: results[vid] for vid in changed_versions},
            'timing': {vid: self.controller.session.get('timing', {}).get(vid, 0) for vid in changed_versions},
            'completed': {vid: self.controller.session.get('completed', {}).get(vid, False) for vid in changed_versions},
            'attached_logs': {vid: attached_logs.get(vid, []) for vid in changed_versions if vid in attached_logs},
        }

        # Write filtered session to a temp file for upload
        filtered_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'session_results_upload.json')
        try:
            with open(filtered_path, 'w', encoding='utf-8') as f:
                json.dump(filtered_session, f, indent=2)
        except Exception as e:
            QMessageBox.warning(self, "Error", f"Failed to prepare upload: {e}")
            return

        # Store the changed versions so we can update hashes after successful upload
        self.controller._pending_upload_versions = changed_versions

        # Submit to panel
        self.controller.panel.submit_session(filtered_path)
        QMessageBox.information(self, "Uploading",
            f"Uploading {len(changed_versions)} changed report(s) to panel...\n\n"
            f"Versions: {', '.join(changed_versions[:5])}" +
            (f"\n...and {len(changed_versions) - 5} more" if len(changed_versions) > 5 else ""))

    def check_retests(self):
        """Manually check for pending retests."""
        if not self.controller.panel or not self.controller.panel.is_configured:
            QMessageBox.warning(self, "Not Configured",
                "Panel not configured. Create test_panel_config.json with your API URL and key.")
            return
        retests = self.controller.panel.check_retests_now()
        if retests:
            show_retest_dialog(retests, self)
        else:
            QMessageBox.information(self, "No Retests", "No pending retests found.")

    def on_item_clicked(self, item):
        # Get the actual version ID from UserRole data (display text includes percentage)
        vid = item.data(QtCore.Qt.UserRole)
        if not vid:
            vid = item.text()  # Fallback to text if no data
        v = next((x for x in get_active_versions() if x['id'] == vid), None)
        if v:
            # modify emulator.ini
            meta = self.controller.intro.get_metadata()
            path = meta.get('emulator_path')
            ini_path = None
            if path and os.path.isdir(path):
                ini_candidate = os.path.join(path, 'emulator.ini')
                if os.path.isfile(ini_candidate):
                    ini_path = ini_candidate
            # If an emulator.ini exists, attempt to modify it; otherwise proceed without modifying
            if ini_path:
                ok = self.controller.modify_emulator_ini(ini_path, v['steam_date'], v['steam_time'])
                if not ok:
                    QMessageBox.warning(self, "Write error", f"Failed to modify {ini_path}")
                    return
            self.controller.current_version = v
            self.controller.show_tests_for(v)

    def export(self):
        self.controller.export_report()


class AttachmentsDialog(QDialog):
    """Dialog for viewing and managing log attachments from the web panel."""

    def __init__(self, parent=None, panel=None, report_id=None, version_id=None):
        super().__init__(parent)
        self.panel = panel
        self.report_id = report_id
        self.version_id = version_id
        self.attachments_modified = False
        self.logs = []

        self.setWindowTitle(f"Attachments - {version_id}")
        self.setMinimumSize(500, 300)
        self.setup_ui()
        self.load_attachments()

    def setup_ui(self):
        layout = QVBoxLayout()

        # Info label
        self.info_label = QLabel(f"Report #{self.report_id} - Loading attachments...")
        layout.addWidget(self.info_label)

        # List widget for attachments
        self.list_widget = QListWidget()
        self.list_widget.setSelectionMode(QListWidget.SingleSelection)
        self.list_widget.itemDoubleClicked.connect(self.view_selected)
        layout.addWidget(self.list_widget)

        # Buttons
        btn_layout = QHBoxLayout()

        self.view_btn = QPushButton("View in Notepad")
        self.view_btn.setStyleSheet("background-color:#3498db;color:white;")
        self.view_btn.clicked.connect(self.view_selected)
        self.view_btn.setEnabled(False)
        btn_layout.addWidget(self.view_btn)

        self.delete_btn = QPushButton("Delete")
        self.delete_btn.setStyleSheet("background-color:#e74c3c;color:white;")
        self.delete_btn.clicked.connect(self.delete_selected)
        self.delete_btn.setEnabled(False)
        btn_layout.addWidget(self.delete_btn)

        btn_layout.addStretch()

        self.close_btn = QPushButton("Close")
        self.close_btn.clicked.connect(self.accept)
        btn_layout.addWidget(self.close_btn)

        layout.addLayout(btn_layout)
        self.setLayout(layout)

        # Connect selection change
        self.list_widget.itemSelectionChanged.connect(self.on_selection_changed)

    def load_attachments(self):
        """Load attachments from the web panel."""
        self.list_widget.clear()
        self.logs = []

        try:
            self.logs = self.panel.get_report_logs(self.report_id)

            if not self.logs:
                self.info_label.setText(f"Report #{self.report_id} - No attachments found")
                return

            self.info_label.setText(f"Report #{self.report_id} - {len(self.logs)} attachment(s)")

            for log in self.logs:
                # Format the display text
                size_kb = log.size_original / 1024
                display_text = f"{log.filename} ({size_kb:.1f} KB) - {log.log_datetime}"

                item = QListWidgetItem(display_text)
                item.setData(QtCore.Qt.UserRole, log.id)  # Store log ID
                item.setData(QtCore.Qt.UserRole + 1, log.filename)  # Store filename
                self.list_widget.addItem(item)

        except Exception as e:
            self.info_label.setText(f"Error loading attachments: {e}")

    def on_selection_changed(self):
        """Handle selection change."""
        has_selection = bool(self.list_widget.selectedItems())
        self.view_btn.setEnabled(has_selection)
        self.delete_btn.setEnabled(has_selection)

    def view_selected(self):
        """Download and view selected attachment in notepad."""
        items = self.list_widget.selectedItems()
        if not items:
            return

        item = items[0]
        log_id = item.data(QtCore.Qt.UserRole)
        filename = item.data(QtCore.Qt.UserRole + 1)

        try:
            # Download the log content
            content = self.panel.download_report_log(log_id, decompress=True)
            if content is None:
                QMessageBox.warning(self, "Download Failed",
                    "Failed to download the log file from the server.")
                return

            # Create a temp file and open in notepad
            import tempfile
            temp_dir = tempfile.gettempdir()
            temp_path = os.path.join(temp_dir, f"steam_test_{filename}")

            with open(temp_path, 'w', encoding='utf-8') as f:
                f.write(content)

            # Open in notepad (Windows) or default text editor
            if sys.platform == 'win32':
                os.startfile(temp_path)
            elif sys.platform == 'darwin':
                os.system(f'open "{temp_path}"')
            else:
                os.system(f'xdg-open "{temp_path}"')

        except Exception as e:
            QMessageBox.warning(self, "Error", f"Failed to view attachment: {e}")

    def delete_selected(self):
        """Delete the selected attachment."""
        items = self.list_widget.selectedItems()
        if not items:
            return

        item = items[0]
        log_id = item.data(QtCore.Qt.UserRole)
        filename = item.data(QtCore.Qt.UserRole + 1)

        # Confirm deletion
        reply = QMessageBox.question(self, "Confirm Delete",
            f"Are you sure you want to delete '{filename}'?\n\n"
            "This action cannot be undone.",
            QMessageBox.Yes | QMessageBox.No, QMessageBox.No)

        if reply != QMessageBox.Yes:
            return

        try:
            success = self.panel.delete_report_log(log_id)
            if success:
                self.attachments_modified = True
                QMessageBox.information(self, "Deleted",
                    f"'{filename}' has been deleted successfully.")
                # Reload the list
                self.load_attachments()
            else:
                QMessageBox.warning(self, "Delete Failed",
                    "Failed to delete the attachment. You may not have permission.")
        except Exception as e:
            QMessageBox.warning(self, "Error", f"Failed to delete attachment: {e}")


class VersionNotificationsDialog(QDialog):
    """Dialog for displaying version notifications/known issues when starting tests."""

    def __init__(self, parent=None, version_id=None, notifications=None):
        super().__init__(parent)
        self.setWindowTitle(f"Notifications - {version_id}")
        self.setMinimumWidth(500)
        self.setMaximumWidth(700)
        self.setup_ui(version_id, notifications or [])

    def setup_ui(self, version_id, notifications):
        layout = QVBoxLayout()

        # Header
        header = QLabel(f"<h3>Known Issues / Notes for {version_id}</h3>")
        header.setStyleSheet("color: #c0392b;")
        layout.addWidget(header)

        info = QLabel(f"{len(notifications)} notification(s) for this version:")
        layout.addWidget(info)

        # Scroll area for notifications
        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        scroll.setMinimumHeight(200)
        scroll.setMaximumHeight(400)

        content = QWidget()
        content_layout = QVBoxLayout()
        content_layout.setSpacing(10)

        # Display notifications stacked (oldest at bottom, newest at top)
        # The list from API is oldest first, so we reverse it
        for n in reversed(notifications):
            notif_frame = QFrame()
            notif_frame.setFrameStyle(QFrame.Box | QFrame.Raised)
            notif_frame.setStyleSheet("""
                QFrame {
                    border: 2px solid #e74c3c;
                    border-radius: 5px;
                    background-color: #fdf2f2;
                    padding: 5px;
                }
            """)
            notif_layout = QVBoxLayout()

            # Notification name (bold)
            name_label = QLabel(f"<b>{n.get('name', 'Untitled')}</b>")
            notif_layout.addWidget(name_label)

            # Message content - support basic HTML
            message = n.get('message', '')
            # Convert BBCode-style formatting if present
            message = message.replace('[b]', '<b>').replace('[/b]', '</b>')
            message = message.replace('[i]', '<i>').replace('[/i]', '</i>')
            message = message.replace('[u]', '<u>').replace('[/u]', '</u>')
            message = message.replace('\n', '<br>')

            msg_label = QLabel(message)
            msg_label.setWordWrap(True)
            msg_label.setTextFormat(QtCore.Qt.RichText)
            msg_label.setOpenExternalLinks(True)
            notif_layout.addWidget(msg_label)

            # Metadata line (commit hash and date)
            meta_parts = []
            if n.get('commit_hash'):
                meta_parts.append(f"Commit: {n['commit_hash'][:8]}")
            if n.get('created_at'):
                meta_parts.append(f"Added: {n['created_at']}")
            if n.get('created_by'):
                meta_parts.append(f"By: {n['created_by']}")
            if meta_parts:
                meta_label = QLabel(f"<small><i>{' | '.join(meta_parts)}</i></small>")
                meta_label.setStyleSheet("color: #666;")
                notif_layout.addWidget(meta_label)

            notif_frame.setLayout(notif_layout)
            content_layout.addWidget(notif_frame)

        content_layout.addStretch()
        content.setLayout(content_layout)
        scroll.setWidget(content)
        layout.addWidget(scroll)

        # OK button
        btn_layout = QHBoxLayout()
        btn_layout.addStretch()
        ok_btn = QPushButton("Continue to Tests")
        ok_btn.setStyleSheet("background-color:#27ae60;color:white;padding:8px 20px;")
        ok_btn.clicked.connect(self.accept)
        btn_layout.addWidget(ok_btn)
        layout.addLayout(btn_layout)

        self.setLayout(layout)
        self.adjustSize()


class TestPage(QWidget):
    def __init__(self, parent=None, controller=None):
        super().__init__(parent)
        self.controller = controller
        self.current_test_index = 0  # Track focused test for keyboard navigation
        self.test_frames = []  # Store references to test frames for highlighting
        layout = QVBoxLayout()
        self.header = QLabel("")
        layout.addWidget(self.header)

        self.scroll = QScrollArea()
        self.scroll.setWidgetResizable(True)
        content = QWidget()
        self.form = QVBoxLayout()
        content.setLayout(self.form)
        self.scroll.setWidget(content)
        layout.addWidget(self.scroll)

        hl = QHBoxLayout()
        self.stopwatch_label = QLabel("Stopwatch: 0:00:00")
        self.stopwatch_start_btn = QPushButton("Start")
        self.stopwatch_stop_btn = QPushButton("Stop")
        self.stopwatch_reset_btn = QPushButton("Reset")
        self.stopwatch_start_btn.clicked.connect(self.start_stopwatch)
        self.stopwatch_stop_btn.clicked.connect(self.stop_stopwatch)
        self.stopwatch_reset_btn.clicked.connect(self.reset_stopwatch)

        # View Attachments button - shows attachments from the web panel
        self.view_attachments_btn = QPushButton("View Attachments")
        self.view_attachments_btn.setStyleSheet("background-color:#3498db;color:white;")
        self.view_attachments_btn.setToolTip("View and manage log attachments from the web panel")
        self.view_attachments_btn.clicked.connect(self.view_attachments)
        self.view_attachments_btn.setVisible(False)  # Hidden by default, shown when applicable

        self.attach_log_btn = QPushButton("Attach Log")
        self.attach_log_btn.setStyleSheet("background-color:#9b59b6;color:white;")
        self.attach_log_btn.setToolTip("Find and attach test log files for this version")
        self.attach_log_btn.clicked.connect(self.attach_log)

        self.finish_btn = QPushButton("Finish Test")
        self.finish_btn.clicked.connect(self.finish)
        hl.addWidget(self.stopwatch_label)
        hl.addWidget(self.stopwatch_start_btn)
        hl.addWidget(self.stopwatch_stop_btn)
        hl.addWidget(self.stopwatch_reset_btn)
        hl.addStretch()
        hl.addWidget(self.view_attachments_btn)
        hl.addWidget(self.attach_log_btn)
        hl.addWidget(self.finish_btn)
        layout.addLayout(hl)
        self.setLayout(layout)
        self.entries = []
        self.stopwatch_running = False
        self.stopwatch_elapsed = 0.0
        self._stopwatch_start = None
        self._stopwatch_timer = QtCore.QTimer(self)
        self._stopwatch_timer.timeout.connect(self._update_stopwatch_display)
        self._stopwatch_timer.setInterval(200)
        self._update_stopwatch_display()
        # Cache for current version's report ID
        self._current_report_id = None

    def load_tests(self, version):
        self.header.setText(f"Version: {version['id']} — Packages: {', '.join(version.get('packages', []))}")
        self.reset_stopwatch()

        # Update button visibility based on emulator path and panel state
        self._update_button_visibility(version)

        # Get retests for this specific version from panel
        retest_test_keys = set()
        if self.controller.panel and self.controller.panel.is_configured:
            try:
                retests = self.controller.panel.get_retests_for_version(version['id'])
                for r in retests:
                    retest_test_keys.add(r.test_key)
            except Exception:
                pass

        # clear
        for i in reversed(range(self.form.count())):
            w = self.form.itemAt(i).widget()
            if w:
                w.setParent(None)
        self.entries = []
        self.test_frames = []  # Reset frame references
        self.current_test_index = 0  # Reset current test index

        # Try to get version-specific tests from API (uses version-specific template if assigned)
        version_tests = self.controller.get_tests_for_version(version['id'])
        if version_tests is not None:
            # Use version-specific tests (template already filters the tests)
            tests_to_show = version_tests
            skip = set()  # No additional skip needed, template already filtered
        else:
            # Fall back to global TESTS with skip_tests filtering
            tests_to_show = TESTS
            skip = set(version.get('skip_tests', []))

        for tnum, tname, tdesc in tests_to_show:
            if tnum in skip:
                continue
            frame = QFrame()
            fl = QHBoxLayout()

            # Create a container for the test title and description
            title_container = QWidget()
            title_layout = QVBoxLayout()
            title_layout.setContentsMargins(0, 0, 0, 0)
            title_layout.setSpacing(2)

            # Title label
            lbl = QLabel(f"{tnum} — {tname}")

            # Description label (smaller, italic)
            desc_lbl = QLabel(tdesc if tdesc else "")
            desc_lbl.setStyleSheet("color: #888; font-style: italic; font-size: 11px;")
            desc_lbl.setWordWrap(True)

            title_layout.addWidget(lbl)
            if tdesc:
                title_layout.addWidget(desc_lbl)
            title_container.setLayout(title_layout)

            # Highlight in red if this test needs retesting
            if tnum in retest_test_keys:
                lbl.setStyleSheet("color: #e74c3c; font-weight: bold;")
                desc_lbl.setStyleSheet("color: #e74c3c; font-style: italic; font-size: 11px;")
                frame.setStyleSheet("background-color: #fff5f5; border: 1px solid #e74c3c; border-radius: 4px;")
                lbl.setToolTip("⚠️ This test needs retesting!")

            # create a group of radio buttons for status (exclusive selection)
            status_widget = QWidget()
            status_layout = QVBoxLayout()
            status_layout.setContentsMargins(0, 0, 0, 0)
            group = QButtonGroup(status_widget)
            # skip the empty string option; allow no selection initially
            for opt in STATUS_OPTIONS[1:]:
                rb = QRadioButton(opt)
                group.addButton(rb)
                status_layout.addWidget(rb)
            status_widget.setLayout(status_layout)

            notes = ImageTextEdit()
            notes.setMaximumHeight(120)

            # add a small toolbar with a Code button to wrap selection in a code block
            note_container = QWidget()
            note_v = QVBoxLayout()
            note_v.setContentsMargins(0, 0, 0, 0)
            note_v.addWidget(notes)
            note_container.setLayout(note_v)

            fl.addWidget(title_container, 2)
            fl.addWidget(status_widget, 1)
            fl.addWidget(note_container, 3)
            frame.setLayout(fl)
            frame.setProperty('is_retest', tnum in retest_test_keys)  # Store retest status
            self.form.addWidget(frame)
            self.test_frames.append(frame)  # Store frame reference for highlighting
            self.entries.append((tnum, group, notes))
        self.form.addStretch()
        # populate if existing
        saved = self.controller.session.get('results', {}).get(version['id'], {})
        for tnum, group, notes in self.entries:
            r = saved.get(tnum, {})
            if r:
                status = r.get('status', '')
                # notes are stored as HTML (may include embedded images)
                notes.setHtml(r.get('notes', ''))
                if status:
                    for b in group.buttons():
                        if b.text() == status:
                            b.setChecked(True)
                            break

    def _get_stopwatch_seconds(self):
        elapsed = self.stopwatch_elapsed
        if self.stopwatch_running and self._stopwatch_start:
            elapsed += (datetime.now() - self._stopwatch_start).total_seconds()
        return elapsed

    def _update_stopwatch_display(self):
        elapsed = int(self._get_stopwatch_seconds())
        self.stopwatch_label.setText(f"Stopwatch: {self.controller.format_seconds(elapsed)}")

    def start_stopwatch(self):
        if self.stopwatch_running:
            return
        self.stopwatch_running = True
        self._stopwatch_start = datetime.now()
        if not self._stopwatch_timer.isActive():
            self._stopwatch_timer.start()

    def stop_stopwatch(self):
        if not self.stopwatch_running:
            return
        self.stopwatch_elapsed += (datetime.now() - self._stopwatch_start).total_seconds()
        self.stopwatch_running = False
        self._stopwatch_start = None
        if self._stopwatch_timer.isActive():
            self._stopwatch_timer.stop()
        self._update_stopwatch_display()

    def reset_stopwatch(self):
        self.stopwatch_running = False
        self.stopwatch_elapsed = 0.0
        self._stopwatch_start = None
        if self._stopwatch_timer.isActive():
            self._stopwatch_timer.stop()
        self._update_stopwatch_display()

    def attach_log(self):
        """Find and attach log files for the current version."""
        if not self.controller.current_version:
            QMessageBox.warning(self, "No Version", "No version selected.")
            return

        version = self.controller.current_version
        vid = version['id']
        packages = version.get('packages', [])

        # Get emulator path from intro metadata
        meta = self.controller.intro.get_metadata()
        emulator_path = meta.get('emulator_path', '')

        if not emulator_path:
            QMessageBox.warning(self, "No Path",
                "Emulator path not set. Please set it on the intro page.")
            return

        # Find and compress matching log files
        result = find_and_compress_log_files(emulator_path, packages, max_hours=13)

        if not result['success']:
            QMessageBox.warning(self, "No Logs Found", result['error'])
            return

        logs = result['logs']

        # Store in session under 'attached_logs' keyed by version id
        if 'attached_logs' not in self.controller.session:
            self.controller.session['attached_logs'] = {}

        self.controller.session['attached_logs'][vid] = logs
        self.controller.save_session()

        # Show summary
        total_original = sum(log['size_original'] for log in logs)
        total_compressed = sum(log['size_compressed'] for log in logs)
        ratio = (1 - total_compressed / total_original) * 100 if total_original > 0 else 0

        filenames = '\n'.join(f"  • {log['filename']}" for log in logs)
        QMessageBox.information(self, "Logs Attached",
            f"Successfully attached {len(logs)} log file(s):\n\n{filenames}\n\n"
            f"Original size: {total_original:,} bytes\n"
            f"Compressed size: {total_compressed:,} bytes\n"
            f"Compression ratio: {ratio:.1f}%")

    def _update_button_visibility(self, version):
        """Update the visibility of Attach Log and View Attachments buttons based on current state."""
        vid = version['id']

        # Get emulator path from intro metadata
        meta = self.controller.intro.get_metadata()
        emulator_path = meta.get('emulator_path', '').strip()

        # Hide Attach Log button if emulator path is empty
        self.attach_log_btn.setVisible(bool(emulator_path))

        # Check if this report has been submitted to the API
        # A version is considered submitted if it has an upload hash
        upload_hashes = self.controller.session.get('upload_hashes', {})
        is_submitted = vid in upload_hashes

        # Check if panel is configured and online
        has_panel = (self.controller.panel and
                     self.controller.panel.is_configured and
                     not self.controller.offline_mode)

        # Try to find the report ID on the panel and check for attachments
        show_view_attachments = False
        self._current_report_id = None

        if is_submitted and has_panel:
            try:
                # Get test type from metadata
                test_type = 'WAN' if meta.get('WAN') else 'LAN'
                tester = meta.get('tester', '')

                if tester:
                    # Find the report ID for this version
                    report_id = self.controller.panel.find_report_id(tester, vid, test_type)
                    if report_id:
                        self._current_report_id = report_id
                        # Check if there are any attachments
                        logs = self.controller.panel.get_report_logs(report_id)
                        if logs:
                            show_view_attachments = True
            except Exception:
                pass

        self.view_attachments_btn.setVisible(show_view_attachments)

    def view_attachments(self):
        """Show dialog to view and manage log attachments from the web panel."""
        if not self.controller.current_version:
            QMessageBox.warning(self, "No Version", "No version selected.")
            return

        if not self._current_report_id:
            QMessageBox.warning(self, "No Report",
                "No submitted report found for this version.")
            return

        if not self.controller.panel or not self.controller.panel.is_configured:
            QMessageBox.warning(self, "Panel Not Configured",
                "Panel not configured. Cannot view attachments.")
            return

        # Show the attachments dialog
        dialog = AttachmentsDialog(
            parent=self,
            panel=self.controller.panel,
            report_id=self._current_report_id,
            version_id=self.controller.current_version['id']
        )
        result = dialog.exec_()

        # If dialog reported that attachments were deleted, update button visibility
        if dialog.attachments_modified:
            self._update_button_visibility(self.controller.current_version)

    def finish(self):
        # collect
        results = {}
        for tnum, group, notes in self.entries:
            checked = group.checkedButton()
            status = checked.text() if checked else ''
            # save notes as HTML so embedded images are preserved
            results[tnum] = {'status': status, 'notes': notes.toHtml()}
        vid = self.controller.current_version['id']
        if 'results' not in self.controller.session:
            self.controller.session['results'] = {}
        self.controller.session['results'][vid] = results
        # mark completed
        if 'completed' not in self.controller.session:
            self.controller.session['completed'] = {}
        self.controller.session['completed'][vid] = True
        self.controller.last_completed_version = vid
        self.controller.save_session()
        self.controller.show_versions()

    # ==================== Keyboard Navigation Methods ====================

    def highlight_current_test(self):
        """Highlight the currently focused test and scroll to it."""
        if not self.test_frames:
            return

        # Clamp index to valid range
        if self.current_test_index < 0:
            self.current_test_index = 0
        if self.current_test_index >= len(self.test_frames):
            self.current_test_index = len(self.test_frames) - 1

        # Update all frame styles
        for i, frame in enumerate(self.test_frames):
            is_retest = frame.property('is_retest')
            if i == self.current_test_index:
                # Focused test - blue highlight
                frame.setStyleSheet("background-color: #e3f2fd; border: 2px solid #2196f3; border-radius: 4px;")
            elif is_retest:
                # Retest needed - red highlight
                frame.setStyleSheet("background-color: #fff5f5; border: 1px solid #e74c3c; border-radius: 4px;")
            else:
                # Normal test - no highlight
                frame.setStyleSheet("")

        # Scroll to the focused test
        if self.current_test_index < len(self.test_frames):
            frame = self.test_frames[self.current_test_index]
            self.scroll.ensureWidgetVisible(frame, 50, 50)

    def next_test(self):
        """Move focus to the next test."""
        if not self.entries:
            return
        if self.current_test_index < len(self.entries) - 1:
            self.current_test_index += 1
            self.highlight_current_test()

    def prev_test(self):
        """Move focus to the previous test."""
        if not self.entries:
            return
        if self.current_test_index > 0:
            self.current_test_index -= 1
            self.highlight_current_test()

    def set_current_test_status(self, status_index):
        """Set the status of the currently focused test.

        Args:
            status_index: 1=Working, 2=Semi-working, 3=Not working, 4=N/A
        """
        if not self.entries or self.current_test_index >= len(self.entries):
            return

        # Map 1-4 to STATUS_OPTIONS[1:] (skipping empty string at index 0)
        # STATUS_OPTIONS = ["", "Working", "Semi-working", "Not working", "N/A"]
        if status_index < 1 or status_index > 4:
            return

        tnum, group, notes = self.entries[self.current_test_index]
        buttons = group.buttons()

        # buttons correspond to STATUS_OPTIONS[1:], so index 0 = Working, 1 = Semi-working, etc.
        target_index = status_index - 1
        if target_index < len(buttons):
            buttons[target_index].setChecked(True)

    def focus_current_notes(self):
        """Focus the notes field of the currently focused test."""
        if not self.entries or self.current_test_index >= len(self.entries):
            return

        tnum, group, notes = self.entries[self.current_test_index]
        notes.setFocus()


class Controller:
    # Path to settings INI file (next to this script)
    SETTINGS_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'test_tool_settings.ini')

    def __init__(self):
        self.app = QApplication(sys.argv)
        self.window = QtWidgets.QMainWindow()
        self.window.setWindowTitle('Steam Emulator Tester')
        self.stack = QStackedWidget()
        # ensure session exists before pages which may read it
        self.session = {}

        # Panel will be initialized after loading settings from INI
        self.panel = None

        # Offline mode flag - True when API is configured but not reachable
        self.offline_mode = False

        # Track versions pending upload (for hash update after success)
        self._pending_upload_versions = []

        self.last_completed_version = None
        self.intro = IntroPage()
        # wire intro restart button to controller restart handler
        try:
            self.intro.restart_btn.clicked.connect(self.restart_session)
        except Exception:
            pass

        # Load settings from INI file (this also initializes panel if configured)
        self._load_settings()

        # Connect API settings changes to save settings
        self.intro.api_url_input.editingFinished.connect(self._save_settings)
        self.intro.api_key_input.editingFinished.connect(self._save_settings)
        self.intro.load_from_api_cb.stateChanged.connect(self._save_settings)

        self.versions = VersionPage(controller=self)
        self.tests = TestPage(controller=self)
        # if emulator.ini lives next to this script, prefill the path input
        try:
            script_dir = os.path.dirname(os.path.abspath(__file__))
            if os.path.isfile(os.path.join(script_dir, 'emulator.ini')):
                self.intro.path_input.setText(script_dir)
        except Exception:
            pass
        self.stack.addWidget(self.intro)
        self.stack.addWidget(self.versions)
        self.stack.addWidget(self.tests)
        self.window.setCentralWidget(self.stack)
        # connections
        self.intro.next_btn.clicked.connect(self.show_versions)
        self.current_version = None
        self.last_completed_version = None
        # timing: accumulated seconds per version is stored in session['timing']
        # runtime-only: current running version id and start timestamp
        self.timer_running_version = None
        self._timer_start = None
        # QTimer to tick every second and update UI
        self._tick_timer = QtCore.QTimer()
        self._tick_timer.timeout.connect(self._tick)
        self._tick_timer.start(1000)

        # try load session
        self.load_session()

        # Start panel monitoring if configured (checks on startup + every 10 min)
        if self.panel and self.panel.is_configured:
            # Load tests from API when panel is configured
            self._load_tests_from_api()

            # Load client versions from API (includes notifications)
            self._load_versions_from_api()

            # Show offline mode warning if API is not reachable
            if self.offline_mode:
                self._show_offline_mode_dialog()
            else:
                # Only start monitoring and fetch tester name if online
                self.panel.start_monitoring()
                self._load_tester_name_from_api()

    def _load_settings(self):
        """Load settings from INI file and initialize panel."""
        api_url = ''
        api_key = ''
        load_from_api = False
        emulator_path = ''

        try:
            if os.path.isfile(self.SETTINGS_FILE):
                config = configparser.ConfigParser()
                config.read(self.SETTINGS_FILE, encoding='utf-8')
                if 'API' in config:
                    api_url = normalize_api_url(config.get('API', 'api_url', fallback=''))
                    api_key = config.get('API', 'api_key', fallback='')
                    load_from_api = config.getboolean('API', 'load_from_api', fallback=False)
                    self.intro.api_url_input.setText(api_url)
                    self.intro.api_key_input.setText(api_key)
                    self.intro.load_from_api_cb.setChecked(load_from_api)
                if 'Paths' in config:
                    emulator_path = config.get('Paths', 'emulator_path', fallback='')
                    if emulator_path and os.path.isdir(emulator_path):
                        self.intro.path_input.setText(emulator_path)
        except Exception as e:
            print(f"Error loading settings: {e}")

        # Initialize panel integration with settings from INI (not JSON)
        if PANEL_AVAILABLE and api_url and api_key:
            try:
                self.panel = PanelIntegration()
                # Directly configure the panel with our INI settings (no JSON file)
                self.panel.create_config(api_url, api_key, save_to_file=False)
                # Connect signals
                self.panel.retest_notification.connect(self._on_retests_found)
                self.panel.submission_complete.connect(self._on_submission_complete)
            except Exception as e:
                print(f"Panel integration init error: {e}")
                self.panel = None
        elif PANEL_AVAILABLE:
            # Create panel without config - user can configure later
            try:
                self.panel = PanelIntegration()
                self.panel.retest_notification.connect(self._on_retests_found)
                self.panel.submission_complete.connect(self._on_submission_complete)
            except Exception as e:
                print(f"Panel integration init error: {e}")
                self.panel = None

    def _save_settings(self):
        """Save settings to INI file."""
        try:
            config = configparser.ConfigParser()
            api_url = normalize_api_url(self.intro.api_url_input.text())
            if api_url != self.intro.api_url_input.text():
                self.intro.api_url_input.setText(api_url)
            config['API'] = {
                'api_url': api_url,
                'api_key': self.intro.api_key_input.text(),
                'load_from_api': str(self.intro.load_from_api_cb.isChecked())
            }
            with open(self.SETTINGS_FILE, 'w', encoding='utf-8') as f:
                config.write(f)

            # Update panel config with new API settings
            api_url = normalize_api_url(self.intro.api_url_input.text())
            api_key = self.intro.api_key_input.text()
            if api_url and api_key and self.panel:
                self._update_panel_config(api_url, api_key)
        except Exception as e:
            print(f"Error saving settings: {e}")

    def _update_panel_config(self, api_url, api_key):
        """Update the panel integration with the API settings."""
        if not self.panel:
            return
        try:
            api_url = normalize_api_url(api_url)
            if not api_url:
                return
            # Reconfigure the panel with the new settings (no JSON file needed)
            self.panel.create_config(api_url, api_key, save_to_file=False)
            # Also reload tests when config changes
            self._load_tests_from_api()
        except Exception as e:
            print(f"Error updating panel config: {e}")

    def _load_tests_from_api(self):
        """Load test types from the API and update the global TESTS list.

        If the API is configured but not reachable, sets offline_mode to True
        and uses fallback tests.
        """
        global TESTS
        if not self.panel or not self.panel.is_configured:
            print("Panel not configured, using fallback tests")
            TESTS = list(FALLBACK_TESTS)
            self.offline_mode = False  # Not offline, just not configured
            return False

        # Test connection first
        try:
            if not self.panel.test_connection():
                print("API not reachable, entering offline mode with fallback tests")
                TESTS = list(FALLBACK_TESTS)
                self.offline_mode = True
                return False
        except Exception as e:
            print(f"Connection test failed: {e}, entering offline mode with fallback tests")
            TESTS = list(FALLBACK_TESTS)
            self.offline_mode = True
            return False

        # Connection successful, try to load tests
        try:
            result = self.panel.get_tests(enabled_only=True)
            if result and result.success and result.tests:
                # Convert API tests to the same format as FALLBACK_TESTS
                # Format: (test_key, test_name, description)
                new_tests = []
                for test in result.tests:
                    new_tests.append((
                        test.test_key,
                        test.name,
                        test.description or ''
                    ))
                TESTS = new_tests
                print(f"Loaded {len(TESTS)} tests from API")
                self.offline_mode = False
                return True
            else:
                error = result.error if result else "Unknown error"
                print(f"Failed to load tests from API: {error}, entering offline mode with fallback tests")
                TESTS = list(FALLBACK_TESTS)
                self.offline_mode = True
                return False
        except Exception as e:
            print(f"Error loading tests from API: {e}, entering offline mode with fallback tests")
            TESTS = list(FALLBACK_TESTS)
            self.offline_mode = True
            return False

    def get_tests_list(self):
        """Get the current tests list (from API or fallback)."""
        return TESTS

    def get_tests_for_version(self, version_id: str):
        """Get tests for a specific version using version-specific template if assigned.

        Args:
            version_id: The client version string (e.g., 'secondblob.bin.2004-01-15')

        Returns:
            List of tests in (test_key, test_name, description) format, or None if offline/error
        """
        if not self.panel or not self.panel.is_configured or self.offline_mode:
            return None

        try:
            result = self.panel.get_tests(enabled_only=True, client_version=version_id)
            if result and result.success and result.tests:
                tests = []
                for test in result.tests:
                    tests.append((
                        test.test_key,
                        test.name,
                        test.description or ''
                    ))
                if result.template:
                    print(f"Using template '{result.template.get('name', 'Unknown')}' for version {version_id} ({len(tests)} tests)")
                return tests
            return None
        except Exception as e:
            print(f"Error getting tests for version {version_id}: {e}")
            return None

    def _load_versions_from_api(self):
        """Load client versions from the API and update the global API_VERSIONS list.

        If the API is configured but not reachable, uses fallback VERSIONS from file.
        """
        global API_VERSIONS
        if not self.panel or not self.panel.is_configured:
            print("Panel not configured, using fallback versions from file")
            API_VERSIONS = None  # Will use VERSIONS from versions.py
            return False

        # Connection should already be tested by _load_tests_from_api
        if self.offline_mode:
            print("Offline mode, using fallback versions from file")
            API_VERSIONS = None
            return False

        try:
            # Get versions with notifications included
            result = self.panel.get_versions(enabled_only=True, include_notifications=True)
            if result and result.success and result.versions:
                # Convert API ClientVersion objects to the same format as VERSIONS
                new_versions = []
                for v in result.versions:
                    version_dict = {
                        'id': v.id,
                        'packages': v.packages or [],
                        'steam_date': v.steam_date,
                        'steam_time': v.steam_time,
                        'skip_tests': v.skip_tests or [],
                        'display_name': v.display_name,
                        'notifications': []
                    }
                    # Add notifications
                    if v.notifications:
                        for n in v.notifications:
                            version_dict['notifications'].append({
                                'id': n.id,
                                'name': n.name,
                                'message': n.message,
                                'commit_hash': n.commit_hash,
                                'created_at': n.created_at
                            })
                    new_versions.append(version_dict)

                API_VERSIONS = new_versions
                print(f"Loaded {len(API_VERSIONS)} client versions from API")

                # Count total notifications
                total_notifs = sum(len(v.get('notifications', [])) for v in API_VERSIONS)
                if total_notifs > 0:
                    print(f"  (includes {total_notifs} version notifications)")

                return True
            else:
                error = result.error if result else "Unknown error"
                print(f"Failed to load versions from API: {error}, using fallback versions")
                API_VERSIONS = None
                return False
        except Exception as e:
            print(f"Error loading versions from API: {e}, using fallback versions")
            API_VERSIONS = None
            return False

    def _show_offline_mode_dialog(self):
        """Show a dialog informing the user that the tool is in offline mode."""
        msg = QMessageBox(self.window)
        msg.setIcon(QMessageBox.Warning)
        msg.setWindowTitle("Offline Mode")
        msg.setText("Could not connect to the Test Panel API.")
        msg.setInformativeText(
            "The tool is running in offline mode.\n\n"
            "You can still perform testing, but your results will NOT be "
            "automatically uploaded to the panel.\n\n"
            "To submit your test results:\n"
            "1. Complete your testing as usual\n"
            "2. Export your results to an HTML report\n"
            "3. Manually upload the report through the web panel using a browser\n\n"
            "The tool will use the default test definitions until a connection "
            "can be established."
        )
        msg.setStandardButtons(QMessageBox.Ok)
        msg.exec_()

    def _load_tester_name_from_api(self):
        """Fetch the tester name and revisions from the API using the configured API key."""
        if not self.panel or not self.panel.is_configured:
            return
        try:
            # Get full user info including revisions
            user_info = self.panel.get_user_info_full()
            if user_info and user_info.success:
                # Set tester name only if field is empty
                if not self.intro.name_input.text().strip() and user_info.username:
                    self.intro.name_input.setText(user_info.username)
                    print(f"Loaded tester name from API: {user_info.username}")

                # Populate revisions dropdown
                if user_info.revisions:
                    self.intro.populate_revisions(user_info.revisions)
                    print(f"Loaded {len(user_info.revisions)} revisions from API")
        except Exception as e:
            print(f"Error loading user info from API: {e}")

    def show_versions(self):
        # stop timing when leaving tests page
        self.stop_timer()
        self.versions.populate()
        # Update panel buttons state
        self.versions._update_panel_buttons()
        self.stack.setCurrentWidget(self.versions)

    def _on_retests_found(self, retests):
        """Handle retest notification signal from panel."""
        if retests and PANEL_AVAILABLE:
            # Show notification dialog
            show_retest_dialog(retests, self.window)

    def _on_submission_complete(self, success, message, report_id):
        """Handle submission complete signal from panel."""
        if success:
            # Update upload hashes for successfully uploaded versions
            if hasattr(self, '_pending_upload_versions') and self._pending_upload_versions:
                results = self.session.get('results', {})
                attached_logs = self.session.get('attached_logs', {})

                if 'upload_hashes' not in self.session:
                    self.session['upload_hashes'] = {}

                for vid in self._pending_upload_versions:
                    vid_results = results.get(vid, {})
                    vid_logs = attached_logs.get(vid, [])
                    self.session['upload_hashes'][vid] = compute_version_hash(vid_results, vid_logs)

                self._pending_upload_versions = []
                self.save_session()

            # Clean up temp upload file
            try:
                filtered_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'session_results_upload.json')
                if os.path.exists(filtered_path):
                    os.remove(filtered_path)
            except Exception:
                pass

            QMessageBox.information(self.window, "Upload Complete",
                f"Report uploaded successfully!\n\n{message}")
        else:
            # Clear pending versions on failure so user can retry
            self._pending_upload_versions = []

            QMessageBox.warning(self.window, "Upload Failed",
                f"Failed to upload report:\n\n{message}")

    def show_tests_for(self, version):
        # Check for notifications for this version before showing tests
        version_id = version['id']
        commit_hash = self.intro.get_metadata().get('commit')
        notifications = get_version_notifications_for_display(version_id, commit_hash)

        if notifications:
            # Show notifications dialog
            dialog = VersionNotificationsDialog(self.window, version_id, notifications)
            dialog.exec_()  # Wait for user to acknowledge

        self.tests.load_tests(version)
        # start/resume timing for this version
        self.start_timer(version['id'])
        self.stack.setCurrentWidget(self.tests)

    def format_seconds(self, s):
        h = int(s // 3600)
        m = int((s % 3600) // 60)
        sec = int(s % 60)
        return f"{h:d}:{m:02d}:{sec:02d}"

    def get_time_info(self, version_id):
        # return (version_seconds, total_seconds)
        timing = self.session.get('timing', {})
        base = int(timing.get(version_id, 0))
        running_extra = 0
        if self.timer_running_version == version_id and self._timer_start:
            running_extra = int((datetime.now() - self._timer_start).total_seconds())
        version_seconds = base + running_extra
        total = sum(int(v) for v in timing.values()) + (int((datetime.now() - self._timer_start).total_seconds()) if self._timer_start and self.timer_running_version else 0)
        return version_seconds, total

    def _tick(self):
        # update UI timing labels when on test page
        if self.stack.currentWidget() == self.tests and self.current_version:
            vid = self.current_version['id']
            vsec, tot = self.get_time_info(vid)
            timestr = f"<br><small>Time on this version: {self.format_seconds(vsec)} — Total testing time: {self.format_seconds(tot)}</small>"
            # preserve the original header content (version & packages)
            base = f"Version: {self.current_version['id']} — Packages: {', '.join(self.current_version.get('packages', []))}"
            self.tests.header.setText(base + timestr)

    def start_timer(self, version_id):
        # if already running on this version do nothing
        if self.timer_running_version == version_id:
            return
        # stop any other running timer first
        if self.timer_running_version is not None:
            self.stop_timer()
        self.timer_running_version = version_id
        self._timer_start = datetime.now()

    def stop_timer(self):
        if not self.timer_running_version or not self._timer_start:
            self.timer_running_version = None
            self._timer_start = None
            return
        elapsed = int((datetime.now() - self._timer_start).total_seconds())
        if 'timing' not in self.session:
            self.session['timing'] = {}
        self.session['timing'][self.timer_running_version] = int(self.session['timing'].get(self.timer_running_version, 0)) + elapsed
        # clear running state
        self.timer_running_version = None
        self._timer_start = None
        self.save_session()

    def modify_emulator_ini(self, ini_path, steam_date, steam_time):
        try:
            with open(ini_path, 'r', encoding='utf-8') as f:
                text = f.read()
        except Exception:
            return False
        # robust replace or append: handle existing values like 2004/10/01 or 00:00:01,
        # and tolerate leading/trailing whitespace around the key and '='
        date_pattern = r'^(?P<prefix>\s*steam_date\s*=\s*).*$'
        time_pattern = r'^(?P<prefix>\s*steam_time\s*=\s*).*$'

        if re.search(date_pattern, text, flags=re.MULTILINE):
            text = re.sub(date_pattern, lambda m: f"{m.group('prefix')}{steam_date}", text, flags=re.MULTILINE)
        else:
            text += f"\nsteam_date={steam_date}\n"

        if re.search(time_pattern, text, flags=re.MULTILINE):
            text = re.sub(time_pattern, lambda m: f"{m.group('prefix')}{steam_time}", text, flags=re.MULTILINE)
        else:
            text += f"steam_time={steam_time}\n"

        # make a backup before overwriting
        try:
            shutil.copy2(ini_path, ini_path + '.bak')
        except Exception:
            pass
        try:
            with open(ini_path, 'w', encoding='utf-8') as f:
                f.write(text)
            return True
        except Exception:
            return False

    def save_session(self):
        try:
            # persist current metadata (tester, commit, WAN/LAN, emulator_path)
            try:
                meta = self.intro.get_metadata()
                # only save tester/commit/WAN/LAN and emulator path
                self.session['meta'] = {
                    'tester': meta.get('tester',''),
                    'commit': meta.get('commit',''),
                    'emulator_path': meta.get('emulator_path',''),
                    'WAN': bool(meta.get('WAN', False)),
                    'LAN': bool(meta.get('LAN', False)),
                }
            except Exception:
                pass

            # Build version_packages mapping for all tested versions
            # This maps version IDs to their Steam/SteamUI package versions
            try:
                version_packages = {}
                for v in get_active_versions():
                    vid = v['id']
                    packages = v.get('packages', [])
                    steam_ver = None
                    steamui_ver = None
                    for pkg in packages:
                        if pkg.startswith('Steam_'):
                            steam_ver = pkg.split('_', 1)[1]
                        elif pkg.startswith('SteamUI_'):
                            steamui_ver = pkg.split('_', 1)[1]
                        elif pkg.startswith('Platform_'):
                            # Platform packages are equivalent to SteamUI for early versions
                            steamui_ver = pkg.split('_', 1)[1]
                    version_packages[vid] = {
                        'steam_pkg_version': steam_ver,
                        'steamui_version': steamui_ver
                    }
                self.session['version_packages'] = version_packages
            except Exception:
                pass

            with open('session_results.json', 'w', encoding='utf-8') as f:
                json.dump(self.session, f, indent=2)
        except Exception:
            pass

    def load_session(self):
        if os.path.isfile('session_results.json'):
            try:
                with open('session_results.json', 'r', encoding='utf-8') as f:
                    self.session = json.load(f)
            except Exception:
                self.session = {}
        else:
            self.session = {}
        # migrate legacy results for test 12 -> 12a notes
        try:
            self._migrate_legacy_test12_notes()
        except Exception:
            pass
        # if session contains metadata, prefill intro fields
        try:
            meta = self.session.get('meta', {})
            if meta:
                try:
                    self.intro.name_input.setText(meta.get('tester', ''))
                    # Use set_commit_sha for the combo box
                    self.intro.set_commit_sha(meta.get('commit', ''))
                    self.intro.path_input.setText(meta.get('emulator_path', ''))
                    self.intro.wan_cb.setChecked(bool(meta.get('WAN', False)))
                    self.intro.lan_cb.setChecked(bool(meta.get('LAN', False)))
                except Exception:
                    pass
        except Exception:
            pass
        # update UI if present
        try:
            self.versions.populate()
        except Exception:
            pass

    def _migrate_legacy_test12_notes(self):
        results = self.session.get('results', {})
        if not isinstance(results, dict):
            return
        for _, tests in results.items():
            if not isinstance(tests, dict):
                continue
            legacy = tests.get('12')
            if not isinstance(legacy, dict):
                continue
            legacy_notes = legacy.get('notes', '')
            if not legacy_notes:
                continue
            target = tests.get('12a')
            if not isinstance(target, dict):
                tests['12a'] = {'status': '', 'notes': legacy_notes}
                continue
            if not target.get('notes'):
                target['notes'] = legacy_notes

    def restart_session(self):
        # confirm with the user
        reply = QMessageBox.question(self.window, "Restart all tests",
                                     "This will delete the current session and all saved results. Continue?",
                                     QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
        if reply != QMessageBox.Yes:
            return
        # stop any running timer, delete session file, clear memory, reload
        try:
            self.stop_timer()
        except Exception:
            pass
        try:
            if os.path.isfile('session_results.json'):
                os.remove('session_results.json')
        except Exception:
            pass
        self.session = {}
        self.load_session()
        # go to versions page refreshed
        self.show_versions()

    def export_report(self):
        meta = self.intro.get_metadata()
        commit = (meta.get('commit') or '').strip() or "unknown"
        date_str = datetime.now().strftime("%Y-%m-%d")
        default_name = f"{date_str} - Commit ({commit}) Results.html"
        path, _ = QFileDialog.getSaveFileName(self.window, "Save report as", default_name, "HTML Files (*.html)")
        if not path:
            return
        html = self.build_html_report(meta)
        try:
            with open(path, 'w', encoding='utf-8') as f:
                f.write(html)
            QMessageBox.information(self.window, "Exported", f"Report saved to {path}")
        except Exception as e:
            QMessageBox.warning(self.window, "Error", f"Failed to save report: {e}")

    def build_html_report(self, meta):
        now = datetime.now().strftime("%b %d, %Y %I:%M:%S %p")
        results = self.session.get('results', {})
        timing = self.session.get('timing', {})
        # include running extra time if a timer is active
        running_extra = 0
        running_vid = self.timer_running_version
        if running_vid and self._timer_start:
            running_extra = int((datetime.now() - self._timer_start).total_seconds())

        # only include versions that were tested/completed or have results saved
        completed_ids = set(k for k, v in self.session.get('completed', {}).items() if v)
        saved_ids = set(results.keys())
        tested_ids = completed_ids | saved_ids

        # compute total time across tested versions (include running timer if active)
        total_all = 0
        for v in get_active_versions():
            vid = v['id']
            if vid not in tested_ids:
                continue
            sec = int(timing.get(vid, 0))
            if running_vid == vid:
                sec += running_extra
            total_all += sec

        html = ["<html><head><meta charset='utf-8'><title>Steam Emulator Test Report</title>",
                "<style>"
                ":root{--text:#222;--muted:#666;--border:#ccc;--bg:#f5f5f5;}"
                "body{font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:var(--text);line-height:1.35;margin:20px;}"
                "h1{font-size:22px;margin:0 0 8px;}"
                "h2{font-size:18px;margin:18px 0 6px;}"
                "p{margin:4px 0 8px;}"
                "table{border-collapse:collapse;width:100%;font-size:13px;}"
                "th,td{border:1px solid var(--border);padding:6px;vertical-align:top;}"
                "th{background:var(--bg);text-align:left;}"
                ".meta{font-size:13px;color:var(--muted);}"
                ".notes{font-size:13px;line-height:1.35;}"
                ".notes *{font-size:inherit !important;line-height:inherit;}"
                "details.version{border:1px solid #e5e5e5;border-radius:6px;padding:10px;margin:12px 0;background:#fafafa;}"
                "details.version[open]{background:#fff;}"
                "details.version summary{cursor:pointer;font-weight:600;font-size:15px;list-style:none;}"
                "details.version summary::-webkit-details-marker{display:none;}"
                "details.version summary:before{content:'▸';display:inline-block;margin-right:6px;color:#666;}"
                "details.version[open] summary:before{content:'▾';}"
                ".matrix-wrap{overflow:auto;max-width:100%;margin-top:6px;}"
                ".matrix th{position:sticky;top:0;background:#fff;z-index:2;}"
                ".matrix th:first-child{left:0;z-index:3;}"
                ".matrix .row-label{background:#f9f9f9;white-space:nowrap;position:sticky;left:0;z-index:1;}"
                ".matrix .cell{width:24px;height:18px;border:1px solid var(--border);}"
                ".matrix .cell-empty{background:#fff;border:1px solid #eee;}"
                ".matrix .cell-link{display:block;width:100%;height:100%;text-decoration:none;}"
                ".legend{margin-top:8px;display:flex;gap:12px;align-items:center;font-size:13px;}"
                ".legend-item{display:flex;gap:6px;align-items:center;}"
                ".legend-swatch{width:18px;height:12px;border:1px solid var(--border);}"
                "</style>",
                "</head><body>"]
        # add modal/lightbox HTML + JS so data:image thumbnails open in a popup
        html.append('''
<div id="imgModal" style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;overflow:auto;background-color:rgba(0,0,0,0.8);text-align:center;">
    <span style="position:absolute;right:20px;top:20px;color:#fff;font-size:30px;cursor:pointer;" onclick="document.getElementById('imgModal').style.display='none'">&times;</span>
    <img id="imgModalImg" src="" style="max-width:90%;max-height:90%;margin-top:3%;cursor:pointer;" />
</div>
<script>
// open data:image modal when clicking anchors to embedded images
document.addEventListener('click', function(e){
    var a = e.target.closest && e.target.closest('a');
    if(!a) return;
    var href = a.getAttribute && a.getAttribute('href');
    if(href && href.indexOf('data:image') === 0){
        e.preventDefault();
        var img = document.getElementById('imgModalImg');
        img.src = href;
        document.getElementById('imgModal').style.display = 'block';
    }
});
// clicking the full image closes the modal
document.getElementById('imgModalImg').addEventListener('click', function(){
    document.getElementById('imgModal').style.display = 'none';
});
</script>
''')
        html.append("<h1>Steam Emulator Test Report</h1>")
        # include total testing time next to the generated timestamp
        html.append(f"<p class='meta'><strong>Tester:</strong> {meta.get('tester','')} &nbsp; <strong>Commit:</strong> {meta.get('commit','')} &nbsp; <strong>Report Generated:</strong> {now} &nbsp; <strong>Total testing time:</strong> {self.format_seconds(total_all)}</p>")
        html.append(f"<p class='meta'><strong>Test Type:</strong> " + ("WAN" if meta.get('WAN') else "") + (" / " if meta.get('WAN') and meta.get('LAN') else "") + ("LAN" if meta.get('LAN') else "") + "</p>")

        def anchor_id(value):
            return re.sub(r'[^a-zA-Z0-9_-]+', '-', str(value)).strip('-').lower()

        # build summary matrix chart (placed above version details)
        # columns: all tests in TESTS order; rows: tested versions (same order)
        test_cols = [t[0] for t in TESTS]
        test_names = {t[0]: t[1] for t in TESTS}

        # color mapping
        color_map = {
            'Working': '#3498db',
            'Semi-working': '#f1c40f',
            'Not working': '#e74c3c',
            'N/A': '#95a5a6',
            'SKIP': '#95a5a6'
        }

        matrix = ['<h2>Test Matrix Overview</h2>']
        matrix.append('<div class="matrix-wrap">')
        matrix.append('<table class="matrix">')
        # header row
        header_cells = ['<th>Packages</th>']
        for tc in test_cols:
            label = html_lib.escape(f"{tc} {test_names.get(tc,'')}")
            header_cells.append(f'<th style="white-space:nowrap;">{label}</th>')
        matrix.append('<tr>' + ''.join(header_cells) + '</tr>')

        for v in get_active_versions():
            vid = v['id']
            if vid not in tested_ids:
                continue
            skip = set(v.get('skip_tests', []))
            packages = v.get('packages', [])
            row_label = ', '.join(packages) if packages else vid
            ver_anchor = anchor_id(vid)
            row = [f'<td class="row-label">{html_lib.escape(row_label)}</td>']
            saved = results.get(vid, {})
            for tc in test_cols:
                if tc in skip:
                    color = color_map.get('SKIP')
                    cell = f'<td class="cell" style="background:{color};"></td>'
                else:
                    st = saved.get(tc, {}).get('status', '')
                    row_anchor = f"test-{ver_anchor}-{anchor_id(tc)}"
                    title = html_lib.escape(f"{vid} - Test {tc}")
                    if not st:
                        cell = f'<td class="cell cell-empty"><a class="cell-link" href="#{row_anchor}" title="{title}"></a></td>'
                    else:
                        color = color_map.get(st, '#ffffff')
                        cell = f'<td class="cell" style="background:{color};"><a class="cell-link" href="#{row_anchor}" title="{title}"></a></td>'
                row.append(cell)
            matrix.append('<tr>' + ''.join(row) + '</tr>')

        matrix.append('</table>')
        matrix.append('</div>')

        # legend
        legend = ['<div class="legend">']
        legend.append('<div class="legend-item"><div class="legend-swatch" style="background:#3498db"></div><div>Working</div></div>')
        legend.append('<div class="legend-item"><div class="legend-swatch" style="background:#f1c40f"></div><div>Semi-working</div></div>')
        legend.append('<div class="legend-item"><div class="legend-swatch" style="background:#e74c3c"></div><div>Not working</div></div>')
        legend.append('<div class="legend-item"><div class="legend-swatch" style="background:#95a5a6"></div><div>N/A / Skipped</div></div>')
        legend.append('</div>')

        html.extend(matrix)
        html.extend(legend)
        html.append("<div style='margin:10px 0;'>"
                    "<button type='button' onclick='setAllVersions(true)'>Expand all</button> "
                    "<button type='button' onclick='setAllVersions(false)'>Collapse all</button>"
                    "</div>")
        html.append("<script>"
                    "function setAllVersions(open){"
                    "var nodes=document.querySelectorAll('details.version');"
                    "nodes.forEach(function(d){d.open=open;});"
                    "}"
                    "</script>")
        for v in get_active_versions():
            vid = v['id']
            if vid not in tested_ids:
                continue
            skip = set(v.get('skip_tests', []))
            # time for this version
            sec = int(timing.get(vid, 0))
            if running_vid == vid:
                sec += running_extra
            ver_anchor = anchor_id(vid)
            html.append(f"<details class='version'><summary id='ver-{ver_anchor}'>{vid}</summary>")
            html.append(f"<p class='meta'><strong>Packages:</strong> {', '.join(v.get('packages',[]))} &nbsp; <strong>steam_date:</strong> {v.get('steam_date')} &nbsp; <strong>steam_time:</strong> {v.get('steam_time')}</p>")
            html.append(f"<p class='meta'><strong>Time spent testing:</strong> {self.format_seconds(sec)}</p>")
            html.append('<table>')
            html.append('<tr><th>Test #</th><th>Test</th><th>Expected</th><th>Status</th><th>Notes</th></tr>')
            saved = results.get(vid, {})
            for tnum, tname, texp in TESTS:
                if tnum in skip:
                    continue
                r = saved.get(tnum, {})
                status = r.get('status', '')
                raw_notes = r.get('notes', '') or ''
                # if notes appear to already be HTML (contains tags, code blocks, or embedded image), use directly
                if '<img' in raw_notes or '<pre' in raw_notes or '<code' in raw_notes or '<p' in raw_notes or '<br' in raw_notes or '&lt;' in raw_notes:
                    notes = raw_notes
                else:
                    # escape plain text and preserve newlines
                    notes = html_lib.escape(raw_notes).replace('\n', '<br>')
                row_anchor = f"test-{ver_anchor}-{anchor_id(tnum)}"
                html.append(f"<tr id='{row_anchor}'><td>{tnum}</td><td>{tname}</td><td>{texp}</td><td>{status}</td><td><div class='notes'>{notes}</div></td></tr>")
            html.append('</table>')
            html.append('</details>')
        html.append('</body></html>')
        return '\n'.join(html)

    def _setup_keyboard_shortcuts(self):
        """Set up global keyboard shortcuts for the application."""
        # Navigation shortcuts
        # Ctrl+1: Go to Intro page
        shortcut_intro = QShortcut(QKeySequence("Ctrl+1"), self.window)
        shortcut_intro.activated.connect(lambda: self.stack.setCurrentWidget(self.intro))

        # Ctrl+2: Go to Versions page
        shortcut_versions = QShortcut(QKeySequence("Ctrl+2"), self.window)
        shortcut_versions.activated.connect(self.show_versions)

        # Ctrl+3: Go to Tests page (if a version is selected)
        shortcut_tests = QShortcut(QKeySequence("Ctrl+3"), self.window)
        shortcut_tests.activated.connect(lambda: self.stack.setCurrentWidget(self.tests) if self.current_version else None)

        # Ctrl+S: Save session
        shortcut_save = QShortcut(QKeySequence("Ctrl+S"), self.window)
        shortcut_save.activated.connect(self._shortcut_save)

        # Ctrl+E: Export report
        shortcut_export = QShortcut(QKeySequence("Ctrl+E"), self.window)
        shortcut_export.activated.connect(self.export_report)

        # Ctrl+U: Upload to panel
        shortcut_upload = QShortcut(QKeySequence("Ctrl+U"), self.window)
        shortcut_upload.activated.connect(self._shortcut_upload)

        # Ctrl+R: Reload session
        shortcut_reload = QShortcut(QKeySequence("Ctrl+R"), self.window)
        shortcut_reload.activated.connect(self.load_session)

        # Ctrl+T: Check retests
        shortcut_retests = QShortcut(QKeySequence("Ctrl+T"), self.window)
        shortcut_retests.activated.connect(self._shortcut_check_retests)

        # F5: Reload/refresh current view
        shortcut_refresh = QShortcut(QKeySequence("F5"), self.window)
        shortcut_refresh.activated.connect(self._shortcut_refresh)

        # Ctrl+F: Finish test (when on tests page)
        shortcut_finish = QShortcut(QKeySequence("Ctrl+F"), self.window)
        shortcut_finish.activated.connect(self._shortcut_finish_test)

        # Escape: Go back / cancel
        shortcut_back = QShortcut(QKeySequence("Escape"), self.window)
        shortcut_back.activated.connect(self._shortcut_back)

        # F1: Show keyboard shortcuts help
        shortcut_help = QShortcut(QKeySequence("F1"), self.window)
        shortcut_help.activated.connect(self._show_shortcuts_help)

        # Ctrl+L: Attach log files (when on tests page)
        shortcut_attach = QShortcut(QKeySequence("Ctrl+L"), self.window)
        shortcut_attach.activated.connect(self._shortcut_attach_log)

        # Alt+Up/Down: Navigate tests list when on versions page
        shortcut_prev_version = QShortcut(QKeySequence("Alt+Up"), self.window)
        shortcut_prev_version.activated.connect(self._shortcut_prev_version)

        shortcut_next_version = QShortcut(QKeySequence("Alt+Down"), self.window)
        shortcut_next_version.activated.connect(self._shortcut_next_version)

        # Space/Enter: Start timer when on tests page
        shortcut_timer = QShortcut(QKeySequence("Ctrl+Space"), self.window)
        shortcut_timer.activated.connect(self._shortcut_toggle_timer)

        # ==================== Test Status & Navigation Shortcuts ====================
        # These shortcuts only work on the Tests page

        # 1/2/3/4: Set test status (Working/Semi-working/Not working/N/A)
        shortcut_status_1 = QShortcut(QKeySequence("1"), self.window)
        shortcut_status_1.activated.connect(lambda: self._shortcut_set_status(1))

        shortcut_status_2 = QShortcut(QKeySequence("2"), self.window)
        shortcut_status_2.activated.connect(lambda: self._shortcut_set_status(2))

        shortcut_status_3 = QShortcut(QKeySequence("3"), self.window)
        shortcut_status_3.activated.connect(lambda: self._shortcut_set_status(3))

        shortcut_status_4 = QShortcut(QKeySequence("4"), self.window)
        shortcut_status_4.activated.connect(lambda: self._shortcut_set_status(4))

        # Tab/Enter: Next test (only when not in text field)
        shortcut_next_test = QShortcut(QKeySequence("Ctrl+Down"), self.window)
        shortcut_next_test.activated.connect(self._shortcut_next_test)

        # Also allow Enter for next test (common workflow)
        shortcut_next_test_enter = QShortcut(QKeySequence("Ctrl+Return"), self.window)
        shortcut_next_test_enter.activated.connect(self._shortcut_next_test)

        # Shift+Tab: Previous test
        shortcut_prev_test = QShortcut(QKeySequence("Ctrl+Up"), self.window)
        shortcut_prev_test.activated.connect(self._shortcut_prev_test)

        # Ctrl+N: Focus notes field for current test
        shortcut_focus_notes = QShortcut(QKeySequence("Ctrl+N"), self.window)
        shortcut_focus_notes.activated.connect(self._shortcut_focus_notes)

    def _shortcut_set_status(self, status_index):
        """Handle 1/2/3/4 shortcut - set test status."""
        if self.stack.currentWidget() == self.tests:
            self.tests.set_current_test_status(status_index)
            # Auto-advance to next test after setting status
            self.tests.next_test()

    def _shortcut_next_test(self):
        """Handle Ctrl+Down/Ctrl+Enter shortcut - next test."""
        if self.stack.currentWidget() == self.tests:
            self.tests.next_test()

    def _shortcut_prev_test(self):
        """Handle Ctrl+Up shortcut - previous test."""
        if self.stack.currentWidget() == self.tests:
            self.tests.prev_test()

    def _shortcut_focus_notes(self):
        """Handle Ctrl+N shortcut - focus notes field."""
        if self.stack.currentWidget() == self.tests:
            self.tests.focus_current_notes()

    def _shortcut_save(self):
        """Handle Ctrl+S shortcut - save session."""
        self.save_session()
        QMessageBox.information(self.window, "Saved", "Session saved successfully.")

    def _shortcut_upload(self):
        """Handle Ctrl+U shortcut - upload to panel."""
        if self.stack.currentWidget() == self.versions:
            self.versions.upload_to_panel()
        else:
            QMessageBox.information(self.window, "Hint", "Go to the Versions page (Ctrl+2) to upload to panel.")

    def _shortcut_check_retests(self):
        """Handle Ctrl+T shortcut - check retests."""
        if self.stack.currentWidget() == self.versions:
            self.versions.check_retests()
        else:
            QMessageBox.information(self.window, "Hint", "Go to the Versions page (Ctrl+2) to check retests.")

    def _shortcut_refresh(self):
        """Handle F5 shortcut - refresh current view."""
        current = self.stack.currentWidget()
        if current == self.versions:
            self.versions.populate()
        elif current == self.tests and self.current_version:
            self.tests.load_tests(self.current_version)

    def _shortcut_finish_test(self):
        """Handle Ctrl+F shortcut - finish test."""
        if self.stack.currentWidget() == self.tests:
            self.tests.finish()
        else:
            QMessageBox.information(self.window, "Hint", "Go to the Tests page (Ctrl+3) to finish a test.")

    def _shortcut_back(self):
        """Handle Escape shortcut - go back."""
        current = self.stack.currentWidget()
        if current == self.tests:
            self.show_versions()
        elif current == self.versions:
            self.stack.setCurrentWidget(self.intro)

    def _shortcut_attach_log(self):
        """Handle Ctrl+L shortcut - attach log files."""
        if self.stack.currentWidget() == self.tests:
            self.tests.attach_log()

    def _shortcut_prev_version(self):
        """Handle Alt+Up shortcut - previous version."""
        if self.stack.currentWidget() == self.versions:
            lw = self.versions.list_widget
            current = lw.currentRow()
            if current > 0:
                lw.setCurrentRow(current - 1)

    def _shortcut_next_version(self):
        """Handle Alt+Down shortcut - next version."""
        if self.stack.currentWidget() == self.versions:
            lw = self.versions.list_widget
            current = lw.currentRow()
            if current < lw.count() - 1:
                lw.setCurrentRow(current + 1)

    def _shortcut_toggle_timer(self):
        """Handle Ctrl+Space shortcut - toggle stopwatch."""
        if self.stack.currentWidget() == self.tests:
            if self.tests.stopwatch_running:
                self.tests.stop_stopwatch()
            else:
                self.tests.start_stopwatch()

    def _show_shortcuts_help(self):
        """Show keyboard shortcuts help dialog."""
        shortcuts_text = """
<h3>Keyboard Shortcuts</h3>

<h4 style="color: #2196f3; margin-top: 15px;">Test Status (Tests Page)</h4>
<table style="border-collapse: collapse; width: 100%;">
<tr><th style="text-align:left; padding: 4px; border-bottom: 1px solid #ccc;">Shortcut</th><th style="text-align:left; padding: 4px; border-bottom: 1px solid #ccc;">Action</th></tr>
<tr><td style="padding: 4px;"><b>1</b></td><td style="padding: 4px;">Set status: Working (then advance)</td></tr>
<tr><td style="padding: 4px;"><b>2</b></td><td style="padding: 4px;">Set status: Semi-working (then advance)</td></tr>
<tr><td style="padding: 4px;"><b>3</b></td><td style="padding: 4px;">Set status: Not working (then advance)</td></tr>
<tr><td style="padding: 4px;"><b>4</b></td><td style="padding: 4px;">Set status: N/A (then advance)</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+Down</b></td><td style="padding: 4px;">Next test</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+Up</b></td><td style="padding: 4px;">Previous test</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+Enter</b></td><td style="padding: 4px;">Next test (alternate)</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+N</b></td><td style="padding: 4px;">Focus notes field</td></tr>
</table>

<h4 style="color: #27ae60; margin-top: 15px;">Navigation</h4>
<table style="border-collapse: collapse; width: 100%;">
<tr><th style="text-align:left; padding: 4px; border-bottom: 1px solid #ccc;">Shortcut</th><th style="text-align:left; padding: 4px; border-bottom: 1px solid #ccc;">Action</th></tr>
<tr><td style="padding: 4px;"><b>Ctrl+1</b></td><td style="padding: 4px;">Go to Intro page</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+2</b></td><td style="padding: 4px;">Go to Versions page</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+3</b></td><td style="padding: 4px;">Go to Tests page</td></tr>
<tr><td style="padding: 4px;"><b>Escape</b></td><td style="padding: 4px;">Go back</td></tr>
<tr><td style="padding: 4px;"><b>Alt+Up/Down</b></td><td style="padding: 4px;">Navigate versions list</td></tr>
</table>

<h4 style="color: #9b59b6; margin-top: 15px;">Session & Reports</h4>
<table style="border-collapse: collapse; width: 100%;">
<tr><th style="text-align:left; padding: 4px; border-bottom: 1px solid #ccc;">Shortcut</th><th style="text-align:left; padding: 4px; border-bottom: 1px solid #ccc;">Action</th></tr>
<tr><td style="padding: 4px;"><b>Ctrl+S</b></td><td style="padding: 4px;">Save session</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+R</b></td><td style="padding: 4px;">Reload session</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+E</b></td><td style="padding: 4px;">Export HTML report</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+U</b></td><td style="padding: 4px;">Upload to panel</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+T</b></td><td style="padding: 4px;">Check retests</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+F</b></td><td style="padding: 4px;">Finish current test</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+L</b></td><td style="padding: 4px;">Attach log files</td></tr>
<tr><td style="padding: 4px;"><b>Ctrl+Space</b></td><td style="padding: 4px;">Toggle stopwatch</td></tr>
<tr><td style="padding: 4px;"><b>F5</b></td><td style="padding: 4px;">Refresh current view</td></tr>
<tr><td style="padding: 4px;"><b>F1</b></td><td style="padding: 4px;">Show this help</td></tr>
</table>
"""
        dlg = QDialog(self.window)
        dlg.setWindowTitle("Keyboard Shortcuts")
        dlg.setMinimumSize(450, 580)
        layout = QVBoxLayout()
        label = QLabel(shortcuts_text)
        label.setWordWrap(True)
        layout.addWidget(label)
        close_btn = QPushButton("Close")
        close_btn.clicked.connect(dlg.accept)
        layout.addWidget(close_btn)
        dlg.setLayout(layout)
        dlg.exec_()

    def run(self):
        # Setup keyboard shortcuts before showing window
        self._setup_keyboard_shortcuts()
        self.window.resize(950, 800)  # Larger to accommodate API settings
        self.window.show()
        sys.exit(self.app.exec_())

if __name__ == '__main__':
    c = Controller()
    c.run()
