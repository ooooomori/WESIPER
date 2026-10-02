#!/usr/bin/env bash
# 올해 시즌 리그 1위를 매일 03:10(KST)에 다시 계산하는 예약 작업 설치.
# 사용: bash install-year-leaders-daily.sh <업로드한 임시 폴더>
set -euo pipefail
stage="${1:?stage directory required}"
dest=/home/bitnami/wesiper/year-leaders
mkdir -p "$dest/lib"
cp "$stage"/lib/*.php "$dest/lib/"
cp "$stage/build-year-leaders.php" "$dest/"
chmod -R a+rX "$dest"
touch "$dest/daily.log" && chmod 666 "$dest/daily.log"
[[ "$(timedatectl show -p Timezone --value)" == Asia/Seoul ]] || { echo 'Cron host timezone must be Asia/Seoul' >&2; exit 1; }
line="10 3 * * * PATH=\$PATH:/opt/bitnami/php/bin; sudo -n -u daemon \$(command -v php) $dest/build-year-leaders.php --apply --year=\$(date +\\%Y) /opt/bitnami/apache/conf/wesiper-db.php >> $dest/daily.log 2>&1"
{ crontab -l 2>/dev/null | grep -v 'build-year-leaders.php' || true; echo '# 올해 시즌 리그 1위 갱신 (일일 크롤링 02:00 이후)'; echo "$line"; } | crontab -
echo "installed to $dest"
crontab -l | grep -n 'build-year-leaders' || true
