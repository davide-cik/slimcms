<?php

namespace App\Support;

/**
 * Mette uno user-agent in una delle quattro categorie che interessano.
 *
 * Le firme stanno in `config/slimcms.php` e non qui: "altri bot e spam" e'
 * una lista che cresce ogni mese, e farla crescere non deve voler dire
 * toccare una classe.
 *
 * L'ordine dei controlli conta. Quasi tutti i bot si dichiarano `Mozilla/5.0`
 * per non essere bloccati: cercare prima il browser e poi il bot li
 * classificherebbe tutti come persone. Si guarda quindi prima cio' che e'
 * riconoscibile — bot AI, motori, strumenti — e solo alla fine si accetta
 * che sia un browser.
 */
class ClassificatoreAgente
{
    public const UMANO = 'umano';
    public const MOTORE = 'motore';
    public const AI = 'ai';
    public const BOT = 'bot';

    public const CATEGORIE = [
        self::UMANO => 'Persone',
        self::MOTORE => 'Motori di ricerca',
        self::AI => 'Bot AI',
        self::BOT => 'Altri bot e scanner',
    ];

    public static function categoria(?string $agente): string
    {
        $ua = mb_strtolower(trim((string) $agente));

        // Un agente vuoto non e' una persona: ogni browser ne manda uno.
        if ($ua === '') {
            return self::BOT;
        }

        foreach ([self::AI, self::MOTORE, self::BOT] as $categoria) {
            foreach (config("slimcms.agenti.{$categoria}", []) as $firma) {
                if (str_contains($ua, mb_strtolower($firma))) {
                    return $categoria;
                }
            }
        }

        // Solo ora si guarda se somiglia a un browser. Le tre firme sono
        // quelle dei motori di rendering: qualunque browser vero ne ha una.
        foreach (['mozilla/', 'applewebkit/', 'gecko/'] as $firma) {
            if (str_contains($ua, $firma)) {
                return self::UMANO;
            }
        }

        return self::BOT;
    }

    /**
     * Il nome leggibile dell'agente, per la tabella del pannello.
     *
     * Uno user-agent intero e' illeggibile e ce ne sono centinaia di
     * varianti: "Chrome 131" e "Chrome 152" sono la stessa cosa quando la
     * domanda e' "chi mi visita". Il numero di versione si butta.
     */
    public static function nome(?string $agente): string
    {
        $ua = (string) $agente;

        foreach (config('slimcms.agenti.nomi', []) as $firma => $nome) {
            if (stripos($ua, $firma) !== false) {
                return $nome;
            }
        }

        return $ua === '' ? 'sconosciuto' : mb_substr(preg_replace('/[\d.]+/', '', explode(' ', $ua)[0]) ?: $ua, 0, 40);
    }
}
