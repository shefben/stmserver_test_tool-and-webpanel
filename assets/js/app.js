/**
 * Steam Emulator Test Panel - JavaScript
 * OldSteam Theme Colors
 */

// Status colors matching OldSteam theme
const STATUS_COLORS = {
    'Working': '#7ea64b',      // FullGreen
    'Semi-working': '#c4b550', // Maize/Yellow
    'Not working': '#c45050',  // Red
    'N/A': '#808080'           // Gray
};

// Theme colors for charts
const THEME_COLORS = {
    text: '#a0aa95',           // Label color
    textLight: '#eff6ee',      // Off-white
    grid: 'rgba(160, 170, 149, 0.1)',
    background: '#4c5844',     // GreenBG
    primary: '#c4b550',        // Maize
    accent: '#7ea64b'          // FullGreen
};

// Wait for DOM and Chart.js to be ready
document.addEventListener('DOMContentLoaded', function() {
    // Configure Chart.js defaults for OldSteam theme
    if (typeof Chart !== 'undefined') {
        Chart.defaults.color = THEME_COLORS.text;
        Chart.defaults.borderColor = THEME_COLORS.grid;

        // Legend styling
        Chart.defaults.plugins.legend.labels.color = THEME_COLORS.text;
        Chart.defaults.plugins.legend.labels.font = { size: 12 };

        // Title styling
        Chart.defaults.plugins.title.color = THEME_COLORS.textLight;

        // Tooltip styling
        Chart.defaults.plugins.tooltip.backgroundColor = THEME_COLORS.background;
        Chart.defaults.plugins.tooltip.titleColor = THEME_COLORS.primary;
        Chart.defaults.plugins.tooltip.bodyColor = THEME_COLORS.textLight;
        Chart.defaults.plugins.tooltip.borderColor = THEME_COLORS.text;
        Chart.defaults.plugins.tooltip.borderWidth = 1;

        console.log('Chart.js configured with OldSteam theme');
    } else {
        console.warn('Chart.js not loaded');
    }

    // File drop zone handling
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const browseBtn = document.getElementById('browseBtn');
    const submitBtn = document.getElementById('submitBtn');
    const fileSelected = document.getElementById('fileSelected');
    const fileName = document.getElementById('fileName');
    const removeFile = document.getElementById('removeFile');
    const dropZoneContent = dropZone ? dropZone.querySelector('.drop-zone-content') : null;

    console.log('File upload elements:', { dropZone, fileInput, browseBtn, submitBtn, fileSelected, fileName, removeFile, dropZoneContent });

    if (dropZone && fileInput) {
        console.log('Setting up file upload handlers');

        // Browse button click - handle this first
        if (browseBtn) {
            browseBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Browse button clicked');
                fileInput.click();
            });
        }

        // Drop zone click (not on button) - exclude clicks on the button
        dropZone.addEventListener('click', function(e) {
            // Don't trigger if clicking the button or the file-selected area
            if (e.target.id === 'browseBtn' || e.target.closest('#browseBtn') ||
                e.target.id === 'removeFile' || e.target.closest('.file-selected')) {
                return;
            }
            console.log('Drop zone clicked, target:', e.target);
            fileInput.click();
        });

        // Drag and drop
        dropZone.addEventListener('dragenter', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            e.stopPropagation();
            // Only remove class if leaving the dropzone entirely
            if (!dropZone.contains(e.relatedTarget)) {
                dropZone.classList.remove('dragover');
            }
        });

        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('dragover');
            console.log('File dropped');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                // Check if it's a JSON file
                const file = files[0];
                console.log('Dropped file:', file.name, file.type);
                if (file.type === 'application/json' || file.name.endsWith('.json')) {
                    // Create a new DataTransfer to set files on input
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    fileInput.files = dataTransfer.files;
                    handleFileSelect(file);
                } else {
                    showNotification('Please select a JSON file', 'error');
                }
            }
        });

        // File input change
        fileInput.addEventListener('change', function() {
            console.log('File input changed, files:', fileInput.files.length);
            if (fileInput.files.length > 0) {
                handleFileSelect(fileInput.files[0]);
            }
        });

        // Remove file button
        if (removeFile) {
            removeFile.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Remove file clicked');
                fileInput.value = '';
                if (dropZoneContent) dropZoneContent.style.display = '';
                if (fileSelected) fileSelected.style.display = 'none';
                if (submitBtn) submitBtn.disabled = true;
            });
        }

        function handleFileSelect(file) {
            console.log('File selected:', file.name);
            if (dropZoneContent) dropZoneContent.style.display = 'none';
            if (fileSelected) fileSelected.style.display = 'flex';
            if (fileName) fileName.textContent = file.name;
            if (submitBtn) submitBtn.disabled = false;
        }
    } else {
        console.log('File upload elements not found on this page');
    }

    // Matrix cell tooltips
    const matrixCells = document.querySelectorAll('.matrix-cell');
    matrixCells.forEach(cell => {
        cell.addEventListener('click', function() {
            const tooltip = this.getAttribute('data-tooltip');
            if (tooltip) {
                alert(tooltip);
            }
        });
    });

    // Expandable notes - show in rich modal instead of alert
    // Note: For test rows, clicking anywhere on the row opens the test detail modal
    // Notes cells in non-test-row tables still get their own click handler
    const noteCells = document.querySelectorAll('.notes-cell');
    noteCells.forEach(cell => {
        // Skip if this cell is inside a test-row (test detail modal handles it)
        if (cell.closest('.test-row')) {
            return;
        }

        // Skip if this cell has the has-notes class (results page has its own detail modal)
        if (cell.classList.contains('has-notes')) {
            return;
        }

        if (cell.scrollWidth > cell.clientWidth || cell.getAttribute('data-full')) {
            cell.style.cursor = 'pointer';
            cell.title = 'Click to view full notes';
            cell.addEventListener('click', function(e) {
                // Don't trigger if clicking a link inside the cell
                if (e.target.tagName === 'A' || e.target.tagName === 'IMG') return;

                const fullNotes = this.getAttribute('data-full');
                if (fullNotes) {
                    RichNotesRenderer.openNotesModal(fullNotes);
                }
            });
        }
    });
});

