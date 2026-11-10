#!/usr/bin/env bash

set -euo pipefail

SCENARIO="${1:-fresh}"
PORT="${2:-8080}"
BASE_URL="http://127.0.0.1:${PORT}"
TYPO3_BIN=".Build/bin/typo3"
PASSWORD="Password.1"
EDITOR="jana"

mkdir -p var/sqlite var/log

cleanup() {
    local status=$?
    if [[ -n "${SERVER_PID:-}" ]] && kill -0 "${SERVER_PID}" 2>/dev/null; then
        echo "Stopping PHP web server (PID ${SERVER_PID})..."
        kill "${SERVER_PID}" 2>/dev/null || true
    fi
    exit ${status}
}
trap cleanup EXIT INT TERM

echo "=== Preparing clean TYPO3 SQLite database ==="
rm -f var/sqlite/*.sqlite
rm -rf config/system/settings.php config/sites/main

${TYPO3_BIN} setup --force --no-interaction \
    --driver=sqlite \
    --server-type=other \
    --admin-username=admin \
    --admin-user-password="${PASSWORD}" \
    --project-name="Playwright Acceptance"

${TYPO3_BIN} extension:setup

WORKSPACE=""

case "${SCENARIO}" in
    fresh)
        echo "=== Scenario: fresh ==="
        php Build/playwright/seed.php pages
        ${TYPO3_BIN} publishpage:setup fresh --editor="${EDITOR}" --editor-password="${PASSWORD}"
        ${TYPO3_BIN} publishpage:check
        WORKSPACE="Redaktion"
        ;;
    single-group)
        echo "=== Scenario: single-group ==="
        php Build/playwright/seed.php pages
        ${TYPO3_BIN} publishpage:setup single-group --group="Online-Redaktion" --editor="${EDITOR}" --editor-password="${PASSWORD}"
        ${TYPO3_BIN} publishpage:check
        WORKSPACE="Online-Redaktion"
        ;;
    group-per-workspace)
        echo "=== Scenario: group-per-workspace ==="
        php Build/playwright/seed.php pages
        ${TYPO3_BIN} publishpage:setup group-per-workspace --group="News,Sport,Kultur" --editor="${EDITOR}:Sport" --editor-password="${PASSWORD}"
        ${TYPO3_BIN} publishpage:check
        WORKSPACE="Sport"
        ;;
    existing-groups)
        echo "=== Scenario: existing-groups ==="
        php Build/playwright/seed.php tree
        ${TYPO3_BIN} publishpage:setup existing-groups --attach-to-editors
        ${TYPO3_BIN} backend:user:create --username="${EDITOR}" --password="${PASSWORD}" --groups=1
        ${TYPO3_BIN} publishpage:check --fix-live-editing
        ${TYPO3_BIN} publishpage:check
        WORKSPACE="Redaktion"
        ;;
    *)
        echo "Unknown scenario: ${SCENARIO}. Expected: fresh, single-group, group-per-workspace, existing-groups"
        exit 1
        ;;
esac

echo "=== Starting PHP built-in webserver on port ${PORT} ==="
php -S "127.0.0.1:${PORT}" -t .Build/public Build/playwright/router.php > var/log/php-server.log 2>&1 &
SERVER_PID=$!

echo "Waiting for web server to answer..."
for i in {1..30}; do
    if curl -s -o /dev/null -w "%{http_code}" "${BASE_URL}/typo3/" 2>/dev/null | grep -q "200\|302\|303\|307"; then
        echo "Web server is up and responding!"
        break
    fi
    sleep 0.5
done

echo "=== Running Playwright Acceptance Tests (${SCENARIO}) ==="
PUBLISHPAGE_BASE_URL="${BASE_URL}" \
PUBLISHPAGE_SETUP_EDITOR="${EDITOR}" \
PUBLISHPAGE_SETUP_PASSWORD="${PASSWORD}" \
PUBLISHPAGE_SETUP_WORKSPACE="${WORKSPACE}" \
npx playwright test

echo "=== Acceptance Test for scenario ${SCENARIO} passed successfully ==="
