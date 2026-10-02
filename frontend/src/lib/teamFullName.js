const teamNames = {
    KIA: 'KIA 타이거즈', LG: 'LG 트윈스', SSG: 'SSG 랜더스', 두산: '두산 베어스',
    삼성: '삼성 라이온즈', 롯데: '롯데 자이언츠', KT: 'KT 위즈', 한화: '한화 이글스',
    NC: 'NC 다이노스', 키움: '키움 히어로즈', 울산: '울산 웨일즈',
    SK: 'SK 와이번스', OB: 'OB 베어스', MBC: 'MBC 청룡',
    빙그레: '빙그레 이글스', 해태: '해태 타이거즈', 현대: '현대 유니콘스',
    쌍방울: '쌍방울 레이더스', 삼미: '삼미 슈퍼스타즈', 청보: '청보 핀토스',
    태평양: '태평양 돌핀스', 우리: '우리 히어로즈',
    히어로즈: '서울 히어로즈', 넥센: '넥센 히어로즈',
};

export function teamFullName(team) {
    const name = String(team ?? '').trim();
    return teamNames[name.toUpperCase()] || name;
}
