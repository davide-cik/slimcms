<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            // tenant_id e' string perche' tenants.id e' una string (UUID) in stancl/tenancy.
            $table->string('tenant_id');
            $table->string('domain')->unique();
            $table->string('name');
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();
            $table->json('theme')->nullable();
            $table->json('seo_defaults')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
