#!/bin/sh
# Cleans the upload directories (file with Windows line endings, CRLF)
UPLOAD_DIR=${UPLOAD_DIR-/srv/app/uploads}
MAX_MB=${MAX_MB:-500}
LOCK=/tmp/cleanup.lock

cleanup() {
    rm -f "$LOCK"
}
trap cleanup EXIT INT TERM

if [ -e "$LOCK" ]; then
    echo "Already running (PID $(cat "$LOCK"))" >&2
    exit 1
fi
echo $$ > "$LOCK"

exec 3>&1 1>>/var/log/cleanup.log 2>&1

used=$(du -sm "$UPLOAD_DIR" | awk '{ print $1 }')
echo "Used: ${used} MB of ${MAX_MB} MB" >&3

attempt=0
until [ "$used" -le "$MAX_MB" ] || [ "$attempt" -ge 5 ]; do
    oldest=$(ls -tr "$UPLOAD_DIR" | head -n 1)
    [ -n "$oldest" ] || break
    rm -rf -- "${UPLOAD_DIR:?}/$oldest"
    attempt=$((attempt + 1))
    used=$(du -sm "$UPLOAD_DIR" | awk '{ print $1 }')
done

for entry in "$UPLOAD_DIR"/*.tmp "$UPLOAD_DIR"/.[!.]*; do
    test -f "$entry" || continue
    case $entry in
        *.tmp) rm -- "$entry" ;;
        */.DS_Store|*/Thumbs.db) rm -- "$entry" && echo "removed: $entry" ;;
        *) : ;;
    esac
done

find "$UPLOAD_DIR" -type d -empty -mindepth 1 -exec rmdir {} + 2>/dev/null
echo "Done after $attempt passes" | mail -s "Cléanup $(hostname)" admin@example.com
exit $?
