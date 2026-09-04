#!/usr/bin/env python3
"""Verifica che le regole CSS si applichino davvero al markup pubblicato.

Perche' esiste: il 2026-09-04 slimcms.it e' andato online mezzo senza stile.
Astro scopa gli stili per componente aggiungendo [data-astro-cid-XXX] ai
selettori e lo stesso attributo agli elementi di QUEL componente. Spostando il
markup dei blocchi in un componente diverso da quello che dichiarava lo
<style>, i selettori continuavano a esistere ma non corrispondevano piu' a
nulla. L'HTML restava valido, il peso plausibile, il contenuto corretto:
nessuna verifica basata su quelli se ne accorgeva.

Due controlli:
  1. ogni classe usata nel markup ha almeno una regola che la puo' colpire
  2. nessuna regola scoped punta a una classe il cui elemento NON porta
     quell'attributo di scope (il caso che ha rotto il sito)

Uso: verifica-css.py <dist_dir>
"""
from __future__ import annotations
import re, sys, pathlib
from collections import defaultdict

TAG = re.compile(r'<[a-zA-Z][^>]*>')
CLASSI_TAG = re.compile(r'class="([^"]*)"')
# .nome-classe eventualmente seguito da [data-astro-cid-xxxx]
SELETTORE = re.compile(r'\.([a-zA-Z][\w-]*)(\[data-astro-cid-[\w-]+\])?')


def analizza_html(html: str):
    """classe -> insieme degli attributi di scope trovati sugli elementi che la usano."""
    scope_per_classe: dict[str, set[str]] = defaultdict(set)

    for tag in TAG.finditer(html):
        testo = tag.group(0)
        m = CLASSI_TAG.search(testo)
        if not m:
            continue
        cids = set(re.findall(r'(data-astro-cid-[\w-]+)', testo))
        for c in m.group(1).split():
            scope_per_classe[c] |= cids

    return scope_per_classe


def analizza_css(css: str):
    """classe -> insieme dei requisiti di scope ('' = nessuno, quindi globale)."""
    requisiti: dict[str, set[str]] = defaultdict(set)

    for m in SELETTORE.finditer(css):
        classe, scope = m.group(1), m.group(2)
        requisiti[classe].add(scope[1:-1] if scope else '')

    return requisiti


def main(dist: str) -> int:
    d = pathlib.Path(dist)
    html_files = list(d.rglob('*.html'))

    if not html_files:
        print('ERRORE: nessun file HTML in', dist, file=sys.stderr)
        return 1

    css = '\n'.join(p.read_text(encoding='utf-8') for p in d.rglob('*.css'))
    if not css.strip():
        print('ERRORE: nessun CSS trovato in', dist, file=sys.stderr)
        return 1

    requisiti = analizza_css(css)
    problemi = 0

    for html_file in html_files:
        scope_per_classe = analizza_html(html_file.read_text(encoding='utf-8'))
        nome = html_file.relative_to(d)

        senza_regola: list[str] = []
        scope_sbagliato: list[str] = []

        for classe, cids_elemento in scope_per_classe.items():
            req = requisiti.get(classe)

            if not req:
                senza_regola.append(classe)
                continue

            # '' fra i requisiti = esiste una regola globale: si applica sempre.
            if '' in req:
                continue

            # Tutte le regole sono scoped: almeno uno degli scope richiesti
            # deve comparire sull'elemento che usa la classe.
            if not (req & cids_elemento):
                scope_sbagliato.append(
                    f'{classe} (CSS chiede {sorted(req)}, elemento ha '
                    f'{sorted(cids_elemento) or "nessuno scope"})'
                )

        if senza_regola or scope_sbagliato:
            problemi += 1
            if senza_regola:
                print(f'  {nome}: SENZA REGOLA CSS: {", ".join(sorted(senza_regola))}',
                      file=sys.stderr)
            for s in sorted(scope_sbagliato):
                print(f'  {nome}: REGOLA CHE NON SI APPLICA -> {s}', file=sys.stderr)
        else:
            print(f'  {nome}: {len(scope_per_classe)} classi, tutte effettivamente stilate')

    return 1 if problemi else 0


if __name__ == '__main__':
    if len(sys.argv) != 2:
        print('usage: verifica-css.py <dist_dir>', file=sys.stderr)
        sys.exit(2)
    sys.exit(main(sys.argv[1]))
