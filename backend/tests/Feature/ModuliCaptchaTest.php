<?php

namespace Tests\Feature;

use App\Models\Messaggio;
use App\Models\Modulo;
use App\Models\Plan;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\Captcha\CaptchaSemplice;
use App\Support\Captcha\FabbricaCaptcha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * I moduli di un sito e la verifica anti-spam.
 *
 * Un sito ne ha quanti ne servono, ognuno con i propri campi e il proprio
 * destinatario; il captcha lo sceglie chi gestisce il sito.
 */
class ModuliCaptchaTest extends TestCase
{
    use RefreshDatabase;

    private Site $sito;
    private Modulo $modulo;

    protected function setUp(): void
    {
        parent::setUp();

        $piano = Plan::create(['name' => 'T', 'price_monthly' => 0, 'max_sites' => 5, 'max_storage_gb' => 1]);
        $tenant = Tenant::create(['id' => 'c', 'name' => 'C', 'slug' => 'c', 'status' => 'active', 'plan_id' => $piano->id]);

        $this->sito = Site::withoutTenancy()->create([
            'tenant_id' => $tenant->id, 'domain' => 'c.test', 'name' => 'C',
            'contact_email' => 'sito@c.test',
            'captcha_fornitore' => 'nessuno',
        ]);

        $this->sito->useAsCurrent();
        $this->modulo = Modulo::create([
            'nome' => 'Richiesta preventivo',
            'email_destinatario' => 'preventivi@c.test',
            'messaggio_conferma' => 'Ti mandiamo un preventivo entro due giorni.',
            'campi' => [
                ['etichetta' => 'Azienda', 'nome' => 'azienda', 'tipo' => 'testo', 'obbligatorio' => true],
                ['etichetta' => 'Budget', 'nome' => 'budget', 'tipo' => 'scelta', 'obbligatorio' => false,
                 'opzioni' => ['fino a 5.000', 'oltre 5.000']],
                ['etichetta' => 'Accetto la privacy', 'nome' => 'privacy', 'tipo' => 'consenso', 'obbligatorio' => true],
            ],
        ]);
        Site::forgetCurrent();

        Cache::flush();
        Mail::fake();
    }

