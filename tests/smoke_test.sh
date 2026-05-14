#!/bin/bash
# smoke_test.sh — Automated smoke & security tests for My-BeerFest
#
# Requires: curl, python3 (for JSON parsing)
# Usage:    ./tests/smoke_test.sh
#
# Expects the app to be running via: docker compose up -d
# Default base URL: http://127.0.0.1:8181

set -euo pipefail

BASE_URL="${BASE_URL:-http://127.0.0.1:8181}"
STATS_USER="${STATS_USER:-stats}"
STATS_PASS="${STATS_PASS:-changeme}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-changeme}"

PASSED=0
FAILED=0
ERRORS=""
TMPDIR_TEST=$(mktemp -d)
trap 'rm -rf "$TMPDIR_TEST"' EXIT

# ── Helpers ──────────────────────────────────────────────────────────

pass() {
    PASSED=$((PASSED + 1))
    printf "  \033[32mPASS\033[0m  %s\n" "$1"
}

fail() {
    FAILED=$((FAILED + 1))
    ERRORS="${ERRORS}\n  - $1"
    printf "  \033[31mFAIL\033[0m  %s\n" "$1"
}

assert_status() {
    local desc="$1" url="$2" expected="$3"
    shift 3
    local code
    code=$(curl -s -o /dev/null -w "%{http_code}" "$@" "$url")
    if [ "$code" = "$expected" ]; then
        pass "$desc (HTTP $code)"
    else
        fail "$desc — expected $expected, got $code"
    fi
}

# File-based body assertions (avoids issues with large bodies in shell variables)
assert_file_contains() {
    local desc="$1" file="$2" needle="$3"
    if grep -qF "$needle" "$file"; then
        pass "$desc"
    else
        fail "$desc — missing: $needle"
    fi
}

assert_file_not_contains() {
    local desc="$1" file="$2" needle="$3"
    if grep -qF "$needle" "$file"; then
        fail "$desc — unexpected: $needle"
    else
        pass "$desc"
    fi
}

assert_header() {
    local desc="$1" file="$2" needle="$3"
    if grep -qi "$needle" "$file"; then
        pass "$desc"
    else
        fail "$desc — header missing: $needle"
    fi
}

assert_no_header() {
    local desc="$1" file="$2" needle="$3"
    if grep -qi "$needle" "$file"; then
        fail "$desc — unexpected header: $needle"
    else
        pass "$desc"
    fi
}

# ── Wait for app to be ready ─────────────────────────────────────────

printf "\n\033[1m▸ Waiting for app at %s ...\033[0m\n" "$BASE_URL"
for _ in $(seq 1 15); do
    if curl -s -o /dev/null -w "" "$BASE_URL/" 2>/dev/null; then
        break
    fi
    sleep 1
done

# ══════════════════════════════════════════════════════════════════════
# 1. MAIN APP
# ══════════════════════════════════════════════════════════════════════
printf "\n\033[1m▸ Main app\033[0m\n"

MAIN_FILE="$TMPDIR_TEST/main.html"
curl -s "$BASE_URL/" > "$MAIN_FILE"
assert_status "GET / returns 200" "$BASE_URL/" 200
assert_file_contains "Page contains beer list container" "$MAIN_FILE" "beer-list"
assert_file_contains "escAttr helper present" "$MAIN_FILE" "escAttr"
assert_file_contains "safeUrl helper present" "$MAIN_FILE" "safeUrl"
assert_file_contains "safeUrl used in beer card href" "$MAIN_FILE" "safeUrl(beer.untappd)"
assert_file_contains "escAttr used on data-beer-name" "$MAIN_FILE" "escAttr(beer.name)"

# ══════════════════════════════════════════════════════════════════════
# 2. BEER DATA
# ══════════════════════════════════════════════════════════════════════
printf "\n\033[1m▸ Beer data\033[0m\n"

BEERS_BODY=$(curl -s "$BASE_URL/data/beers.json")
BEER_COUNT=$(echo "$BEERS_BODY" | python3 -c "import sys,json; print(len(json.load(sys.stdin)))" 2>/dev/null || echo "0")
if [ "$BEER_COUNT" -gt 0 ]; then
    pass "beers.json returns valid JSON array ($BEER_COUNT beers)"
else
    fail "beers.json empty or invalid"
fi

assert_status "Backup beers JSON without auth returns 401" \
    "$BASE_URL/data/beers-20000101_000000.json" 401

