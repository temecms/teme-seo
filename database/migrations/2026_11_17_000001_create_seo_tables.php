<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per content item: what Teme SEO read from its working revision.
        Schema::create('seo_items', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->string('type', 32);
            $table->string('language', 35)->default('');
            $table->text('title');
            $table->string('status', 16);
            $table->boolean('indexable')->default(true);
            $table->boolean('trashed')->default(false);
            $table->text('seo_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->boolean('has_excerpt')->default(false);
            $table->boolean('has_featured_image')->default(false);
            $table->unsignedInteger('words')->default(0);
            $table->unsignedInteger('links_out')->default(0);
            $table->unsignedInteger('images_missing_alt')->default(0);
            $table->boolean('cornerstone')->default(false);
            $table->boolean('stale')->default(true);
            $table->timestampTz('indexed_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->index(['status', 'trashed']);
            $table->index('stale');
        });

        // Each internal link one item's body makes to another, by ID (rule 9 keeps them exact).
        Schema::create('seo_links', function (Blueprint $table): void {
            $table->string('source_id', 26);
            $table->string('target_id', 26);
            $table->unsignedSmallInteger('occurrences')->default(1);

            $table->primary(['source_id', 'target_id']);
            $table->index('target_id');
        });

        // What the editor panels offer a person, until it is used or discarded.
        Schema::create('seo_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->string('item_id', 26);
            $table->string('panel', 24);
            $table->string('key', 64);
            $table->string('label', 120);
            $table->string('detail', 500)->default('');
            $table->jsonb('change');
            $table->string('settled', 16)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['item_id', 'panel', 'key']);
        });

        // Search Console's totals by day.
        Schema::create('seo_search_days', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->double('position')->default(0);
        });

        // Search Console's top searches and top pages over the last period fetched.
        Schema::create('seo_search_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 8);
            $table->text('key');
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->double('position')->default(0);

            $table->index(['kind', 'clicks']);
        });
    }
};
