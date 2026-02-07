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
import threading
from datetime import datetime, timedelta
from PyQt5 import QtWidgets, QtCore
from PyQt5.QtWidgets import (QApplication, QWidget, QVBoxLayout, QLabel, QLineEdit,
                             QPushButton, QFileDialog, QCheckBox, QStackedWidget, QListWidget,
                             QListWidgetItem, QHBoxLayout, QTextEdit, QMessageBox, QScrollArea, QFrame,
                             QButtonGroup, QRadioButton, QDialog, QGroupBox, QComboBox, QShortcut,
                             QProgressBar, QSizePolicy)
from PyQt5.QtCore import QDate, QUrl, Qt
from PyQt5.QtGui import QPixmap, QImage, QDesktopServices, QTextCursor, QColor, QKeySequence, QTextCharFormat
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
# Format: list of dicts with id, packages, steam_date, steam_time (skip_tests deprecated, use templates)
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


def get_version_storage_key(vid, commit_hash=None):
    """Generate a storage key for version results.

    When commit_hash is provided, returns a composite key for commit-specific storage.
    When commit_hash is empty/None, returns just the version ID for backward compatibility.

    Args:
        vid: Version ID (e.g., "Jun2003")
        commit_hash: Commit hash string (e.g., "abc123...")

    Returns:
        Storage key string
    """
    if commit_hash:
        return f"{vid}|{commit_hash}"
    return vid


def parse_version_storage_key(storage_key):
    """Parse a storage key into version ID and commit hash.

    Args:
        storage_key: Storage key (either 'vid' or 'vid|commit')

    Returns:
        Tuple of (version_id, commit_hash or None)
    """
    if '|' in storage_key:
        parts = storage_key.split('|', 1)
        return parts[0], parts[1]
    return storage_key, None


def get_results_for_version(session, vid, commit_hash=None):
    """Get results for a version, trying commit-specific key first.

    Looks up results by:
    1. Commit-specific key (vid|commit) if commit_hash is provided
    2. Falls back to version-only key (vid) for backward compatibility

    Args:
        session: The session dict containing 'results'
        vid: Version ID
        commit_hash: Current commit hash (optional)

    Returns:
        Results dict for the version, or empty dict if not found
    """
    results = session.get('results', {})

    if commit_hash:
        # Try commit-specific key first
        key = get_version_storage_key(vid, commit_hash)
        if key in results:
            return results[key]

    # Fall back to version-only key (backward compatibility)
    return results.get(vid, {})


def is_version_completed(session, vid, commit_hash=None):
    """Check if a version is completed for the given commit.

    Args:
        session: The session dict containing 'completed'
        vid: Version ID
        commit_hash: Current commit hash (optional)

    Returns:
        True if version is completed for the commit, False otherwise
    """
    completed = session.get('completed', {})

    if commit_hash:
        # Try commit-specific key first
        key = get_version_storage_key(vid, commit_hash)
        if key in completed:
            return completed[key]

    # Fall back to version-only key (backward compatibility)
    return completed.get(vid, False)


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
                    cursor = self.textCursor()
                    cursor.beginEditBlock()
                    cursor.insertHtml(html)
                    # Reset character format to default after inserting code blocks
                    default_format = QTextCharFormat()
                    cursor.setCharFormat(default_format)
                    cursor.endEditBlock()
                    self.setTextCursor(cursor)
                    return
                except Exception:
                    pass

        # if clipboard contains image data, embed it as base64 PNG
        if source.hasImage():
            image = source.imageData()
            try:
                # full image bytes - only store the full image
                ba_full = QtCore.QByteArray()
                buf_full = QtCore.QBuffer(ba_full)
                buf_full.open(QtCore.QIODevice.WriteOnly)
                image.save(buf_full, 'PNG')
                b64_full = ba_full.toBase64().data().decode('ascii')

                # calculate thumbnail display dimensions (maintaining aspect ratio)
                # Qt's QTextEdit doesn't support CSS max-width, so we use explicit width/height
                thumb_max_w = 125
                thumb_max_h = 100
                orig_w = image.width()
                orig_h = image.height()

                # scale to fit within thumbnail bounds while maintaining aspect ratio
                if orig_w > 0 and orig_h > 0:
                    scale_w = thumb_max_w / orig_w
                    scale_h = thumb_max_h / orig_h
                    scale = min(scale_w, scale_h, 1.0)  # don't upscale small images
                    display_w = int(orig_w * scale)
                    display_h = int(orig_h * scale)
                else:
                    display_w = thumb_max_w
                    display_h = thumb_max_h

                # insert clickable image - uses full image with explicit dimensions for thumbnail display
                # clicking opens the full-size image via the anchor href
                img_html = f'<a href="data:image/png;base64,{b64_full}"><img src="data:image/png;base64,{b64_full}" width="{display_w}" height="{display_h}" title="Click to view full size" /></a>'
                # add a small spacer after image
                img_html += '<br/>'
                cursor = self.textCursor()
                cursor.insertHtml(img_html)
                # Reset character format to default after inserting anchor/image
                # This prevents subsequent text from inheriting the link styling
                default_format = QTextCharFormat()
                cursor.setCharFormat(default_format)
                self.setTextCursor(cursor)
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
        cursor.beginEditBlock()
        cursor.insertHtml(block)
        # Reset character format to default after code block
        default_format = QTextCharFormat()
        cursor.setCharFormat(default_format)
        cursor.insertText('\n')
        cursor.endEditBlock()
        self.setTextCursor(cursor)

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
        # Reset character format to default after code block
        # This prevents the code block styling from bleeding into subsequent text
        default_format = QTextCharFormat()
        cursor.setCharFormat(default_format)
        # Insert a line break with default formatting to ensure clean separation
        cursor.insertText('\n')
        cursor.endEditBlock()
        # Update the text cursor in the editor to use the reset format
        self.setTextCursor(cursor)

    # Unique markers that Qt will preserve as text - used to identify code blocks after toHtml()
    CODE_MARKER_START = "⟦CODE⟧"
    CODE_MARKER_END = "⟦/CODE⟧"

    def build_code_block(self, code_text):
        highlighted = self.highlight_python(code_text)
        # Include unique text markers that Qt preserves - these help identify code blocks
        # when the HTML is later processed by clean_notes()
        return f"{self.CODE_MARKER_START}<pre style=\"{self.CODE_BLOCK_STYLE}\"><code>{highlighted}</code></pre>{self.CODE_MARKER_END}"

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


def prepare_notes_for_editor(notes: str) -> str:
    """
    Prepare saved notes for display in the Qt editor.

    Converts clean <pre><code> blocks back to styled HTML that Qt can render properly.
    Also converts {{IMAGE:...}} markers back to inline images.

    Args:
        notes: Cleaned notes string with <pre><code> blocks

    Returns:
        HTML suitable for Qt's setHtml()
    """
    if not notes:
        return notes

    # Define the code block style (must match ImageTextEdit.CODE_BLOCK_STYLE)
    code_block_style = (
        "background:#111;"
        "color:#eee;"
        "font-family:Consolas,'Courier New',monospace;"
        "font-size:12px;"
        "line-height:1.4;"
        "padding:8px;"
        "border-radius:6px;"
        "white-space:pre-wrap;"
    )

    # Convert <pre><code> blocks to styled pre tags for Qt
    def style_code_block(match):
        code_content = match.group(1)
        # Escape is already done, just wrap in styled pre
        return f'<pre style="{code_block_style}"><code>{code_content}</code></pre>'

    notes = re.sub(
        r'<pre[^>]*>\s*<code[^>]*>([\s\S]*?)</code>\s*</pre>',
        style_code_block,
        notes,
        flags=re.IGNORECASE
    )

    # Convert {{IMAGE:data:...}} markers back to inline images
    def restore_image(match):
        data_uri = match.group(1)
        return f'<a href="{data_uri}"><img src="{data_uri}" width="125" height="100"/></a>'

    notes = re.sub(
        r'\{\{IMAGE:(data:image/[^}]+)\}\}',
        restore_image,
        notes
    )

    return notes


