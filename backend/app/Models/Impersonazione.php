<?php

namespace App\Models;

use App\ControlPlane\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Un accesso di un amministratore di piattaforma al pannello di un sito.
 *
 * NON usa BelongsToSite: e' un registro di piattaforma, va letto dal control
 * plane dove non c'e' nessun sito corrente, e deve mostrare gli accessi a
 * TUTTI i siti. Esclusione consapevole, elencata in TenantScopeTest.
 */
class Impersonazione extends Model
{
    protected $table = 'impersonazioni';

    protected $fillable = ['token', 'admin_user_id', 'user_id', 'site_id', 'ip', 'usato_il', 'terminata_il'];

    protected function casts(): array
    {
        return [
            'usato_il' => 'datetime',
            'terminata_il' => 'datetime',
        ];
    }

    /** Secondi entro cui il token va speso. Oltre, non vale piu' nulla. */
    public const VALIDITA = 60;

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public static function apri(AdminUser $admin, User $utente, Site $site, ?string $ip = null): self
    {
        return static::create([
            'token' => Str::random(64),
            'admin_user_id' => $admin->id,
            'user_id' => $utente->id,
            'site_id' => $site->id,
            'ip' => $ip,
        ]);
    }

    /** Un token vale una volta sola e per un minuto. */
    public function spendibile(): bool
    {
        return $this->usato_il === null
            && $this->created_at->diffInSeconds(now()) <= self::VALIDITA;
    }
}
