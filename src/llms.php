<?php
header('Content-Type: text/markdown; charset=utf-8');

$appLanguage = getenv('APP_LANGUAGE') ?: 'da';
$langFile = __DIR__ . "/lang/{$appLanguage}.conf";
if (!file_exists($langFile)) {
    $langFile = __DIR__ . '/lang/en.conf';
}
$translations = file_exists($langFile) ? parse_ini_file($langFile) : [];

$festivalTitle = getenv('FESTIVAL_TITLE') ?: ($translations['default_festival_title'] ?? 'My BeerFest');
$festivalInfoText = getenv('FESTIVAL_INFO_TEXT') ?: ($translations['default_info_text'] ?? '');
$seoDescription = getenv('FESTIVAL_SEO_DESCRIPTION') ?: $festivalInfoText;
$contactEmail = getenv('CONTACT_EMAIL') ?: '';

$summary = trim($seoDescription) !== ''
    ? trim($seoDescription)
    : 'Beer festival catalog and rating app.';

echo "# {$festivalTitle}\n\n";
echo "> {$summary}\n\n";
echo "Progressive Web App for browsing, filtering, and rating beers at the festival. ";
echo "Ratings are stored locally in the visitor's browser — no accounts, no server-side personal data.\n\n";

echo "## Beer catalog\n\n";
echo "- [Beer catalog (JSON)](/data/beers.json): Machine-readable list of every beer with brewery, style, ABV, country, session, and Untappd link. Primary structured data source.\n";
echo "- [Country flag map (JSON)](/data/flags.json): Country → emoji flag lookup used by the UI.\n\n";

echo "## Pages\n\n";
echo "- [Festival home](/): Browse, filter, search, and rate beers.\n";
echo "- [Privacy policy](/privacy-policy.php): GDPR privacy policy and data handling.\n";

if ($contactEmail !== '') {
    echo "\n## Contact\n\n";
    echo "- {$contactEmail}\n";
}
