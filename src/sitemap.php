<?php
header('Content-Type: application/xml; charset=utf-8');

$envDomain = getenv('DOMAIN') ?: '';
if ($envDomain !== '') {
    $siteUrlBase = preg_match('#^https?://#', $envDomain)
        ? rtrim($envDomain, '/')
        : 'https://' . rtrim($envDomain, '/');
} else {
    $isHttpsCanonical = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $siteUrlBase = $host !== '' ? ($isHttpsCanonical ? 'https://' : 'http://') . $host : '';
}

$today = date('Y-m-d');

$urls = [
    ['loc' => '/',                    'changefreq' => 'daily',   'priority' => '1.0'],
    ['loc' => '/llms.txt',            'changefreq' => 'monthly', 'priority' => '0.5'],
    ['loc' => '/privacy-policy.php',  'changefreq' => 'yearly',  'priority' => '0.3'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    $loc = htmlspecialchars($siteUrlBase . $u['loc'], ENT_XML1, 'UTF-8');
    echo "  <url>\n";
    echo "    <loc>{$loc}</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo "    <changefreq>{$u['changefreq']}</changefreq>\n";
    echo "    <priority>{$u['priority']}</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
