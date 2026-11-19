<?php

/**
 * Stores each item's audit problems when the index job runs, so the Audit
 * page reads them instead of working the checks out on every view (#493).
 *
 * @package teme/seo
 * @since   1.0.1
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_items', function (Blueprint $table): void {
            $table->jsonb('problems')->default('[]');
            $table->smallInteger('problem_count')->default(0);
            $table->index('problem_count');
        });
    }
};
