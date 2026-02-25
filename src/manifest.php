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
            'purpose' => 'any maskable'
        ],
        [
            'src' => $icon512,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any maskable'
        ]
    ]
];

// Encode the array to JSON and output it
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
