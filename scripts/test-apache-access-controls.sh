#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${AEC_APACHE_TEST_URL:-http://127.0.0.1}"
expect_status() {
  local path="$1"
  local expected="$2"
  local actual
  actual="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "${BASE_URL}${path}")"
  if [[ "$actual" != "$expected" ]]; then
    printf 'FAIL %s expected HTTP %s, got %s\n' "$path" "$expected" "$actual" >&2
    exit 1
  fi
  printf 'PASS %s -> HTTP %s\n' "$path" "$actual"
}

# Public frontend must remain reachable while deployment/source paths are denied.
expect_status "/" 200
expect_status "/scripts/install.php" 403
expect_status "/database/migrations/001_core_schema.sql" 403
expect_status "/docs/CPANEL-DEPLOYMENT.md" 403
expect_status "/api/_bootstrap.php" 403
expect_status "/api/_email.php" 403
expect_status "/api/README.md" 403
expect_status "/.env.example" 403

# Private storage is deliberately a sibling of the document root, never a web path.
# A client-side route may return the public index.html with HTTP 200. The private
# file content must never be present in that response.
private_response="$(mktemp)"
trap 'rm -f "$private_response"' EXIT
private_status="$(curl --silent --show-error --output "$private_response" --write-out '%{http_code}' "${BASE_URL}/aec-private-storage/http-denial-probe.txt")"
if [[ "$private_status" != "200" && "$private_status" != "403" && "$private_status" != "404" ]]; then
  printf 'FAIL private storage URL returned unexpected HTTP %s\\n' "$private_status" >&2
  exit 1
fi
if grep -Fq 'PRIVATE_MEDIA_SECRET_MARKER' "$private_response"; then
  echo "FAIL private media content was exposed through HTTP" >&2
  exit 1
fi
printf 'PASS /aec-private-storage/http-denial-probe.txt -> HTTP %s with no private bytes\\n' "$private_status"

echo "PASS Apache protected-path and private-storage HTTP denial checks"
