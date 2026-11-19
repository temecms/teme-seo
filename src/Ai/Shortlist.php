<?php

/**
 * Picks the published items most worth showing the AI for one article: those
 * whose titles share the most words with it, cornerstone items first among
 * equals, so a large site's list fits in one request.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Ai;

final class Shortlist
{
    /**
     * Up to $most targets, best first; none that shares no word.
     *
     * @param  list<array{id: string, title: string, cornerstone: bool, linksIn: int}>  $targets
     *
     * @return list<array{id: string, title: string, cornerstone: bool, linksIn: int}>
     */
    public static function of(string $text, array $targets, int $most): array
    {
        $words = array_fill_keys(self::words($text), true);
        $scored = [];

        foreach ($targets as $target) {
            $shared = count(array_filter(self::words($target['title']), fn (string $word): bool => isset($words[$word])));

            if ($shared > 0) {
                $scored[] = ['score' => $shared * 2 + ($target['cornerstone'] ? 3 : 0), 'target' => $target];
            }
        }

        usort($scored, fn (array $one, array $other): int => [$other['score'], $one['target']['id']] <=> [$one['score'], $other['target']['id']]);

        return array_map(fn (array $one): array => $one['target'], array_slice($scored, 0, $most));
    }

    /**
     * The distinct words of four letters or more, in lower case.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $word): bool => mb_strlen($word) >= 4)));
    }
}