// Create status distribution pie chart
function createStatusPieChart(canvasId, data, clickable = false) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') {
        console.warn('Cannot create chart: canvas or Chart.js not available');
        return null;
    }

    const chartConfig = {
        type: 'doughnut',
        data: {
            labels: Object.keys(data),
            datasets: [{
                data: Object.values(data),
                backgroundColor: Object.keys(data).map(k => STATUS_COLORS[k] || '#808080'),
                borderWidth: 2,
                borderColor: '#282e22'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        usePointStyle: true,
                        color: THEME_COLORS.text
                    }
                }
            },
            cutout: '60%'
        }
    };

    if (clickable) {
        chartConfig.options.onClick = function(event, elements) {
            if (elements.length > 0) {
                const index = elements[0].index;
                const status = Object.keys(data)[index];
                window.location.href = '?page=results&status=' + encodeURIComponent(status);
            }
        };
    }

    return new Chart(ctx, chartConfig);
}

// Create version trend line chart
function createVersionTrendChart(canvasId, labels, datasets, clickable = false) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') {
        console.warn('Cannot create chart: canvas or Chart.js not available');
        return null;
    }

    const chartConfig = {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets.map((ds) => ({
                label: ds.label,
                data: ds.data,
                borderColor: STATUS_COLORS[ds.label] || THEME_COLORS.primary,
                backgroundColor: 'transparent',
                tension: 0.4,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointBackgroundColor: STATUS_COLORS[ds.label] || THEME_COLORS.primary,
                pointBorderColor: '#282e22',
                pointBorderWidth: 2
            }))
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: THEME_COLORS.text
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: THEME_COLORS.grid
                    },
                    ticks: {
                        color: THEME_COLORS.text
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: THEME_COLORS.text
                    }
                }
            }
        }
    };

    if (clickable) {
        chartConfig.options.onClick = function(event, elements) {
            if (elements.length > 0) {
                const index = elements[0].index;
                const version = labels[index];
                window.location.href = '?page=results&version=' + encodeURIComponent(version);
            }
        };
    }

    return new Chart(ctx, chartConfig);
}

// Create problematic tests bar chart
function createProblematicTestsChart(canvasId, tests, clickable = false) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') {
        console.warn('Cannot create chart: canvas or Chart.js not available');
        return null;
    }

    const chartConfig = {
        type: 'bar',
        data: {
            labels: tests.map(t => t.key),
            datasets: [{
                label: 'Failure Rate %',
                data: tests.map(t => t.failRate),
                backgroundColor: STATUS_COLORS['Not working'],
                borderRadius: 4,
                borderWidth: 1,
                borderColor: '#282e22'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        title: function(items) {
                            const idx = items[0].dataIndex;
                            return tests[idx].name;
                        }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    max: 100,
                    grid: {
                        color: THEME_COLORS.grid
                    },
                    ticks: {
                        color: THEME_COLORS.text
                    }
                },
                y: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: THEME_COLORS.text
                    }
                }
            }
        }
    };

    if (clickable) {
        chartConfig.options.onClick = function(event, elements) {
            if (elements.length > 0) {
                const index = elements[0].index;
                const testKey = tests[index].key;
                window.location.href = '?page=results&test_key=' + encodeURIComponent(testKey) + '&status=Not+working';
            }
        };
    }

    return new Chart(ctx, chartConfig);
}

