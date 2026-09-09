import os
import requests
import time
from datetime import datetime, timedelta
import re
import pymysql
from collections import defaultdict

DB_CONFIG = {
    "host": os.environ["DB_HOST"],
    "user": os.environ["DB_USER"],
    "password": os.environ["DB_PASSWORD"],
    "database": os.environ["DB_NAME"],
    "charset": "utf8mb4",
}

def fetch_yesterdays_kbo_game_ids():
    team_codes = ['SS', 'HT', 'NC', 'KT', 'LG', 'SK', 'OB', 'WO', 'LT', 'HH']
    unique_game_ids = set()

    yesterday = datetime.now() - timedelta(days=1)
    yesterday_str = yesterday.strftime('%Y-%m-%d')
    target_month_str = yesterday.strftime('%Y-%m-01')
    
    print(f"--- KBO 스케줄 단기 추적 개시 (타깃일: {yesterday_str}) ---")

    headers = {
        "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
        "Accept": "application/json",
        "Referer": "https://m.sports.naver.com/"
    }

    for team in team_codes:
        url = f"https://api-gw.sports.naver.com/schedule/calendar?upperCategoryId=kbaseball&categoryIds=kbo&date={target_month_str}&teamCode={team}"
        try:
            response = requests.get(url, headers=headers, timeout=10)
            response.raise_for_status()
            data = response.json()

            if data.get('code') != 200 or not data.get('success'):
                continue

            for day_data in data['result'].get('dates', []):
                game_date = day_data.get('ymd', '')
                if game_date != yesterday_str or day_data.get('gameCount', 0) == 0:
                    continue

                for game in day_data.get('gameInfos', []):
                    game_id = game.get('gameId')
                    status = game.get('statusCode', '')
                    if status not in ["CANCEL", "BEFORE"] and game_id:
                        unique_game_ids.add(game_id)
        except Exception as e:
            print(f"[{team}] API 교신 오류: {e}")
        time.sleep(0.5)

    sorted_game_ids = sorted(list(unique_game_ids))
    print(f"--- 작전 종료. 실효 경기 총 {len(sorted_game_ids)}건 확보 ---")
    return sorted_game_ids, yesterday_str

def extract_baseball_data(api_response, game_id):
    record_data = api_response.get('result', {}).get('recordData', {})
    sb_lookup, cs_lookup = {}, {}
    
    for record in record_data.get('etcRecords', []):
        how = record.get('how', '')
        is_sb = ('도루' in how and '도루자' not in how)
        is_cs = ('도루자' in how or '견제도루자' in how)
        if not (is_sb or is_cs): continue

        matches = re.findall(r'([가-힣a-zA-Z]+)(\d*)\s*\(([\d\s,]+)회\)', record.get('result', ''))
        for name, count_str, inn_str in matches:
            innings = [int(x) for x in inn_str.replace(',', ' ').split() if x.isdigit()]
            explicit_count = int(count_str) if count_str.isdigit() else 1
            target_inn = innings[0] if innings else 1

            if is_sb:
                sb_lookup[(name, target_inn)] = sb_lookup.get((name, target_inn), 0) + explicit_count
            else:
                cs_lookup[(name, target_inn)] = cs_lookup.get((name, target_inn), 0) + explicit_count

    extracted_pas = []
    batters_box = record_data.get('battersBoxscore', {})

    for team_type in ['away', 'home']:
        for batter in batters_box.get(team_type, []):
            name, pcode = batter.get('name'), batter.get('playerCode')
            for i in range(1, 26):
                inn_result = batter.get(f'inn{i}', "")
                current_sb, current_cs = sb_lookup.get((name, i), 0), cs_lookup.get((name, i), 0)

                if not inn_result and current_sb == 0 and current_cs == 0: continue

                if not inn_result:
                    extracted_pas.append({'player_id': pcode, 'player_name': name, 'inning': i, 'pa_result': "", 'sb': current_sb, 'cs': current_cs})
                else:
                    for pa_idx, pa_text in enumerate(inn_result.split('/')):
                        extracted_pas.append({
                            'player_id': pcode, 'player_name': name, 'inning': i, 'pa_result': pa_text.strip(),
                            'sb': current_sb if pa_idx == 0 else 0, 'cs': current_cs if pa_idx == 0 else 0
                        })
    return extracted_pas

def fetch_baseball_records(game_ids):
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
    crawled_data = []
    for game_id in game_ids:
        try:
            response = requests.get(f"https://api-gw.sports.naver.com/schedule/games/{game_id}/record", headers=headers, timeout=10)
            if response.status_code == 200:
                crawled_data.append({"game_id": game_id, "raw_data": response.json()})
        except Exception as e:
            print(f"데이터 추출 실패 ({game_id}): {e}")
        time.sleep(1.0)
    return crawled_data