def convert_old_thumbnail_format(html: str) -> str:
    """
    Convert old thumbnail format to new format in HTML notes.

    Old format stored separate thumbnail and full images:
        <a href="data:image/png;base64,{FULL}"><img src="data:image/png;base64,{THUMB}" style="..."/></a>

    New format uses single full image with width/height for display:
        <a href="data:image/png;base64,{FULL}"><img src="data:image/png;base64,{FULL}" width="125" height="100" .../></a>

    Args:
        html: HTML string possibly containing old thumbnail format

    Returns:
        HTML with old thumbnails converted to new format
    """
    if not html:
        return html

    # Pattern to match anchor with data:image href containing img with different data:image src
    # Captures: full href, the img tag, and the thumbnail src
    pattern = re.compile(
        r'<a\s+[^>]*href=["\']?(data:image/[^"\'>\s]+)["\']?[^>]*>'  # anchor with data:image href
        r'\s*<img\s+([^>]*?)src=["\']?(data:image/[^"\'>\s]+)["\']?([^>]*)/?>'  # img with src
        r'\s*</a>',
        re.IGNORECASE | re.DOTALL
    )

    def replace_thumbnail(match):
        full_image = match.group(1)  # href (full image)
        img_attrs_before = match.group(2)  # attributes before src
        thumb_image = match.group(3)  # src (thumbnail)
        img_attrs_after = match.group(4)  # attributes after src

        # If href and src are the same, already in new format
        if full_image == thumb_image:
            return match.group(0)

        # Check if this looks like old format (different images)
        # Build new img tag using full image with thumbnail display size
        # Remove old style/width/height attributes and add new ones
        attrs = img_attrs_before + img_attrs_after
        # Remove old style attribute
        attrs = re.sub(r'\s*style=["\'][^"\']*["\']', '', attrs, flags=re.IGNORECASE)
        # Remove old width/height attributes
        attrs = re.sub(r'\s*width=["\']?\d+["\']?', '', attrs, flags=re.IGNORECASE)
        attrs = re.sub(r'\s*height=["\']?\d+["\']?', '', attrs, flags=re.IGNORECASE)
        attrs = attrs.strip()

        # Build new img tag with full image and thumbnail dimensions
        new_img = f'<img src="{full_image}" width="125" height="100" title="Click to view full size"'
        if attrs:
            new_img += f' {attrs}'
        new_img += ' />'

        return f'<a href="{full_image}">{new_img}</a>'

    return pattern.sub(replace_thumbnail, html)


def convert_markdown_code_blocks_to_html(text: str) -> str:
    """
    Convert markdown code blocks (```code```) to HTML <pre><code> tags.

    Supports:
    - ```language\\ncode\\n``` (with language specifier)
    - ```\\ncode\\n``` (no language)
    - ```code``` (inline on same line)

    Args:
        text: Text containing markdown code blocks

    Returns:
        Text with code blocks converted to HTML
    """
    # Match ```language\ncode\n``` or ```code``` patterns
    # Language is optional, handles \r\n line endings
    def replace_code_block(match):
        lang = match.group(1) or ''
        code = match.group(2)
        # Skip if the content is empty or just whitespace
        if not code.strip():
            return match.group(0)
        # Trim leading/trailing newlines from code content
        code = code.strip('\r\n')
        # Escape HTML entities in the code
        code = html_lib.escape(code)
        # Build HTML - use data-language attribute for optional language
        lang_attr = f' data-language="{html_lib.escape(lang)}"' if lang else ''
        return f'<pre class="code-block"{lang_attr}><code>{code}</code></pre>'

    # Match triple backticks with optional language specifier
    # Pattern: ```lang\ncode\n``` or ```code```
    code_block_pattern = r'```(\w*)[\r\n]*([\s\S]*?)```'
    return re.sub(code_block_pattern, replace_code_block, text)


def clean_notes(notes: str) -> str:
    """
    Clean notes text to match PHP's cleanNotes() function.

    This converts Qt rich text HTML to plain text/markdown format,
    extracting embedded images and reformatting them.
    Markdown code blocks (```) are converted to HTML <pre><code> tags.
    Qt-styled code blocks (with background:#111) are detected and preserved.

    The output MUST match PHP's cleanNotes() exactly for hash comparison.

    Args:
        notes: Raw notes string, possibly containing Qt HTML or markdown

    Returns:
        Cleaned notes string with code blocks as HTML
    """
    if not notes:
        return ''

    # Check if this already looks like markdown (not HTML)
    # Convert markdown code blocks to HTML before further processing
    has_markdown_code_blocks = re.search(r'```[\s\S]*?```', notes)
    has_markdown_images = re.search(r'!\[[^\]]*\]\([^)]+\)', notes)
    has_image_markers = re.search(r'\[image:data:image/', notes) or re.search(r'\{\{IMAGE:data:image/', notes)

    if has_markdown_code_blocks or has_markdown_images or has_image_markers:
        # Clean up Qt CSS first
        text = notes.replace('p, li { white-space: pre-wrap; }', '')

        # Convert markdown code blocks (```) to HTML <pre><code> tags
        if has_markdown_code_blocks:
            text = convert_markdown_code_blocks_to_html(text)

        return text.strip()

    # First convert old thumbnail format to new format
    # This ensures we only extract the full image, not separate thumbnails
    notes = convert_old_thumbnail_format(notes)

    # Extract embedded images from Qt HTML before stripping tags
    # Qt sends images as: <a href="data:image/png;base64,..."><img src="..."/></a>
    # After conversion, href and src are the same (full image), so deduplicate
    extracted_images = []
    seen_images = set()

    # Match anchor tags with data:image hrefs (Qt format) - prefer href as it's the full image
    for match in re.finditer(r'<a\s+[^>]*href=["\']?(data:image/[^"\'>\s]+)["\']?[^>]*>', notes, re.IGNORECASE):
        img_data = match.group(1)
        if img_data not in seen_images:
            extracted_images.append(img_data)
            seen_images.add(img_data)

    # Also match img tags with data URIs (only add if not already seen from anchor)
    for match in re.finditer(r'<img\s+[^>]*src=["\']?(data:image/[^"\'>\s]+)["\']?[^>]*>', notes, re.IGNORECASE):
        img_data = match.group(1)
        if img_data not in seen_images:
            extracted_images.append(img_data)
            seen_images.add(img_data)

    # IMPORTANT: Qt's toHtml() does NOT preserve <pre><code> tags!
    # We use unique text markers (⟦CODE⟧ and ⟦/CODE⟧) that Qt preserves as text.
    # These markers let us identify code blocks even after Qt transforms the HTML.
    code_blocks = []
    CODE_MARKER_START = "⟦CODE⟧"
    CODE_MARKER_END = "⟦/CODE⟧"

    def extract_marked_code_block(match):
        """Extract code text from marker-delimited code block."""
        code_html = match.group(1)
        # Convert HTML line breaks to newlines BEFORE stripping tags
        code_html = re.sub(r'<br\s*/?>', '\n', code_html, flags=re.IGNORECASE)
        # Convert paragraph/div endings to newlines
        code_html = re.sub(r'</p>\s*<p[^>]*>', '\n', code_html, flags=re.IGNORECASE)
        code_html = re.sub(r'</div>\s*<div[^>]*>', '\n', code_html, flags=re.IGNORECASE)
        # Add space between adjacent HTML elements to preserve word spacing
        # e.g., <span>word1</span><span>word2</span> -> word1 word2
        code_html = re.sub(r'>\s*<', '> <', code_html)
        # Strip all HTML tags to get plain text
        code_text = re.sub(r'<[^>]+>', '', code_html)
        # Decode HTML entities (this also converts &nbsp; to space)
        code_text = html_lib.unescape(code_text)
        # Re-escape for safe HTML
        code_text = html_lib.escape(code_text)

        # Save and return placeholder
        placeholder = f"__CODE_BLOCK_{len(code_blocks)}__"
        code_blocks.append(f'<pre><code>{code_text}</code></pre>')
        return placeholder

    # Match code blocks by our unique markers (these survive Qt's HTML transformation)
    # The pattern captures everything between ⟦CODE⟧ and ⟦/CODE⟧
    notes = re.sub(
        re.escape(CODE_MARKER_START) + r'([\s\S]*?)' + re.escape(CODE_MARKER_END),
        extract_marked_code_block,
        notes
    )

    # Also check for explicit <pre><code> blocks (in case they come from other sources)
    def clean_explicit_code_block(match):
        code_html = match.group(1)
        # Convert HTML line breaks to newlines BEFORE stripping tags
        code_html = re.sub(r'<br\s*/?>', '\n', code_html, flags=re.IGNORECASE)
        # Convert paragraph/div endings to newlines
        code_html = re.sub(r'</p>\s*<p[^>]*>', '\n', code_html, flags=re.IGNORECASE)
        code_html = re.sub(r'</div>\s*<div[^>]*>', '\n', code_html, flags=re.IGNORECASE)
        # Add space between adjacent HTML elements to preserve word spacing
        code_html = re.sub(r'>\s*<', '> <', code_html)
        code_text = re.sub(r'<[^>]+>', '', code_html)
        code_text = html_lib.unescape(code_text)
        code_text = html_lib.escape(code_text)
        placeholder = f"__CODE_BLOCK_{len(code_blocks)}__"
        code_blocks.append(f'<pre><code>{code_text}</code></pre>')
        return placeholder

    notes = re.sub(
        r'<pre[^>]*>\s*<code[^>]*>([\s\S]*?)</code>\s*</pre>',
        clean_explicit_code_block,
        notes,
        flags=re.IGNORECASE
    )

    # Handle Qt rich text HTML - convert to plain text
    # Convert structural HTML elements to newlines before stripping tags
    # This preserves paragraph breaks, line breaks, and content ordering
    notes = re.sub(r'<br\s*/?>', '\n', notes, flags=re.IGNORECASE)
    notes = re.sub(r'</p>', '\n', notes, flags=re.IGNORECASE)
    notes = re.sub(r'</div>', '\n', notes, flags=re.IGNORECASE)
    # Strip remaining HTML tags (code blocks are already protected as placeholders)
    text = re.sub(r'<[^>]+>', '', notes)
    # Decode HTML entities
    text = html_lib.unescape(text)
    # Remove Qt rich text CSS that leaks through
    text = text.replace('p, li { white-space: pre-wrap; }', '')
    # Clean up "image" link text that Qt leaves behind
    text = re.sub(r'\bimage\b\s*', '', text, flags=re.IGNORECASE)
    text = text.strip()

    # Restore code blocks
    for i, block in enumerate(code_blocks):
        placeholder = f"__CODE_BLOCK_{i}__"
        text = text.replace(placeholder, f"\n{block}\n")

    # Clean up multiple newlines that may result from replacements
    text = re.sub(r'\n{3,}', '\n\n', text)
    text = text.strip()

    # Append extracted images in a format the renderer understands
    if extracted_images:
        for data_uri in extracted_images:
            text += "\n\n{{IMAGE:" + data_uri + "}}"
        text = text.strip()

    return text


