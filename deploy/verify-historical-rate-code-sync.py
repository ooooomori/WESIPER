"""Verify public code synchronization without reading private configuration."""
import hashlib,json,subprocess
from pathlib import Path
release=Path('/home/bitnami/wesiper-season-api-release-20261001')
manifest=json.loads((release/'code-manifest.json').read_text(encoding='utf-8-sig'))
checked=[]
for relative,expected in manifest.items():
    if relative.startswith('backend/'):
        paths=[Path(root)/relative.removeprefix('backend/') for root in ('/opt/bitnami/apache/htdocs','/home/bitnami/wesiper-weather-preview')]
    else:
        paths=[Path('/home/bitnami/wesiper')/relative.removeprefix('crawler/')]
    for path in paths:
        assert hashlib.sha256(path.read_bytes()).hexdigest()==expected,str(path)+' differs'
        if path.suffix=='.php':
            subprocess.run(['/opt/bitnami/php/bin/php','-l',str(path)],check=True,stdout=subprocess.DEVNULL)
        else:
            compile(path.read_text(encoding='utf-8'),str(path),'exec')
        checked.append(str(path))
for relative in ('api/playerProfile.php','lib/player-rankings.php'):
    expected=hashlib.sha256((release/relative).read_bytes()).hexdigest()
    for root in ('/opt/bitnami/apache/htdocs','/home/bitnami/wesiper-weather-preview'):
        path=Path(root)/relative
        assert hashlib.sha256(path.read_bytes()).hexdigest()==expected,str(path)+' differs from released code'
        subprocess.run(['/opt/bitnami/php/bin/php','-l',str(path)],check=True,stdout=subprocess.DEVNULL)
        checked.append(str(path))
subprocess.run(['sha256sum','--check',str(release/'backups/candle-before.sha256')],check=True,stdout=subprocess.DEVNULL)
result={'passed':True,'checked_files':checked,'candle_files_unchanged':True,'local_other_changes_preserved':['backend/api/playerProfile.php: league selection for rankings','backend/lib/player-rankings.php: ERA+ ranking extension'], 'note':'Historical API changes are present locally; separate local ranking extensions were not published by this release.'}
(release/'code-sync-verification.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
print(json.dumps({'passed':True,'files':len(checked),'candle_files_unchanged':True}))
