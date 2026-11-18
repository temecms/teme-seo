<?php

declare(strict_types=1);

namespace Teme\Seo\SearchConsole;

use RuntimeException;
use Teme\Contracts\Http\OutboundHttp;

/**
 * Speaks to Google's Search Console API through the plugin's outbound door:
 * trades the saved refresh token for an access token, and asks the search
 * analytics of one property. Every failure is one plain sentence.
 */
final readonly class SearchConsoleClient
{
    private const string TOKEN = 'https://oauth2.googleapis.com/token';

    private const string API = 'https://searchconsole.googleapis.com/webmasters/v3/sites/';

    public function __construct(
        private OutboundHttp $http,
    ) {}

    /**
     * Returns an access token for the OAuth client and refresh token.
     *
     * @throws RuntimeException when Google refuses them
     */
    public function accessToken(string $clientId, string $clientSecret, string $refreshToken): string
    {
        $answer = $this->http->postForm(self::TOKEN, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        $token = $answer->status === 200 ? (json_decode($answer->body, true)['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException(__('Google refused the OAuth client or the refresh token. Check them in Teme SEO’s settings.'));
        }

        return $token;
    }

    /**
     * Returns the rows Search Console answers for one search analytics query.
     *
     * @param  array<string, mixed>  $query
     *
     * @return list<array{keys: list<string>, clicks: int, impressions: int, position: float}>
     *
     * @throws RuntimeException when Search Console refuses or fails
     */
    public function rows(string $property, string $accessToken, array $query): array
    {
        $answer = $this->http->postJson(self::API.rawurlencode($property).'/searchAnalytics/query', $query, ['Authorization' => 'Bearer '.$accessToken], 10);

        if ($answer->status === 403 || $answer->status === 404) {
            throw new RuntimeException(__('Search Console says this account can’t read the property :property.', ['property' => $property]));
        }

        if ($answer->status !== 200) {
            throw new RuntimeException(__('Search Console answered :status. Teme SEO will try again later.', ['status' => $answer->status]));
        }

        $rows = [];
        $body = json_decode($answer->body, true);

        foreach (is_array($body) && is_array($body['rows'] ?? null) ? $body['rows'] : [] as $row) {
            if (! is_array($row) || ! is_array($row['keys'] ?? null)) {
                continue;
            }

            $rows[] = [
                'keys' => array_map(fn (mixed $key): string => is_scalar($key) ? (string) $key : '', array_values($row['keys'])),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'position' => round((float) ($row['position'] ?? 0), 1),
            ];
        }

        return $rows;
    }
}
