#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
VERSION=${1:?Usage: build-release-package.sh <version>}
COMMIT=${GITHUB_SHA:-$(git -C "$ROOT" rev-parse HEAD)}
OUTPUT_DIR=${OUTPUT_DIR:-"$ROOT/release-artifacts"}
REQUIRE_CPANEL_GATES=${REQUIRE_CPANEL_GATES:-true}

[[ "$VERSION" =~ ^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$ ]] || {
  echo "Invalid release version: $VERSION" >&2
  exit 2
}
[[ "$COMMIT" =~ ^[0-9a-f]{40}$ ]] || {
  echo "GITHUB_SHA or checked-out commit must be a full 40-character SHA." >&2
  exit 2
}

for command in bun zip unzip sha256sum find sort xargs; do
  command -v "$command" >/dev/null 2>&1 || { echo "Required command is missing: $command" >&2; exit 1; }
done

cd "$ROOT"
bun install --frozen-lockfile
bun run lint
bun run build

for path in dist/index.html dist/.htaccess api/.htaccess api/_bootstrap.php database/migrations/001_core_schema.sql scripts/.htaccess scripts/migrate.php scripts/create-admin.php scripts/install.php scripts/diagnose.php api/_config.php docs/CPANEL-DEPLOYMENT.md docs/SUPPORT-DIAGNOSTICS-RECOVERY.md; do
  [[ -s "$path" ]] || { echo "Required release input is missing or empty: $path" >&2; exit 1; }
done

if [[ "$REQUIRE_CPANEL_GATES" == "true" ]]; then
  for path in scripts/preflight.php scripts/backup.php scripts/restore.php scripts/lib/backup-common.php scripts/test-backup-restore.php docs/CPANEL-BACKUP-RESTORE.md; do
    [[ -s "$path" ]] || { echo "Release gate is not ready; missing $path (merge the cPanel preflight and encrypted backup/restore changes first)." >&2; exit 1; }
  done
  grep -Fq -- "--cipher-algo" scripts/lib/backup-common.php || { echo "Release gate failed: backups are not encrypted with GPG." >&2; exit 1; }
  grep -Fq "AES256" scripts/lib/backup-common.php || { echo "Release gate failed: AES-256 backup encryption is missing." >&2; exit 1; }
  grep -Fq -- "--confirm-restore" scripts/restore.php || { echo "Release gate failed: restore confirmation guard is missing." >&2; exit 1; }
  grep -Fq "SHOW TABLES" scripts/restore.php || { echo "Release gate failed: restore does not verify an empty target database." >&2; exit 1; }
  grep -Fq "GET_LOCK" scripts/create-admin.php || { echo "Release gate failed: first-admin bootstrap is not protected by a database advisory lock." >&2; exit 1; }
  grep -Fq "COUNT(*) FROM users WHERE role = 'super_admin'" scripts/create-admin.php || { echo "Release gate failed: first-admin bootstrap does not fail closed when a super-admin exists." >&2; exit 1; }
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
cp -a dist/. "$STAGE/"
cp -a api database scripts "$STAGE/"
mkdir -p "$STAGE/docs"
cp docs/CPANEL-DEPLOYMENT.md docs/SUPPORT-DIAGNOSTICS-RECOVERY.md "$STAGE/docs/"
if [[ -s docs/CPANEL-BACKUP-RESTORE.md ]]; then cp docs/CPANEL-BACKUP-RESTORE.md "$STAGE/docs/"; fi

# Do not package symlinks; they can escape the extracted release directory.
if find "$STAGE" -type l -print -quit | grep -q .; then
  echo "Release staging directory contains a symbolic link; refusing to package." >&2
  exit 1
fi

{
  printf 'version=%s\n' "$VERSION"
  printf 'commit=%s\n' "$COMMIT"
  printf 'created_utc=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
} > "$STAGE/RELEASE-METADATA.txt"

(
  cd "$STAGE"
  find . -type f ! -name 'RELEASE-MANIFEST.sha256' -print0 \
    | LC_ALL=C sort -z \
    | xargs -0 sha256sum > RELEASE-MANIFEST.sha256
)

mkdir -p "$OUTPUT_DIR"
OUTPUT_DIR=$(cd "$OUTPUT_DIR" && pwd)
ZIP_FILE="$OUTPUT_DIR/aec-lms-$VERSION.zip"
rm -f "$ZIP_FILE" "$ZIP_FILE.sha256"
(
  cd "$STAGE"
  find . -type f -print0 | LC_ALL=C sort -z | xargs -0 zip -X -q "$ZIP_FILE"
)
unzip -tq "$ZIP_FILE" >/dev/null
# Write the sidecar with an unambiguous basename and no dependence on cwd.
(
  cd "$OUTPUT_DIR"
  sha256sum "$(basename "$ZIP_FILE")" > "$(basename "$ZIP_FILE").sha256"
  sha256sum --strict -c "$(basename "$ZIP_FILE").sha256"
)

bash "$ROOT/scripts/verify-release-package.sh" "$ZIP_FILE"
echo "Created: $ZIP_FILE"
echo "Checksum: $ZIP_FILE.sha256"
