#!/usr/bin/env bash
# Restore into a target database (use a SCRATCH database for the monthly restore test).
# Usage: restore.sh <db-backup.sql.gz.gpg> <target_database>
set -euo pipefail
FILE="$1"; TARGET="$2"
: "${DB_HOST:?}" "${DB_USERNAME:?}" "${DB_PASSWORD:?}" "${BACKUP_PASSPHRASE_FILE:?}"
export MYSQL_PWD="$DB_PASSWORD"
mysql --host="$DB_HOST" --user="$DB_USERNAME" -e "CREATE DATABASE IF NOT EXISTS \`$TARGET\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gpg --batch --decrypt --passphrase-file "$BACKUP_PASSPHRASE_FILE" "$FILE" | gunzip | mysql --host="$DB_HOST" --user="$DB_USERNAME" "$TARGET"
# Smoke check
mysql --host="$DB_HOST" --user="$DB_USERNAME" -N -e "SELECT COUNT(*) AS tenants FROM \`$TARGET\`.tenants; SELECT COUNT(*) AS appointments FROM \`$TARGET\`.appointments;"
echo "restore ok into $TARGET"
