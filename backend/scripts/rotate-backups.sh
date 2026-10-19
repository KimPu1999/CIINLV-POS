#!/usr/bin/env bash
set -Eeuo pipefail

backup_root="${BACKUP_DIR:-storage/app/private/backups}"
daily_days="${BACKUP_DAILY_RETENTION_DAYS:-14}"
weekly_weeks="${BACKUP_WEEKLY_RETENTION_WEEKS:-8}"
monthly_months="${BACKUP_MONTHLY_RETENTION_MONTHS:-12}"

find "$backup_root/daily" -type f -name '*.dump.enc' -mtime "+$daily_days" -delete 2>/dev/null || true
find "$backup_root/daily" -type f -name '*.sha256' -mtime "+$daily_days" -delete 2>/dev/null || true
find "$backup_root/weekly" -type f -mtime "+$((weekly_weeks * 7))" -delete 2>/dev/null || true
find "$backup_root/monthly" -type f -mtime "+$((monthly_months * 31))" -delete 2>/dev/null || true

printf 'Backup retention rotation completed for %s\n' "$backup_root"
