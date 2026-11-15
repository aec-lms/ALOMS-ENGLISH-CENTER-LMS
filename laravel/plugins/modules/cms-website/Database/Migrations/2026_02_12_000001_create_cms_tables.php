<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_pages')) {
            Schema::create('cms_pages', function (Blueprint $table): void {
                $table->id();
                $table->string('slug', 160)->unique();
                $table->string('title', 180);
                $table->json('blocks_json');
                $table->string('seo_title', 180)->nullable();
                $table->string('seo_description', 320)->nullable();
                $table->boolean('is_published')->default(false);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('cms_sliders')) {
            Schema::create('cms_sliders', function (Blueprint $table): void {
                $table->id();
                $table->string('key_name', 80); // hero, testimonial_left, testimonial_right
                $table->string('title', 180);
                $table->string('subtitle', 255)->nullable();
                $table->string('image_path', 255)->nullable();
                $table->string('cta_url', 255)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('contact_inquiries')) {
            Schema::create('contact_inquiries', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('email', 180);
                $table->string('subject', 180);
                $table->text('message');
                $table->string('status', 24)->default('new');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
