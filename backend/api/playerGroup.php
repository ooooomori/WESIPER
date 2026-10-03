<?php
// 선수 묶음 목록: 같은 학교·리틀야구단(type=school), 같은 해 입단(type=draft), 같은 생일(type=birthday)
header('Content-Type: application/json; charset=utf-8');
// 거의 바뀌지 않는 정보라 한 시간 재사용한다. 오류 응답은 저장하지 않는다.
header('Cache-Control: public, max-age=3600');
header_register_callback(static function(): void { if (http_response_code() >= 400) header('Cache-Control: no-store'); });
$type = $_GET['type'] ?? '';
$value = is_string($_GET['value'] ?? null) ? trim($_GET['value']) : '';
$valid = match ($type) {
    // 학교(초·중·고·대)와 리틀야구단만 받는다.
    'school' => (bool)preg_match('/^[\p{L}\p{N} .]{2,30}(초|중|고|대|리틀)$/u', $value),
    'draft' => (bool)preg_match('/^(19[89]\d|20\d\d)$/D', $value),
    'birthday' => (bool)preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D', $value),
    default => false,
};
if (!$valid) {
    http_response_code(400);
    echo json_encode(['error' => '잘못된 조회 조건입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    require_once __DIR__ . '/kbocandle/common.php';
    require_once __DIR__ . '/../lib/player-groups.php';
    $result = match ($type) {
        'school' => alumniPlayers($pdo, $value),
        'draft' => draftClassPlayers($pdo, (int)$value),
        'birthday' => birthdayPlayers($pdo, $value),
    };
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Player group lookup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => '선수 목록을 불러오지 못했습니다.'], JSON_UNESCAPED_UNICODE);
}
