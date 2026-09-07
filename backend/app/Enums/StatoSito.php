<?php

namespace App\Enums;

/**
 * Lo stato del ciclo di vita di un sito.
 *
 * Sta nel control plane insieme al dominio: e' una decisione della
 * piattaforma, non del cliente. Chi abita il sito puo' cambiare tutto quello
 * che il sito mostra, non se il sito e' online.
 *
 * `sospeso` e `parcheggiato` fanno la stessa cosa tecnica — una pagina di
 * cortesia al posto del sito — e sono due valori distinti perche' dicono due
 * cose diverse a chi guarda il pannello, e mostrano due testi diversi a chi
 * arriva. Un sito parcheggiato non e' un sito con un problema.
 */
enum StatoSito: string
{
    case Attivo = 'attivo';
    case Parcheggiato = 'parcheggiato';
    case Sospeso = 'sospeso';

    public function etichetta(): string
    {
        return match ($this) {
            self::Attivo => 'Attivo',
            self::Parcheggiato => 'Parcheggiato',
            self::Sospeso => 'Sospeso',
        };
    }

    public function descrizione(): string
    {
        return match ($this) {
            self::Attivo => 'Il sito e\' online e viene ripubblicato a ogni modifica.',
            self::Parcheggiato => 'Il dominio risponde con una pagina di attesa: il sito non e\' ancora uscito.',
            self::Sospeso => 'Il sito non e\' raggiungibile. I contenuti restano nel pannello.',
        };
    }

    public function colore(): string
    {
        return match ($this) {
            self::Attivo => 'success',
            self::Parcheggiato => 'info',
            self::Sospeso => 'danger',
        };
    }

    /** Al posto del sito il visitatore vede una pagina di cortesia. */
    public function mostraCortesia(): bool
    {
        return $this !== self::Attivo;
    }

    /**
     * Il titolo predefinito della pagina di cortesia.
     *
     * Non dice mai perche'. A chi arriva non interessa se il cliente non ha
     * pagato, e dirlo sarebbe un fatto privato del cliente scritto su una
     * pagina pubblica.
     */
    public function titoloCortesia(): string
    {
        return match ($this) {
            self::Parcheggiato => 'Arriviamo',
            default => 'Torniamo presto',
        };
    }

    public function testoCortesia(): string
    {
        return match ($this) {
            self::Parcheggiato => 'Questo sito e\' in preparazione. Torna fra qualche giorno.',
            default => 'Questo sito e\' temporaneamente non disponibile. Riprova piu\' tardi.',
        };
    }

    /** @return array<string, string> per le tendine del pannello */
    public static function opzioni(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->etichetta()])->all();
    }
}
