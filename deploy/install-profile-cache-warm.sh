#!/usr/bin/env bash
# 선수 프로필 리그 집계 캐시 예열 예약 작업 설치 (일일 크롤링 02:00 직후 02:05, 보조 06:05 KST).
# 운영 htdocs/lib을 그대로 써서 php-fpm과 같은 캐시 키를 채운다.
# 사용: bash install-profile-cache-warm.sh <warm-profile-caches.php를 올린 임시 폴더>
set -euo pipefail
stage="${1:?stage directory required}"
dest=/home/bitnami/wesiper/cache-warm
mkdir -p "$dest"
cp "$stage/warm-profile-caches.php" "$dest/"
chmod -R a+rX "$dest"
touch "$dest/warm.log" && chmod 666 "$dest/warm.log"
[[ "$(timedatectl show -p Timezone --value)" == Asia/Seoul ]] || { echo 'Cron host timezone must be Asia/Seoul' >&2; exit 1; }
cmd="PATH=\$PATH:/opt/bitnami/php/bin; sudo -n -u daemon \$(command -v php) $dest/warm-profile-caches.php /opt/bitnami/apache/htdocs/lib /opt/bitnami/apache/conf/wesiper-db.php >> $dest/warm.log 2>&1"
{ crontab -l 2>/dev/null | grep -v 'warm-profile-caches.php' | grep -v '^# 선수 프로필 캐시 예열' || true; echo '# 선수 프로필 캐시 예열 (일일 크롤링 직후)'; echo "5 2 * * * $cmd"; echo "5 6 * * * $cmd"; } | crontab -
echo "installed to $dest"
crontab -l | grep -n 'warm-profile-caches' || true
