<?php

namespace App\Models;

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
        return $this->sites;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->sites()->whereKey($tenant)->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->sites()->exists();
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