assert_status "Backup beers JSON with admin auth passes auth" \
    "$BASE_URL/data/beers-20000101_000000.json" 404 \
    -u "$ADMIN_USER:$ADMIN_PASS"

# ══════════════════════════════════════════════════════════════════════
# 3. RATING ENDPOINT
# ══════════════════════════════════════════════════════════════════════
printf "\n\033[1m▸ Rating endpoint (log_rating.php)\033[0m\n"

# Pick first beer ID from data
FIRST_ID=$(echo "$BEERS_BODY" | python3 -c "import sys,json; print(json.load(sys.stdin)[0]['id'])" 2>/dev/null || echo "test-id")

assert_status "Valid rating POST returns 200" \
    "$BASE_URL/log_rating.php" 200 \
    -X POST -H "Content-Type: application/json" \
    -d "{\"beer_id\":\"$FIRST_ID\",\"rating\":4.0,\"session_id\":\"smoke-test\"}"

assert_status "Missing beer_id returns 400" \
    "$BASE_URL/log_rating.php" 400 \
    -X POST -H "Content-Type: application/json" \
    -d '{"rating":4.0,"session_id":"smoke-test"}'

assert_status "Invalid rating value returns 400" \
    "$BASE_URL/log_rating.php" 400 \
    -X POST -H "Content-Type: application/json" \
    -d "{\"beer_id\":\"$FIRST_ID\",\"rating\":99,\"session_id\":\"smoke-test\"}"

assert_status "Malformed JSON returns 400" \
    "$BASE_URL/log_rating.php" 400 \
    -X POST -H "Content-Type: application/json" \
    -d '{bad json}'

assert_status "GET method returns 405" \
    "$BASE_URL/log_rating.php" 405

# CORS: no wildcard
RATING_HDRS="$TMPDIR_TEST/rating_headers.txt"
curl -s -D "$RATING_HDRS" -o /dev/null -X POST "$BASE_URL/log_rating.php" \
    -H "Content-Type: application/json" \
    -d "{\"beer_id\":\"$FIRST_ID\",\"rating\":3.0,\"session_id\":\"smoke-test\"}"
assert_no_header "No wildcard CORS on rating endpoint" "$RATING_HDRS" "Access-Control-Allow-Origin: \*"

# ══════════════════════════════════════════════════════════════════════
# 4. COOKIE CONSENT ENDPOINT
# ══════════════════════════════════════════════════════════════════════
printf "\n\033[1m▸ Cookie consent endpoint (log_cookie_consent.php)\033[0m\n"

assert_status "Valid consent POST returns 204" \
    "$BASE_URL/log_cookie_consent.php" 204 \
    -X POST -H "Content-Type: application/json" \
    -d '{"consent":true}'

assert_status "Invalid consent value returns 400" \
    "$BASE_URL/log_cookie_consent.php" 400 \
    -X POST -H "Content-Type: application/json" \
    -d '{"consent":"yes"}'

assert_status "Malformed JSON returns 400" \
    "$BASE_URL/log_cookie_consent.php" 400 \
    -X POST -H "Content-Type: application/json" \
    -d 'not json'

CONSENT_HDRS="$TMPDIR_TEST/consent_headers.txt"
curl -s -D "$CONSENT_HDRS" -o /dev/null -X POST "$BASE_URL/log_cookie_consent.php" \
    -H "Content-Type: application/json" \
    -d '{"consent":false}'
assert_no_header "No wildcard CORS on consent endpoint" "$CONSENT_HDRS" "Access-Control-Allow-Origin: \*"

# ══════════════════════════════════════════════════════════════════════
# 5. STATS PAGE
# ══════════════════════════════════════════════════════════════════════
printf "\n\033[1m▸ Stats page (stats.php)\033[0m\n"

assert_status "Stats without auth returns 401" "$BASE_URL/stats.php" 401

assert_status "Stats with auth returns 200" \
    "$BASE_URL/stats.php" 200 -u "$STATS_USER:$STATS_PASS"

