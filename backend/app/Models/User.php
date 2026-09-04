<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSiteViaPivot;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
class User extends Authenticatable implements FilamentUser, HasTenants
{
    use BelongsToSiteViaPivot;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
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
    public function roleOn(Site $site): ?string
    {
        return $this->sites()->whereKey($site)->first()?->pivot->role;
    }
}
