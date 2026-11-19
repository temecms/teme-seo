<?php

/**
 * The suggestions Teme SEO's editor panels offer, in its own table
 * `seo_suggestions`: what a job found for an item, until the person uses or
 * discards each, and what they did, so a discarded link isn't offered again.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Storage;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Teme\Contracts\Editor\SuggestedChange;
use Teme\Contracts\Editor\Suggestion;
use Throwable;

final class SuggestionStore
{
    /**
     * Replaces the waiting suggestions of one panel for an item whose key
     * starts with $prefix, keeping what the person already used or discarded.
     *
     * @param  list<Suggestion>  $suggestions
     */
    public function replace(string $itemId, string $panel, string $prefix, array $suggestions): void
    {
        DB::transaction(function () use ($itemId, $panel, $prefix, $suggestions): void {
            DB::table('seo_suggestions')
                ->where('item_id', $itemId)
                ->where('panel', $panel)
                ->whereNull('settled')
                ->where('key', 'like', addcslashes($prefix, '%_\\').'%')
                ->delete();

            $settled = $this->settledKeys($itemId, $panel);
            $now = new DateTimeImmutable;

            foreach ($suggestions as $suggestion) {
                if (isset($settled[$suggestion->id])) {
                    continue;
                }

                DB::table('seo_suggestions')->insert([
                    'item_id' => $itemId,
                    'panel' => $panel,
                    'key' => $suggestion->id,
                    'label' => $suggestion->label,
                    'detail' => $suggestion->detail,
                    'change' => json_encode(['kind' => $suggestion->change->kind, 'values' => $suggestion->change->values], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ]);
            }
        });
    }

    /**
     * Returns the suggestions still waiting for the person, oldest first, at
     * most twenty; one that can no longer be made is left out.
     *
     * @return list<Suggestion>
     */
    public function waiting(string $itemId, string $panel): array
    {
        $suggestions = [];

        foreach (DB::table('seo_suggestions')->where('item_id', $itemId)->where('panel', $panel)->whereNull('settled')->orderBy('id')->limit(20)->get() as $row) {
            $suggestion = self::suggestion($row);

            if ($suggestion !== null) {
                $suggestions[] = $suggestion;
            }
        }

        return $suggestions;
    }

    /**
     * Records that the person used or discarded a suggestion.
     */
    public function settle(string $itemId, string $panel, string $key, bool $used): void
    {
        DB::table('seo_suggestions')
            ->where('item_id', $itemId)
            ->where('panel', $panel)
            ->where('key', $key)
            ->update(['settled' => $used ? 'used' : 'discarded']);
    }

    /**
     * Returns the keys of the suggestions the person already used or discarded, as keys.
     *
     * @return array<string, true>
     */
    public function settledKeys(string $itemId, string $panel): array
    {
        $keys = DB::table('seo_suggestions')->where('item_id', $itemId)->where('panel', $panel)->whereNotNull('settled')->pluck('key')->all();

        return array_fill_keys(array_map(strval(...), $keys), true);
    }

    /**
     * Forgets every suggestion for an item deleted for good.
     */
    public function forget(string $itemId): void
    {
        DB::table('seo_suggestions')->where('item_id', $itemId)->delete();
    }

    /**
     * A stored row as a suggestion, or null when it no longer passes the contract's checks.
     */
    private static function suggestion(object $row): ?Suggestion
    {
        $change = json_decode((string) $row->change, true);

        if (! is_array($change) || ! is_array($change['values'] ?? null)) {
            return null;
        }

        $values = $change['values'];

        try {
            $made = match ($change['kind'] ?? null) {
                SuggestedChange::LINK => SuggestedChange::link((string) $values['phrase'], (string) $values['itemId'], (int) $values['occurrence']),
                SuggestedChange::BODY => SuggestedChange::body(is_array($values['document'] ?? null) ? $values['document'] : []),
                SuggestedChange::TITLE => SuggestedChange::title((string) $values['title']),
                default => null,
            };

            return $made === null ? null : new Suggestion((string) $row->key, (string) $row->label, (string) $row->detail, $made);
        } catch (Throwable) {
            return null;
        }
    }
}
