"""Read existing DB only, or an immutable local snapshot. No source API calls."""
import gzip
import hashlib
import json
import os
from pathlib import Path

from prediction_model import aggregate, regular_bounds

SQL = '''SELECT PK,game_date,game_id,player_id,inning,pa_result
         FROM kbo_season_records WHERE game_date >= %s AND game_date <= %s
         ORDER BY game_date,game_id,player_id,inning,PK'''


def connect():
    import pymysql
    return pymysql.connect(host=os.environ['DB_HOST'], port=int(os.getenv('DB_PORT','3306')),
        user=os.environ['DB_USER'], password=os.environ['DB_PASSWORD'],
        database=os.environ['DB_NAME'], charset='utf8mb4')


def schedule_path():
    local = Path(__file__).resolve().parents[1] / 'backend/api/kbocandle/common.php'
    return local if local.exists() else Path('/opt/bitnami/apache/htdocs/api/kbocandle/common.php')


def load(snapshot=None, through='2026-12-31', start='2018-01-01'):
    if snapshot:
        with gzip.open(snapshot, 'rt', encoding='utf-8') as f:
            source = json.load(f)
        bounds = {int(k): tuple(v) for k,v in source['bounds'].items()}
        rows = [r for r in source['rows'] if start <= r[1] <= through]
    else:
        bounds = regular_bounds(schedule_path().read_text(encoding='utf-8'))
        conn = connect()
        try:
            with conn.cursor() as cur:
                cur.execute(SQL, (start, through))
                rows = [[pk,str(day),gid,player,inning,text] for pk,day,gid,player,inning,text in cur.fetchall()]
        finally:
            conn.close()
    encoded = json.dumps({'bounds':bounds,'rows':rows}, ensure_ascii=False, separators=(',',':')).encode()
    return aggregate(rows,bounds), hashlib.sha256(encoded).hexdigest(), bounds, len(rows)
