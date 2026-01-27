#!/usr/bin/env python3
"""
Steam Emulator Test Panel - API Client

A comprehensive Python client for communicating with the test panel API.
Features:
- Config file loading (JSON format)
- Report submission
- Retest queue checking (startup + periodic)
- Callback support for retest notifications

Usage:
    # As a module
    from api_client import TestPanelClient
    client = TestPanelClient.from_config('config.json')
    client.submit_report('session_results.json')
    retests = client.get_retest_queue()

    # As CLI
    python api_client.py submit session_results.json
    python api_client.py check-retests
    python api_client.py --help
"""

import os
import sys
import json
import time
import gzip
import base64
import threading
import logging
import re
import html as html_lib
from pathlib import Path
from typing import Optional, Callable, List, Dict, Any, Union
from dataclasses import dataclass, field
from datetime import datetime

try:
    import requests
except ImportError:
    print("Error: 'requests' library is required. Install with: pip install requests")
    sys.exit(1)

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger('TestPanelClient')


def convert_html_code_blocks_to_markdown(text: str) -> str:
    """
    Convert HTML <pre><code> blocks back to markdown ``` format.

    This reverses the conversion done by convert_markdown_code_blocks_to_html.
    Handles both <pre class="code-block"> and plain <pre><code> formats.

    Args:
        text: Text containing HTML code blocks

    Returns:
        Text with HTML code blocks converted to markdown
    """
    def replace_code_block(match):
        # Get the language from data-language attribute if present
        pre_tag = match.group(1)
        code_content = match.group(2)

        # Extract language from data-language attribute
        lang_match = re.search(r'data-language=["\'](\w+)["\']', pre_tag)
        lang = lang_match.group(1) if lang_match else ''

        # Unescape HTML entities in code
        code_content = html_lib.unescape(code_content)

        # Build markdown code block
        return f'```{lang}\n{code_content}\n```'

    # Match <pre ...><code>...</code></pre> patterns
    # Group 1: the opening pre tag (to extract attributes)
    # Group 2: the code content
    pattern = r'<pre([^>]*)>\s*<code[^>]*>([\s\S]*?)</code>\s*</pre>'
    return re.sub(pattern, replace_code_block, text, flags=re.IGNORECASE)


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


def clean_notes_for_api(notes: str) -> str:
    """
    Clean notes for API submission by stripping HTML but preserving images.

    This function:
    - Strips all HTML tags except embedded images
    - Keeps markdown code blocks (```) as-is (NOT converted to HTML)
    - Converts HTML code blocks to markdown format
    - Detects Qt-styled code blocks and converts to markdown
    - Decodes HTML entities

    Args:
        notes: Raw notes string, possibly containing Qt HTML or markdown

    Returns:
        Cleaned notes string with images preserved and code blocks in markdown format
    """
    if not notes:
        return ''

    # Check if this already looks like markdown (not HTML)
    has_markdown_code_blocks = re.search(r'```[\s\S]*?```', notes)
    has_markdown_images = re.search(r'!\[[^\]]*\]\([^)]+\)', notes)
    has_image_markers = re.search(r'\[image:data:image/', notes) or re.search(r'\{\{IMAGE:data:image/', notes)

    if has_markdown_code_blocks or has_markdown_images or has_image_markers:
        # Clean up Qt CSS first
        text = notes.replace('p, li { white-space: pre-wrap; }', '')

        # Keep markdown code blocks as-is - do NOT convert to HTML
        # The web panel's JavaScript renderer will handle markdown -> HTML conversion

        return text.strip()

    # Extract embedded images from Qt HTML before stripping tags
    # Qt sends images as: <a href="data:image/png;base64,..."><img src="..."/></a>
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
    # Convert these to markdown format for consistent storage.
    code_blocks = []
    CODE_MARKER_START = "⟦CODE⟧"
    CODE_MARKER_END = "⟦/CODE⟧"

    def extract_marked_code_block(match):
        """Extract code text from marker-delimited code block and convert to markdown."""
        code_html = match.group(1)
        # Convert HTML line breaks to newlines BEFORE stripping tags
        code_html = re.sub(r'<br\s*/?>', '\n', code_html, flags=re.IGNORECASE)
        # Convert paragraph/div endings to newlines
        code_html = re.sub(r'</p>\s*<p[^>]*>', '\n', code_html, flags=re.IGNORECASE)
        code_html = re.sub(r'</div>\s*<div[^>]*>', '\n', code_html, flags=re.IGNORECASE)
        # Add space between adjacent HTML elements to preserve word spacing
        code_html = re.sub(r'>\s*<', '> <', code_html)
        # Strip all HTML tags to get plain text
        code_text = re.sub(r'<[^>]+>', '', code_html)
        # Decode HTML entities (also converts &nbsp; to space)
        code_text = html_lib.unescape(code_text)
        # Don't re-escape - store as plain text in markdown format

        # Save and return placeholder (using markdown format now)
        placeholder = f"__CODE_BLOCK_{len(code_blocks)}__"
        code_blocks.append(f'```\n{code_text}\n```')
        return placeholder

    # Match code blocks by our unique markers (these survive Qt's HTML transformation)
    notes = re.sub(
        re.escape(CODE_MARKER_START) + r'([\s\S]*?)' + re.escape(CODE_MARKER_END),
        extract_marked_code_block,
        notes
    )

    # Also check for explicit <pre><code> blocks (in case they come from other sources)
    # Convert these to markdown format
    def clean_explicit_code_block(match):
        pre_tag = match.group(1)
        code_html = match.group(2)
        # Convert HTML line breaks to newlines BEFORE stripping tags
        code_html = re.sub(r'<br\s*/?>', '\n', code_html, flags=re.IGNORECASE)
        # Convert paragraph/div endings to newlines
        code_html = re.sub(r'</p>\s*<p[^>]*>', '\n', code_html, flags=re.IGNORECASE)
        code_html = re.sub(r'</div>\s*<div[^>]*>', '\n', code_html, flags=re.IGNORECASE)
        # Add space between adjacent HTML elements to preserve word spacing
        code_html = re.sub(r'>\s*<', '> <', code_html)
        code_text = re.sub(r'<[^>]+>', '', code_html)
        code_text = html_lib.unescape(code_text)

        # Extract language from data-language attribute if present
        lang_match = re.search(r'data-language=["\'](\w+)["\']', pre_tag)
        lang = lang_match.group(1) if lang_match else ''

        placeholder = f"__CODE_BLOCK_{len(code_blocks)}__"
        code_blocks.append(f'```{lang}\n{code_text}\n```')
        return placeholder

    notes = re.sub(
        r'<pre([^>]*)>\s*<code[^>]*>([\s\S]*?)</code>\s*</pre>',
        clean_explicit_code_block,
        notes,
        flags=re.IGNORECASE
    )

    # Strip other HTML tags (code blocks are already protected as placeholders)
    text = re.sub(r'<[^>]+>', '', notes)
    # Decode HTML entities
    text = html_lib.unescape(text)
    # Remove Qt rich text CSS
    text = text.replace('p, li { white-space: pre-wrap; }', '')
    # Clean up "image" link text that Qt leaves behind
    text = re.sub(r'\bimage\b\s*', '', text, flags=re.IGNORECASE)
    text = text.strip()

    # Restore code blocks (now in markdown format)
    for i, block in enumerate(code_blocks):
        placeholder = f"__CODE_BLOCK_{i}__"
        text = text.replace(placeholder, f"\n\n{block}\n\n")

    # Clean up multiple newlines
    text = re.sub(r'\n{3,}', '\n\n', text)
    text = text.strip()

    # Append extracted images in a format the renderer understands
    if extracted_images:
        for data_uri in extracted_images:
            text += "\n\n{{IMAGE:" + data_uri + "}}"
        text = text.strip()

    return text


def prepare_data_for_api(data: Dict[str, Any]) -> Dict[str, Any]:
    """
    Prepare session data for API submission by cleaning notes.

    This creates a copy of the data with HTML stripped from notes,
    preserving only images and converting code blocks to markdown.

    Args:
        data: The session data dict with results

    Returns:
        A copy of data with cleaned notes
    """
    import copy
    cleaned_data = copy.deepcopy(data)

    if 'results' in cleaned_data:
        for version_id, version_results in cleaned_data['results'].items():
            if isinstance(version_results, dict):
                for test_key, test_data in version_results.items():
                    if isinstance(test_data, dict) and 'notes' in test_data:
                        test_data['notes'] = clean_notes_for_api(test_data['notes'])

    return cleaned_data


