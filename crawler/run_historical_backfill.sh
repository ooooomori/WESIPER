#!/bin/bash
set -eu
set -a
. /home/bitnami/.config/wesiper/crawler.env
set +a
cd /home/bitnami/wesiper
start_year=${1:-2014}
end_year=${2:-2017}
run_dir="backfill-${start_year}-${end_year}"
mkdir -p "$run_dir"
set +e
.venv/bin/python -u backfill_seasons.py --start-year "$start_year" --end-year "$end_year"
result=$?
printf '%s\n' "$result" > "$run_dir/exit-code.txt"
exit "$result"
