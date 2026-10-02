#!/usr/bin/env bash
# Run with sudo from the private release folder after staged API validation.
set -eu
release=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
live=/opt/bitnami/apache/htdocs
preview=/home/bitnami/wesiper-weather-preview
php=/opt/bitnami/php/bin/php
backup="$release/backups"
test -f "$release/deploy/player-api-baseline.json"
test -f "$release/deploy/player-data-migration-state.json"
mkdir -p "$backup"
# Preserve the first backup on retries.
if [ ! -f "$backup/live-api.tar.gz" ]; then
    tar -czf "$backup/live-api.tar.gz" -C "$live" api
    cp -p /home/bitnami/wesiper/import_missing_pitcher_records.py "$backup/import_missing_pitcher_records.py"
    tar -czf "$backup/preview-api.tar.gz" -C "$preview" api/playerProfile.php merge-player-profile.php
    chmod 600 "$backup"/*
fi
"$php" -l "$release/deploy/migrate-player-data.php"
"$php" "$release/deploy/migrate-player-data.php" --cutover

publish_file() {
    source=$1
    target=$2
    install -m 644 "$source" "$target.player-migration-tmp"
    mv -f "$target.player-migration-tmp" "$target"
}

# The repo uses a shared loader at config/database.php. Keep it protected from
# HTTP access when backend/ is deployed as the Apache document root.
mkdir -p "$live/config"
publish_file "$release/backend/config/.htaccess" "$live/config/.htaccess"
publish_file "$release/backend/config/database.php" "$live/config/database.php"

# The compatibility view exposes p_no and is_kbodle while old common.php is
# still active. Publish all callers first, then switch the common table name.
for file in get_custom_kbodle.php get_player_list.php get_random_player.php get_roster.php get_today_kbodle.php 'get_today_kbodle copy.php'; do
    publish_file "$release/backend/api/kbodle/$file" "$live/api/kbodle/$file"
done
for file in kbocandle/get_player_list.php kbocandle/prediction_rankings.php playerProfile.php; do
    publish_file "$release/backend/api/$file" "$live/api/$file"
done
publish_file "$release/crawler/import_missing_pitcher_records.py" /home/bitnami/wesiper/import_missing_pitcher_records.py
publish_file "$release/backend/api/playerProfile.php" "$preview/api/playerProfile.php"
publish_file "$release/deploy/merge-player-profile.php" "$preview/merge-player-profile.php"
# Reset PHP workers once with the old common.php and migrated callers, so
# cached old callers finish against the filtered compatibility view.
/opt/bitnami/apache/bin/apachectl -k graceful
sleep 3
publish_file "$release/backend/api/kbodle/common.php" "$live/api/kbodle/common.php"
publish_file "$release/backend/api/kbobingo/common.php" "$live/api/kbobingo/common.php"
/opt/bitnami/apache/bin/apachectl -k graceful
"$php" "$release/deploy/migrate-player-data.php" --verify
echo 'Player DB, API, preview and crawler release applied. Event editing remains with the user.'
