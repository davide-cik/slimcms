<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Emette un token Sanctum per il worker di build.
 *
 * Il token e' legato a UN sito tramite l'ability site:<id>. Un token che
 * puo' leggere tutti i siti (sites:*) va emesso solo per il worker di
 * piattaforma, con --all, e va trattato come una credenziale critica:
 * chi lo possiede legge i contenuti di ogni cliente.
 */
class IssueBuildToken extends Command
{
    protected $signature = 'slimcms:build-token
                            {email : utente a cui intestare il token}
                            {--site= : dominio del sito autorizzato}
                            {--all : token di piattaforma, valido su TUTTI i siti}';

    protected $description = 'Emette un token API per il worker di build';

    public function handle(): int
    {
        $utente = User::withoutSitePivotScope()->where('email', $this->argument('email'))->first();

        if ($utente === null) {
            $this->error("Nessun utente con email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if ($this->option('all')) {
            if (! $this->confirm('Questo token leggera i contenuti di TUTTI i clienti. Confermi?', false)) {
                return self::FAILURE;
            }

            $abilita = ['sites:*'];
            $nome = 'build-piattaforma';
        } else {
            $dominio = $this->option('site');

            if (blank($dominio)) {
                $this->error('Serve --site=<dominio> oppure --all.');

                return self::FAILURE;
            }

            $sito = Site::withoutTenancy()->where('domain', $dominio)->first();

            if ($sito === null) {
                $this->error("Nessun sito con dominio {$dominio}.");

                return self::FAILURE;
            }

            if (! $utente->canAccessTenant($sito)) {
                $this->error("{$utente->email} non ha accesso a {$dominio}: token non emesso.");

                return self::FAILURE;
            }

            $abilita = ['site:' . $sito->id];
            $nome = 'build-' . $dominio;
        }

        $token = $utente->createToken($nome, $abilita);

        $this->newLine();
        $this->info('Token emesso. Viene mostrato una volta sola, salvalo ora.');
        $this->line('  nome:     ' . $nome);
        $this->line('  abilita:  ' . implode(', ', $abilita));
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();

        return self::SUCCESS;
    }
}