@dataclass
class Config:
    """Configuration for the API client."""
    api_url: str
    api_key: str
    check_interval: int = 600  # 10 minutes in seconds
    auto_check_retests: bool = True
    timeout: int = 30

    @classmethod
    def from_dict(cls, data: dict) -> 'Config':
        return cls(
            api_url=data.get('api_url', '').rstrip('/'),
            api_key=data.get('api_key', ''),
            check_interval=data.get('check_interval', 600),
            auto_check_retests=data.get('auto_check_retests', True),
            timeout=data.get('timeout', 30)
        )

    def to_dict(self) -> dict:
        return {
            'api_url': self.api_url,
            'api_key': self.api_key,
            'check_interval': self.check_interval,
            'auto_check_retests': self.auto_check_retests,
            'timeout': self.timeout
        }

    def validate(self) -> List[str]:
        """Validate configuration and return list of errors."""
        errors = []
        if not self.api_url:
            errors.append("api_url is required")
        if not self.api_key:
            errors.append("api_key is required")
        if not self.api_key.startswith('sk_'):
            errors.append("api_key should start with 'sk_'")
        return errors


@dataclass
class TestType:
    """Represents a test type from the API."""
    test_key: str
    name: str
    description: str
    category_id: Optional[int]
    category_name: str
    sort_order: int
    is_enabled: bool

    @classmethod
    def from_dict(cls, data: dict) -> 'TestType':
        return cls(
            test_key=data.get('test_key', ''),
            name=data.get('name', ''),
            description=data.get('description', ''),
            category_id=data.get('category_id'),
            category_name=data.get('category_name', 'Uncategorized'),
            sort_order=data.get('sort_order', 0),
            is_enabled=data.get('is_enabled', True)
        )


@dataclass
class TestCategory:
    """Represents a test category from the API."""
    id: int
    name: str
    sort_order: int

    @classmethod
    def from_dict(cls, data: dict) -> 'TestCategory':
        return cls(
            id=data.get('id', 0),
            name=data.get('name', ''),
            sort_order=data.get('sort_order', 0)
        )


@dataclass
class TestsResult:
    """Result of fetching tests from the API."""
    success: bool
    categories: List[TestCategory] = field(default_factory=list)
    tests: List[TestType] = field(default_factory=list)
    grouped: Dict[str, List[TestType]] = field(default_factory=dict)
    error: Optional[str] = None
    template: Optional[Dict[str, any]] = None  # Template info if version-specific template was applied
    skip_tests: List[str] = field(default_factory=list)  # Tests to skip for this version (from admin_versions settings)


@dataclass
class VersionNotification:
    """Represents a notification/known issue for a client version."""
    id: int
    name: str
    message: str
    commit_hash: Optional[str] = None
    created_at: Optional[str] = None
    created_by: Optional[str] = None

    @classmethod
    def from_dict(cls, data: dict) -> 'VersionNotification':
        return cls(
            id=data.get('id', 0),
            name=data.get('name', ''),
            message=data.get('message', ''),
            commit_hash=data.get('commit_hash'),
            created_at=data.get('created_at'),
            created_by=data.get('created_by')
        )


@dataclass
class ClientVersion:
    """Represents a client version from the API."""
    id: str  # version_id string
    packages: List[str] = field(default_factory=list)
    steam_date: Optional[str] = None
    steam_time: Optional[str] = None
    skip_tests: List[str] = field(default_factory=list)
    display_name: Optional[str] = None
    sort_order: int = 0
    is_enabled: bool = True
    notifications: List[VersionNotification] = field(default_factory=list)
    notification_count: int = 0

    @classmethod
    def from_dict(cls, data: dict) -> 'ClientVersion':
        notifications = []
        if 'notifications' in data:
            notifications = [VersionNotification.from_dict(n) for n in data.get('notifications', [])]
        return cls(
            id=data.get('id', ''),
            packages=data.get('packages', []),
            steam_date=data.get('steam_date'),
            steam_time=data.get('steam_time'),
            skip_tests=data.get('skip_tests', []),
            display_name=data.get('display_name'),
            sort_order=data.get('sort_order', 0),
            is_enabled=data.get('is_enabled', True),
            notifications=notifications,
            notification_count=data.get('notification_count', len(notifications))
        )


@dataclass
class VersionsResult:
    """Result of fetching client versions from the API."""
    success: bool
    versions: List[ClientVersion] = field(default_factory=list)
    error: Optional[str] = None


@dataclass
class RetestItem:
    """Represents a retest queue item."""
    type: str  # 'retest' or 'fixed'
    id: int
    test_key: str
    test_name: str
    client_version: str
    reason: str
    latest_revision: bool
    commit_hash: Optional[str] = None  # Fix commit (for 'fixed' type)
    created_at: Optional[str] = None
    notes: Optional[str] = None  # Admin notes explaining what to retest
    report_id: Optional[int] = None  # Associated report ID
    report_revision: Optional[int] = None  # Report revision when retest was requested
    tested_commit_hash: Optional[str] = None  # Commit hash the test was originally submitted against

    @classmethod
    def from_dict(cls, data: dict) -> 'RetestItem':
        return cls(
            type=data.get('type', ''),
            id=data.get('id', 0),
            test_key=data.get('test_key', ''),
            test_name=data.get('test_name', ''),
            client_version=data.get('client_version', ''),
            reason=data.get('reason', ''),
            latest_revision=data.get('latest_revision', False),
            commit_hash=data.get('commit_hash'),
            created_at=data.get('created_at'),
            notes=data.get('notes'),
            report_id=data.get('report_id'),
            report_revision=data.get('report_revision'),
            tested_commit_hash=data.get('tested_commit_hash')
        )


@dataclass
class ReportLog:
    """Represents a log file attached to a report."""
    id: int
    filename: str
    log_datetime: str
    size_original: int
    size_compressed: int
    created_at: Optional[str] = None
    data: Optional[bytes] = None  # Compressed data (when downloaded)

    @classmethod
    def from_dict(cls, data: dict) -> 'ReportLog':
        return cls(
            id=data.get('id', 0),
            filename=data.get('filename', ''),
            log_datetime=data.get('log_datetime', ''),
            size_original=data.get('size_original', 0),
            size_compressed=data.get('size_compressed', 0),
            created_at=data.get('created_at')
        )


@dataclass
class SubmitResult:
    """Result of a report submission."""
    success: bool
    report_id: Optional[int] = None
    client_version: Optional[str] = None
    tests_recorded: int = 0
    logs_attached: int = 0
    view_url: Optional[str] = None
    error: Optional[str] = None


@dataclass
class HashCheckVersionResult:
    """Result of hash check for a single version."""
    exists: bool
    hash_matches: bool
    server_hash: Optional[str]
    report_id: Optional[int]
    revision_count: int
    action: str  # 'skip', 'update', or 'create'


@dataclass
class HashCheckResult:
    """Result of checking report hashes with the server."""
    success: bool
    results: Dict[str, HashCheckVersionResult] = field(default_factory=dict)
    error: Optional[str] = None

    @classmethod
    def from_dict(cls, data: dict) -> 'HashCheckResult':
        if data.get('success'):
            results = {}
            for version_id, version_data in data.get('results', {}).items():
                results[version_id] = HashCheckVersionResult(
                    exists=version_data.get('exists', False),
                    hash_matches=version_data.get('hash_matches', False),
                    server_hash=version_data.get('server_hash'),
                    report_id=version_data.get('report_id'),
                    revision_count=version_data.get('revision_count', 0),
                    action=version_data.get('action', 'create')
                )
            return cls(success=True, results=results)
        return cls(success=False, error=data.get('error', 'Unknown error'))


@dataclass
class Revision:
    """Represents a Git revision/commit."""
    sha: str
    notes: str
    files: dict  # {'added': [], 'removed': [], 'modified': []}
    ts: int  # Unix timestamp
    datetime: str  # Formatted datetime string

    @classmethod
    def from_dict(cls, sha: str, data: dict) -> 'Revision':
        return cls(
            sha=sha,
            notes=data.get('notes', ''),
            files=data.get('files', {'added': [], 'removed': [], 'modified': []}),
            ts=data.get('ts', 0),
            datetime=data.get('datetime', '')
        )


