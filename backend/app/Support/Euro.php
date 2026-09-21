<?php

namespace App\Support;

/**
 * Euro nei form, centesimi nel database.
 *
 * Chi compila scrive "39,90"; il campo `numeric` di Filament lo rifiuterebbe
 * per la virgola, e un float nel database sbaglierebbe i conti. La
 * conversione sta qui, in un posto solo.
 */
final class Euro
{
    public static function inCentesimi(string|int|float|null $valore): ?int
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        $testo = str_replace([' ', '€'], '', (string) $valore);

        // Con la virgola, il punto e' il separatore delle migliaia.
        if (str_contains($testo, ',')) {
            $testo = str_replace(['.', ','], ['', '.'], $testo);
        }

        if (! is_numeric($testo)) {
            return null;
        }

        return (int) round(((float) $testo) * 100);
    }

    public static function daCentesimi(?int $centesimi): ?string
    {
        return $centesimi === null ? null : number_format($centesimi / 100, 2, ',', '');
    }
}
