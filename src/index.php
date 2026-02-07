<?php
// PHP part: Handles environment variables and loads JSON data.
ob_start(); // Start output buffering

session_start();

// Generate a unique session ID if not already set.
if (!isset($_SESSION['session_id'])) {
    $_SESSION['session_id'] = uniqid('user_', true);
}

// Set application language from environment variable, default to 'da'.
$appLanguage = getenv('APP_LANGUAGE') ?: 'da';

// Load language strings from the appropriate config file.
$langFile = __DIR__ . "/lang/{$appLanguage}.conf";
$translations = [];
if (file_exists($langFile)) {
    $translations = parse_ini_file($langFile);
} else {
    // Fallback to English if the specified language file doesn't exist.
    $appLanguage = 'en';
    $langFile = __DIR__ . "/lang/{$appLanguage}.conf";
    if (file_exists($langFile)) {
        $translations = parse_ini_file($langFile);
    } else {
        die("Error: Language files not found. Please ensure lang/da.conf and lang/en.conf exist.");
    }
}

// Load configuration from environment variables with fallbacks.
$festivalTitle = getenv('FESTIVAL_TITLE') ?: $translations['default_festival_title'];
$festivalInfoText = getenv('FESTIVAL_INFO_TEXT') ?: $translations['default_info_text'];
$enableStatisticsLogging = getenv('ENABLE_STATISTICS_LOGGING') === 'true';
$enableMainstyleFiltering = getenv('ENABLE_MAINSTYLE_FILTERING') === 'true';
$contactEmail = getenv('CONTACT_EMAIL') ?: 'contact@mybeerfest.com';
$themeColor = getenv('THEME_COLOR') ?: '#2B684B';
$festivalTitleShort = getenv('FESTIVAL_TITLE_SHORT') ?: 'Ølfestival';
$devMode = getenv('DEV_MODE') === 'true';

