<?php

declare(strict_types=1);

namespace Teme\Seo\Panels;

use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\EditorPanelContext;
use Teme\Contracts\Editor\PanelView;
use Teme\Contracts\Editor\SuggestedChange;
use Teme\Contracts\Editor\Suggestion;
use Teme\Seo\Storage\ItemIndex;
use Teme\Seo\Storage\SuggestionStore;

/**
 * The Internal links panel: how many items link to the one being edited
 * and how many it links to, whether it is cornerstone content, and the
 * links Teme SEO and the site's AI suggest, read from what the jobs found.
 */
final readonly class LinksPanel implements EditorPanel
{
    public const string ID = 'links';

    public function __construct(
        private ItemIndex $index,
        private SuggestionStore $suggestions,
    ) {}

    /**
     * Returns the panel's status line and the links still waiting.
     */
    public function view(EditorPanelContext $context): PanelView
    {
        $waiting = $this->suggestions->waiting($context->itemId, self::ID);
        $targets = [];

        foreach ($waiting as $suggestion) {
            if ($suggestion->change->kind === SuggestedChange::LINK) {
                $targets[] = (string) $suggestion->change->values['itemId'];
            }
        }

        $live = array_fill_keys($this->index->published($targets), true);
        $shown = array_values(array_filter(
            $waiting,
            fn (Suggestion $suggestion): bool => $suggestion->change->kind !== SuggestedChange::LINK || isset($live[(string) $suggestion->change->values['itemId']]),
        ));

        $row = $this->index->find($context->itemId);

        if ($row === null || (bool) $row->stale) {
            return new PanelView(__('Teme SEO hasn’t read this item yet. Save it, or press Find links.'), 'neutral', $shown);
        }

        $status = __('Links in: :in. Links out: :out.', [
            'in' => $this->index->linksIn([$context->itemId])[$context->itemId] ?? 0,
            'out' => (int) $row->links_out,
        ]);

        if ((bool) $row->cornerstone) {
            $status .= ' '.__('Cornerstone content.');
        }

        return new PanelView($status, $shown === [] ? 'neutral' : 'info', $shown);
    }

    /**
     * Records that the person used or discarded a suggestion, so a discarded
     * link isn't offered again.
     */
    public function settled(string $suggestionId, bool $used, EditorPanelContext $context): void
    {
        $this->suggestions->settle($context->itemId, self::ID, $suggestionId, $used);
    }
}
