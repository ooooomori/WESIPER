export function recordColumnWidth(label, key, mobile = false) {
    if (key === 'position') return mobile ? 40 : 48;
    return Math.max(key === 'innings' ? (mobile ? 50 : 64) : (mobile ? 38 : 52), [...label].reduce((width, char) => width + (char.charCodeAt(0) > 255 ? (mobile ? 11 : 12) : (mobile ? 6 : 7)), mobile ? 8 : 20));
}