@dataclass
class UserInfo:
    """Represents authenticated user info from the API."""
    success: bool
    username: Optional[str] = None
    revisions: Optional[List['Revision']] = None  # List of revisions, newest first
    revisions_count: int = 0
    error: Optional[str] = None

    @classmethod
    def from_dict(cls, data: dict) -> 'UserInfo':
        if data.get('success'):
            user = data.get('user', {})
            # Parse revisions from API response
            revisions_data = data.get('revisions', {})
            revisions = []
            for sha, rev_data in revisions_data.items():
                revisions.append(Revision.from_dict(sha, rev_data))
            # Sort by timestamp, newest first
            revisions.sort(key=lambda r: r.ts, reverse=True)
            return cls(
                success=True,
                username=user.get('username'),
                revisions=revisions,
                revisions_count=data.get('revisions_count', len(revisions))
            )
        return cls(
            success=False,
            error=data.get('error', 'Unknown error')
        )


@dataclass
class Notification:
    """Represents a user notification."""
    id: int
    type: str  # 'retest', 'fixed', 'info'
    report_id: Optional[int]
    test_key: Optional[str]
    client_version: Optional[str]
    title: str
    message: str
    notes: Optional[str]
    is_read: bool
    created_at: str
    read_at: Optional[str] = None

    @classmethod
    def from_dict(cls, data: dict) -> 'Notification':
        return cls(
            id=data.get('id', 0),
            type=data.get('type', 'info'),
            report_id=data.get('report_id'),
            test_key=data.get('test_key'),
            client_version=data.get('client_version'),
            title=data.get('title', ''),
            message=data.get('message', ''),
            notes=data.get('notes'),
            is_read=bool(data.get('is_read', False)),
            created_at=data.get('created_at', ''),
            read_at=data.get('read_at')
        )


@dataclass
class NotificationsResult:
    """Result of fetching notifications."""
    success: bool
    unread_count: int = 0
    notifications: List[Notification] = field(default_factory=list)
    error: Optional[str] = None


@dataclass
class PendingSubmission:
    """Represents a report waiting to be submitted when online."""
    id: str  # Unique ID for this submission
    file_path: str  # Path to the session results JSON file
    data: Dict[str, Any]  # The actual report data
    created_at: str  # ISO timestamp when queued
    attempts: int = 0  # Number of submission attempts
    last_error: Optional[str] = None  # Last error message

    def to_dict(self) -> dict:
        return {
            'id': self.id,
            'file_path': self.file_path,
            'data': self.data,
            'created_at': self.created_at,
            'attempts': self.attempts,
            'last_error': self.last_error
        }

    @classmethod
    def from_dict(cls, data: dict) -> 'PendingSubmission':
        return cls(
            id=data.get('id', ''),
            file_path=data.get('file_path', ''),
            data=data.get('data', {}),
            created_at=data.get('created_at', ''),
            attempts=data.get('attempts', 0),
            last_error=data.get('last_error')
        )


class DataCache:
    """
    Persistent cache for offline operation.

    Stores versions, tests, templates, and pending submissions to allow
    the tool to function when the API is unreachable.
    """

    CACHE_VERSION = 1  # Increment when cache format changes
    DEFAULT_CACHE_FILE = 'test_panel_cache.json'

    def __init__(self, cache_path: Optional[str] = None):
        """
        Initialize the data cache.

        Args:
            cache_path: Path to cache file. If None, uses default location.
        """
        if cache_path:
            self.cache_path = cache_path
        else:
            # Store cache in same directory as config, or user home
            self.cache_path = os.path.join(
                os.path.dirname(os.path.abspath(__file__)),
                self.DEFAULT_CACHE_FILE
            )

        self._data = {
            'cache_version': self.CACHE_VERSION,
            'last_sync': None,  # ISO timestamp of last successful sync
            'versions': [],  # List of version dicts
            'versions_hash': None,  # Hash to detect server-side changes
            'tests': [],  # List of test dicts (general test list)
            'tests_hash': None,
            'categories': [],  # List of category dicts
            'version_tests': {},  # Dict mapping version_id -> list of test dicts (template-based)
            'version_skip_tests': {},  # Dict mapping version_id -> list of skip test keys
            'pending_submissions': [],  # List of PendingSubmission dicts
            'connection_status': {
                'is_online': False,
                'last_online': None,
                'last_check': None
            }
        }

        self._dirty = False
        self._load()

    def _load(self) -> bool:
        """Load cache from disk."""
        if not os.path.exists(self.cache_path):
            logger.debug(f"Cache file not found: {self.cache_path}")
            return False

        try:
            with open(self.cache_path, 'r', encoding='utf-8') as f:
                data = json.load(f)

            # Check cache version
            if data.get('cache_version') != self.CACHE_VERSION:
                logger.warning(f"Cache version mismatch, clearing cache")
                return False

            self._data = data
            logger.info(f"Loaded cache from {self.cache_path}")

            # Log cache status
            if self._data.get('last_sync'):
                logger.info(f"  Last sync: {self._data['last_sync']}")
            if self._data.get('versions'):
                logger.info(f"  Cached versions: {len(self._data['versions'])}")
            if self._data.get('tests'):
                logger.info(f"  Cached tests: {len(self._data['tests'])}")
            pending = self._data.get('pending_submissions', [])
            if pending:
                logger.info(f"  Pending submissions: {len(pending)}")

            return True
        except Exception as e:
            logger.error(f"Failed to load cache: {e}")
            return False

    def save(self) -> bool:
        """Save cache to disk."""
        if not self._dirty:
            return True

        try:
            # Ensure directory exists
            os.makedirs(os.path.dirname(self.cache_path) or '.', exist_ok=True)

            with open(self.cache_path, 'w', encoding='utf-8') as f:
                json.dump(self._data, f, indent=2)

            self._dirty = False
            logger.debug(f"Saved cache to {self.cache_path}")
            return True
        except Exception as e:
            logger.error(f"Failed to save cache: {e}")
            return False

    def _mark_dirty(self):
        """Mark cache as needing to be saved."""
        self._dirty = True

    # =========== Versions ===========

    def get_versions(self) -> List[dict]:
        """Get cached versions."""
        return self._data.get('versions', [])

    def set_versions(self, versions: List[dict], hash_value: Optional[str] = None):
        """Update cached versions."""
        self._data['versions'] = versions
        self._data['versions_hash'] = hash_value
        self._data['last_sync'] = datetime.now().isoformat()
        self._mark_dirty()

    def get_versions_hash(self) -> Optional[str]:
        """Get hash of cached versions for comparison."""
        return self._data.get('versions_hash')

    # =========== Tests ===========

    def get_tests(self) -> List[dict]:
        """Get cached general test list."""
        return self._data.get('tests', [])

    def get_categories(self) -> List[dict]:
        """Get cached categories."""
        return self._data.get('categories', [])

    def set_tests(self, tests: List[dict], categories: List[dict], hash_value: Optional[str] = None):
        """Update cached general test list."""
        self._data['tests'] = tests
        self._data['categories'] = categories
        self._data['tests_hash'] = hash_value
        self._data['last_sync'] = datetime.now().isoformat()
        self._mark_dirty()

    def get_tests_hash(self) -> Optional[str]:
        """Get hash of cached tests for comparison."""
        return self._data.get('tests_hash')

    # =========== Version-specific tests (templates) ===========

    def get_version_tests(self, version_id: str) -> Optional[List[dict]]:
        """Get cached tests for a specific version (from template)."""
        return self._data.get('version_tests', {}).get(version_id)

    def get_version_skip_tests(self, version_id: str) -> List[str]:
        """Get cached skip tests for a specific version."""
        return self._data.get('version_skip_tests', {}).get(version_id, [])

    def set_version_tests(self, version_id: str, tests: List[dict], skip_tests: List[str]):
        """Cache tests for a specific version."""
        if 'version_tests' not in self._data:
            self._data['version_tests'] = {}
        if 'version_skip_tests' not in self._data:
            self._data['version_skip_tests'] = {}

        self._data['version_tests'][version_id] = tests
        self._data['version_skip_tests'][version_id] = skip_tests
        self._mark_dirty()

    def clear_version_tests(self, version_id: Optional[str] = None):
        """Clear cached version-specific tests."""
        if version_id:
            self._data.get('version_tests', {}).pop(version_id, None)
            self._data.get('version_skip_tests', {}).pop(version_id, None)
        else:
            self._data['version_tests'] = {}
            self._data['version_skip_tests'] = {}
        self._mark_dirty()

    # =========== Pending Submissions ===========

    def add_pending_submission(self, file_path: str, data: Dict[str, Any]) -> str:
        """
        Add a report to the pending submission queue.

        Returns:
            Unique ID for the pending submission
        """
        import uuid
        submission_id = str(uuid.uuid4())[:8]

        pending = PendingSubmission(
            id=submission_id,
            file_path=file_path,
            data=data,
            created_at=datetime.now().isoformat()
        )

        if 'pending_submissions' not in self._data:
            self._data['pending_submissions'] = []

        self._data['pending_submissions'].append(pending.to_dict())
        self._mark_dirty()
        self.save()  # Save immediately for pending submissions

        logger.info(f"Queued report for submission: {submission_id}")
        return submission_id

    def get_pending_submissions(self) -> List[PendingSubmission]:
        """Get all pending submissions."""
        return [PendingSubmission.from_dict(p) for p in self._data.get('pending_submissions', [])]

    def get_pending_count(self) -> int:
        """Get count of pending submissions."""
        return len(self._data.get('pending_submissions', []))

    def remove_pending_submission(self, submission_id: str) -> bool:
        """Remove a pending submission (after successful submission)."""
        pending = self._data.get('pending_submissions', [])
        for i, p in enumerate(pending):
            if p.get('id') == submission_id:
                pending.pop(i)
                self._mark_dirty()
                self.save()
                logger.info(f"Removed pending submission: {submission_id}")
                return True
        return False

    def update_pending_submission(self, submission_id: str, attempts: int, error: Optional[str] = None):
        """Update a pending submission's attempt count and error."""
        pending = self._data.get('pending_submissions', [])
        for p in pending:
            if p.get('id') == submission_id:
                p['attempts'] = attempts
                p['last_error'] = error
                self._mark_dirty()
                self.save()
                return True
        return False

    # =========== Connection Status ===========

    def set_online_status(self, is_online: bool):
        """Update connection status."""
        self._data['connection_status']['is_online'] = is_online
        self._data['connection_status']['last_check'] = datetime.now().isoformat()
        if is_online:
            self._data['connection_status']['last_online'] = datetime.now().isoformat()
        self._mark_dirty()

    def is_online(self) -> bool:
        """Get last known online status."""
        return self._data.get('connection_status', {}).get('is_online', False)

    def get_last_sync(self) -> Optional[str]:
        """Get timestamp of last successful sync."""
        return self._data.get('last_sync')

    def has_cached_data(self) -> bool:
        """Check if we have any cached data available."""
        return bool(self._data.get('versions') or self._data.get('tests'))

    # =========== Cache Management ===========

    def clear(self):
        """Clear all cached data (but keep pending submissions)."""
        pending = self._data.get('pending_submissions', [])
        self._data = {
            'cache_version': self.CACHE_VERSION,
            'last_sync': None,
            'versions': [],
            'versions_hash': None,
            'tests': [],
            'tests_hash': None,
            'categories': [],
            'version_tests': {},
            'version_skip_tests': {},
            'pending_submissions': pending,  # Preserve pending submissions
            'connection_status': {
                'is_online': False,
                'last_online': None,
                'last_check': None
            }
        }
        self._mark_dirty()
        self.save()
        logger.info("Cache cleared (pending submissions preserved)")


