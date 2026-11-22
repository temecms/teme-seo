<?php

declare(strict_types=1);

namespace Teme\Seo\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use SeoFakeAi;
use SeoFakeJob;
use SeoFakeReader;
use Teme\Contracts\Editor\EditorPanelContext;
use Teme\Contracts\Editor\SuggestedChange;
use Teme\Seo\Ai\AskAiForLinks;
use Teme\Seo\Ai\RewriteForKeyphrase;
use Teme\Seo\Links\LinkSuggester;
use Teme\Seo\Panels\KeyphrasePanel;
use Teme\Seo\Panels\LinksPanel;
use Teme\Seo\Storage\Indexer;
use Teme\Seo\Storage\ItemIndex;
use Teme\Seo\Storage\SuggestionStore;
use Teme\Seo\Tests\Support\SeoTestCase;

require_once __DIR__.'/Support/SeoTestCase.php';

/**
 * Teme SEO's AI help: link ideas checked against the draft, and rewrites aimed at a keyphrase.
 */
final class AiTest extends SeoTestCase
{
    private string $draftId = '01kbbbbbbbbbbbbbbbbbbbbbbb';

    private EditorPanelContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new EditorPanelContext($this->draftId, '01kaaaaaaaaaaaaaaaaaaaaaaa', 'post', 'en', '1', 'en');
    }

    #[TestDox('offers the AI’s links only where the phrase is in the draft and the item is a published one it was shown')]
    public function test_it_offers_the_ais_links_only_where_the_phrase_is_in_the_draft_and_the_item_is_a_published_one(): void
    {
        $roses = seoWrite($this->editor, 'Feeding roses in spring');
        $tulips = seoWrite($this->editor, 'Planting tulips in autumn');
        $snapshot = seoSnapshot($this->draftId, 'My garden', [seoParagraph('I feed my roses in spring and plant tulips when the leaves fall.')]);
        $ai = new SeoFakeAi(['links' => [
            ['phrase' => 'my roses in spring', 'id' => $roses->id, 'why' => 'Readers will want the feeding plan.'],
            ['phrase' => 'plant tulips', 'id' => '01kzzzzzzzzzzzzzzzzzzzzzzz', 'why' => 'An item that isn’t there.'],
            ['phrase' => 'bulbs in pots', 'id' => $tulips->id, 'why' => 'A phrase that isn’t there.'],
        ]]);

        $handler = new AskAiForLinks($ai, new Indexer(new SeoFakeReader($snapshot), new ItemIndex), new ItemIndex, new SuggestionStore, new LinkSuggester);
        $handler->handle(new SeoFakeJob(['itemId' => $this->draftId]));

        $view = (new LinksPanel(new ItemIndex, new SuggestionStore))->view($this->context);

        expect($ai->asked[0]->task)->toBe('link-ideas')
            ->and($ai->asked[0]->inputs['items'])->toContain($roses->id.' | Feeding roses in spring')
            ->and($view->suggestions)->toHaveCount(1)
            ->and($view->suggestions[0]->change->values)->toBe(['phrase' => 'my roses in spring', 'itemId' => $roses->id, 'occurrence' => 1])
            ->and($view->suggestions[0]->detail)->toBe('To “Feeding roses in spring” Readers will want the feeding plan.');
    }

    #[TestDox('offers a rewrite of the introduction as a whole document, leaving every other block as it was')]
    public function test_it_offers_a_rewrite_of_the_introduction_as_a_whole_document_leaving_every_other_block_as_it_w(): void
    {
        $roses = seoWrite($this->editor, 'Feeding roses in spring');
        $blocks = [
            seoParagraph('Gardens need care.'),
            seoHeading('When to start'),
            seoParagraph('Start ', ['feeding roses', $roses], ' in March.'),
        ];
        $ai = new SeoFakeAi(['blocks' => [['index' => 0, 'text' => 'Rose care starts with feeding.'], ['index' => 2, 'text' => 'This block holds a link and was never sent.']]]);

        (new RewriteForKeyphrase($ai, new Indexer(new SeoFakeReader(seoSnapshot($this->draftId, 'Roses', $blocks)), new ItemIndex), new SuggestionStore))
            ->handle(new SeoFakeJob(['itemId' => $this->draftId, 'values' => ['keyphrase' => 'rose care', 'part' => 'intro']]));

        $view = (new KeyphrasePanel(new SuggestionStore))->view($this->context);
        $document = $view->suggestions[0]->change->values['document'];

        expect($ai->asked[0]->inputs['blocks'])->toBe('0: Gardens need care.')
            ->and($view->suggestions[0]->change->kind)->toBe(SuggestedChange::BODY)
            ->and($view->suggestions[0]->label)->toBe('The introduction, for “rose care”')
            ->and($document['content'][0]['content'])->toEqual([['type' => 'text', 'text' => 'Rose care starts with feeding.']])
            ->and(array_slice($document['content'], 1))->toEqual(array_slice($blocks, 1));
    }

    #[TestDox('sends no paragraph that holds a link to be rewritten')]
    public function test_it_sends_no_paragraph_that_holds_a_link_to_be_rewritten(): void
    {
        $roses = seoWrite($this->editor, 'Feeding roses in spring');
        $document = ['type' => 'doc', 'content' => [seoParagraph('Plain words.'), seoParagraph('Linked ', ['words', $roses]), seoHeading('A heading')]];

        expect(RewriteForKeyphrase::blocks($document, 'spread'))->toBe([0 => 'Plain words.'])
            ->and(RewriteForKeyphrase::blocks($document, 'headings'))->toBe([2 => 'A heading'])
            ->and(RewriteForKeyphrase::blocks(['type' => 'doc', 'content' => [seoParagraph('Linked ', ['words', $roses])]], 'intro'))->toBe([]);
    }

    #[TestDox('keeps nothing when the AI changes nothing')]
    public function test_it_keeps_nothing_when_the_ai_changes_nothing(): void
    {
        $ai = new SeoFakeAi(['blocks' => []]);

        (new RewriteForKeyphrase($ai, new Indexer(new SeoFakeReader(seoSnapshot($this->draftId, 'Roses', [seoParagraph('Gardens need care.')])), new ItemIndex), new SuggestionStore))
            ->handle($job = new SeoFakeJob(['itemId' => $this->draftId, 'values' => ['keyphrase' => 'rose care', 'part' => 'intro']]));

        expect(DB::table('seo_suggestions')->count())->toBe(0)
            ->and(end($job->messages))->toBe('The AI suggested no change.');
    }

    #[TestDox('stops offering a keyphrase rewrite once the draft has moved on from the revision it was made from')]
    public function test_it_stops_offering_a_keyphrase_rewrite_once_the_draft_has_moved_on_from_the_revision_it_was_made_from(): void
    {
        $ai = new SeoFakeAi(['blocks' => [['index' => 0, 'text' => 'Rose care starts with feeding.']]]);
        $madeFrom = '01kccccccccccccccccccccccc';
        $snapshot = seoSnapshot($this->draftId, 'Roses', [seoParagraph('Gardens need care.')], $madeFrom);

        (new RewriteForKeyphrase($ai, new Indexer(new SeoFakeReader($snapshot), new ItemIndex), new SuggestionStore))
            ->handle(new SeoFakeJob(['itemId' => $this->draftId, 'values' => ['keyphrase' => 'rose care', 'part' => 'intro']]));

        $stillOn = new EditorPanelContext($this->draftId, $madeFrom, 'post', 'en', '1', 'en');
        $movedOn = new EditorPanelContext($this->draftId, '01kddddddddddddddddddddddd', 'post', 'en', '1', 'en');

        expect((new KeyphrasePanel(new SuggestionStore))->view($stillOn)->suggestions)->toHaveCount(1)
            ->and((new KeyphrasePanel(new SuggestionStore))->view($movedOn)->suggestions)->toHaveCount(0)
            // Drawing the panel writes nothing: the old rewrite is only left out.
            ->and(DB::table('seo_suggestions')->where('item_id', $this->draftId)->whereNull('settled')->count())->toBe(1);
    }
}