// Create test category chart
function createTestCategoryChart(canvasId, data) {
    const ctx = document.getElementById(canvasId);
    if (!ctx || typeof Chart === 'undefined') {
        console.warn('Cannot create chart: canvas or Chart.js not available');
        return null;
    }

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => d.key),
            datasets: [
                {
                    label: 'Working',
                    data: data.map(d => d.working),
                    backgroundColor: STATUS_COLORS['Working'],
                    borderWidth: 1,
                    borderColor: '#282e22'
                },
                {
                    label: 'Semi-working',
                    data: data.map(d => d.semi),
                    backgroundColor: STATUS_COLORS['Semi-working'],
                    borderWidth: 1,
                    borderColor: '#282e22'
                },
                {
                    label: 'Not working',
                    data: data.map(d => d.broken),
                    backgroundColor: STATUS_COLORS['Not working'],
                    borderWidth: 1,
                    borderColor: '#282e22'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: THEME_COLORS.text
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: THEME_COLORS.text
                    }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    grid: {
                        color: THEME_COLORS.grid
                    },
                    ticks: {
                        color: THEME_COLORS.text
                    }
                }
            }
        }
    });
}

// Utility: format date
function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

// Copy to clipboard helper
function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            showNotification('Copied to clipboard!', 'success');
        }).catch(err => {
            console.error('Failed to copy:', err);
            fallbackCopyToClipboard(text);
        });
    } else {
        fallbackCopyToClipboard(text);
    }
}

function fallbackCopyToClipboard(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showNotification('Copied to clipboard!', 'success');
    } catch (err) {
        showNotification('Failed to copy', 'error');
    }
    document.body.removeChild(textarea);
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `flash-message ${type}`;
    notification.textContent = message;
    notification.style.position = 'fixed';
    notification.style.top = '20px';
    notification.style.right = '20px';
    notification.style.zIndex = '9999';
    notification.style.animation = 'fadeIn 0.3s ease';
    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Add CSS for notifications and sortable tables
const notificationStyles = document.createElement('style');
notificationStyles.textContent = `
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeOut {
        from { opacity: 1; transform: translateY(0); }
        to { opacity: 0; transform: translateY(-10px); }
    }

    /* Sortable table headers - icons inline next to text */
    table.sortable th {
        cursor: pointer;
        user-select: none;
        white-space: nowrap;
    }
    table.sortable th:hover {
        background: var(--bg-accent, #5a6a50);
    }
    table.sortable th .sort-icons {
        display: inline-flex;
        flex-direction: column;
        vertical-align: middle;
        margin-left: 4px;
        line-height: 0;
    }
    table.sortable th .sort-icon-up,
    table.sortable th .sort-icon-down {
        width: 0;
        height: 0;
        border-left: 4px solid transparent;
        border-right: 4px solid transparent;
        opacity: 0.3;
    }
    table.sortable th .sort-icon-up {
        border-bottom: 5px solid currentColor;
        margin-bottom: 1px;
    }
    table.sortable th .sort-icon-down {
        border-top: 5px solid currentColor;
        margin-top: 1px;
    }
    table.sortable th.sort-asc .sort-icon-up {
        opacity: 1;
        border-bottom-color: var(--primary, #c4b550);
    }
    table.sortable th.sort-desc .sort-icon-down {
        opacity: 1;
        border-top-color: var(--primary, #c4b550);
    }
    table.sortable th.no-sort {
        cursor: default;
    }
    table.sortable th.no-sort .sort-icons {
        display: none;
    }
    table.sortable th.no-sort:hover {
        background: inherit;
    }
`;
document.head.appendChild(notificationStyles);

/**
 * Sortable Tables
 * Automatically makes tables with class "sortable" clickable for sorting
 * Click cycle: ascending -> descending -> original -> ascending...
 */
class SortableTable {
    constructor(table) {
        this.table = table;
        this.thead = table.querySelector('thead');
        this.tbody = table.querySelector('tbody');

        if (!this.thead || !this.tbody) return;

        this.headers = Array.from(this.thead.querySelectorAll('th'));
        this.originalRows = Array.from(this.tbody.querySelectorAll('tr'));
        this.currentSort = { column: -1, direction: 'none' }; // none, asc, desc

        this.init();
    }

    init() {
        this.headers.forEach((header, index) => {
            // Skip headers marked as no-sort
            if (header.classList.contains('no-sort')) return;

            // Add sort icons inline next to header text
            this.addSortIcons(header);

            header.addEventListener('click', () => this.sortByColumn(index));
        });
    }

    addSortIcons(header) {
        // Create sort icons container
        const icons = document.createElement('span');
        icons.className = 'sort-icons';
        icons.innerHTML = '<span class="sort-icon-up"></span><span class="sort-icon-down"></span>';
        header.appendChild(icons);
    }

