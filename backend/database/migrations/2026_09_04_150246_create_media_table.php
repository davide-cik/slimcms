<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            $table->morphs('model');

            // AGGIUNTA NOSTRA, non presente nella migrazione di Spatie.
            //
            // La tabella media di Spatie e' solo polimorfa: senza una colonna
            // di scoping sfuggirebbe al global scope su cui poggia tutto
            // l'isolamento del progetto, e TenantScopeTest non la coprirebbe.
            // Un file caricato da un cliente sarebbe elencabile da un altro.
            //
            // nullable perche' Spatie crea le righe passando dal proprio
            // modello: il valore lo assegna BelongsToSite in creating().
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();

            $table->nullableTimestamps();
        });
    }
};
