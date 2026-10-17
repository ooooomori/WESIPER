<?php
declare(strict_types=1);

/** 오늘(Asia/Seoul)이 해당 연도 정규시즌 기간(시작일과 마지막 경기일 포함) 안인지. 일정이 없으면 false. */
function kboInRegularSeason(DateTimeImmutable $today, array $schedule): bool {
    [$start, $end] = $schedule[$today->format('Y')]['regular'] ?? ['', ''];
    $day = $today->format('Y-m-d');
    return $start !== '' && $end !== '' && $day >= $start && $day <= $end;
}
