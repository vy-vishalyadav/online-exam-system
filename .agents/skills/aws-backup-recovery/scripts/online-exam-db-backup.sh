#!/usr/bin/env bash
# ==============================================================================
# Script: online-exam-db-backup.sh
# Purpose: Production-safe, low-cost MariaDB logical backup with S3 replication
# Target: online_exam_db on EC2 (ap-south-1)
# Schedule: Daily via systemd timer (online-exam-backup.timer)
# Security: Root-owned, chmod 700, zero passwords in code or process tables
# ==============================================================================

set -euo pipefail

# Configuration
DB_NAME="online_exam_db"
BACKUP_DIR="/var/backups/mariadb"
LOCK_FILE="/var/run/online-exam-backup.lock"
S3_BUCKET="online-exam-production-backups-aps1-9032915"
S3_BASE_PREFIX="online-exam/mariadb"
AWS_REGION="ap-south-1"
RETENTION_DAYS=7
MIN_FREE_DISK_MB=500

# Logging Helper
log() {
    echo "[$(date -u +'%Y-%m-%d %H:%M:%S UTC')] $*"
}

log "=== Starting MariaDB Database Backup for ${DB_NAME} ==="

# 1. Acquire exclusive lock to prevent overlapping runs
exec 200>"${LOCK_FILE}"
if ! flock -n 200; then
    log "ERROR: Another backup process is currently holding the lock (${LOCK_FILE}). Aborting."
    exit 1
fi

# 2. Ensure protected backup directory exists with restrictive permissions
if [ ! -d "${BACKUP_DIR}" ]; then
    log "Creating protected backup directory ${BACKUP_DIR}..."
    mkdir -p "${BACKUP_DIR}"
    chmod 700 "${BACKUP_DIR}"
    chown root:root "${BACKUP_DIR}"
fi

# 3. Disk space pre-check
AVAIL_DISK_MB=$(df -P -m "${BACKUP_DIR}" | awk 'NR==2 {print $4}')
log "Available disk space on ${BACKUP_DIR}: ${AVAIL_DISK_MB} MB"
if [ "${AVAIL_DISK_MB}" -lt "${MIN_FREE_DISK_MB}" ]; then
    log "ERROR: Insufficient disk space (${AVAIL_DISK_MB} MB available < ${MIN_FREE_DISK_MB} MB required). Aborting backup to protect server."
    exit 1
fi

# 4. Generate timestamped filenames
NOW_UTC=$(date -u +'%Y-%m-%d_%H-%M-%S')
YEAR=$(date -u +'%Y')
MONTH=$(date -u +'%m')
DAY=$(date -u +'%d')

BASE_NAME="online_exam_db_${NOW_UTC}"
GZ_FILE="${BACKUP_DIR}/${BASE_NAME}.sql.gz"
SHA_FILE="${BACKUP_DIR}/${BASE_NAME}.sql.gz.sha256"
TEMP_GZ="${BACKUP_DIR}/${BASE_NAME}.sql.gz.tmp"

# Clean up temporary file on exit if left behind
cleanup() {
    if [ -f "${TEMP_GZ}" ]; then
        rm -f "${TEMP_GZ}"
    fi
}
trap cleanup EXIT

# 5. Execute transactional logical dump directly compressed to gzip
log "Executing mariadb-dump (--single-transaction --quick --routines --triggers --no-tablespaces)..."
if ! mariadb-dump \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --no-tablespaces \
    "${DB_NAME}" | gzip -9 > "${TEMP_GZ}"; then
    log "ERROR: mariadb-dump execution failed."
    exit 1
fi

mv "${TEMP_GZ}" "${GZ_FILE}"
chmod 600 "${GZ_FILE}"
chown root:root "${GZ_FILE}"

# 6. Verify backup file existence and size
if [ ! -s "${GZ_FILE}" ]; then
    log "ERROR: Generated backup file ${GZ_FILE} is empty or missing."
    rm -f "${GZ_FILE}"
    exit 1
fi

BACKUP_SIZE_BYTES=$(stat -c%s "${GZ_FILE}")
log "Logical backup generated successfully: ${GZ_FILE} (${BACKUP_SIZE_BYTES} bytes)"

# 7. Verify gzip archive integrity
log "Testing gzip archive integrity..."
if ! gzip -t "${GZ_FILE}"; then
    log "ERROR: Gzip integrity check failed for ${GZ_FILE}."
    exit 1
fi
log "Archive integrity verified OK."

# 8. Calculate SHA256 checksum
log "Computing SHA256 checksum..."
(
    cd "${BACKUP_DIR}"
    sha256sum "$(basename "${GZ_FILE}")" > "$(basename "${SHA_FILE}")"
)
chmod 600 "${SHA_FILE}"
chown root:root "${SHA_FILE}"

CHECKSUM=$(awk '{print $1}' "${SHA_FILE}")
log "SHA256: ${CHECKSUM}"

# 9. Replicate backup and checksum to private S3 bucket
S3_TARGET_DIR="s3://${S3_BUCKET}/${S3_BASE_PREFIX}/${YEAR}/${MONTH}/${DAY}"
log "Uploading backup archive to S3: ${S3_TARGET_DIR}/$(basename "${GZ_FILE}")..."

if ! aws s3 cp "${GZ_FILE}" "${S3_TARGET_DIR}/$(basename "${GZ_FILE}")" --region "${AWS_REGION}"; then
    log "ERROR: Failed to upload backup archive to S3."
    exit 1
fi

log "Uploading checksum manifest to S3: ${S3_TARGET_DIR}/$(basename "${SHA_FILE}")..."
if ! aws s3 cp "${SHA_FILE}" "${S3_TARGET_DIR}/$(basename "${SHA_FILE}")" --region "${AWS_REGION}"; then
    log "ERROR: Failed to upload checksum manifest to S3."
    exit 1
fi

# 10. Verify remote object via S3 HeadObject
log "Verifying remote S3 object integrity via HeadObject..."
S3_OBJECT_KEY="${S3_BASE_PREFIX}/${YEAR}/${MONTH}/${DAY}/$(basename "${GZ_FILE}")"
if ! aws s3api head-object --bucket "${S3_BUCKET}" --key "${S3_OBJECT_KEY}" --region "${AWS_REGION}" > /dev/null; then
    log "ERROR: HeadObject verification failed for s3://${S3_BUCKET}/${S3_OBJECT_KEY}."
    exit 1
fi
log "S3 upload confirmed and verified: s3://${S3_BUCKET}/${S3_OBJECT_KEY}"

# 11. Enforce local retention policy (prune local backups older than RETENTION_DAYS)
log "Pruning local backups older than ${RETENTION_DAYS} days in ${BACKUP_DIR}..."
find "${BACKUP_DIR}" -type f -name 'online_exam_db_*.sql.gz*' -mtime +"${RETENTION_DAYS}" -print -delete || true

# Assert that at least the current backup remains
if [ ! -f "${GZ_FILE}" ]; then
    log "CRITICAL ERROR: Newest backup ${GZ_FILE} was deleted during cleanup!"
    exit 1
fi

LOCAL_COUNT=$(find "${BACKUP_DIR}" -type f -name 'online_exam_db_*.sql.gz' | wc -l)
log "Local backups currently stored: ${LOCAL_COUNT} archive(s)."

log "=== MariaDB Database Backup Completed Successfully ==="
exit 0
