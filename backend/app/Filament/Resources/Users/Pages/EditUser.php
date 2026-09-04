<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // "Rimuovi dal sito" invece di "Elimina": l'utente puo' lavorare
            // anche su altri siti, cancellarlo davvero sarebbe sbagliato.
            DeleteAction::make()
                ->label('Rimuovi da questo sito')
                ->modalHeading('Rimuovere il redattore da questo sito?')
                ->modalDescription('Il suo account resta attivo sugli altri siti a cui ha accesso.')
                ->using(function ($record): void {
                    $site = Filament::getTenant();

                    if ($site !== null) {
                        $record->sites()->detach($site->getKey());
                    }
                }),
        ];
    }

    protected function afterSave(): void
    {
        $site = Filament::getTenant();

        if ($site === null) {
            return;
        }

        $this->record->sites()->syncWithoutDetaching([
            $site->getKey() => ['role' => $this->data['ruolo_sul_sito'] ?? 'editor'],
        ]);
    }
}