    private function invia(array $sovrascrivi = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/public/c.test/contact', array_replace_recursive([
            'modulo' => $this->modulo->slug,
            'name' => 'Giulia',
            'email' => 'giulia@example.com',
            'message' => 'Vorrei un preventivo.',
            'dati' => ['azienda' => 'Bianchi Srl', 'budget' => 'oltre 5.000', 'privacy' => '1'],
        ], $sovrascrivi));
    }

    public function test_lo_slug_si_ricava_dal_nome(): void
    {
        $this->assertSame('richiesta-preventivo', $this->modulo->slug);
    }

    public function test_i_campi_in_piu_arrivano_col_messaggio(): void
    {
        $this->invia()->assertOk();

        $m = Messaggio::withoutSiteScope()->sole();

        $this->assertSame($this->modulo->id, $m->modulo_id);
        $this->assertSame('Azienda', $m->dati[0]['etichetta']);
        $this->assertSame('Bianchi Srl', $m->dati[0]['valore']);
    }

    /**
     * Le etichette si copiano nel messaggio invece di risolverle dal modulo:
     * un campo rinominato l'anno prossimo non deve rendere illeggibile un
     * messaggio ricevuto oggi.
     */
    public function test_le_etichette_restano_anche_se_il_modulo_cambia(): void
    {
        $this->invia()->assertOk();

        $this->sito->useAsCurrent();
        $this->modulo->update(['campi' => []]);
        Site::forgetCurrent();

        $this->assertSame('Azienda', Messaggio::withoutSiteScope()->sole()->dati[0]['etichetta']);
    }

    public function test_un_campo_obbligatorio_del_modulo_e_obbligatorio(): void
    {
        $this->invia(['dati' => ['azienda' => '']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dati.azienda']);
    }

    /** Una scelta fuori elenco non e' un refuso: e' un valore cambiato prima dell'invio. */
    public function test_una_scelta_fuori_elenco_viene_rifiutata(): void
    {
        $this->invia(['dati' => ['budget' => 'gratis']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dati.budget']);
    }

    public function test_il_destinatario_del_modulo_vince_su_quello_del_sito(): void
    {
        $this->invia()->assertOk();

        Mail::assertSent(\App\Mail\MessaggioDiContatto::class, fn ($m) => $m->hasTo('preventivi@c.test'));
    }

    public function test_un_modulo_spento_non_accetta_invii(): void
    {
        $this->sito->useAsCurrent();
        $this->modulo->update(['attivo' => false]);
        Site::forgetCurrent();

        // Il modulo non si trova piu': restano i tre campi di sempre, e i
        // campi in piu' non vengono nemmeno chiesti.
        $this->invia()->assertOk();

        $this->assertNull(Messaggio::withoutSiteScope()->sole()->modulo_id);
    }

    public function test_la_conferma_del_modulo_torna_al_visitatore(): void
    {
        $this->invia()->assertOk()->assertJsonPath('message', 'Ti mandiamo un preventivo entro due giorni.');
    }

    // ------------------------------------------------------------- captcha

    private function conCaptcha(string $fornitore, ?string $chiave = null, ?string $segreto = null): void
    {
        $this->sito->forceFill([
            'captcha_fornitore' => $fornitore,
            'captcha_chiave_pubblica' => $chiave,
            'captcha_segreto' => $segreto,
        ])->save();
    }

    public function test_il_captcha_semplice_e_il_predefinito(): void
    {
        $this->sito->forceFill(['captcha_fornitore' => null])->save();

        $this->assertInstanceOf(CaptchaSemplice::class, FabbricaCaptcha::per($this->sito->fresh()));
    }

    public function test_la_sfida_semplice_si_verifica_e_non_si_rigioca(): void
    {
        $this->conCaptcha('semplice');

        $sfida = $this->getJson('/api/public/c.test/captcha')->assertOk()->json('sfida');

        preg_match('/(\d+) (.) (\d+)/u', $sfida['domanda'], $m);
        $risposta = (string) ($m[2] === '+' ? $m[1] + $m[3] : $m[1] - $m[3]);

        $this->invia(['captcha' => $risposta, 'captcha_token' => $sfida['token']])->assertOk();

        // Lo stesso gettone non vale due volte.
        $this->invia(['captcha' => $risposta, 'captcha_token' => $sfida['token']])->assertStatus(422);
        $this->assertSame(1, Messaggio::withoutSiteScope()->count());
    }

    public function test_una_risposta_sbagliata_non_passa(): void
    {
        $this->conCaptcha('semplice');

        $sfida = $this->getJson('/api/public/c.test/captcha')->json('sfida');

        $this->invia(['captcha' => '9999', 'captcha_token' => $sfida['token']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['captcha']);

        $this->assertSame(0, Messaggio::withoutSiteScope()->count());
    }

    public function test_senza_captcha_il_messaggio_non_passa(): void
    {
        $this->conCaptcha('semplice');

        $this->invia()->assertStatus(422);
    }

    public function test_turnstile_accetta_quando_cloudflare_dice_di_si(): void
    {
        $this->conCaptcha('turnstile', 'chiave-pubblica', 'segreto');
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->invia(['cf-turnstile-response' => 'gettone'])->assertOk();
    }

    public function test_turnstile_rifiuta_quando_cloudflare_dice_di_no(): void
    {
        $this->conCaptcha('turnstile', 'chiave-pubblica', 'segreto');
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->invia(['cf-turnstile-response' => 'gettone'])->assertStatus(422);
    }

    /**
     * reCAPTCHA v3 da' un punteggio invece di un si'/no: sotto la soglia non
     * e' una persona. Due test e non uno con due `Http::fake`: la seconda
     * chiamata non sostituisce la prima, le aggiunge, e vince quella che
     * corrisponde per prima — un test scritto cosi' verificherebbe due volte
     * lo stesso caso credendo di verificarne due.
     */
    public function test_recaptcha_rifiuta_un_punteggio_basso(): void
    {
        $this->conCaptcha('recaptcha', 'chiave', 'segreto');
        Http::fake(['www.google.com/*' => Http::response(['success' => true, 'score' => 0.1])]);

        $this->invia(['g-recaptcha-response' => 'gettone'])->assertStatus(422);
        $this->assertSame(0, Messaggio::withoutSiteScope()->count());
    }

    public function test_recaptcha_accetta_un_punteggio_alto(): void
    {
        $this->conCaptcha('recaptcha', 'chiave', 'segreto');
        Http::fake(['www.google.com/*' => Http::response(['success' => true, 'score' => 0.9])]);

        $this->invia(['g-recaptcha-response' => 'gettone'])->assertOk();
        $this->assertSame(1, Messaggio::withoutSiteScope()->count());
    }

    /**
     * Un fornitore irraggiungibile non deve buttare via un messaggio vero:
     * l'esca e il limite di invii restano comunque in piedi.
     */
    public function test_un_fornitore_irraggiungibile_non_perde_il_messaggio(): void
    {
        $this->conCaptcha('turnstile', 'chiave', 'segreto');
        Http::fake(['challenges.cloudflare.com/*' => Http::response('', 503)]);

        $this->invia(['cf-turnstile-response' => 'gettone'])->assertOk();
    }

    /** Il segreto non deve uscire dal backend: finirebbe nell'HTML del sito. */
    public function test_il_segreto_del_captcha_non_esce_dall_api(): void
    {
        $this->conCaptcha('turnstile', 'chiave-pubblica', 'segretissimo');

        $r = $this->getJson('/api/public/c.test/captcha')->assertOk();

        $this->assertStringNotContainsString('segretissimo', $r->getContent());
        $this->assertSame('chiave-pubblica', $r->json('captcha.chiave'));
    }
}