def compute_version_hash(results_data: dict, attached_logs: list = None) -> str:
    """
    Compute a hash of the version results and attached logs to detect changes.

    This hash is used for server-side deduplication. The PHP server uses
    the same algorithm, so the hashes MUST match between Python and PHP.

    The notes are cleaned to match PHP's cleanNotes() before hashing,
    ensuring the hash of the data to be stored is compared.

    Args:
        results_data: The results dict for a single version (test results)
        attached_logs: Optional list of attached log dicts for this version

    Returns:
        SHA256 hash string of the data
    """
    # Clean notes to match PHP's cleanNotes() function before hashing
    # This ensures the hash is computed on the same data that gets stored
    cleaned_results = {}
    for test_key, test_data in results_data.items():
        cleaned_results[test_key] = {
            'status': test_data.get('status', ''),
            'notes': clean_notes(test_data.get('notes', ''))
        }

    # Create a deterministic representation of the data
    data_to_hash = {
        'results': cleaned_results,
        'logs': attached_logs or []
    }
    # Sort keys to ensure consistent ordering
    # Use separators without spaces to match PHP's json_encode() output
    json_str = json.dumps(data_to_hash, sort_keys=True, ensure_ascii=True, separators=(',', ':'))
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
        self.pending_label = QLabel("")
        self.pending_label.setStyleSheet("color:#e67e22;font-weight:bold;")
        self.pending_label.setVisible(False)
        self.retry_btn = QPushButton("Retry")
        self.retry_btn.setStyleSheet("background-color:#e67e22;color:white;")
        self.retry_btn.setVisible(False)
        self.retry_btn.setToolTip("Retry sending queued submissions")
        layout.addWidget(QLabel("Select a Steam version to test:"))
        layout.addWidget(self.list_widget)
        hl = QHBoxLayout()
        hl.addWidget(self.reload_btn)
        hl.addWidget(self.retests_btn)
        hl.addStretch()
        hl.addWidget(self.pending_label)
        hl.addWidget(self.retry_btn)
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
        self.retry_btn.clicked.connect(self.retry_pending_submissions)
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

        # Get current commit for commit-specific display
        try:
            current_commit = self.controller.intro.get_metadata().get('commit', '')
        except Exception:
            current_commit = ''

        for v in get_active_versions():
            vid = v['id']
            # Calculate completion percentage using template-filtered tests
            # Try to get version-specific tests from API/cache
            version_tests, _ = self.controller.get_tests_for_version(vid)
            if version_tests:
                # Use template-filtered tests
                test_keys = set(t[0] for t in version_tests)
                total_tests = len(version_tests)
            else:
                # Fall back to all tests if API not available
                test_keys = set(t[0] for t in TESTS)
                total_tests = len(TESTS)
            # Use commit-specific results lookup
            saved_results = get_results_for_version(self.controller.session, vid, current_commit)
            completed_tests = sum(1 for tk in test_keys if saved_results.get(tk, {}).get('status', ''))
            pct = int((completed_tests / total_tests * 100)) if total_tests > 0 else 0

            # Build display text with percentage
            display_text = f"{vid}  [{pct}%]"
            item = QListWidgetItem(display_text)
            item.setData(QtCore.Qt.UserRole, vid)  # Store actual version id

            # Check if this version needs retesting (red background with black text)
            if vid in retest_versions:
                item.setBackground(QColor('#e74c3c'))  # Red background for retests
                item.setForeground(QColor('#000000'))  # Black text for readability
                font = item.font()
                font.setBold(True)
                item.setFont(font)
            # Mark completed visually (highlight most recent)
            elif self.controller.last_completed_version == vid:
                item.setBackground(QColor('#fff3cd'))
            elif is_version_completed(self.controller.session, vid, current_commit):
                item.setBackground(QColor('#d4edda'))  # Light green for completed
                if pct == 100:
                    item.setForeground(QColor('#155724'))  # Dark green text

            self.list_widget.addItem(item)

    def _update_panel_buttons(self):
        """Update panel button states based on availability and pending submissions."""
        if PANEL_AVAILABLE and self.controller.panel and self.controller.panel.is_configured:
            self.upload_btn.setEnabled(True)
            self.retests_btn.setEnabled(True)
            self.upload_btn.setToolTip("Upload results to Test Panel")
            self.retests_btn.setToolTip("Check for pending retests")

            # Show pending submissions count
            pending_count = 0
            if hasattr(self.controller.panel, 'get_pending_submissions_count'):
                pending_count = self.controller.panel.get_pending_submissions_count()

            if pending_count > 0:
                self.pending_label.setText(f"📤 {pending_count} pending")
                self.pending_label.setVisible(True)
                self.retry_btn.setVisible(True)
                self.retry_btn.setToolTip(f"Retry sending {pending_count} queued report(s)")
            else:
                self.pending_label.setVisible(False)
                self.retry_btn.setVisible(False)
        else:
            self.upload_btn.setEnabled(False)
            self.retests_btn.setEnabled(False)
            self.pending_label.setVisible(False)
            self.retry_btn.setVisible(False)
            tip = "Panel not configured - create test_panel_config.json" if PANEL_AVAILABLE else "Panel integration not available"
            self.upload_btn.setToolTip(tip)
            self.retests_btn.setToolTip(tip)

    def retry_pending_submissions(self):
        """Retry sending all pending submissions."""
        if not self.controller.panel or not self.controller.panel.is_configured:
            return

        if not hasattr(self.controller.panel, 'retry_pending_submissions'):
            return

        pending_count = self.controller.panel.get_pending_submissions_count()
        if pending_count == 0:
            QMessageBox.information(self, "No Pending", "No pending submissions to retry.")
            self._update_panel_buttons()
            return

        reply = QMessageBox.question(self, "Retry Submissions",
            f"Retry sending {pending_count} pending submission(s)?",
            QMessageBox.Yes | QMessageBox.No, QMessageBox.Yes)

        if reply != QMessageBox.Yes:
            return

        result = self.controller.panel.retry_pending_submissions()
        succeeded = result.get('succeeded', 0)
        failed = result.get('failed', 0)

        if succeeded > 0 and failed == 0:
            QMessageBox.information(self, "Success",
                f"Successfully sent {succeeded} report(s).")
        elif succeeded > 0 and failed > 0:
            QMessageBox.warning(self, "Partial Success",
                f"Sent {succeeded} report(s), but {failed} failed.\n\n"
                f"Failed submissions will remain in the queue for later retry.")
        elif failed > 0:
            QMessageBox.warning(self, "Still Offline",
                f"Could not send any reports. {failed} submission(s) remain queued.\n\n"
                f"Check your internet connection and try again.")
        else:
            QMessageBox.information(self, "No Change", "No submissions were processed.")

        self._update_panel_buttons()

    def upload_to_panel(self):
        """Upload session results to the test panel, only uploading changed reports."""
        if not self.controller.panel or not self.controller.panel.is_configured:
            QMessageBox.warning(self, "Not Configured",
                "Panel not configured. Create test_panel_config.json with your API URL and key.")
            return

        # Save session first to ensure latest data
        self.controller.save_session()

        # Determine which versions need to be uploaded
        results = self.controller.session.get('results', {})
        attached_logs = self.controller.session.get('attached_logs', {})
        upload_hashes = self.controller.session.get('upload_hashes', {})
        meta = self.controller.session.get('meta', {})

        if not results:
            QMessageBox.warning(self, "No Results",
                "No test results to submit. Please complete some tests first.")
            return

        # Validate commit hash is set
        commit_hash = meta.get('commit', '').strip()
        if not commit_hash:
            QMessageBox.warning(self, "Missing Commit Hash",
                "A commit revision is required to submit reports.\n\n"
                "Please set the commit hash on the intro page before submitting.")
            return

        # Filter out empty reports (no tests with status or notes)
        empty_versions = []
        valid_results = {}
        for storage_key, vid_results in results.items():
            has_content = False
            for test_key, test_data in vid_results.items():
                if isinstance(test_data, dict):
                    status = test_data.get('status', '').strip()
                    notes = test_data.get('notes', '').strip()
                    if status or notes:
                        has_content = True
                        break
            if has_content:
                valid_results[storage_key] = vid_results
            else:
                original_vid, _ = parse_version_storage_key(storage_key)
                empty_versions.append(original_vid)

        if not valid_results:
            QMessageBox.warning(self, "No Content",
                "All reports are empty (no test statuses or notes set).\n\n"
                "Please set test statuses or add notes before submitting.")
            return

        if empty_versions:
            # Warn about empty versions being skipped but continue
            QMessageBox.information(self, "Empty Reports Skipped",
                f"The following versions have no test results and will be skipped:\n\n"
                f"{', '.join(empty_versions)}")

        # Use filtered results for the rest of the upload
        results = valid_results

        # Compute current hashes for all versions
        # Note: Storage keys may be 'vid' or 'vid|commit' - extract original vid for attached_logs lookup
        version_hashes = {}
        for storage_key, vid_results in results.items():
            original_vid, _ = parse_version_storage_key(storage_key)
            vid_logs = attached_logs.get(original_vid, [])
            version_hashes[storage_key] = compute_version_hash(vid_results, vid_logs)

        # Find versions that have changed locally (for informational purposes)
        locally_changed = []
        locally_unchanged = []
        for vid, current_hash in version_hashes.items():
            last_hash = upload_hashes.get(vid)
            if current_hash != last_hash:
                locally_changed.append(vid)
            else:
                locally_unchanged.append(vid)

        # ALWAYS check with the server for ALL versions
        # This handles the case where a report was deleted on the server
        # (local hash matches, but server no longer has the report)
        hashes_to_check = version_hashes.copy()

        # Build test_type from WAN/LAN flags
        test_type = ''
        if meta.get('WAN') and meta.get('LAN'):
            test_type = 'WAN/LAN'
        elif meta.get('WAN'):
            test_type = 'WAN'
        elif meta.get('LAN'):
            test_type = 'LAN'

        tester = meta.get('tester', '')
        commit_hash = meta.get('commit', '')

        # Check with server
        hash_check_result = self.controller.panel.check_hashes(
            hashes_to_check, tester, test_type, commit_hash
        )

        if not hash_check_result.success:
            # If server check fails, fall back to uploading all locally changed versions
            if not locally_changed:
                QMessageBox.information(self, "No Changes",
                    "No local changes detected and could not verify with server.\n\n"
                    f"Error: {hash_check_result.error}")
                return
            QMessageBox.warning(self, "Server Check Failed",
                f"Could not check hashes with server: {hash_check_result.error}\n\n"
                f"Proceeding to upload all {len(locally_changed)} changed version(s).")
            changed_versions = locally_changed
            skipped_versions = []
        else:
            # Filter based on server response - check ALL versions, not just locally changed
            # This handles the case where a report was deleted on the server
            changed_versions = []
            skipped_versions = []

            for vid in version_hashes.keys():
                if vid in hash_check_result.results:
                    result = hash_check_result.results[vid]
                    if result.action == 'skip':
                        # Server has the same hash, truly skip this version
                        skipped_versions.append(vid)
                        # Update local hash to match
                        upload_hashes[vid] = version_hashes[vid]
                    elif result.action == 'create':
                        # Report doesn't exist on server (new or was deleted)
                        changed_versions.append(vid)
                    else:
                        # 'update' - report exists but hash differs
                        changed_versions.append(vid)
                else:
                    # Version not in response, upload it
                    changed_versions.append(vid)

            # Update session with skipped hashes
            if skipped_versions:
                self.controller.session['upload_hashes'] = upload_hashes
                self.controller.save_session()

        # Show message if all versions were skipped
        if not changed_versions:
            QMessageBox.information(self, "Already Up to Date",
                f"All {len(skipped_versions)} report(s) already exist on the server with identical content.\n\n"
                f"No upload needed.")
            return

        # Show info about skipped versions if any
        skip_info = ""
        if skipped_versions:
            skip_info = f"\n\n{len(skipped_versions)} version(s) skipped (already up to date on server)."

        # Build a filtered session with only versions that need uploading
        # Include per-version commit tracking (if different commits were used for different versions)
        # Note: storage_key may be 'vid' or 'vid|commit' - extract original vid for lookups
        version_commits = self.controller.session.get('version_commits', {})
        timing = self.controller.session.get('timing', {})
        completed = self.controller.session.get('completed', {})

        filtered_results = {}
        filtered_timing = {}
        filtered_completed = {}
        filtered_attached_logs = {}
        filtered_version_commits = {}

        for storage_key in changed_versions:
            original_vid, commit_from_key = parse_version_storage_key(storage_key)
            filtered_results[storage_key] = results[storage_key]
            filtered_timing[storage_key] = timing.get(storage_key, timing.get(original_vid, 0))
            filtered_completed[storage_key] = completed.get(storage_key, completed.get(original_vid, False))
            # attached_logs uses plain vid
            if original_vid in attached_logs:
                filtered_attached_logs[storage_key] = attached_logs[original_vid]
            # version_commits - use commit from key if present, else lookup, else meta
            if commit_from_key:
                filtered_version_commits[storage_key] = commit_from_key
            else:
                filtered_version_commits[storage_key] = version_commits.get(original_vid, meta.get('commit', ''))

        filtered_session = {
            'meta': meta,
            'results': filtered_results,
            'timing': filtered_timing,
            'completed': filtered_completed,
            'attached_logs': filtered_attached_logs,
            'version_commits': filtered_version_commits,
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

        # Show progress dialog
        self.controller._submission_progress = SubmissionProgressDialog(
            self, len(changed_versions), changed_versions
        )
        self.controller._submission_progress.show()

        # Submit to panel (async)
        self.controller.panel.submit_session(filtered_path)

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


class SubmissionProgressDialog(QDialog):
    """Dialog showing submission progress with an indeterminate progress bar."""

    def __init__(self, parent=None, version_count=0, versions=None):
        super().__init__(parent)
        self.setWindowTitle("Uploading Report")
        self.setMinimumWidth(400)
        self.setModal(True)
        # Remove close button to prevent closing during upload
        self.setWindowFlags(self.windowFlags() & ~Qt.WindowCloseButtonHint)
        self.setup_ui(version_count, versions or [])

    def setup_ui(self, version_count, versions):
        layout = QVBoxLayout()
        layout.setSpacing(15)
        layout.setContentsMargins(20, 20, 20, 20)

        # Status label
        self.status_label = QLabel(f"Uploading {version_count} report(s) to panel...")
        self.status_label.setStyleSheet("font-size: 14px; font-weight: bold;")
        layout.addWidget(self.status_label)

        # Version list (show first few)
        if versions:
            version_text = ", ".join(versions[:5])
            if len(versions) > 5:
                version_text += f"\n...and {len(versions) - 5} more"
            version_label = QLabel(version_text)
            version_label.setStyleSheet("color: #666; font-size: 12px;")
            version_label.setWordWrap(True)
            layout.addWidget(version_label)

        # Progress bar (indeterminate)
        self.progress_bar = QProgressBar()
        self.progress_bar.setMinimum(0)
        self.progress_bar.setMaximum(0)  # Indeterminate mode
        self.progress_bar.setTextVisible(False)
        self.progress_bar.setStyleSheet("""
            QProgressBar {
                border: 1px solid #ccc;
                border-radius: 5px;
                background-color: #f0f0f0;
                height: 20px;
            }
            QProgressBar::chunk {
                background-color: #3498db;
                border-radius: 4px;
            }
        """)
        layout.addWidget(self.progress_bar)

        # Info label
        self.info_label = QLabel("Please wait...")
        self.info_label.setStyleSheet("color: #888; font-size: 11px;")
        layout.addWidget(self.info_label)

        self.setLayout(layout)

    def set_complete(self, success, message):
        """Update dialog to show completion status."""
        self.progress_bar.setMaximum(100)
        self.progress_bar.setValue(100)

        if success:
            self.status_label.setText("Upload Complete!")
            self.status_label.setStyleSheet("font-size: 14px; font-weight: bold; color: #27ae60;")
            self.info_label.setText(message)
            self.progress_bar.setStyleSheet("""
                QProgressBar {
                    border: 1px solid #27ae60;
                    border-radius: 5px;
                    background-color: #f0f0f0;
                    height: 20px;
                }
                QProgressBar::chunk {
                    background-color: #27ae60;
                    border-radius: 4px;
                }
            """)
        else:
            self.status_label.setText("Upload Failed")
            self.status_label.setStyleSheet("font-size: 14px; font-weight: bold; color: #e74c3c;")
            self.info_label.setText(message)
            self.progress_bar.setStyleSheet("""
                QProgressBar {
                    border: 1px solid #e74c3c;
                    border-radius: 5px;
                    background-color: #f0f0f0;
                    height: 20px;
                }
                QProgressBar::chunk {
                    background-color: #e74c3c;
                    border-radius: 4px;
                }
            """)

        # Re-enable close button and allow closing
        self.setWindowFlags(self.windowFlags() | Qt.WindowCloseButtonHint)
        self.show()  # Refresh window flags

        # Auto-close after delay for success
        if success:
            QtCore.QTimer.singleShot(2000, self.accept)


class FlagNotificationDialog(QDialog):
    """Dialog for displaying flag notifications (retest requests or fixed tests)."""

    def __init__(self, parent=None, flag_type='retest', flags=None):
        super().__init__(parent)
        self.flag_type = flag_type
        self.flags = flags or []
        title = "Retest Requested" if flag_type == 'retest' else "Tests Marked as Fixed"
        self.setWindowTitle(f"{title} ({len(self.flags)})")
        self.setMinimumSize(550, 400)
        self.setup_ui()

    def setup_ui(self):
        layout = QVBoxLayout(self)

        text = QTextEdit()
        text.setReadOnly(True)

        # Build HTML content
        if self.flag_type == 'retest':
            header = "<h3>🔄 The following tests need retesting:</h3>"
            item_color = "#fff3e0"  # Light orange
            border_color = "#ff9800"
        else:
            header = "<h3>✅ The following tests have been marked as fixed:</h3>"
            item_color = "#e8f5e9"  # Light green
            border_color = "#4caf50"

        html = f"""
        <style>
            .flag-item {{ margin-bottom: 15px; padding: 10px; background: {item_color};
                          border-left: 3px solid {border_color}; border-radius: 5px; }}
            .flag-header {{ font-weight: bold; font-size: 14px; margin-bottom: 5px; }}
            .flag-meta {{ font-size: 12px; color: #666; }}
            .notes {{ background: #fff; padding: 8px; margin-top: 8px; font-size: 12px; border-radius: 3px; }}
        </style>
        {header}
        """

        for flag in self.flags:
            test_key = flag.get('test_key', 'Unknown')
            test_name = flag.get('test_name', '')
            version = flag.get('client_version', 'Unknown')
            reason = flag.get('reason', '')
            notes = flag.get('notes', '')
            report_id = flag.get('report_id')

            icon = "🔄" if self.flag_type == 'retest' else "✅"
            html += f'<div class="flag-item">'
            html += f'<div class="flag-header">{icon} Test {test_key}'
            if test_name:
                html += f': {test_name}'
            html += '</div>'
            html += f'<div class="flag-meta">'
            html += f'Version: {version}'
            if reason:
                html += f'<br>Reason: {reason}'
            if report_id:
                html += f'<br>Report ID: #{report_id}'
            html += '</div>'

            if notes:
                html += '<div class="notes">'
                notes_escaped = notes.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
                notes_html = notes_escaped.replace('\n', '<br>')
                html += f'📝 <b>Notes:</b> {notes_html}'
                html += '</div>'

            html += '</div>'

        text.setHtml(html)
        layout.addWidget(text)

        # Add acknowledge button
        button_layout = QHBoxLayout()
        ack_button = QPushButton("Acknowledge")
        ack_button.clicked.connect(self.accept)
        ack_button.setStyleSheet("""
            QPushButton {
                background-color: #3498db;
                color: white;
                padding: 8px 20px;
                border: none;
                border-radius: 4px;
                font-weight: bold;
            }
            QPushButton:hover {
                background-color: #2980b9;
            }
        """)
        button_layout.addStretch()
        button_layout.addWidget(ack_button)
        layout.addLayout(button_layout)


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

        self.cancel_btn = QPushButton("Cancel")
        self.cancel_btn.setStyleSheet("background-color:#95a5a6;color:white;")
        self.cancel_btn.setToolTip("Discard changes and return to main menu")
        self.cancel_btn.clicked.connect(self.cancel_test)

        self.finish_btn = QPushButton("Finish Test")
        self.finish_btn.clicked.connect(self.finish)
        hl.addWidget(self.stopwatch_label)
        hl.addWidget(self.stopwatch_start_btn)
        hl.addWidget(self.stopwatch_stop_btn)
        hl.addWidget(self.stopwatch_reset_btn)
        hl.addStretch()
        hl.addWidget(self.view_attachments_btn)
        hl.addWidget(self.attach_log_btn)
        hl.addWidget(self.cancel_btn)
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
        # Templates replace skip_tests - the returned tests list is already filtered
        version_tests, _ = self.controller.get_tests_for_version(version['id'])
        if version_tests is not None:
            # Use version-specific tests from API (already template-filtered)
            tests_to_show = version_tests
        else:
            # Fall back to global TESTS if API not available
            tests_to_show = TESTS

        for tnum, tname, tdesc in tests_to_show:
            # Tests are already filtered by template - no skip check needed
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
        # populate if existing - use commit-specific lookup
        try:
            current_commit = self.controller.intro.get_metadata().get('commit', '')
        except Exception:
            current_commit = ''
        saved = get_results_for_version(self.controller.session, version['id'], current_commit)
        for tnum, group, notes in self.entries:
            r = saved.get(tnum, {})
            if r:
                status = r.get('status', '')
                # Notes are stored in cleaned format with <pre><code> blocks
                # Convert to styled HTML for Qt display, handle old thumbnail format too
                notes_html = r.get('notes', '')
                notes_html = convert_old_thumbnail_format(notes_html)
                notes_html = prepare_notes_for_editor(notes_html)
                notes.setHtml(notes_html)
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
        has_content = False
        for tnum, group, notes in self.entries:
            checked = group.checkedButton()
            status = checked.text() if checked else ''
            # Clean notes immediately when saving - this converts code block markers
            # to proper <pre><code> format and preserves embedded images
            cleaned_notes = clean_notes(notes.toHtml())
            results[tnum] = {'status': status, 'notes': cleaned_notes}
            # Check if this test has any content
            if status or cleaned_notes.strip():
                has_content = True

        # If no tests have status or notes, discard the report silently
        if not has_content:
            QMessageBox.information(
                self,
                "Empty Report",
                "No test results or notes were entered.\nThe report has been discarded."
            )
            self.controller.show_versions()
            return

        vid = self.controller.current_version['id']

        # Get current commit for commit-specific storage
        try:
            current_commit = self.controller.intro.get_metadata().get('commit', '')
        except Exception:
            current_commit = ''

        # Generate commit-specific storage key
        storage_key = get_version_storage_key(vid, current_commit)

        if 'results' not in self.controller.session:
            self.controller.session['results'] = {}
        self.controller.session['results'][storage_key] = results

        # mark completed with commit-specific key
        if 'completed' not in self.controller.session:
            self.controller.session['completed'] = {}
        self.controller.session['completed'][storage_key] = True
        self.controller.last_completed_version = vid  # Keep as vid for list highlighting

        # Track which commit was used for this version (for reference)
        if 'version_commits' not in self.controller.session:
            self.controller.session['version_commits'] = {}
        if current_commit:
            self.controller.session['version_commits'][vid] = current_commit

        self.controller.save_session()
        self.controller.show_versions()

    def cancel_test(self):
        """Discard changes and return to main menu (version list)."""
        # Ask for confirmation if any changes were made
        reply = QMessageBox.question(
            self,
            "Cancel Test",
            "Are you sure you want to discard any changes and return to the main menu?",
            QMessageBox.Yes | QMessageBox.No,
            QMessageBox.No
        )
        if reply == QMessageBox.Yes:
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

        # Cache for version-specific tests (loaded during navigation, cleared on submission)
        self._version_tests_cache = {}

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
            # Load tests from API asynchronously (non-blocking)
            # The callback handles offline mode check and starts monitoring
            self._load_tests_from_api_async(is_startup=True)

            # Load client versions from API asynchronously (non-blocking)
            self._load_versions_from_api_async()

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
                self.panel.flag_notification.connect(self._on_flag_notification)
                # Start background flag polling thread
                self._start_flag_polling()
            except Exception as e:
                print(f"Panel integration init error: {e}")
                self.panel = None
        elif PANEL_AVAILABLE:
            # Create panel without config - user can configure later
            try:
                self.panel = PanelIntegration()
                self.panel.retest_notification.connect(self._on_retests_found)
                self.panel.submission_complete.connect(self._on_submission_complete)
                self.panel.flag_notification.connect(self._on_flag_notification)
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
            # Also reload tests when config changes (async to avoid UI freeze)
            self._load_tests_from_api_async()
            self._load_versions_from_api_async()
        except Exception as e:
            print(f"Error updating panel config: {e}")

    def _load_tests_from_api(self):
        """Load test types from the API and update the global TESTS list (synchronous).

        If the API is configured but not reachable, uses cached data if available,
        otherwise falls back to FALLBACK_TESTS. Sets offline_mode accordingly.

        Note: This is the synchronous version. For non-blocking startup, use
        _load_tests_from_api_async() instead.
        """
        global TESTS
        if not self.panel or not self.panel.is_configured:
            print("Panel not configured, using fallback tests")
            TESTS = list(FALLBACK_TESTS)
            self.offline_mode = False  # Not offline, just not configured
            return False

        # Try to load tests (API or cached)
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

                # Check if this is cached data (offline mode)
                if result.error and "cached" in result.error.lower():
                    print(f"Loaded {len(TESTS)} tests from cache (offline)")
                    self.offline_mode = True
                    return True  # Still successful, just using cached data
                else:
                    print(f"Loaded {len(TESTS)} tests from API")
                    self.offline_mode = False
                    return True
            else:
                # No tests available from API or cache
                error = result.error if result else "Unknown error"
                print(f"Failed to load tests: {error}, using fallback tests")
                TESTS = list(FALLBACK_TESTS)
                self.offline_mode = True
                return False
        except Exception as e:
            print(f"Error loading tests: {e}, using fallback tests")
            TESTS = list(FALLBACK_TESTS)
            self.offline_mode = True
            return False

    def _load_tests_from_api_async(self, is_startup: bool = False):
        """Load test types from the API asynchronously (non-blocking).

        Args:
            is_startup: If True, trigger offline mode check and monitoring after load

        Results are handled via _on_tests_loaded callback.
        """
        global TESTS
        if not self.panel or not self.panel.is_configured:
            print("Panel not configured, using fallback tests")
            TESTS = list(FALLBACK_TESTS)
            self.offline_mode = False
            return

        # Create callback wrapper to pass is_startup parameter
        def callback_wrapper(success, result):
            self._on_tests_loaded(success, result, is_startup=is_startup)

        # Queue async load with callback
        self.panel.get_tests_async(enabled_only=True, callback=callback_wrapper)

    def _on_tests_loaded(self, success: bool, result, is_startup: bool = False):
        """Callback handler for async tests loading.

        Args:
            success: Whether the API call succeeded
            result: The TestsResult object
            is_startup: If True, handle offline mode check and start monitoring
        """
        global TESTS
        try:
            if success and result and hasattr(result, 'success') and result.success and result.tests:
                # Convert API tests to the same format as FALLBACK_TESTS
                new_tests = []
                for test in result.tests:
                    new_tests.append((
                        test.test_key,
                        test.name,
                        test.description or ''
                    ))
                TESTS = new_tests

                # Check if this is cached data (offline mode)
                if result.error and "cached" in result.error.lower():
                    print(f"Loaded {len(TESTS)} tests from cache (offline)")
                    self.offline_mode = True
                else:
                    print(f"Loaded {len(TESTS)} tests from API")
                    self.offline_mode = False
            else:
                # No tests available from API or cache
                error = result.error if hasattr(result, 'error') and result.error else str(result) if result else "Unknown error"
                print(f"Failed to load tests: {error}, using fallback tests")
                TESTS = list(FALLBACK_TESTS)
                self.offline_mode = True
        except Exception as e:
            print(f"Error processing tests result: {e}, using fallback tests")
            TESTS = list(FALLBACK_TESTS)
            self.offline_mode = True

        # Handle startup-specific actions
        if is_startup:
            self._on_startup_tests_loaded()

    def _on_startup_tests_loaded(self):
        """Called after async tests load at startup to handle offline mode and monitoring."""
        if self.offline_mode:
            # Show offline mode warning dialog
            self._show_offline_mode_dialog()
        else:
            # Start monitoring and fetch tester name if online
            if self.panel and self.panel.is_configured:
                self.panel.start_monitoring()
                self._load_tester_name_from_api_async()

    def get_tests_list(self):
        """Get the current tests list (from API or fallback)."""
        return TESTS

    def get_tests_for_version(self, version_id: str, force_refresh: bool = False):
        """Get tests for a specific version using version-specific template if assigned.

        Uses in-memory cache to avoid repeated API calls during navigation.
        Cache is cleared on report submission to get fresh data.

        Args:
            version_id: The client version string (e.g., 'secondblob.bin.2004-01-15')
            force_refresh: If True, bypass cache and fetch fresh data from API

        Returns:
            Tuple of (tests_list, None) or (None, None) if not available
            - tests_list: List of tests in (test_key, test_name, description) format
            - Second element is always None (skip_tests deprecated, use templates)
        """
        if not self.panel or not self.panel.is_configured:
            return None, None

        # Check in-memory cache first (unless force refresh requested)
        if not force_refresh and version_id in self._version_tests_cache:
            return self._version_tests_cache[version_id], None

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

                # Check if this is cached data
                is_cached = result.error and "cached" in result.error.lower()

                if result.template:
                    source = "cache" if is_cached else "API"
                    print(f"Using template '{result.template.get('name', 'Unknown')}' for version {version_id} ({len(tests)} tests, from {source})")
                elif is_cached:
                    print(f"Using cached tests for version {version_id} ({len(tests)} tests)")

                # Cache the result for subsequent accesses
                self._version_tests_cache[version_id] = tests

                # skip_tests is deprecated - templates now control test visibility
                # Return None for skip_tests for backward compatibility
                return tests, None
            return None, None
        except Exception as e:
            print(f"Error getting tests for version {version_id}: {e}")
            return None, None

    def clear_version_tests_cache(self):
        """Clear the in-memory version tests cache. Called after report submission."""
        self._version_tests_cache = {}

    def _load_versions_from_api(self):
        """Load client versions from the API and update the global API_VERSIONS list.

        If the API is configured but not reachable, uses cached versions if available,
        otherwise falls back to VERSIONS from file.
        """
        global API_VERSIONS
        if not self.panel or not self.panel.is_configured:
            print("Panel not configured, using fallback versions from file")
            API_VERSIONS = None  # Will use VERSIONS from versions.py
            return False

        try:
            # Get versions with notifications included (uses cache if offline)
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
                        'skip_tests': [],  # Deprecated: templates control test visibility
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

                # Check if this is cached data (offline mode)
                if result.error and "cached" in result.error.lower():
                    print(f"Loaded {len(API_VERSIONS)} client versions from cache (offline)")
                else:
                    print(f"Loaded {len(API_VERSIONS)} client versions from API")

                # Count total notifications
                total_notifs = sum(len(v.get('notifications', [])) for v in API_VERSIONS)
                if total_notifs > 0:
                    print(f"  (includes {total_notifs} version notifications)")

                return True
            else:
                error = result.error if result else "Unknown error"
                print(f"Failed to load versions: {error}, using fallback versions from file")
                API_VERSIONS = None
                return False
        except Exception as e:
            print(f"Error loading versions: {e}, using fallback versions from file")
            API_VERSIONS = None
            return False

    def _load_versions_from_api_async(self):
        """Load client versions from the API asynchronously (non-blocking).

        Results are handled via _on_versions_loaded callback.
        """
        global API_VERSIONS
        if not self.panel or not self.panel.is_configured:
            print("Panel not configured, using fallback versions from file")
            API_VERSIONS = None
            return

        # Queue async load with callback
        self.panel.get_versions_async(
            enabled_only=True,
            include_notifications=True,
            callback=self._on_versions_loaded
        )

    def _on_versions_loaded(self, success: bool, result):
        """Callback handler for async versions loading."""
        global API_VERSIONS
        try:
            if success and result and hasattr(result, 'success') and result.success and result.versions:
                # Convert API ClientVersion objects to the same format as VERSIONS
                new_versions = []
                for v in result.versions:
                    version_dict = {
                        'id': v.id,
                        'packages': v.packages or [],
                        'steam_date': v.steam_date,
                        'steam_time': v.steam_time,
                        'skip_tests': [],  # Deprecated: templates control test visibility
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

                # Check if this is cached data (offline mode)
                if result.error and "cached" in result.error.lower():
                    print(f"Loaded {len(API_VERSIONS)} client versions from cache (offline)")
                else:
                    print(f"Loaded {len(API_VERSIONS)} client versions from API")

                # Count total notifications
                total_notifs = sum(len(v.get('notifications', [])) for v in API_VERSIONS)
                if total_notifs > 0:
                    print(f"  (includes {total_notifs} version notifications)")
            else:
                error = result.error if hasattr(result, 'error') and result.error else str(result) if result else "Unknown error"
                print(f"Failed to load versions: {error}, using fallback versions from file")
                API_VERSIONS = None
        except Exception as e:
            print(f"Error processing versions result: {e}, using fallback versions from file")
            API_VERSIONS = None

    def _show_offline_mode_dialog(self):
        """Show a dialog informing the user that the tool is in offline mode."""
        msg = QMessageBox(self.window)
        msg.setIcon(QMessageBox.Warning)
        msg.setWindowTitle("Offline Mode")
        msg.setText("Could not connect to the Test Panel API.")

        # Check if cached data is available
        has_cache = self.panel and self.panel.has_cached_data() if hasattr(self.panel, 'has_cached_data') else False
        pending_count = self.panel.get_pending_submissions_count() if self.panel and hasattr(self.panel, 'get_pending_submissions_count') else 0

        if has_cache:
            info_text = (
                "The tool is running in offline mode with cached data.\n\n"
                "You can continue testing using previously cached test definitions "
                "and client versions.\n\n"
                "When you submit results:\n"
                "• Reports will be queued locally for later submission\n"
                "• Once the connection is restored, queued reports will be sent automatically\n"
            )
            if pending_count > 0:
                info_text += f"\n📤 {pending_count} report(s) currently pending submission."
        else:
            info_text = (
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

        msg.setInformativeText(info_text)
        msg.setStandardButtons(QMessageBox.Ok)
        msg.exec_()

    def _load_tester_name_from_api(self):
        """Fetch the tester name and revisions from the API using the configured API key (synchronous)."""
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

    def _load_tester_name_from_api_async(self):
        """Fetch the tester name and revisions from the API asynchronously (non-blocking).

        Results are handled via _on_user_info_loaded callback.
        """
        if not self.panel or not self.panel.is_configured:
            return

        # Queue async load with callback
        self.panel.get_user_info_async(callback=self._on_user_info_loaded)

    def _on_user_info_loaded(self, success: bool, result):
        """Callback handler for async user info loading."""
        try:
            if success and result and hasattr(result, 'success') and result.success:
                # Set tester name only if field is empty
                if not self.intro.name_input.text().strip() and result.username:
                    self.intro.name_input.setText(result.username)
                    print(f"Loaded tester name from API: {result.username}")

                # Populate revisions dropdown
                if hasattr(result, 'revisions') and result.revisions:
                    self.intro.populate_revisions(result.revisions)
                    print(f"Loaded {len(result.revisions)} revisions from API")
        except Exception as e:
            print(f"Error processing user info result: {e}")

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

                for storage_key in self._pending_upload_versions:
                    vid_results = results.get(storage_key, {})
                    # Extract original vid for attached_logs lookup (may be 'vid' or 'vid|commit')
                    original_vid, _ = parse_version_storage_key(storage_key)
                    vid_logs = attached_logs.get(original_vid, [])
                    self.session['upload_hashes'][storage_key] = compute_version_hash(vid_results, vid_logs)

                self._pending_upload_versions = []
                self.save_session()

            # Clean up temp upload file
            try:
                filtered_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'session_results_upload.json')
                if os.path.exists(filtered_path):
                    os.remove(filtered_path)
            except Exception:
                pass

            # Clear version tests cache and refresh data from API asynchronously
            self.clear_version_tests_cache()
            self._load_tests_from_api_async()
            self._load_versions_from_api_async()

            # Update progress dialog to show success
            if hasattr(self, '_submission_progress') and self._submission_progress:
                self._submission_progress.set_complete(True, message)
            else:
                QMessageBox.information(self.window, "Upload Complete",
                    f"Report uploaded successfully!\n\n{message}")
        else:
            # Clear pending versions on failure so user can retry
            self._pending_upload_versions = []

            # Update progress dialog to show failure
            if hasattr(self, '_submission_progress') and self._submission_progress:
                self._submission_progress.set_complete(False, message)
            else:
                QMessageBox.warning(self.window, "Upload Failed",
                    f"Failed to upload report:\n\n{message}")

    def _on_flag_notification(self, count, flags):
        """Handle flag notification signal from panel polling thread."""
        if count > 0 and flags:
            # Show notification dialog for each flag type
            retest_flags = [f for f in flags if f.get('type') == 'retest']
            fixed_flags = [f for f in flags if f.get('type') == 'fixed']

            if retest_flags:
                # Show retest notification dialog
                dialog = FlagNotificationDialog(self.window, 'retest', retest_flags)
                if dialog.exec_() == QDialog.Accepted:
                    # User acknowledged - send acknowledgement to server asynchronously
                    for flag in retest_flags:
                        try:
                            self.panel.acknowledge_flag_async('retest', flag.get('id'))
                        except Exception as e:
                            print(f"Failed to acknowledge retest flag: {e}")

            if fixed_flags:
                # Show fixed notification dialog
                dialog = FlagNotificationDialog(self.window, 'fixed', fixed_flags)
                if dialog.exec_() == QDialog.Accepted:
                    # User acknowledged - send acknowledgement to server asynchronously
                    for flag in fixed_flags:
                        try:
                            self.panel.acknowledge_flag_async('fixed', flag.get('id'))
                        except Exception as e:
                            print(f"Failed to acknowledge fixed flag: {e}")

    def _start_flag_polling(self):
        """Start background thread for polling flag notifications."""
        self._flag_polling_stop = threading.Event()
        self._flag_polling_thread = threading.Thread(
            target=self._flag_polling_loop,
            daemon=True,
            name="FlagPolling"
        )
        self._flag_polling_thread.start()

    def _stop_flag_polling(self):
        """Stop the background flag polling thread."""
        if hasattr(self, '_flag_polling_stop'):
            self._flag_polling_stop.set()
        if hasattr(self, '_flag_polling_thread') and self._flag_polling_thread.is_alive():
            self._flag_polling_thread.join(timeout=2.0)

    def _flag_polling_loop(self):
        """Background thread that polls for flag notifications periodically."""
        import time
        poll_interval = 30  # Check every 30 seconds

        while not self._flag_polling_stop.is_set():
            try:
                if self.panel and self.panel.is_configured:
                    result = self.panel.check_flags_lightweight()
                    if result and result.get('success'):
                        count = result.get('count', 0)
                        flags = result.get('flags', [])
                        if count > 0:
                            # Emit signal to main thread (Qt signals are thread-safe)
                            self.panel.flag_notification.emit(count, flags)
            except Exception as e:
                print(f"Flag polling error: {e}")

            # Wait for next poll interval or until stop is requested
            self._flag_polling_stop.wait(poll_interval)

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
        # Note: keys may be 'vid' or 'vid|commit' - extract original vid for matching
        completed_ids = set()
        for k, v in self.session.get('completed', {}).items():
            if v:
                original_vid, _ = parse_version_storage_key(k)
                completed_ids.add(original_vid)

        saved_ids = set()
        # Build a lookup that maps plain vid to results (handles both 'vid' and 'vid|commit' keys)
        # If multiple commits have results for same vid, we use the first one found (newest is likely last)
        vid_to_results = {}
        for k, v in results.items():
            original_vid, _ = parse_version_storage_key(k)
            saved_ids.add(original_vid)
            # Store results for this vid (later keys will overwrite earlier ones)
            vid_to_results[original_vid] = v

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
        # columns: union of tests across all tested versions (respects templates)
        # rows: tested versions (same order)

        # Collect tests for each version and build union for matrix columns
        version_tests_cache = {}  # vid -> list of (test_key, name, desc)
        all_test_keys_ordered = []  # maintains order of first appearance
        all_test_names = {}

        for v in get_active_versions():
            vid = v['id']
            if vid not in tested_ids:
                continue

            # Try to get version-specific tests from API/cache (template-filtered)
            version_tests, _ = self.get_tests_for_version(vid)
            if version_tests:
                version_tests_cache[vid] = version_tests
                for tk, tn, td in version_tests:
                    if tk not in all_test_names:
                        all_test_keys_ordered.append(tk)
                        all_test_names[tk] = tn
            else:
                # Fallback to global TESTS
                version_tests_cache[vid] = TESTS
                for tk, tn, td in TESTS:
                    if tk not in all_test_names:
                        all_test_keys_ordered.append(tk)
                        all_test_names[tk] = tn

        # Use ordered union for matrix columns, fallback to TESTS if empty
        test_cols = all_test_keys_ordered if all_test_keys_ordered else [t[0] for t in TESTS]
        test_names = all_test_names if all_test_names else {t[0]: t[1] for t in TESTS}

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
            packages = v.get('packages', [])
            row_label = ', '.join(packages) if packages else vid
            ver_anchor = anchor_id(vid)
            row = [f'<td class="row-label">{html_lib.escape(row_label)}</td>']
            saved = vid_to_results.get(vid, {})
            # Get tests applicable to this version (from template)
            version_test_keys = set(t[0] for t in version_tests_cache.get(vid, TESTS))
            for tc in test_cols:
                # Treat tests not in version's template as skipped
                if tc not in version_test_keys:
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
            saved = vid_to_results.get(vid, {})
            # Use version-specific tests from cache (respects templates)
            version_test_list = version_tests_cache.get(vid, TESTS)
            for tnum, tname, texp in version_test_list:
                # Tests are already filtered by template - no skip check needed
                r = saved.get(tnum, {})
                status = r.get('status', '')
                raw_notes = r.get('notes', '') or ''
                # convert old thumbnail format (separate thumb image) to new format (resized full image)
                raw_notes = convert_old_thumbnail_format(raw_notes)
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
