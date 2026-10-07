# -*- coding: utf-8 -*-
"""Sekelyebb tagolas: a rovid alszakaszokbol tablazat lesz.

A Word-utmutatok minden felületi elemnek kulon cimsort adtak, es ala egy-ket
mondatot. Ez a sugoban attekinthetetlen. Ahol egy fejezetreszen belul harom
vagy tobb ilyen rovid alszakasz jon egymas utan, azokbol egyetlen
table.erp-table lesz: "elem -> leiras".

Hasznalat:  python3 tools/egyszerusit.py <fajl> [--max-hossz N] [--proba]
"""
import sys, re, io

FEJ = re.compile(r'<h([23])[^>]*>(.*?)</h\1>', re.S)

def szakaszok(s):
    """A h2/h3 cimsorok mellett a hozzajuk tartozo torzs."""
    pontok = [(m.start(), m.end(), int(m.group(1)),
               re.sub(r'<[^>]+>', '', m.group(2)).strip()) for m in FEJ.finditer(s)]
    ki = []
    for i, (a, b, szint, cim) in enumerate(pontok):
        vege = pontok[i+1][0] if i+1 < len(pontok) else len(s)
        ki.append({'eleje': a, 'cim_vege': b, 'vege': vege, 'szint': szint,
                   'cim': cim, 'torzs': s[b:vege].strip()})
    return ki

def rovid(torzs, max_hossz):
    """Rovid szakasz torzse, tablazatcellaba valo formaban.

    Elfogadja az egy-ket bekezdest, es a bekezdes + felsorolas parost is -
    a lista a cellaban marad, nem vesz el belole semmi. Kepet vagy tablazatot
    tartalmazo szakaszt nem von ossze: azok onallo cimsort erdemelnek.
    """
    t = torzs.strip()
    if '<table' in t or '<img' in t or '<h' in t:
        return None
    if len(re.sub(r'<[^>]+>', '', t)) > max_hossz:
        return None
    # csak bekezdesekbol es legfeljebb egy felsorolasbol allhat
    maradek = re.sub(r'<p>.*?</p>', '', t, flags=re.S)
    maradek = re.sub(r'<ul>.*?</ul>', '', maradek, flags=re.S, count=1)
    if maradek.strip():
        return None
    return ' '.join(t.split())

def tabla(sorok, fejlec=('Elem', 'Mit jelent')):
    ki = ['<table class="erp-table">', '<thead><tr class="header">',
          '<th><strong>%s</strong></th>' % fejlec[0],
          '<th><strong>%s</strong></th>' % fejlec[1],
          '</tr></thead>', '<tbody>']
    for i, (cim, szoveg) in enumerate(sorok):
        ki += ['<tr class="%s">' % ('odd' if i % 2 == 0 else 'even'),
               '<td><strong>%s</strong></td>' % cim, '<td>%s</td>' % szoveg, '</tr>']
    ki += ['</tbody>', '</table>']
    return '\n'.join(ki)

def alakit(s, max_hossz=400, min_db=3):
    sz = szakaszok(s)
    cserek = []          # (eleje, vege, uj_szoveg)
    i = 0
    while i < len(sz):
        if sz[i]['szint'] != 3:
            i += 1; continue
        futam = []
        j = i
        while j < len(sz) and sz[j]['szint'] == 3:
            r = rovid(sz[j]['torzs'], max_hossz)
            if r is None: break
            futam.append((sz[j]['cim'], r)); j += 1
        if len(futam) >= min_db:
            cserek.append((sz[i]['eleje'], sz[j-1]['vege'], tabla(futam)))
            i = j
        else:
            i += 1
    for a, b, uj in reversed(cserek):
        s = s[:a] + uj + '\n\n' + s[b:]
    return s, len(cserek), sum(1 for _ in cserek)

if __name__ == '__main__':
    fajl = sys.argv[1]
    max_hossz = 400
    if '--max-hossz' in sys.argv:
        max_hossz = int(sys.argv[sys.argv.index('--max-hossz')+1])
    s = io.open(fajl, encoding='utf-8').read()
    elotte = {h: len(re.findall(r'<%s[ >]' % h, s)) for h in ('h2','h3')}
    uj, db, _ = alakit(s, max_hossz)
    utana = {h: len(re.findall(r'<%s[ >]' % h, uj)) for h in ('h2','h3')}
    if '--proba' not in sys.argv:
        io.open(fajl, 'w', encoding='utf-8').write(uj)
    print('  %-40s h3: %d -> %d   (%d tablazat keszult)'
          % (fajl.split('/')[-1], elotte['h3'], utana['h3'], db))
