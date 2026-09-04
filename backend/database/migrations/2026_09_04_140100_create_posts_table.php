<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            // L'autore puo' essere rimosso dal sito senza che l'articolo sparisca.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('excerpt')->nullable();
            $table->string('featured_image_path')->nullable();
            $table->json('tags')->nullable();
            $table->json('blocks')->nullable();
            $table->json('seo')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('publish_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status', 'publish_at']);
        });

        Schema::create('category_post', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();

            $table->unique(['post_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_post');
        Schema::dropIfExists('posts');
    }
};
