<?php

declare(strict_types=1);

namespace Teme\Seo\Tests;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestDox;
use Teme\Seo\Checks\BuiltInChecks;
use Teme\Seo\Tests\Support\SeoTestCase;

require_once __DIR__.'/Support/SeoTestCase.php';

/**
 * The links index: counts in and out, the orphan report and what trash does to it.
 */
final class LinksTest extends SeoTestCase
{
    #[TestDox('counts the links in and out of each item exactly, as they are saved')]
    public function test_it_counts_the_links_in_and_out_of_each_item_exactly_as_they_are_saved(): void
    {
        $roses = seoWrite($this->editor, 'Feeding roses in spring');
        $tulips = seoWrite($this->editor, 'Planting tulips', [seoParagraph('Plant them near ', ['your roses', $roses], '.')]);
        $guide = seoWrite($this->editor, 'A garden guide', [seoParagraph(['Roses', $roses], ' and ', ['tulips', $tulips], '.')]);
        seoWrite($this->editor, 'A draft about roses', [seoParagraph(['Roses', $roses])], publish: false);

        $in = DB::table('seo_links')->where('target_id', $roses->id)->count();

        expect($in)->toBe(3)
            ->and(DB::table('seo_items')->where('id', $guide->id)->value('links_out'))->toBe(2)
            ->and(DB::table('seo_items')->where('id', $roses->id)->value('links_out'))->toBe(0);
    }

    #[TestDox('lists the orphaned items, cornerstone items first, and leaves out items only a draft links to')]
    public function test_it_lists_the_orphaned_items_cornerstone_items_first_and_leaves_out_items_only_a_draft_links_t(): void
    {
        $roses = seoWrite($this->editor, 'Feeding roses in spring');
        $tulips = seoWrite($this->editor, 'Planting tulips');
        seoWrite($this->editor, 'A garden guide', [seoParagraph(['Roses', $roses], '.')]);
        seoWrite($this->editor, 'A draft', [seoParagraph(['Tulips', $tulips])], publish: false);
        DB::table('seo_items')->where('id', $tulips->id)->update(['cornerstone' => true]);

        $this->signIn($this->editor);
        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'links']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('block_orphans', fn (Assert $reload) => $reload
                ->where('block_orphans.total', 2)
                ->where('block_orphans.rows.0.id', $tulips->id)
                ->where('block_orphans.rows.0.cells.cornerstone.value', 'Cornerstone')
                ->where('block_orphans.rows.1.cells.title.value', 'A garden guide')));
    }

    #[TestDox('forgets an item deleted for good, and stops counting a trashed item’s links')]
    public function test_it_forgets_an_item_deleted_for_good_and_stops_counting_a_trashed_items_links(): void
    {
        $roses = seoWrite($this->editor, 'Feeding roses in spring');
        $guide = seoWrite($this->editor, 'A garden guide', [seoParagraph(['Roses', $roses], '.')]);

        $this->trash($guide, $this->editor);

        expect(DB::table('seo_items')->where('id', $guide->id)->value('trashed'))->toBeTrue()
            ->and(DB::table('seo_items as i')->whereRaw(BuiltInChecks::conditions()['orphaned'])->where('i.id', $roses->id)->exists())->toBeTrue();
    }
}
