#!/bin/sh
# Burst demo for the auth rate limiter (5/min per IP, sliding window).
#
# Fires 7 rapid POST /api/tokens attempts with BAD credentials at the dev
# stack, then proves recovery once Retry-After has elapsed. Expected output:
#
#   attempts 1-5 -> 401 (failed logins COUNT: the limiter runs before
#                        authentication -- this is the credential-stuffing model)
#   attempts 6-7 -> 429 + Retry-After: <seconds> in the standard error shape
#   after waiting Retry-After seconds -> 401 again (a slot freed up)
#
# Uses only curl against localhost:8081 -- nothing installed, nothing mutated
# (the credentials are invalid, so no user/token is ever created).
set -eu

BASE_URL="${BASE_URL:-http://localhost:8081}"
BODY='{"email":"burst-demo@example.com","password":"definitely-wrong","name":"burst"}'
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Sends one attempt; leaves status in $code, Retry-After (or empty) in $retry.
attempt() {
    code="$(curl -s -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' \
        -X POST "$BASE_URL/api/tokens" \
        -H 'Content-Type: application/json' \
        -d "$BODY")"
    retry="$(awk 'tolower($1) == "retry-after:" { gsub(/\r/, ""); print $2 }' "$TMP/headers")"
}

echo "== Burst: 7 rapid POST $BASE_URL/api/tokens with bad credentials =="
retry_after=""
i=1
while [ "$i" -le 7 ]; do
    attempt
    if [ "$code" = "429" ]; then
        retry_after="$retry"
        printf 'attempt %d: %s  Retry-After: %ss  body: %s\n' "$i" "$code" "$retry" "$(cat "$TMP/body")"
    else
        printf 'attempt %d: %s\n' "$i" "$code"
    fi
    i=$((i + 1))
done

if [ -z "$retry_after" ]; then
    echo "ERROR: never saw a 429 -- is the limiter enabled (dev limit is 5/min)?" >&2
    exit 1
fi

echo ""
echo "== Recovery: honoring Retry-After like a well-behaved client =="
# One wait is USUALLY enough, but the sliding window can hand out a short
# Retry-After right before a window boundary while the previous window's
# decaying weight still blocks -- so do what a real client should do anyway:
# wait, retry, and if still limited, honor the fresh Retry-After and repeat.
# (Rejected attempts do not consume budget, so retrying is safe.)
deadline=$(($(date +%s) + 90))
while :; do
    wait_s=$((${retry_after:-1} + 1))
    printf 'waiting %ss ... ' "$wait_s"
    sleep "$wait_s"
    attempt
    printf 'retry: %s\n' "$code"
    [ "$code" = "401" ] && break
    retry_after="$retry"
    if [ "$(date +%s)" -ge "$deadline" ]; then
        echo "ERROR: still 429 after 90s of honoring Retry-After" >&2
        exit 1
    fi
done

echo "recovered: 401 -- limited no longer, just a wrong password again"
echo ""
echo "OK: limit -> 429 with Retry-After -> recovery, all demonstrated."
