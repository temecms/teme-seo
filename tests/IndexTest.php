<?php

declare(strict_types=1);

namespace Teme\Seo\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Teme\Seo\Tests\Support\SeoTestCase;

require_once __DIR__.'/Support/SeoTestCase.php';

/**
 * The index-site job: what the hourly pass reads, and what reading every item again repairs.
 */
final class IndexTest extends SeoTestCase
{
    #[TestDox('reads only what changed on the hourly pass, and every item when asked')]
    public function test_it_reads_only_what_changed_on_the_hourly_pass_and_every_item_when_asked(): void
    {
        $roses = seoWrite($this->editor, 'Feeding roses in spring');
        $tulips = seoWrite($this->editor, 'Planting tulips');
        DB::table('seo_items')->update(['indexed_at' => '2026-01-01 00:00:00+00', 'updated_at' => '2026-01-01 00:00:00+00']);
        DB::table('seo_items')->where('id', $tulips->id)->update(['stale' => true]);

        expect($this->seo->runJob('index-site')->state)->toBe('finished');

        $touched = DB::table('seo_items')->where('updated_at', '>', '2026-01-01 00:00:00+00')->pluck('id')->all();

        expect($touched)->toBe([$tulips->id]);

        $this->seo->runJob('index-site', ['action' => 'read-links']);

        expect(DB::table('seo_items')->where('id', $roses->id)->value('indexed_at'))->not->toBe('2026-01-01 00:00:00+00');
    }

    #[TestDox('clears what changed while Teme SEO was off when every item is read again')]
    public function test_it_clears_what_changed_while_teme_seo_was_off_when_every_item_is_read_again(): void
    {
        $roses = seoWrite($this->editor, 'Feeding roses in spring');
        $tulips = seoWrite($this->editor, 'Planting tulips');
        $gone = '01kccccccccccccccccccccccc';

        // While Teme SEO was off, tulips went back to draft and another item was deleted for good.
        DB::table('seo_items')->insert(['id' => $gone, 'type' => 'post', 'title' => 'Deleted', 'status' => 'published', 'stale' => false]);
        DB::table('contents')->where('id', $tulips->id)->update(['published_revision_id' => null, 'status' => 'draft']);

        $this->seo->runJob('index-site', ['action' => 'read-links']);

        expect(DB::table('seo_items')->where('id', $gone)->exists())->toBeFalse()
            ->and(DB::table('seo_items')->where('id', $tulips->id)->value('status'))->not->toBe('published')
            ->and(DB::table('seo_items')->where('id', $roses->id)->value('status'))->toBe('published');
    }
}
