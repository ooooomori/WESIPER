// 팀 로고·작은 로고 파일 찾기. 아래 폴더에 규칙대로 이름을 붙여 넣으면 코드 수정 없이 쓰인다.
//   logos/<code>-logo.(svg|webp|png)              큰 로고
//   s-logos/<code>-small-logo.(svg|webp|png)      작은 로고(모자·원형 마커·표)
const logoFiles = import.meta.glob('../assets/images/logos/*-logo.{svg,webp,png}', { eager: true, query: '?url', import: 'default' });
const smallLogoFiles = import.meta.glob('../assets/images/s-logos/*-{small,cap}-logo.{svg,webp,png}', { eager: true, query: '?url', import: 'default' });
const pick = (files, base) => ['svg', 'webp', 'png'].map(extension => files[`${base}.${extension}`]).find(Boolean) || null;

export const teamLogoByCode = code => code ? pick(logoFiles, `../assets/images/logos/${code}-logo`) : null;
export const teamSmallLogoByCode = code => code ? pick(smallLogoFiles, `../assets/images/s-logos/${code}-small-logo`) : null;
// 모자 앞면용 로고: s-logos/<code>-cap-logo.* 가 있으면 그것을(흰색 전용 로고 등), 없으면 작은 로고 → 큰 로고 순으로 쓴다.
export const teamCapOnlyLogoByCode = code => code ? pick(smallLogoFiles, `../assets/images/s-logos/${code}-cap-logo`) : null;
export const teamCapLogoByCode = code => teamCapOnlyLogoByCode(code) || teamSmallLogoByCode(code) || teamLogoByCode(code);

// 홈 모자 색: 코드 => [모자 몸통 색, 챙 색(없으면 몸통과 같음), 로고 색('white' = 흰색 자수, 'original' = 로고 원래 색, 'none' = 로고 없음)]
// 현대는 작은 로고 파일에 흰 배경이 있어 모자에 얹을 수 없다.
const teamCaps = {
    kia: ['#e4002b', null, 'white'], kt: ['#1a1a1a', null, 'white'], lg: ['#1a1a1a', '#c30452', 'original'], sam: ['#0a4aa3', null, 'white'],
    ssg: ['#ce0e2d', null, 'white'], doo: ['#131d40', null, 'original'], kiw: ['#6f1a2b', null, 'white'], han: ['#1a1a1a', null, 'original'],
    lot: ['#0a2240', '#d00f31', 'original'], nc: ['#1d3c6e', null, 'white'], ulsan: ['#c90000', null, 'white'],
    sk: ['#1a1a1a', null, 'original'], sk00: ['#0b4a8f', null, 'white'], nex: ['#7a1230', null, 'white'], heroes: ['#7a1230', null, 'white'], hai: ['#1a1a1a', '#d81f2a', 'original'],
    hyd: ['#0b5a33', null, 'none'], sbw: ['#1c1c1c', '#f6c416', 'original'], mbc: ['#1746a2', null, 'original'], ob: ['#1f2a6b', null, 'white'],
    sami: ['#1c2447', '#d8382c', 'original'], chungbo: ['#d4202a', null, 'white'], pacific: ['#e03a2b', '#2b3fb0', 'original'], bing: ['#1a1a1a', null, 'original'],
};
export const teamCapByCode = code => teamCaps[code] ? { crown: teamCaps[code][0], brim: teamCaps[code][1] || teamCaps[code][0], originalLogo: teamCaps[code][2] === 'original', noLogo: teamCaps[code][2] === 'none' } : null;

// 팀 이름(현재 팀·옛 팀)으로 모자 설정 찾기
const currentTeamCodes = { KIA: 'kia', LG: 'lg', SSG: 'ssg', 두산: 'doo', 삼성: 'sam', 롯데: 'lot', 한화: 'han', KT: 'kt', NC: 'nc', 키움: 'kiw', 울산: 'ulsan', '울산 웨일즈': 'ulsan' };
// 시대에 따라 로고·모자가 달랐던 팀: 2000~2005년 SK는 파란 「W」 로고(코드 sk00)를 썼다.
export const eraTeamCode = (name, year) => name === 'SK' && Number(year) >= 2000 && Number(year) <= 2005 ? 'sk00' : null;
export const teamCapByName = (name, year) => { const team = String(name || '').trim(); return teamCapByCode(eraTeamCode(team, year) || currentTeamCodes[team.toUpperCase()] || currentTeamCodes[team] || legacyTeams[team]?.[0]); };

// 지금은 없는 팀·옛 팀명: [파일 코드, 대표 색(모자·강조에 쓴다)]
export const legacyTeams = {
    해태: ['hai', '#c8102e'], OB: ['ob', '#1f2a6b'], MBC: ['mbc', '#1746a2'], 삼미: ['sami', '#2a5a94'],
    청보: ['chungbo', '#1b75bb'], 태평양: ['pacific', '#2233cc'], 현대: ['hyd', '#0b6b3a'], 쌍방울: ['sbw', '#1c1c1c'],
    빙그레: ['bing', '#f37021'], SK: ['sk', '#ea002c'], 넥센: ['nex', '#820024'], 히어로즈: ['heroes', '#7a1230'], 우리: ['heroes', '#7a1230'],
};
export const legacyTeamCode = team => legacyTeams[team]?.[0] || null;
export const legacyTeamColor = team => legacyTeams[team]?.[1] || null;
