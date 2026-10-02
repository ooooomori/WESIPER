export function recordColumnWidth(label, key, mobile = false) {
    if (key === 'position') return mobile ? 40 : 48;
    // PC는 열 기본 너비와 여백을 조금 줄여 표를 촘촘하게(모바일은 그대로)
    return Math.max(key === 'innings' ? (mobile ? 50 : 56) : (mobile ? 38 : 46), [...label].reduce((width, char) => width + (char.charCodeAt(0) > 255 ? (mobile ? 11 : 12) : (mobile ? 6 : 7)), mobile ? 8 : 14));
}
