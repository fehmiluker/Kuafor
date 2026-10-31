#!/usr/bin/env bash
# Encrypted, consistent MySQL backup + uploaded files.
# Required env (e.g. /etc/kuafor/backup.env, chmod 600):
#   DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD BACKUP_DIR BACKUP_PASSPHRASE_FILE [RETENTION_DAYS] [APP_DIR]
set -euo pipefail
: "${DB_HOST:?}" "${DB_DATABASE:?}" "${DB_USERNAME:?}" "${DB_PASSWORD:?}" "${BACKUP_DIR:?}" "${BACKUP_PASSPHRASE_FILE:?}"
RETENTION_DAYS="${RETENTION_DAYS:-14}"
APP_DIR="${APP_DIR:-/var/www/kuafor/current}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
umask 077
mkdir -p "$BACKUP_DIR"
export MYSQL_PWD="$DB_PASSWORD"

# --single-transaction: consistent InnoDB snapshot without locking the app.
mysqldump --host="$DB_HOST" --user="$DB_USERNAME" --single-transaction --routines --triggers \
  --default-character-set=utf8mb4 --set-gtid-purged=OFF "$DB_DATABASE" \
  | gzip -9 \
  | gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase-file "$BACKUP_PASSPHRASE_FILE" \
  > "$BACKUP_DIR/db-$STAMP.sql.gz.gpg"

tar -C "$APP_DIR/storage/app" -czf - public \
  | gpg --batch --yes --symmetric --cipher-algo AES256 --passphrase-file "$BACKUP_PASSPHRASE_FILE" \
  > "$BACKUP_DIR/files-$STAMP.tar.gz.gpg"

sha256sum "$BACKUP_DIR"/*-"$STAMP".* > "$BACKUP_DIR/SHA256SUMS-$STAMP"
find "$BACKUP_DIR" -type f -mtime +"$RETENTION_DAYS" -delete
echo "backup ok: $STAMP"
# Ship off-site (object storage with versioning/immutability), e.g.:
# rclone copy "$BACKUP_DIR" remote:kuafor-backups --include "*-$STAMP*"
