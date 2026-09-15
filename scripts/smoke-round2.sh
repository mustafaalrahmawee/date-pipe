#!/usr/bin/env bash
#
# DataPipe round-2 smoke tests (Collections & Parsing).
#
# Prerequisites:
#   - API server running:  cd api && php artisan serve
#   - Run from the REPO ROOT:  bash scripts/smoke-round2.sh
#   - Optional: API_BASE=http://other:8000 bash scripts/smoke-round2.sh
#
# The suite is self-contained: it generates its own small CSV with known
# rows, uploads, processes and reports on it, and asserts exact JSON
# values (including the BigDecimal precision guarantees). It also runs
# an optional larger-file check when test-files/test-1mb.csv exists.
#
# Note: imports created here stay in the database (no DELETE endpoint
# yet by design). Re-runs simply add new imports.

set -u

BASE="${API_BASE:-http://127.0.0.1:8000}"
EMAIL="${SMOKE_EMAIL:-smoke@example.com}"
PASS="${SMOKE_PASSWORD:-secret123}"

failures=0
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

pass() { echo "PASS: $1"; }
fail() { echo "FAIL: $1"; echo "      $2"; failures=$((failures + 1)); }

check_contains() { # label body needle
    if printf '%s' "$2" | grep -qF -- "$3"; then
        pass "$1"
    else
        fail "$1" "expected to find [$3] in: $(printf '%s' "$2" | head -c 300)"
    fi
}

fetch() { # method url [curl-args...] -> sets CODE and BODY
    local method="$1" url="$2"; shift 2
    CODE=$(curl -s -o "$tmp/body" -w '%{http_code}' -X "$method" "$url" "$@")
    BODY=$(cat "$tmp/body")
}

echo "== 0) server reachable? =="
fetch GET "$BASE/up"
if [ "$CODE" != "200" ]; then
    echo "Server not reachable at $BASE (HTTP $CODE)."
    echo "Start it in another terminal:  cd api && php artisan serve"
    exit 1
fi
pass "health endpoint 200"

echo "== 1) login =="
fetch POST "$BASE/api/auth/login" \
    -H "Content-Type: application/json" -H "Accept: application/json" \
    -d "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}"