class TestPanelClient:
    """
    Client for the Steam Emulator Test Panel API.

    Provides methods for:
    - Submitting test reports
    - Checking retest queue
    - Periodic background checking for retests
    """

    DEFAULT_CONFIG_PATHS = [
        'test_panel_config.json',
        'config.json',
        os.path.expanduser('~/.steam_test_panel.json'),
        os.path.expanduser('~/test_panel_config.json'),
    ]

    def __init__(self, config: Config, cache_path: Optional[str] = None):
        """
        Initialize the client with a configuration.

        Args:
            config: Configuration object
            cache_path: Optional path to cache file for offline support
        """
        self.config = config
        self._check_thread: Optional[threading.Thread] = None
        self._stop_checking = threading.Event()
        self._retest_callbacks: List[Callable[[List[RetestItem]], None]] = []
        self._last_retest_check: Optional[datetime] = None
        self._cached_retests: List[RetestItem] = []

        # Initialize data cache for offline support
        self.cache = DataCache(cache_path)
        self._is_online = False
        self._online_callbacks: List[Callable[[bool], None]] = []

        # Validate configuration
        errors = config.validate()
        if errors:
            raise ValueError(f"Invalid configuration: {', '.join(errors)}")

    @classmethod
    def from_config_file(cls, path: str) -> 'TestPanelClient':
        """
        Create a client from a config file.

        Args:
            path: Path to JSON config file

        Returns:
            TestPanelClient instance
        """
        with open(path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        config = Config.from_dict(data)
        return cls(config)

    @classmethod
    def from_config(cls, path: Optional[str] = None) -> 'TestPanelClient':
        """
        Create a client, searching for config in default locations if path not provided.

        Args:
            path: Optional path to config file

        Returns:
            TestPanelClient instance
        """
        if path:
            return cls.from_config_file(path)

        # Search default locations
        for default_path in cls.DEFAULT_CONFIG_PATHS:
            if os.path.isfile(default_path):
                logger.info(f"Loading config from: {default_path}")
                return cls.from_config_file(default_path)

        raise FileNotFoundError(
            f"Config file not found. Searched: {cls.DEFAULT_CONFIG_PATHS}. "
            "Create a config file or provide the path explicitly."
        )

    @staticmethod
    def create_config_template(path: str = 'test_panel_config.json') -> None:
        """
        Create a template config file.

        Args:
            path: Path where to save the template
        """
        template = {
            'api_url': 'http://localhost/test_api',
            'api_key': 'sk_your_api_key_here',
            'check_interval': 600,
            'auto_check_retests': True,
            'timeout': 30
        }
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(template, f, indent=2)
        print(f"Config template created: {path}")
        print("Edit the file with your API URL and key.")

    def _get_headers(self) -> dict:
        """Get headers for API requests."""
        return {
            'Content-Type': 'application/json',
            'X-API-Key': self.config.api_key
        }

    def _make_request(self, method: str, endpoint: str, data: Optional[dict] = None,
                      params: Optional[dict] = None) -> requests.Response:
        """
        Make an API request.

        Args:
            method: HTTP method (GET, POST, etc.)
            endpoint: API endpoint path
            data: Optional JSON data for POST requests
            params: Optional query parameters

        Returns:
            Response object
        """
        url = f"{self.config.api_url}/{endpoint.lstrip('/')}"

        kwargs = {
            'headers': self._get_headers(),
            'timeout': self.config.timeout,
        }

        if params:
            kwargs['params'] = params
        if data:
            kwargs['json'] = data

        response = requests.request(method, url, **kwargs)
        return response

    def submit_report(self, file_path: str, verbose: bool = False,
                      queue_if_offline: bool = True) -> SubmitResult:
        """
        Submit a test report to the API, with offline queuing support.

        Args:
            file_path: Path to session_results.json file
            verbose: Print detailed output
            queue_if_offline: If True, queue the report for later submission when offline

        Returns:
            SubmitResult with submission details
        """
        # Validate file exists
        if not os.path.exists(file_path):
            return SubmitResult(success=False, error=f"File not found: {file_path}")

        # Read and parse JSON file
        try:
            with open(file_path, 'r', encoding='utf-8') as f:
                data = json.load(f)
        except json.JSONDecodeError as e:
            return SubmitResult(success=False, error=f"Invalid JSON: {e}")
        except IOError as e:
            return SubmitResult(success=False, error=f"Could not read file: {e}")

        # Clean HTML from notes before API submission
        # This strips HTML but preserves embedded images and code blocks
        data = prepare_data_for_api(data)

        if verbose:
            logger.info(f"Loaded report file: {file_path}")
            if 'meta' in data:
                logger.info(f"  Tester: {data['meta'].get('tester', 'Unknown')}")
            if 'results' in data:
                versions = list(data['results'].keys())
                logger.info(f"  Versions: {len(versions)}")

        # Try to submit to API
        try:
            response = self._make_request('POST', '/api/submit.php', data=data)

            try:
                result = response.json()
            except json.JSONDecodeError:
                result = {'raw_response': response.text}

            if response.status_code == 201:
                self._update_online_status(True)
                # Handle multi-report response format
                reports = result.get('reports', [])
                if reports:
                    first_report = reports[0]
                    return SubmitResult(
                        success=True,
                        report_id=first_report.get('report_id'),
                        client_version=first_report.get('client_version'),
                        tests_recorded=first_report.get('tests_recorded', 0),
                        logs_attached=first_report.get('logs_attached', 0),
                        view_url=first_report.get('view_url')
                    )
                # Legacy single-report format
                return SubmitResult(
                    success=True,
                    report_id=result.get('report_id'),
                    client_version=result.get('client_version'),
                    tests_recorded=result.get('tests_recorded', 0),
                    logs_attached=result.get('logs_attached', 0),
                    view_url=result.get('view_url')
                )
            else:
                return SubmitResult(
                    success=False,
                    error=result.get('error', f"HTTP {response.status_code}")
                )

        except requests.exceptions.ConnectionError:
            self._update_online_status(False)
            if queue_if_offline:
                submission_id = self.cache.add_pending_submission(file_path, data)
                return SubmitResult(
                    success=False,
                    error=f"Offline - report queued for later submission (ID: {submission_id})"
                )
            return SubmitResult(success=False, error="Could not connect to API")
        except requests.exceptions.Timeout:
            self._update_online_status(False)
            if queue_if_offline:
                submission_id = self.cache.add_pending_submission(file_path, data)
                return SubmitResult(
                    success=False,
                    error=f"Timeout - report queued for later submission (ID: {submission_id})"
                )
            return SubmitResult(success=False, error="Request timed out")
        except requests.exceptions.RequestException as e:
            self._update_online_status(False)
            if queue_if_offline:
                submission_id = self.cache.add_pending_submission(file_path, data)
                return SubmitResult(
                    success=False,
                    error=f"Error - report queued for later submission (ID: {submission_id})"
                )
            return SubmitResult(success=False, error=str(e))

    def submit_report_data(self, data: Dict[str, Any], queue_if_offline: bool = True) -> SubmitResult:
        """
        Submit report data directly (without reading from file).

        Args:
            data: Report data dict
            queue_if_offline: If True, queue the report for later submission when offline

        Returns:
            SubmitResult with submission details
        """
        # Clean HTML from notes before API submission
        data = prepare_data_for_api(data)

        try:
            response = self._make_request('POST', '/api/submit.php', data=data)

            try:
                result = response.json()
            except json.JSONDecodeError:
                result = {'raw_response': response.text}

            if response.status_code == 201:
                self._update_online_status(True)
                reports = result.get('reports', [])
                if reports:
                    first_report = reports[0]
                    return SubmitResult(
                        success=True,
                        report_id=first_report.get('report_id'),
                        client_version=first_report.get('client_version'),
                        tests_recorded=first_report.get('tests_recorded', 0),
                        logs_attached=first_report.get('logs_attached', 0),
                        view_url=first_report.get('view_url')
                    )
                return SubmitResult(
                    success=True,
                    report_id=result.get('report_id'),
                    client_version=result.get('client_version'),
                    tests_recorded=result.get('tests_recorded', 0),
                    logs_attached=result.get('logs_attached', 0),
                    view_url=result.get('view_url')
                )
            else:
                return SubmitResult(
                    success=False,
                    error=result.get('error', f"HTTP {response.status_code}")
                )

        except requests.exceptions.RequestException as e:
            self._update_online_status(False)
            if queue_if_offline:
                submission_id = self.cache.add_pending_submission('', data)
                return SubmitResult(
                    success=False,
                    error=f"Offline - report queued (ID: {submission_id})"
                )
            return SubmitResult(success=False, error=str(e))

    def _process_pending_submissions(self) -> int:
        """
        Process pending submissions when coming back online.

        Returns:
            Number of successfully submitted reports
        """
        pending = self.cache.get_pending_submissions()
        if not pending:
            return 0

        logger.info(f"Processing {len(pending)} pending submissions...")
        success_count = 0

        for submission in pending:
            try:
                result = self.submit_report_data(submission.data, queue_if_offline=False)
                if result.success:
                    self.cache.remove_pending_submission(submission.id)
                    success_count += 1
                    logger.info(f"  Submitted pending report {submission.id} (Report ID: {result.report_id})")
                else:
                    # Update attempt count
                    self.cache.update_pending_submission(
                        submission.id,
                        submission.attempts + 1,
                        result.error
                    )
                    logger.warning(f"  Failed to submit {submission.id}: {result.error}")
            except Exception as e:
                self.cache.update_pending_submission(
                    submission.id,
                    submission.attempts + 1,
                    str(e)
                )
                logger.error(f"  Error submitting {submission.id}: {e}")

        if success_count > 0:
            logger.info(f"Successfully submitted {success_count} of {len(pending)} pending reports")

        return success_count

    def retry_pending_submissions(self) -> int:
        """
        Manually retry pending submissions.

        Returns:
            Number of successfully submitted reports
        """
        return self._process_pending_submissions()

    def get_pending_submissions(self) -> List[PendingSubmission]:
        """Get list of pending submissions."""
        return self.cache.get_pending_submissions()

    def clear_pending_submission(self, submission_id: str) -> bool:
        """Remove a pending submission from the queue."""
        return self.cache.remove_pending_submission(submission_id)

    def check_hashes(self, hashes: Dict[str, str], tester: str, test_type: str,
                     commit_hash: Optional[str] = None) -> HashCheckResult:
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
        try:
            data = {
                'hashes': hashes,
                'tester': tester,
                'test_type': test_type
            }
            if commit_hash:
                data['commit_hash'] = commit_hash

            response = self._make_request('POST', '/api/check_hash.php', data=data)

            try:
                result = response.json()
            except json.JSONDecodeError:
                return HashCheckResult(success=False, error="Invalid JSON response from server")

            if response.status_code == 200:
                return HashCheckResult.from_dict(result)
            else:
                return HashCheckResult(
                    success=False,
                    error=result.get('error', f"HTTP {response.status_code}")
                )

        except requests.exceptions.ConnectionError:
            return HashCheckResult(success=False, error="Could not connect to API")
        except requests.exceptions.Timeout:
            return HashCheckResult(success=False, error="Request timed out")
        except requests.exceptions.RequestException as e:
            return HashCheckResult(success=False, error=str(e))

    def get_retest_queue(self, client_version: Optional[str] = None) -> List[RetestItem]:
        """
        Get the current retest queue from the API.

        Args:
            client_version: Optional filter by client version

        Returns:
            List of RetestItem objects
        """
        params = {}
        if client_version:
            params['client_version'] = client_version

        try:
            response = self._make_request('GET', '/api/retests.php', params=params)

            if response.status_code == 200:
                data = response.json()
                if data.get('success'):
                    items = [RetestItem.from_dict(item) for item in data.get('retest_queue', [])]
                    self._cached_retests = items
                    self._last_retest_check = datetime.now()
                    return items

            logger.warning(f"Failed to get retest queue: HTTP {response.status_code}")
            return []

        except requests.exceptions.RequestException as e:
            logger.error(f"Error fetching retest queue: {e}")
            return []

    def check_flags(self) -> dict:
        """
        Lightweight flag check for polling - checks for unacknowledged flags.

        This is a low-overhead call designed for periodic background polling.

        Returns:
            Dict with 'success', 'count', and 'flags' keys
        """
        try:
            response = self._make_request('GET', '/api/flag_check.php')

            if response.status_code == 200:
                data = response.json()
                if data.get('success'):
                    return {
                        'success': True,
                        'count': data.get('count', 0),
                        'flags': data.get('flags', [])
                    }
                return {'success': False, 'count': 0, 'flags': [], 'error': data.get('error', 'Unknown error')}

            return {'success': False, 'count': 0, 'flags': [], 'error': f'HTTP {response.status_code}'}

        except requests.exceptions.RequestException as e:
            logger.debug(f"Flag check failed (offline?): {e}")
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
        try:
            response = self._make_request('POST', '/api/flag_check.php', data={
                'type': flag_type,
                'id': flag_id
            })

            if response.status_code == 200:
                data = response.json()
                return data.get('success', False)

            return False

        except requests.exceptions.RequestException as e:
            logger.error(f"Error acknowledging flag: {e}")
            return False

    def get_tests(self, enabled_only: bool = True, client_version: Optional[str] = None,
                  use_cache: bool = True) -> TestsResult:
        """
        Get test types and categories from the API, with offline cache support.

        Args:
            enabled_only: If True, only return enabled tests
            client_version: Optional client version string to get version-specific template tests
            use_cache: If True, return cached data when offline

        Returns:
            TestsResult with tests grouped by category
        """
        params = {}
        if not enabled_only:
            params['all'] = '1'
        if client_version:
            params['client_version'] = client_version

        try:
            response = self._make_request('GET', '/api/tests.php', params=params)

            if response.status_code == 200:
                # Check for empty response body before parsing JSON
                if not response.text or not response.text.strip():
                    logger.warning("Empty response body from tests API")
                    return self._get_cached_tests(client_version) if use_cache else TestsResult(success=False, error="Empty response from server")

                try:
                    data = response.json()
                except (json.JSONDecodeError, ValueError) as e:
                    logger.error(f"Invalid JSON response from tests API: {e}")
                    logger.debug(f"Response text: {response.text[:500] if response.text else '(empty)'}")
                    return self._get_cached_tests(client_version) if use_cache else TestsResult(success=False, error=f"Invalid JSON response: {e}")

                if data.get('success'):
                    categories = [TestCategory.from_dict(c) for c in data.get('categories', [])]
                    tests = [TestType.from_dict(t) for t in data.get('tests', [])]

                    # Build grouped dict with TestType objects
                    grouped = {}
                    for cat_name, test_list in data.get('grouped', {}).items():
                        grouped[cat_name] = [TestType.from_dict(t) for t in test_list]

                    # Get skip_tests from the response (synced with admin_versions settings)
                    skip_tests = data.get('skip_tests', [])

                    result = TestsResult(
                        success=True,
                        categories=categories,
                        tests=tests,
                        grouped=grouped,
                        template=data.get('template'),  # Include template info if present
                        skip_tests=skip_tests  # Include skip_tests from version settings
                    )

                    # Update cache with successful response
                    self._update_online_status(True)
                    if client_version:
                        # Cache version-specific tests
                        test_dicts = [{'test_key': t.test_key, 'name': t.name, 'description': t.description,
                                      'category_id': t.category_id, 'category_name': t.category_name,
                                      'sort_order': t.sort_order, 'is_enabled': t.is_enabled} for t in tests]
                        self.cache.set_version_tests(client_version, test_dicts, skip_tests)
                    else:
                        # Cache general test list
                        test_dicts = [{'test_key': t.test_key, 'name': t.name, 'description': t.description,
                                      'category_id': t.category_id, 'category_name': t.category_name,
                                      'sort_order': t.sort_order, 'is_enabled': t.is_enabled} for t in tests]
                        cat_dicts = [{'id': c.id, 'name': c.name, 'sort_order': c.sort_order} for c in categories]
                        self.cache.set_tests(test_dicts, cat_dicts)
                    self.cache.save()

                    return result
                else:
                    error_msg = data.get('error', 'Unknown error from server')
                    logger.warning(f"Tests API returned error: {error_msg}")
                    return self._get_cached_tests(client_version) if use_cache else TestsResult(success=False, error=error_msg)

            # Handle non-200 status codes with more detail
            error_msg = f"HTTP {response.status_code}"
            if response.status_code == 401:
                error_msg = "Authentication failed - check API key"
            elif response.status_code == 403:
                error_msg = "Access denied"
            elif response.status_code == 404:
                error_msg = "API endpoint not found - check API URL"
            elif response.status_code >= 500:
                error_msg = f"Server error (HTTP {response.status_code})"

            logger.warning(f"Failed to get tests: {error_msg}")
            return self._get_cached_tests(client_version) if use_cache else TestsResult(success=False, error=error_msg)

        except requests.exceptions.RequestException as e:
            logger.error(f"Error fetching tests: {e}")
            self._update_online_status(False)
            return self._get_cached_tests(client_version) if use_cache else TestsResult(success=False, error=str(e))

    def _get_cached_tests(self, client_version: Optional[str] = None) -> TestsResult:
        """Get tests from cache when offline."""
        if client_version:
            # Try version-specific cache
            cached_tests = self.cache.get_version_tests(client_version)
            cached_skip = self.cache.get_version_skip_tests(client_version)
            if cached_tests:
                tests = [TestType.from_dict(t) for t in cached_tests]
                logger.info(f"Using cached tests for version {client_version} ({len(tests)} tests)")
                return TestsResult(
                    success=True,
                    tests=tests,
                    skip_tests=cached_skip,
                    error="Using cached data (offline)"
                )

        # Fall back to general test cache
        cached_tests = self.cache.get_tests()
        cached_cats = self.cache.get_categories()
        if cached_tests:
            tests = [TestType.from_dict(t) for t in cached_tests]
            categories = [TestCategory.from_dict(c) for c in cached_cats]
            logger.info(f"Using cached tests ({len(tests)} tests)")
            return TestsResult(
                success=True,
                tests=tests,
                categories=categories,
                error="Using cached data (offline)"
            )

        return TestsResult(success=False, error="No cached data available")

    def get_versions(self, enabled_only: bool = True, include_notifications: bool = False,
                     use_cache: bool = True) -> VersionsResult:
        """
        Get client versions from the API, with offline cache support.

        Args:
            enabled_only: If True, only return enabled versions
            include_notifications: If True, include notifications for each version
            use_cache: If True, return cached data when offline

        Returns:
            VersionsResult with list of ClientVersion objects
        """
        params = {}
        if not enabled_only:
            params['all'] = '1'
        if include_notifications:
            params['notifications'] = '1'

        try:
            response = self._make_request('GET', '/api/versions.php', params=params)

            if response.status_code == 200:
                # Check for empty response body before parsing JSON
                if not response.text or not response.text.strip():
                    logger.warning("Empty response body from versions API")
                    return self._get_cached_versions() if use_cache else VersionsResult(success=False, error="Empty response from server")

                try:
                    data = response.json()
                except (json.JSONDecodeError, ValueError) as e:
                    logger.error(f"Invalid JSON response from versions API: {e}")
                    logger.debug(f"Response text: {response.text[:500] if response.text else '(empty)'}")
                    return self._get_cached_versions() if use_cache else VersionsResult(success=False, error=f"Invalid JSON response: {e}")

                if data.get('success'):
                    versions = [ClientVersion.from_dict(v) for v in data.get('versions', [])]

                    result = VersionsResult(
                        success=True,
                        versions=versions
                    )

                    # Update cache with successful response
                    self._update_online_status(True)
                    version_dicts = []
                    for v in versions:
                        v_dict = {
                            'id': v.id,
                            'packages': v.packages,
                            'steam_date': v.steam_date,
                            'steam_time': v.steam_time,
                            'skip_tests': v.skip_tests,
                            'display_name': v.display_name,
                            'sort_order': v.sort_order,
                            'is_enabled': v.is_enabled,
                            'notifications': [{'id': n.id, 'name': n.name, 'message': n.message,
                                             'commit_hash': n.commit_hash, 'created_at': n.created_at}
                                            for n in v.notifications],
                            'notification_count': v.notification_count
                        }
                        version_dicts.append(v_dict)
                    self.cache.set_versions(version_dicts)
                    self.cache.save()

                    return result
                else:
                    error_msg = data.get('error', 'Unknown error from server')
                    logger.warning(f"Versions API returned error: {error_msg}")
                    return self._get_cached_versions() if use_cache else VersionsResult(success=False, error=error_msg)

            # Handle non-200 status codes with more detail
            error_msg = f"HTTP {response.status_code}"
            if response.status_code == 401:
                error_msg = "Authentication failed - check API key"
            elif response.status_code == 403:
                error_msg = "Access denied"
            elif response.status_code == 404:
                error_msg = "API endpoint not found - check API URL"
            elif response.status_code >= 500:
                error_msg = f"Server error (HTTP {response.status_code})"

            logger.warning(f"Failed to get versions: {error_msg}")
            return self._get_cached_versions() if use_cache else VersionsResult(success=False, error=error_msg)

        except requests.exceptions.RequestException as e:
            logger.error(f"Error fetching versions: {e}")
            self._update_online_status(False)
            return self._get_cached_versions() if use_cache else VersionsResult(success=False, error=str(e))

    def _get_cached_versions(self) -> VersionsResult:
        """Get versions from cache when offline."""
        cached_versions = self.cache.get_versions()
        if cached_versions:
            versions = [ClientVersion.from_dict(v) for v in cached_versions]
            logger.info(f"Using cached versions ({len(versions)} versions)")
            return VersionsResult(
                success=True,
                versions=versions,
                error="Using cached data (offline)"
            )
        return VersionsResult(success=False, error="No cached data available")

    def _update_online_status(self, is_online: bool):
        """Update online status and notify callbacks."""
        was_online = self._is_online
        self._is_online = is_online
        self.cache.set_online_status(is_online)

        # Notify callbacks if status changed
        if was_online != is_online:
            for callback in self._online_callbacks:
                try:
                    callback(is_online)
                except Exception as e:
                    logger.error(f"Error in online status callback: {e}")

            if is_online and not was_online:
                logger.info("Connection restored - now online")
                # Try to submit pending reports
                self._process_pending_submissions()
            elif not is_online and was_online:
                logger.warning("Connection lost - now offline")

    def add_online_callback(self, callback: Callable[[bool], None]):
        """Add callback to be notified when online status changes."""
        self._online_callbacks.append(callback)

    def remove_online_callback(self, callback: Callable[[bool], None]):
        """Remove online status callback."""
        if callback in self._online_callbacks:
            self._online_callbacks.remove(callback)

    def is_online(self) -> bool:
        """Check if currently online (based on last API call)."""
        return self._is_online

    def has_cached_data(self) -> bool:
        """Check if there's cached data available for offline use."""
        return self.cache.has_cached_data()

    def get_pending_submissions_count(self) -> int:
        """Get count of reports waiting to be submitted."""
        return self.cache.get_pending_count()

    def get_version_notifications(self, version_id: str, commit_hash: Optional[str] = None) -> List[VersionNotification]:
        """
        Get notifications/known issues for a specific client version.

        Args:
            version_id: The client version ID string
            commit_hash: Optional commit hash to filter notifications

        Returns:
            List of VersionNotification objects (oldest first, for stacking)
        """
        try:
            data = {
                'action': 'get_notifications',
                'version_id': version_id
            }
            if commit_hash:
                data['commit_hash'] = commit_hash

            response = self._make_request('POST', '/api/versions.php', data=data)

            if response.status_code == 200:
                result = response.json()
                if result.get('success'):
                    return [VersionNotification.from_dict(n) for n in result.get('notifications', [])]

            logger.warning(f"Failed to get version notifications: HTTP {response.status_code}")
            return []

        except requests.exceptions.RequestException as e:
            logger.error(f"Error fetching version notifications: {e}")
            return []

    def get_notifications_batch(self, version_ids: List[str], commit_hash: Optional[str] = None) -> Dict[str, List[VersionNotification]]:
        """
        Get notifications for multiple versions at once.

        Args:
            version_ids: List of version ID strings
            commit_hash: Optional commit hash to filter notifications

        Returns:
            Dict mapping version_id to list of VersionNotification objects
        """
        try:
            data = {
                'action': 'get_notifications_batch',
                'version_ids': version_ids
            }
            if commit_hash:
                data['commit_hash'] = commit_hash

            response = self._make_request('POST', '/api/versions.php', data=data)

            if response.status_code == 200:
                result = response.json()
                if result.get('success'):
                    notifications_by_version = {}
                    for version_id, notifs in result.get('notifications_by_version', {}).items():
                        notifications_by_version[version_id] = [VersionNotification.from_dict(n) for n in notifs]
                    return notifications_by_version

            logger.warning(f"Failed to get batch notifications: HTTP {response.status_code}")
            return {}

        except requests.exceptions.RequestException as e:
            logger.error(f"Error fetching batch notifications: {e}")
            return {}

    def get_user_info(self) -> UserInfo:
        """
        Get info about the authenticated user.

        Returns:
            UserInfo with username if successful
        """
        try:
            response = self._make_request('GET', '/api/user.php')

            if response.status_code == 200:
                data = response.json()
                return UserInfo.from_dict(data)

            logger.warning(f"Failed to get user info: HTTP {response.status_code}")
            return UserInfo(success=False, error=f"HTTP {response.status_code}")

        except requests.exceptions.RequestException as e:
            logger.error(f"Error fetching user info: {e}")
            return UserInfo(success=False, error=str(e))

    def get_report_logs(self, report_id: int) -> List[ReportLog]:
        """
        Get list of log files attached to a report.

        Args:
            report_id: The report ID

        Returns:
            List of ReportLog objects (without data)
        """
        try:
            response = self._make_request('GET', '/api/logs.php', params={'report_id': report_id})

            if response.status_code == 200:
                data = response.json()
                if data.get('success'):
                    return [ReportLog.from_dict(log) for log in data.get('logs', [])]

            logger.warning(f"Failed to get report logs: HTTP {response.status_code}")
            return []

        except requests.exceptions.RequestException as e:
            logger.error(f"Error fetching report logs: {e}")
            return []

    def download_report_log(self, log_id: int, decompress: bool = True) -> Optional[Union[str, bytes]]:
        """
        Download a specific log file.

        Args:
            log_id: The log file ID
            decompress: If True, decompress and return as string. If False, return raw compressed bytes.

        Returns:
            Log content as string (if decompress=True) or bytes (if decompress=False), or None on error
        """
        try:
            response = self._make_request('GET', '/api/logs.php', params={'log_id': log_id})

            if response.status_code == 200:
                data = response.json()
                if data.get('success') and 'log' in data:
                    log_data = data['log']
                    compressed_data = base64.b64decode(log_data.get('data', ''))

                    if decompress:
                        try:
                            # Try zlib decompress first (what PHP gzcompress uses)
                            import zlib
                            decompressed = zlib.decompress(compressed_data)
                            return decompressed.decode('utf-8', errors='replace')
                        except zlib.error:
                            # Try gzip decompress as fallback
                            try:
                                decompressed = gzip.decompress(compressed_data)
                                return decompressed.decode('utf-8', errors='replace')
                            except Exception:
                                logger.error("Failed to decompress log data")
                                return None
                    else:
                        return compressed_data

            logger.warning(f"Failed to download log: HTTP {response.status_code}")
            return None

        except requests.exceptions.RequestException as e:
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
        content = self.download_report_log(log_id, decompress=True)
        if content is not None:
            try:
                with open(output_path, 'w', encoding='utf-8') as f:
                    f.write(content)
                return True
            except IOError as e:
                logger.error(f"Failed to save log file: {e}")
                return False
        return False

    def delete_report_log(self, log_id: int) -> bool:
        """
        Delete a log file from a report.

        Args:
            log_id: The log file ID to delete

        Returns:
            True if successful
        """
        try:
            response = self._make_request('POST', '/api/logs.php', data={
                'action': 'delete',
                'log_id': log_id
            })

            if response.status_code == 200:
                data = response.json()
                return data.get('success', False)

            logger.warning(f"Failed to delete log: HTTP {response.status_code}")
            return False

        except requests.exceptions.RequestException as e:
            logger.error(f"Error deleting log: {e}")
            return False

    def find_report_id(self, tester: str, client_version: str, test_type: str) -> Optional[int]:
        """
        Find the report ID for a given tester, client version, and test type.

        Args:
            tester: The tester's username
            client_version: The client version ID
            test_type: The test type (WAN or LAN)

        Returns:
            The report ID if found, None otherwise
        """
        try:
            response = self._make_request('GET', '/api/reports.php', params={
                'tester': tester,
                'version': client_version,
                'type': test_type,
                'limit': 1
            })

            if response.status_code == 200:
                data = response.json()
                reports = data.get('reports', [])
                if reports:
                    return reports[0].get('id')

            return None

        except requests.exceptions.RequestException as e:
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
        import zlib
        from datetime import datetime

        with open(file_path, 'rb') as f:
            content = f.read()

        size_original = len(content)
        compressed = zlib.compress(content, 9)
        size_compressed = len(compressed)

        # Get file modification time
        file_stat = os.stat(file_path)
        file_datetime = datetime.fromtimestamp(file_stat.st_mtime).strftime('%Y-%m-%d %H:%M:%S')

        return {
            'filename': os.path.basename(file_path),
            'datetime': file_datetime,
            'size_original': size_original,
            'size_compressed': size_compressed,
            'data': base64.b64encode(compressed).decode('ascii')
        }

    def mark_retest_completed(self, item: RetestItem, new_status: Optional[str] = None) -> bool:
        """
        Mark a retest item as completed.

        Args:
            item: The retest item to mark as completed
            new_status: For 'fixed' type, the new test status ('Working', etc.)

        Returns:
            True if successful
        """
        data = {
            'type': item.type,
            'id': item.id
        }
        if new_status:
            data['new_status'] = new_status

        try:
            response = self._make_request('POST', '/api/retests.php', data=data)
            result = response.json()
            return result.get('success', False)
        except Exception as e:
            logger.error(f"Error marking retest completed: {e}")
            return False

    def get_notifications(self, unread_only: bool = False, limit: int = 50) -> NotificationsResult:
        """
        Get user notifications.

        Args:
            unread_only: If True, only return unread notifications
            limit: Maximum number of notifications to return

        Returns:
            NotificationsResult with notifications list
        """
        params = {'limit': limit}
        if unread_only:
            params['unread'] = 'true'

        try:
            response = self._make_request('GET', '/api/notifications.php', params=params)

            if response.status_code == 200:
                data = response.json()
                if data.get('success'):
                    notifications = [Notification.from_dict(n) for n in data.get('notifications', [])]
                    return NotificationsResult(
                        success=True,
                        unread_count=data.get('unread_count', 0),
                        notifications=notifications
                    )

            logger.warning(f"Failed to get notifications: HTTP {response.status_code}")
            return NotificationsResult(success=False, error=f"HTTP {response.status_code}")

        except requests.exceptions.RequestException as e:
            logger.error(f"Error fetching notifications: {e}")
            return NotificationsResult(success=False, error=str(e))

    def mark_notification_read(self, notification_id: int) -> bool:
        """
        Mark a notification as read.

        Args:
            notification_id: The notification ID

        Returns:
            True if successful
        """
        try:
            response = self._make_request('POST', '/api/notifications.php', data={
                'action': 'mark_read',
                'notification_id': notification_id
            })
            result = response.json()
            return result.get('success', False)
        except Exception as e:
            logger.error(f"Error marking notification read: {e}")
            return False

    def mark_all_notifications_read(self) -> bool:
        """
        Mark all notifications as read.

        Returns:
            True if successful
        """
        try:
            response = self._make_request('POST', '/api/notifications.php', data={
                'action': 'mark_all_read'
            })
            result = response.json()
            return result.get('success', False)
        except Exception as e:
            logger.error(f"Error marking all notifications read: {e}")
            return False

    def add_retest_callback(self, callback: Callable[[List[RetestItem]], None]) -> None:
        """
        Add a callback to be called when new retests are found.

        Args:
            callback: Function that takes a list of RetestItem objects
        """
        self._retest_callbacks.append(callback)

    def remove_retest_callback(self, callback: Callable[[List[RetestItem]], None]) -> None:
        """
        Remove a previously added callback.

        Args:
            callback: The callback function to remove
        """
        if callback in self._retest_callbacks:
            self._retest_callbacks.remove(callback)

    def _notify_callbacks(self, items: List[RetestItem]) -> None:
        """Notify all registered callbacks about new retests."""
        for callback in self._retest_callbacks:
            try:
                callback(items)
            except Exception as e:
                logger.error(f"Error in retest callback: {e}")

    def check_retests_now(self, client_version: Optional[str] = None) -> List[RetestItem]:
        """
        Check for retests immediately and notify callbacks if any found.

        Args:
            client_version: Optional filter by client version

        Returns:
            List of retest items
        """
        items = self.get_retest_queue(client_version)
        if items:
            self._notify_callbacks(items)
        return items

    def start_periodic_checking(self, client_version: Optional[str] = None) -> None:
        """
        Start periodic background checking for retests.

        Args:
            client_version: Optional filter by client version
        """
        if self._check_thread and self._check_thread.is_alive():
            logger.warning("Periodic checking already running")
            return

        self._stop_checking.clear()

        def check_loop():
            logger.info(f"Starting periodic retest checking (interval: {self.config.check_interval}s)")

            # Initial check
            self.check_retests_now(client_version)

            while not self._stop_checking.wait(self.config.check_interval):
                logger.debug("Checking for retests...")
                self.check_retests_now(client_version)

            logger.info("Periodic checking stopped")

        self._check_thread = threading.Thread(target=check_loop, daemon=True)
        self._check_thread.start()

    def stop_periodic_checking(self) -> None:
        """Stop periodic background checking."""
        self._stop_checking.set()
        if self._check_thread:
            self._check_thread.join(timeout=5)
            self._check_thread = None

    def get_cached_retests(self) -> List[RetestItem]:
        """Get the last fetched retest queue without making a new request."""
        return self._cached_retests

    def get_last_check_time(self) -> Optional[datetime]:
        """Get the time of the last retest check."""
        return self._last_retest_check

    def test_connection(self) -> bool:
        """
        Test the API connection.

        Returns:
            True if connection is successful
        """
        try:
            response = self._make_request('GET', '/api/retests.php')
            return response.status_code in (200, 401)  # 401 means connected but wrong key
        except Exception:
            return False


def print_retests(items: List[RetestItem]) -> None:
    """Print retest items in a formatted way."""
    if not items:
        print("No pending retests.")
        return

    print(f"\n{'='*60}")
    print(f"RETEST QUEUE ({len(items)} items)")
    print(f"{'='*60}")

    for item in items:
        print(f"\n[{item.type.upper()}] Test {item.test_key}: {item.test_name}")
        print(f"  Version: {item.client_version}")
        print(f"  Reason: {item.reason}")
        if item.notes:
            print(f"  Admin Notes: {item.notes}")
        if item.report_id:
            revision_info = f" (revision {item.report_revision})" if item.report_revision is not None else ""
            print(f"  Report ID: {item.report_id}{revision_info}")
        if item.latest_revision:
            print(f"  ** Uses LATEST REVISION **")
        if item.commit_hash:
            print(f"  Fix commit: {item.commit_hash}")

    print(f"\n{'='*60}\n")


def main():
    """Main CLI entry point."""
    import argparse

    parser = argparse.ArgumentParser(
        description='Steam Emulator Test Panel API Client',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Commands:
  submit FILE       Submit a test report
  check-retests     Check for pending retests
  test-connection   Test API connection
  create-config     Create a template config file
  daemon            Run as daemon checking for retests periodically

Examples:
  %(prog)s submit session_results.json
  %(prog)s check-retests --version "secondblob.bin.2004-01-15"
  %(prog)s daemon --interval 300
  %(prog)s create-config --output my_config.json
        """
    )

    parser.add_argument(
        '-c', '--config',
        help='Path to config file (default: searches standard locations)'
    )

    parser.add_argument(
        '-v', '--verbose',
        action='store_true',
        help='Verbose output'
    )

    subparsers = parser.add_subparsers(dest='command', help='Command to run')

    # Submit command
    submit_parser = subparsers.add_parser('submit', help='Submit a test report')
    submit_parser.add_argument('file', help='Path to session_results.json')

    # Check retests command
    check_parser = subparsers.add_parser('check-retests', help='Check for pending retests')
    check_parser.add_argument(
        '--version', '-V',
        help='Filter by client version'
    )

    # Test connection command
    subparsers.add_parser('test-connection', help='Test API connection')

    # Create config command
    config_parser = subparsers.add_parser('create-config', help='Create template config file')
    config_parser.add_argument(
        '-o', '--output',
        default='test_panel_config.json',
        help='Output path (default: test_panel_config.json)'
    )

    # Daemon command
    daemon_parser = subparsers.add_parser('daemon', help='Run as daemon checking retests')
    daemon_parser.add_argument(
        '--interval', '-i',
        type=int,
        default=600,
        help='Check interval in seconds (default: 600)'
    )
    daemon_parser.add_argument(
        '--version', '-V',
        help='Filter by client version'
    )

    args = parser.parse_args()

    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)

    # Handle create-config without needing existing config
    if args.command == 'create-config':
        TestPanelClient.create_config_template(args.output)
        return 0

    # All other commands need a client
    if not args.command:
        parser.print_help()
        return 0

    try:
        client = TestPanelClient.from_config(args.config)
    except FileNotFoundError as e:
        print(f"Error: {e}")
        print("\nRun 'python api_client.py create-config' to create a template.")
        return 1
    except ValueError as e:
        print(f"Config error: {e}")
        return 1

    # Execute commands
    if args.command == 'submit':
        print(f"Submitting report: {args.file}")
        result = client.submit_report(args.file, verbose=args.verbose)

        if result.success:
            print("\n✓ Report submitted successfully!")
            print(f"  Report ID: {result.report_id}")
            print(f"  Client Version: {result.client_version}")
            print(f"  Tests Recorded: {result.tests_recorded}")
            if result.view_url:
                print(f"  View URL: {result.view_url}")
            return 0
        else:
            print(f"\n✗ Submission failed: {result.error}")
            return 1

    elif args.command == 'check-retests':
        print("Checking for pending retests...")
        items = client.get_retest_queue(args.version)
        print_retests(items)
        return 0

    elif args.command == 'test-connection':
        print(f"Testing connection to: {client.config.api_url}")
        if client.test_connection():
            print("✓ Connection successful!")
            return 0
        else:
            print("✗ Connection failed!")
            return 1

    elif args.command == 'daemon':
        print(f"Starting daemon mode (checking every {args.interval}s)")
        print("Press Ctrl+C to stop.\n")

        # Override interval from command line
        client.config.check_interval = args.interval

        # Add callback to print retests
        client.add_retest_callback(print_retests)

        try:
            client.start_periodic_checking(args.version)

            # Keep main thread alive
            while True:
                time.sleep(1)
        except KeyboardInterrupt:
            print("\nStopping...")
            client.stop_periodic_checking()

        return 0

    return 0


if __name__ == '__main__':
    sys.exit(main())