def load_to_mysql(dataset):
    if not dataset:
        print("적재할 타깃 데이터가 존재하지 않습니다.")
        return

    print(f"--- DB 인서트 작전 개시: 총 {len(dataset)}개 행 ---")
    
    try:
        conn = pymysql.connect(**DB_CONFIG)
        cursor = conn.cursor()

        sql = """
            INSERT INTO kbo_season_records 
            (game_id, game_date, player_id, player_name, inning, pa_result, sb, cs) 
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """

        values = [
            (row['game_id'], row['game_date'], row['player_id'], row['player_name'], row['inning'], row['pa_result'], row['sb'], row['cs'])
            for row in dataset
        ]

        cursor.executemany(sql, values)
        conn.commit()
        print("✅ 성공적으로 개인 타석 기록 갱신을 완료했습니다.")

    except Exception as e:
        print(f"데이터베이스 접근 중 치명적 에러 발생: {e}")
        if 'conn' in locals() and conn.open: conn.rollback()
    finally:
        if 'conn' in locals() and conn.open: conn.close()

def parse_kbo_result(pa_txt):
    """기초 스탯 판독기"""
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
    print(f"--- {target_year}시즌 리그 일자별 유효 스탯 집계 개시 ---")
    try:
        conn = pymysql.connect(**DB_CONFIG)
        cursor = conn.cursor(pymysql.cursors.DictCursor)
        
        # 1. 비율 스탯이 완벽하게 소거된 순수 누적 스탯 전용 테이블 정의
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS kbo_league_records (
                year INT,
                game_date DATE,
                cum_ab INT, cum_h INT, cum_ob INT, cum_sf INT, cum_tb INT,
                cum_eff_ab INT, cum_eff_tb INT, cum_eff_h INT, cum_eff_ob INT,
                PRIMARY KEY (year, game_date)
            )
        """)
        
        # 2. 해당 시즌 전 구단 타석 데이터 시간순 조회
        sql = """
            SELECT game_date, pa_result, sb, cs 
            FROM kbo_season_records 
            WHERE YEAR(game_date) = %s 
            ORDER BY game_date ASC, game_id ASC, inning ASC
        """
        cursor.execute(sql, (target_year,))
        rows = cursor.fetchall()
        
        if not rows:
            print(f"[{target_year}] 해당 연도 데이터가 존재하지 않습니다.")
            return

        # 3. 전역 누적 변수 초기화
        cum_ab = 0; cum_h = 0; cum_ob = 0; cum_sf = 0; cum_tb = 0
        cum_eff_ab = 0; cum_eff_tb = 0; cum_eff_h = 0; cum_eff_ob = 0
        
        rows_by_date = defaultdict(list)
        for r in rows:
            rows_by_date[r['game_date']].append(r)
            
        # 4. 시간 흐름에 따른 일일 누적 연산 진행
        for d in sorted(rows_by_date.keys()):
            for row in rows_by_date[d]:
                parsed = parse_kbo_result(row['pa_result'])
                if not parsed: continue
                
                sb = int(row['sb'] or 0)
                cs = int(row['cs'] or 0)
                pa_txt = str(row['pa_result']).strip()
                
                eff_ab = parsed['ab']; eff_tb = parsed['tb']; eff_h = parsed['h']
                is_on_base = (parsed['h'] > 0 or parsed['obp'] > 0)
                
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
            
            # 5. 비율 연산 제외 후 순수 누적 데이터 INSERT
            insert_sql = """
                REPLACE INTO kbo_league_records (
                    year, game_date, 
                    cum_ab, cum_h, cum_ob, cum_sf, cum_tb,
                    cum_eff_ab, cum_eff_tb, cum_eff_h, cum_eff_ob
                ) VALUES (
                    %s, %s,
                    %s, %s, %s, %s, %s,
                    %s, %s, %s, %s
                )
            """
            cursor.execute(insert_sql, (
                target_year, d,
                cum_ab, cum_h, cum_ob, cum_sf, cum_tb,
                cum_eff_ab, cum_eff_tb, cum_eff_h, cum_eff_ob
            ))
        
        conn.commit()
        print(f"✅ {target_year}시즌 리그 일자별 유효 스탯 적재를 완벽히 마쳤습니다.")
        
    except Exception as e:
        print(f"리그 데이터 집계 중 치명적 에러 발생: {e}")
        if 'conn' in locals() and conn.open: conn.rollback()
    finally:
        if 'conn' in locals() and conn.open: conn.close()

if __name__ == "__main__":
    # 1. 전일자 경기 조회 및 개인별 타석 기록 크롤링
    yesterday_games, target_date = fetch_yesterdays_kbo_game_ids()
    fetched_results = fetch_baseball_records(yesterday_games)

    final_dataset = []
    for match in fetched_results:
        parsed_records = extract_baseball_data(match["raw_data"], match['game_id'])
        for record in parsed_records:
            record['game_id'] = match['game_id']
            record['game_date'] = target_date
        final_dataset.extend(parsed_records)

    # 2. 크롤링 데이터 DB 반영 (시즌 기록 갱신)
    load_to_mysql(final_dataset)
    
    # 3. 당일 추가된 데이터 기반으로 해당 시즌 리그 기록 통째로 재갱신
    if final_dataset:
        current_year = datetime.strptime(target_date, '%Y-%m-%d').year
        aggregate_league_eff_stats(current_year)
    else:
        print("새롭게 적재된 데이터가 없어 리그 집계는 스킵합니다.")
