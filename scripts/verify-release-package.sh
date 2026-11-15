#!/usr/bin/env bash
set -euo pipefail

ZIP_FILE=${1:?Usage: verify-release-package.sh <release.zip>}
[[ -f "$ZIP_FILE" ]] || { echo "Release ZIP not found: $ZIP_FILE" >&2; exit 1; }
for command in unzip sha256sum mktemp; do
  command -v "$command" >/dev/null 2>&1 || { echo "Required command is missing: $command" >&2; exit 1; }
done

ZIP_FILE=$(cd "$(dirname "$ZIP_FILE")" && pwd)/$(basename "$ZIP_FILE")
unzip -tq "$ZIP_FILE" >/dev/null

# Reject absolute paths, traversal components, Windows paths and backslashes
# before extracting an archive supplied by a release pipeline.
while IFS= read -r entry; do
  case "$entry" in
    /*|../*|*/../*|*/..|*\\*|[A-Za-z]:*)
      echo "Unsafe path in release ZIP: $entry" >&2
      exit 1
      ;;
  esac
done < <(unzip -Z1 "$ZIP_FILE")

TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT
unzip -q "$ZIP_FILE" -d "$TMP_DIR"
cd "$TMP_DIR"

required=(
  "index.html"
  ".htaccess"
  "api/.htaccess"
  "api/_bootstrap.php"
  "database/migrations/001_core_schema.sql"
  "scripts/.htaccess"
  "scripts/migrate.php"
  "scripts/create-admin.php"
  "scripts/install.php"
  "scripts/preflight.php"
  "scripts/diagnose.php"
  "scripts/backup.php"
  "scripts/restore.php"
  "scripts/lib/backup-common.php"
  "scripts/test-backup-restore.php"
  "api/_config.php"
  "docs/CPANEL-DEPLOYMENT.md"
  "docs/CPANEL-BACKUP-RESTORE.md"
  "docs/SUPPORT-DIAGNOSTICS-RECOVERY.md"
  "RELEASE-METADATA.txt"
  "RELEASE-MANIFEST.sha256"
)
for path in "${required[@]}"; do
  [[ -s "$path" ]] || { echo "Required release file is missing or empty: $path" >&2; exit 1; }
done

# Reject secrets and local caches. SQL migration sources are intentional;
# arbitrary SQL dumps and all encrypted/compressed backup files are not.
while IFS= read -r -d '' file; do
  case "$file" in
    */.env.example) ;;
    */.env|*/.env.*)
      echo "Forbidden environment file in release package: $file" >&2
      exit 1
      ;;
    */.my.cnf|*/.git/*|*/node_modules/*)
      echo "Forbidden credential or development directory in release package: $file" >&2
      exit 1
      ;;
    ./database/migrations/*.sql) ;;
    *.sql|*.sql.gz|*.tar|*.tar.gz|*.gpg)
      echo "Forbidden SQL dump or backup file in release package: $file" >&2
      exit 1
      ;;
  esac
done < <(find . -type f -print0)

grep -Eq '^version=[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$' RELEASE-METADATA.txt
grep -Eq '^commit=[0-9a-f]{40}$' RELEASE-METADATA.txt
sha256sum --strict -c RELEASE-MANIFEST.sha256

echo "Release package verification passed: $ZIP_FILE"
