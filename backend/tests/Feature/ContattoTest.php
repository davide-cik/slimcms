<?php

namespace Tests\Feature;

use App\Mail\MessaggioDiContatto;
use App\Models\Messaggio;
use App\Models\Page;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Il modulo di contatto, dall'invio del visitatore alla riga nel pannello.
 *
 * E' l'unico endpoint del sito pubblico che scrive, e l'unico che il browser
 * di un visitatore chiama davvero. Prima non lo chiamava nessuno: il sito si
 * risolveva dall'Host, e l'Host che arriva qui e' sempre quello dell'API,
 * mai quello del cliente. Rispondeva 404 a chiunque.
 */
class ContattoTest extends TestCase
{
    use RefreshDatabase;

    private Site $sitoA;
    private Site $sitoB;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);

        $this->sitoA = Site::withoutTenancy()->create([
            'tenant_id' => $tenant->id, 'domain' => 'a.test', 'name' => 'A',
            'contact_email' => 'titolare@a.test',
        ]);
        $this->sitoB = Site::withoutTenancy()->create([
            'tenant_id' => $tenant->id, 'domain' => 'b.test', 'name' => 'B',
        ]);

        Mail::fake();
    }

    /**
     * La sfida del captcha semplice, risolta.
     *
     * I test non la spengono: da quando esiste, il captcha semplice e' il
     * valore predefinito di ogni sito, quindi spegnerlo qui vorrebbe dire
     * provare un percorso che nessun sito vero segue.
     *
     * @return array{captcha: string, captcha_token: string}
     */
    private function sfidaRisolta(string $dominio): array
    {
        $sfida = $this->getJson("/api/public/{$dominio}/captcha")->json('sfida');

        if ($sfida === null) {
            return [];
        }

        preg_match('/(\d+) (.) (\d+)/u', $sfida['domanda'], $m);

        return [
            'captcha' => (string) ($m[2] === '+' ? $m[1] + $m[3] : $m[1] - $m[3]),
            'captcha_token' => $sfida['token'],
        ];
    }

    private function invia(string $dominio, array $sovrascrivi = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/public/{$dominio}/contact", array_merge($this->sfidaRisolta($dominio), [
            'name' => 'Giulia Bianchi',
            'email' => 'giulia@example.com',
            'message' => 'Vorrei un preventivo.',
            'page' => '/contatti/',
        ], $sovrascrivi));
    }

    public function test_il_messaggio_finisce_in_tabella(): void
    {
        $this->invia('a.test')->assertOk()->assertJsonPath('ok', true);

        $m = Messaggio::withoutSiteScope()->sole();

        $this->assertSame($this->sitoA->id, $m->site_id);
        $this->assertSame('Giulia Bianchi', $m->nome);
        $this->assertSame('/contatti/', $m->pagina);
        $this->assertNull($m->letto_il);
    }

    /**
     * Il messaggio va salvato PRIMA dell'invio, non dopo.
     *
     * Un form che risponde "messaggio ricevuto" e si affida solo alla mail e'
     * un modo per perdere richieste commerciali senza accorgersene.
     */
    public function test_il_messaggio_resta_anche_se_la_mail_non_parte(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP giu'));

        $this->invia('a.test')->assertOk();

        $this->assertSame(1, Messaggio::withoutSiteScope()->count());
    }

    public function test_avvisa_il_destinatario_configurato(): void
    {
        $this->invia('a.test')->assertOk();

        Mail::assertSent(MessaggioDiContatto::class, fn ($mail) => $mail->hasTo('titolare@a.test'));
    }

    public function test_senza_destinatario_non_manda_niente_ma_salva(): void
    {
        $this->invia('b.test')->assertOk();

        Mail::assertNothingSent();
        $this->assertSame(1, Messaggio::withoutSiteScope()->count());
    }

    /**
     * L'esca risponde 200 come un invio riuscito.
     *
     * Un 422 direbbe al bot quale campo togliere. Prima era una regola di
     * validazione `max:0` che faceva esattamente questo, mentre il commento
     * accanto prometteva il contrario.
     */
    public function test_l_esca_risponde_come_un_invio_riuscito_ma_non_salva(): void
    {
        $r = $this->invia('a.test', ['website' => 'http://spam.example']);

        $r->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(0, Messaggio::withoutSiteScope()->count());
        Mail::assertNothingSent();
    }

    public function test_i_campi_obbligatori_sono_obbligatori(): void
    {
        $this->invia('a.test', ['name' => '', 'email' => 'non-una-email', 'message' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'message']);

        $this->assertSame(0, Messaggio::withoutSiteScope()->count());
    }

    public function test_un_dominio_sconosciuto_da_404(): void
    {
        $this->invia('mai-esistito.test')->assertNotFound();
    }

    /** Il messaggio va al sito nella URL, non a un altro. */
    public function test_il_messaggio_va_al_sito_giusto(): void
    {
        $this->invia('b.test')->assertOk();

        $this->assertSame($this->sitoB->id, Messaggio::withoutSiteScope()->sole()->site_id);
    }

    /**
     * Senza intestazioni CORS il browser scarta la risposta e il modulo non
     * funziona da nessun sito: il sito statico e l'API stanno su domini
     * diversi, quindi la chiamata e' per forza cross-origin.
     */
    public function test_la_risposta_porta_le_intestazioni_cors(): void
    {
        $this->postJson("/api/public/a.test/contact", array_merge($this->sfidaRisolta('a.test'), [
            'name' => 'G', 'email' => 'g@example.com', 'message' => 'ciao',
        ]), ['Origin' => 'https://a.test'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_il_resto_dell_api_non_risponde_a_nessuna_origine(): void
    {
        $r = $this->getJson("/api/sites/a.test", ['Origin' => 'https://cattivo.test']);

        $this->assertNull($r->headers->get('Access-Control-Allow-Origin'));
    }

    // ------------------------------------------------------------- ricerca

    public function test_la_ricerca_trova_pagine_e_articoli(): void
    {
        $this->sitoA->useAsCurrent();
        Page::create(['title' => 'Chi siamo', 'slug' => 'chi-siamo', 'status' => 'published']);
        Post::create(['title' => 'Perche WordPress', 'slug' => 'perche-wordpress', 'status' => 'published', 'publish_at' => now()->subDay()]);
        Site::forgetCurrent();

        $r = $this->getJson('/api/public/a.test/search?q=chi');
        $r->assertOk()->assertJsonPath('results.0.title', 'Chi siamo');

        // Cercare su un sito col blog e non trovare gli articoli e' una
        // ricerca che mente.
        $this->getJson('/api/public/a.test/search?q=wordpress')
            ->assertOk()
            ->assertJsonPath('results.0.title', 'Perche WordPress');
    }

    public function test_la_ricerca_non_esce_dal_sito(): void
    {
        $this->sitoA->useAsCurrent();
        Page::create(['title' => 'Segreta', 'slug' => 'segreta', 'status' => 'published']);
        Site::forgetCurrent();

        $this->getJson('/api/public/b.test/search?q=segreta')
            ->assertOk()
            ->assertJsonPath('results', []);
    }
}
