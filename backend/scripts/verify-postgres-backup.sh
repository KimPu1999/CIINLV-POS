#!/usr/bin/env bash
set -Eeuo pipefail
export LC_ALL=C

: "${BACKUP_ENCRYPTION_KEY:?BACKUP_ENCRYPTION_KEY is required}"
backup_file="${1:?Usage: verify-postgres-backup.sh /path/to/backup.dump.enc}"

work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
  -pass env:BACKUP_ENCRYPTION_KEY \
  -in "$backup_file" -out "$work_dir/backup.dump"
pg_restore --list "$work_dir/backup.dump" >/dev/null
printf 'Backup archive is decryptable and readable by pg_restore: %s\n' "$backup_file"