STATS_FILE="$TMPDIR_TEST/stats.html"
curl -s -u "$STATS_USER:$STATS_PASS" "$BASE_URL/stats.php" > "$STATS_FILE"
# Stats page uses safe DOM construction (textContent/createElement) instead of innerHTML+esc()
assert_file_contains "Safe DOM: highlights use textContent" "$STATS_FILE" '.textContent'
assert_file_contains "Safe DOM: highlights use createElement" "$STATS_FILE" "createElement('span')"
assert_file_contains "Safe DOM: tables built via createDocumentFragment" "$STATS_FILE" 'createDocumentFragment()'
assert_file_not_contains "No innerHTML with user data in highlights" "$STATS_FILE" 'innerHTML = fmt('
assert_file_not_contains "No innerHTML += in table rendering" "$STATS_FILE" 'innerHTML +='

# JSON API
STATS_JSON=$(curl -s -u "$STATS_USER:$STATS_PASS" "$BASE_URL/stats.php?format=json")
STATS_KEYS=$(echo "$STATS_JSON" | python3 -c "import sys,json; print(','.join(sorted(json.load(sys.stdin).keys())))" 2>/dev/null || echo "")
if echo "$STATS_KEYS" | grep -q "engagement"; then
    pass "Stats JSON API returns expected structure"
else
    fail "Stats JSON API missing expected keys (got: $STATS_KEYS)"
fi

# ══════════════════════════════════════════════════════════════════════
# 6. ADMIN PAGE & API
# ══════════════════════════════════════════════════════════════════════
printf "\n\033[1m▸ Admin panel & API\033[0m\n"

assert_status "Admin page without auth returns 401" "$BASE_URL/admin.php" 401

assert_status "Admin page with auth returns 200" \
    "$BASE_URL/admin.php" 200 -u "$ADMIN_USER:$ADMIN_PASS"

