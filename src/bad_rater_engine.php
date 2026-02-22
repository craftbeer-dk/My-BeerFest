<?php
// bad_rater_engine.php — Detection engine for suspicious rating behaviour.
// Implements the 6 patterns defined in BAD_RATERS.md.

/**
 * Analyse the ratings log and return flagged sessions with their triggered patterns.
 *
 * @param  string $ratingsLogPath  Absolute path to the NDJSON ratings log.
 * @return array  { flagged: { session_id => { ... } }, summary: { ... } }
 */
function detectBadRaters(string $ratingsLogPath): array
{
    // ── 1. Parse log into raw + deduplicated structures ──────────────

    $rawEntries = [];   // session_id => [ entry, … ]  (preserves order)
    $deduped    = [];   // session_id => [ beer_id => entry ]  (latest wins)

    // For Outlier Bomber: accumulate per-beer rating sums across all users.
    $beerRatingSum   = [];  // beer_id => float
    $beerRatingCount = [];  // beer_id => int

    $handle = fopen($ratingsLogPath, 'r');
    if (!$handle) {
        return ['flagged' => [], 'summary' => [
            'total_sessions_analyzed' => 0,
            'total_flagged'           => 0,
            'pattern_counts'          => [],
        ]];
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') continue;

        $entry = json_decode($line, true);
        if (!is_array($entry)) continue;

        $sid = $entry['session_id'] ?? '';
        $bid = $entry['beer_id']    ?? '';
        if ($sid === '' || $bid === '') continue;

        $rating = isset($entry['rating']) ? (float) $entry['rating'] : null;
        $ts     = isset($entry['timestamp_unix_ms']) ? (int) $entry['timestamp_unix_ms'] : 0;

        $parsed = [
            'beer_id'           => $bid,
            'rating'            => $rating,
            'timestamp_unix_ms' => $ts,
        ];

        // Raw (append)
        $rawEntries[$sid][] = $parsed;

        // Deduped (overwrite — latest wins because log is chronological)
        $deduped[$sid][$bid] = $parsed;
    }
    fclose($handle);

    // Compute festival-wide per-beer means (scored ratings only, rating > 0).
    foreach ($deduped as $entries) {
        foreach ($entries as $e) {
            if ($e['rating'] !== null && $e['rating'] > 0) {
                $bid = $e['beer_id'];
                $beerRatingSum[$bid]   = ($beerRatingSum[$bid]   ?? 0) + $e['rating'];
                $beerRatingCount[$bid] = ($beerRatingCount[$bid] ?? 0) + 1;
            }
        }
    }
    $beerMeans = [];
    foreach ($beerRatingSum as $bid => $sum) {
        $beerMeans[$bid] = $sum / $beerRatingCount[$bid];
    }

    // ── 2. Run detectors ─────────────────────────────────────────────

    $allFlags = []; // session_id => [ pattern, … ]

    $addFlags = function (array $hits) use (&$allFlags) {
        foreach ($hits as $sid => $pattern) {
            $allFlags[$sid][] = $pattern;
        }
    };

    $addFlags(detectSpamRaters($rawEntries));
    $addFlags(detectFlatLiners($deduped));
    $addFlags(detectBlitzers($rawEntries));
    $addFlags(detectExtremists($deduped));
    $addFlags(detectFlipFloppers($rawEntries));
    $addFlags(detectOutlierBombers($deduped, $beerMeans));

    // ── 3. Build output ──────────────────────────────────────────────

    $flagged       = [];
    $patternCounts = [];

    foreach ($allFlags as $sid => $patterns) {
        $totalRaw    = count($rawEntries[$sid] ?? []);
        $dedupedList = $deduped[$sid] ?? [];
        $totalDedup  = count($dedupedList);
        $totalScored = 0;
        foreach ($dedupedList as $e) {
            if ($e['rating'] !== null && $e['rating'] > 0) $totalScored++;
        }

        // Confidence: 2+ patterns = High; else inherit pattern severity.
        if (count($patterns) >= 2) {
            $confidence = 'High';
        } else {
            $confidence = $patterns[0]['severity'] ?? 'Low';
        }

        $flagged[$sid] = [
            'session_id'            => $sid,
            'patterns'              => $patterns,
            'total_raw_entries'     => $totalRaw,
            'total_deduped_ratings' => $totalDedup,
            'total_scored_ratings'  => $totalScored,
            'confidence'            => $confidence,
        ];

        foreach ($patterns as $p) {
            $name = $p['name'];
            $patternCounts[$name] = ($patternCounts[$name] ?? 0) + 1;
        }
    }

    return [
        'flagged' => $flagged,
        'summary' => [
            'total_sessions_analyzed' => count($rawEntries),
            'total_flagged'           => count($flagged),
            'pattern_counts'          => $patternCounts,
        ],
    ];
}

// ── Detector functions ───────────────────────────────────────────────

/**
 * 1. Spam Rater — 20+ ratings in any 10-minute sliding window.
 */
function detectSpamRaters(array $rawEntries): array
{
    $hits   = [];
    $window = 600000; // 10 minutes in ms
    $thresh = 20;

    foreach ($rawEntries as $sid => $entries) {
        if (count($entries) < $thresh) continue;

        // Sort by timestamp
        usort($entries, fn($a, $b) => $a['timestamp_unix_ms'] <=> $b['timestamp_unix_ms']);

        // Two-pointer sliding window
        $maxInWindow = 0;
        $left = 0;
        for ($right = 0, $n = count($entries); $right < $n; $right++) {
            while ($entries[$right]['timestamp_unix_ms'] - $entries[$left]['timestamp_unix_ms'] > $window) {
                $left++;
            }
            $count = $right - $left + 1;
            if ($count > $maxInWindow) $maxInWindow = $count;
        }

        if ($maxInWindow >= $thresh) {
            $hits[$sid] = [
                'name'     => 'Spam Rater',
                'severity' => 'High',
                'detail'   => $maxInWindow . ' ratings in a 10-minute window',
            ];
        }
    }
    return $hits;
}

