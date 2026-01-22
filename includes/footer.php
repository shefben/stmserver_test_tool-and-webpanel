        </div>
    </main>

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
