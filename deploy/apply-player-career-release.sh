#!/usr/bin/env bash
set -eu
release=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
live=/opt/bitnami/apache/htdocs
preview=/home/bitnami/wesiper-weather-preview
backup="$release/backups"
test -f "$release/deploy/player-career-state.json"
mkdir -p "$backup"
if [ ! -f "$backup/live-api.tar.gz" ]; then
    tar -czf "$backup/live-api.tar.gz" -C "$live" api lib
    tar -czf "$backup/preview-api.tar.gz" -C "$preview" api/playerProfile.php
    cp -p /home/bitnami/wesiper/import_missing_pitcher_records.py "$backup/import_missing_pitcher_records.py"
    chmod 600 "$backup"/*
fi
publish_file() {
    install -m 644 "$1" "$2.player-career-tmp"
    mv -f "$2.player-career-tmp" "$2"
}
for file in kbobingo/search.php kbodle/get_player_list.php kbocandle/get_player_list.php playerProfile.php; do
    publish_file "$release/backend/api/$file" "$live/api/$file"
done
rm -f -- "$live/api/kbobingo/search_test.php"
publish_file "$release/backend/lib/player-career.php" "$live/lib/player-career.php"
publish_file "$release/crawler/import_missing_pitcher_records.py" /home/bitnami/wesiper/import_missing_pitcher_records.py
publish_file "$release/backend/api/playerProfile.php" "$preview/api/playerProfile.php"
fpm_pid=$(cat /opt/bitnami/php/var/run/php-fpm.pid)
case "$fpm_pid" in ''|*[!0-9]*) echo 'Invalid PHP-FPM PID' >&2; exit 1;; esac
test "$(readlink -f "/proc/$fpm_pid/exe")" = /opt/bitnami/php/sbin/php-fpm
kill -USR2 "$fpm_pid"
touch "$release/deploy/player-career-api-deployed"
echo 'Career API/helper/crawler deployed; old-column removal is a separate step.'
