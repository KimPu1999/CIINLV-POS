#!/usr/bin/env bash
set -Eeuo pipefail

: "${BACKUP_ENCRYPTION_KEY:?BACKUP_ENCRYPTION_KEY is required}"
: "${RESTORE_CONFIRMATION:?Set RESTORE_CONFIRMATION=I_UNDERSTAND_ISOLATED_RESTORE}"
if [[ "$RESTORE_CONFIRMATION" != 'I_UNDERSTAND_ISOLATED_RESTORE' ]]; then
  echo 'Refusing restore: explicit isolated-restore confirmation is required.' >&2
  exit 2
fi

: "${RESTORE_TARGET_DATABASE:?RESTORE_TARGET_DATABASE is required}"
case "$RESTORE_TARGET_DATABASE" in
  *_restore|*_staging|*_test) ;;
  *) echo 'Refusing restore: target database must end in _restore, _staging, or _test.' >&2; exit 2 ;;
esac

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"

backup_file="${1:?Usage: restore-postgres-backup.sh /path/to/backup.dump.enc}"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
  -pass env:BACKUP_ENCRYPTION_KEY \
  -in "$backup_file" -out "$work_dir/backup.dump"
pg_restore --list "$work_dir/backup.dump" >/dev/null

export PGPASSWORD="$DB_PASSWORD"
pg_restore \
  --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" \
  --dbname="$RESTORE_TARGET_DATABASE" --clean --if-exists --no-owner \
  "$work_dir/backup.dump"

printf 'Isolated restore completed into %s. Run scripts/verify-restored-database.sh next.\n' "$RESTORE_TARGET_DATABASE"
