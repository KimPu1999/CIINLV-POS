#!/usr/bin/env bash
set -Eeuo pipefail

umask 077
export LC_ALL=C

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${BACKUP_ENCRYPTION_KEY:?BACKUP_ENCRYPTION_KEY is required; backups are encrypted at rest}"

backup_root="${BACKUP_DIR:-storage/app/private/backups}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

mkdir -p "$backup_root/daily" "${STORAGE_BACKUP_DIR:-$backup_root/storage}"
export PGPASSWORD="$DB_PASSWORD"

dump_file="$work_dir/${DB_DATABASE}-${timestamp}.dump"
archive_file="$backup_root/daily/${DB_DATABASE}-${timestamp}.dump.enc"

pg_dump \
  --host="$DB_HOST" \
  --port="$DB_PORT" \
  --username="$DB_USERNAME" \
  --dbname="$DB_DATABASE" \
  --format=custom \
  --no-owner \
  --no-acl \
  --file="$dump_file"

openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
  -pass env:BACKUP_ENCRYPTION_KEY \
  -in "$dump_file" -out "$archive_file"

if [[ -d "${PRIVATE_STORAGE_PATH:-storage/app/private}" ]]; then
  storage_archive="${STORAGE_BACKUP_DIR:-$backup_root/storage}/${DB_DATABASE}-${timestamp}-storage.tar.gz.enc"
  tar -czf "$work_dir/storage.tar.gz" --exclude='./backups' -C "${PRIVATE_STORAGE_PATH:-storage/app/private}" .
  openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
    -pass env:BACKUP_ENCRYPTION_KEY \
    -in "$work_dir/storage.tar.gz" -out "$storage_archive"
fi

if command -v sha256sum >/dev/null 2>&1; then
  sha256sum "$archive_file" > "${archive_file}.sha256"
else
  shasum -a 256 "$archive_file" > "${archive_file}.sha256"
fi
printf 'Encrypted PostgreSQL backup written to %s\n' "$archive_file"
