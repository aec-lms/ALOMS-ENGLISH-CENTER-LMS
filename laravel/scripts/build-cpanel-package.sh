#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
STAGE_DIR="${DIST_DIR}/aec-lms-cpanel"
ARCHIVE_PATH="${DIST_DIR}/aec-lms-cpanel-release.zip"

echo "==> Building AEC LMS cPanel Production Package from ${ROOT_DIR}"

rm -rf "${STAGE_DIR}" "${ARCHIVE_PATH}"
mkdir -p "${STAGE_DIR}"

rsync -a \
  --exclude='.git' \
  --exclude='.env' \
  --exclude='.env.*' \
  --include='.env.example' \
  --exclude='node_modules' \
  --exclude='tests' \
  --exclude='dist' \
  --exclude='storage/logs/*.log' \
  --exclude='storage/framework/cache/data/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  --exclude='storage/app/installed.lock' \
  --exclude='storage/app/backups/*' \
  --exclude='*.sqlite' \
  --exclude='*.sqlite3' \
  "${ROOT_DIR}/" "${STAGE_DIR}/"

mkdir -p \
  "${STAGE_DIR}/storage/app/private" \
  "${STAGE_DIR}/storage/app/backups" \
  "${STAGE_DIR}/storage/framework/cache/data" \
  "${STAGE_DIR}/storage/framework/sessions" \
  "${STAGE_DIR}/storage/framework/views" \
  "${STAGE_DIR}/storage/logs" \
  "${STAGE_DIR}/bootstrap/cache"

if command -v composer >/dev/null 2>&1; then
  (cd "${STAGE_DIR}" && composer install --no-dev --optimize-autoloader --no-interaction)
else
  echo "WARN: composer not found; vendor/ was not bundled into the stage directory."
fi

(cd "${STAGE_DIR}" && zip -rq "${ARCHIVE_PATH}" .)
echo "==> Created cPanel release package: ${ARCHIVE_PATH}"
