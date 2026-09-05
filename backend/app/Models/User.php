<?php

namespace App\Models;

use App\Enums\Ruolo;
use App\Models\Concerns\BelongsToSiteViaPivot;
use App\Models\Concerns\HasAppMfa;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Collection;

/**
 * Utente redattore di uno o piu' mini siti.
 *
 * NON usa BelongsToSite: la relazione con i siti e' many-to-many via pivot
 * site_user, non una colonna site_id, perche' la stessa persona puo'
 * lavorare su piu' siti dello stesso cliente con ruoli diversi.
 *
 * L'isolamento per questo modello passa quindi da getTenants()/canAccessTenant(),
 * non dal global scope: e' il motivo per cui e' elencato in
 * TenantScopeTest::EXCLUDED_MODELS.
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants
{
    use BelongsToSiteViaPivot;
    use HasAppMfa;
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var array<int|string, string|null> ruolo per sito, riempita alla prima domanda */
    protected array $ruoliPerSito = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Cifrati a riposo: chi legge il database non deve poter
            // rigenerare i codici TOTP di nessuno.
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * I "tenant" del pannello data plane sono i Site, non i Tenant: il
     * selettore in /admin fa passare da un mini sito all'altro.
     */
    public function getTenants(Panel $panel): array | Collection
    {
        // withoutTenancy() e' necessario: Site porta il global scope di stancl,
        // e dopo che l'utente ha scelto un sito la tenancy e' inizializzata.
        // Senza questo, il selettore mostrerebbe solo i siti del tenant
        // corrente e nasconderebbe silenziosamente gli altri a cui l'utente
        // ha accesso. L'insieme dei siti accessibili lo definisce il pivot,
        // non lo stato della tenancy.
        return $this->sites()->withoutTenancy()->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->sites()->withoutTenancy()->whereKey($tenant)->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->sites()->withoutTenancy()->exists();
    }

    /**
     * Il tenant (cliente) a cui appartiene questo utente, dedotto dai suoi siti.
     *
     * Un utente appartiene a UN SOLO cliente: puo' avere piu' mini siti, ma
     * tutti dello stesso cliente (specifiche, sezione 5). Chi amministra piu'
     * clienti usa il control plane, non il pannello di un sito.
     */
    public function tenantId(): ?string
    {
        return $this->sites()->withoutTenancy()->value('sites.tenant_id');
    }

    /**
     * Ruolo dell'utente su un sito specifico. Il ruolo sta sul pivot:
     * la stessa persona puo' essere admin su un sito e author su un altro.
     */
    public function roleOn(Site | int | string | null $site): ?string
    {
        $chiave = $site instanceof Site ? $site->getKey() : $site;

        if ($chiave === null) {
            return null;
        }

        // Memoria per istanza. Una policy viene interrogata decine di volte
        // per schermata — una per riga, una per ogni azione di quella riga —
        // e senza questo sarebbero decine di query identiche. Sta
        // sull'istanza e non in una static: un utente riletto dal database
        // riparte pulito, come succede per le relazioni.
        if (! array_key_exists($chiave, $this->ruoliPerSito)) {
            // withoutTenancy() come negli altri metodi: dentro il pannello la
            // tenancy e' inizializzata e il global scope di stancl su Site
            // nasconderebbe i siti degli altri clienti. Qui la domanda e'
            // sul pivot, e la risposta non deve dipendere dallo stato della
            // tenancy nel momento in cui viene fatta.
            $this->ruoliPerSito[$chiave] = $this->sites()
                ->withoutTenancy()
                ->whereKey($chiave)
                ->first()?->pivot->role;
        }

        return $this->ruoliPerSito[$chiave];
    }

    /** Lo stesso ruolo, come enum: la forma con cui ragionano le policy. */
    public function ruolo(Site | int | string | null $site): ?Ruolo
    {
        return Ruolo::da($this->roleOn($site));
    }
}
