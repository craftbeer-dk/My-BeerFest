<?php
// Set the content type to application/json
header('Content-Type: application/json');

// Load environment variables with fallbacks
$festivalTitle = getenv('FESTIVAL_TITLE') ?: 'My BeerFest';
$festivalTitleShort = getenv('FESTIVAL_TITLE_SHORT') ?: 'My BeerFest';
$festivalInfoText = getenv('FESTIVAL_INFO_TEXT') ?: 'My BeerFest';
require_once __DIR__ . '/config/theme_color.php';
$themeColor = getThemeColor();

// Resolve icon paths: use custom icons if available, otherwise defaults
$icon192 = file_exists(__DIR__ . '/custom/icon-192.png') ? 'custom/icon-192.png' : 'images/icon-192.png';
$icon512 = file_exists(__DIR__ . '/custom/icon-512.png') ? 'custom/icon-512.png' : 'images/icon-512.png';

// Maskable icons for Android adaptive icons (round, squircle, etc.)
// These should have the logo within the inner 80% safe zone with a solid background filling the rest.
// Falls back to the regular icons if dedicated maskable versions don't exist.
$maskable192 = file_exists(__DIR__ . '/custom/icon-192-maskable.png') ? 'custom/icon-192-maskable.png'
    : (file_exists(__DIR__ . '/images/icon-192-maskable.png') ? 'images/icon-192-maskable.png' : $icon192);
$maskable512 = file_exists(__DIR__ . '/custom/icon-512-maskable.png') ? 'custom/icon-512-maskable.png'
    : (file_exists(__DIR__ . '/images/icon-512-maskable.png') ? 'images/icon-512-maskable.png' : $icon512);

// Create the manifest data as a PHP array
$manifest = [
    'name' => $festivalTitle,
    'short_name' => $festivalTitleShort,
    'description' => $festivalInfoText,
    'start_url' => '.',
    'display' => 'standalone',
    'background_color' => $themeColor,
    'theme_color' => $themeColor,
    'icons' => [
        [
            'src' => $icon192,
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $icon512,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $maskable192,
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'maskable'
        ],
        [
            'src' => $maskable512,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable'
        ]
    ]
];

// Encode the array to JSON and output it
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
