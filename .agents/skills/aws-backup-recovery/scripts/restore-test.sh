#!/usr/bin/env bash
# ==============================================================================
# Script: restore-test.sh
# Purpose: Isolated, non-destructive test restoration of Online Exam DB backups
# Target: Isolated temporary database on MariaDB (online_exam_restore_test_*)
# Safety: Strictly refuses to restore into online_exam_db
# ==============================================================================

set -euo pipefail

log() {
    echo "[$(date -u +'%Y-%m-%d %H:%M:%S UTC')] $*"
}

BACKUP_PATH="${1:-}"

if [ -z "${BACKUP_PATH}" ]; then
    echo "Usage: $0 </path/to/backup.sql.gz | s3://bucket/prefix/backup.sql.gz>"
    exit 1
fi

TEMP_DIR="/var/backups/mariadb/restore_test_tmp"
mkdir -p "${TEMP_DIR}"
chmod 700 "${TEMP_DIR}"

LOCAL_FILE=""
CLEANUP_LOCAL=0

cleanup() {
    if [ "${CLEANUP_LOCAL}" -eq 1 ] && [ -n "${LOCAL_FILE}" ] && [ -f "${LOCAL_FILE}" ]; then
        rm -f "${LOCAL_FILE}" "${LOCAL_FILE}.sha256" 2>/dev/null || true
    fi
    rm -rf "${TEMP_DIR}" 2>/dev/null || true
}
trap cleanup EXIT

# 1. Resolve source: Local file vs S3 URI
if [[ "${BACKUP_PATH}" =~ ^s3:// ]]; then
    log "Downloading backup from S3: ${BACKUP_PATH}..."
    FILE_NAME=$(basename "${BACKUP_PATH}")
    LOCAL_FILE="${TEMP_DIR}/${FILE_NAME}"
    aws s3 cp "${BACKUP_PATH}" "${LOCAL_FILE}" --region ap-south-1
    aws s3 cp "${BACKUP_PATH}.sha256" "${LOCAL_FILE}.sha256" --region ap-south-1 2>/dev/null || true
    CLEANUP_LOCAL=1

    # Verify Checksum if present
    if [ -f "${LOCAL_FILE}.sha256" ]; then
        log "Verifying SHA256 checksum from S3..."
        EXPECTED_SHA=$(awk '{print $1}' "${LOCAL_FILE}.sha256")
        ACTUAL_SHA=$(sha256sum "${LOCAL_FILE}" | awk '{print $1}')
        if [ "${EXPECTED_SHA}" != "${ACTUAL_SHA}" ]; then
            log "ERROR: SHA256 mismatch! Expected ${EXPECTED_SHA}, got ${ACTUAL_SHA}."
            exit 1
        fi
        log "SHA256 checksum verified: ${ACTUAL_SHA}"
    fi
else
    LOCAL_FILE="${BACKUP_PATH}"
fi

if [ ! -f "${LOCAL_FILE}" ]; then
    log "ERROR: Backup file does not exist: ${LOCAL_FILE}"
    exit 1
fi

# 2. Safety Gate: Refuse production DB name
TIMESTAMP=$(date +%s)
TEST_DB="online_exam_restore_test_${TIMESTAMP}"

log "=== Isolated Restore Verification Started ==="
log "Source archive : ${BACKUP_PATH}"
log "Test database  : ${TEST_DB}"

# Guarantee production DB cannot be targeted
if [ "${TEST_DB}" = "online_exam_db" ]; then
    log "CRITICAL SAFETY VIOLATION: Cannot restore into production database!"
    exit 1
fi

# 3. Create isolated database
log "Creating temporary isolated database: ${TEST_DB}..."
mariadb -e "CREATE DATABASE \`${TEST_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

drop_test_db() {
    log "Dropping temporary isolated database: ${TEST_DB}..."
    mariadb -e "DROP DATABASE IF EXISTS \`${TEST_DB}\`;"
}
# Ensure test db is dropped on exit
trap 'drop_test_db; cleanup' EXIT

# 4. Decompress and import
log "Importing decompressed SQL dump into ${TEST_DB}..."
if [[ "${LOCAL_FILE}" =~ \.gz$ ]]; then
    zcat "${LOCAL_FILE}" | mariadb "${TEST_DB}"
else
    mariadb "${TEST_DB}" < "${LOCAL_FILE}"
fi
log "SQL import completed with exit status 0."

# 5. Validate table inventory
EXPECTED_TABLES=("admin" "app_jobs" "classes" "draft_answers" "exam_class_assignments" "exam_sessions" "exam_violations" "exams" "questions" "results" "student_answers" "students")
EXPECTED_COUNT=${#EXPECTED_TABLES[@]}

RESTORED_COUNT=$(mariadb -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = '${TEST_DB}';")
log "Restored table count: ${RESTORED_COUNT} (Expected: ${EXPECTED_COUNT})"

if [ "${RESTORED_COUNT}" -ne "${EXPECTED_COUNT}" ]; then
    log "ERROR: Restored table count (${RESTORED_COUNT}) does not match expected (${EXPECTED_COUNT})."
    exit 1
fi

for tbl in "${EXPECTED_TABLES[@]}"; do
    EXISTS=$(mariadb -N -e "SELECT 1 FROM information_schema.TABLES WHERE table_schema = '${TEST_DB}' AND table_name = '${tbl}';")
    if [ "${EXISTS}" != "1" ]; then
        log "ERROR: Missing required table in restored database: ${tbl}"
        exit 1
    fi
done
log "All ${EXPECTED_COUNT} core tables confirmed present in restored schema."

# 6. Validate row counts against production (without exposing student data)
log "Comparing aggregate row counts against production online_exam_db..."
ROW_MISMATCH=0
for tbl in "${EXPECTED_TABLES[@]}"; do
    PROD_ROWS=$(mariadb -N -e "SELECT COUNT(*) FROM \`online_exam_db\`.\`${tbl}\`;")
    TEST_ROWS=$(mariadb -N -e "SELECT COUNT(*) FROM \`${TEST_DB}\`.\`${tbl}\`;")
    if [ "${PROD_ROWS}" -ne "${TEST_ROWS}" ]; then
        log "WARNING: Row count mismatch on table ${tbl} (Production: ${PROD_ROWS}, Restored: ${TEST_ROWS})"
        ROW_MISMATCH=1
    else
        log "  • Table '${tbl}': ${TEST_ROWS} rows [MATCH]"
    fi
done

if [ "${ROW_MISMATCH}" -ne 0 ]; then
    log "ERROR: One or more table row counts differed between production and restored dump."
    exit 1
fi

log "=== Isolated Restore Verification PASSED Successfully ==="
log "Backup archive is 100% verified and fully restorable."
exit 0
