#!/bin/bash
# API Smoke Test Harness — USGAR Hotels
# Usage: bash tests/api-harness.sh [base_url]
# Default: http://localhost:8000

BASE_URL="${1:-http://localhost:8000}"
PASS=0
WARN=0
FAIL=0

test_endpoint() {
    local method="$1"
    local path="$2"
    local expected_status="$3"
    local body="$4"
    local label="$method $path"

    if [ "$method" = "GET" ]; then
        response=$(curl -s -o /tmp/api_response -w "%{http_code}" "${BASE_URL}${path}")
    else
        response=$(curl -s -o /tmp/api_response -w "%{http_code}" -X "$method" \
            -H "Content-Type: application/json" \
            -d "$body" \
            "${BASE_URL}${path}")
    fi

    body_content=$(cat /tmp/api_response)

    if [ "$response" = "$expected_status" ]; then
        echo " $label → $response"
        PASS=$((PASS + 1))
    elif [ "$response" = "500" ]; then
        echo " $label → $response (SERVER ERROR)"
        FAIL=$((FAIL + 1))
    elif [ "$response" = "000" ]; then
        echo " $label → CONNECTION REFUSED (server not running?)"
        FAIL=$((FAIL + 1))
    else
        echo "️  $label → $response (expected $expected_status)"
        WARN=$((WARN + 1))
    fi
}

echo "================================="
echo "  USGAR Hotels API Harness"
echo "  Target: $BASE_URL"
echo "================================="
echo ""

# Health
test_endpoint "GET" "/api/health" "200"

# Rooms
test_endpoint "GET" "/api/rooms" "200"
test_endpoint "GET" "/api/rooms?checkIn=2026-08-01&checkOut=2026-08-03" "200"

# Booking - validation error expected (empty body)
test_endpoint "POST" "/api/booking" "400" '{}'

# Extend Hold - validation error expected
test_endpoint "POST" "/api/extend-hold" "400" '{}'

# Booking Status - missing param
test_endpoint "GET" "/api/booking-status" "400"

# Auth
test_endpoint "GET" "/api/auth/me" "401"
test_endpoint "POST" "/api/auth/register" "400" '{}'
test_endpoint "POST" "/api/auth/login-email" "400" '{}'
test_endpoint "POST" "/api/auth/logout" "401"

echo ""
echo "================================="
echo "  Results:  $PASS  ️  $WARN   $FAIL"
echo "================================="

# Exit with error code if any failures
[ $FAIL -gt 0 ] && exit 1
exit 0
