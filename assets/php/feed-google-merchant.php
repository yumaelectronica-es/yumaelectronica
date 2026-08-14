<?php
/**
 * Yuma Electrónica — Google Merchant Center product feed.
 *
 * Serves a Google Shopping RSS 2.0 feed built from
 * assets/js/products-feed-data.json (a static snapshot of the catalog
 * generated when this feed was built). This is a static HTML catalog with
 * no server-side product database, so there's no live stock/availability
 * signal — every product is reported as in_stock. Product "disabled"
 * status set from the admin panel lives only in the admin's own browser
 * (localStorage), so it can't be reflected here either.
 */
header('Content-Type: application/xml; charset=utf-8');

$siteUrl = 'https://www.yumaelectronica.es';
$dataFile = __DIR__ . '/../js/products-feed-data.json';

function xml_esc($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

$products = [];
if (is_file($dataFile)) {
    $decoded = json_decode(file_get_contents($dataFile), true);
    if (is_array($decoded)) $products = $decoded;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss xmlns:g="http://base.google.com/ns/1.0" version="2.0">' . "\n";
echo '<channel>' . "\n";
echo '<title>' . xml_esc('Yuma Electrónica — Catálogo') . '</title>' . "\n";
echo '<link>' . xml_esc($siteUrl) . '</link>' . "\n";
echo '<description>' . xml_esc('Feed de productos de Yuma Electrónica para Google Merchant Center') . '</description>' . "\n";

foreach ($products as $p) {
    $id = $p['id'] ?? '';
    $name = $p['name'] ?? '';
    $brand = $p['brand'] ?? '';
    $price = isset($p['price']) ? number_format((float) $p['price'], 2, '.', '') : '0.00';
    $image = $p['image'] ?? '';
    $path = $p['path'] ?? '';
    $description = $p['description'] ?? $name;
    $gtin = $p['gtin'] ?? '';
    if (!$id || !$path) continue;

    $link = $siteUrl . '/producto/' . $path . '.html';
    $imageLink = $image ? $siteUrl . $image : '';

    echo '<item>' . "\n";
    echo '<g:id>' . xml_esc($id) . '</g:id>' . "\n";
    echo '<title>' . xml_esc($name) . '</title>' . "\n";
    echo '<description>' . xml_esc($description) . '</description>' . "\n";
    echo '<link>' . xml_esc($link) . '</link>' . "\n";
    if ($imageLink) echo '<g:image_link>' . xml_esc($imageLink) . '</g:image_link>' . "\n";
    echo '<g:availability>in_stock</g:availability>' . "\n";
    echo '<g:price>' . xml_esc($price . ' EUR') . '</g:price>' . "\n";
    if ($brand) echo '<g:brand>' . xml_esc($brand) . '</g:brand>' . "\n";
    echo '<g:condition>new</g:condition>' . "\n";
    if ($gtin) {
        echo '<g:gtin>' . xml_esc($gtin) . '</g:gtin>' . "\n";
    } else {
        echo '<g:identifier_exists>no</g:identifier_exists>' . "\n";
    }
    echo '</item>' . "\n";
}

echo '</channel>' . "\n";
echo '</rss>' . "\n";
