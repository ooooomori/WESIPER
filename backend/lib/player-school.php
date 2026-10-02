<?php
declare(strict_types=1);

function longerPlayerValue(?string $existing, ?string $prefixed): ?string {
    $existing = trim($existing ?? '');
    $prefixed = trim($prefixed ?? '');
    $value = mb_strlen($prefixed, 'UTF-8') > mb_strlen($existing, 'UTF-8') ? $prefixed : $existing;
    return $value === '' ? null : $value;
}

function playerSchoolOnly(?string $career): ?string {
    $career = trim($career ?? '');
    if ($career === '') return null;
    // Preserve hyphens inside foreign school names, e.g. Ku-Pao(고).
    $protected = preg_replace('/([A-Za-z])-([A-Za-z])/', '$1' . "\x01" . '$2', $career);
    $tokens = preg_split('/\s*[-–—;,\r\n]+\s*/u', $protected, -1, PREG_SPLIT_NO_EMPTY);
    $schools = [];
    foreach ($tokens as $token) {
        $token = trim(str_replace("\x01", '-', $token));
        $label = preg_match('/^\((.*)\)$/us', $token, $wrapped) ? trim($wrapped[1]) : $token;
        // Hyundai and these professional cities resemble Korean school suffixes.
        if (preg_match('/^(?:미국\s*)?(?:샌디에이고|시카고|현대|해태|태평양|청보|쌍방울|삼미|넥센|히어로즈|키움|상무|경찰|LG|SK|SSG|KT|NC|KIA|두산|삼성|롯데|한화)$/ui', $label)) continue;
        $school = preg_match('/(?:학교|초(?:등학교)?|중(?:학교)?|고(?:등학교|교)?|대(?:학교|학)?)(?:야구단|BC|BSC)?(?:\([^)]*\))?$/ui', $label)
            || preg_match('/\([초중고대]\)$/u', $token)
            || preg_match('/(?:리틀|유소년)|(?:BC|BSC|클럽)$/ui', $label)
            || preg_match('/\b(?:school|university|college|Eide)\b/ui', $label);
        if ($school) $schools[] = $token;
    }
    return $schools ? implode('-', array_values(array_unique($schools))) : null;
}
