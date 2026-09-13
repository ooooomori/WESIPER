#!/bin/bash
set -eu
set -a
. /home/bitnami/.config/wesiper/crawler.env
set +a
cd /home/bitnami/wesiper
mkdir -p backfill-2014-2017
set +e
.venv/bin/python -u backfill_seasons.py --start-year 2014 --end-year 2017
result=$?
printf '%s\n' "$result" > backfill-2014-2017/exit-code.txt
exit "$result"
