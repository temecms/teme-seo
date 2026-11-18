<?php

declare(strict_types=1);

namespace Teme\Seo\Checks;

/**
 * The audit's own checks, each a plain fact about a published item that
 * Teme SEO can tell from its index: its words, as people read them, and
 * the SQL that finds the items failing it in the plugin's own tables.
 */
final class BuiltInChecks
{
    /** The longest title search results show in full, in characters. */
    public const int LONGEST_TITLE = 60;

    /** The longest description search results show in full, in characters. */
    public const int LONGEST_DESCRIPTION = 160;

    /**
     * Each check's label, in the order the audit lists them.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'no-description' => __('No description'),
            'long-title' => __('Title too long'),
            'long-description' => __('Description too long'),
            'no-featured-image' => __('No featured image'),
            'missing-alt' => __('Images without alt text'),
            'no-internal-links' => __('No internal links'),
            'broken-links' => __('Links readers can’t reach'),
            'orphaned' => __('Orphaned'),
            'thin' => __('Thin content'),
            'hidden' => __('Hidden from search engines'),
        ];
    }

    /**
     * Each check as an SQL condition on `seo_items` aliased `i`, true when
     * the item fails it. `?` in the thin check is the fewest words.
     *
     * @return array<string, string>
     */
    public static function conditions(): array
    {
        return [
            'no-description' => "(coalesce(i.meta_description, '') = '' and not i.has_excerpt)",
            'long-title' => "char_length(coalesce(nullif(i.seo_title, ''), i.title)) > ".self::LONGEST_TITLE,
            'long-description' => "char_length(coalesce(i.meta_description, '')) > ".self::LONGEST_DESCRIPTION,
            // A plugin can't tell which types have a featured image, so only posts are asked (plugins/seo/FINDINGS.md).
            'no-featured-image' => "(not i.has_featured_image and i.type = 'post')",
            'missing-alt' => 'i.images_missing_alt > 0',
            'no-internal-links' => 'i.links_out = 0',
            'broken-links' => 'exists (select 1 from seo_links l left join seo_items t on t.id = l.target_id where l.source_id = i.id and (t.id is null or not ('.self::published('t').')))',
            'orphaned' => 'i.indexable and not exists (select 1 from seo_links l join seo_items s on s.id = l.source_id where l.target_id = i.id and l.source_id <> i.id and '.self::published('s').')',
            'thin' => 'i.words < ?',
            'hidden' => 'not i.indexable',
        ];
    }

    /**
     * The SQL that says an item in the index is published and on the site.
     */
    public static function published(string $alias): string
    {
        return "({$alias}.status = 'published' and not {$alias}.trashed)";
    }
}
