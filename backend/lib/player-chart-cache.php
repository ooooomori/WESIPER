<?php
declare(strict_types=1);

/*
 * 선수 페이지 차트 탭(스플릿 차트·캔들 차트) 응답 파일 캐시.
 *
 * - 지난 시즌 기록: 입력이 바뀌지 않으므로 PROFILE_HISTORY_VERSION이 같은 동안 계속 재사용한다.
 * - 올해(또는 통산처럼 올해를 포함하는) 기록: 크롤러 revision이 바뀌면 다시 계산한다.
 *   퓨처스 크롤러와 백필은 revision을 올리지 않으므로 1시간이 지나면 만료시키는 보조 장치를 둔다.
 */
const PROFILE_CHART_CACHE_TTL = 3600;

function profileChartCacheRevision(): string {
    $path = getenv('WESIPER_CANDLE_REVISION_FILE') ?: '/tmp/wesiper-candle-data-revision';
    $revision = is_readable($path) ? trim((string)file_get_contents($path)) : '';
    return $revision !== '' ? $revision : 'initial';
}

function profileChartCachePath(string $name, array $keyParts): ?string {
    $dir = sys_get_temp_dir() . '/wesiper-profile-chart-' . (function_exists('posix_geteuid') ? posix_geteuid() : 'web');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return null;
    $version = defined('PROFILE_HISTORY_VERSION') ? PROFILE_HISTORY_VERSION : '';
    $tiebreakers = defined('PROFILE_TIEBREAKER_GAMES') ? PROFILE_TIEBREAKER_GAMES : [];
    return $dir . '/v1-' . $name . '-' . hash('sha256', json_encode([$keyParts, $version, $tiebreakers], JSON_THROW_ON_ERROR)) . '.json';
}

// 저장된 응답 본문(JSON 문자열)을 돌려준다. 없거나 만료됐으면 null.
function profileChartCacheLoad(string $path, bool $volatile): ?string {
    if (!is_file($path)) return null;
    $cached = json_decode((string)@file_get_contents($path), true);
    if (!is_array($cached) || !is_string($cached['body'] ?? null)) return null;
    if (!$volatile) return $cached['body'];
    $now = time();
    $created = (int)($cached['createdAt'] ?? 0);
    if (($cached['revision'] ?? null) !== profileChartCacheRevision() || $created <= 0 || $now < $created || $now - $created >= PROFILE_CHART_CACHE_TTL) return null;
    return $cached['body'];
}

function profileChartCacheStore(string $path, string $body, string $revision): void {
    $tmp = @tempnam(dirname($path), 'chart-');
    if ($tmp === false) return;
    try {
        if (@file_put_contents($tmp, json_encode(['revision' => $revision, 'createdAt' => time(), 'body' => $body], JSON_THROW_ON_ERROR)) !== false) @rename($tmp, $path);
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
}

// 배열을 계산하는 쪽에서 쓴다: 캐시가 있으면 그대로, 없으면 계산해서 저장한다. JSON 문자열을 돌려준다.
function profileChartCachedJson(string $name, array $keyParts, bool $volatile, callable $compute): string {
    $path = profileChartCachePath($name, $keyParts);
    if ($path !== null && ($body = profileChartCacheLoad($path, $volatile)) !== null) return $body;
    $revision = profileChartCacheRevision();
    $body = json_encode($compute(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // 계산하는 사이 크롤이 끝났다면 새 revision 이름표를 붙이지 않는다.
    if ($path !== null && $revision === profileChartCacheRevision()) profileChartCacheStore($path, $body, $revision);
    return $body;
}

// 응답을 직접 echo하는 엔드포인트용: 캐시가 있으면 바로 내보내고 끝낸다. 없으면 출력을 가로채 성공 응답만 저장한다.
function profileChartCacheServe(string $name, array $keyParts, bool $volatile): void {
    $path = profileChartCachePath($name, $keyParts);
    if ($path === null) return;
    if (($body = profileChartCacheLoad($path, $volatile)) !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo $body;
        exit;
    }
    $revision = profileChartCacheRevision();
    ob_start(static function (string $buffer) use ($path, $revision): string {
        $decoded = json_decode($buffer, true);
        if (http_response_code() === 200 && is_array($decoded) && ($decoded['success'] ?? false) === true && !isset($decoded['error']) && $revision === profileChartCacheRevision()) {
            profileChartCacheStore($path, $buffer, $revision);
        }
        return $buffer;
    });
}
