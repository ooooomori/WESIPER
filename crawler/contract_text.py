"""Customer-facing contract conditions, separate from source evidence."""
import re


def normalize_term(term):
    return re.sub(r'년\s*\+\s*', '+', term).replace(' ', '') if term else term


def money_text(value, currency='KRW'):
    if value is None:
        return ''
    value = int(value)
    high, low = divmod(value, 100_000_000)
    ten_thousands, remainder = divmod(low, 10_000)
    result = (f'{high}억' if high else '')
    if ten_thousands:
        result += (' ' if result else '') + f'{ten_thousands:,}만'
    if remainder:
        result += (' ' if result else '') + f'{remainder:,}'
    return (result or '0') + {'KRW': '원', 'USD': '달러', 'JPY': '엔'}.get(currency, ' '+currency)


def contract_text(term, total, currency='KRW', details=''):
    term=normalize_term(term)
    text = details or ''
    text=re.sub(r'년\s*\+\s*', '+', text)
    if '원문:' in text:
        text = text.split('원문:', 1)[1].split(';', 1)[0]
    elif text.startswith('KBO 연감 계약서'):
        text = text.split('총액:', 1)[1] if '총액:' in text else ''
    # Retain all incentive terms, including wrapped performance thresholds.
    text = re.sub(r',?\s*보상선수.*$', '', text)
    text = re.sub(r'KBO 연감[^.]*\.', '', text)
    text = re.sub(r'[^,.]*해외 복귀,\s*', '', text)
    text = re.sub(r'\d+월\s*\d+일 (?:체결|발표)[^.]*\.', '', text)
    text = re.sub(r'기존 \d{4}~\d{4} 계약 후 신규 계약\.', '', text)
    text = text.replace('구단 발표 ', '').replace('발표 최대 총액', '').replace('단순 7년 확정 지급액이 아님.', '')
    text = text.replace('두산 계약 옵트아웃 후 자유계약.', '')
    if not re.search(r'\d|인센티브|옵션|옵트아웃|군복무|계약 조건 비공개', text):
        text = ''
    text = re.sub(r'\s+', ' ', text).strip(' ,;.')
    # The first line always gives the stored term and announced total.
    headline = ' '.join(filter(None, [term, money_text(total, currency)]))
    if headline and text.startswith(headline+' · '):
        text=text[len(headline)+3:]
    elif headline and text.startswith(headline+' '):
        text=text[len(headline):].strip()
    if not headline:
        return '계약 조건 비공개'
    if not text or text in (headline, money_text(total, currency)):
        return headline
    if text.startswith('('):
        return headline + ' ' + text
    if term and text.startswith(term+', '):
        text = text[len(term)+2:]
    # A bare amount is already in the headline; retain its incentive suffix.
    amount_prefix = re.match(r'^(?:각각\s*)?\d[\d,.\s]*(?:억[\d,.\s]*)?(?:천[\d,.\s]*)?(?:백[\d,.\s]*)?(?:만)?(?:원|달러|엔)', text)
    if amount_prefix and not text.startswith('각각'):
        suffix = text[amount_prefix.end():].strip()
        if suffix.startswith('(') and '연봉' not in suffix:
            return headline + ' ' + suffix
        if not suffix:
            return headline
    return headline + ' · ' + text