TOKEN=$(printf '%s' "$BODY" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
AUTH=(-H "Accept: application/json" -H "Authorization: Bearer $TOKEN")
if [ -n "$TOKEN" ]; then pass "token received"; else fail "token received" "response: $BODY"; exit 1; fi

echo "== 2) upload fixture CSV (7 data rows, 4 of them invalid) =="
printf '%s\n' \
    'name,email,amount' \
    'alice,alice@example.com,0.1' \
    'bob,bob@example.com,0.2' \
    'carol,CAROL@Example.com,10.5' \
    ',dave@example.com,5' \
    'erin,not-an-email,5' \
    'frank,frank@example.com,abc' \
    'gina,gina@example.com' > "$tmp/errors.csv"
fetch POST "$BASE/api/imports" "${AUTH[@]}" -F "file=@$tmp/errors.csv"
ID=$(printf '%s' "$BODY" | sed -n 's/.*"id":"\([0-9A-Za-z]*\)".*/\1/p')
check_contains "upload 201" "$CODE" "201"
if [ -n "$ID" ]; then pass "import id: $ID"; else fail "import id" "response: $BODY"; exit 1; fi

echo "== 3) process: tolerant counting =="
fetch POST "$BASE/api/imports/$ID/process" "${AUTH[@]}"
check_contains "process 200" "$CODE" "200"
check_contains "total rows 7" "$BODY" '"total_rows":7'
check_contains "valid rows 3" "$BODY" '"valid_rows":3'
check_contains "invalid rows 4" "$BODY" '"invalid_rows":4'
check_contains "error reported at row 5 (empty name)" "$BODY" '"row":5'
check_contains "error reported at row 6 (bad email)" "$BODY" '"row":6'
check_contains "error reported at row 7 (bad amount)" "$BODY" '"row":7'
check_contains "error reported at row 8 (2 columns)" "$BODY" '"row":8'
check_contains "at most 10 errors reported" "$BODY" '"errors":['

echo "== 4) idempotency: second run replaces, no duplicates =="
fetch POST "$BASE/api/imports/$ID/process" "${AUTH[@]}"
check_contains "second run valid rows 3" "$BODY" '"valid_rows":3'
fetch GET "$BASE/api/imports/$ID/records?per_page=100" "${AUTH[@]}"
check_contains "still 3 records after re-run" "$BODY" '"total":3'

echo "== 5) records: paginated, amount as exact string =="
fetch GET "$BASE/api/imports/$ID/records?per_page=2" "${AUTH[@]}"
check_contains "records 200" "$CODE" "200"
check_contains "pagination total 3" "$BODY" '"total":3'
check_contains "pagination per_page 2" "$BODY" '"per_page":2'
check_contains "page ends at 2" "$BODY" '"to":2'
check_contains "first record is alice" "$BODY" '"name":"alice"'
check_contains "amount exact string (alice)" "$BODY" '"amount":"0.100000"'

echo "== 6) report: BigDecimal aggregation =="
fetch GET "$BASE/api/imports/$ID/report" "${AUTH[@]}"
check_contains "report 200" "$CODE" "200"
check_contains "record count 3" "$BODY" '"record_count":3'
check_contains "sum exact 0.1+0.2+10.5" "$BODY" '"sum":"10.800000"'
check_contains "avg exact 3.600000" "$BODY" '"avg":"3.600000"'
check_contains "min exact 0.100000" "$BODY" '"min":"0.100000"'
check_contains "max exact 10.500000" "$BODY" '"max":"10.500000"'
check_contains "domain grouping lowercased" "$BODY" '"domain":"example.com"'

echo "== 7) list imports =="
fetch GET "$BASE/api/imports?per_page=50" "${AUTH[@]}"
check_contains "list 200" "$CODE" "200"
check_contains "list contains new import" "$BODY" "$ID"

echo "== 8) ownership and auth =="
fetch POST "$BASE/api/imports/$ID/process" -H "Accept: application/json"
if [ "$CODE" = "401" ]; then pass "no token -> 401"; else fail "no token -> 401" "got HTTP $CODE: $BODY"; fi
fetch POST "$BASE/api/imports/01AAAAAAAAAAAAAAAAAAAAAAAA/process" "${AUTH[@]}"
if [ "$CODE" = "404" ]; then pass "unknown/foreign id -> 404"; else fail "unknown/foreign id -> 404" "got HTTP $CODE: $BODY"; fi

echo "== 9) bigger file (optional, needs test-files/test-1mb.csv) =="
if [ -f test-files/test-1mb.csv ]; then
    fetch POST "$BASE/api/imports" "${AUTH[@]}" -F "file=@test-files/test-1mb.csv"
    BIG=$(printf '%s' "$BODY" | sed -n 's/.*"id":"\([0-9A-Za-z]*\)".*/\1/p')
    fetch POST "$BASE/api/imports/$BIG/process" "${AUTH[@]}"
    VALID=$(printf '%s' "$BODY" | sed -n 's/.*"valid_rows":\([0-9]*\).*/\1/p')
    check_contains "big upload+process 200" "$CODE" "200"
    check_contains "big file exactly 1 invalid row (known file quirk)" "$BODY" '"invalid_rows":1'
    if [ -n "$VALID" ] && [ "$VALID" -gt 20000 ]; then
        pass "big file ~28k valid rows ($VALID)"
    else
        fail "big file ~28k valid rows" "valid_rows='$VALID'"
    fi
    fetch GET "$BASE/api/imports/$BIG/report" "${AUTH[@]}"
    check_contains "big report avg exact 123.456789" "$BODY" '"avg":"123.456789"'
    check_contains "big report min=max=avg (uniform fixture)" "$BODY" '"max":"123.456789"'
else
    echo "SKIP: test-files/test-1mb.csv not found"
fi

echo
echo "== summary =="
if [ "$failures" -eq 0 ]; then
    echo "ALL TESTS PASSED"
    exit 0
fi
echo "$failures FAILURE(S)"
exit 1