    sortByColumn(columnIndex) {
        const header = this.headers[columnIndex];

        // Determine next sort direction
        let newDirection;
        if (this.currentSort.column !== columnIndex) {
            // New column - start with ascending
            newDirection = 'asc';
        } else {
            // Same column - cycle through: asc -> desc -> none -> asc
            switch (this.currentSort.direction) {
                case 'asc': newDirection = 'desc'; break;
                case 'desc': newDirection = 'none'; break;
                default: newDirection = 'asc';
            }
        }

        // Remove sort classes from all headers
        this.headers.forEach(h => {
            h.classList.remove('sort-asc', 'sort-desc');
        });

        // Apply sort
        if (newDirection === 'none') {
            // Restore original order
            this.originalRows.forEach(row => this.tbody.appendChild(row));
            this.currentSort = { column: -1, direction: 'none' };
        } else {
            // Sort rows
            const rows = Array.from(this.tbody.querySelectorAll('tr'));
            const sortedRows = this.sortRows(rows, columnIndex, newDirection);

            // Clear and re-append sorted rows
            sortedRows.forEach(row => this.tbody.appendChild(row));

            // Update header class
            header.classList.add(newDirection === 'asc' ? 'sort-asc' : 'sort-desc');
            this.currentSort = { column: columnIndex, direction: newDirection };
        }
    }

    sortRows(rows, columnIndex, direction) {
        return rows.sort((a, b) => {
            const cellA = a.cells[columnIndex];
            const cellB = b.cells[columnIndex];

            if (!cellA || !cellB) return 0;

            // Get sort value - check for data-sort attribute first
            let valueA = cellA.getAttribute('data-sort') || cellA.textContent.trim();
            let valueB = cellB.getAttribute('data-sort') || cellB.textContent.trim();

            // Try to parse as numbers
            const numA = this.parseNumber(valueA);
            const numB = this.parseNumber(valueB);

            let comparison;
            if (numA !== null && numB !== null) {
                // Numeric comparison
                comparison = numA - numB;
            } else {
                // String comparison (case-insensitive)
                comparison = valueA.toLowerCase().localeCompare(valueB.toLowerCase());
            }

            return direction === 'asc' ? comparison : -comparison;
        });
    }

    parseNumber(value) {
        // Remove common formatting (commas, %, $, etc.)
        const cleaned = value.replace(/[,$%]/g, '').trim();
        const num = parseFloat(cleaned);
        return isNaN(num) ? null : num;
    }
}

// Initialize sortable tables on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    const sortableTables = document.querySelectorAll('table.sortable');
    sortableTables.forEach(table => new SortableTable(table));
});

/**
 * Rich Notes Renderer
 * Parses and renders notes containing:
 * - Code blocks (```language\n...\n```)
 * - Inline code (`code`)
 * - Images (markdown ![alt](url) or data: URIs)
 * - URLs (auto-linked)
 * - Line breaks
 */
