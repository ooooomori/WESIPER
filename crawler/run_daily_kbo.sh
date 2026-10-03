#!/usr/bin/env bash
# Each cron invocation runs one job, with its own lock, exit status and log.
set -euo pipefail
umask 077
export TZ=Asia/Seoul
export PYTHONUNBUFFERED=1
root="${WESIPER_CRAWLER_ROOT:-/home/bitnami/wesiper}"
env_file="${WESIPER_CRAWLER_ENV:-/home/bitnami/.config/wesiper/crawler.env}"
job="${1:-}"
case "$job" in candle|futures|movements|fielding) ;; *) echo 'Usage: run_daily_kbo.sh candle|futures|movements|fielding [crawler options]' >&2; exit 2 ;; esac
shift
mkdir -p "$root/logs" "$root/locks"
exec >> "$root/logs/$job-$(date +%F).log" 2>&1
echo "$(date --iso-8601=seconds) START $job"
exec 9> "$root/locks/$job.lock"
if ! flock -n 9; then echo "$(date --iso-8601=seconds) SKIP $job already running"; exit 0; fi
finish() { status=$?; echo "$(date --iso-8601=seconds) END $job exit=$status"; }
trap finish EXIT
set -a
source "$env_file"
set +a
: "${DB_HOST:?}" "${DB_USER:?}" "${DB_PASSWORD:?}" "${DB_NAME:?}"
cd "$root"
python="$root/.venv/bin/python"
if [[ "$job" == candle ]]; then
    if [[ $# == 0 ]]; then set -- --write; fi
    "$python" "$root/kbo_candle_crawl.py" "$@"
elif [[ "$job" == futures ]]; then
    if [[ $# == 0 ]]; then set -- --write; fi
    "$python" "$root/kbo_futures_crawl.py" --year "$(date +%Y)" --daily "$@"
elif [[ "$job" == fielding ]]; then
    if [[ $# == 0 ]]; then set -- --write; fi
    "$python" "$root/kbo_fielding_crawl.py" --year "$(date +%Y)" "$@"
else
    if [[ $# == 0 ]]; then set -- --write; fi
    "$python" "$root/daily_movement_contracts.py" "$@"
fi
