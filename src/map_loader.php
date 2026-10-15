<?php
// Loads optional floor-plan maps from data/maps/*.svg for inlining into index.php.
// The SVG is kept as authored; only things that could run code or load external
// resources are removed. See "Floor maps" in readme.md for the file convention.

const MAP_MAX_FILES = 10;
const MAP_MAX_BYTES = 2 * 1024 * 1024;
const MAP_SVG_NS = 'http://www.w3.org/2000/svg';
const MAP_XLINK_NS = 'http://www.w3.org/1999/xlink';

const MAP_ALLOWED_ELEMENTS = [
    'svg', 'g', 'defs', 'title', 'desc', 'metadata', 'symbol', 'use', 'switch', 'a', 'view',
    'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path',
    'text', 'tspan', 'textPath', 'clipPath', 'mask', 'pattern', 'marker',
    'linearGradient', 'radialGradient', 'stop', 'image', 'style', 'filter',
];

/**
 * @return array<int, array{name: string, svg: string}>
 */
function loadFestivalMaps(string $dir): array
{
    $files = is_dir($dir) ? glob(rtrim($dir, '/') . '/*.svg') : [];
    if (!$files) {
        return [];
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    $files = array_slice($files, 0, MAP_MAX_FILES);

    $signature = '';
    foreach ($files as $file) {
        $signature .= $file . '|' . filemtime($file) . '|' . filesize($file) . ';';
    }
    $cacheFile = sys_get_temp_dir() . '/mybeerfest-maps-' . md5($signature . filemtime(__FILE__)) . '.json';
    if (is_readable($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $maps = [];
    foreach ($files as $file) {
        $map = sanitizeMapSvg((string) file_get_contents($file), basename($file, '.svg'));
        if ($map === null) {
            error_log('MyBeerFest: skipping invalid map file ' . basename($file));
            continue;
        }
        $maps[] = $map;
    }

    foreach (glob(sys_get_temp_dir() . '/mybeerfest-maps-*.json') ?: [] as $stale) {
        @unlink($stale);
    }
    @file_put_contents($cacheFile, json_encode($maps), LOCK_EX);
    return $maps;
}

/**
 * @return array{name: string, svg: string}|null
 */
function sanitizeMapSvg(string $xml, string $fallbackName): ?array
{
    if ($xml === '' || strlen($xml) > MAP_MAX_BYTES || stripos($xml, '<!ENTITY') !== false) {
        return null;
    }

    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $doc->documentElement;
    if (!$loaded || !$root || $root->localName !== 'svg' || $root->namespaceURI !== MAP_SVG_NS) {
        return null;
    }

    $name = trim($root->getAttribute('data-location'));
    if ($name === '') {
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'title') {
                $name = trim($child->textContent);
                break;
            }
        }
    }
    if ($name === '') {
        $name = ucfirst(trim(str_replace(['-', '_'], ' ', preg_replace('/^\d+[-_ ]*/', '', $fallbackName))));
    }

    if (!$root->hasAttribute('viewBox')) {
        $w = (float) $root->getAttribute('width');
        $h = (float) $root->getAttribute('height');
        if ($w <= 0 || $h <= 0) {
            return null;
        }
        $root->setAttribute('viewBox', "0 0 $w $h");
    }

    sanitizeMapNode($root);

    return [
        'name' => $name,
        'svg'  => $doc->saveXML($root),
    ];
}

function sanitizeMapNode(DOMElement $el): void
{
    foreach (iterator_to_array($el->childNodes) as $child) {
        if ($child instanceof DOMCdataSection) {
            $el->replaceChild($el->ownerDocument->createTextNode($child->data), $child);
        } elseif ($child instanceof DOMElement) {
            if ($child->namespaceURI !== MAP_SVG_NS || !isAllowedMapElement($child->localName)) {
                $el->removeChild($child);
            } elseif ($child->localName === 'style') {
                $css = sanitizeMapCss($child->textContent);
                if ($css === null) {
                    $el->removeChild($child);
                } else {
                    $child->textContent = $css;
                }
            } else {
                sanitizeMapNode($child);
            }
        } elseif (!($child instanceof DOMText)) {
            $el->removeChild($child);
        }
    }
    sanitizeMapAttributes($el);
}

function isAllowedMapElement(string $name): bool
{
    return in_array($name, MAP_ALLOWED_ELEMENTS, true) || preg_match('/^fe[A-Z][A-Za-z]+$/', $name);
}

function sanitizeMapAttributes(DOMElement $el): void
{
    foreach (iterator_to_array($el->attributes) as $attr) {
        $name = $attr->localName;
        $value = $attr->value;

        if (stripos($name, 'on') === 0 || preg_match('/(java|vb)script:|data:text|expression\s*\(/i', $value)) {
            $el->removeAttributeNode($attr);
            continue;
        }
        if ($name === 'href' && ($attr->namespaceURI === null || $attr->namespaceURI === MAP_XLINK_NS)) {
            if (!isSafeMapHref($el, $value)) {
                $el->removeAttributeNode($attr);
            }
            continue;
        }
        if ($name === 'style') {
            $style = sanitizeMapCss($value);
            if ($style === null) {
                $el->removeAttributeNode($attr);
            } elseif ($style !== $value) {
                $el->setAttribute('style', $style);
            }
            continue;
        }
        if (stripos($value, 'url(') !== false && preg_match('/url\(\s*[\'"]?\s*(?!#)/i', $value)) {
            $el->removeAttributeNode($attr);
        }
    }
}

function isSafeMapHref(DOMElement $el, string $href): bool
{
    $href = trim($href);
    if (preg_match('/^#[\w.:-]+$/', $href)) {
        return $el->localName !== 'a';
    }
    if ($el->localName !== 'image') {
        return false;
    }
    if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#', $href)) {
        return true;
    }
    // Same-origin image paths only, e.g. /custom/floorplan.png
    return preg_match('#^/?(?!/)[A-Za-z0-9_\-./]+\.(png|jpe?g|gif|webp)$#i', $href) && strpos($href, '..') === false;
}

/**
 * Keeps CSS as written but drops @import and any url() that isn't a local
 * reference or a data: URI. Returns null for CSS that can't be checked safely.
 */
function sanitizeMapCss(string $css): ?string
{
    if (strpos($css, '\\') !== false || preg_match('/expression\s*\(|behavior\s*:|-moz-binding|(java|vb)script:/i', $css)) {
        return null;
    }
    $css = preg_replace('/@import[^;]*;?/i', '', $css);
    return preg_replace_callback('/url\(\s*([\'"]?)(.*?)\1\s*\)/is', function ($m) {
        $target = trim($m[2]);
        return ($target !== '' && ($target[0] === '#' || stripos($target, 'data:') === 0)) ? $m[0] : 'none';
    }, $css);
}
