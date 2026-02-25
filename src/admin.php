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
    <link rel="stylesheet" href="dist/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo file_exists(__DIR__ . '/custom/theme.css') ? 'custom/theme.css' : 'config/theme.css'; ?>">
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

        /* Untappd lookup */
        .btn-untappd {
            background: #f5a623;
            color: #1a1a1a;
            font-weight: 700;
        }
        .btn-untappd:hover {
            background: #e09500;
        }
        .lookup-progress {
            padding: 1rem;
            text-align: center;
            color: var(--card-paragraph-color);
        }
        .lookup-progress-bar {
            width: 100%;
            height: 6px;
            background: var(--palette-interactive);
            border-radius: 3px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        .lookup-progress-fill {
            height: 100%;
            background: var(--palette-text-primary);
            border-radius: 3px;
            transition: width 0.3s;
        }
        .lookup-result-item {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            align-items: center;
            padding: 0.75rem;
            border-bottom: 1px solid var(--divider-color);
            font-size: 0.8125rem;
        }
        .lookup-result-item:last-child {
            border-bottom: none;
        }
        .lookup-result-info {
            flex: 1;
            min-width: 200px;
        }
        .lookup-result-beer {
            font-weight: 600;
            color: var(--card-heading-color);
        }
        .lookup-result-meta {
            font-size: 0.75rem;
            color: var(--card-paragraph-color);
            margin-top: 0.125rem;
        }
        .lookup-result-values {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .lookup-value-box {
            text-align: center;
            min-width: 60px;
        }
        .lookup-value-label {
            font-size: 0.625rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--card-paragraph-color);
        }
        .lookup-value-num {
            font-size: 1rem;
            font-weight: 700;
            color: var(--card-heading-color);
        }
        .lookup-arrow {
            color: var(--card-paragraph-color);
            font-size: 1rem;
        }
        .confidence-high { color: #6ee7b7; }
        .confidence-medium { color: #fcd34d; }
        .confidence-low { color: #fca5a5; }
        .lookup-result-actions {
            display: flex;
            gap: 0.375rem;
            align-items: center;
        }
        .lookup-manual-input {
            display: flex;
            gap: 0.375rem;
            margin-top: 0.5rem;
            width: 100%;
        }
        .lookup-manual-input input {
            flex: 1;
            padding: 0.375rem 0.5rem;
            border: 1px solid var(--input-border-color);
            border-radius: 0.25rem;
            background-color: var(--input-background-color);
            color: var(--input-text-color);
            font-size: 0.75rem;
        }
        .lookup-accepted {
            opacity: 0.5;
        }
        .lookup-dropdown {
            position: relative;
            display: inline-block;
        }
        .lookup-dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 100;
            margin-top: 0.25rem;
            min-width: 220px;
            background: var(--palette-primary);
            border: 1px solid var(--card-border-color);
            border-radius: 0.375rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .lookup-dropdown-menu.open {
            display: block;
        }
        .lookup-dropdown-menu button {
            display: block;
            width: 100%;
            text-align: left;
            padding: 0.625rem 1rem;
            background: none;
            border: none;
            color: var(--card-paragraph-color);
            font-size: 0.8125rem;
            cursor: pointer;
            transition: background-color 0.15s;
        }
        .lookup-dropdown-menu button:hover {
            background: var(--palette-interactive);
            color: var(--palette-text-primary);
        }
        .lookup-dropdown-menu .menu-hint {
            font-size: 0.6875rem;
            color: var(--card-paragraph-color);
            opacity: 0.7;
        }
        .lookup-status-badge {
            font-size: 0.6875rem;
            font-weight: 700;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
        }
        .badge-accepted {
            background: rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
        }
        .badge-declined {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        .badge-nochange {
            background: rgba(203, 213, 225, 0.15);
            color: var(--card-paragraph-color);
        }

        /* Bad Rater Detection */
        .rater-pattern-badge {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            margin: 0.125rem;
        }
        .rater-pattern-high { background: rgba(239,68,68,0.2); color: #fca5a5; }
        .rater-pattern-medium { background: rgba(252,211,77,0.2); color: #fcd34d; }
        .rater-pattern-low { background: rgba(110,231,183,0.2); color: #6ee7b7; }
        .rater-conf-high { color: #ef4444; font-weight: 700; }
        .rater-conf-medium { color: #fcd34d; font-weight: 600; }
        .rater-conf-low { color: #6ee7b7; }
        .rater-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        .rater-table th {
            text-align: left; padding: 0.625rem 0.5rem; font-weight: 600;
            font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em;
            color: var(--label-color); border-bottom: 2px solid var(--divider-color); white-space: nowrap;
        }
        .rater-table td {
            padding: 0.5rem; border-bottom: 1px solid var(--divider-color);
            color: var(--card-paragraph-color); vertical-align: top;
        }
        .rater-table tr.excluded-row { opacity: 0.5; }
        .rater-excluded-badge {
            background: rgba(239,68,68,0.2); color: #fca5a5;
            font-size: 0.6875rem; font-weight: 700; padding: 0.125rem 0.5rem; border-radius: 9999px;
        }
        .rater-scan-info {
            font-size: 0.875rem; color: var(--card-paragraph-color);
            padding: 0.75rem; background-color: var(--palette-interactive);
            border-radius: 0.375rem; margin-bottom: 1rem;
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
            <div class="lookup-dropdown" id="lookup-dropdown">
                <button class="btn btn-untappd" onclick="window._admin.toggleLookupMenu(event)">Lookup Ratings &#9662;</button>
                <div class="lookup-dropdown-menu" id="lookup-dropdown-menu">
                    <button onclick="window._admin.bulkLookup('all')">All Beers</button>
                    <button onclick="window._admin.bulkLookup('not-recent')">Not checked in 12h<br><span class="menu-hint" id="lookup-hint-not-recent"></span></button>
                    <button onclick="window._admin.bulkLookup('missing-url')">Missing Untappd URL<br><span class="menu-hint" id="lookup-hint-missing-url"></span></button>
                </div>
            </div>
            <span id="changes-badge" class="changes-badge" style="cursor:pointer;" onclick="window._admin.showPendingDiff()">0 changes</span>
            <div style="flex:1"></div>
            <input type="text" id="search-input" class="search-input" placeholder="Search beers..." oninput="handleSearch()">
        </div>

        <!-- Beer Table -->
        <div class="highlight-section" style="padding: 0.75rem;">
            <div class="table-wrapper" style="max-height: 70vh; overflow-y: auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="min-width:120px">Actions</th>
                            <th>Name</th>
                            <th>Brewery</th>
                            <th>Style</th>
                            <th>ABV</th>
                            <th>Rating</th>
                            <th>Country</th>
                            <th>Session</th>
                            <th>Note</th>
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

        <!-- Bad Rater Detection -->
        <div class="section-header" id="raters-header" onclick="window._admin.toggleRaters()">
            <h2 class="text-xl font-bold flex-grow" style="margin:0">Bad Rater Detection</h2>
            <span class="toggle-icon rotated" id="raters-toggle">&#9660;</span>
        </div>
        <div class="section-content collapsed" id="raters-content">
            <div class="highlight-section">
                <div style="display:flex; gap:0.5rem; align-items:center; margin-bottom:1rem; flex-wrap:wrap;">
                    <button class="btn btn-primary" id="btn-scan-raters" onclick="window._admin.scanBadRaters()">Scan for Bad Raters</button>
                    <span id="rater-scan-status" style="font-size:0.875rem; color:var(--card-paragraph-color);"></span>
                </div>
                <div id="raters-results">
                    <p style="color: var(--card-paragraph-color); font-size: 0.875rem;">Click "Scan for Bad Raters" to analyse the ratings log for suspicious patterns.</p>
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

    <!-- Untappd Lookup Review Modal -->
    <div id="lookup-modal" class="modal-overlay hidden">
        <div class="modal-content" style="width:1100px;">
            <div class="modal-header">
                <span class="modal-title" id="lookup-modal-title">Untappd Lookup</span>
                <button class="btn-small btn-ghost" onclick="window._admin.closeLookupModal()">&times;</button>
            </div>
            <div id="lookup-modal-toolbar" style="display:none; margin-bottom:1rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                <button class="btn btn-success btn-small" onclick="window._admin.acceptAllHigh()">Accept All High Confidence</button>
                <button class="btn btn-ghost btn-small" onclick="window._admin.closeLookupModal()">Done</button>
            </div>
            <div id="lookup-content"></div>
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
            { key: 'note', label: 'Note', type: 'text' },
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
                html = '<tr><td colspan="9" style="text-align:center; padding:2rem; color:var(--card-paragraph-color);">No beers found</td></tr>';
            }

            for (var i = 0; i < filtered.length; i++) {
                var beer = filtered[i];
                var rowClass = '';
                if (modifiedIds[beer.id] === 'modified') rowClass = 'modified';
                else if (modifiedIds[beer.id] === 'added') rowClass = 'added';

                html += renderDisplayRow(beer, rowClass);
            }
            tbody.innerHTML = html;
            updateToolbar();
        }

        function renderDisplayRow(beer, rowClass) {
            return '<tr class="' + rowClass + '" data-id="' + esc(beer.id) + '">' +
                '<td><div class="actions-cell">' +
                    '<button class="btn-small btn-primary" onclick="window._admin.startEdit(\'' + esc(beer.id) + '\')">Edit</button>' +
                    '<button class="btn-small btn-untappd" onclick="window._admin.singleLookup(\'' + esc(beer.id) + '\')">UT</button>' +
                    '<button class="btn-small btn-secondary" onclick="window._admin.deleteBeer(\'' + esc(beer.id) + '\')">Del</button>' +
                '</div></td>' +
                '<td title="' + esc(beer.name || '') + '">' + esc(beer.name || '') + '</td>' +
                '<td title="' + esc(beer.brewery || '') + '">' + esc(beer.brewery || '') + '</td>' +
                '<td title="' + esc(beer.style || '') + '">' + esc(beer.style || '') + '</td>' +
                '<td>' + esc(beer.alc != null ? beer.alc : '') + '</td>' +
                '<td>' + esc(beer.rating != null ? beer.rating : '') + '</td>' +
                '<td>' + esc(beer.country || '') + '</td>' +
                '<td>' + esc(beer.session || '') + '</td>' +
                '<td title="' + esc(beer.note || '') + '">' + esc(beer.note || '') + '</td>' +
            '</tr>';
        }

        // renderEditRow removed — editing now uses the modal

        function getFilteredBeers() {
            if (!searchTerm) return currentBeers;
            var term = searchTerm.toLowerCase();
            return currentBeers.filter(function(b) {
                return (b.name && b.name.toLowerCase().indexOf(term) >= 0) ||
                       (b.brewery && b.brewery.toLowerCase().indexOf(term) >= 0) ||
                       (b.style && b.style.toLowerCase().indexOf(term) >= 0) ||
                       (b.id && b.id.toLowerCase().indexOf(term) >= 0) ||
                       (b.country && b.country.toLowerCase().indexOf(term) >= 0) ||
                       (b.session && b.session.toLowerCase().indexOf(term) >= 0) ||
                       (b.note && b.note.toLowerCase().indexOf(term) >= 0);
            });
        }

        // --- Edit (modal-based) ---
        function startEdit(beerId) {
            var beer = currentBeers.find(function(b) { return b.id === beerId; });
            if (!beer) return;

            var form = document.getElementById('add-form');
            var html = '<div class="form-group"><label class="form-label">ID (unique)</label>' +
                '<input type="text" class="form-input" id="add-field-id" value="' + esc(beer.id) + '" readonly style="opacity:0.6;cursor:not-allowed;"></div>';

            for (var f = 0; f < FIELDS.length; f++) {
                var field = FIELDS[f];
                var val = beer[field.key] != null ? beer[field.key] : '';
                html += '<div class="form-group"><label class="form-label">' + esc(field.label) +
                    (field.required ? ' *' : '') + '</label>' +
                    '<input type="' + field.type + '" class="form-input" id="add-field-' + field.key + '"' +
                    ' value="' + esc(String(val)) + '"' +
                    (field.step ? ' step="' + field.step + '"' : '') + '></div>';
            }
            form.innerHTML = html;

            editingId = beerId;
            document.getElementById('add-modal-title').textContent = 'Edit Beer';
            document.getElementById('add-modal-confirm').textContent = 'Save Changes';
            document.getElementById('add-modal').classList.remove('hidden');
            // Focus the first editable field (name)
            var firstInput = document.getElementById('add-field-name');
            if (firstInput) firstInput.focus();
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

            if (editingId === beerId) closeAddModal();
            renderTable();
        }

        // --- Add Beer ---
        function showAddModal() {
            editingId = null;
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
            editingId = null;
            document.getElementById('add-modal').classList.add('hidden');
        }

        window.confirmAddBeer = function() {
            var isEditing = editingId !== null;
            var id = isEditing ? editingId : document.getElementById('add-field-id').value.trim();

            if (!id) {
                showToast('ID is required', 'error');
                return;
            }

            if (!isEditing && currentBeers.some(function(b) { return b.id === id; })) {
                showToast('A beer with this ID already exists', 'error');
                return;
            }

            // Collect field values from modal
            var newValues = {};
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

                newValues[field.key] = val;
            }

            if (isEditing) {
                // Edit existing beer
                var beerIndex = currentBeers.findIndex(function(b) { return b.id === id; });
                if (beerIndex < 0) return;
                var beer = currentBeers[beerIndex];

                // Apply values and detect real changes
                var hasChanges = false;
                for (var key in newValues) {
                    var newVal = newValues[key];
                    var oldVal = beer[key];
                    if (oldVal === undefined) oldVal = null;
                    if (newVal === '') newVal = null;

                    // Normalize for comparison: both null/empty are equivalent
                    var oldNorm = (oldVal === null || oldVal === undefined || oldVal === '') ? null : oldVal;
                    var newNorm = (newVal === null || newVal === undefined || newVal === '') ? null : newVal;
                    if (String(oldNorm) !== String(newNorm)) {
                        hasChanges = true;
                    }

                    if (newVal === null || newVal === '') {
                        delete beer[key];
                    } else {
                        beer[key] = newVal;
                    }
                }

                if (hasChanges && !modifiedIds[id]) {
                    var origBeer = originalBeers.find(function(b) { return b.id === id; });
                    modifiedIds[id] = origBeer ? 'modified' : 'added';
                }
            } else {
                // Add new beer
                var beer = { id: id };
                for (var key in newValues) {
                    if (newValues[key] !== null && newValues[key] !== '') {
                        beer[key] = newValues[key];
                    }
                }
                currentBeers.push(beer);
                modifiedIds[id] = 'added';
            }

            closeAddModal();
            renderTable();
            showToast(isEditing ? (hasChanges ? 'Beer updated (unsaved)' : 'No changes detected') : 'Beer added (unsaved)');
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
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
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

        function showPendingDiff() {
            if (Object.keys(modifiedIds).length === 0) return;

            document.getElementById('diff-modal-title').textContent = 'Pending Changes';

            // Build the diff from tracked changes
            var added = [];
            var removed = [];
            var modified = [];

            for (var id in modifiedIds) {
                var type = modifiedIds[id];
                if (type === 'added') {
                    var beer = currentBeers.find(function(b) { return b.id === id; });
                    if (beer) added.push(beer);
                } else if (type === 'deleted') {
                    var dBeer = deletedBeers[id];
                    if (dBeer) removed.push(dBeer);
                } else if (type === 'modified') {
                    var origBeer = originalBeers.find(function(b) { return b.id === id; });
                    var currBeer = currentBeers.find(function(b) { return b.id === id; });
                    if (origBeer && currBeer) {
                        var changes = getFieldChanges(origBeer, currBeer);
                        if (changes.length > 0) {
                            modified.push({ beer: currBeer, changes: changes });
                        }
                    }
                }
            }

            var diff = { added: added, removed: removed, modified: modified, unchanged: currentBeers.length - added.length - modified.length };
            renderDiff(diff);
            document.getElementById('diff-modal').classList.remove('hidden');
        }

        // --- Restore ---
        function restoreVersion(filename, dateLabel) {
            if (!confirm('Restore version from ' + dateLabel + '? Current data will be backed up first.')) return;

            fetch('admin_api.php?action=restore', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
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

        function timeAgo(unixTs) {
            var diff = Math.floor(Date.now() / 1000) - unixTs;
            if (diff < 60) return 'just now';
            if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
            if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
            return Math.floor(diff / 86400) + 'd ago';
        }

        function formatBytes(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // --- Untappd Lookup ---
        var lookupBatchSize = <?php echo (int)(getenv('UNTAPPD_RATE_MAX') ?: 5); ?>;
        var lookupResults = []; // Stored results for the current review session

        function singleLookup(beerId) {
            var beer = currentBeers.find(function(b) { return b.id === beerId; });
            if (!beer) return;
            runLookup([{ id: beerId }]);
        }

        function toggleLookupMenu(e) {
            e.stopPropagation();
            var menu = document.getElementById('lookup-dropdown-menu');
            var isOpen = menu.classList.contains('open');
            menu.classList.toggle('open');
            if (!isOpen) {
                // Update hints with counts
                var twelveHoursAgo = Math.floor(Date.now() / 1000) - (12 * 3600);
                var notRecent = currentBeers.filter(function(b) { return !b.last_lookup || b.last_lookup < twelveHoursAgo; }).length;
                var missingUrl = currentBeers.filter(function(b) { return !b.untappd; }).length;
                document.getElementById('lookup-hint-not-recent').textContent = notRecent + ' of ' + currentBeers.length + ' beers';
                document.getElementById('lookup-hint-missing-url').textContent = missingUrl + ' of ' + currentBeers.length + ' beers';
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            var menu = document.getElementById('lookup-dropdown-menu');
            if (menu && !e.target.closest('.lookup-dropdown')) {
                menu.classList.remove('open');
            }
        });

        function bulkLookup(filter) {
            document.getElementById('lookup-dropdown-menu').classList.remove('open');

            var queue;
            if (filter === 'not-recent') {
                var twelveHoursAgo = Math.floor(Date.now() / 1000) - (12 * 3600);
                queue = currentBeers.filter(function(b) { return !b.last_lookup || b.last_lookup < twelveHoursAgo; });
            } else if (filter === 'missing-url') {
                queue = currentBeers.filter(function(b) { return !b.untappd; });
            } else {
                queue = currentBeers.slice();
            }

            if (queue.length === 0) {
                showToast('No beers match this filter', 'error');
                return;
            }

            openLookupModal();
            showLookupProgress(0, queue.length);
            lookupResults = [];
            var batchSize = lookupBatchSize;

            function processBatch(startIdx) {
                if (startIdx >= queue.length) {
                    renderLookupResults();
                    return;
                }
                var batch = queue.slice(startIdx, startIdx + batchSize);
                var payload = batch.map(function(b) {
                    return { id: b.id };
                });

                fetch('admin_api.php?action=untappd_lookup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(payload)
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.status === 'success' && data.results) {
                        lookupResults = lookupResults.concat(data.results);
                        stampLastLookup(data.results);
                    }
                    showLookupProgress(Math.min(startIdx + batchSize, queue.length), queue.length);
                    processBatch(startIdx + batchSize);
                })
                .catch(function(err) {
                    showToast('Lookup error: ' + err.message, 'error');
                    renderLookupResults();
                });
            }

            processBatch(0);
        }

        // Sync last_lookup from server into both current and original (no unsaved-change trigger)
        function stampLastLookup(results) {
            var now = Math.floor(Date.now() / 1000);
            results.forEach(function(r) {
                var ci = currentBeers.findIndex(function(b) { return b.id === r.id; });
                if (ci >= 0) currentBeers[ci].last_lookup = now;
                var oi = originalBeers.findIndex(function(b) { return b.id === r.id; });
                if (oi >= 0) originalBeers[oi].last_lookup = now;
            });
        }

        function runLookup(items) {
            openLookupModal();
            showLookupProgress(0, items.length);
            lookupResults = [];

            fetch('admin_api.php?action=untappd_lookup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(items)
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success' && data.results) {
                    lookupResults = data.results;
                    stampLastLookup(data.results);
                }
                renderLookupResults();
            })
            .catch(function(err) {
                document.getElementById('lookup-content').innerHTML =
                    '<p style="color:#ef4444; padding:1rem;">Lookup failed: ' + esc(err.message) + '</p>';
            });
        }

        function openLookupModal() {
            document.getElementById('lookup-modal').classList.remove('hidden');
            document.getElementById('lookup-modal-toolbar').style.display = 'none';
        }

        function closeLookupModal() {
            document.getElementById('lookup-modal').classList.add('hidden');
        }

        function showLookupProgress(current, total) {
            var pct = total > 0 ? Math.round((current / total) * 100) : 0;
            document.getElementById('lookup-content').innerHTML =
                '<div class="lookup-progress">' +
                    '<p>Looking up beer ' + current + ' of ' + total + '...</p>' +
                    '<div class="lookup-progress-bar"><div class="lookup-progress-fill" style="width:' + pct + '%"></div></div>' +
                '</div>';
        }

        function renderLookupResults() {
            document.getElementById('lookup-modal-toolbar').style.display = 'flex';
            var container = document.getElementById('lookup-content');

            if (lookupResults.length === 0) {
                container.innerHTML = '<p style="color:var(--card-paragraph-color); padding:1rem; text-align:center;">No results</p>';
                return;
            }

            // Sort: actionable items first (found + changed or redirect), then no-change, then not-found
            lookupResults.sort(function(a, b) {
                var actionA = a.found && (a.rating_changed || a.redirected_url) ? 0 : (a.found ? 1 : 2);
                var actionB = b.found && (b.rating_changed || b.redirected_url) ? 0 : (b.found ? 1 : 2);
                return actionA - actionB;
            });

            // Stats summary
            var foundChanged = lookupResults.filter(function(r) { return r.found && r.rating_changed; }).length;
            var foundRedirect = lookupResults.filter(function(r) { return r.found && !r.rating_changed && r.redirected_url; }).length;
            var foundSame = lookupResults.filter(function(r) { return r.found && !r.rating_changed && !r.redirected_url; }).length;
            var notFound = lookupResults.filter(function(r) { return !r.found; }).length;

            var html = '<div class="diff-summary" style="margin-bottom:0.75rem;">' +
                '<strong>' + foundChanged + '</strong> with updated ratings, ' +
                (foundRedirect > 0 ? '<strong>' + foundRedirect + '</strong> with redirected URLs, ' : '') +
                '<strong>' + foundSame + '</strong> unchanged, ' +
                '<strong>' + notFound + '</strong> not found' +
                '</div>';

            for (var i = 0; i < lookupResults.length; i++) {
                var r = lookupResults[i];
                var beer = currentBeers.find(function(b) { return b.id === r.id; });
                var beerName = beer ? (beer.name || r.id) : r.id;
                var beerBrewery = beer ? (beer.brewery || '') : '';

                html += '<div class="lookup-result-item" id="lookup-row-' + esc(r.id) + '">';
                html += '<div class="lookup-result-info">';
                html += '<div class="lookup-result-beer">' + esc(beerName) + '</div>';
                html += '<div class="lookup-result-meta">' + esc(beerBrewery);
                if (r.untappd_name) {
                    html += ' &rarr; Untappd: ' + esc(r.untappd_name);
                    if (r.untappd_brewery) html += ' by ' + esc(r.untappd_brewery);
                }
                if (beer && beer.last_lookup) {
                    html += ' &middot; Checked ' + timeAgo(beer.last_lookup);
                }
                if (beer && beer.last_update) {
                    html += ' &middot; Updated ' + timeAgo(beer.last_update);
                }
                html += '</div>';
                html += '</div>';

                if (r.found) {
                    // Confidence badge
                    var confClass = r.confidence >= 80 ? 'confidence-high' : (r.confidence >= 50 ? 'confidence-medium' : 'confidence-low');
                    html += '<div class="lookup-result-values">';
                    html += '<div class="lookup-value-box"><div class="lookup-value-label">Current</div><div class="lookup-value-num">' + (r.current_rating != null ? r.current_rating : '—') + '</div></div>';
                    html += '<span class="lookup-arrow">&rarr;</span>';
                    html += '<div class="lookup-value-box"><div class="lookup-value-label">Untappd</div><div class="lookup-value-num">' + r.untappd_rating + '</div></div>';
                    html += '<div class="lookup-value-box"><div class="lookup-value-label">Match</div><div class="lookup-value-num ' + confClass + '">' + r.confidence + '%</div></div>';
                    html += '</div>';

                    // Show redirect notice when URL changed
                    if (r.redirected_url) {
                        html += '<div style="font-size:0.7rem; color:var(--palette-text-primary); margin:0.25rem 0; word-break:break-all;">' +
                            '&#8618; URL redirected to: <a href="' + esc(r.redirected_url) + '" target="_blank" style="color:var(--link-color);">' + esc(r.redirected_url) + '</a>' +
                            '</div>';
                    }

                    var hasChanges = r.rating_changed || r.redirected_url;
                    if (hasChanges) {
                        html += '<div class="lookup-result-actions" id="lookup-actions-' + esc(r.id) + '">' +
                            '<button class="btn-small btn-success" onclick="window._admin.acceptLookup(\'' + esc(r.id) + '\', ' + i + ')">Accept</button>' +
                            '<button class="btn-small btn-ghost" onclick="window._admin.declineLookup(\'' + esc(r.id) + '\', ' + i + ')">Decline</button>' +
                            '</div>';
                    } else {
                        html += '<span class="lookup-status-badge badge-nochange">No change</span>';
                    }
                } else {
                    // Not found — show search link and manual input
                    html += '<div style="flex:1; min-width:200px;">';
                    if (r.search_url) {
                        html += '<a href="' + esc(r.search_url) + '" target="_blank" style="color:var(--link-color); font-size:0.75rem;">Search on Untappd &nearr;</a>';
                    }
                    html += '<div class="lookup-manual-input">' +
                        '<input type="url" placeholder="Paste Untappd URL..." id="manual-url-' + esc(r.id) + '">' +
                        '<button class="btn-small btn-untappd" onclick="window._admin.manualFetch(\'' + esc(r.id) + '\')">Fetch</button>' +
                        '</div>';
                    html += '</div>';
                    html += '<span class="lookup-status-badge" style="background:rgba(239,68,68,0.15); color:#fca5a5;">Not found</span>';
                }

                html += '</div>';
            }

            container.innerHTML = html;
        }

        function acceptLookup(beerId, resultIdx) {
            var r = lookupResults[resultIdx];
            if (!r) return;

            var beerIdx = currentBeers.findIndex(function(b) { return b.id === beerId; });
            if (beerIdx < 0) return;

            // Update the beer data
            if (r.untappd_rating != null) {
                currentBeers[beerIdx].rating = r.untappd_rating;
            }
            // Prefer the canonical (redirected) URL if available
            var urlToStore = r.redirected_url || r.untappd_url;
            if (urlToStore) {
                currentBeers[beerIdx].untappd = urlToStore;
            }
            currentBeers[beerIdx].last_update = Math.floor(Date.now() / 1000);

            // Mark as modified
            if (!modifiedIds[beerId]) {
                modifiedIds[beerId] = 'modified';
            }

            // Update the row UI
            var actions = document.getElementById('lookup-actions-' + beerId);
            if (actions) {
                actions.innerHTML = '<span class="lookup-status-badge badge-accepted">Accepted</span>';
            }
            var row = document.getElementById('lookup-row-' + beerId);
            if (row) row.classList.add('lookup-accepted');

            renderTable();
        }

        function declineLookup(beerId, resultIdx) {
            var actions = document.getElementById('lookup-actions-' + beerId);
            if (actions) {
                actions.innerHTML = '<span class="lookup-status-badge badge-declined">Declined</span>';
            }
            var row = document.getElementById('lookup-row-' + beerId);
            if (row) row.classList.add('lookup-accepted');
        }

        function acceptAllHigh() {
            for (var i = 0; i < lookupResults.length; i++) {
                var r = lookupResults[i];
                if (r.found && (r.rating_changed || r.redirected_url) && r.confidence >= 80) {
                    acceptLookup(r.id, i);
                }
            }
            showToast('Accepted all high-confidence matches');
        }

        function manualFetch(beerId) {
            var input = document.getElementById('manual-url-' + beerId);
            if (!input) return;
            var url = input.value.trim();
            if (!url || !url.match(/^https:\/\/untappd\.com\/b\//)) {
                showToast('Please enter a valid Untappd beer URL (https://untappd.com/b/...)', 'error');
                return;
            }

            input.disabled = true;
            fetch('admin_api.php?action=untappd_lookup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify([{ id: beerId, manual_url: url }])
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                input.disabled = false;
                if (data.status === 'success' && data.results && data.results[0]) {
                    var r = data.results[0];
                    // Replace the result in our array
                    var idx = lookupResults.findIndex(function(lr) { return lr.id === beerId; });
                    if (idx >= 0) {
                        lookupResults[idx] = r;
                    } else {
                        lookupResults.push(r);
                    }
                    renderLookupResults();
                } else {
                    showToast('Could not fetch rating from that URL', 'error');
                }
            })
            .catch(function(err) {
                input.disabled = false;
                showToast('Fetch error: ' + err.message, 'error');
            });
        }

        // --- Bad Rater Detection ---
        var raterResults = null;

        function toggleRaters() {
            var content = document.getElementById('raters-content');
            var icon = document.getElementById('raters-toggle');
            content.classList.toggle('collapsed');
            icon.classList.toggle('rotated');
        }

        function scanBadRaters() {
            var btn = document.getElementById('btn-scan-raters');
            var status = document.getElementById('rater-scan-status');
            btn.disabled = true;
            btn.textContent = 'Scanning...';
            status.textContent = 'Analysing ratings log...';

            fetch('admin_api.php?action=bad_raters', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    raterResults = data;
                    renderRaterResults(data);
                    status.textContent = 'Scan complete. ' + data.summary.total_sessions_analyzed + ' sessions analysed.';
                } else {
                    showToast('Error: ' + (data.message || 'Scan failed'), 'error');
                    status.textContent = 'Scan failed.';
                }
            })
            .catch(function(err) {
                showToast('Network error: ' + err.message, 'error');
                status.textContent = 'Scan failed.';
            })
            .finally(function() {
                btn.disabled = false;
                btn.textContent = 'Scan for Bad Raters';
            });
        }

        function getSortedRaters() {
            if (!raterResults || !raterResults.flagged) return [];
            var raters = [];
            var flagged = raterResults.flagged;
            for (var sid in flagged) {
                if (Object.prototype.hasOwnProperty.call(flagged, sid)) {
                    raters.push(flagged[sid]);
                }
            }
            raters.sort(function(a, b) {
                return b.patterns.length - a.patterns.length || b.total_raw_entries - a.total_raw_entries;
            });
            return raters;
        }

        function renderRaterResults(data) {
            var container = document.getElementById('raters-results');
            var raters = getSortedRaters();

            container.textContent = '';

            if (raters.length === 0) {
                var empty = document.createElement('p');
                empty.style.cssText = 'color:var(--card-paragraph-color); padding:1rem; text-align:center;';
                empty.textContent = 'No suspicious raters detected.';
                container.appendChild(empty);
                return;
            }

            // Summary bar (only server-controlled numbers, safe to build with textContent)
            var infoDiv = document.createElement('div');
            infoDiv.className = 'rater-scan-info';
            var pcounts = data.summary.pattern_counts || {};
            var parts = [];
            for (var p in pcounts) {
                if (Object.prototype.hasOwnProperty.call(pcounts, p)) parts.push(p + ': ' + pcounts[p]);
            }
            infoDiv.textContent = raters.length + ' suspicious session(s) flagged out of ' + data.summary.total_sessions_analyzed + ' analysed. Pattern breakdown: ' + parts.join(', ');
            container.appendChild(infoDiv);

            // Build table with safe DOM construction
            var wrapper = document.createElement('div');
            wrapper.style.cssText = 'max-height:50vh; overflow-y:auto;';
            var table = document.createElement('table');
            table.className = 'rater-table';

            var thead = document.createElement('thead');
            var headRow = document.createElement('tr');
            ['Session ID','Patterns','Confidence','Raw','Deduped','Scored','Action'].forEach(function(txt) {
                var th = document.createElement('th');
                th.textContent = txt;
                headRow.appendChild(th);
            });
            thead.appendChild(headRow);
            table.appendChild(thead);

            var tbody = document.createElement('tbody');
            var frag = document.createDocumentFragment();

            for (var i = 0; i < raters.length; i++) {
                var r = raters[i];
                var sid = r.session_id;
                var shortSid = sid.length > 20 ? sid.substring(0, 20) + '...' : sid;
                var tr = document.createElement('tr');
                if (r.excluded) tr.className = 'excluded-row';

                // Session ID cell
                var tdSid = document.createElement('td');
                tdSid.title = sid;
                var code = document.createElement('code');
                code.style.fontSize = '0.75rem';
                code.textContent = shortSid;
                tdSid.appendChild(code);
                tr.appendChild(tdSid);

                // Patterns cell
                var tdPat = document.createElement('td');
                for (var j = 0; j < r.patterns.length; j++) {
                    var pat = r.patterns[j];
                    var badge = document.createElement('span');
                    badge.className = 'rater-pattern-badge ' + (pat.severity === 'High' ? 'rater-pattern-high' : (pat.severity === 'Medium' ? 'rater-pattern-medium' : 'rater-pattern-low'));
                    badge.title = pat.detail;
                    badge.textContent = pat.name;
                    tdPat.appendChild(badge);
                }
                tr.appendChild(tdPat);

                // Confidence cell
                var tdConf = document.createElement('td');
                var confSpan = document.createElement('span');
                confSpan.className = r.confidence === 'High' ? 'rater-conf-high' : (r.confidence === 'Medium' ? 'rater-conf-medium' : 'rater-conf-low');
                confSpan.textContent = r.confidence;
                tdConf.appendChild(confSpan);
                tr.appendChild(tdConf);

                // Numeric cells
                ['total_raw_entries','total_deduped_ratings','total_scored_ratings'].forEach(function(key) {
                    var td = document.createElement('td');
                    td.textContent = String(r[key]);
                    tr.appendChild(td);
                });

                // Action cell — use data attributes instead of inline onclick with user data
                var tdAction = document.createElement('td');
                var btn = document.createElement('button');
                btn.setAttribute('data-sid', sid);
                if (r.excluded) {
                    btn.className = 'btn-small btn-success';
                    btn.textContent = 'Include';
                    btn.setAttribute('data-exclude', 'false');
                    tdAction.appendChild(btn);
                    var exBadge = document.createElement('span');
                    exBadge.className = 'rater-excluded-badge';
                    exBadge.textContent = 'Excluded';
                    tdAction.appendChild(document.createTextNode(' '));
                    tdAction.appendChild(exBadge);
                } else {
                    btn.className = 'btn-small btn-secondary';
                    btn.textContent = 'Exclude';
                    btn.setAttribute('data-exclude', 'true');
                    tdAction.appendChild(btn);
                }
                tr.appendChild(tdAction);
                frag.appendChild(tr);
            }

            tbody.appendChild(frag);
            table.appendChild(tbody);
            wrapper.appendChild(table);
            container.appendChild(wrapper);

            // Event delegation: handle exclude/include clicks safely
            wrapper.addEventListener('click', function(e) {
                var btn = e.target.closest('button[data-sid]');
                if (!btn) return;
                var clickedSid = btn.getAttribute('data-sid');
                var doExclude = btn.getAttribute('data-exclude') === 'true';
                toggleExcludeRater(clickedSid, doExclude);
            });
        }

        function toggleExcludeRater(sessionId, exclude) {
            var flagged = raterResults ? raterResults.flagged : {};
            var rater = flagged[sessionId];
            var patternNames = rater ? rater.patterns.map(function(p) { return p.name; }) : [];

            fetch('admin_api.php?action=exclude_rater', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ session_id: sessionId, exclude: exclude, patterns: patternNames })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.status === 'success') {
                    if (raterResults && raterResults.flagged[sessionId]) {
                        raterResults.flagged[sessionId].excluded = exclude;
                    }
                    renderRaterResults(raterResults);
                    showToast(exclude ? 'Rater excluded from stats' : 'Rater included in stats');
                } else {
                    showToast('Error: ' + (data.message || 'Failed'), 'error');
                }
            })
            .catch(function(err) {
                showToast('Network error: ' + err.message, 'error');
            });
        }

        // Expose functions to onclick handlers
        window._admin = {
            startEdit: startEdit,
            deleteBeer: deleteBeer,
            showDiff: showDiff,
            restoreVersion: restoreVersion,
            singleLookup: singleLookup,
            toggleLookupMenu: toggleLookupMenu,
            bulkLookup: bulkLookup,
            closeLookupModal: closeLookupModal,
            acceptLookup: acceptLookup,
            declineLookup: declineLookup,
            acceptAllHigh: acceptAllHigh,
            manualFetch: manualFetch,
            showPendingDiff: showPendingDiff,
            toggleRaters: toggleRaters,
            scanBadRaters: scanBadRaters,
            toggleExcludeRater: toggleExcludeRater
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