const RichNotesRenderer = {
    /**
     * Render rich notes content
     * @param {string} content - Raw notes content
     * @returns {string} - HTML string
     */
    render: function(content) {
        if (!content || content === '-') return content;

        // Convert HTML code blocks to markdown BEFORE escaping
        // This handles legacy data that may have <pre><code> blocks
        content = this.convertHtmlCodeBlocksToMarkdown(content);

        // Escape HTML first to prevent XSS
        let html = this.escapeHtml(content);

        // Process BBCode tags (must be before markdown processing)
        html = this.renderBBCode(html);

        // Process code blocks first (```language\n...\n```)
        html = this.renderCodeBlocks(html);

        // Process inline code (`code`)
        html = this.renderInlineCode(html);

        // Process images (markdown style and data URIs)
        html = this.renderImages(html);

        // Auto-link URLs (but not inside code blocks or images)
        html = this.renderLinks(html);

        // Convert newlines to <br> (but not inside <pre> blocks)
        html = this.renderLineBreaks(html);

        return html;
    },

    /**
     * Convert HTML <pre><code> blocks to markdown ``` format
     * This handles legacy data before escaping
     */
    convertHtmlCodeBlocksToMarkdown: function(text) {
        // Match <pre...><code>...</code></pre> patterns
        return text.replace(/<pre[^>]*>\s*<code[^>]*>([\s\S]*?)<\/code>\s*<\/pre>/gi, function(match, code) {
            // Decode HTML entities in the code
            var temp = document.createElement('div');
            temp.innerHTML = code;
            var decoded = temp.textContent || temp.innerText || '';
            return '```\n' + decoded + '\n```';
        });
    },

    /**
     * Render BBCode tags
     * Supports: [b], [i], [u], [code], [url], [img]
     */
    renderBBCode: function(html) {
        // Protect existing HTML tags from being replaced
        const protectedBlocks = [];
        let protectIndex = 0;

        // [code] blocks - convert to markdown code blocks for later processing
        html = html.replace(/\[code\]([\s\S]*?)\[\/code\]/gi, function(match, code) {
            return '```\n' + code + '\n```';
        });

        // [b] - bold
        html = html.replace(/\[b\]([\s\S]*?)\[\/b\]/gi, '<strong>$1</strong>');

        // [i] - italic
        html = html.replace(/\[i\]([\s\S]*?)\[\/i\]/gi, '<em>$1</em>');

        // [u] - underline
        html = html.replace(/\[u\]([\s\S]*?)\[\/u\]/gi, '<u>$1</u>');

        // [url=...] - link with text
        html = html.replace(/\[url=([^\]]+)\]([\s\S]*?)\[\/url\]/gi, function(match, url, text) {
            // Unescape the URL (it was escaped earlier)
            url = url.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"');
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + text + '</a>';
        });

        // [url] - simple URL
        html = html.replace(/\[url\]([\s\S]*?)\[\/url\]/gi, function(match, url) {
            url = url.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"');
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
        });

        // [img] - image
        html = html.replace(/\[img\]([\s\S]*?)\[\/img\]/gi, function(match, url) {
            url = url.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"');
            return '<img src="' + url + '" alt="Image" class="note-image" style="max-width: 100%; height: auto; border-radius: 4px;" />';
        });

        return html;
    },

    /**
     * Escape HTML entities
     */
    escapeHtml: function(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    /**
     * Render fenced code blocks
     * Supports: ```language\ncode\n```, ```\ncode\n```, or even ```code```
     * Handles both \n and \r\n line endings
     */
    renderCodeBlocks: function(html) {
        // Match ```language\ncode``` or ```code``` patterns
        // Language is optional, newline after language is optional, handles \r\n
        const codeBlockRegex = /```(\w*)[\r\n]*([\s\S]*?)```/g;

        return html.replace(codeBlockRegex, function(match, lang, code) {
            // Skip if the content is empty or just whitespace
            if (!code.trim()) {
                return match;
            }
            const langClass = lang ? ' data-language="' + lang + '"' : '';
            const langLabel = lang ? '<span class="code-lang">' + lang + '</span>' : '';
            // Trim leading/trailing whitespace from code
            code = code.replace(/^[\r\n]+/, '').replace(/[\r\n]+$/, '');
            return '<div class="code-block-wrapper">' + langLabel +
                   '<pre class="code-block"' + langClass + '><code>' + code + '</code></pre>' +
                   '<button type="button" class="code-copy-btn" onclick="RichNotesRenderer.copyCode(this)" title="Copy code">📋</button></div>';
        });
    },

    /**
     * Render inline code
     * Supports: `code`
     */
    renderInlineCode: function(html) {
        // Match `code` pattern, but not inside code blocks
        return html.replace(/`([^`\n]+)`/g, '<code class="inline-code">$1</code>');
    },

    /**
     * Render images
     * Supports:
     * - Markdown: ![alt](url)
     * - Data URI: [image:data:image/png;base64,...]
     * - Plain data URI: data:image/...
     */
    renderImages: function(html) {
        // Markdown image syntax: ![alt](url)
        html = html.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, function(match, alt, url) {
            return '<div class="note-image-wrapper"><img src="' + url + '" alt="' + alt + '" class="note-image" onclick="RichNotesRenderer.openImageModal(this)" /></div>';
        });

        // Data URI pattern: [image:data:image/...]
        html = html.replace(/\[image:(data:image\/[^\]]+)\]/g, function(match, dataUri) {
            return '<div class="note-image-wrapper"><img src="' + dataUri + '" alt="Embedded image" class="note-image" onclick="RichNotesRenderer.openImageModal(this)" /></div>';
        });

        // Plain base64 image block marker: {{IMAGE:data:image/...}}
        html = html.replace(/\{\{IMAGE:(data:image\/[^}]+)\}\}/g, function(match, dataUri) {
            return '<div class="note-image-wrapper"><img src="' + dataUri + '" alt="Embedded image" class="note-image" onclick="RichNotesRenderer.openImageModal(this)" /></div>';
        });

        return html;
    },

    /**
     * Auto-link URLs
     */
    renderLinks: function(html) {
        // Match URLs not already in HTML tags or code blocks
        // Simple URL pattern
        const urlRegex = /(?<!["'=])(https?:\/\/[^\s<>\[\]]+)/g;

        return html.replace(urlRegex, '<a href="$1" target="_blank" rel="noopener noreferrer" class="note-link">$1</a>');
    },

    /**
     * Convert newlines to <br> except inside pre blocks
     */
    renderLineBreaks: function(html) {
        // Split by pre blocks, process non-pre parts, rejoin
        const parts = html.split(/(<pre[\s\S]*?<\/pre>)/g);

        return parts.map(function(part) {
            if (part.startsWith('<pre')) {
                return part;
            }
            return part.replace(/\n/g, '<br>');
        }).join('');
    },

    /**
     * Copy code to clipboard
     */
    copyCode: function(btn) {
        const wrapper = btn.closest('.code-block-wrapper');
        const code = wrapper.querySelector('code').textContent;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(function() {
                btn.textContent = '✓';
                btn.classList.add('copied');
                setTimeout(function() {
                    btn.textContent = '📋';
                    btn.classList.remove('copied');
                }, 2000);
            });
        } else {
            // Fallback
            const textarea = document.createElement('textarea');
            textarea.value = code;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand('copy');
                btn.textContent = '✓';
                btn.classList.add('copied');
                setTimeout(function() {
                    btn.textContent = '📋';
                    btn.classList.remove('copied');
                }, 2000);
            } catch (err) {
                console.error('Failed to copy code');
            }
            document.body.removeChild(textarea);
        }
    },

    /**
     * Open image in modal for full view
     */
    openImageModal: function(img) {
        // Create modal overlay
        const existingModal = document.getElementById('note-image-modal');
        if (existingModal) {
            existingModal.remove();
        }

        const modal = document.createElement('div');
        modal.id = 'note-image-modal';
        modal.className = 'note-image-modal';
        modal.innerHTML =
            '<div class="note-image-modal-content">' +
                '<button type="button" class="note-image-modal-close" onclick="this.closest(\'.note-image-modal\').remove()">&times;</button>' +
                '<img src="' + img.src + '" alt="' + (img.alt || 'Image') + '" />' +
            '</div>';

        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.remove();
            }
        });

        document.body.appendChild(modal);

        // Close on Escape
        const closeOnEscape = function(e) {
            if (e.key === 'Escape') {
                modal.remove();
                document.removeEventListener('keydown', closeOnEscape);
            }
        };
        document.addEventListener('keydown', closeOnEscape);
    },

    /**
     * Open notes content in a modal with rich rendering
     * Replaces the basic alert() for viewing full notes
     */
    openNotesModal: function(content) {
        // Remove any existing notes modal
        const existingModal = document.getElementById('notes-view-modal');
        if (existingModal) {
            existingModal.remove();
        }

        // Create modal overlay
        const modal = document.createElement('div');
        modal.id = 'notes-view-modal';
        modal.className = 'notes-view-modal';

        // Render the content with RichNotesRenderer
        const renderedContent = this.render(content);

        modal.innerHTML =
            '<div class="notes-view-modal-content">' +
                '<div class="notes-view-modal-header">' +
                    '<h3>Notes</h3>' +
                    '<button type="button" class="notes-view-modal-close" onclick="this.closest(\'.notes-view-modal\').remove()" title="Close">&times;</button>' +
                '</div>' +
                '<div class="notes-view-modal-body rich-notes-rendered">' +
                    renderedContent +
                '</div>' +
            '</div>';

        // Close on backdrop click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.remove();
            }
        });

        document.body.appendChild(modal);

        // Close on Escape
        const closeOnEscape = function(e) {
            if (e.key === 'Escape') {
                modal.remove();
                document.removeEventListener('keydown', closeOnEscape);
            }
        };
        document.addEventListener('keydown', closeOnEscape);
    },

    /**
     * Initialize rich notes rendering on page
     * Call this after DOM is ready
     */
    init: function(selector) {
        selector = selector || '.notes-cell, .rich-notes';
        const elements = document.querySelectorAll(selector);

        elements.forEach(function(el) {
            // Skip if already rendered
            if (el.classList.contains('rich-notes-rendered')) return;

            // Get the raw content
            const rawContent = el.getAttribute('data-full') || el.textContent;

            // Only render if content has rich formatting markers
            if (RichNotesRenderer.hasRichContent(rawContent)) {
                el.innerHTML = RichNotesRenderer.render(rawContent);
                el.classList.add('rich-notes-rendered');
            }
        });
    },

    /**
     * Check if content has rich formatting
     */
    hasRichContent: function(content) {
        if (!content) return false;

        // Check for code blocks
        if (/```[\s\S]*```/.test(content)) return true;

        // Check for inline code
        if (/`[^`]+`/.test(content)) return true;

        // Check for markdown images
        if (/!\[[^\]]*\]\([^)]+\)/.test(content)) return true;

        // Check for embedded image markers
        if (/\[image:data:image\//.test(content)) return true;
        if (/\{\{IMAGE:data:image\//.test(content)) return true;

        // Check for URLs
        if (/https?:\/\/[^\s]+/.test(content)) return true;

        return false;
    },

    /**
     * Open test detail modal showing full test information
     * @param {Object} testData - Object with test details
     */
    openTestDetailModal: function(testData) {
        // Remove any existing test detail modal
        const existingModal = document.getElementById('test-detail-modal');
        if (existingModal) {
            existingModal.remove();
        }

        // Render the notes content
        const notesContent = testData.notes || '';
        const renderedNotes = notesContent ? this.render(notesContent) : '<span class="empty-notes">No notes</span>';

        // Create status badge HTML
        const statusBadgeHtml = testData.statusBadge || this.createStatusBadge(testData.status);

        // Create modal
        const modal = document.createElement('div');
        modal.id = 'test-detail-modal';
        modal.className = 'test-detail-modal';

        modal.innerHTML =
            '<div class="test-detail-modal-content">' +
                '<div class="test-detail-modal-header">' +
                    '<h3>' +
                        '<span class="test-key-badge">' + this.escapeHtml(testData.testKey) + '</span>' +
                        '<span>' + this.escapeHtml(testData.testName) + '</span>' +
                    '</h3>' +
                    '<button type="button" class="test-detail-modal-close" title="Close">&times;</button>' +
                '</div>' +
                '<div class="test-detail-modal-body">' +
                    '<div class="test-detail-section">' +
                        '<div class="test-detail-section-title">Expected Result</div>' +
                        '<div class="test-detail-expected">' + this.escapeHtml(testData.expected || '-') + '</div>' +
                    '</div>' +
                    '<div class="test-detail-section">' +
                        '<div class="test-detail-section-title">Status</div>' +
                        '<div class="test-detail-status">' + statusBadgeHtml + '</div>' +
                    '</div>' +
                    '<div class="test-detail-section">' +
                        '<div class="test-detail-section-title">Notes</div>' +
                        '<div class="test-detail-notes rich-notes-rendered' + (notesContent ? '' : ' empty-notes') + '">' +
                            renderedNotes +
                        '</div>' +
                    '</div>' +
                '</div>' +
            '</div>';

        // Close button handler
        modal.querySelector('.test-detail-modal-close').addEventListener('click', function() {
            modal.remove();
        });

        // Close on backdrop click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.remove();
            }
        });

        document.body.appendChild(modal);

        // Close on Escape
        const closeOnEscape = function(e) {
            if (e.key === 'Escape') {
                modal.remove();
                document.removeEventListener('keydown', closeOnEscape);
            }
        };
        document.addEventListener('keydown', closeOnEscape);
    },

    /**
     * Create a status badge HTML string
     */
    createStatusBadge: function(status) {
        const statusClasses = {
            'Working': 'status-working',
            'Semi-working': 'status-semi',
            'Not working': 'status-not-working',
            'N/A': 'status-na'
        };
        const className = statusClasses[status] || 'status-na';
        return '<span class="status-badge ' + className + '">' + this.escapeHtml(status || 'N/A') + '</span>';
    },

    /**
     * Initialize clickable test rows
     */
    initTestRowClicks: function() {
        const testRows = document.querySelectorAll('.test-row');
        const self = this;

        testRows.forEach(function(row) {
            row.addEventListener('click', function(e) {
                // Don't trigger if clicking a link, button, or interactive element
                const target = e.target;
                if (target.closest('a') || target.closest('button') || target.tagName === 'A' || target.tagName === 'BUTTON') {
                    return;
                }

                // Extract test data from the row
                const cells = row.querySelectorAll('td');
                if (cells.length < 4) return;

                // Get test key from first cell
                const testKeyLink = cells[0].querySelector('.test-key-link');
                const testKey = testKeyLink ? testKeyLink.textContent.trim() : cells[0].textContent.trim();

                // Get test name and expected from second cell
                const testNameDiv = cells[1].querySelector('div:first-child');
                const expectedDiv = cells[1].querySelector('div:nth-child(2)');
                const testName = testNameDiv ? testNameDiv.textContent.trim() : '';
                const expected = expectedDiv ? expectedDiv.textContent.trim() : '';

                // Get status from third cell
                const statusBadge = cells[2].querySelector('.status-badge');
                const status = statusBadge ? statusBadge.textContent.trim() : 'N/A';
                const statusBadgeHtml = statusBadge ? statusBadge.outerHTML : '';

                // Get notes from fourth cell
                const notesCell = cells[3];
                const notes = notesCell.getAttribute('data-full') || '';

                // Open the modal
                self.openTestDetailModal({
                    testKey: testKey,
                    testName: testName,
                    expected: expected,
                    status: status,
                    statusBadge: statusBadgeHtml,
                    notes: notes
                });
            });
        });
    }
};

