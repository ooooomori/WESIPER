"""Unmodified fourth code-cell parser from the supplied Colab notebook."""
import re

def extract_baseball_data(api_response, game_id):
    record_data = api_response.get('result', {}).get('recordData', {})
    sb_lookup = {}
    cs_lookup = {}
    etc_records = record_data.get('etcRecords', [])

    for record in etc_records:
        how = record.get('how', '')
        is_sb = ('도루' in how and '도루자' not in how)
        is_cs = ('도루자' in how or '견제도루자' in how)

        if not (is_sb or is_cs):
            continue

        result_text = record.get('result', '')
        matches = re.findall(r'([가-힣a-zA-Z]+)(\d*)\s*\(([\d\s,]+)회\)', result_text)

        for name, count_str, inn_str in matches:
            innings = [int(x) for x in inn_str.replace(',', ' ').split() if x.isdigit()]
            explicit_count = int(count_str) if count_str.isdigit() else 1

            # [수정] 횟수는 3개 이상인데 이닝 리스트 개수랑 횟수가 일치해서 굳이 수동 안 봐도 되는 건 거르고,
            # 정말로 이닝 분배가 꼬이는 경우(횟수 != 이닝 개수 등)에만 경기 코드(game_id)와 함께 출력
            if explicit_count >= 3 and explicit_count != len(innings):
                print(f"[수동 처리 필요] 경기코드: {game_id} | 선수: {name} | 횟수: {explicit_count} | 이닝: {innings} | 원문: {result_text}")

            target_inn = innings[0] if innings else 1

            if is_sb:
                sb_lookup[(name, target_inn)] = sb_lookup.get((name, target_inn), 0) + explicit_count
            else:
                cs_lookup[(name, target_inn)] = cs_lookup.get((name, target_inn), 0) + explicit_count

    extracted_pas = []
    batters_box = record_data.get('battersBoxscore', {})

    for team_type in ['away', 'home']:
        for batter in batters_box.get(team_type, []):
            name = batter.get('name')
            pcode = batter.get('playerCode')

            for i in range(1, 26):
                inn_key = f'inn{i}'
                inn_result = batter.get(inn_key, "")
                current_sb = sb_lookup.get((name, i), 0)
                current_cs = cs_lookup.get((name, i), 0)

                if not inn_result and current_sb == 0 and current_cs == 0:
                    continue

                if not inn_result:
                    extracted_pas.append({
                        'player_id': pcode,
                        'player_name': name,
                        'inning': i,
                        'pa_result': "",
                        'sb': current_sb,
                        'cs': current_cs
                    })
                else:
                    pa_list = inn_result.split('/')
                    for pa_idx, pa_text in enumerate(pa_list):
                        apply_sb = current_sb if pa_idx == 0 else 0
                        apply_cs = current_cs if pa_idx == 0 else 0

                        extracted_pas.append({
                            'player_id': pcode,
                            'player_name': name,
                            'inning': i,
                            'pa_result': pa_text.strip(),
                            'sb': apply_sb,
                            'cs': apply_cs
                        })

    return extracted_pas
