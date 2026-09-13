import os
import pymysql
from collections import defaultdict

def parse_kbo_result(pa_txt):
    """PHP 로직과 100% 동일한 기초 스탯 판독기"""
    clean = str(pa_txt).strip()
    if not clean: return None
    
    if clean in ['4구', '사구', '고4', '볼넷'] or '사사구' in clean or '볼넷' in clean:
        return {'ab': 0, 'h': 0, 'tb': 0, 'obp': 1}
    if '희비' in clean or '희플' in clean:
        return {'ab': 0, 'h': 0, 'tb': 0, 'obp': 0}
    if '희번' in clean or '희타' in clean or '희실' in clean:
        return {'ab': 0, 'h': 0, 'tb': 0, 'obp': 0}

    last_char = clean[-1] if len(clean) > 0 else ''
    if last_char == '안': return {'ab': 1, 'h': 1, 'tb': 1, 'obp': 1}
    if last_char == '2': return {'ab': 1, 'h': 1, 'tb': 2, 'obp': 1}
    if last_char == '3': return {'ab': 1, 'h': 1, 'tb': 3, 'obp': 1}
    if last_char == '홈': return {'ab': 1, 'h': 1, 'tb': 4, 'obp': 1}

    return {'ab': 1, 'h': 0, 'tb': 0, 'obp': 0}

