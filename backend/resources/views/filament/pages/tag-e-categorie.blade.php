<x-filament-panels::page>
    {{-- Due riquadri, uno sotto l'altro: sono tabelle, e affiancate su un
         portatile diventano due colonne strette con lo slug che va a capo. --}}
    <div class="flex flex-col gap-6">
        @livewire(\App\Filament\Widgets\TabellaTag::class)
        @livewire(\App\Filament\Widgets\TabellaCategorie::class)
    </div>
</x-filament-panels::page>
