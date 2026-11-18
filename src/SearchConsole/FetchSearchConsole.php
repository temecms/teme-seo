<?php

declare(strict_types=1);

namespace Teme\Seo\SearchConsole;

use DateTimeImmutable;
use RuntimeException;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Seo\Storage\SearchFigures;
use Teme\Seo\TemeSeo;

/**
 * Fetches Search Console's figures for the property in Teme SEO's settings:
 * totals by day for the last 56 days Google has, and the top 100 searches
 * and pages of the last 28, replacing what was kept.
 */
final readonly class FetchSearchConsole implements JobHandler
{
    /** Search Console's figures arrive about two days late. */
    private const int LAG_DAYS = 2;

    public function __construct(
        private SettingsReader $settings,
        private SecretReader $secrets,
        private SearchConsoleClient $client,
        private SearchFigures $figures,
    ) {}

    public function handle(JobContext $context): void
    {
        $property = trim($this->settings->string(TemeSeo::SETTINGS, 'property'));
        $clientId = trim($this->settings->string(TemeSeo::SETTINGS, 'client_id'));
        $secret = $this->secrets->secret(TemeSeo::SETTINGS, 'client_secret');
        $refresh = $this->secrets->secret(TemeSeo::SETTINGS, 'refresh_token');

        if (! $this->settings->bool(TemeSeo::SETTINGS, 'search_console') || $property === '' || $clientId === '' || $secret === null || $secret === '' || $refresh === null || $refresh === '') {
            throw new RuntimeException(__('Search Console isn’t connected. Switch it on and fill in the property, the OAuth client and the refresh token in Teme SEO’s settings.'));
        }

        $context->progress(0, 4, __('Signing in to Google'));
        $token = $this->client->accessToken($clientId, $secret, $refresh);

        $end = (new DateTimeImmutable('today'))->modify('-'.self::LAG_DAYS.' days');
        $start = $end->modify('-'.(SearchFigures::DAYS_KEPT - 1).' days');
        $periodStart = $end->modify('-27 days');

        $context->progress(1, 4, __('Fetching totals by day'));
        $days = array_map(fn (array $row): array => [
            'day' => $row['keys'][0] ?? '',
            'clicks' => $row['clicks'],
            'impressions' => $row['impressions'],
            'position' => $row['position'],
        ], $this->client->rows($property, $token, self::query($start, $end, 'date', SearchFigures::DAYS_KEPT)));
        $this->figures->replaceDays(array_values(array_filter($days, fn (array $day): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day['day']) === 1)));

        foreach (['query' => 2, 'page' => 3] as $kind => $step) {
            $context->progress($step, 4, $kind === 'query' ? __('Fetching top searches') : __('Fetching top pages'));
            $this->figures->replaceRows($kind, array_map(fn (array $row): array => [
                'key' => mb_substr($row['keys'][0] ?? '', 0, 2000),
                'clicks' => $row['clicks'],
                'impressions' => $row['impressions'],
                'position' => $row['position'],
            ], $this->client->rows($property, $token, self::query($periodStart, $end, $kind, 100))));
        }

        $context->progress(4, 4, __('Search Console figures fetched.'));
    }

    /**
     * One search analytics query over these days, by one dimension.
     *
     * @return array<string, mixed>
     */
    private static function query(DateTimeImmutable $start, DateTimeImmutable $end, string $dimension, int $rows): array
    {
        return [
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
            'dimensions' => [$dimension],
            'rowLimit' => $rows,
            'dataState' => 'final',
        ];
    }
}
