<?php

declare(strict_types=1);

namespace Teme\Seo\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;
use Teme\Seo\Tests\Support\SeoTestCase;

require_once __DIR__.'/Support/SeoTestCase.php';

/**
 * The Internal links panel: suggestions, discarded ones and the cornerstone mark.
 */
final class SuggestionsTest extends SeoTestCase
{
    #[TestDox('suggests links from the draft’s phrases to published items, cornerstone items first')]
    public function test_it_suggests_links_from_the_drafts_phrases_to_published_items_cornerstone_items_first(): void
    {
        $roses = seoWrite($this->editor, 'Feeding roses in spring');
        $apples = seoWrite($this->editor, 'Pruning apple trees');
        $linked = seoWrite($this->editor, 'Planting tulips');
        seoWrite($this->editor, 'Compost for beginners', publish: false);
        DB::table('seo_items')->where('id', $apples->id)->update(['cornerstone' => true]);

        $draft = seoWrite($this->editor, 'My garden year', [
            seoHeading('Feeding roses in spring'),
            seoParagraph('This year I began feeding roses in spring, then pruning apple trees in the orchard. '),
            seoParagraph('Compost for beginners comes next, after ', ['planting tulips', $linked], '.'),
        ], publish: false);

        $this->signIn($this->editor);
        $this->runAction($draft->id, 'find')->assertOk();

        $view = $this->getJson($this->panelUrl($draft->id))->assertOk()->json();

        expect(array_column(array_column($view['suggestions'], 'change'), 'itemId'))->toBe([$apples->id, $roses->id])
            ->and($view['suggestions'][0]['change'])->toMatchArray(['kind' => 'link', 'phrase' => 'pruning apple trees', 'occurrence' => 1])
            ->and($view['suggestions'][0]['label'])->toBe('Link “pruning apple trees”')
            ->and($view['suggestions'][0]['detail'])->toBe('To “Pruning apple trees”, cornerstone content')
            // The heading's match counts, as the editor counts it, but the link goes in the paragraph.
            ->and($view['suggestions'][1]['change'])->toMatchArray(['phrase' => 'feeding roses in spring', 'occurrence' => 2]);
    }

    #[TestDox('never offers a discarded link again')]
    public function test_it_never_offers_a_discarded_link_again(): void
    {
        $roses = seoWrite($this->editor, 'Feeding roses in spring');
        $draft = seoWrite($this->editor, 'My garden year', [seoParagraph('I keep feeding roses in spring.')], publish: false);

        $this->signIn($this->editor);
        $this->runAction($draft->id, 'find');
        $this->postJson(route('content.panels.suggestions', ['content' => $draft->id, 'code' => 'seo', 'panel' => 'links', 'suggestion' => 'link-'.$roses->id]), ['used' => false])->assertOk();
        $this->runAction($draft->id, 'find');

        expect($this->getJson($this->panelUrl($draft->id))->json('suggestions'))->toBe([]);
    }

    #[TestDox('marks and unmarks an item as cornerstone content for a person who may edit it')]
    public function test_it_marks_and_unmarks_an_item_as_cornerstone_content_for_a_person_who_may_edit_it(): void
    {
        $draft = seoWrite($this->editor, 'Our guide to roses', [seoParagraph('All about roses.')]);

        $this->signIn($this->editor);
        $this->runAction($draft->id, 'cornerstone', ['mark' => 'yes'])->assertOk();

        expect(DB::table('seo_items')->where('id', $draft->id)->value('cornerstone'))->toBeTrue()
            ->and($this->getJson($this->panelUrl($draft->id))->json('status'))->toBe('Links in: 0. Links out: 0. Cornerstone content.');

        $this->runAction($draft->id, 'cornerstone', ['mark' => 'no']);

        expect(DB::table('seo_items')->where('id', $draft->id)->value('cornerstone'))->toBeFalse();
    }

    #[TestDox('writes nothing when the person may not edit the item')]
    public function test_it_writes_nothing_when_the_person_may_not_edit_the_item(): void
    {
        $author = $this->person('author');
        $other = seoWrite($this->editor, 'An editor’s draft', [seoParagraph('Words.')], publish: false);

        $this->signIn($author);
        $this->runAction($other->id, 'cornerstone', ['mark' => 'yes'])->assertForbidden();

        expect(DB::table('seo_items')->where('id', $other->id)->value('cornerstone'))->toBeFalse();
    }

    /**
     * The address of one of Teme SEO's panels for this item.
     */
    private function panelUrl(string $itemId, string $panel = 'links'): string
    {
        return route('content.panels.show', ['content' => $itemId, 'code' => 'seo', 'panel' => $panel]);
    }

    /**
     * Runs one of a panel's actions on the item's working revision, as the signed-in person.
     *
     * @param  array<string, mixed>  $values
     *
     * @return TestResponse<Response>
     */
    private function runAction(string $itemId, string $action, array $values = [], string $panel = 'links'): TestResponse
    {
        $revision = DB::table('contents')->where('id', $itemId)->value('working_revision_id');

        return $this->postJson(route('content.panels.actions', ['content' => $itemId, 'code' => 'seo', 'panel' => $panel, 'action' => $action]), ['revision_id' => $revision, 'values' => $values]);
    }
}
