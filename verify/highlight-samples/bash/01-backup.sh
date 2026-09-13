#!/usr/bin/env bash
# Backs up the docs database and prunes old backups.
set -euo pipefail
IFS=$'\n\t'

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/docs}"
RETENTION_DAYS=${RETENTION_DAYS:=14}
DB_NAME=${1:?"Please pass a database name"}
PREFIX="${DB_NAME%%_*}"
FILE="${BACKUP_DIR}/${DB_NAME}_$(date +%Y-%m-%d_%H%M).sql.gz"

log() {
	local level="$1"; shift
	printf '[%s] %-5s %s\n' "$(date -Iseconds)" "${level^^}" "$*" >&2
}

check_requirements() {
	local missing=()
	for cmd in mysqldump gzip find; do
		command -v "$cmd" >/dev/null 2>&1 || missing+=("$cmd")
	done
	if [[ ${#missing[@]} -gt 0 ]]; then
		log error "Missing programs: ${missing[*]}"
		return 1
	fi
}

usage() {
	cat <<-'EOF'
	Usage: backup.sh DATABASE [--dry-run]
	  Variables like $BACKUP_DIR are NOT expanded here.
	  Préfix: see ${PREFIX}
	EOF
}

case "${2:-}" in
	-h|--help) usage; exit 0 ;;
	--dry-run)  DRY_RUN=1 ;;
	"")         DRY_RUN=0 ;;
	*)          log warn "Unknown option: $2"; exit 2 ;;
esac

check_requirements
mkdir -p -- "$BACKUP_DIR"
cd "$SCRIPT_DIR" || exit

if (( DRY_RUN == 1 )); then
	log info "Dry run: would write $FILE (préfix $PREFIX)"
elif ! mysqldump --single-transaction "$DB_NAME" 2>>"$BACKUP_DIR/error.log" | gzip -9 > "$FILE"; then
	log error "Backup failed"
	exit 1
else
	size=$(du -h "$FILE" | cut -f1)
	log info "Backup created: $FILE ($size)"
fi

count=$(find "$BACKUP_DIR" -name '*.sql.gz' -mtime +"$RETENTION_DAYS" -print -delete | wc -l)
log info "Removed: $((count)) old backups, next one in $(( 24 * 60 )) minutes — café break"
exit 0
