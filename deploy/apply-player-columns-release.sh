#!/usr/bin/env bash
# The DB must expose canonical columns before callers are deployed.
set -eu
release=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
live=/opt/bitnami/apache/htdocs
preview=/home/bitnami/wesiper-weather-preview
backup="$release/backups"
test -f "$release/deploy/player-columns-state.json"
test -f "$release/deploy/player-api-baseline.json"
mkdir -p "$backup"
if [ ! -f "$backup/live-api.tar.gz" ]; then
    tar -czf "$backup/live-api.tar.gz" -C "$live" api lib
    tar -czf "$backup/preview-api.tar.gz" -C "$preview" api/playerProfile.php merge-player-profile.php
    cp -p /home/bitnami/wesiper/import_missing_pitcher_records.py "$backup/import_missing_pitcher_records.py"
    chmod 600 "$backup"/*
fi
publish_file() {
    install -m 644 "$1" "$2.player-columns-tmp"
    mv -f "$2.player-columns-tmp" "$2"
}
for file in kbodle/get_custom_kbodle.php kbodle/get_player_list.php kbodle/get_random_player.php kbodle/get_roster.php kbodle/get_today_kbodle.php 'kbodle/get_today_kbodle copy.php' kbocandle/get_player_list.php kbocandle/prediction_rankings.php playerProfile.php kbobingo/get_user_board.php kbobingo/pick_data.php kbobingo/pick_data_most.php kbobingo/pick_data_test.php kbobingo/search.php kbobingo/search_test.php; do
    publish_file "$release/backend/api/$file" "$live/api/$file"
done
publish_file "$release/backend/lib/player-school.php" "$live/lib/player-school.php"
publish_file "$release/crawler/import_missing_pitcher_records.py" /home/bitnami/wesiper/import_missing_pitcher_records.py
publish_file "$release/backend/api/playerProfile.php" "$preview/api/playerProfile.php"
publish_file "$release/deploy/merge-player-profile.php" "$preview/merge-player-profile.php"
# Gracefully replace FPM workers so cached callers finish before old columns go.
fpm_pid=$(cat /opt/bitnami/php/var/run/php-fpm.pid)
case "$fpm_pid" in ''|*[!0-9]*) echo 'Invalid PHP-FPM PID' >&2; exit 1;; esac
test "$(readlink -f "/proc/$fpm_pid/exe")" = /opt/bitnami/php/sbin/php-fpm
kill -USR2 "$fpm_pid"
echo 'Canonical API, preview and crawler files deployed. DB cutover remains separate.'
