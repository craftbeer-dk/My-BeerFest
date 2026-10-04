<?php
// Shared beer catalog helpers for the logging endpoints and the stats dashboard.

const BEER_ID_PATTERN = '/^[a-zA-Z0-9_-]{1,100}$/';
const SESSION_ID_PATTERN = '/^[a-zA-Z0-9_.-]{1,64}$/';

/**
 * Loads beers.json as a map of id => beer. Entries without a scalar id are skipped.
 */
function loadBeerCatalog(): array {
    $beers = json_decode((string) @file_get_contents(__DIR__ . '/data/beers.json'), true);
    $catalog = [];
    if (!is_array($beers)) {
        return $catalog;
    }
    foreach ($beers as $beer) {
        if (!is_array($beer) || !isset($beer['id']) || !is_scalar($beer['id'])) {
            continue;
        }
        $catalog[(string) $beer['id']] = $beer;
    }
    return $catalog;
}

function isValidBeerId($id, array $catalog): bool {
    return is_string($id) && preg_match(BEER_ID_PATTERN, $id) && isset($catalog[$id]);
}

function isValidSessionId($id): bool {
    return is_string($id) && preg_match(SESSION_ID_PATTERN, $id);
}
