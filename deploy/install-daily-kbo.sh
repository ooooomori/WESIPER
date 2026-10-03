#!/usr/bin/env bash
set -euo pipefail
umask 077
stage="${1:?release directory required}"
root=/home/bitnami/wesiper
backup="/home/bitnami/deploy-backups/daily-kbo-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup"
files=(kbo_candle_crawl.py kbo_futures_crawl.py player_ingest.py run_daily_kbo.sh update_kbo_scoreboard.py kbo_futures_player_overrides.json prediction_data.py daily_movement_contracts.py movement_identity.py movement_identity_overrides.json player_identity_corrections.py collect_player_movements.py kbo_fielding_crawl.py)
for file in "${files[@]}"; do
    [[ ! -f "$root/$file" ]] || cp -p "$root/$file" "$backup/$file"
    cp "$stage/$file" "$root/$file.daily-release"
    chmod 644 "$root/$file.daily-release"
    mv -f "$root/$file.daily-release" "$root/$file"
done
chmod 755 "$root/run_daily_kbo.sh"
crontab -l > "$backup/crontab.before" 2>/dev/null || true
# Host cron clock must be KST; TZ also controls child processes and log dates.
[[ "$(timedatectl show -p Timezone --value)" == Asia/Seoul ]] || { echo 'Cron host timezone must be Asia/Seoul' >&2; exit 1; }
python3 - "$backup/crontab.before" "$backup/crontab.after" <<'PY'
from pathlib import Path
import sys
source=Path(sys.argv[1]).read_text()
lines=[line for line in source.splitlines() if not any(name in line for name in ('kbo_candle_crawl.py','kbo_futures_crawl.py','run_daily_kbo.sh'))]
lines += ['# Daily KBO ingestion: independent jobs at 02:00 KST (host Asia/Seoul).',
          '0 2 * * * /home/bitnami/wesiper/run_daily_kbo.sh candle',
          '0 2 * * * /home/bitnami/wesiper/run_daily_kbo.sh futures',
          '10 2 * * * /home/bitnami/wesiper/run_daily_kbo.sh fielding',
          '30 2 * * * /home/bitnami/wesiper/run_daily_kbo.sh movements']
Path(sys.argv[2]).write_text('\n'.join(lines)+'\n')
PY
crontab "$backup/crontab.after"
echo "Installed daily jobs; backup=$backup"
crontab -l
