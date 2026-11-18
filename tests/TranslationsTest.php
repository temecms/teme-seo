<?php

declare(strict_types=1);

namespace Teme\Seo\Tests;

use App\Domain\Plugins\CodeScan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Teme\Seo\Tests\Support\SeoTestCase;

require_once __DIR__.'/Support/SeoTestCase.php';

/**
 * Every word Teme SEO shows, in each language Teme ships, with its placeholders kept.
 */
final class TranslationsTest extends SeoTestCase
{
    /** The manifest keys whose values a person reads. */
    private const array WORDS = ['label', 'description', 'text', 'confirm', 'plural', 'title'];

    /**
     * Teme's languages besides English.
     *
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['de' => ['de'], 'es' => ['es'], 'fr' => ['fr'], 'hi' => ['hi'], 'it' => ['it'], 'ja' => ['ja'], 'pt_BR' => ['pt_BR']];
    }

    #[DataProvider('locales')]
    #[TestDox('translates every sentence and manifest word into $locale, keeping each placeholder')]
    public function test_it_translates_every_sentence_and_manifest_word_keeping_each_placeholder(string $locale): void
    {
        $folder = dirname(__DIR__);
        $file = $folder.'/lang/'.$locale.'.json';

        expect(is_file($file))->toBeTrue();

        /** @var array<string, string> $lines */
        $lines = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $missing = [];

        foreach ($this->english($folder) as $english) {
            $line = $lines[$english] ?? null;

            if (! is_string($line) || trim($line) === '') {
                $missing[] = $english;

                continue;
            }

            preg_match_all('/:[a-z_]+/', $english, $wanted);
            preg_match_all('/:[a-z_]+/', $line, $kept);
            $wanted = $wanted[0];
            $kept = $kept[0];
            sort($wanted);
            sort($kept);

            expect($kept)->toBe($wanted, "The {$locale} line for “{$english}” changes its placeholders.");
        }

        expect($missing)->toBe([]);
    }

    /**
     * Every English sentence the plugin's code passes to __() and every word its manifest shows.
     *
     * @return list<string>
     */
    private function english(string $folder): array
    {
        /** @var array<string, mixed> $composer */
        $composer = json_decode((string) file_get_contents($folder.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $words = app(CodeScan::class)->sentences($folder, $composer);

        /** @var array<string, mixed> $manifest */
        $manifest = json_decode((string) file_get_contents($folder.'/teme-plugin.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->collect($manifest['contributes'] ?? [], null, $words);

        $words = array_values(array_unique($words));
        sort($words);

        return $words;
    }

    /**
     * Adds the words a person reads from this part of the manifest.
     *
     * @param  list<string>  $words
     */
    private function collect(mixed $value, ?string $key, array &$words, bool $options = false): void
    {
        if (is_array($value)) {
            foreach ($value as $inner => $child) {
                $this->collect($child, is_string($inner) ? $inner : $key, $words, $key === 'options' && is_string($inner));
            }

            return;
        }

        if (is_string($value) && ($options || in_array($key, self::WORDS, true))) {
            $words[] = $value;
        }
    }
}
