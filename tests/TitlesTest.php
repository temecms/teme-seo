<?php

declare(strict_types=1);

namespace Teme\Seo\Tests;

use App\Domain\Contents\Models\Content;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestDox;
use Teme\Seo\Tests\Support\SeoTestCase;

require_once __DIR__.'/Support/SeoTestCase.php';

/**
 * The bulk editor for titles and descriptions, which writes through core as the person.
 */
final class TitlesTest extends SeoTestCase
{
    #[TestDox('lists items with their SEO titles and descriptions, and finds the ones missing')]
    public function test_it_lists_items_with_their_seo_titles_and_descriptions_and_finds_the_ones_missing(): void
    {
        seoWrite($this->editor, 'Feeding roses', fields: ['seo_title' => 'Feed roses well', 'meta_description' => 'How and when.']);
        $bare = seoWrite($this->editor, 'Planting tulips');

        $this->signIn($this->editor);
        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'titles', 't' => ['titles' => ['filters' => ['missing' => 'description']]]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('block_titles', fn (Assert $reload) => $reload
                ->where('block_titles.total', 1)
                ->where('block_titles.rows.0.id', $bare->id)));

        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'titles', 't' => ['titles' => ['search' => 'feed roses']]]))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps('block_titles', fn (Assert $reload) => $reload
                ->where('block_titles.rows.0.cells.seo-title.value', 'Feed roses well')
                ->where('block_titles.rows.0.cells.description.value', 'How and when.')));
    }

    #[TestDox('fits the Titles and descriptions table without a Status column that hid the rest')]
    public function test_it_fits_the_titles_and_descriptions_table_without_a_status_column_that_hid_the_rest(): void
    {
        $this->signIn($this->editor);
        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'titles']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks', fn (iterable $blocks): bool => collect($blocks)->contains(fn (mixed $block): bool => is_array($block)
                    && ($block['id'] ?? null) === 'titles'
                    && ($block['type'] ?? null) === 'table'
                    && collect((array) ($block['columns'] ?? []))->pluck('id')->all() === ['title', 'seo-title', 'description'])));
    }

    #[TestDox('lists to a person only the drafts they may edit, when titles asks for unpublished items')]
    public function test_it_lists_to_a_person_only_the_drafts_they_may_edit_when_titles_asks_for_unpublished_items(): void
    {
        $author = $this->person('author');
        User::query()->findOrFail($author->id)->givePermissionTo('plugin.seo.manage');
        seoWrite($this->editor, 'Feeding roses');
        $secret = seoWrite($this->editor, 'An editor’s secret plan', publish: false);
        $own = seoWrite($author, 'My own draft', publish: false);

        $this->signIn($author);
        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'titles', 't' => ['titles' => ['filters' => ['status' => 'unpublished']]]]))
            ->assertOk()
            ->assertInertia(fn (Assert $view) => $view->loadDeferredProps('block_titles', fn (Assert $reload) => $reload
                ->where('block_titles.total', 1)
                ->where('block_titles.rows.0.id', $own->id)));

        // The drafts an editor may edit are all theirs to see.
        $this->signIn($this->editor);
        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'titles', 't' => ['titles' => ['filters' => ['status' => 'unpublished'], 'search' => 'secret plan']]]))
            ->assertInertia(fn (Assert $view) => $view->loadDeferredProps('block_titles', fn (Assert $reload) => $reload
                ->where('block_titles.total', 1)
                ->where('block_titles.rows.0.id', $secret->id)));
    }

    #[TestDox('saves a new title and description as a revision of the draft, as the person editing')]
    public function test_it_saves_a_new_title_and_description_as_a_revision_of_the_draft_as_the_person_editing(): void
    {
        $item = seoWrite($this->editor, 'Planting tulips');
        $revisions = DB::table('content_revisions')->where('content_id', $item->id)->count();

        $this->signIn($this->editor);
        $this->get(route('plugins.area.edit', ['code' => 'seo', 'record' => 'item', 'id' => $item->id]))->assertOk();
        $this->post(route('plugins.area.save', ['code' => 'seo', 'record' => 'item', 'id' => $item->id]), ['values' => [
            'title' => 'Planting tulips in autumn',
            'seo_title' => 'When to plant tulips',
            'meta_description' => 'Plant tulips in autumn, before the ground freezes.',
            'cornerstone' => true,
        ]])->assertRedirect()->assertSessionHasNoErrors();

        $content = Content::query()->findOrFail($item->id);

        expect(DB::table('content_revisions')->where('content_id', $item->id)->count())->toBe($revisions + 1)
            ->and(DB::table('content_revisions')->where('id', $content->working_revision_id)->value('title'))->toBe('Planting tulips in autumn')
            ->and(DB::table('seo_items')->where('id', $item->id)->value('meta_description'))->toBe('Plant tulips in autumn, before the ground freezes.')
            ->and(DB::table('seo_items')->where('id', $item->id)->value('cornerstone'))->toBeTrue();
    }

    #[TestDox('refuses, writing nothing, what the editor would refuse')]
    public function test_it_refuses_writing_nothing_what_the_editor_would_refuse(): void
    {
        $item = seoWrite($this->editor, 'Planting tulips');
        $revisions = DB::table('content_revisions')->where('content_id', $item->id)->count();

        $this->signIn($this->editor);
        $this->post(route('plugins.area.save', ['code' => 'seo', 'record' => 'item', 'id' => $item->id]), ['values' => [
            'title' => 'Planting tulips',
            'seo_title' => str_repeat('Too long ', 30),
        ]])->assertSessionHasErrors();

        expect(DB::table('content_revisions')->where('content_id', $item->id)->count())->toBe($revisions);
    }

    #[TestDox('writes nothing for an author who may not edit another person’s item')]
    public function test_it_writes_nothing_for_an_author_who_may_not_edit_another_persons_item(): void
    {
        $item = seoWrite($this->editor, 'Planting tulips');
        $author = $this->person('author');
        // The author may use Teme SEO's pages, but not edit an editor's item.
        User::query()->findOrFail($author->id)->givePermissionTo('plugin.seo.manage');

        $this->signIn($author);
        $this->post(route('plugins.area.save', ['code' => 'seo', 'record' => 'item', 'id' => $item->id]), ['values' => ['title' => 'Mine now']])
            ->assertSessionHasErrors('record');

        expect(DB::table('content_revisions')->where('content_id', $item->id)->where('title', 'Mine now')->exists())->toBeFalse();
    }
}
