<?php
declare(strict_types=1);

/** Keep the public bingo team's existing codes while storing historical names. */
function playerCareerTeamCode(?string $team): ?string
{
    static $codes = [
        '삼성'=>'sam','롯데'=>'lot','MBC'=>'lg','LG'=>'lg','OB'=>'doo','두산'=>'doo',
        '해태'=>'kia','KIA'=>'kia','빙그레'=>'han','한화'=>'han','SK'=>'ssg','SSG'=>'ssg',
        '삼미'=>'hyd','청보'=>'hyd','태평양'=>'hyd','현대'=>'hyd','쌍방울'=>'sbw',
        '우리'=>'kiw','히어로즈'=>'kiw','서울'=>'kiw','넥센'=>'kiw','키움'=>'kiw',
        'NC'=>'nc','KT'=>'kt',
    ];
    return $codes[$team] ?? null;
}

/** Query once per search; career data is overlaid on the cached baseball stats. */
function playerCareerProfiles(mysqli $db, array $ids): array
{
    if (!$ids) return [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $profiles = array_fill_keys($ids, ['wbc'=>false,'gg'=>[],'as'=>[]]);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT player_id,category,type,team FROM kbo_player_career
        WHERE player_id IN ($placeholders) AND
        ((category='national' AND type='WBC') OR
         (category='award' AND type IN ('골든글러브','올스타')))");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
        $id = (int)$row['player_id'];
        if ($row['category'] === 'national') { $profiles[$id]['wbc'] = true; continue; }
        $code = playerCareerTeamCode($row['team']);
        if ($code !== null) $profiles[$id][$row['type'] === '골든글러브' ? 'gg' : 'as'][$code] = true;
    }
    $stmt->close();
    return $profiles;
}

function applyPlayerCareerProfile(array $player, array $career): array
{
    foreach (['gg','as'] as $kind) {
        foreach ($career[$kind] as $code => $_) {
            if (isset($player['Season'][$code]) && is_array($player['Season'][$code]))
                $player['Season'][$code][$kind] = true;
        }
    }
    $player['Profile']['is_WBC'] = $career['wbc'];
    return $player;
}
