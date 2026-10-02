"""Official KBO identities that cannot be resolved by a shared former name."""


def kim_taeuk_id(name, team, year):
    # 67768: 1998-04-15, 2017 Hanwha first-round pick, renamed in 2021.
    # 62349: 1979-01-19, MLB/Nexen/KIA pitcher, never this Hanwha player.
    if team == '한화' and name == '김병현' and 2017 <= int(year) <= 2020:
        return 67768
    return None


def official_rename_id(event, old, new):
    if (event['event_date'], event['team'], old, new) == ('2021-02-19', '한화', '김병현', '김태욱'):
        return 67768
    return None


def validate_rename_id(item):
    for event in item['events']:
        expected = official_rename_id(event, event['old_name'], event['new_name'])
        if expected is not None and item['player_id'] != expected:
            raise ValueError('Hanwha Kim Tae-uk rename cannot target MLB Kim Byung-hyun (62349)')
