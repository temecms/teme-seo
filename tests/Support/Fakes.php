<?php

declare(strict_types=1);

use Teme\Contracts\Ai\AiAnswer;
use Teme\Contracts\Ai\AiAsk;
use Teme\Contracts\Ai\AiStanding;
use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ItemPage;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\ItemSnapshot;
use Teme\Contracts\Content\QueuePage;
use Teme\Contracts\Content\QueueQuery;
use Teme\Contracts\Content\ReadRefused;
use Teme\Contracts\Jobs\JobContext;

/*
 * Stand-ins for Teme's doors, for the tests that run one of Teme SEO's AI
 * jobs alone: the site's AI connection needs a provider the tests can't
 * reach, so the AI's answer is given here.
 */

/** A job's context with this payload, acting for this person, keeping its progress. */
final class SeoFakeJob implements JobContext
{
    /** @var list<string> */
    public array $messages = [];

    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload, private string $person = '1') {}

    public function payload(): array
    {
        return $this->payload;
    }

    public function attempt(): int
    {
        return 1;
    }

    public function progress(int $done, int $total, string $message = ''): void
    {
        $this->messages[] = $message;
    }

    public function personId(): string
    {
        return $this->person;
    }
}

/** The site's AI, answering with the data given, and keeping what it was asked. */
final class SeoFakeAi implements SiteAi
{
    /** @var list<AiAsk> */
    public array $asked = [];

    /** @param array<string, mixed> $data */
    public function __construct(private array $data) {}

    public function standing(string $task): AiStanding
    {
        throw new LogicException('Not asked in these tests.');
    }

    public function ask(AiAsk $ask, string $actingPersonId): AiAnswer
    {
        $this->asked[] = $ask;

        return new AiAnswer('test-model', null, $this->data, 10, 10, null, 'request-1');
    }
}

/** A content reader that answers one item's snapshot. */
final class SeoFakeReader implements ContentReader
{
    public function __construct(private ItemSnapshot $snapshot) {}

    public function published(ItemQuery $query): ItemPage
    {
        return new ItemPage([], null);
    }

    public function draft(string $itemId, string $actingPersonId): ItemSnapshot
    {
        return $itemId === $this->snapshot->item->id ? $this->snapshot : throw new ReadRefused('No such item.');
    }

    public function queue(QueueQuery $query, string $actingPersonId): QueuePage
    {
        return new QueuePage([], null);
    }
}

/**
 * A draft's snapshot with this body, for the fake reader.
 *
 * @param  list<array<string, mixed>>  $blocks
 */
function seoSnapshot(string $id, string $title, array $blocks): ItemSnapshot
{
    return new ItemSnapshot(new ItemRef($id, 'post', $title, 'draft', null, 'en'), '01kaaaaaaaaaaaaaaaaaaaaaaa', 'a-draft', ['type' => 'doc', 'content' => $blocks], [], [], [], []);
}
