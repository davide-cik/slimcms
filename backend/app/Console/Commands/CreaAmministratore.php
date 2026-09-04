<?php

namespace App\Console\Commands;

use App\ControlPlane\Models\AdminUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Crea un amministratore del control plane.
 *
 * Serve al bootstrap di un ambiente nuovo: il database di produzione nasce
 * vuoto di proposito, e i seeder demo si rifiutano di girarci.
 *
 * NON imposta il segreto MFA. Al primo accesso e' Filament a mostrare il
 * codice QR da inquadrare con l'app authenticator: cosi' il segreto nasce nel
 * browser di chi lo usera' e non passa da un canale di terzi. Un secondo
 * fattore che qualcun altro puo' generare non e' un secondo fattore.
 */
class CreaAmministratore extends Command
{
    protected $signature = 'slimcms:crea-admin
                            {email}
                            {--nome= : nome visualizzato}
                            {--ruolo=super-admin : super-admin oppure support}
                            {--password= : se omessa, ne viene generata una}';

    protected $description = 'Crea un amministratore del control plane';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Email non valida: {$email}");

            return self::FAILURE;
        }

        if (AdminUser::where('email', $email)->exists()) {
            $this->error("Esiste gia' un amministratore con {$email}.");

            return self::FAILURE;
        }

        $ruolo = $this->option('ruolo');

        if (! in_array($ruolo, ['super-admin', 'support'], true)) {
            $this->error("Ruolo non valido: {$ruolo}. Ammessi: super-admin, support.");

            return self::FAILURE;
        }

        // Password generata se non fornita: piu' sicura di una scelta a mano
        // sotto pressione, ed e' comunque temporanea.
        $password = $this->option('password') ?: Str::password(20, symbols: false);

        $utente = AdminUser::create([
            'name' => $this->option('nome') ?: Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make($password),
            'role' => $ruolo,
        ]);

        $this->newLine();
        $this->info('Amministratore creato.');
        $this->line('  email:    ' . $utente->email);
        $this->line('  ruolo:    ' . $utente->role);

        if (! $this->option('password')) {
            $this->line('  password: ' . $password);
            $this->newLine();
            $this->warn('La password e\' mostrata una volta sola. Cambiala dopo il primo accesso.');
        }

        $this->newLine();
        $this->line('Al primo accesso il pannello chiedera\' di configurare l\'autenticazione');
        $this->line('a due fattori: inquadra il codice QR con l\'app authenticator.');
        $this->line('La MFA e\' obbligatoria e non si puo\' saltare.');

        return self::SUCCESS;
    }
}
