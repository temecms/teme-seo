<?php

/**
 * Reads an item's working revision for the person the work acts for, and
 * keeps what Teme SEO needs of it in the index: its search fields, its
 * words and its links.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Storage;

use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\ItemSnapshot;
use Teme\Contracts\Content\ReadRefused;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Seo\Document\Article;
use Teme\Seo\TemeSeo;

final readonly class Indexer
{
    public function __construct(
        private ContentReader $content,
        private ItemIndex $index,
        private ?SettingsReader $settings = null,
    ) {}

    /**
     * Records what a hook tells of an item, then reads its body when a
     * person is behind the change. Without one, or when that person may not
     * read it, the item waits for the next pass of the index.
     */
    public function heard(ItemRef $item, ?string $personId, bool $trashed = false): void
    {
        $this->index->remember($item, $trashed);

        if ($personId === null || $trashed) {
            return;
        }

        try {
            $this->read($item->id, $personId);
        } catch (ReadRefused) {
            // The next pass of the index reads it, as the administrator who enabled Teme SEO.
        }
    }

    /**
     * Reads one item's working revision as this person and records it.
     * Returns the snapshot read.
     *
     * @throws ReadRefused when the person may not read it
     */
    public function read(string $itemId, string $personId): ItemSnapshot
    {
        $snapshot = $this->content->draft($itemId, $personId);
        $article = Article::read($snapshot->body);
        $fields = $snapshot->fields;
        $seoTitle = self::text($fields['seo_title'] ?? null);
        $description = self::text($fields['meta_description'] ?? null);

        $thin = $this->settings === null ? 300 : max(1, $this->settings->int(TemeSeo::SETTINGS, 'thin_words'));
        $this->index->record($snapshot->item, [
            'seo_title' => $seoTitle,
            'meta_description' => $description,
            'has_excerpt' => self::text($fields['excerpt'] ?? null) !== '',
            'has_featured_image' => ! in_array($fields['featured_image'] ?? null, [null, '', []], true),
            'words' => $article->words,
            'images_missing_alt' => $article->imagesMissingAlt,
        ], $article->links, $thin);

        return $snapshot;
    }

    /**
     * A field's value as trimmed text; anything else is empty.
     */
    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
