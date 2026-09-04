#!/usr/bin/env python3
"""Rotation backup SlimCMS.

Strategia 30 daily + 4 weekly + 2 monthly:
  - age 0-29 giorni   → DAILY     : conservo tutti (1 file/giorno)
  - age 30-57 giorni  → WEEKLY    : conservo 4 file (uno per finestra da 7 giorni)
  - age 58-117 giorni → MONTHLY   : conservo 2 file (uno per finestra da 30 giorni)
  - age >=118 giorni  → DELETE

Per ogni "bucket" tengo il file più VECCHIO che cade dentro (così la copertura
temporale è massima e si avvicina al confine "alto" della finestra).

Naming atteso dei file: slimcms_<db>_YYYY-MM-DD.sql.gz
Altri file sono ignorati.
"""
from __future__ import annotations
import sys, os, re, datetime as dt, glob, pathlib

# SlimCMS ha piu' database (dev, prod): il nome del db fa parte del pattern
# e la rotazione va applicata separatamente per ciascuno, altrimenti i file
# di un database conterebbero come copertura temporale dell'altro.
PATTERN = re.compile(r'^slimcms_(?P<db>[a-z0-9_]+)_(?P<data>\d{4}-\d{2}-\d{2})\.sql\.gz$')


def raggruppa(backup_dir: str) -> dict[str, list[tuple[int, pathlib.Path]]]:
    """File raggruppati per database, con l'eta' in giorni."""
    oggi = dt.date.today()
    gruppi: dict[str, list[tuple[int, pathlib.Path]]] = {}

    for path in sorted(pathlib.Path(backup_dir).glob('slimcms_*.sql.gz')):
        m = PATTERN.match(path.name)
        if not m:
            print(f'  skip (nome non riconosciuto): {path.name}')
            continue
        try:
            d = dt.date.fromisoformat(m.group('data'))
        except ValueError:
            continue
        gruppi.setdefault(m.group('db'), []).append(((oggi - d).days, path))

    return gruppi


def da_conservare(entries: list[tuple[int, pathlib.Path]]) -> tuple[set[pathlib.Path], int, int]:
    """Applica 30 daily + 4 weekly + 2 monthly a UN solo database."""
    keep: set[pathlib.Path] = set()

    for age, path in entries:
        if age <= 29:
            keep.add(path)

    # WEEKLY: 4 finestre da 7 giorni in [30..57]
    weekly: dict[int, tuple[int, pathlib.Path]] = {}
    for age, path in entries:
        if 30 <= age <= 57:
            b = (age - 30) // 7
            best = weekly.get(b)
            # tengo il PIU' VECCHIO della finestra: massimizza la copertura
            if best is None or age > best[0]:
                weekly[b] = (age, path)
    for _, (_, pth) in weekly.items():
        keep.add(pth)

    # MONTHLY: 2 finestre da 30 giorni in [58..117]
    monthly: dict[int, tuple[int, pathlib.Path]] = {}
    for age, path in entries:
        if 58 <= age <= 117:
            b = (age - 58) // 30
            best = monthly.get(b)
            if best is None or age > best[0]:
                monthly[b] = (age, path)
    for _, (_, pth) in monthly.items():
        keep.add(pth)

    return keep, len(weekly), len(monthly)


def main(backup_dir: str) -> int:
    gruppi = raggruppa(backup_dir)

    if not gruppi:
        print('  nessun backup da ruotare')
        return 0

    # La rotazione va applicata SEPARATAMENTE per ogni database: altrimenti i
    # file di uno conterebbero come copertura temporale dell'altro, e un
    # database con backup piu' frequenti farebbe cancellare quelli dell'altro.
    for db in sorted(gruppi):
        entries = sorted(gruppi[db], key=lambda x: x[0])
        keep, n_weekly, n_monthly = da_conservare(entries)

        deleted = []
        for age, path in entries:
            if path not in keep:
                try:
                    path.unlink()
                    deleted.append(path.name)
                except OSError as e:
                    print(f'  ERR delete {path}: {e}', file=sys.stderr)

        n_daily = sum(1 for a, pth in entries if a <= 29 and pth in keep)
        print(f'  [{db}] {len(entries)} file -> tenuti {len(keep)} '
              f'({n_daily} daily, {n_weekly} weekly, {n_monthly} monthly), '
              f'cancellati {len(deleted)}')
        for d in deleted:
            print(f'    - {d}')

    return 0


if __name__ == '__main__':
    if len(sys.argv) != 2:
        print('usage: backup-rotate.py <backup_dir>', file=sys.stderr)
        sys.exit(2)
    sys.exit(main(sys.argv[1]))
