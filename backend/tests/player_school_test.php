<?php
require dirname(__DIR__) . '/lib/player-school.php';
$cases = [
    ['갈산초-양천중-충암고-LG-샌디에이고-마이애미-디트로이트-미네소타', '갈산초-양천중-충암고'],
    ['고명초-잠신중-덕수정보고-(영남사이버대)-롯데-히어로즈-키움-LG', '고명초-잠신중-덕수정보고-(영남사이버대)'],
    ['일본 이케타고-SSG-두산', '일본 이케타고'],
    ['미국 Kansas(대)-KT', '미국 Kansas(대)'],
    ['대만 Ku-Pao(고)', '대만 Ku-Pao(고)'],
    ['동의대(얼리 드래프트)-상무-LG-SK', '동의대(얼리 드래프트)'],
    ['쿠바 Eide Luis Agusto Tursios Lima', '쿠바 Eide Luis Agusto Tursios Lima'],
    ['(천안유소년리틀)-온양중-라온고', '(천안유소년리틀)-온양중-라온고'],
    ['광남고BC-창원공고야구단-경찰', '광남고BC-창원공고야구단'],
    ['SK-LG-NC-경찰-상무-시카고', null],
    ['태안초-태안중-북일고-현대-히어로즈-상무-KT-롯데', '태안초-태안중-북일고'],
    ['글로벌선진학교-한화', '글로벌선진학교'],
    [null, null],
    ['', null],
];
foreach ($cases as [$input, $expected]) {
    $actual = playerSchoolOnly($input);
    if ($actual !== $expected) throw new RuntimeException(json_encode([$input, $expected, $actual], JSON_UNESCAPED_UNICODE));
}
if (longerPlayerValue('포수', '내야수') !== '내야수'
    || longerPlayerValue('내야수', '투수') !== '내야수'
    || longerPlayerValue('최채흥', '최지명') !== '최채흥'
    || longerPlayerValue(null, '  서울고  ') !== '서울고') throw new RuntimeException('Longer value selection failed.');
echo count($cases) . " school cases and 4 length/tie cases passed\n";
