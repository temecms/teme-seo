<?php

/**
 * Finds phrases in an article that name another published item, from that
 * item's title, and says where a link would go: the phrase as written and
 * which of its matches, counted as the editor counts them. Cornerstone
 * items come first, then longer phrases. English stop words for now.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo\Links;

use Teme\Seo\Document\Article;
use Teme\Seo\Document\TextRun;

final class LinkSuggester
{
    /** The longest phrase it tries, in words. */
    private const int LONGEST = 8;

    /** Words that never start or end a linked phrase. */
    private const array STOP_WORDS = [
        'a', 'about', 'after', 'all', 'an', 'and', 'are', 'as', 'at', 'be', 'before', 'but', 'by', 'can', 'do', 'for', 'from',
        'get', 'has', 'have', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'my', 'no', 'not', 'of', 'on', 'or', 'our',
        'so', 'than', 'that', 'the', 'their', 'then', 'there', 'these', 'this', 'to', 'up', 'was', 'we', 'what', 'when',
        'where', 'which', 'who', 'why', 'will', 'with', 'you', 'your',
    ];

    /**
     * Up to $most links worth making, best first, each to a different item
     * and on a different phrase.
     *
     * @param  list<array{id: string, title: string, cornerstone: bool, linksIn: int}>  $targets
     * @param  list<string>  $skip  IDs never to suggest: those already linked, and those the person discarded
     *
     * @return list<array{target: string, title: string, phrase: string, occurrence: int, cornerstone: bool}>
     */
    public function suggest(Article $article, array $targets, array $skip, int $most = 10): array
    {
        $runs = array_map(fn (TextRun $run): array => ['lower' => mb_strtolower($run->text), 'text' => $run->text, 'linkable' => $run->linkable], $article->runs);
        $skipped = array_fill_keys($skip, true);
        $candidates = [];

        foreach ($targets as $target) {
            if (isset($skipped[$target['id']])) {
                continue;
            }

            foreach (self::phrases($target['title']) as $phrase) {
                if ($this->find($runs, $phrase, []) !== null) {
                    $words = substr_count($phrase, ' ') + 1;
                    $candidates[] = [
                        'target' => $target,
                        'phrase' => $phrase,
                        'score' => $words * 10 + ($target['cornerstone'] ? 30 : 0) + ($target['linksIn'] === 0 ? 5 : 0),
                    ];

                    break;
                }
            }
        }

        usort($candidates, fn (array $one, array $other): int => [$other['score'], $one['target']['id']] <=> [$one['score'], $other['target']['id']]);

        $used = [];
        $chosen = [];

        foreach ($candidates as $candidate) {
            if (count($chosen) >= $most) {
                break;
            }

            if (self::sharesWords($candidate['phrase'], array_column($chosen, 'lower'))) {
                continue;
            }

            $match = $this->find($runs, $candidate['phrase'], $used);

            if ($match === null) {
                continue;
            }

            $used[] = [$match['run'], $match['start'], $match['end']];
            $chosen[] = [
                'target' => $candidate['target']['id'],
                'title' => $candidate['target']['title'],
                'phrase' => $match['written'],
                'occurrence' => $match['occurrence'],
                'cornerstone' => $candidate['target']['cornerstone'],
                'lower' => $candidate['phrase'],
            ];
        }

        return array_map(fn (array $one): array => array_diff_key($one, ['lower' => true]), $chosen);
    }

    /**
     * Where a link on this phrase would go: the words as written and which
     * of the phrase's matches it is, as the editor counts them; null when
     * the phrase isn't in the article's prose as whole words.
     *
     * @return array{phrase: string, occurrence: int}|null
     */
    public function locate(Article $article, string $phrase): ?array
    {
        $runs = array_map(fn (TextRun $run): array => ['lower' => mb_strtolower($run->text), 'text' => $run->text, 'linkable' => $run->linkable], $article->runs);
        $match = trim($phrase) === '' ? null : $this->find($runs, mb_strtolower(trim($phrase)), []);

        return $match === null ? null : ['phrase' => $match['written'], 'occurrence' => $match['occurrence']];
    }

    /**
     * The phrases a title offers, longest first: the whole title, then runs
     * of its words that neither start nor end with a stop word and hold a
     * word of four letters or more. A one-word title is offered only when it
     * has five letters or more.
     *
     * @return list<string>
     */
    public static function phrases(string $title): array
    {
        $words = preg_split('/[^\p{L}\p{N}\'’-]+/u', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $count = count($words);

        if ($count === 1) {
            return mb_strlen($words[0]) >= 5 && ! in_array($words[0], self::STOP_WORDS, true) ? [$words[0]] : [];
        }

        $phrases = [];

        for ($length = min($count, self::LONGEST); $length >= 2; $length--) {
            for ($start = 0; $start + $length <= $count; $start++) {
                $run = array_slice($words, $start, $length);

                if (in_array($run[0], self::STOP_WORDS, true) || in_array($run[$length - 1], self::STOP_WORDS, true)) {
                    continue;
                }

                $long = array_filter($run, fn (string $word): bool => mb_strlen($word) >= 4 && ! in_array($word, self::STOP_WORDS, true));

                if ($long !== []) {
                    $phrases[implode(' ', $run)] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($phrases));
    }

    /**
     * The first match of the phrase where a link may go, as whole words and
     * clear of the ranges already used: its run, where it starts and ends,
     * the words as written, and which match it is among all the phrase's
     * matches outside links and code, as the editor counts them.
     *
     * @param  list<array{lower: string, text: string, linkable: bool}>  $runs
     * @param  list<array{0: int, 1: int, 2: int}>  $used
     *
     * @return array{run: int, start: int, end: int, written: string, occurrence: int}|null
     */
    private function find(array $runs, string $phrase, array $used): ?array
    {
        $length = mb_strlen($phrase);
        $seen = 0;

        foreach ($runs as $index => $run) {
            $at = mb_strpos($run['lower'], $phrase);

            while ($at !== false) {
                $seen++;
                $end = $at + $length;

                if ($run['linkable'] && self::wholeWords($run['lower'], $at, $end) && ! self::overlaps($used, $index, $at, $end)) {
                    return ['run' => $index, 'start' => $at, 'end' => $end, 'written' => mb_substr($run['text'], $at, $length), 'occurrence' => $seen];
                }

                $at = mb_strpos($run['lower'], $phrase, $end);
            }
        }

        return null;
    }

    /**
     * Whether the match starts and ends on a word's edge.
     */
    private static function wholeWords(string $text, int $start, int $end): bool
    {
        $before = $start === 0 ? '' : mb_substr($text, $start - 1, 1);
        $after = mb_substr($text, $end, 1);

        return preg_match('/[\p{L}\p{N}]/u', $before) !== 1 && preg_match('/[\p{L}\p{N}]/u', $after) !== 1;
    }

    /**
     * Whether the phrase holds, or is held by, one already chosen: using one
     * would change which match of the other the editor counts.
     *
     * @param  list<string>  $chosen
     */
    private static function sharesWords(string $phrase, array $chosen): bool
    {
        foreach ($chosen as $other) {
            if (str_contains($phrase, $other) || str_contains($other, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the range touches one already used.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $used
     */
    private static function overlaps(array $used, int $run, int $start, int $end): bool
    {
        foreach ($used as [$usedRun, $usedStart, $usedEnd]) {
            if ($usedRun === $run && $start < $usedEnd && $usedStart < $end) {
                return true;
            }
        }

        return false;
    }
}
