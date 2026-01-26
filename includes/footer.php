        </div>
    </main>

    <?php
    // GitHub Floating Download Box - only show if logged in, configured, and not on admin/profile pages
    if (isLoggedIn() && isGitHubConfigured() && shouldShowGitHubFloatingBox()):
        $latestRevision = getLatestGitHubRevisionInfo();
        if ($latestRevision):
    ?>
    <!-- GitHub Floating Download Box -->
    <div class="github-floating-box" id="github-floating-box">
        <div class="github-floating-box-title">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/>
            </svg>
            Latest Revision
        </div>
        <div class="github-floating-box-content">
            <a href="#" class="github-revision-link" title="Click for details">
                <span class="revision-sha"><?= e($latestRevision['short_sha']) ?></span>
                <span class="revision-info-icon">&#9432;</span>
            </a>
            <?php if (!empty($latestRevision['date'])): ?>
            <div class="github-revision-datetime"><?= e($latestRevision['date']) ?></div>
            <?php endif; ?>
            <a href="api/github_zip.php?action=download" class="github-download-link" title="Download latest source">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Download ZIP
            </a>
        </div>
    </div>

    <!-- GitHub Revision Detail Modal -->
    <div id="github-revision-modal" class="github-revision-modal">
        <div class="github-revision-modal-content">
            <div class="github-revision-modal-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="4"/>
                        <line x1="1.05" y1="12" x2="7" y2="12"/>
                        <line x1="17.01" y1="12" x2="22.96" y2="12"/>
                    </svg>
                    Commit <span class="revision-sha-badge"><?= e($latestRevision['short_sha']) ?></span>
                </h3>
                <button type="button" class="github-revision-modal-close" title="Close">&times;</button>
            </div>
            <div class="github-revision-modal-body">
                <div class="github-revision-section">
                    <div class="github-revision-section-title">Commit Message</div>
                    <div class="github-revision-message"><?= e($latestRevision['message']) ?></div>
                    <div class="github-revision-date"><?= $latestRevision['date'] ? 'Committed: ' . e($latestRevision['date']) : '' ?></div>
                </div>
                <div class="github-revision-section">
                    <div class="github-revision-section-title">Files Changed</div>
                    <div class="github-revision-files">
                        <?php
                        $files = $latestRevision['files'];
                        $hasFiles = !empty($files['added']) || !empty($files['removed']) || !empty($files['modified']);

                        if (!$hasFiles):
                        ?>
                            <div class="github-revision-no-files">No file details available for this commit</div>
                        <?php else: ?>
                            <?php if (!empty($files['added'])): ?>
                            <div class="github-revision-file-group">
                                <div class="github-revision-file-group-title added">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                                    Added (<?= count($files['added']) ?>)
                                </div>
                                <ul class="github-revision-file-list">
                                    <?php foreach ($files['added'] as $file): ?>
                                        <li><?= e($file) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($files['removed'])): ?>
                            <div class="github-revision-file-group">
                                <div class="github-revision-file-group-title removed">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/></svg>
                                    Removed (<?= count($files['removed']) ?>)
                                </div>
                                <ul class="github-revision-file-list">
                                    <?php foreach ($files['removed'] as $file): ?>
                                        <li><?= e($file) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($files['modified'])): ?>
                            <div class="github-revision-file-group">
                                <div class="github-revision-file-group-title modified">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                    Modified (<?= count($files['modified']) ?>)
                                </div>
                                <ul class="github-revision-file-list">
                                    <?php foreach ($files['modified'] as $file): ?>
                                        <li><?= e($file) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="github-revision-modal-footer">
                <a href="api/github_zip.php?action=download" class="btn github-download-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Download ZIP
                </a>
                <a href="https://github.com/<?= e($latestRevision['owner']) ?>/<?= e($latestRevision['repo']) ?>/commit/<?= e($latestRevision['sha']) ?>" target="_blank" class="btn btn-secondary">
                    View on GitHub
                </a>
            </div>
        </div>
    </div>
    <?php
        endif;
    endif;
    ?>

    <!-- Global Search Modal -->
    <div id="global-search-modal" class="search-modal-overlay" style="display: none;">
        <div class="search-modal">
            <div class="search-input-wrapper">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                </svg>
                <input type="text" id="global-search-input" placeholder="Search reports, tests, versions, testers..." autocomplete="off">
                <kbd>Esc</kbd>
            </div>
            <div class="search-results" id="search-results">
                <div class="search-hint">
                    <p>Type to search across:</p>
                    <ul>
                        <li><strong>Reports</strong> - by version, tester, commit hash</li>
                        <li><strong>Tests</strong> - by name or key</li>
                        <li><strong>Results</strong> - by status or notes</li>
                    </ul>
                    <p class="search-shortcuts">
                        <kbd>↑</kbd><kbd>↓</kbd> Navigate &nbsp;&nbsp;
                        <kbd>Enter</kbd> Open &nbsp;&nbsp;
                        <kbd>Esc</kbd> Close
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
    <script>
    // Global Search Functionality
    var searchModal = document.getElementById('global-search-modal');
    var searchInput = document.getElementById('global-search-input');
    var searchResults = document.getElementById('search-results');
    var searchTimeout = null;
    var selectedIndex = -1;

    function openGlobalSearch() {
        searchModal.style.display = 'flex';
        searchInput.value = '';
        searchInput.focus();
        showSearchHint();
        selectedIndex = -1;
    }

    function closeGlobalSearch() {
        searchModal.style.display = 'none';
        searchInput.value = '';
    }

    function showSearchHint() {
        searchResults.innerHTML = '<div class="search-hint">' +
            '<p>Type to search across:</p>' +
            '<ul>' +
            '<li><strong>Reports</strong> - by version, tester, commit hash</li>' +
            '<li><strong>Tests</strong> - by name or key</li>' +
            '<li><strong>Results</strong> - by status or notes</li>' +
            '</ul>' +
            '<p class="search-shortcuts">' +
            '<kbd>\u2191</kbd><kbd>\u2193</kbd> Navigate &nbsp;&nbsp;' +
            '<kbd>Enter</kbd> Open &nbsp;&nbsp;' +
            '<kbd>Esc</kbd> Close' +
            '</p>' +
            '</div>';
    }

    function performSearch(query) {
        if (query.length < 2) {
            showSearchHint();
            return;
        }

        searchResults.innerHTML = '<div class="search-loading">Searching...</div>';

        fetch('api/search.php?q=' + encodeURIComponent(query))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    searchResults.innerHTML = '<div class="search-error">' + (data.error || 'Search failed') + '</div>';
                    return;
                }

                if (data.results.length === 0) {
                    searchResults.innerHTML = '<div class="search-empty">No results found for "' + escapeHtml(query) + '"</div>';
                    return;
                }

                var html = '';
                var currentCategory = '';

                data.results.forEach(function(item, idx) {
                    if (item.category !== currentCategory) {
                        currentCategory = item.category;
                        html += '<div class="search-category">' + escapeHtml(currentCategory) + '</div>';
                    }
                    html += '<a href="' + item.url + '" class="search-result-item" data-index="' + idx + '">';
                    html += '<span class="result-type">' + escapeHtml(item.type) + '</span>';
                    html += '<span class="result-title">' + escapeHtml(item.title) + '</span>';
                    if (item.subtitle) {
                        html += '<span class="result-subtitle">' + escapeHtml(item.subtitle) + '</span>';
                    }
                    html += '</a>';
                });

                searchResults.innerHTML = html;
                selectedIndex = -1;
            })
            .catch(function(err) {
                searchResults.innerHTML = '<div class="search-error">Search error: ' + err.message + '</div>';
            });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function selectResult(delta) {
        var items = searchResults.querySelectorAll('.search-result-item');
        if (items.length === 0) return;

        if (selectedIndex >= 0 && selectedIndex < items.length) {
            items[selectedIndex].classList.remove('selected');
        }

        selectedIndex += delta;
        if (selectedIndex < 0) selectedIndex = items.length - 1;
        if (selectedIndex >= items.length) selectedIndex = 0;

        items[selectedIndex].classList.add('selected');
        items[selectedIndex].scrollIntoView({ block: 'nearest' });
    }

    function openSelectedResult() {
        var items = searchResults.querySelectorAll('.search-result-item');
        if (selectedIndex >= 0 && selectedIndex < items.length) {
            window.location.href = items[selectedIndex].href;
        }
    }

    // Event listeners
    searchInput?.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        var query = this.value.trim();
        searchTimeout = setTimeout(function() {
            performSearch(query);
        }, 200);
    });

    searchInput?.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectResult(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectResult(-1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedIndex >= 0) {
                openSelectedResult();
            }
        } else if (e.key === 'Escape') {
            closeGlobalSearch();
        }
    });

    searchModal?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeGlobalSearch();
        }
    });

    // Global keyboard shortcut
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            openGlobalSearch();
        }
        if (e.key === 'Escape' && searchModal?.style.display === 'flex') {
            closeGlobalSearch();
        }
    });
    </script>
</body>
</html>
