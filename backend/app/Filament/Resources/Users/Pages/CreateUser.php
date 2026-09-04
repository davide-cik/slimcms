<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Il legame utente-sito vive sul pivot, non su una colonna: va creato
     * a mano dopo il salvataggio. Senza questo l'utente nascerebbe orfano,
     * invisibile alla lista (che e' scoped per pivot) e incapace di
     * accedere a qualsiasi pannello.
     */
    protected function afterCreate(): void
    {
        $site = Filament::getTenant();

        if ($site === null) {
            return;
        }

        $this->record->sites()->syncWithoutDetaching([
            $site->getKey() => ['role' => $this->data['ruolo_sul_sito'] ?? 'editor'],
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
