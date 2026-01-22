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

    // Expandable notes
    const noteCells = document.querySelectorAll('.notes-cell');
    noteCells.forEach(cell => {
        if (cell.scrollWidth > cell.clientWidth) {
            cell.style.cursor = 'pointer';
            cell.title = 'Click to expand';
            cell.addEventListener('click', function() {
                const fullNotes = this.getAttribute('data-full');
                if (fullNotes) {
                    alert(fullNotes);
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
