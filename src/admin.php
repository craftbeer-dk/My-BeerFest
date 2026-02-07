<?php
ob_start();
session_start();

$appLanguage = getenv('APP_LANGUAGE') ?: 'da';
$langFile = __DIR__ . "/lang/{$appLanguage}.conf";
$translations = [];
if (file_exists($langFile)) {
    $translations = parse_ini_file($langFile);
} else {
    $appLanguage = 'en';
    $langFile = __DIR__ . "/lang/{$appLanguage}.conf";
    if (file_exists($langFile)) {
        $translations = parse_ini_file($langFile);
    }
}

function t($key, $default = '') {
    global $translations;
    return htmlspecialchars($translations[$key] ?? $default);
}

$festivalTitle = getenv('FESTIVAL_TITLE') ?: (t('default_festival_title', 'My Beerfest'));
$themeColor = getenv('THEME_COLOR') ?: '#2B684B';

// Load current beer data
$beersDataPath = '/var/www/html/data/beers.json';
$beers = [];
if (file_exists($beersDataPath)) {
    $beers = json_decode(file_get_contents($beersDataPath), true) ?: [];
}
$beersJson = json_encode($beers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($appLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?php echo htmlspecialchars($festivalTitle); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="config/theme.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            background-color: var(--background-color);
            color: var(--text-color);
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1rem;
        }

        /* Navigation Button */
        .nav-button {
            background-color: var(--button-primary-background-color);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            border: none;
            display: inline-flex;
            align-items: center;
            text-decoration: none;
            margin-bottom: 1.5rem;
        }
        .nav-button:hover {
            background-color: var(--button-primary-hover-bg);
            text-decoration: none;
        }

        /* Sections */
        .highlight-section {
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .highlight-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--card-heading-color);
            border-bottom: 1px solid var(--divider-color);
            padding-bottom: 0.5rem;
        }

        /* Toolbar */
        .admin-toolbar {
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: background-color 0.2s;
            border: none;
            color: white;
        }
        .btn-primary {
            background-color: var(--button-primary-background-color);
        }
        .btn-primary:hover {
            background-color: var(--button-primary-hover-bg);
        }
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-secondary {
            background-color: var(--button-secondary-bg);
        }
        .btn-secondary:hover {
            background-color: var(--button-secondary-hover-bg);
        }
        .btn-small {
            padding: 0.25rem 0.625rem;
            font-size: 0.75rem;
            border-radius: 0.375rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
            color: white;
        }
        .btn-success {
            background-color: var(--button-success-bg);
        }
        .btn-success:hover {
            background-color: #047857;
        }
        .btn-ghost {
            background: transparent;
            color: var(--card-paragraph-color);
            border: 1px solid var(--input-border-color);
        }
        .btn-ghost:hover {
            background-color: var(--palette-interactive);
        }

        .changes-badge {
            background: var(--palette-text-primary);
            color: var(--palette-primary);
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            display: none;
        }
        .changes-badge.visible {
            display: inline-block;
        }

        /* Table */
        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8125rem;
        }
        .admin-table th {
            text-align: left;
            padding: 0.625rem 0.5rem;
            font-weight: 600;
            font-size: 0.6875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--label-color);
            border-bottom: 2px solid var(--divider-color);
            white-space: nowrap;
            position: sticky;
            top: 0;
            background-color: var(--section-background-color);
            z-index: 1;
        }
        .admin-table td {
            padding: 0.375rem 0.5rem;
            border-bottom: 1px solid var(--divider-color);
            color: var(--card-paragraph-color);
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .admin-table tr:hover {
            background-color: rgba(255,255,255,0.03);
        }
        .admin-table tr.modified {
            background-color: rgba(229, 237, 144, 0.08);
        }
        .admin-table tr.deleted {
            background-color: rgba(239, 68, 68, 0.08);
            text-decoration: line-through;
            opacity: 0.6;
        }
        .admin-table tr.added {
            background-color: rgba(16, 185, 129, 0.08);
        }

        /* Inline edit inputs */
        .admin-table input[type="text"],
        .admin-table input[type="number"],
        .admin-table input[type="url"] {
            padding: 0.25rem 0.375rem;
            border: 1px solid var(--input-border-color);
            border-radius: 0.25rem;
            background-color: var(--input-background-color);
            color: var(--input-text-color);
            width: 100%;
            font-size: 0.8125rem;
            min-width: 60px;
        }
        .admin-table input:focus {
            outline: none;
            border-color: var(--palette-text-primary);
            box-shadow: 0 0 3px 1px var(--palette-text-primary);
        }

        .actions-cell {
            white-space: nowrap;
            display: flex;
            gap: 0.25rem;
        }

        /* Search */
        .search-input {
            padding: 0.5rem;
            border: 1px solid var(--input-border-color);
            border-radius: 0.375rem;
            background-color: var(--input-background-color);
            color: var(--input-text-color);
            font-size: 0.875rem;
            flex: 1;
            min-width: 150px;
            max-width: 300px;
        }
        .search-input:focus {
            outline: none;
            border-color: var(--palette-text-primary);
            box-shadow: 0 0 3px 1px var(--palette-text-primary);
        }

        /* Collapsible section */
        .section-header {
            background-color: var(--header-background-color);
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--header-text-color);
            transition: background-color 0.2s;
            margin-bottom: 1rem;
        }
        .section-header:hover {
            background-color: var(--header-hover-bg);
        }
        .toggle-icon {
            transition: transform 0.3s ease-in-out;
            font-size: 0.875rem;
        }
        .toggle-icon.rotated {
            transform: rotate(-90deg);
        }
        .section-content {
            max-height: 2000px;
            opacity: 1;
            overflow: hidden;
            transition: max-height 0.5s ease-in-out, opacity 0.3s ease-in-out;
        }
        .section-content.collapsed {
            max-height: 0;
            opacity: 0;
        }

        /* Version list */
        .version-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--divider-color);
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .version-item:last-child {
            border-bottom: none;
        }
        .version-info {
            display: flex;
            flex-direction: column;
            gap: 0.125rem;
        }
        .version-date {
            font-weight: 600;
            color: var(--card-heading-color);
            font-size: 0.875rem;
        }
        .version-meta {
            font-size: 0.75rem;
            color: var(--card-paragraph-color);
        }
        .version-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .modal-overlay.hidden {
            display: none;
        }
        .modal-content {
            background: var(--palette-primary);
            border: 1px solid var(--card-border-color);
            border-radius: 0.5rem;
            padding: 1.5rem;
            max-width: 95vw;
            max-height: 90vh;
            overflow: auto;
            width: 1000px;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--divider-color);
        }
        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--card-heading-color);
        }

        /* Diff styles */
        .diff-section {
            margin-bottom: 1rem;
        }
        .diff-section-title {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            padding: 0.375rem 0.75rem;
            border-radius: 0.25rem;
        }
        .diff-added-title {
            background-color: rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
        }
        .diff-removed-title {
            background-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        .diff-modified-title {
            background-color: rgba(229, 237, 144, 0.2);
            color: var(--palette-text-primary);
        }
        .diff-item {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--divider-color);
            font-size: 0.8125rem;
        }
        .diff-item:last-child {
            border-bottom: none;
        }
        .diff-beer-name {
            font-weight: 600;
            color: var(--card-heading-color);
            margin-bottom: 0.25rem;
        }
        .diff-field {
            color: var(--card-paragraph-color);
            font-size: 0.75rem;
        }
        .diff-old {
            color: #fca5a5;
            text-decoration: line-through;
        }
        .diff-new {
            color: #6ee7b7;
        }
        .diff-summary {
            font-size: 0.875rem;
            color: var(--card-paragraph-color);
            padding: 0.75rem;
            background-color: var(--palette-interactive);
            border-radius: 0.375rem;
            margin-bottom: 1rem;
        }

        /* Add beer modal form */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        .form-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--label-color);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .form-input {
            padding: 0.5rem;
            border: 1px solid var(--input-border-color);
            border-radius: 0.375rem;
            background-color: var(--input-background-color);
            color: var(--input-text-color);
            font-size: 0.875rem;
        }
        .form-input:focus {
            outline: none;
            border-color: var(--palette-text-primary);
            box-shadow: 0 0 3px 1px var(--palette-text-primary);
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 1rem;
            left: 50%;
            transform: translateX(-50%);
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            z-index: 2000;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
            pointer-events: none;
        }
        .toast.visible {
            opacity: 1;
        }
        .toast.success {
            background-color: #10b981;
        }
        .toast.error {
            background-color: #ef4444;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .admin-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .search-input {
                max-width: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="nav-button">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:0.5rem"><polyline points="15 18 9 12 15 6"></polyline></svg>
            Back
        </a>

        <h1 class="text-4xl font-semibold text-center mb-6 p-4 rounded-lg shadow-lg"
            style="background-color: var(--title-bg-color); color: var(--title-text-color);">
            Admin - <?php echo htmlspecialchars($festivalTitle); ?>
        </h1>

        <!-- Toolbar -->
        <div class="admin-toolbar">
            <button id="btn-save-all" class="btn btn-success" disabled onclick="saveAllChanges()">Save All Changes</button>
            <button class="btn btn-primary" onclick="showAddModal()">+ Add Beer</button>
            <span id="changes-badge" class="changes-badge">0 changes</span>
            <div style="flex:1"></div>
            <input type="text" id="search-input" class="search-input" placeholder="Search beers..." oninput="handleSearch()">
        </div>

        <!-- Beer Table -->
        <div class="highlight-section" style="padding: 0.75rem;">
            <div class="table-wrapper" style="max-height: 70vh; overflow-y: auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Brewery</th>
                            <th>Style</th>
                            <th>ABV</th>
                            <th>Rating</th>
                            <th>Country</th>
                            <th>Session</th>
                            <th style="min-width:120px">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="beer-table-body">
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Version History -->
        <div class="section-header" id="versions-header" onclick="toggleVersions()">
            <h2 class="text-xl font-bold flex-grow" style="margin:0">Version History</h2>
            <span class="toggle-icon rotated" id="versions-toggle">&#9660;</span>
        </div>
        <div class="section-content collapsed" id="versions-content">
            <div class="highlight-section">
                <div id="versions-list">
                    <p style="color: var(--card-paragraph-color); font-size: 0.875rem;">Loading versions...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Beer Modal -->
    <div id="add-modal" class="modal-overlay hidden">
        <div class="modal-content" style="max-width:600px;">
            <div class="modal-header">
                <span class="modal-title" id="add-modal-title">Add New Beer</span>
                <button class="btn-small btn-ghost" onclick="closeAddModal()">&times;</button>
            </div>
            <div class="form-grid" id="add-form">
            </div>
            <div style="margin-top:1rem; display:flex; gap:0.5rem; justify-content:flex-end;">
                <button class="btn btn-ghost" onclick="closeAddModal()">Cancel</button>
                <button class="btn btn-success" id="add-modal-confirm" onclick="confirmAddBeer()">Add Beer</button>
            </div>
        </div>
    </div>

    <!-- Diff Modal -->
    <div id="diff-modal" class="modal-overlay hidden">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title" id="diff-modal-title">Version Diff</span>
                <button class="btn-small btn-ghost" onclick="closeDiffModal()">&times;</button>
            </div>
            <div id="diff-content"></div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast"></div>

    <script>
    (function() {
        // --- State ---
        var originalBeers = <?php echo $beersJson; ?>;
        var currentBeers = JSON.parse(JSON.stringify(originalBeers));
        var modifiedIds = {};   // id -> 'modified' | 'added' | 'deleted'
        var deletedBeers = {};  // id -> beer object (so we can track them)
        var editingId = null;
        var searchTerm = '';

        var FIELDS = [
            { key: 'name', label: 'Name', type: 'text', required: true },
            { key: 'brewery', label: 'Brewery', type: 'text', required: true },
            { key: 'style', label: 'Style', type: 'text' },
            { key: 'alc', label: 'ABV %', type: 'number', step: '0.1' },
            { key: 'rating', label: 'Rating', type: 'number', step: '0.01' },
            { key: 'country', label: 'Country', type: 'text' },
            { key: 'session', label: 'Session', type: 'text' },
            { key: 'untappd', label: 'Untappd URL', type: 'url' }
        ];

        // --- Init ---
        renderTable();
        loadVersions();

        // Warn on unsaved changes
        window.addEventListener('beforeunload', function(e) {
            if (Object.keys(modifiedIds).length > 0) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // --- Table ---
        function renderTable() {
            var tbody = document.getElementById('beer-table-body');
            var html = '';
            var filtered = getFilteredBeers();

            if (filtered.length === 0) {
                html = '<tr><td colspan="8" style="text-align:center; padding:2rem; color:var(--card-paragraph-color);">No beers found</td></tr>';
            }

            for (var i = 0; i < filtered.length; i++) {
                var beer = filtered[i];
                var isEditing = editingId === beer.id;
                var rowClass = '';
                if (modifiedIds[beer.id] === 'modified') rowClass = 'modified';
                else if (modifiedIds[beer.id] === 'added') rowClass = 'added';

                if (isEditing) {
                    html += renderEditRow(beer, rowClass);
                } else {
                    html += renderDisplayRow(beer, rowClass);
                }
            }
            tbody.innerHTML = html;
            updateToolbar();
        }

        function renderDisplayRow(beer, rowClass) {
            return '<tr class="' + rowClass + '" data-id="' + esc(beer.id) + '">' +
                '<td title="' + esc(beer.name || '') + '">' + esc(beer.name || '') + '</td>' +
                '<td title="' + esc(beer.brewery || '') + '">' + esc(beer.brewery || '') + '</td>' +
                '<td title="' + esc(beer.style || '') + '">' + esc(beer.style || '') + '</td>' +
                '<td>' + esc(beer.alc != null ? beer.alc : '') + '</td>' +
                '<td>' + esc(beer.rating != null ? beer.rating : '') + '</td>' +
                '<td>' + esc(beer.country || '') + '</td>' +
                '<td>' + esc(beer.session || '') + '</td>' +
                '<td><div class="actions-cell">' +
                    '<button class="btn-small btn-primary" onclick="window._admin.startEdit(\'' + esc(beer.id) + '\')">Edit</button>' +
                    '<button class="btn-small btn-secondary" onclick="window._admin.deleteBeer(\'' + esc(beer.id) + '\')">Del</button>' +
                '</div></td>' +
            '</tr>';
        }

        function renderEditRow(beer, rowClass) {
            var html = '<tr class="' + rowClass + '" data-id="' + esc(beer.id) + '">';
            for (var f = 0; f < FIELDS.length; f++) {
                var field = FIELDS[f];
                var val = beer[field.key] != null ? beer[field.key] : '';
                var attrs = 'type="' + field.type + '" data-field="' + field.key + '" value="' + esc(String(val)) + '"';
                if (field.step) attrs += ' step="' + field.step + '"';
                html += '<td><input ' + attrs + ' onkeydown="window._admin.handleEditKey(event, \'' + esc(beer.id) + '\')"></td>';
            }
            html += '<td><div class="actions-cell">' +
                '<button class="btn-small btn-success" onclick="window._admin.saveEdit(\'' + esc(beer.id) + '\')">OK</button>' +
                '<button class="btn-small btn-ghost" onclick="window._admin.cancelEdit()">X</button>' +
            '</div></td></tr>';
            return html;
        }

        function getFilteredBeers() {
            if (!searchTerm) return currentBeers;
            var term = searchTerm.toLowerCase();
            return currentBeers.filter(function(b) {
                return (b.name && b.name.toLowerCase().indexOf(term) >= 0) ||
                       (b.brewery && b.brewery.toLowerCase().indexOf(term) >= 0) ||
                       (b.style && b.style.toLowerCase().indexOf(term) >= 0) ||
                       (b.id && b.id.toLowerCase().indexOf(term) >= 0) ||
                       (b.country && b.country.toLowerCase().indexOf(term) >= 0) ||
                       (b.session && b.session.toLowerCase().indexOf(term) >= 0);
            });
        }

        // --- Edit ---
        function startEdit(beerId) {
            editingId = beerId;
            renderTable();
            // Focus first input
            var row = document.querySelector('tr[data-id="' + beerId + '"]');
            if (row) {
                var input = row.querySelector('input');
                if (input) input.focus();
            }
        }

        function saveEdit(beerId) {
            var row = document.querySelector('tr[data-id="' + beerId + '"]');
            if (!row) return;

            var inputs = row.querySelectorAll('input');
            var beerIndex = currentBeers.findIndex(function(b) { return b.id === beerId; });
            if (beerIndex < 0) return;

            var beer = currentBeers[beerIndex];
            var changed = false;

            inputs.forEach(function(input) {
                var field = input.getAttribute('data-field');
                var val = input.value.trim();

                if (field === 'alc' || field === 'rating') {
                    val = val === '' ? null : parseFloat(val);
                    if (val !== null && isNaN(val)) val = null;
                }

                var orig = beer[field];
                if (orig === undefined) orig = null;
                if (val !== orig) {
                    changed = true;
                }
                if (val === null || val === '') {
                    delete beer[field];
                } else {
                    beer[field] = val;
                }
            });

            if (changed && !modifiedIds[beerId]) {
                // Check if it's actually different from original
                var origBeer = originalBeers.find(function(b) { return b.id === beerId; });
                if (origBeer) {
                    modifiedIds[beerId] = 'modified';
                } else {
                    modifiedIds[beerId] = 'added';
                }
            }

            editingId = null;
            renderTable();
        }

        function cancelEdit() {
            editingId = null;
            renderTable();
        }

        function handleEditKey(e, beerId) {
            if (e.key === 'Enter') {
                e.preventDefault();
                saveEdit(beerId);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                cancelEdit();
            }
        }

        // --- Delete ---
        function deleteBeer(beerId) {
            if (!confirm('Delete this beer?')) return;
            var idx = currentBeers.findIndex(function(b) { return b.id === beerId; });
            if (idx < 0) return;

            var beer = currentBeers[idx];
            var wasNew = modifiedIds[beerId] === 'added';
            currentBeers.splice(idx, 1);

            if (wasNew) {
                // Was newly added, just remove the tracking
                delete modifiedIds[beerId];
            } else {
                modifiedIds[beerId] = 'deleted';
                deletedBeers[beerId] = beer;
            }

            if (editingId === beerId) editingId = null;
            renderTable();
        }

        // --- Add Beer ---
        function showAddModal() {
            var form = document.getElementById('add-form');
            var html = '<div class="form-group"><label class="form-label">ID (unique)</label>' +
                '<input type="text" class="form-input" id="add-field-id" value="' + generateId() + '"></div>';

            for (var f = 0; f < FIELDS.length; f++) {
                var field = FIELDS[f];
                html += '<div class="form-group"><label class="form-label">' + esc(field.label) +
                    (field.required ? ' *' : '') + '</label>' +
                    '<input type="' + field.type + '" class="form-input" id="add-field-' + field.key + '"' +
                    (field.step ? ' step="' + field.step + '"' : '') + '></div>';
            }
            form.innerHTML = html;
            document.getElementById('add-modal-title').textContent = 'Add New Beer';
            document.getElementById('add-modal-confirm').textContent = 'Add Beer';
            document.getElementById('add-modal').classList.remove('hidden');
        }

        function closeAddModal() {
            document.getElementById('add-modal').classList.add('hidden');
        }

        window.confirmAddBeer = function() {
            var idInput = document.getElementById('add-field-id');
            var id = idInput.value.trim();
            if (!id) {
                showToast('ID is required', 'error');
                return;
            }
            if (currentBeers.some(function(b) { return b.id === id; })) {
                showToast('A beer with this ID already exists', 'error');
                return;
            }

            var beer = { id: id };
            for (var f = 0; f < FIELDS.length; f++) {
                var field = FIELDS[f];
                var input = document.getElementById('add-field-' + field.key);
                var val = input ? input.value.trim() : '';

                if (field.required && !val) {
                    showToast(field.label + ' is required', 'error');
                    return;
                }

                if (field.type === 'number') {
                    val = val === '' ? null : parseFloat(val);
                    if (val !== null && isNaN(val)) val = null;
                }

                if (val !== null && val !== '') {
                    beer[field.key] = val;
                }
            }

            currentBeers.push(beer);
            modifiedIds[id] = 'added';
            closeAddModal();
            renderTable();
            showToast('Beer added (unsaved)');
        };

        function generateId() {
            var maxNum = 0;
            for (var i = 0; i < currentBeers.length; i++) {
                var match = currentBeers[i].id.match(/(\d+)$/);
                if (match) {
                    var n = parseInt(match[1], 10);
                    if (n > maxNum) maxNum = n;
                }
            }
            return 'beer-' + (maxNum + 1);
        }

        // --- Save All ---
        function saveAllChanges() {
            if (Object.keys(modifiedIds).length === 0) return;
            if (!confirm('Save all changes? This will create a backup of the current data.')) return;

            var btn = document.getElementById('btn-save-all');
            btn.disabled = true;
            btn.textContent = 'Saving...';

            fetch('admin_api.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(currentBeers)
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    originalBeers = JSON.parse(JSON.stringify(currentBeers));
                    modifiedIds = {};
                    deletedBeers = {};
                    showToast('Saved! Backup: ' + (data.backup || 'none'));
                    renderTable();
                    loadVersions();
                } else {
                    showToast('Error: ' + (data.message || 'Save failed'), 'error');
                }
            })
            .catch(function(err) {
                showToast('Network error: ' + err.message, 'error');
            })
            .finally(function() {
                btn.textContent = 'Save All Changes';
                updateToolbar();
            });
        }
        window.saveAllChanges = saveAllChanges;

        // --- Toolbar ---
        function updateToolbar() {
            var count = Object.keys(modifiedIds).length;
            var badge = document.getElementById('changes-badge');
            var btn = document.getElementById('btn-save-all');

            if (count > 0) {
                badge.textContent = count + ' change' + (count > 1 ? 's' : '');
                badge.classList.add('visible');
                btn.disabled = false;
            } else {
                badge.classList.remove('visible');
                btn.disabled = true;
            }
        }

        // --- Search ---
        function handleSearch() {
            searchTerm = document.getElementById('search-input').value.trim();
            renderTable();
        }
        window.handleSearch = handleSearch;

        // --- Versions ---
        function toggleVersions() {
            var content = document.getElementById('versions-content');
            var icon = document.getElementById('versions-toggle');
            content.classList.toggle('collapsed');
            icon.classList.toggle('rotated');
        }
        window.toggleVersions = toggleVersions;

        function loadVersions() {
            fetch('admin_api.php?action=versions')
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    renderVersions(data.versions);
                }
            })
            .catch(function(err) {
                document.getElementById('versions-list').innerHTML =
                    '<p style="color:var(--card-paragraph-color)">Failed to load versions</p>';
            });
        }

        function renderVersions(versions) {
            var container = document.getElementById('versions-list');
            if (!versions || versions.length === 0) {
                container.innerHTML = '<p style="color:var(--card-paragraph-color); font-size:0.875rem;">No previous versions found. Versions are created when you save changes.</p>';
                return;
            }

            var html = '';
            for (var i = 0; i < versions.length; i++) {
                var v = versions[i];
                var dateStr = formatTimestamp(v.timestamp);
                var sizeStr = formatBytes(v.size);

                html += '<div class="version-item">' +
                    '<div class="version-info">' +
                        '<span class="version-date">' + esc(dateStr) + '</span>' +
                        '<span class="version-meta">' + v.beer_count + ' beers &middot; ' + esc(sizeStr) + '</span>' +
                    '</div>' +
                    '<div class="version-actions">' +
                        '<button class="btn-small btn-primary" onclick="window._admin.showDiff(\'' + esc(v.filename) + '\', \'' + esc(dateStr) + '\')">View Diff</button>' +
                        '<button class="btn-small btn-secondary" onclick="window._admin.restoreVersion(\'' + esc(v.filename) + '\', \'' + esc(dateStr) + '\')">Restore</button>' +
                    '</div>' +
                '</div>';
            }
            container.innerHTML = html;
        }

        // --- Diff ---
        function showDiff(filename, dateLabel) {
            document.getElementById('diff-modal-title').textContent = 'Diff: ' + dateLabel + ' vs Current';
            document.getElementById('diff-content').innerHTML = '<p style="color:var(--card-paragraph-color)">Loading...</p>';
            document.getElementById('diff-modal').classList.remove('hidden');

            fetch('admin_api.php?action=version_data&filename=' + encodeURIComponent(filename))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    var diff = computeDiff(data.data, originalBeers);
                    renderDiff(diff);
                } else {
                    document.getElementById('diff-content').innerHTML =
                        '<p style="color:#ef4444">Error: ' + esc(data.message) + '</p>';
                }
            })
            .catch(function(err) {
                document.getElementById('diff-content').innerHTML =
                    '<p style="color:#ef4444">Failed to load version data</p>';
            });
        }

        function computeDiff(oldData, newData) {
            var oldMap = {};
            var newMap = {};
            for (var i = 0; i < oldData.length; i++) oldMap[oldData[i].id] = oldData[i];
            for (var j = 0; j < newData.length; j++) newMap[newData[j].id] = newData[j];

            var added = [];
            var removed = [];
            var modified = [];
            var unchanged = 0;

            // Find removed and modified
            for (var oldId in oldMap) {
                if (!newMap[oldId]) {
                    removed.push(oldMap[oldId]);
                } else {
                    var changes = getFieldChanges(oldMap[oldId], newMap[oldId]);
                    if (changes.length > 0) {
                        modified.push({ beer: newMap[oldId], changes: changes });
                    } else {
                        unchanged++;
                    }
                }
            }

            // Find added
            for (var newId in newMap) {
                if (!oldMap[newId]) {
                    added.push(newMap[newId]);
                }
            }

            return { added: added, removed: removed, modified: modified, unchanged: unchanged };
        }

        function getFieldChanges(oldBeer, newBeer) {
            var allKeys = {};
            var changes = [];
            for (var k in oldBeer) allKeys[k] = true;
            for (var k2 in newBeer) allKeys[k2] = true;

            for (var key in allKeys) {
                if (key === 'id') continue;
                var oldVal = oldBeer[key] != null ? oldBeer[key] : '';
                var newVal = newBeer[key] != null ? newBeer[key] : '';
                if (String(oldVal) !== String(newVal)) {
                    changes.push({ field: key, oldVal: oldVal, newVal: newVal });
                }
            }
            return changes;
        }

        function renderDiff(diff) {
            var html = '';

            // Summary
            html += '<div class="diff-summary">' +
                '<strong>' + diff.added.length + '</strong> added, ' +
                '<strong>' + diff.removed.length + '</strong> removed, ' +
                '<strong>' + diff.modified.length + '</strong> modified, ' +
                '<strong>' + diff.unchanged + '</strong> unchanged' +
            '</div>';

            if (diff.added.length === 0 && diff.removed.length === 0 && diff.modified.length === 0) {
                html += '<p style="text-align:center; color:var(--card-paragraph-color); padding:1rem;">No differences found</p>';
                document.getElementById('diff-content').innerHTML = html;
                return;
            }

            // Added
            if (diff.added.length > 0) {
                html += '<div class="diff-section"><div class="diff-section-title diff-added-title">+ Added (' + diff.added.length + ')</div>';
                for (var a = 0; a < diff.added.length; a++) {
                    var beer = diff.added[a];
                    html += '<div class="diff-item"><div class="diff-beer-name">' + esc(beer.name || beer.id) + '</div>' +
                        '<div class="diff-field">' + esc(beer.brewery || '') + ' &middot; ' + esc(beer.style || '') + '</div></div>';
                }
                html += '</div>';
            }

            // Removed
            if (diff.removed.length > 0) {
                html += '<div class="diff-section"><div class="diff-section-title diff-removed-title">- Removed (' + diff.removed.length + ')</div>';
                for (var r = 0; r < diff.removed.length; r++) {
                    var rbeer = diff.removed[r];
                    html += '<div class="diff-item"><div class="diff-beer-name">' + esc(rbeer.name || rbeer.id) + '</div>' +
                        '<div class="diff-field">' + esc(rbeer.brewery || '') + ' &middot; ' + esc(rbeer.style || '') + '</div></div>';
                }
                html += '</div>';
            }

            // Modified
            if (diff.modified.length > 0) {
                html += '<div class="diff-section"><div class="diff-section-title diff-modified-title">~ Modified (' + diff.modified.length + ')</div>';
                for (var m = 0; m < diff.modified.length; m++) {
                    var mod = diff.modified[m];
                    html += '<div class="diff-item"><div class="diff-beer-name">' + esc(mod.beer.name || mod.beer.id) + '</div>';
                    for (var c = 0; c < mod.changes.length; c++) {
                        var ch = mod.changes[c];
                        html += '<div class="diff-field">' + esc(ch.field) + ': ' +
                            '<span class="diff-old">' + esc(String(ch.oldVal)) + '</span> &rarr; ' +
                            '<span class="diff-new">' + esc(String(ch.newVal)) + '</span></div>';
                    }
                    html += '</div>';
                }
                html += '</div>';
            }

            document.getElementById('diff-content').innerHTML = html;
        }

        function closeDiffModal() {
            document.getElementById('diff-modal').classList.add('hidden');
        }
        window.closeDiffModal = closeDiffModal;

        // --- Restore ---
        function restoreVersion(filename, dateLabel) {
            if (!confirm('Restore version from ' + dateLabel + '? Current data will be backed up first.')) return;

            fetch('admin_api.php?action=restore', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ filename: filename })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    showToast('Version restored! Reloading...');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showToast('Error: ' + (data.message || 'Restore failed'), 'error');
                }
            })
            .catch(function(err) {
                showToast('Network error: ' + err.message, 'error');
            });
        }

        // --- Helpers ---
        function esc(str) {
            if (str == null) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(String(str)));
            return div.innerHTML;
        }

        function showToast(msg, type) {
            var toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.className = 'toast visible ' + (type || 'success');
            clearTimeout(window._toastTimer);
            window._toastTimer = setTimeout(function() {
                toast.classList.remove('visible');
            }, 3000);
        }

        function formatTimestamp(ts) {
            // ts is like "20260207_143022"
            if (!ts || ts.length < 15) return ts;
            return ts.substring(0, 4) + '-' + ts.substring(4, 6) + '-' + ts.substring(6, 8) +
                   ' ' + ts.substring(9, 11) + ':' + ts.substring(11, 13) + ':' + ts.substring(13, 15);
        }

        function formatBytes(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // Expose functions to onclick handlers
        window._admin = {
            startEdit: startEdit,
            saveEdit: saveEdit,
            cancelEdit: cancelEdit,
            handleEditKey: handleEditKey,
            deleteBeer: deleteBeer,
            showDiff: showDiff,
            restoreVersion: restoreVersion
        };
        window.showAddModal = showAddModal;
        window.closeAddModal = closeAddModal;
    })();
    </script>
</body>
</html>
<?php
$html = ob_get_clean();
echo preg_replace('/<!--[\s\S]*?-->/', '', $html);
?>
