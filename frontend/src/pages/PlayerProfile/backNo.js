// 등번호 0도 표시한다. null, undefined, 빈 문자열만 숨긴다.
export const hasBackNo = value => value != null && String(value).trim() !== '';
