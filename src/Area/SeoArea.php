<?php

/**
 * Teme SEO's own pages: the audit, the links report, titles and
 * descriptions, and Search Console. Reads come from the plugin's own
 * tables; a save goes through Teme's content door as the person editing,
 * with the editor's own checks.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Area;

use Illuminate\Database\Query\Builder;
use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Admin\Chart;
use Teme\Contracts\Admin\Figure;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\Row;
use Teme\Contracts\Admin\RowPage;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Admin\TableQuery;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\QueueQuery;
use Teme\Contracts\Content\ReadRefused;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Seo\Checks\BuiltInChecks;
use Teme\Seo\Storage\ItemIndex;
use Teme\Seo\Storage\SearchFigures;
use Teme\Seo\TemeSeo;

final readonly class SeoArea implements PluginArea
{
    /** The most unpublished items a table looks through for one person. */
    private const int MOST_DRAFTS = 5000;

    public function __construct(
        private ItemIndex $index,
        private SearchFigures $search,
        private ContentReader $reader,
        private ContentWriter $writer,
        private SettingsReader $settings,
    ) {}

    /**
     * Returns the audit's totals, or Search Console's over the last 28 days
     * with their change from the 28 before.
     */
    public function figures(string $page, string $block, AreaContext $context): array
    {
        if ($block === 'search-totals') {
            return $this->searchTotals();
        }

        $published = $this->index->query()->whereRaw(BuiltInChecks::published('i'));
        $problems = (clone $published)->whereRaw('('.$this->anyProblem().')', [$this->thinWords()]);
        $orphaned = (clone $published)->whereRaw(BuiltInChecks::conditions()['orphaned']);

        return [
            'published' => Figure::number((clone $published)->count()),
            'problems' => Figure::number($problems->count()),
            'orphaned' => Figure::number($orphaned->count()),
            'cornerstone' => Figure::number((clone $published)->where('i.cornerstone', true)->count()),
        ];
    }

    /**
     * Returns Search Console's clicks and impressions by day, the last 28 days.
     */
    public function chart(string $page, string $block, AreaContext $context): Chart
    {
        $days = $this->search->days(28);

        return new Chart(array_column($days, 'day'), [
            'clicks' => array_column($days, 'clicks'),
            'impressions' => array_column($days, 'impressions'),
        ]);
    }

    /**
     * Returns one page of items, of the links report, or of Search Console's
     * top searches or pages.
     */
    public function rows(string $record, TableQuery $query, AreaContext $context): RowPage
    {
        return match ($record) {
            'search' => $this->searchRows($query),
            'link' => $this->linkRows($query),
            default => $this->itemRows($query, $context->personId),
        };
    }

    /**
     * Returns an item's title, search fields and cornerstone mark, read from
     * its draft for the person editing, or null when they may not edit it.
     */
    public function values(string $record, string $id, AreaContext $context): ?array
    {
        if ($record === 'search') {
            $kind = str_starts_with($id, 'page-') ? 'page' : 'query';
            $key = $this->search->key($kind, (int) substr($id, strpos($id, '-') + 1));

            return $key === null ? null : ['key' => $key];
        }

        try {
            $snapshot = $this->reader->draft($id, $context->personId);
        } catch (ReadRefused) {
            return null;
        }

        $values = ['title' => $snapshot->item->title, 'cornerstone' => $this->index->isCornerstone($id)];

        if ($record === 'item') {
            $values['seo_title'] = is_string($snapshot->fields['seo_title'] ?? null) ? $snapshot->fields['seo_title'] : '';
            $values['meta_description'] = is_string($snapshot->fields['meta_description'] ?? null) ? $snapshot->fields['meta_description'] : '';
        }

        return $values;
    }

    /**
     * Saves an item's title and search fields as a new revision of its
     * draft, through Teme's content door as the person editing, and its
     * cornerstone mark in Teme SEO's index.
     */
    public function save(string $record, ?string $id, array $values, AreaContext $context): Saved
    {
        if ($record === 'search' || $id === null) {
            throw new WriteRefused(__('Search Console’s figures can’t be changed here.'));
        }

        try {
            $snapshot = $this->reader->draft($id, $context->personId);
        } catch (ReadRefused $refused) {
            throw new WriteRefused($refused->getMessage());
        }

        $title = is_string($values['title'] ?? null) ? trim($values['title']) : $snapshot->item->title;
        $fields = [];

        foreach (['seo_title', 'meta_description'] as $handle) {
            if (! array_key_exists($handle, $values)) {
                continue;
            }

            $value = is_string($values[$handle]) ? trim($values[$handle]) : '';
            $stored = is_string($snapshot->fields[$handle] ?? null) ? $snapshot->fields[$handle] : '';

            if ($value !== $stored) {
                $fields[$handle] = $value === '' ? null : $value;
            }
        }

        $changed = false;

        if ($title !== $snapshot->item->title || $fields !== []) {
            $this->writer->change($snapshot->item, new ItemChanges(
                title: $title !== $snapshot->item->title ? $title : null,
                fields: $fields !== [] ? $fields : null,
            ), $context->personId);
            $changed = true;
        }

        if (array_key_exists('cornerstone', $values) && (bool) $values['cornerstone'] !== $this->index->isCornerstone($id)) {
            $this->index->remember($snapshot->item, stale: false);
            $this->index->markCornerstone($id, (bool) $values['cornerstone']);
            $changed = true;
        }

        return new Saved($id, $changed);
    }

    /**
     * Teme SEO's tables offer no row action but edit.
     */
    public function act(string $record, string $action, array $ids, AreaContext $context): Saved
    {
        throw new WriteRefused(__('Teme SEO’s pages have no such action.'));
    }

    /**
     * Search Console's totals over the last 28 days, each with its change.
     *
     * @return array<string, Figure>
     */
    private function searchTotals(): array
    {
        $days = $this->search->days();

        if ($days === []) {
            return [];
        }

        $current = self::totals(array_slice($days, -28));
        $before = self::totals(array_slice($days, 0, max(0, count($days) - 28)));
        $change = fn (float $now, float $then): ?float => $then > 0 ? round(($now - $then) / $then, 4) : null;

        return [
            'clicks' => Figure::number($current['clicks'], $change($current['clicks'], $before['clicks'])),
            'impressions' => Figure::number($current['impressions'], $change($current['impressions'], $before['impressions'])),
            'ctr' => Figure::percent($current['ctr'], $change($current['ctr'], $before['ctr'])),
            'position' => Figure::number(round($current['position'], 1), $change($current['position'], $before['position'])),
        ];
    }

    /**
     * The sums of these days, their click rate, and their position weighted by impressions.
     *
     * @param  list<array{day: string, clicks: int, impressions: int, position: float}>  $days
     *
     * @return array{clicks: int, impressions: int, ctr: float, position: float}
     */
    private static function totals(array $days): array
    {
        $clicks = array_sum(array_column($days, 'clicks'));
        $impressions = array_sum(array_column($days, 'impressions'));
        $weighted = array_sum(array_map(fn (array $day): float => $day['position'] * $day['impressions'], $days));

        return [
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0.0,
            'position' => $impressions > 0 ? $weighted / $impressions : 0.0,
        ];
    }

    /**
     * One page of items for the audit and for titles and descriptions: core
     * doesn't say which table asks, so each row carries both tables' cells
     * (plugins/seo/FINDINGS.md). Items with the most problems come first.
     */
    private function itemRows(TableQuery $query, string $personId): RowPage
    {
        $conditions = BuiltInChecks::conditions();
        $thin = $this->thinWords();
        $editable = $this->editableDrafts($personId);
        $builder = $this->index->query()
            ->where('i.trashed', false)
            // A draft shows only to a person who may edit it, as core's own queue shows it.
            ->where(fn (Builder $visible) => $visible->where('i.status', 'published')->orWhereIn('i.id', $editable));
        $this->search($builder, $query->search);
        $check = $query->filters['check'] ?? null;

        if ($check !== null || ($query->filters['cornerstone'] ?? null) === 'yes') {
            $builder->whereRaw(BuiltInChecks::published('i'));
        }

        if ($check === 'any') {
            $builder->whereRaw('('.$this->anyProblem().')', [$thin]);
        } elseif ($check !== null && isset($conditions[$check])) {
            $builder->whereRaw($conditions[$check], $check === 'thin' ? [$thin] : []);
        }

        if (($query->filters['cornerstone'] ?? null) === 'yes') {
            $builder->where('i.cornerstone', true);
        }

        match ($query->filters['missing'] ?? null) {
            'seo-title' => $builder->whereRaw("coalesce(i.seo_title, '') = ''"),
            'description' => $builder->whereRaw("coalesce(i.meta_description, '') = ''"),
            default => null,
        };

        match ($query->filters['status'] ?? null) {
            'published' => $builder->where('i.status', 'published'),
            'unpublished' => $builder->where('i.status', '<>', 'published'),
            default => null,
        };

        $total = (clone $builder)->count();
        $selects = ['i.*'];
        $bindings = [];

        foreach ($conditions as $id => $condition) {
            $selects[] = '('.BuiltInChecks::published('i').' and '.$condition.') as "fails_'.$id.'"';

            if ($id === 'thin') {
                $bindings[] = $thin;
            }
        }

        $builder->selectRaw(implode(', ', $selects), $bindings)
            ->orderByRaw('('.$this->problemCount().') '.($query->sort === 'count' && $query->direction === 'asc' ? 'asc' : 'desc'), [$thin])
            ->orderBy('i.title')
            ->orderBy('i.id');

        $labels = BuiltInChecks::labels();
        $rows = [];

        foreach ($builder->forPage($query->page, $query->perPage)->get() as $row) {
            $failing = [];

            foreach (array_keys($conditions) as $id) {
                if ((bool) $row->{'fails_'.$id}) {
                    $failing[] = $labels[$id];
                }
            }

            $rows[] = new Row((string) $row->id, [
                'title' => Cell::text((string) $row->title),
                'type' => Cell::text((string) $row->type),
                'status' => self::status((string) $row->status),
                'problems' => Cell::text($row->status !== 'published' ? __('Checked once published') : ($failing === [] ? __('None') : implode(', ', $failing))),
                'count' => Cell::number(count($failing)),
                'seo-title' => Cell::text((string) ($row->seo_title ?? '')),
                'description' => Cell::text((string) ($row->meta_description ?? '')),
            ]);
        }

        return new RowPage($rows, $total);
    }

    /**
     * The IDs of the unpublished items this person may edit, as core's
     * queue lists them, up to the most a table looks through.
     *
     * @return list<string>
     */
    private function editableDrafts(string $personId): array
    {
        $ids = [];
        $after = null;

        do {
            try {
                $page = $this->reader->queue(new QueueQuery(after: $after, limit: 200), $personId);
            } catch (ReadRefused) {
                return [];
            }

            foreach ($page->items as $item) {
                $ids[] = $item->item->id;
            }

            $after = $page->next;
        } while ($after !== null && count($ids) < self::MOST_DRAFTS);

        return $ids;
    }

    /**
     * One page of the links report: published items with how many link in
     * and out, the orphaned ones alone unless every item is asked for,
     * cornerstone items first.
     */
    private function linkRows(TableQuery $query): RowPage
    {
        $builder = $this->index->query()->whereRaw(BuiltInChecks::published('i'));
        $this->search($builder, $query->search);

        if (($query->filters['show'] ?? 'orphaned') === 'orphaned') {
            $builder->whereRaw(BuiltInChecks::conditions()['orphaned']);
        }

        if (($query->filters['cornerstone'] ?? null) === 'yes') {
            $builder->where('i.cornerstone', true);
        }

        $total = (clone $builder)->count();
        $direction = $query->direction === 'asc' ? 'asc' : 'desc';
        $builder->select('i.*')->orderByDesc('i.cornerstone');

        if ($query->sort === 'links-out') {
            $builder->orderBy('i.links_out', $direction);
        } elseif ($query->sort === 'links-in') {
            $builder->orderByRaw('(select count(*) from seo_links l join seo_items s on s.id = l.source_id where l.target_id = i.id and l.source_id <> i.id and '.BuiltInChecks::published('s').') '.$direction);
        }

        $page = $builder->orderBy('i.title')->orderBy('i.id')->forPage($query->page, $query->perPage)->get();
        $linksIn = $this->index->linksIn(array_map(fn (object $row): string => (string) $row->id, $page->all()));
        $rows = [];

        foreach ($page as $row) {
            $rows[] = new Row((string) $row->id, [
                'title' => Cell::text((string) $row->title),
                'type' => Cell::text((string) $row->type),
                'cornerstone' => (bool) $row->cornerstone ? Cell::badge(__('Cornerstone'), 'info') : Cell::text(''),
                'links-in' => Cell::number($linksIn[(string) $row->id] ?? 0),
                'links-out' => Cell::number((int) $row->links_out),
            ]);
        }

        return new RowPage($rows, $total);
    }

    /**
     * One page of Search Console's top searches or top pages.
     */
    private function searchRows(TableQuery $query): RowPage
    {
        $kind = ($query->filters['show'] ?? null) === 'pages' ? 'page' : 'query';
        $found = $this->search->rows($kind, $query->search, $query->sort, $query->direction, $query->page, $query->perPage);

        return new RowPage(array_map(fn (array $row): Row => new Row($kind.'-'.$row['id'], [
            'key' => Cell::text($row['key']),
            'clicks' => Cell::number($row['clicks']),
            'impressions' => Cell::number($row['impressions']),
            'position' => Cell::number($row['position']),
        ], ['edit']), $found['rows']), $found['total']);
    }

    /**
     * Narrows the query to items whose title, SEO title or description holds the words.
     */
    private function search(Builder $builder, string $search): void
    {
        $words = trim($search);

        if ($words === '') {
            return;
        }

        $like = '%'.addcslashes($words, '%_\\').'%';
        $builder->where(fn (Builder $query) => $query->where('i.title', 'ilike', $like)->orWhere('i.seo_title', 'ilike', $like)->orWhere('i.meta_description', 'ilike', $like));
    }

    /**
     * The SQL that is true when a published item fails any check; `?` is the fewest words.
     */
    private function anyProblem(): string
    {
        return implode(' or ', BuiltInChecks::conditions());
    }

    /**
     * The SQL that counts a published item's problems; `?` is the fewest words.
     */
    private function problemCount(): string
    {
        $sum = implode(' + ', array_map(fn (string $condition): string => "(case when {$condition} then 1 else 0 end)", BuiltInChecks::conditions()));

        return 'case when '.BuiltInChecks::published('i').' then '.$sum.' else -1 end';
    }

    /**
     * The fewest words a published item may have before the audit calls it thin.
     */
    private function thinWords(): int
    {
        return max(1, $this->settings->int(TemeSeo::SETTINGS, 'thin_words'));
    }

    /**
     * An item's status as a badge.
     */
    private static function status(string $status): Cell
    {
        return match ($status) {
            'published' => Cell::badge(__('Published'), 'ok'),
            'scheduled' => Cell::badge(__('Scheduled'), 'info'),
            'review' => Cell::badge(__('In review'), 'warn'),
            default => Cell::badge(__('Draft'), 'neutral'),
        };
    }
}