// Auto-initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Delay slightly to ensure all elements are ready
    setTimeout(function() {
        RichNotesRenderer.init();
        RichNotesRenderer.initTestRowClicks();
    }, 100);
});

/**
 * GitHub Floating Box and Revision Modal
 * Displays latest revision with download link
 */
var GitHubFloatingBox = {
    modal: null,
    currentRevision: null,

    /**
     * Initialize the floating box modal functionality
     */
    init: function() {
        var self = this;

        // Get the modal element
        this.modal = document.getElementById('github-revision-modal');
        if (!this.modal) return;

        // Close button
        var closeBtn = this.modal.querySelector('.github-revision-modal-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                self.closeModal();
            });
        }

        // Close on backdrop click
        this.modal.addEventListener('click', function(e) {
            if (e.target === self.modal) {
                self.closeModal();
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && self.modal.classList.contains('active')) {
                self.closeModal();
            }
        });

        // Setup revision link click handler
        var revisionLink = document.querySelector('.github-revision-link');
        if (revisionLink) {
            revisionLink.addEventListener('click', function(e) {
                e.preventDefault();
                self.fetchAndShowRevisionDetails();
            });
        }
    },

    /**
     * Fetch revision details from API and show modal
     */
    fetchAndShowRevisionDetails: function() {
        var self = this;

        // Show loading state
        this.showModal({
            sha: '...',
            message: 'Loading...',
            date: '',
            files: { added: [], removed: [], modified: [] }
        });

        // Fetch from API
        fetch('api/github_zip.php?action=info')
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                if (data.success && data.data && data.data.latest_revision) {
                    var rev = data.data.latest_revision;
                    self.showModal({
                        sha: rev.sha,
                        short_sha: rev.short_sha,
                        message: rev.message || 'No commit message',
                        date: rev.date || '',
                        files: rev.files || { added: [], removed: [], modified: [] },
                        download_url: data.data.download_url
                    });
                } else {
                    self.showModal({
                        sha: 'Error',
                        message: data.error || 'Failed to load revision details',
                        date: '',
                        files: { added: [], removed: [], modified: [] }
                    });
                }
            })
            .catch(function(error) {
                self.showModal({
                    sha: 'Error',
                    message: 'Failed to fetch revision: ' + error.message,
                    date: '',
                    files: { added: [], removed: [], modified: [] }
                });
            });
    },

    /**
     * Show the revision detail modal
     */
    showModal: function(revision) {
        if (!this.modal) return;

        this.currentRevision = revision;

        // Update SHA badge
        var shaBadge = this.modal.querySelector('.revision-sha-badge');
        if (shaBadge) {
            shaBadge.textContent = revision.short_sha || revision.sha.substring(0, 8);
        }

        // Update message
        var messageEl = this.modal.querySelector('.github-revision-message');
        if (messageEl) {
            messageEl.textContent = revision.message;
        }

        // Update date
        var dateEl = this.modal.querySelector('.github-revision-date');
        if (dateEl) {
            dateEl.textContent = revision.date ? 'Committed: ' + revision.date : '';
            dateEl.style.display = revision.date ? 'block' : 'none';
        }

        // Update files section
        this.updateFilesSection(revision.files);

        // Update download button if present
        var downloadBtn = this.modal.querySelector('.github-download-btn');
        if (downloadBtn && revision.download_url) {
            downloadBtn.href = revision.download_url;
        }

        // Show modal
        this.modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    },

    /**
     * Update the files changed section
     */
    updateFilesSection: function(files) {
        var self = this;
        var filesContainer = this.modal.querySelector('.github-revision-files');
        if (!filesContainer) return;

        var hasFiles = (files.added && files.added.length > 0) ||
                       (files.removed && files.removed.length > 0) ||
                       (files.modified && files.modified.length > 0);

        if (!hasFiles) {
            filesContainer.innerHTML = '<div class="github-revision-no-files">No file details available for this commit</div>';
            return;
        }

        var html = '';

        // Added files
        if (files.added && files.added.length > 0) {
            html += '<div class="github-revision-file-group">';
            html += '<div class="github-revision-file-group-title added">';
            html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>';
            html += ' Added (' + files.added.length + ')';
            html += '</div>';
            html += '<ul class="github-revision-file-list">';
            files.added.forEach(function(file) {
                html += '<li>' + self.escapeHtml(file) + '</li>';
            });
            html += '</ul></div>';
        }

        // Removed files
        if (files.removed && files.removed.length > 0) {
            html += '<div class="github-revision-file-group">';
            html += '<div class="github-revision-file-group-title removed">';
            html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>';
            html += ' Removed (' + files.removed.length + ')';
            html += '</div>';
            html += '<ul class="github-revision-file-list">';
            files.removed.forEach(function(file) {
                html += '<li>' + self.escapeHtml(file) + '</li>';
            });
            html += '</ul></div>';
        }

        // Modified files
        if (files.modified && files.modified.length > 0) {
            html += '<div class="github-revision-file-group">';
            html += '<div class="github-revision-file-group-title modified">';
            html += '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>';
            html += ' Modified (' + files.modified.length + ')';
            html += '</div>';
            html += '<ul class="github-revision-file-list">';
            files.modified.forEach(function(file) {
                html += '<li>' + self.escapeHtml(file) + '</li>';
            });
            html += '</ul></div>';
        }

        filesContainer.innerHTML = html;
    },

    /**
     * Close the modal
     */
    closeModal: function() {
        if (!this.modal) return;
        this.modal.classList.remove('active');
        document.body.style.overflow = '';
    },

    /**
     * Escape HTML entities
     */
    escapeHtml: function(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    GitHubFloatingBox.init();
});
