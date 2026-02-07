# Credits
XJR9000 - Python testing tool and idea

ShefBen - Implemented website and api connectivity

# Steam Emulator Test Panel

A comprehensive testing and reporting system for Steam emulator development, featuring a web-based dashboard, RESTful API, and desktop testing tool.

## Origins

This project was initially created by **XJR9000**, who developed the original Python-based STMServer testing tool. The original tool was designed to systematically test Steam emulator functionality and generate JSON reports that could be shared with server developers to track compatibility and identify issues.

The project has since been significantly expanded to include:

- A full-featured HTTP API for programmatic access
- A web-based administration panel for viewing and managing test reports
- Enhanced Python testing tool with API integration
- GitHub integration for tracking commits and revisions
- Multi-user support with role-based access control
- Report versioning and revision history

## What Is This?

The Steam Emulator Test Panel is a quality assurance and regression testing system designed for Steam emulator development. It provides a structured way to:

1. **Execute systematic tests** against different versions of the emulator
2. **Record and track results** across multiple Steam client versions
3. **Compare functionality** between different emulator builds
4. **Collaborate** between testers and developers
5. **Monitor progress** as issues are fixed and features are implemented

## Components

### Web Panel

The web panel provides a browser-based interface for:

- **Dashboard**: Overview of testing progress with statistics and charts
- **Reports**: Browse and filter submitted test reports
- **Test Results**: View individual test outcomes with notes and status
- **Version Tracking**: Compare results across different Steam client versions
- **Git History**: View repository commits with associated test data
- **User Management**: Admin controls for managing testers and permissions

### REST API

The HTTP API enables programmatic access to:

- Submit test reports from automated tools
- Retrieve current testing progress and statistics
- Manage report revisions
- Query test results by version, commit, or tester
- Access notification system for retest requests

### Python Testing Tool

The desktop application (built with PyQt5) provides:

- **Guided Testing**: Step-by-step interface for executing tests
- **Status Tracking**: Mark tests as Working, Semi-working, Not working, or N/A
- **Notes & Details**: Record observations and error messages for each test
- **Log Attachment**: Attach relevant log files to reports
- **API Integration**: Automatically sync with the web panel
- **Offline Mode**: Continue testing even without server connectivity
- **Session Management**: Save and resume testing sessions
- **Revision Selection**: Choose which emulator commit you're testing against
- **Keyboard Shortcuts**: Rapid test completion with keyboard-driven workflow

## Key Features

### Report Management
- Create detailed test reports for each Steam client version
- Attach log files and debugging information
- Track report revisions with full change history
- Compare results between different testers

### GitHub Integration
- Automatically fetch commit history from the repository
- Link test reports to specific commits
- View commit messages and changed files
- Track which commits have been tested

### Multi-Version Testing
- Test against multiple Steam client versions simultaneously
- Track compatibility across different client builds
- Identify regressions when functionality breaks
- Monitor improvements as issues are fixed

### Collaboration Features
- Request retests from other testers
- Notification system for important updates
- Comment system on reports
- Role-based access (admin/user)

### Regression & Progression Tracking
- **Dashboard Widgets**: Real-time display of recent regressions and progressions
- **Automatic Detection**: System detects when tests change status between submissions
- **Notifications**: Admins receive notifications when regressions occur
- **Version Comparison**: Compare any two versions side-by-side to see changes
- **Change History**: Full revision history with diffs for each report

### Global Search
- **Unified Search**: Search across reports, tests, versions, testers, and notes
- **Keyboard Shortcut**: Press `Ctrl+K` to open search from anywhere
- **Category Grouping**: Results organized by type for quick navigation
- **Full-Text Search**: Search within test notes and comments

### Test Templates
- **Predefined Templates**: Apply test configurations quickly
- **Version-Based Matching**: Auto-skip tests known to be N/A for specific versions
- **Customizable**: Create and manage templates through the admin panel
- **Default Status**: Set default status when applying templates

### Tagging System
- **Report Tags**: Label reports with custom tags (e.g., "regression", "verified", "needs-review")
- **Color-Coded**: Visual tag badges with customizable colors
- **Filter by Tag**: Quickly find reports with specific tags
- **Admin Managed**: Tag vocabulary controlled by administrators

## Installation

1. Upload the files to a PHP-enabled web server
2. Navigate to `install.php` in your browser
3. Configure database connection settings
4. Set up admin account credentials
5. (Optional) Configure GitHub integration for commit tracking
6. Begin testing!

### Requirements

- PHP 7.4 or higher
- MySQL/MariaDB database
- Web server (Apache, Nginx, etc.)
- cURL extension for PHP (for GitHub integration)

## Usage Overview

### For Testers

1. Log in to the web panel or Python tool using your API key
2. Select the Steam client version and emulator commit you're testing
3. Execute each test according to the test descriptions
4. Record the result (Working/Semi-working/Not working/N/A)
5. Add notes describing any issues or observations
6. Submit your report when complete

### For Developers

1. View the dashboard for an overview of current compatibility
2. Filter reports by version, commit, or test category
3. Review detailed test results and attached logs
4. Request retests when fixes are implemented
5. Track progress across different emulator builds

### For Administrators

1. Manage user accounts and permissions
2. Configure test categories and test types
3. Review and moderate submitted reports
4. Monitor overall testing progress

## Python Tool Keyboard Shortcuts

The desktop testing tool supports comprehensive keyboard shortcuts for efficient testing:

### Test Status (Tests Page)
| Shortcut | Action |
|----------|--------|
| `1` | Set status: Working (then advance to next test) |
| `2` | Set status: Semi-working (then advance) |
| `3` | Set status: Not working (then advance) |
| `4` | Set status: N/A (then advance) |
| `Ctrl+Down` | Next test |
| `Ctrl+Up` | Previous test |
| `Ctrl+Enter` | Next test (alternate) |
| `Ctrl+N` | Focus notes field |

### Navigation
| Shortcut | Action |
|----------|--------|
| `Ctrl+1` | Go to Intro page |
| `Ctrl+2` | Go to Versions page |
| `Ctrl+3` | Go to Tests page |
| `Escape` | Go back |
| `Alt+Up/Down` | Navigate versions list |

### Session & Reports
| Shortcut | Action |
|----------|--------|
| `Ctrl+S` | Save session |
| `Ctrl+R` | Reload session |
| `Ctrl+E` | Export HTML report |
| `Ctrl+U` | Upload to panel |
| `Ctrl+T` | Check retests |
| `Ctrl+F` | Finish current test |
| `Ctrl+L` | Attach log files |
| `Ctrl+Space` | Toggle stopwatch |
| `F5` | Refresh current view |
| `F1` | Show keyboard shortcuts help |

## API Documentation

The API uses API key authentication via the `X-API-Key` header.

### Key Endpoints

- `GET /api/user.php` - Get current user info and available revisions
- `POST /api/submit.php` - Submit a test report (includes regression detection)
- `GET /api/reports.php` - List reports with filtering
- `GET /api/tests.php` - Get available test definitions
- `GET /api/revisions.php` - Get GitHub commit history
- `GET /api/search.php` - Global search across all data
- `GET/POST /api/report_tags.php` - Manage report tags

## Credits

- **XJR9000** - Original creator of the STMServer testing tool
- Contributors to the expanded web panel and API system

## License

This project is part of the Steam emulator development effort. Please refer to the main project repository for licensing information.