/**
 * 2. Flat-Liner — 10+ deduplicated ratings and 80%+ are the same value.
 */
function detectFlatLiners(array $deduped): array
{
    $hits = [];

    foreach ($deduped as $sid => $entries) {
        $ratings = array_column($entries, 'rating');
        $ratings = array_filter($ratings, fn($r) => $r !== null);
        $total   = count($ratings);
        if ($total < 10) continue;

        // Find mode
        $freq = array_count_values(array_map(fn($r) => (string) $r, $ratings));
        arsort($freq);
        $modeValue = array_key_first($freq);
        $modeCount = $freq[$modeValue];
        $pct       = $modeCount / $total;

        if ($pct >= 0.8) {
            $hits[$sid] = [
                'name'     => 'Flat-Liner',
                'severity' => 'Medium',
                'detail'   => round($pct * 100) . '% of ' . $total . ' ratings are ' . $modeValue,
            ];
        }
    }
    return $hits;
}

/**
 * 3. Blitzer — 5+ consecutive rating gaps under 15 seconds.
 */
function detectBlitzers(array $rawEntries): array
{
    $hits      = [];
    $gapLimit  = 15000; // 15 seconds in ms
    $minStreak = 5;

    foreach ($rawEntries as $sid => $entries) {
        if (count($entries) < $minStreak + 1) continue;

        usort($entries, fn($a, $b) => $a['timestamp_unix_ms'] <=> $b['timestamp_unix_ms']);

        $streak    = 0;
        $maxStreak = 0;
        for ($i = 1, $n = count($entries); $i < $n; $i++) {
            $gap = $entries[$i]['timestamp_unix_ms'] - $entries[$i - 1]['timestamp_unix_ms'];
            if ($gap < $gapLimit) {
                $streak++;
                if ($streak > $maxStreak) $maxStreak = $streak;
            } else {
                $streak = 0;
            }
        }

        if ($maxStreak >= $minStreak) {
            $hits[$sid] = [
                'name'     => 'Blitzer',
                'severity' => 'High',
                'detail'   => $maxStreak . ' consecutive gaps under 15 seconds',
            ];
        }
    }
    return $hits;
}

/**
 * 4. Extremist — 10+ scored deduplicated ratings and 80%+ are 0.25 or 5.00.
 */
function detectExtremists(array $deduped): array
{
    $hits = [];

    foreach ($deduped as $sid => $entries) {
        $scored = [];
        foreach ($entries as $e) {
            if ($e['rating'] !== null && $e['rating'] > 0) {
                $scored[] = $e['rating'];
            }
        }
        $total = count($scored);
        if ($total < 10) continue;

        $extremeCount = 0;
        foreach ($scored as $r) {
            if ($r <= 0.25 || $r >= 5.0) $extremeCount++;
        }
        $pct = $extremeCount / $total;

        if ($pct >= 0.8) {
            $hits[$sid] = [
                'name'     => 'Extremist',
                'severity' => 'Medium',
                'detail'   => round($pct * 100) . '% of ' . $total . ' scored ratings are extreme (0.25 or 5.00)',
            ];
        }
    }
    return $hits;
}

/**
 * 5. Flip-Flopper — Any single beer re-rated 5+ times by the same user.
 */
function detectFlipFloppers(array $rawEntries): array
{
    $hits = [];

    foreach ($rawEntries as $sid => $entries) {
        $beerCounts = [];
        foreach ($entries as $e) {
            $bid = $e['beer_id'];
            $beerCounts[$bid] = ($beerCounts[$bid] ?? 0) + 1;
        }

        $worst = 0;
        $worstBeer = '';
        foreach ($beerCounts as $bid => $c) {
            if ($c > $worst) {
                $worst     = $c;
                $worstBeer = $bid;
            }
        }

        if ($worst >= 5) {
            $hits[$sid] = [
                'name'     => 'Flip-Flopper',
                'severity' => 'Low',
                'detail'   => 'Beer "' . $worstBeer . '" re-rated ' . $worst . ' times',
            ];
        }
    }
    return $hits;
}

/**
 * 6. Outlier Bomber — 10+ scored ratings, average deviation > 2.0 from per-beer festival mean.
 */
function detectOutlierBombers(array $deduped, array $beerMeans): array
{
    $hits = [];

    foreach ($deduped as $sid => $entries) {
        $deviations = [];
        foreach ($entries as $e) {
            if ($e['rating'] === null || $e['rating'] <= 0) continue;
            $bid = $e['beer_id'];
            if (!isset($beerMeans[$bid])) continue;
            $deviations[] = $e['rating'] - $beerMeans[$bid];
        }

        if (count($deviations) < 10) continue;

        $avgDev = array_sum($deviations) / count($deviations);

        if (abs($avgDev) > 2.0) {
            $direction = $avgDev > 0 ? 'above' : 'below';
            $hits[$sid] = [
                'name'     => 'Outlier Bomber',
                'severity' => 'Low-Medium',
                'detail'   => 'Average deviation ' . round(abs($avgDev), 2) . ' points ' . $direction . ' consensus across ' . count($deviations) . ' beers',
            ];
        }
    }
    return $hits;
}
