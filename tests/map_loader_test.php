<?php
// Unit tests for src/map_loader.php (floor-map SVG sanitizer).
// Run inside the php container: docker compose exec -T php php < tests/map_loader_test.php

require '/var/www/html/map_loader.php';

$failed = 0;
function check(string $desc, bool $ok): void
{
    global $failed;
    if (!$ok) {
        $failed++;
    }
    printf("  %s  %s\n", $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m", $desc);
}

$svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="100" height="50" onload="alert(1)" data-location="Main Hall">
  <title>Ignored title</title>
  <style>
    @import url(https://evil.example/x.css);
    @font-face { font-family: X; src: url(data:font/woff2;base64,AAAA) format('woff2'); }
    .st0 { fill: #ff0000; }
    .st1 { fill: url(#grad); }
    .st2 { background: url(https://evil.example/track.png); }
  </style>
  <defs><linearGradient id="grad"><stop offset="0" stop-color="#000"/></linearGradient></defs>
  <script>alert(1)</script>
  <foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>
  <a href="javascript:alert(1)"><rect id="linked" width="1" height="1"/></a>
  <rect class="st0 page" id="bg" fill="url(#grad)" onclick="alert(1)" width="100" height="50"/>
  <rect id="tracked" fill="url(https://evil.example/x.svg#p)" width="1" height="1"/>
  <rect style="fill:red;background:url(https://evil.example/t.png)" width="1" height="1"/>
  <use href="#bg"/>
  <use xlink:href="https://evil.example/sprite.svg#a"/>
  <image href="/custom/plan.png" width="10" height="10"/>
  <image href="data:image/png;base64,iVBORw0KGgo=" width="1" height="1"/>
  <image href="data:image/svg+xml;base64,PHN2Zz4=" width="1" height="1"/>
  <image href="https://evil.example/x.png" width="1" height="1"/>
  <animate attributeName="href" to="javascript:alert(1)"/>
  <g id="legend"><text>Legend</text></g>
  <g class="stand" data-stand="7" data-location="Main Hall #7" data-name="Brewery"><title>7 Brewery</title><circle cx="5" cy="5" r="1"/><text x="5" y="5">&lt;/template&gt;&lt;script&gt;7</text></g>
</svg>
SVG;

echo "Map sanitizer\n";
$map = sanitizeMapSvg($svg, '01-main-hall');
check('valid SVG is accepted', $map !== null);
$out = $map['svg'] ?? '';
check('name comes from data-location', ($map['name'] ?? '') === 'Main Hall');
check('viewBox is derived from width/height', strpos($out, 'viewBox="0 0 100 50"') !== false);
check('script elements are removed', stripos($out, '<script') === false);
check('event handler attributes are removed', !preg_match('/\son[a-z]+=/i', $out));
check('foreignObject is removed', stripos($out, 'foreignObject') === false);
check('animation elements are removed', strpos($out, '<animate') === false);
check('link targets are removed but the content is kept', stripos($out, 'javascript:') === false && strpos($out, 'id="linked"') !== false);
check('no external URLs survive', stripos($out, 'evil.example') === false);
check('attributes with an external url() are removed', strpos($out, 'id="tracked" width') !== false);
check('SVG data URIs are removed from images', strpos($out, 'data:image/svg') === false);
check('ids, classes and local references are kept as authored', strpos($out, 'class="st0 page" id="bg" fill="url(#grad)"') !== false && strpos($out, 'href="#bg"') !== false);
check('same-origin image paths are kept', strpos($out, 'href="/custom/plan.png"') !== false);
check('raster data URIs are kept', strpos($out, 'data:image/png;base64') !== false);
check('the legend is kept', strpos($out, 'id="legend"') !== false);
check('stand attributes and titles are kept', strpos($out, 'data-stand="7" data-location="Main Hall #7" data-name="Brewery"') !== false && strpos($out, '<title>7 Brewery</title>') !== false);
check('text content stays escaped', strpos($out, '&lt;/template&gt;') !== false);
check('embedded CSS rules are kept as written', strpos($out, '.st0 { fill: #ff0000; }') !== false && strpos($out, '.st1 { fill: url(#grad); }') !== false);
check('embedded data: fonts are kept', strpos($out, 'url(data:font/woff2;base64,AAAA)') !== false);
check('CSS @import is removed', stripos($out, '@import') === false);
check('external CSS url() is neutralised', strpos($out, '.st2 { background: none; }') !== false);

$escaped = sanitizeMapSvg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><style>.a { background: u\72l(https://evil.example/x) }</style><rect class="a"/></svg>', 'x');
check('CSS with escapes is dropped', $escaped !== null && strpos($escaped['svg'], '<style') === false);

$entity = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><text>&x;</text></svg>';
check('documents declaring entities are rejected', sanitizeMapSvg($entity, 'x') === null);
check('non-SVG documents are rejected', sanitizeMapSvg('<html><body/></html>', 'x') === null);
check('malformed XML is rejected', sanitizeMapSvg('<svg xmlns="http://www.w3.org/2000/svg"><g>', 'x') === null);
check('SVG without viewBox or size is rejected', sanitizeMapSvg('<svg xmlns="http://www.w3.org/2000/svg"/>', 'x') === null);

$titled = sanitizeMapSvg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><title>Upstairs</title></svg>', '02-upstairs');
check('name falls back to <title>', ($titled['name'] ?? '') === 'Upstairs');
$plain = sanitizeMapSvg('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>', '03-beer_garden');
check('name falls back to the file name', ($plain['name'] ?? '') === 'Beer garden');

echo $failed === 0 ? "\nAll map sanitizer tests passed.\n" : "\n$failed map sanitizer test(s) failed.\n";
exit($failed === 0 ? 0 : 1);
