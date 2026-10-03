#!/usr/bin/env bash
set -euo pipefail
runner="$(cd "$(dirname "$0")" && pwd)/run_daily_kbo.sh"
fixture="$(mktemp -d /tmp/wesiper-daily-test.XXXXXX)"
trap 'rm -rf -- "$fixture"' EXIT
mkdir -p "$fixture/.venv/bin" "$fixture/locks"
printf '%s\n' 'DB_HOST=test' 'DB_USER=test' 'DB_PASSWORD=test' 'DB_NAME=test' > "$fixture/env"
cat > "$fixture/.venv/bin/python" <<'SH'
#!/usr/bin/env bash
[[ "$TZ" == Asia/Seoul && "$DB_HOST" == test && "$DB_NAME" == test ]] || exit 9
echo "$*" >> "$WESIPER_CRAWLER_ROOT/calls"
case "$1" in *kbo_candle_crawl.py) exit 7 ;; *kbo_futures_crawl.py|*daily_movement_contracts.py|*kbo_fielding_crawl.py) exit 0 ;; *) exit 8 ;; esac
SH
chmod +x "$fixture/.venv/bin/python"
export WESIPER_CRAWLER_ROOT="$fixture" WESIPER_CRAWLER_ENV="$fixture/env"
if bash "$runner" candle; then echo 'Expected candle failure' >&2; exit 1; else [[ $? == 7 ]]; fi
bash "$runner" futures
bash "$runner" movements
bash "$runner" fielding
[[ $(wc -l < "$fixture/calls") == 4 ]]
flock "$fixture/locks/candle.lock" bash "$runner" candle
[[ $(wc -l < "$fixture/calls") == 4 ]]
flock "$fixture/locks/movements.lock" bash "$runner" movements
[[ $(wc -l < "$fixture/calls") == 4 ]]
grep -q 'END candle exit=7' "$fixture/logs/candle-"*.log
grep -q 'SKIP candle already running' "$fixture/logs/candle-"*.log
grep -q 'END futures exit=0' "$fixture/logs/futures-"*.log
grep -q -- "--year $(TZ=Asia/Seoul date +%Y) --daily --write" "$fixture/calls"
grep -q 'daily_movement_contracts.py --write' "$fixture/calls"
grep -q "kbo_fielding_crawl.py --year $(TZ=Asia/Seoul date +%Y) --write" "$fixture/calls"
echo 'PASS: KST, env loading, independent failure, duplicate lock, current year, dated logs'
