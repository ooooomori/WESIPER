#!/usr/bin/env bash
# Publish only player-profile API code; no DB/config/candle changes or restart.
set -euo pipefail
release=/home/bitnami/wesiper-season-api-release-20261001
php=/opt/bitnami/php/bin/php
changed='player-season-totals.php player-season-schedule.php player-records.php player-year-records.php player-rankings.php player-overview-cache.php player-year-metrics.php'
dependencies='player-year-metrics.php player-year-league.php player-fielding-records.php player-game-seasons.php player-streaks.php'
for file in $changed $dependencies; do "$php" -l "$release/lib/$file" >/dev/null; done
"$php" -l "$release/api/playerProfile.php" >/dev/null
publish() { install -m 644 "$1" "$2.season-api-tmp"; mv -f "$2.season-api-tmp" "$2"; }
for target in /opt/bitnami/apache/htdocs /home/bitnami/wesiper-weather-preview; do
  for file in $dependencies; do
    if [ ! -f "$target/lib/$file" ]; then publish "$release/lib/$file" "$target/lib/$file"; fi
  done
  for file in $changed; do publish "$release/lib/$file" "$target/lib/$file"; done
  publish "$release/api/playerProfile.php" "$target/api/playerProfile.php"
done
sha256sum --check "$release/backups/candle-before.sha256" >/dev/null
echo 'Player profile season APIs published; candle files unchanged.'
