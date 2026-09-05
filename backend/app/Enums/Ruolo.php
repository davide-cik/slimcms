<?php

namespace App\Enums;

/**
 * Ruolo di un redattore su un singolo sito (specifiche, sezione 5).
 *
 * Sta sul pivot `site_user`, non sull'utente: la stessa persona puo' essere
 * amministratore di un sito e autore di un altro. E' un enum e non una
 * stringa libera perche' i quattro valori sono un insieme chiuso e la scala
 * dei poteri e' una proprieta' del ruolo, non di chi lo interroga.
 *
 * L'ordine e' significativo: ogni ruolo puo' fare tutto quello che puo' fare
 * quello sotto di lui. Una matrice per singola azione sarebbe piu' espressiva
 * ma anche il posto dove le eccezioni si accumulano finche' nessuno sa piu'
 * cosa vede un autore. Quattro gradini si spiegano in una riga a un cliente.
 */
enum Ruolo: string
{
    case Viewer = 'viewer';
    case Author = 'author';
    case Editor = 'editor';
    case Admin = 'admin';

    /** Posizione nella scala. Non finisce nel database: sul pivot resta la stringa. */
    public function livello(): int
    {
        return match ($this) {
            self::Viewer => 0,
            self::Author => 1,
            self::Editor => 2,
            self::Admin => 3,
        };
    }

    public function almeno(self $minimo): bool
    {
        return $this->livello() >= $minimo->livello();
    }

    /**
     * Pubblicare e' la linea che separa autore e redattore.
     *
     * E' l'unico potere che esce dal pannello: una pagina pubblicata accoda
     * una build e finisce online. Tutto il resto resta reversibile dentro
     * l'amministrazione.
     */
    public function puoPubblicare(): bool
    {
        return $this->almeno(self::Editor);
    }

    /**
     * Etichetta mostrata nel pannello.
     *
     * Sta qui e non nel form perche' e' una promessa: quello che l'etichetta
     * dice, le policy devono farlo rispettare. Tenerle vicine rende visibile
     * quando una delle due cambia senza l'altra.
     */
    public function etichetta(): string
    {
        return match ($this) {
            self::Admin => 'Amministratore — gestisce anche gli altri redattori',
            self::Editor => 'Redattore — crea, pubblica ed elimina contenuti',
            self::Author => 'Autore — scrive e modifica, non pubblica e non elimina',
            self::Viewer => 'In sola lettura — vede i contenuti, non li tocca',
        };
    }

    /** Il valore del pivot, che puo' essere nullo o vecchio, diventa un ruolo o niente. */
    public static function da(?string $valore): ?self
    {
        return $valore === null ? null : self::tryFrom($valore);
    }

    /** @return array<string, string> per le tendine di Filament */
    public static function opzioni(): array
    {
        $opzioni = [];

        // Dal piu' potente al meno potente: e' l'ordine in cui un
        // amministratore pensa quando assegna un ruolo.
        foreach (array_reverse(self::cases()) as $ruolo) {
            $opzioni[$ruolo->value] = $ruolo->etichetta();
        }

        return $opzioni;
    }
}