def aggregate_league_eff_stats(target_year):
    conn = pymysql.connect(
        host=os.environ["DB_HOST"],
        user=os.environ["DB_USER"],
        password=os.environ["DB_PASSWORD"],
        db=os.environ["DB_NAME"],
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )
    
    try:
        with conn.cursor() as cursor:
            # 1. 일자별 리그 유효 스탯 테이블 생성 (네 변수 $cum_eff_ob 기준)
            cursor.execute("""
                CREATE TABLE IF NOT EXISTS kbo_league_records (
                    year INT,
                    game_date DATE,
                    cum_ab INT, cum_h INT, cum_ob INT, cum_sf INT, cum_tb INT,
                    cum_eff_ab INT, cum_eff_tb INT, cum_eff_h INT, cum_eff_ob INT,
                    league_obp DECIMAL(5,4), league_slg DECIMAL(5,4), league_ops DECIMAL(5,4),
                    league_eff_obp DECIMAL(5,4), league_eff_slg DECIMAL(5,4), league_eff_ops DECIMAL(5,4),
                    PRIMARY KEY (year, game_date)
                )
            """)
            cursor.execute('SHOW COLUMNS FROM kbo_league_records')
            available_columns = {row['Field'] for row in cursor.fetchall()}
            
            # 2. 해당 시즌 전 구단 타석 데이터 시간순 싹쓸이
            sql = """
                SELECT game_date, pa_result, sb, cs 
                FROM kbo_season_records 
                WHERE YEAR(game_date) = %s 
                ORDER BY game_date ASC, game_id ASC, inning ASC
            """
            cursor.execute(sql, (target_year,))
            rows = cursor.fetchall()
            
            if not rows:
                print(f"[{target_year}] 데이터가 존재하지 않습니다.")
                return

            # 3. 리그 전체 전역 누적 변수
            cum_ab = 0; cum_h = 0; cum_ob = 0; cum_sf = 0; cum_tb = 0
            cum_eff_ab = 0; cum_eff_tb = 0; cum_eff_h = 0; cum_eff_ob = 0
            
            # 날짜별 그룹핑
            rows_by_date = defaultdict(list)
            for r in rows:
                rows_by_date[r['game_date']].append(r)
                
            # 4. 시간순 절차적 일일 합산 루프
            for d in sorted(rows_by_date.keys()):
                for row in rows_by_date[d]:
                    parsed = parse_kbo_result(row['pa_result'])
                    if not parsed: continue
                    
                    sb = int(row['sb'] or 0)
                    cs = int(row['cs'] or 0)
                    pa_txt = str(row['pa_result']).strip()
                    
                    eff_ab = parsed['ab']; eff_tb = parsed['tb']; eff_h = parsed['h']
                    is_on_base = (parsed['h'] > 0 or parsed['obp'] > 0)
                    
                    # 네가 PHP에 짠 무결점 로직 그대로 적용
                    if is_on_base:
                        if sb > 0 and cs > 0:
                            eff_h = 0; eff_tb = 0
                            if parsed['h'] == 0:
                                eff_ab = 1; cum_eff_ob -= 1
                        elif cs > 0:
                            eff_h = 0; eff_tb = 0
                            if parsed['h'] == 0:
                                eff_ab = 1; cum_eff_ob -= 1
                        elif sb > 0:
                            eff_tb += sb
                    else:
                        if sb > 0: eff_ab = 0
                    
                    bb = 1 if pa_txt in ['4구', '볼넷', '고4'] or '볼넷' in pa_txt else 0
                    hbp = 1 if '사구' in pa_txt else 0
                    sf = 1 if '희비' in pa_txt or '희플' in pa_txt else 0
                            
                    cum_ab += parsed['ab']; cum_h += parsed['h']; cum_tb += parsed['tb']
                    cum_ob += (bb + hbp); cum_sf += sf
                    
                    cum_eff_ab += eff_ab; cum_eff_tb += eff_tb; cum_eff_h += eff_h
                    cum_eff_ob += (bb + hbp)
                
                # 5. 당일 마감 시점의 리그 합산 지표 산출
                obp_den = (cum_ab + cum_ob + cum_sf)
                league_obp = (cum_h + cum_ob) / obp_den if obp_den > 0 else 0
                league_slg = (cum_tb / cum_ab) if cum_ab > 0 else 0
                league_ops = league_obp + league_slg
                
                # 순수 유효 변수만으로 조립된 리그 유효 지표
                eff_obp_den = cum_eff_ab + cum_eff_ob + cum_sf
                league_eff_obp = (cum_eff_h + cum_eff_ob) / eff_obp_den if eff_obp_den > 0 else 0
                league_eff_slg = (cum_eff_tb / cum_eff_ab) if cum_eff_ab > 0 else 0
                league_eff_ops = league_eff_obp + league_eff_slg
                
                # 6. 통계 인서트 (REPLACE로 돌려서 덮어쓰기 안전함)
                insert_sql = """
                    REPLACE INTO kbo_league_records (
                        year, game_date, 
                        cum_ab, cum_h, cum_ob, cum_sf, cum_tb,
                        cum_eff_ab, cum_eff_tb, cum_eff_h, cum_eff_ob,
                        league_obp, league_slg, league_ops,
                        league_eff_obp, league_eff_slg, league_eff_ops
                    ) VALUES (
                        %s, %s,
                        %s, %s, %s, %s, %s,
                        %s, %s, %s, %s,
                        %s, %s, %s,
                        %s, %s, %s
                    )
                """
                insert_values = (
                    target_year, d,
                    cum_ab, cum_h, cum_ob, cum_sf, cum_tb,
                    cum_eff_ab, cum_eff_tb, cum_eff_h, cum_eff_ob,
                    round(league_obp, 4), round(league_slg, 4), round(league_ops, 4),
                    round(league_eff_obp, 4), round(league_eff_slg, 4), round(league_eff_ops, 4)
                )
                # Production stores cumulative totals only; derived rate columns
                # are optional. Keep the existing schema without ALTER TABLE.
                columns = [
                    'year', 'game_date', 'cum_ab', 'cum_h', 'cum_ob', 'cum_sf', 'cum_tb',
                    'cum_eff_ab', 'cum_eff_tb', 'cum_eff_h', 'cum_eff_ob',
                    'league_obp', 'league_slg', 'league_ops',
                    'league_eff_obp', 'league_eff_slg', 'league_eff_ops',
                ]
                selected = [(column, value) for column, value in zip(columns, insert_values) if column in available_columns]
                insert_sql = 'REPLACE INTO kbo_league_records (' + ','.join(column for column, _ in selected) + ') VALUES (' + ','.join(['%s'] * len(selected)) + ')'
                cursor.execute(insert_sql, tuple(value for _, value in selected))
            
            conn.commit()
            print(f"✅ {target_year}시즌 리그 일자별 유효 스탯(eff_ops) 적재 완료.")
            
    finally:
        conn.close()

if __name__ == "__main__":
    for year in range(2018, 2027):
        aggregate_league_eff_stats(year)
