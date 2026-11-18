<?php

declare(strict_types=1);

namespace Teme\Seo\Panels;

use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\EditorPanelContext;
use Teme\Contracts\Editor\PanelView;
use Teme\Seo\Storage\SuggestionStore;

/**
 * The Keyphrase rewrite panel: the rewrites the site's AI made of the item
 * around a keyphrase, each a proposal the person sees word by word before
 * using it.
 */
final readonly class KeyphrasePanel implements EditorPanel
{
    public const string ID = 'keyphrase';

    public function __construct(
        private SuggestionStore $suggestions,
    ) {}

    /**
     * Returns the panel's status line and the rewrites still waiting.
     */
    public function view(EditorPanelContext $context): PanelView
    {
        $waiting = $this->suggestions->waiting($context->itemId, self::ID);

        if ($waiting === []) {
            return new PanelView(__('Give a keyphrase and choose what to rewrite. Nothing changes until you use a rewrite.'));
        }

        return new PanelView(__('Open a rewrite to see each change before you use it.'), 'info', $waiting);
    }

    /**
     * Records that the person used or discarded a rewrite.
     */
    public function settled(string $suggestionId, bool $used, EditorPanelContext $context): void
    {
        $this->suggestions->settle($context->itemId, self::ID, $suggestionId, $used);
    }
}
