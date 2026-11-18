<?php

declare(strict_types=1);

namespace Teme\Seo\Storage;

use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Content\ItemRef;
use Teme\Seo\Checks\BuiltInChecks;

/**
 * Teme SEO's index of the site's items and the links between them, in its
 * own tables `seo_items` and `seo_links`. The only class that reads or
 * writes them, so a Laravel upgrade touches one place.
 */
final class ItemIndex
{
    /**
     * Records what a hook or a page of items says of an item, keeping what
     * was read from its body, and marks it to be read again when asked.
     */
    public function remember(ItemRef $item, bool $trashed = false, bool $stale = true): void
    {
        $now = new DateTimeImmutable;
        $values = [
            'type' => $item->type,
            'language' => $item->language,
            'title' => $item->title,
            'status' => $item->status,
            'indexable' => $item->indexable && $item->status === 'published',
            'trashed' => $trashed,
            'updated_at' => $now,
        ];

        if ($stale) {
            $values['stale'] = true;
        }

        DB::table('seo_items')->upsert([['id' => $item->id, ...$values]], ['id'], array_keys($values));
    }

    /**
     * Records what was read from an item's working revision, and the items
     * its body links to, replacing what was there.
     *
     * @param  array{seo_title: string, meta_description: string, has_excerpt: bool, has_featured_image: bool, words: int, images_missing_alt: int}  $read
     * @param  array<string, int>  $links  occurrences by target ID
     */
    public function record(ItemRef $item, array $read, array $links): void
    {
        DB::transaction(function () use ($item, $read, $links): void {
            $this->remember($item, stale: false);

            DB::table('seo_items')->where('id', $item->id)->update([
                ...$read,
                'links_out' => count(array_diff_key($links, [$item->id => true])),
                'stale' => false,
                'indexed_at' => new DateTimeImmutable,
            ]);

            DB::table('seo_links')->where('source_id', $item->id)->delete();

            $rows = [];

            foreach ($links as $target => $occurrences) {
                $rows[] = ['source_id' => $item->id, 'target_id' => $target, 'occurrences' => min($occurrences, 65_535)];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('seo_links')->insert($chunk);
            }
        });
    }

    /**
     * Forgets an item deleted for good, and the links it made.
     */
    public function forget(string $itemId): void
    {
        DB::transaction(function () use ($itemId): void {
            DB::table('seo_links')->where('source_id', $itemId)->delete();
            DB::table('seo_items')->where('id', $itemId)->delete();
        });
    }

    /**
     * Marks or unmarks an item as cornerstone content. Returns false when
     * the index doesn't know the item.
     */
    public function markCornerstone(string $itemId, bool $cornerstone): bool
    {
        return DB::table('seo_items')->where('id', $itemId)->update(['cornerstone' => $cornerstone]) > 0;
    }

    /**
     * Returns whether the item is marked as cornerstone content.
     */
    public function isCornerstone(string $itemId): bool
    {
        return (bool) DB::table('seo_items')->where('id', $itemId)->value('cornerstone');
    }

    /**
     * Returns whether the index knows the item and has read its body.
     */
    public function isRead(string $itemId): bool
    {
        return DB::table('seo_items')->where('id', $itemId)->where('stale', false)->exists();
    }

    /**
     * Returns whether the index holds any published item yet.
     */
    public function hasPublished(): bool
    {
        return DB::table('seo_items')->where('status', 'published')->exists();
    }

    /**
     * Returns the IDs of every item the index holds as published.
     *
     * @return list<string>
     */
    public function publishedIds(): array
    {
        return array_map(strval(...), DB::table('seo_items')->where('status', 'published')->orderBy('id')->pluck('id')->all());
    }

    /**
     * Returns up to $limit IDs of items still to be read, oldest change first.
     *
     * @return list<string>
     */
    public function staleIds(int $limit): array
    {
        return array_map(strval(...), DB::table('seo_items')->where('stale', true)->where('trashed', false)->orderBy('updated_at')->limit($limit)->pluck('id')->all());
    }

    /**
     * Returns how many published items link to each of these items, the item
     * itself left out, by ID; an item no published item links to is 0.
     *
     * @param  list<string>  $itemIds
     *
     * @return array<string, int>
     */
    public function linksIn(array $itemIds): array
    {
        $counts = array_fill_keys($itemIds, 0);
        $rows = DB::table('seo_links as l')
            ->join('seo_items as s', 's.id', '=', 'l.source_id')
            ->whereIn('l.target_id', $itemIds)
            ->whereColumn('l.source_id', '<>', 'l.target_id')
            ->whereRaw(BuiltInChecks::published('s'))
            ->groupBy('l.target_id')
            ->selectRaw('l.target_id, count(*) as links')
            ->get();

        foreach ($rows as $row) {
            $counts[(string) $row->target_id] = (int) $row->links;
        }

        return $counts;
    }

    /**
     * Returns how many other items each of these items links to, by ID; an
     * item the index hasn't read is left out.
     *
     * @param  list<string>  $itemIds
     *
     * @return array<string, int>
     */
    public function linksOut(array $itemIds): array
    {
        $counts = [];

        foreach (DB::table('seo_items')->whereIn('id', $itemIds)->where('stale', false)->get(['id', 'links_out']) as $row) {
            $counts[(string) $row->id] = (int) $row->links_out;
        }

        return $counts;
    }

    /**
     * Returns the IDs of the items this item links to.
     *
     * @return list<string>
     */
    public function linkedFrom(string $itemId): array
    {
        return array_map(strval(...), DB::table('seo_links')->where('source_id', $itemId)->pluck('target_id')->all());
    }

    /**
     * Returns the published items search engines may list that a link may
     * lead to, the item itself left out, cornerstone items first: each its
     * ID, title, and whether it is cornerstone content.
     *
     * @return list<array{id: string, title: string, cornerstone: bool, linksIn: int}>
     */
    public function linkTargets(string $exceptId, string $language, int $limit = 5000): array
    {
        $rows = DB::table('seo_items as i')
            ->whereRaw(BuiltInChecks::published('i'))
            ->where('i.indexable', true)
            ->where('i.id', '<>', $exceptId)
            ->when($language !== '', fn (Builder $query) => $query->where('i.language', $language))
            ->orderByDesc('i.cornerstone')
            ->orderBy('i.id')
            ->limit($limit)
            ->get(['i.id', 'i.title', 'i.cornerstone']);
        $linksIn = $this->linksIn(array_map(fn (object $row): string => (string) $row->id, $rows->all()));

        return array_map(fn (object $row): array => [
            'id' => (string) $row->id,
            'title' => (string) $row->title,
            'cornerstone' => (bool) $row->cornerstone,
            'linksIn' => $linksIn[(string) $row->id] ?? 0,
        ], $rows->all());
    }

    /**
     * Returns the item's row, or null when the index doesn't know it.
     */
    public function find(string $itemId): ?object
    {
        return DB::table('seo_items')->where('id', $itemId)->first();
    }

    /**
     * Returns the IDs of these items that are published and on the site.
     *
     * @param  list<string>  $itemIds
     *
     * @return list<string>
     */
    public function published(array $itemIds): array
    {
        return array_map(strval(...), DB::table('seo_items as i')->whereIn('i.id', $itemIds)->whereRaw(BuiltInChecks::published('i'))->pluck('i.id')->all());
    }

    /**
     * A query over the index, aliased `i`, for the plugin's pages.
     */
    public function query(): Builder
    {
        return DB::table('seo_items as i');
    }
}
