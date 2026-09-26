"""Allow the selected external webfont origin without replacing other CSP rules.

Usage: python3 deploy/allow-nanum-webfont.py /path/to/.htaccess
Back up the target file before applying this deployment step.
"""
import re
import sys
from pathlib import Path

path = Path(sys.argv[1])
content = path.read_text(encoding='utf-8')
origin = 'https://hangeul.pstatic.net'
if 'Content-Security-Policy' not in content:
    raise SystemExit('No CSP found; target unchanged')
matches = list(re.finditer(r'font-src\s+([^;]+);', content))
if len(matches) != 1:
    raise SystemExit('Expected exactly one font-src directive; target unchanged')
match = matches[0]
if origin not in match.group(1).split():
    content = content[:match.end(1)] + ' ' + origin + content[match.end(1):]
    path.write_text(content, encoding='utf-8')
print('External NanumSquareNeo origin allowed; other directives preserved')