# Same-origin (no Origin header) — should work
VERSIONS_BODY=$(curl -s -u "$ADMIN_USER:$ADMIN_PASS" "$BASE_URL/admin_api.php?action=versions")
VERSIONS_STATUS=$(echo "$VERSIONS_BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || echo "")
if [ "$VERSIONS_STATUS" = "success" ]; then
    pass "Admin API same-origin GET (versions) succeeds"
else
    fail "Admin API same-origin GET (versions) — got status: $VERSIONS_STATUS"
fi

# ══════════════════════════════════════════════════════════════════════
# 7. CSRF / CROSS-ORIGIN PROTECTION
# ══════════════════════════════════════════════════════════════════════
printf "\n\033[1m▸ CSRF / cross-origin protection\033[0m\n"

# Cross-origin GET with evil Origin — should be 403
assert_status "Admin API rejects cross-origin GET (evil Origin)" \
    "$BASE_URL/admin_api.php?action=versions" 403 \
    -u "$ADMIN_USER:$ADMIN_PASS" -H "Origin: https://evil.com"

# Cross-origin POST with evil Origin — should be 403
assert_status "Admin API rejects cross-origin POST (evil Origin)" \
    "$BASE_URL/admin_api.php?action=save" 403 \
    -u "$ADMIN_USER:$ADMIN_PASS" \
    -X POST -H "Origin: https://evil.com" -H "Content-Type: application/json" -d '[]'

# Cross-origin OPTIONS preflight — should fail (401 or 403)
PREFLIGHT_CODE=$(curl -s -o /dev/null -w "%{http_code}" -X OPTIONS \
    -H "Origin: https://evil.com" -H "Access-Control-Request-Method: POST" \
    "$BASE_URL/admin_api.php?action=save")
if [ "$PREFLIGHT_CODE" = "401" ] || [ "$PREFLIGHT_CODE" = "403" ]; then
    pass "Admin API rejects cross-origin preflight (HTTP $PREFLIGHT_CODE)"
else
    fail "Admin API cross-origin preflight — expected 401 or 403, got $PREFLIGHT_CODE"
fi

# No CORS headers on admin API response
ADMIN_HDRS="$TMPDIR_TEST/admin_headers.txt"
curl -s -D "$ADMIN_HDRS" -o /dev/null -u "$ADMIN_USER:$ADMIN_PASS" \
    "$BASE_URL/admin_api.php?action=versions"
assert_no_header "Admin API emits no Access-Control-Allow-Origin header" \
    "$ADMIN_HDRS" "Access-Control-Allow-Origin"

# ══════════════════════════════════════════════════════════════════════
# 8. BAD RATER DETECTION API
# ══════════════════════════════════════════════════════════════════════
printf "\n\033[1m▸ Bad rater detection API\033[0m\n"

assert_status "Bad raters API without auth returns 401" \
    "$BASE_URL/admin_api.php?action=bad_raters" 401

BAD_RATERS_FILE="$TMPDIR_TEST/bad_raters.json"
curl -s -u "$ADMIN_USER:$ADMIN_PASS" \
    "$BASE_URL/admin_api.php?action=bad_raters" > "$BAD_RATERS_FILE"
BAD_RATERS_STATUS=$(python3 -c "import sys,json; print(json.load(open(sys.argv[1])).get('status',''))" "$BAD_RATERS_FILE" 2>/dev/null || echo "")
if [ "$BAD_RATERS_STATUS" = "success" ]; then
    pass "Bad raters API returns success status"
else
    fail "Bad raters API — got status: $BAD_RATERS_STATUS"
fi
assert_file_contains "Bad raters response has summary field" "$BAD_RATERS_FILE" '"summary"'
assert_file_contains "Bad raters response has flagged field" "$BAD_RATERS_FILE" '"flagged"'

# POST exclude_rater without X-Requested-With should return 403
assert_status "Exclude rater without CSRF header returns 403" \
    "$BASE_URL/admin_api.php?action=exclude_rater" 403 \
    -u "$ADMIN_USER:$ADMIN_PASS" \
    -X POST -H "Content-Type: application/json" \
    -d '{"session_id":"test","exclude":true}'

# POST exclude_rater with proper headers should work
EXCLUDE_BODY=$(curl -s -u "$ADMIN_USER:$ADMIN_PASS" \
    -X POST -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" \
    -d '{"session_id":"smoke-test-rater","exclude":true,"patterns":["Spam Rater"]}' \
    "$BASE_URL/admin_api.php?action=exclude_rater")
EXCLUDE_STATUS=$(echo "$EXCLUDE_BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || echo "")
if [ "$EXCLUDE_STATUS" = "success" ]; then
    pass "Exclude rater API returns success"
else
    fail "Exclude rater API — got status: $EXCLUDE_STATUS"
fi

# Clean up: remove the test exclusion
curl -s -u "$ADMIN_USER:$ADMIN_PASS" \
    -X POST -H "Content-Type: application/json" -H "X-Requested-With: XMLHttpRequest" \
    -d '{"session_id":"smoke-test-rater","exclude":false}' \
    "$BASE_URL/admin_api.php?action=exclude_rater" > /dev/null

# GET excluded_raters returns the list
EXCLUDED_LIST_BODY=$(curl -s -u "$ADMIN_USER:$ADMIN_PASS" \
    "$BASE_URL/admin_api.php?action=excluded_raters")
EXCLUDED_LIST_STATUS=$(echo "$EXCLUDED_LIST_BODY" | python3 -c "import sys,json; print(json.load(sys.stdin).get('status',''))" 2>/dev/null || echo "")
if [ "$EXCLUDED_LIST_STATUS" = "success" ]; then
    pass "Excluded raters list API returns success"
else
    fail "Excluded raters list API — got status: $EXCLUDED_LIST_STATUS"
fi

# Stats with exclude_raters param
assert_status "Stats JSON with exclude_raters param returns 200" \
    "$BASE_URL/stats.php?format=json&exclude_raters=1" 200 \
    -u "$STATS_USER:$STATS_PASS"

# ══════════════════════════════════════════════════════════════════════
# 9. STATIC PAGES
# ══════════════════════════════════════════════════════════════════════
printf "\n\033[1m▸ Other pages\033[0m\n"

assert_status "my_stats.php returns 200" "$BASE_URL/my_stats.php" 200
assert_status "privacy-policy.php returns 200" "$BASE_URL/privacy-policy.php" 200
assert_status "manifest.php returns 200" "$BASE_URL/manifest.php" 200

# ══════════════════════════════════════════════════════════════════════
# SUMMARY
# ══════════════════════════════════════════════════════════════════════
TOTAL=$((PASSED + FAILED))
printf "\n\033[1m════════════════════════════════════════\033[0m\n"
printf "\033[1m  Results: %d passed, %d failed (%d total)\033[0m\n" "$PASSED" "$FAILED" "$TOTAL"
if [ "$FAILED" -gt 0 ]; then
    printf "\033[31m  Failures:%b\033[0m\n" "$ERRORS"
    printf "\033[1m════════════════════════════════════════\033[0m\n\n"
    exit 1
else
    printf "\033[32m  All tests passed.\033[0m\n"
    printf "\033[1m════════════════════════════════════════\033[0m\n\n"
    exit 0
fi
