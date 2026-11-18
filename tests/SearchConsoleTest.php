<?php

declare(strict_types=1);

namespace Teme\Seo\Tests;

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestDox;
use Teme\Seo\Tests\Support\SeoTestCase;
use Teme\Testing\SentRequest;

require_once __DIR__.'/Support/SeoTestCase.php';

/**
 * The Search Console overview: the OAuth exchange, the query and the figures shown.
 */
final class SearchConsoleTest extends SeoTestCase
{
    #[TestDox('trades the refresh token for an access token and asks for the property’s figures')]
    public function test_it_trades_the_refresh_token_for_an_access_token_and_asks_for_the_propertys_figures(): void
    {
        $this->connect();
        $outbound = $this->outbound();
        $outbound->answer('oauth2.googleapis.com', 200, ['access_token' => 'token-1', 'expires_in' => 3600]);
        // The kit's fake gives a host one answer, so every query hears the same rows (plugins/seo/FINDINGS.md).
        $day = date('Y-m-d', strtotime('-3 days'));
        $outbound->answer('searchconsole.googleapis.com', 200, ['rows' => [['keys' => [$day], 'clicks' => 7, 'impressions' => 90, 'ctr' => 0.07, 'position' => 4.25]]]);

        expect($this->seo->runJob('search-console')->state)->toBe('finished');

        $token = $outbound->sent('oauth2.googleapis.com')[0];
        $queries = $outbound->sent('searchconsole.googleapis.com');

        expect($token->form())->toMatchArray(['grant_type' => 'refresh_token', 'refresh_token' => 'refresh-me', 'client_secret' => 'shh', 'client_id' => 'client.apps.googleusercontent.com'])
            ->and($queries)->toHaveCount(3)
            ->and($queries[0]->url)->toBe('https://searchconsole.googleapis.com/webmasters/v3/sites/sc-domain%3Aexample.com/searchAnalytics/query')
            ->and(array_column(array_map(fn (SentRequest $sent): array => (array) $sent->json(), $queries), 'dimensions'))->toBe([['date'], ['query'], ['page']])
            ->and(DB::table('seo_search_days')->get(['day', 'clicks'])->map(fn (object $row): array => (array) $row)->all())->toBe([['day' => $day, 'clicks' => 7]])
            ->and(DB::table('seo_search_rows')->where('kind', 'query')->value('position'))->toBe(4.3);

        expect(collect($queries[0]->headers)->mapWithKeys(fn (mixed $value, string $name): array => [strtolower($name) => $value])->get('authorization'))->toContain('Bearer token-1');
    }

    #[TestDox('shows the last 28 days, each figure with its change from the 28 before')]
    public function test_it_shows_the_last_28_days_each_figure_with_its_change_from_the_28_before(): void
    {
        foreach (range(55, 0) as $ago) {
            DB::table('seo_search_days')->insert(['day' => date('Y-m-d', strtotime('-'.($ago + 2).' days')), 'clicks' => $ago < 28 ? 20 : 10, 'impressions' => 400, 'position' => 8.0]);
        }

        DB::table('seo_search_rows')->insert([
            ['kind' => 'query', 'key' => 'feeding roses', 'clicks' => 30, 'impressions' => 900, 'position' => 3.2],
            ['kind' => 'query', 'key' => 'rose food', 'clicks' => 5, 'impressions' => 300, 'position' => 9.5],
            ['kind' => 'page', 'key' => 'https://example.com/feeding-roses/', 'clicks' => 35, 'impressions' => 1200, 'position' => 4.1],
        ]);

        $this->signIn($this->admin);
        $this->get(route('plugins.area.show', ['code' => 'seo', 'page' => 'search-console', 't' => ['search' => ['filters' => ['show' => 'pages']]]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps('block_search-totals', fn (Assert $reload) => $reload
                    ->where('block_search-totals.items.0.value', 560)
                    ->where('block_search-totals.items.0.change', 1)
                    ->where('block_search-totals.items.1.value', 11200))
                ->loadDeferredProps('block_search-daily', fn (Assert $reload) => $reload->has('block_search-daily.labels', 28))
                ->loadDeferredProps('block_search', fn (Assert $reload) => $reload
                    ->where('block_search.total', 1)
                    ->where('block_search.rows.0.cells.key.value', 'https://example.com/feeding-roses/')));
    }

    #[TestDox('fails the run with a plain sentence when Google refuses the token, keeping what it had')]
    public function test_it_fails_the_run_with_a_plain_sentence_when_google_refuses_the_token_keeping_what_it_had(): void
    {
        $this->connect();
        DB::table('seo_search_days')->insert(['day' => '2026-11-01', 'clicks' => 3, 'impressions' => 9, 'position' => 2]);
        $this->outbound()->answer('oauth2.googleapis.com', 400, ['error' => 'invalid_grant']);

        $run = $this->seo->runJob('search-console');

        expect($run->state)->toBe('failed')
            ->and($run->message)->toBe('Google refused the OAuth client or the refresh token. Check them in Teme SEO’s settings.')
            ->and(DB::table('seo_search_days')->count())->toBe(1);
    }

    #[TestDox('sends nothing while Search Console isn’t connected')]
    public function test_it_sends_nothing_while_search_console_isnt_connected(): void
    {
        $this->outbound();

        $run = $this->seo->runJob('search-console');

        expect($run->state)->toBe('failed')
            ->and($run->message)->toStartWith('Search Console isn’t connected.');
        $this->outbound()->assertNothingSent();
    }

    /**
     * Fills in Search Console's settings, as an administrator would.
     */
    private function connect(): void
    {
        $this->seo->changeSettings([
            'search_console' => true,
            'property' => 'sc-domain:example.com',
            'client_id' => 'client.apps.googleusercontent.com',
            'client_secret' => 'shh',
            'refresh_token' => 'refresh-me',
        ]);
    }
}
