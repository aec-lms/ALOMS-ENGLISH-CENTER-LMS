<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('cms_posts')) {
            Schema::create('cms_posts', function (Blueprint $table): void {
                $table->id();
                $table->string('slug', 180)->unique();
                $table->string('title', 200);
                $table->string('category', 100)->default('General');
                $table->string('author', 120)->default("Alom's English Center");
                $table->text('excerpt')->nullable();
                $table->longText('content');
                $table->string('thumbnail_path', 255)->nullable();
                $table->string('seo_title', 180)->nullable();
                $table->string('seo_description', 320)->nullable();
                $table->boolean('is_published')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('cms_pages') && !Schema::hasColumn('cms_pages', 'banner_image')) {
            Schema::table('cms_pages', function (Blueprint $table): void {
                $table->string('banner_image', 255)->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        // Preserved on uninstall.
    }
};
