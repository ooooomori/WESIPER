<?php
declare(strict_types=1);

function profileSchedule() {
        return [
            '2026' => ['preseason' => ['2026-03-12', '2026-03-24'], 'regular' => ['2026-03-28', '2026-12-31'], 'postseason' => ['', '']],
            '2025' => ['preseason' => ['2025-03-08', '2025-03-18'], 'regular' => ['2025-03-22', '2025-10-04'], 'postseason' => ['2025-10-06', '2025-10-31']],
            '2024' => ['preseason' => ['2024-03-09', '2024-03-19'], 'regular' => ['2024-03-23', '2024-10-01'], 'postseason' => ['2024-10-02', '2024-10-28']],
            '2023' => ['preseason' => ['2023-03-13', '2023-03-28'], 'regular' => ['2023-04-01', '2023-10-17'], 'postseason' => ['2023-10-19', '2023-11-13']],
            '2022' => ['preseason' => ['2022-03-12', '2022-03-29'], 'regular' => ['2022-04-02', '2022-10-11'], 'postseason' => ['2022-10-13', '2022-11-08']],
            '2021' => ['preseason' => ['2021-03-21', '2021-03-30'], 'regular' => ['2021-04-03', '2021-10-31'], 'postseason' => ['2021-11-01', '2021-11-18']],
            '2020' => ['preseason' => ['', ''], 'regular' => ['2020-05-05', '2020-10-31'], 'postseason' => ['2020-11-02', '2020-11-24']],
            '2019' => ['preseason' => ['2019-03-12', '2019-03-20'], 'regular' => ['2019-03-23', '2019-10-01'], 'postseason' => ['2019-10-03', '2019-10-26']],
            '2018' => ['preseason' => ['2018-03-13', '2018-03-21'], 'regular' => ['2018-03-24', '2018-10-14'], 'postseason' => ['2018-10-16', '2018-11-12']],
            '2017' => ['preseason' => ['2017-03-14', '2017-03-26'], 'regular' => ['2017-03-31', '2017-10-03'], 'postseason' => ['2017-10-05', '2017-10-30']],
            '2016' => ['preseason' => ['2016-03-08', '2016-03-27'], 'regular' => ['2016-04-01', '2016-10-09'], 'postseason' => ['2016-10-10', '2016-11-02']],
            '2015' => ['preseason' => ['2015-03-07', '2015-03-22'], 'regular' => ['2015-03-28', '2015-10-06'], 'postseason' => ['2015-10-07', '2015-10-31']],
            '2014' => ['preseason' => ['2014-03-08', '2014-03-23'], 'regular' => ['2014-03-29', '2014-10-17'], 'postseason' => ['2014-10-19', '2014-11-11']],
            '2013' => ['preseason' => ['2013-03-09', '2013-03-24'], 'regular' => ['2013-03-30', '2013-10-05'], 'postseason' => ['2013-10-08', '2013-11-01']],
            '2012' => ['preseason' => ['', ''], 'regular' => ['2012-04-07', '2012-10-06'], 'postseason' => ['2012-10-08', '2012-11-01']],
            '2011' => ['preseason' => ['2011-03-12', '2011-03-27'], 'regular' => ['2011-04-02', '2011-10-06'], 'postseason' => ['2011-10-08', '2011-10-31']],
            '2010' => ['preseason' => ['', ''], 'regular' => ['2010-03-27', '2010-09-26'], 'postseason' => ['2010-09-29', '2010-10-19']],
            '2009' => ['preseason' => ['', ''], 'regular' => ['2009-04-04', '2009-09-26'], 'postseason' => ['2009-09-29', '2009-10-24']],
            '2008' => ['preseason' => ['', ''], 'regular' => ['2008-03-29', '2008-10-05'], 'postseason' => ['2008-10-08', '2008-10-31']],
            // Verified regular-season boundaries from the imported KBO schedules.
            // Exhibition/postseason data has not been imported for these years.
            '2007' => ['preseason' => ['', ''], 'regular' => ['2007-04-06', '2007-10-19'], 'postseason' => ['', '']],
            '2006' => ['preseason' => ['', ''], 'regular' => ['2006-04-08', '2006-10-05'], 'postseason' => ['', '']],
            '2005' => ['preseason' => ['', ''], 'regular' => ['2005-04-02', '2005-09-28'], 'postseason' => ['', '']],
            '2004' => ['preseason' => ['', ''], 'regular' => ['2004-04-04', '2004-10-05'], 'postseason' => ['', '']],
            '2003' => ['preseason' => ['', ''], 'regular' => ['2003-04-05', '2003-10-02'], 'postseason' => ['', '']],
            '2002' => ['preseason' => ['', ''], 'regular' => ['2002-04-05', '2002-10-20'], 'postseason' => ['', '']],
            '2001' => ['preseason' => ['', ''], 'regular' => ['2001-04-05', '2001-10-04'], 'postseason' => ['', '']]
        ];
    }

/**
 * 연도별 정규시즌 후반기 시작일(스플릿 차트의 전반기·후반기).
 * 기록실(recordRoomSeasons)과 같은 기준: 7월~8월 15일 사이 1군 경기가 4일 이상 비는 가장 긴 구간을 올스타 휴식기로 본다. 없으면 그 해는 뺀다.
 */
function profileHalfStarts(PDO $db, array $schedule, array $years): array {
    $bounds=[];$params=[];
    foreach($years as $year){
        [$start,$end]=$schedule[$year]['regular']??['',''];
        if(!$start||!$end)continue;
        $bounds[(int)$year]=[$start,$end];array_push($params,"$year-07-01","$year-08-15");
    }
    if(!$bounds)return [];
    $q=$db->prepare('SELECT DISTINCT game_date d FROM kbo_schedule WHERE league_level=1 AND ('.implode(' OR ',array_fill(0,count($bounds),'game_date BETWEEN ? AND ?')).') ORDER BY game_date');
    $q->execute($params);
    $dates=[];foreach($q->fetchAll(PDO::FETCH_COLUMN) as $date)$dates[(int)substr((string)$date,0,4)][]=(string)$date;
    $halves=[];
    foreach($bounds as $year=>[$start,$end]){
        $gap=3;$previous=null;
        foreach($dates[$year]??[] as $date){
            if($date<$start||$date>$end)continue;
            if($previous!==null){$days=(int)(new DateTimeImmutable($previous))->diff(new DateTimeImmutable($date))->days;if($days>$gap){$gap=$days;$halves[$year]=$date;}}
            $previous=$date;
        }
    }
    return $halves;
}
