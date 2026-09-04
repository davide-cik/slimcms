<?php

namespace App\Models;

use App\Models\Concerns\HasAppMfa;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Amministratore della piattaforma (control plane).
 *
 * Identita' separata da User: un redattore non puo' diventare amministratore
 * di piattaforma per errore, perche' vive in un'altra tabella e usa un'altra
 * guardia di autenticazione.
 */
class AdminUser extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    use HasAppMfa;
    use HasFactory;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /** Clienti a cui un operatore di assistenza e' limitato. Vuoto = tutti. */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'admin_user_tenant', 'admin_user_id', 'tenant_id')
            ->withTimestamps();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super-admin';
    }

    /**
     * Accesso al SOLO pannello di controllo. Il controllo sull'id del pannello
     * e' esplicito: senza, questo modello soddisferebbe FilamentUser anche per
     * il pannello dei siti, ed e' proprio la confusione che stiamo evitando.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'manage';
    }

    /** Clienti visibili a questo operatore. */
    public function tenantVisibili()
    {
        if ($this->isSuperAdmin()) {
            return Tenant::query();
        }

        return Tenant::whereIn('id', $this->tenants()->pluck('tenants.id'));
    }
}