// Prepare PHP data for injection into JavaScript.
$translationsJson = json_encode($translations);
$sessionId = $_SESSION['session_id'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($appLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($festivalTitle); ?></title>
    
    <!-- PWA Manifest and Theme Color -->
    <link rel="manifest" href="manifest.php">
    <meta name="theme-color" content="<?php echo htmlspecialchars($themeColor); ?>">
    
    <!-- Apple PWA specific meta tags -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($festivalTitleShort); ?>">
    <link rel="apple-touch-icon" href="images/icon-192.png">
    <link rel="apple-touch-icon" sizes="512x512" href="images/icon-512.png">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="config/theme.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            line-height: 1.6;
            background-color: var(--background-color);
            color: var(--text-color);
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem;
        }
        .beer-card {
            background-color: var(--card-background-color);
            border: 1px solid var(--card-border-color);
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative; /* Needed for absolute positioning of the star */
        }
        .beer-card h2 {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--card-heading-color);
            margin-bottom: 0.5rem;
            padding-right: 2.5rem; /* Ensure text doesn't overlap with the star */
        }
        .beer-card p {
            font-size: 0.875rem;
            color: var(--card-paragraph-color);
            line-height: 1.3;
            margin-bottom: 0.25rem;
        }
        .favorite-star {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            width: 24px;
            height: 24px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .favorite-star polygon {
            fill: var(--icon-favorite-color);
            stroke: var(--icon-favorite-color);
            stroke-width: 1.5;
            stroke-linejoin: round; /* This creates the soft corners */
            transition: fill 0.2s, stroke 0.2s;
        }
        .favorite-star:hover {
            transform: scale(1.2);
        }
        .favorite-star.favorited polygon {
            fill: var(--icon-favorite-active-color);
            stroke: var(--icon-favorite-active-color);
        }
        .search-section, .filter-sort-section {
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .filter-sort-section label, .search-section label {
            font-weight: 600;
            margin-right: 0.5rem;
            display: block;
            margin-bottom: 0.5rem;
            color: var(--label-color);
        }
        .filter-sort-section select, .filter-sort-section input[type="checkbox"], .rating-select, .search-input {
            padding: 0.5rem;
            border: 1px solid var(--input-border-color);
            border-radius: 0.375rem;
            margin-bottom: 0.5rem;
            background-color: var(--input-background-color);
            color: var(--input-text-color);
            transition: border-color 0.2s, box-shadow 0.2s; /* Added for smooth focus transition */
        }
        .search-input::placeholder {
            color: var(--palette-text-secondary);
        }
        /* Custom focus styles for form elements to match the theme */
        .search-input:focus,
        .filter-sort-section select:focus,
        .rating-select:focus,
        .untappd-button:focus {
            outline: none; /* Remove default browser outline */
            border-color: var(--palette-text-primary); /* Use high-contrast color for border */
            box-shadow: 0 0 3px 1px var(--palette-text-primary); /* Add a subtle glow */
        }
        .search-container {
            position: relative;
            top: 5px;
            display: flex;
            align-items: center;
        }
        .clear-search-btn {
            position: absolute;
            right: 0.25rem;
            top: 42%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            cursor: pointer;
            color: var(--icon-color);
            padding: 0.5rem;
        }
        .clear-search-btn:hover {
            color: var(--icon-hover-color);
        }
        .rating-select {
            width: 100%;
            height: 30px; /* Explicit height */
            padding: 0 0.5rem; /* Adjust padding */
            border-radius: 0.375rem;
            font-size: 0.875rem;
            text-align: center;
        }
        .beer-actions-container {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        .untappd-button, .rating-select {
            flex: 1;
        }
        .untappd-button {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 30px; /* Explicit height */
            padding: 0 0.5rem; /* Adjust padding */
            border: 1px solid var(--input-border-color);
            border-radius: 0.375rem;
            background-color: var(--palette-primary);
            color: var(--palette-text-primary);
            text-decoration: none;
            font-size: 0.875rem;
            transition: background-color 0.2s;
        }
        .untappd-button:hover {
            background-color: var(--palette-interactive);
        }
        .untappd-logo {
            width: 16px;
            height: 16px;
            margin-right: 0.5rem;
            background-color: var(--palette-text-primary);
            -webkit-mask-image: url(/images/untappd.svg);
            mask-image: url(/images/untappd.svg);
            -webkit-mask-size: contain;
            mask-size: contain;
            -webkit-mask-repeat: no-repeat;
            mask-repeat: no-repeat;
        }
        @media (min-width: 640px) {
            .beer-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                gap: 1rem;
            }
        }
        .error-message {
            background-color: #fef2f2;
            color: #ef4444;
            padding: 1rem;
            border: 1px solid #fca5a5;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .info-container {
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-top: 2rem;
            margin-bottom: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
        }
        .info-container p {
            margin-bottom: 1rem;
            color: var(--card-paragraph-color);
        }
        .info-container button {
            background-color: var(--button-primary-background-color);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            border: none;
        }
        .info-container button:hover {
            background-color: var(--button-primary-hover-bg);
        }
        .message-box {
            position: fixed;
            bottom: 1rem;
            left: 50%;
            transform: translateX(-50%);
            background-color: #10b981;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 1000;
            display: none;
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }
        .message-box.active {
            display: block;
            opacity: 1;
        }
        .section-header {
            background-color: var(--header-background-color);
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 600;
            color: var(--header-text-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .section-header:hover {
            background-color: var(--header-hover-bg);
        }
        .section-content {
            max-height: 1500px;
            opacity: 1;
            overflow: hidden;
            transition: max-height 0.5s ease-in-out, opacity 0.3s ease-in-out, padding 0.3s ease-in-out;
            padding: 1rem;
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .section-content.collapsed {
            max-height: 0;
            opacity: 0;
            padding-top: 0;
            padding-bottom: 0;
            border: none;
            box-shadow: none;
        }
        .toggle-icon {
            display: inline-block;
            transition: transform 0.3s ease-in-out;
            font-size: 1.2rem;
            margin-left: auto; /* Pushes the icon to the right */
        }
        .toggle-icon.rotated {
            transform: rotate(-90deg);
        }
        .consent-banner, .update-banner {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: var(--palette-surface, #333);
            color: var(--text-color, white);
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            z-index: 2000;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.2);
            text-align: center;
        }
        .consent-banner.hidden, .update-banner.hidden {
            display: none;
        }
        .consent-banner p, .update-banner p {
            margin: 0;
            font-size: 0.9rem;
        }
        .consent-banner .button-group, .update-banner .button-group {
            display: flex;
            flex-wrap: wrap; /* Allow buttons to wrap on small screens */
            justify-content: center;
            gap: 1rem;
            width: 100%;
        }
        .consent-banner button, .update-banner button {
            background-color: var(--button-primary-background-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            border: none;
            width: auto;
        }
        .consent-banner button:hover, .update-banner button:hover {
            background-color: var(--button-primary-hover-bg);
        }
        .consent-banner .decline-button {
            background-color: var(--button-secondary-bg);
        }
        .consent-banner .decline-button:hover {
            background-color: var(--button-secondary-hover-bg);
        }
        @media (min-width: 768px) {
            .consent-banner, .update-banner {
                flex-direction: row;
                justify-content: center; /* Changed from space-between */
                align-items: center;
                padding: 1rem 2rem;
                gap: 2rem; /* Creates space between text and buttons */
            }
            .consent-banner p, .update-banner p {
                text-align: left;
                flex-grow: 0; /* Prevents text from taking all available space */
            }
        }
        .privacy-link {
            display: block;
            margin-top: 1rem;
            font-size: 0.9rem;
            color: var(--link-color);
            text-decoration: underline;
        }
        .clear-filters-button {
            background-color: var(--button-secondary-bg);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            border: none;
            margin-top: 0.5rem;
            width: 100%;
        }
        .clear-filters-button:hover {
            background-color: var(--button-secondary-hover-bg);
        }
        @media (min-width: 768px) {
            .clear-filters-button {
                width: auto;
                padding-left: 1.5rem;
                padding-right: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="text-4xl font-bold text-center mb-6 p-4 rounded-lg shadow-lg" style="background-color: var(--title-bg-color); color: var(--title-text-color);">
            <?php echo htmlspecialchars($festivalTitle); ?>
        </h1>

        <!-- Filter and Sort Section (collapsible) -->
        <div class="filter-sort-section mb-6">
            <div class="section-header" id="filter-sort-header">
                <h2 class="text-xl font-bold flex-grow text-left"><?php echo htmlspecialchars($translations['filters_and_sorting_heading'] ?? 'Filters & Sorting'); ?></h2>
                <span class="toggle-icon">&#9660;</span>
            </div>
            <div class="section-content" id="filter-sort-content">
                <!-- Filter Controls -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div>
                        <label for="session-filter" class="block"><?php echo htmlspecialchars($translations['filter_by_session'] ?? 'Filter by Session'); ?>:</label>
                        <select id="session-filter" class="w-full">
                            <option value=""><?php echo htmlspecialchars($translations['all_sessions'] ?? 'All Sessions'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="style-filter" class="block"><?php echo htmlspecialchars($translations['filter_by_style'] ?? 'Filter by Style'); ?>:</label>
                        <select id="style-filter" class="w-full">
                            <option value=""><?php echo htmlspecialchars($translations['all_styles'] ?? 'All Styles'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="brewery-filter" class="block"><?php echo htmlspecialchars($translations['filter_by_brewery'] ?? 'Filter by Brewery'); ?>:</label>
                        <select id="brewery-filter" class="w-full">
                            <option value=""><?php echo htmlspecialchars($translations['all_breweries'] ?? 'All Breweries'); ?></option>
                        </select>
                    </div>
                    <div>
                        <label for="country-filter" class="block"><?php echo htmlspecialchars($translations['filter_by_country'] ?? 'Filter by Country'); ?>:</label>
                        <select id="country-filter" class="w-full">
                            <option value=""><?php echo htmlspecialchars($translations['all_countries'] ?? 'All Countries'); ?></option>
                        </select>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mt-4 md:mt-0 col-span-1 md:col-span-2 lg:col-span-4">
                        <div class="flex items-center">
                            <input type="checkbox" id="my-rated-filter" class="mr-2 rounded">
                            <label for="my-rated-filter"><?php echo htmlspecialchars($translations['my_rated_beers'] ?? 'My Rated Beers'); ?></label>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="unrated-filter" class="mr-2 rounded">
                            <label for="unrated-filter"><?php echo htmlspecialchars($translations['unrated_beers'] ?? 'Unrated Beers'); ?></label>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="my-favorites-filter" class="mr-2 rounded">
                            <label for="my-favorites-filter"><?php echo htmlspecialchars($translations['my_favorites'] ?? 'My Favorites'); ?></label>
                        </div>
                    </div>
                </div>
                <button id="clear-filters-button" class="clear-filters-button mt-4 hidden"><?php echo htmlspecialchars($translations['clear_filters_button'] ?? 'Clear Filters'); ?></button>

                <!-- Divider -->
                <hr class="my-6" style="border-color: var(--divider-color);">

                <!-- Sorting Controls -->
                <div>
                    <h3 class="text-lg font-semibold mb-2"><?php echo htmlspecialchars($translations['sorting_heading'] ?? 'Sorting'); ?></h3>
                    <div class="flex flex-col md:flex-row md:items-center gap-4">
                        <select id="sort-by" class="w-full md:w-auto">
                            <option value="name-asc"><?php echo htmlspecialchars($translations['sort_name_asc'] ?? 'Name (A-Z)'); ?></option>
                            <option value="name-desc"><?php echo htmlspecialchars($translations['sort_name_desc'] ?? 'Name (Z-A)'); ?></option>
                            <option value="alc-asc"><?php echo htmlspecialchars($translations['sort_alc_asc'] ?? 'Alcohol (Low-High)'); ?></option>
                            <option value="alc-desc"><?php echo htmlspecialchars($translations['sort_alc_desc'] ?? 'Alcohol (High-Low)'); ?></option>
                            <option value="rating-desc"><?php echo htmlspecialchars($translations['sort_global_rating_desc'] ?? 'Global Rating (High-Low)'); ?></option>
                            <option value="rating-asc"><?php echo htmlspecialchars($translations['sort_global_rating_asc'] ?? 'Global Rating (Low-High)'); ?></option>
                            <option value="my-rating-desc"><?php echo htmlspecialchars($translations['sort_my_rating_desc'] ?? 'My Rating (High-Low)'); ?></option>
                            <option value="my-rating-asc"><?php echo htmlspecialchars($translations['sort_my_rating_asc'] ?? 'My Rating (Low-High)'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <script>
            // Apply collapsed state immediately to avoid flash of expanded content
            (function() {
                try {
                    var s = JSON.parse(localStorage.getItem('beerFestivalSettings'));
                    var shouldCollapse = s ? s.filterSortCollapsed : true;
                    if (shouldCollapse) {
                        var el = document.getElementById('filter-sort-content');
                        el.style.transition = 'none';
                        el.classList.add('collapsed');
                        document.querySelector('#filter-sort-header .toggle-icon').classList.add('rotated');
                        // Force reflow then restore transitions
                        el.offsetHeight;
                        el.style.transition = '';
                    }
                } catch(e) {}
            })();
        </script>

        <!-- Search Section (non-collapsible) -->
        <div class="search-section mb-4">
            <div class="search-container">
                <input type="text" id="search-input" class="w-full search-input pr-12" placeholder="<?php echo htmlspecialchars($translations['search_placeholder'] ?? 'Search by beer name or brewery...'); ?>">
                <button id="clear-search-btn" class="clear-search-btn hidden" aria-label="Clear search">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708z"/>
                    </svg>
                </button>
            </div>
        </div>

        <div id="beer-list" class="beer-grid">
            <!-- Beer cards will be loaded here by JavaScript -->
        </div>

        <!-- Load More Button -->
        <div id="load-more-container" class="flex justify-center mt-8 mb-4 hidden">
            <button id="load-more-button" class="info-container m-0" style="padding: 0.75rem 2rem;">
                <?php echo htmlspecialchars($translations['load_more_button'] ?? 'Load more beers'); ?>
            </button>
        </div>

        <!-- Info Container -->
        <div class="info-container" id="info-container">
            <p class="text-lg font-semibold mb-2"><?php echo htmlspecialchars($festivalInfoText); ?></p>
            <p><?php echo htmlspecialchars($translations['local_storage_info'] ?? 'Your ratings are saved locally in your browser.'); ?></p>
            <div class="flex justify-center flex-wrap gap-4 mt-4 mb-4">
                <button onclick="window.location.href='my_stats.php'"><?php echo htmlspecialchars($translations['my_stats_title'] ?? 'My Statistics'); ?></button>
                <button id="copy-data-button"><?php echo htmlspecialchars($translations['copy_my_ratings_link'] ?? 'Copy Data'); ?></button>
                <button id="import-data-button"><?php echo htmlspecialchars($translations['import_data_button_text'] ?? 'Import Data'); ?></button>
            </div>
            <div id="statistics-notice-container" class="text-sm">
                <!-- Statistics consent text and button will be injected here -->
            </div>
            <a href="privacy-policy.php" target="_blank" class="privacy-link"><?php echo htmlspecialchars($translations['privacy_policy_link_text'] ?? 'Privacy Policy'); ?></a>
            <p class="text-sm mt-4">
                &copy; <?php echo date("Y"); ?> My BeerFest. All rights reserved.
                <br>
                Source code on <a href="https://github.com/not_released_yet" target="_blank" class="untappd-link">GitHub</a>
            </p>
        </div>
    </div>

    <!-- Message Box for user feedback -->
    <div id="message-box" class="message-box"></div>

    <!-- Update Banner -->
    <div id="update-banner" class="update-banner hidden">
        <p><?php echo htmlspecialchars($translations['update_available_text'] ?? 'A new version is available.'); ?></p>
        <div class="button-group">
            <button id="update-app-button"><?php echo htmlspecialchars($translations['update_button_text'] ?? 'Update'); ?></button>
        </div>
    </div>

    <!-- Statistics Consent Banner -->
    <?php if ($enableStatisticsLogging): ?>
    <div id="consent-banner" class="consent-banner hidden">
        <p id="consent-text"><?php echo htmlspecialchars($translations['statistics_consent_text'] ?? 'May we collect anonymous usage statistics to improve the app? Your personal selections are always saved locally in your browser regardless of your choice.'); ?></p>
        <div class="button-group">
            <button id="accept-stats-button"><?php echo htmlspecialchars($translations['accept_cookies'] ?? 'Accept'); ?></button>
            <button id="decline-stats-button" class="decline-button"><?php echo htmlspecialchars($translations['decline_cookies'] ?? 'Decline'); ?></button>
        </div>
    </div>
    <?php endif; ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // --- App State ---
            let allBeers = [];
            let userRatings = {};
            let userFavorites = {};
            let statsConsent = null;
            let countryFlags = {};
            let validBeerIds = new Set();
            let lastFetchTimestamp = 0;
            const REFRESH_INTERVAL = 5 * 60 * 1000; // 5 minutes
            
            // Pagination state
            const ITEMS_PER_PAGE = 12;
            let displayedCount = ITEMS_PER_PAGE;

            // --- Injected PHP Data ---
            const translations = <?php echo $translationsJson; ?>;
            const sessionId = "<?php echo htmlspecialchars($sessionId); ?>";
            const enableStatisticsLogging = <?php echo json_encode($enableStatisticsLogging); ?>;
            const enableMainstyleFiltering = <?php echo json_encode(true); ?>;
            const beerDataUrl = '/data/beers.json';

            // --- DOM Elements ---
            const beerListContainer = document.getElementById('beer-list');
            const loadMoreContainer = document.getElementById('load-more-container');
            const loadMoreButton = document.getElementById('load-more-button');
            const searchInput = document.getElementById('search-input');
            const clearSearchBtn = document.getElementById('clear-search-btn');
            const styleFilter = document.getElementById('style-filter');
            const breweryFilter = document.getElementById('brewery-filter');
            const countryFilter = document.getElementById('country-filter');
            const sessionFilter = document.getElementById('session-filter');
            const sortBy = document.getElementById('sort-by');
            const myRatedFilter = document.getElementById('my-rated-filter');
            const unratedFilter = document.getElementById('unrated-filter');
            const myFavoritesFilter = document.getElementById('my-favorites-filter');
            const copyDataButton = document.getElementById('copy-data-button');
            const importDataButton = document.getElementById('import-data-button');
            const messageBox = document.getElementById('message-box');
            const consentBanner = document.getElementById('consent-banner');
            const acceptStatsButton = document.getElementById('accept-stats-button');
            const declineStatsButton = document.getElementById('decline-stats-button');
            const statsNoticeContainer = document.getElementById('statistics-notice-container');
            const clearFiltersButton = document.getElementById('clear-filters-button');
            const filterSortHeader = document.getElementById('filter-sort-header');
            const filterSortContent = document.getElementById('filter-sort-content');
            const filterSortToggleIcon = filterSortHeader.querySelector('.toggle-icon');

            // --- Functions (full definitions) ---
            
            function saveState() {
                const settings = {
                    search: searchInput.value,
                    style: styleFilter.value,
                    brewery: breweryFilter.value,
                    country: countryFilter.value,
                    session: sessionFilter.value,
                    myRated: myRatedFilter.checked,
                    unrated: unratedFilter.checked,
                    myFavorites: myFavoritesFilter.checked,
                    sortBy: sortBy.value,
                    filterSortCollapsed: filterSortContent.classList.contains('collapsed')
                };

                try {
                    localStorage.setItem('userRatings', JSON.stringify(userRatings));
                    localStorage.setItem('userFavorites', JSON.stringify(userFavorites));
                    if (statsConsent !== null) {
                        localStorage.setItem('statsConsent', statsConsent);
                    }
                    localStorage.setItem('beerFestivalSettings', JSON.stringify(settings));
                } catch (e) {
                    console.error("Could not save data to localStorage", e);
                }
            }

            function loadState() {
                try {
                    userRatings = JSON.parse(localStorage.getItem('userRatings')) || {};
                    userFavorites = JSON.parse(localStorage.getItem('userFavorites')) || {};
                    statsConsent = localStorage.getItem('statsConsent'); // Can be null, 'true', or 'false'

                    const savedSettings = JSON.parse(localStorage.getItem('beerFestivalSettings'));
                    if (savedSettings) {
                        searchInput.value = savedSettings.search || '';
                        styleFilter.value = savedSettings.style || '';
                        breweryFilter.value = savedSettings.brewery || '';
                        countryFilter.value = savedSettings.country || '';
                        sessionFilter.value = savedSettings.session || '';
                        myRatedFilter.checked = savedSettings.myRated || false;
                        unratedFilter.checked = savedSettings.unrated || false;
                        myFavoritesFilter.checked = savedSettings.myFavorites || false;
                        sortBy.value = savedSettings.sortBy || 'name-asc';
                        toggleSection(filterSortContent, filterSortToggleIcon, savedSettings.filterSortCollapsed);
                    } else {
                        toggleSection(filterSortContent, filterSortToggleIcon, true);
                    }
                } catch (e) {
                    console.error("Could not load data from localStorage", e);
                    // Clear potentially corrupted data
                    localStorage.removeItem('userRatings');
                    localStorage.removeItem('userFavorites');
                    localStorage.removeItem('statsConsent');
                    localStorage.removeItem('beerFestivalSettings');
                }
            }
            
            function showMessage(message, type = 'success') {
                messageBox.textContent = message;
                messageBox.className = `message-box active ${type === 'success' ? 'bg-green-500' : 'bg-red-500'}`;
                setTimeout(() => {
                    messageBox.classList.remove('active');
                }, 3000);
            }

            function copyShareableLink() {
                try {
                    const settings = {
                        search: searchInput.value,
                        style: styleFilter.value,
                        brewery: breweryFilter.value,
                        country: countryFilter.value,
                        session: sessionFilter.value,
                        myRated: myRatedFilter.checked,
                        unrated: unratedFilter.checked,
                        myFavorites: myFavoritesFilter.checked,
                        sortBy: sortBy.value,
                        filterSortCollapsed: filterSortContent.classList.contains('collapsed')
                    };
                    const allData = {
                        ratings: userRatings,
                        favorites: userFavorites,
                        consent: statsConsent,
                        settings: settings
                    };
                    const jsonString = JSON.stringify(allData);
                    const base64String = btoa(encodeURIComponent(jsonString));
                    
                    const currentUrl = window.location.origin + window.location.pathname;
                    let shareableLink = `${currentUrl}?data=${base64String}`;
                    if (enableStatisticsLogging && statsConsent === 'true') {
                        shareableLink += `&session_id=${sessionId}`;
                    }
                    
                    const tempInput = document.createElement('textarea');
                    tempInput.value = shareableLink;
                    document.body.appendChild(tempInput);
                    tempInput.select();
                    document.execCommand('copy');
                    document.body.removeChild(tempInput);

                    showMessage(translations['link_copied_success'] ?? 'Link with ratings copied to clipboard!', 'success');
                } catch (error) {
                    console.error('Error copying data link:', error);
                    showMessage(translations['error_copying_link'] ?? 'Error copying link.', 'error');
                }
            }
            
            function processImportedData(encodedData) {
                 try {
                    const jsonString = decodeURIComponent(atob(encodedData));
                    const allData = JSON.parse(jsonString);
                    
                    const importedRatings = allData.ratings || {};
                    const sanitizedRatings = {};
                    for (const beerId in importedRatings) {
                        if (Object.prototype.hasOwnProperty.call(importedRatings, beerId) && validBeerIds.has(beerId)) {
                            sanitizedRatings[beerId] = importedRatings[beerId];
                        }
                    }
                    localStorage.setItem('userRatings', JSON.stringify(sanitizedRatings));

                    const importedFavorites = allData.favorites || {};
                    const sanitizedFavorites = {};
                    for (const beerId in importedFavorites) {
                        if (Object.prototype.hasOwnProperty.call(importedFavorites, beerId) && validBeerIds.has(beerId)) {
                            sanitizedFavorites[beerId] = true;
                        }
                    }
                    localStorage.setItem('userFavorites', JSON.stringify(sanitizedFavorites));

                    if (allData.consent === 'true' || allData.consent === 'false') {
                        localStorage.setItem('statsConsent', allData.consent);
                    }

                    if (allData.settings) {
                        localStorage.setItem('beerFestivalSettings', JSON.stringify(allData.settings));
                    }

                    showMessage(translations['ratings_imported_success'] ?? 'Data imported successfully!', 'success');
                    
                    const newUrl = window.location.origin + window.location.pathname;
                    history.replaceState({}, document.title, newUrl);

                    loadState();
                    renderBeers();
                    updateClearButtonState();

                } catch (e) {
                    console.error("Import error:", e);
                    showMessage(translations['error_importing_ratings_invalid_format'] ?? 'Error importing data: Invalid format.', 'error');
                }
            }

            function importDataFromUrlOnLoad() {
                const urlParams = new URLSearchParams(window.location.search);
                const encodedData = urlParams.get('data');
                if (encodedData) {
                    processImportedData(encodedData);
                }
            }

            function toggleSection(contentElement, iconElement, forceCollapse = null) {
                const isCollapsed = contentElement.classList.contains('collapsed');
                const shouldCollapse = forceCollapse !== null ? forceCollapse : !isCollapsed;
                
                if (shouldCollapse) {
                    contentElement.classList.add('collapsed');
                    iconElement.classList.add('rotated');
                } else {
                    contentElement.classList.remove('collapsed');
                    iconElement.classList.remove('rotated');
                }
            }
            
            function updateClearButtonState() {
                const isSessionActive = sessionFilter.value !== '';
                const areOtherFiltersActive = styleFilter.value !== '' || 
                                              breweryFilter.value !== '' || 
                                              countryFilter.value !== '' || 
                                              searchInput.value !== '' || 
                                              myRatedFilter.checked || 
                                              unratedFilter.checked || 
                                              myFavoritesFilter.checked;

                if (!isSessionActive && !areOtherFiltersActive) {
                    clearFiltersButton.classList.add('hidden');
                } else {
                    clearFiltersButton.classList.remove('hidden');
                    if (areOtherFiltersActive) {
                        clearFiltersButton.textContent = translations['clear_filters_button'] ?? 'Clear Filters';
                    } else {
                        clearFiltersButton.textContent = translations['clear_session_button'] ?? 'Clear Session';
                    }
                }
            }

            function initializeFilters() {
                const stylesToFilter = enableMainstyleFiltering ? allBeers.map(beer => beer.mainstyle) : allBeers.map(beer => beer.style);
                populateSelect(styleFilter, [...new Set(stylesToFilter)].sort());
                populateSelect(breweryFilter, [...new Set(allBeers.map(beer => beer.brewery))].sort());
                populateSelect(countryFilter, [...new Set(allBeers.map(beer => beer.country))].sort());
                populateSelect(sessionFilter, [...new Set(allBeers.map(beer => beer.session))].sort());
            }

            function populateSelect(selectElement, options) {
                options.forEach(option => {
                    const opt = document.createElement('option');
                    opt.value = option;
                    opt.textContent = option;
                    selectElement.appendChild(opt);
                });
            }

            function sendRatingToServer(beerId, beerName, rating, currentSessionId) {
                if (!enableStatisticsLogging || statsConsent !== 'true') return;
                fetch('log_rating.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ beer_id: beerId, beer_name: beerName, rating: rating, session_id: currentSessionId }),
                }).catch(error => console.error('Error sending rating log:', error));
            }

            function logStatsConsentToServer(consent) {
                if (!enableStatisticsLogging) return;
                fetch('log_cookie_consent.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ consent: consent === 'true' }),
                }).catch(error => console.error('Error sending cookie consent log:', error));
            }

            function renderBeers() {
                let filteredBeers = [...allBeers];
                const searchTerm = searchInput.value.trim().replace(/\s+/g, ' ').toLowerCase();
                
                if (searchTerm) {
                    filteredBeers = filteredBeers.filter(beer => beer.name.toLowerCase().includes(searchTerm) || beer.brewery.toLowerCase().includes(searchTerm));
                }
                if (styleFilter.value) {
                    const styleProp = enableMainstyleFiltering ? 'mainstyle' : 'style';
                    filteredBeers = filteredBeers.filter(beer => beer[styleProp] === styleFilter.value);
                }
                if (breweryFilter.value) filteredBeers = filteredBeers.filter(beer => beer.brewery === breweryFilter.value);
                if (countryFilter.value) filteredBeers = filteredBeers.filter(beer => beer.country === countryFilter.value);
                if (sessionFilter.value) filteredBeers = filteredBeers.filter(beer => beer.session === sessionFilter.value);

                if (myRatedFilter.checked) filteredBeers = filteredBeers.filter(beer => userRatings[beer.id] !== undefined);
                if (unratedFilter.checked) filteredBeers = filteredBeers.filter(beer => userRatings[beer.id] === undefined);
                if (myFavoritesFilter.checked) filteredBeers = filteredBeers.filter(beer => userFavorites[beer.id]);

                const sortOption = sortBy.value;
                filteredBeers.sort((a, b) => {
                    const myRatingA = userRatings[a.id] || 0;
                    const myRatingB = userRatings[b.id] || 0;
                    switch (sortOption) {
                        case 'name-asc': return a.name.localeCompare(b.name);
                        case 'name-desc': return b.name.localeCompare(a.name);
                        case 'alc-asc': return (a.alc || 0) - (b.alc || 0);
                        case 'alc-desc': return (b.alc || 0) - (a.alc || 0);
                        case 'rating-desc': return (b.rating || 0) - (a.rating || 0);
                        case 'rating-asc': return (a.rating || 0) - (b.rating || 0);
                        case 'my-rating-desc': return myRatingB - myRatingA;
                        case 'my-rating-asc': return myRatingA - myRatingB;
                        default: return 0;
                    }
                });

                // Pagination visibility logic
                if (filteredBeers.length > displayedCount) {
                    loadMoreContainer.classList.remove('hidden');
                } else {
                    loadMoreContainer.classList.add('hidden');
                }

                // Slice array for current page
                const beersToRender = filteredBeers.slice(0, displayedCount);

                beerListContainer.innerHTML = '';
                if (beersToRender.length === 0) {
                    beerListContainer.innerHTML = `<p class="text-center col-span-full">${translations['no_beers_match'] ?? 'No beers match the current filters.'}</p>`;
                    return;
                }
                
                const fragment = document.createDocumentFragment();
                beersToRender.forEach(beer => {
                    const beerCard = document.createElement('div');
                    beerCard.className = 'beer-card';
                    const userRating = userRatings[beer.id] || '';
                    const isFavorited = userFavorites[beer.id];
                    let displayedStyle = beer.style;
                    if (enableMainstyleFiltering) {
                        displayedStyle = beer.mainstyle + (beer.substyle ? ` (${beer.substyle})` : '');
                    }
                    
                    const alcoholText = beer.alc !== null && beer.alc !== undefined ? ` - ${beer.alc}%` : '';
                    const ratingPlaceholder = translations['rate_beer'] ?? 'Rate this beer';
                    const flagEmoji = countryFlags[beer.country.toLowerCase()] || '';

                    beerCard.innerHTML = `
                        <svg class="favorite-star ${isFavorited ? 'favorited' : ''}" data-beer-id="${beer.id}" title="Favorite" viewBox="0 0 24 24">
                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                        </svg>
                        <h2></h2>
                        <p><strong>${translations['brewery'] ?? 'Brewery'}:</strong> <span class="brewery-text"></span></p>
                        <p><strong>${translations['style'] ?? 'Style'}:</strong> <span class="style-text"></span></p>
                        <p><strong>${translations['session'] ?? 'Session'}:</strong> <span class="session-text"></span></p>
                        <div class="beer-actions-container">
                            <a href="${beer.untappd}" target="_blank" class="untappd-button">
                                <div class="untappd-logo"></div>
                                <span class="global-rating-text"></span>
                            </a>
                            <select class="rating-select" data-beer-id="${beer.id}" data-beer-name="${beer.name}">
                                <option value="">${ratingPlaceholder}</option>
                                ${generateRatingOptions(userRating)}
                            </select>
                        </div>
                    `;

                    beerCard.querySelector('h2').textContent = beer.name + alcoholText;
                    beerCard.querySelector('.brewery-text').textContent = `${beer.brewery} ${flagEmoji}`;
                    beerCard.querySelector('.style-text').textContent = displayedStyle;
                    beerCard.querySelector('.session-text').textContent = beer.session !== undefined ? beer.session : 'N/A';
                    beerCard.querySelector('.global-rating-text').textContent = beer.rating !== null && beer.rating !== undefined ? beer.rating.toFixed(2) : 'N/A';
                    
                    fragment.appendChild(beerCard);
                });
                beerListContainer.appendChild(fragment);

                document.querySelectorAll('.rating-select').forEach(select => {
                    select.addEventListener('change', handleRatingChange);
                });
            }

            function generateRatingOptions(currentRating) {
                let optionsHtml = '';
                for (let i = 5; i >= 0.25; i -= 0.25) {
                    const value = i.toFixed(2);
                    const selected = (parseFloat(currentRating) === parseFloat(value)) ? 'selected' : '';
                    optionsHtml += `<option value="${value}" ${selected}>${value}</option>`;
                }
                return optionsHtml;
            }

            function handleRatingChange(event) {
                const { beerId, beerName } = event.target.dataset;
                const newRating = parseFloat(event.target.value);

                if (beerId) {
                    if (!isNaN(newRating)) {
                        userRatings[beerId] = newRating;
                        sendRatingToServer(beerId, beerName, newRating, sessionId);
                    } else {
                        delete userRatings[beerId];
                    }
                }
                saveState();
                if (myRatedFilter.checked || unratedFilter.checked) {
                    renderBeers();
                }
            }

            function handleFavoriteToggle(event) {
                const starSvg = event.target.closest('.favorite-star');
                if (!starSvg) return;

                const beerId = starSvg.dataset.beerId;
                if (!beerId) return;

                if (userFavorites[beerId]) {
                    delete userFavorites[beerId];
                    starSvg.classList.remove('favorited');
                } else {
                    userFavorites[beerId] = true;
                    starSvg.classList.add('favorited');
                }
                saveState();
                
                if (myFavoritesFilter.checked) {
                    renderBeers();
                }
            }

            function setStatsConsent(consent, fromUserInteraction = false) {
                statsConsent = consent ? 'true' : 'false';
                
                if(consentBanner) consentBanner.classList.add('hidden');

                if (fromUserInteraction) {
                    logStatsConsentToServer(statsConsent);
                    const message = consent ? (translations['stats_accepted_message'] ?? 'Thank you for helping improve the app!') : (translations['stats_declined_message'] ?? 'You have opted out of statistics collection.');
                    showMessage(message, 'success');
                }
                updateStatisticsNotice();
                saveState();
            }

            function updateStatisticsNotice() {
                if (!enableStatisticsLogging) {
                    statsNoticeContainer.innerHTML = `<p>${translations['stats_disabled_by_owner'] ?? 'Statistics collection is disabled by the site owner.'}</p>`;
                    return;
                }

                let noticeHtml = '';
                if (statsConsent === 'true') {
                    noticeHtml = `<p>${translations['stats_notice_accepted'] ?? 'Anonymous usage statistics are being collected.'}</p><button id="change-consent-button" class="text-sm underline">${translations['change_consent_button'] ?? 'Change my choice'}</button>`;
                } else if (statsConsent === 'false') {
                    noticeHtml = `<p>${translations['stats_notice_declined'] ?? 'You are not contributing to anonymous usage statistics.'}</p><button id="change-consent-button" class="text-sm underline">${translations['change_consent_button'] ?? 'Change my choice'}</button>`;
                }
                statsNoticeContainer.innerHTML = noticeHtml;
                
                const changeButton = document.getElementById('change-consent-button');
                if(changeButton) {
                    changeButton.addEventListener('click', () => {
                        if(consentBanner) consentBanner.classList.remove('hidden');
                    });
                }
            }
            
            async function refreshDataIfNeeded() {
                try {
                    const response = await fetch(beerDataUrl + '?t=' + new Date().getTime()); // Cache-busting
                    if (!response.ok) return;

                    const newBeers = await response.json();
                    
                    if (JSON.stringify(newBeers) !== JSON.stringify(allBeers)) {
                        allBeers = newBeers;
                        processBeerData(); 
                        renderBeers();
                        showMessage(translations['beer_list_updated'] ?? "Beer list updated!", 'success');
                    }
                } catch (error) {
                    console.warn("Could not refresh beer list, might be offline.", error);
                }
            }
            
            function processBeerData() {
                validBeerIds = new Set(allBeers.map(beer => beer.id));
                allBeers.forEach(beer => {
                    if (beer.style && typeof beer.style === 'string') {
                        const parts = beer.style.split(' - ');
                        beer.mainstyle = parts[0].trim();
                        beer.substyle = parts.length > 1 ? parts.slice(1).join(' - ').trim() : '';
                    } else {
                        beer.mainstyle = beer.style || 'N/A';
                        beer.substyle = '';
                    }
                });
            }

            function initializeAppListeners() {
                const allFilters = [styleFilter, breweryFilter, countryFilter, sessionFilter, sortBy, myRatedFilter, unratedFilter, myFavoritesFilter];
                
                allFilters.forEach(el => el.addEventListener('input', () => { 
                    displayedCount = ITEMS_PER_PAGE; // Reset to page 1 on filter change
                    saveState(); 
                    renderBeers(); 
                    updateClearButtonState(); 
                }));
                
                searchInput.addEventListener('input', () => {
                    displayedCount = ITEMS_PER_PAGE; // Reset to page 1 on search
                    clearSearchBtn.classList.toggle('hidden', !searchInput.value);
                    saveState();
                    renderBeers();
                    updateClearButtonState();
                });

                clearSearchBtn.addEventListener('click', () => {
                    displayedCount = ITEMS_PER_PAGE;
                    searchInput.value = '';
                    clearSearchBtn.classList.add('hidden');
                    saveState();
                    renderBeers();
                    updateClearButtonState();
                });
                
                // Load More click listener
                loadMoreButton.addEventListener('click', () => {
                    displayedCount += ITEMS_PER_PAGE;
                    renderBeers();
                });

                clearFiltersButton.addEventListener('click', () => {
                    displayedCount = ITEMS_PER_PAGE;
                    const areOtherFiltersActive = styleFilter.value !== '' || 
                                                  breweryFilter.value !== '' || 
                                                  countryFilter.value !== '' || 
                                                  searchInput.value !== '' || 
                                                  myRatedFilter.checked || 
                                                  unratedFilter.checked || 
                                                  myFavoritesFilter.checked;

                    if (areOtherFiltersActive) {
                        searchInput.value = '';
                        styleFilter.value = '';
                        breweryFilter.value = '';
                        countryFilter.value = '';
                        myRatedFilter.checked = false;
                        unratedFilter.checked = false;
                        myFavoritesFilter.checked = false;
                        clearSearchBtn.classList.add('hidden');
                    } else {
                        sessionFilter.value = '';
                    }

                    saveState();
                    renderBeers();
                    updateClearButtonState();
                });

                filterSortHeader.addEventListener('click', () => {
                    toggleSection(filterSortContent, filterSortToggleIcon);
                    setTimeout(saveState, 10); 
                });
                
                copyDataButton.addEventListener('click', copyShareableLink);
                importDataButton.addEventListener('click', () => {
                    const link = prompt(translations['paste_import_link_prompt'] ?? 'Please paste the link to import your data:');
                    if (link) {
                        try {
                            const url = new URL(link);
                            const encodedData = url.searchParams.get('data');
                            if (encodedData) {
                                processImportedData(encodedData);
                            } else {
                                showMessage(translations['error_importing_ratings_no_valid'] ?? 'No valid data found in the link.', 'error');
                            }
                        } catch (e) {
                            showMessage(translations['error_importing_ratings_invalid_format'] ?? 'Invalid link format.', 'error');
                        }
                    }
                });
                
                if (enableStatisticsLogging && consentBanner) {
                    acceptStatsButton.addEventListener('click', () => setStatsConsent(true, true));
                    declineStatsButton.addEventListener('click', () => setStatsConsent(false, true));
                }
                
                beerListContainer.addEventListener('click', (event) => {
                    if (event.target.closest('.favorite-star')) {
                        handleFavoriteToggle(event);
                    }
                });
                
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') {
                        const now = new Date().getTime();
                        if (now - lastFetchTimestamp > REFRESH_INTERVAL) {
                            lastFetchTimestamp = now;
                            refreshDataIfNeeded();
                        }
                    }
                });
            }

            // --- Start Application ---
            Promise.all([
                fetch(beerDataUrl).then(res => res.json()),
                fetch('data/flags.json').then(res => res.json())
            ])
            .then(([beers, flags]) => {
                allBeers = beers;
                countryFlags = flags;
                lastFetchTimestamp = new Date().getTime();
                processBeerData();
                
                initializeAppListeners();
                initializeFilters();
                importDataFromUrlOnLoad();
                loadState();
                
                if (enableStatisticsLogging && statsConsent === null) {
                    if(consentBanner) consentBanner.classList.remove('hidden');
                } else if (enableStatisticsLogging) {
                    updateStatisticsNotice();
                }
                
                renderBeers();
                updateClearButtonState();
                clearSearchBtn.classList.toggle('hidden', !searchInput.value);
            })
            .catch(error => {
                console.error('Failed to load initial data:', error);
                document.getElementById('beer-list').innerHTML = `<div class="error-message">${translations['error_fetching_data'] ?? 'Could not fetch beer data'}</div>`;
            });
        });
    </script>
    <?php if (!$devMode): ?>
    <script>
        // PWA Service Worker Registration
        if ('serviceWorker' in navigator) {
            let newWorker;

            function showUpdateBar() {
                const banner = document.getElementById('update-banner');
                if(banner) banner.classList.remove('hidden');
            }

            navigator.serviceWorker.register('/sw.js').then(reg => {
                reg.addEventListener('updatefound', () => {
                    newWorker = reg.installing;
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                           showUpdateBar();
                        }
                    });
                });
            });

            let refreshing;
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (refreshing) return;
                window.location.reload();
                refreshing = true;
            });

            const updateButton = document.getElementById('update-app-button');
            if(updateButton) {
                updateButton.addEventListener('click', () => {
                    newWorker.postMessage({ action: 'skipWaiting' });
                });
            }
        }
    </script>
    <?php else: ?>
    <script>
        // DEV_MODE: Unregister any existing service worker to avoid stale caches
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(registrations => {
                registrations.forEach(reg => reg.unregister());
            });
        }
    </script>
    <?php endif; ?>
</body>
</html>
<?php
// Get all the generated HTML content from the buffer
$html = ob_get_clean();

// Define a more robust regex pattern that matches HTML comments, including multiline ones.
$pattern = '/<!--[\s\S]*?-->/';

// Remove all comments from the HTML
$html_without_comments = preg_replace($pattern, '', $html);

// Send the cleaned HTML to the browser
echo $html_without_comments;
?>
