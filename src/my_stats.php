<?php
// PHP part: Handles environment variables and loads language strings.
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

// Helper function to safely get translation strings
function t($key, $default = '') {
    global $translations;
    return htmlspecialchars($translations[$key] ?? $default);
}

$festivalTitle = getenv('FESTIVAL_TITLE') ?: (t('default_festival_title', 'My Beerfest'));
$themeColor = getenv('THEME_COLOR') ?: '#2B684B';
$translationsJson = json_encode($translations);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($appLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('my_stats_title', 'My Statistics'); ?> - <?php echo htmlspecialchars($festivalTitle); ?></title>
    
    <link rel="stylesheet" href="dist/style.css">
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

        /* Stats Cards - Matching index.php beer-card / section-content style */
        .stat-card {
            background-color: var(--card-background-color);
            border: 1px solid var(--card-border-color);
            border-radius: 0.5rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--palette-text-primary);
            display: block;
            line-height: 1;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--card-paragraph-color);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        /* Highlight Sections - Matching section-content style */
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

        .beer-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--divider-color);
        }

        .beer-item:last-child {
            border-bottom: none;
        }

        /* Navigation Button - Matching info-container button style */
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

        .no-data {
            text-align: center;
            padding: 3rem;
            background-color: var(--section-background-color);
            border: 1px solid var(--section-border-color);
            border-radius: 0.5rem;
            color: var(--card-paragraph-color);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Reverting Back Link to App Button style -->
        <a href="index.php" class="nav-button">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            <?php echo t('back_to_app_link', 'Back to the app'); ?>
        </a>

        <!-- Header matching index.php -->
        <h1 class="text-4xl font-bold text-center mb-6 p-4 rounded-lg shadow-lg" style="background-color: var(--title-bg-color); color: var(--title-text-color);">
            <?php echo t('my_stats_title', 'My Statistics'); ?>
        </h1>

        <div id="stats-content">
            <!-- Summary Grid -->
            <div class="stats-grid">
                <div class="stat-card">
                    <span id="stat-rated" class="stat-number">0</span>
                    <span class="stat-label"><?php echo t('beers_rated', 'Beers Rated'); ?></span>
                </div>
                <div class="stat-card">
                    <span id="stat-avg" class="stat-number">0.00</span>
                    <span class="stat-label"><?php echo t('average_rating', 'Average Rating'); ?></span>
                </div>
                <div class="stat-card">
                    <span id="stat-favorites" class="stat-number">0</span>
                    <span class="stat-label"><?php echo t('my_favorites', 'Favorites'); ?></span>
                </div>
                <div class="stat-card">
                    <span id="stat-breweries-tried" class="stat-number">0</span>
                    <span class="stat-label"><?php echo t('breweries_tried', 'Breweries Tried'); ?></span>
                </div>
                <div class="stat-card">
                    <span id="stat-breweries-total" class="stat-number">0</span>
                    <span class="stat-label"><?php echo t('total_breweries', 'Breweries Total'); ?></span>
                </div>
                <div class="stat-card">
                    <span id="stat-total" class="stat-number">0</span>
                    <span class="stat-label"><?php echo t('total_beers_at_festival', 'Beers Total'); ?></span>
                </div>
            </div>

            <!-- Highlights -->
            <div id="highlights-container">
                <div class="highlight-section">
                    <h2 class="highlight-title"><?php echo t('highest_rated_beers', 'Highest Rated'); ?></h2>
                    <div id="highest-beers-list"></div>
                </div>

                <div class="highlight-section">
                    <h2 class="highlight-title"><?php echo t('lowest_rated_beers', 'Lowest Rated'); ?></h2>
                    <div id="lowest-beers-list"></div>
                </div>
            </div>
        </div>

        <!-- No Data Message -->
        <div id="no-data-message" class="no-data hidden">
            <p><?php echo t('no_stats_yet', 'You haven\'t rated any beers yet. Go explore the festival!'); ?></p>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const translations = <?php echo $translationsJson; ?>;
            const beerDataUrl = '/data/beers.json';
            
            async function initStats() {
                try {
                    const response = await fetch(beerDataUrl);
                    if (!response.ok) throw new Error('Network response was not ok');
                    const allBeers = await response.json();
                    
                    const userRatings = JSON.parse(localStorage.getItem('userRatings')) || {};
                    const userFavorites = JSON.parse(localStorage.getItem('userFavorites')) || {};
                    const ratedIds = Object.keys(userRatings);
                    
                    if (ratedIds.length === 0) {
                        document.getElementById('stats-content').classList.add('hidden');
                        document.getElementById('no-data-message').classList.remove('hidden');
                        return;
                    }

                    // 1. Calculate General Stats
                    const countRated = ratedIds.length;
                    const countFavorites = Object.keys(userFavorites).length;
                    const ratingsArray = Object.values(userRatings);
                    const sumRatings = ratingsArray.reduce((a, b) => a + b, 0);
                    const avgRating = (sumRatings / countRated).toFixed(2);

                    // 2. Calculate Brewery Stats
                    const festivalBreweries = new Set(allBeers.map(b => b.brewery));
                    
                    const triedBeerIds = new Set(ratedIds);
                    const triedBeers = allBeers.filter(b => triedBeerIds.has(b.id));
                    const triedBreweries = new Set(triedBeers.map(b => b.brewery));

                    // 3. Update DOM Summary
                    document.getElementById('stat-total').textContent = allBeers.length;
                    document.getElementById('stat-rated').textContent = countRated;
                    document.getElementById('stat-avg').textContent = avgRating;
                    document.getElementById('stat-favorites').textContent = countFavorites;
                    document.getElementById('stat-breweries-tried').textContent = triedBreweries.size;
                    document.getElementById('stat-breweries-total').textContent = festivalBreweries.size;

                    // 4. Find Extremes
                    const maxRating = Math.max(...ratingsArray);
                    const minRating = Math.min(...ratingsArray);

                    const highestBeers = [];
                    const lowestBeers = [];

                    allBeers.forEach(beer => {
                        const score = userRatings[beer.id];
                        if (score === maxRating) highestBeers.push({...beer, userScore: score});
                        if (score === minRating) lowestBeers.push({...beer, userScore: score});
                    });

                    // 5. Render Lists
                    renderBeerList('highest-beers-list', highestBeers);
                    renderBeerList('lowest-beers-list', lowestBeers);

                } catch (error) {
                    console.error('Error initializing statistics:', error);
                }
            }

            function renderBeerList(containerId, beers) {
                const container = document.getElementById(containerId);
                if (beers.length === 0) {
                    container.innerHTML = '<p class="text-sm italic opacity-50">N/A</p>';
                    return;
                }

                beers.sort((a, b) => a.name.localeCompare(b.name));

                container.innerHTML = beers.map(beer => `
                    <div class="beer-item">
                        <div>
                            <div class="font-semibold text-palette-text-primary">${beer.name}</div>
                            <div class="text-xs opacity-70">${beer.brewery}</div>
                        </div>
                        <div class="font-bold text-lg">${beer.userScore.toFixed(2)}</div>
                    </div>
                `).join('');
            }

            initStats();
        });
    </script>
</body>
</html>
<?php
$html = ob_get_clean();
// Remove HTML comments for production
echo preg_replace('/<!--[\s\S]*?-->/', '', $html);
?>