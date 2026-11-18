<?php

declare(strict_types=1);

namespace Teme\Seo\Storage;

use Illuminate\Support\Facades\DB;

/**
 * Search Console's figures as Teme SEO last fetched them, in its own tables
 * `seo_search_days` and `seo_search_rows`: totals by day for a short
 * history, and the top searches and pages of the last 28 days.
 */
final class SearchFigures
{
    /** How many days of totals are kept: two periods, so each figure shows its change. */
    public const int DAYS_KEPT = 56;

    /**
     * Replaces the totals by day with these, and drops older days.
     *
     * @param  list<array{day: string, clicks: int, impressions: int, position: float}>  $days
     */
    public function replaceDays(array $days): void
    {
        DB::transaction(function () use ($days): void {
            DB::table('seo_search_days')->delete();

            foreach (array_chunk($days, 200) as $chunk) {
                DB::table('seo_search_days')->insert($chunk);
            }
        });
    }

    /**
     * Replaces the top searches or pages with these.
     *
     * @param  'query'|'page'  $kind
     * @param  list<array{key: string, clicks: int, impressions: int, position: float}>  $rows
     */
    public function replaceRows(string $kind, array $rows): void
    {
        DB::transaction(function () use ($kind, $rows): void {
            DB::table('seo_search_rows')->where('kind', $kind)->delete();

            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('seo_search_rows')->insert(array_map(fn (array $row): array => ['kind' => $kind, ...$row], $chunk));
            }
        });
    }

    /**
     * Returns the totals by day, oldest first: the last $count days held.
     *
     * @return list<array{day: string, clicks: int, impressions: int, position: float}>
     */
    public function days(int $count = self::DAYS_KEPT): array
    {
        $rows = DB::table('seo_search_days')->orderByDesc('day')->limit($count)->get();

        return array_reverse(array_map(fn (object $row): array => [
            'day' => substr((string) $row->day, 0, 10),
            'clicks' => (int) $row->clicks,
            'impressions' => (int) $row->impressions,
            'position' => (float) $row->position,
        ], $rows->all()));
    }

    /**
     * Returns one page of the top searches or pages, matching the search,
     * sorted as asked, with how many match in all.
     *
     * @param  'query'|'page'  $kind
     *
     * @return array{rows: list<array{id: int, key: string, clicks: int, impressions: int, position: float}>, total: int}
     */
    public function rows(string $kind, string $search, ?string $sort, string $direction, int $page, int $perPage): array
    {
        $query = DB::table('seo_search_rows')->where('kind', $kind);

        if (trim($search) !== '') {
            $query->where('key', 'ilike', '%'.addcslashes(trim($search), '%_\\').'%');
        }

        $total = $query->count();
        $column = in_array($sort, ['clicks', 'impressions', 'position'], true) ? $sort : 'clicks';
        $rows = $query->orderBy($column, $direction === 'asc' ? 'asc' : 'desc')->orderBy('id')->forPage($page, $perPage)->get();

        return [
            'rows' => array_map(fn (object $row): array => [
                'id' => (int) $row->id,
                'key' => (string) $row->key,
                'clicks' => (int) $row->clicks,
                'impressions' => (int) $row->impressions,
                'position' => (float) $row->position,
            ], $rows->all()),
            'total' => $total,
        ];
    }

    /**
     * Returns one row's search or page, or null when there is no such row.
     *
     * @param  'query'|'page'  $kind
     */
    public function key(string $kind, int $id): ?string
    {
        $key = DB::table('seo_search_rows')->where('kind', $kind)->where('id', $id)->value('key');

        return is_string($key) ? $key : null;
    }
}
