<?php

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Fields\BuiltInFields;
use App\Domain\Hooks\Payloads;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Teme\Contracts\Content\ItemRef;
use Teme\Testing\Person;

/*
 * The testing kit writes items with an empty body; Teme SEO's tests need
 * bodies with words and links, so they write them through Teme's own
 * Operations here (plugins/seo/FINDINGS.md).
 */

/**
 * A paragraph of text and links, each part a string or [words, ItemRef].
 *
 * @param  string|array{0: string, 1: ItemRef}  ...$parts
 *
 * @return array<string, mixed>
 */
function seoParagraph(string|array ...$parts): array
{
    return ['type' => 'paragraph', 'content' => array_map(fn (string|array $part): array => is_string($part)
        ? ['type' => 'text', 'text' => $part]
        : ['type' => 'text', 'text' => $part[0], 'marks' => [['type' => 'link', 'attrs' => ['href' => 'teme:content/'.$part[1]->id]]]], $parts)];
}

/**
 * A heading.
 *
 * @return array<string, mixed>
 */
function seoHeading(string $text, int $level = 2): array
{
    return ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [['type' => 'text', 'text' => $text]]];
}

/**
 * Writes an item with this body and these search fields as the person,
 * published unless asked not to, and returns it as hooks carry it.
 *
 * @param  list<array<string, mixed>>  $blocks
 * @param  array{seo_title?: string, meta_description?: string, excerpt?: string}  $fields
 */
function seoWrite(Person $by, string $title, array $blocks = [], array $fields = [], bool $publish = true, string $type = 'post'): ItemRef
{
    $keys = ['seo_title' => BuiltInFields::SEO_TITLE, 'meta_description' => BuiltInFields::META_DESCRIPTION, 'excerpt' => BuiltInFields::EXCERPT];
    $byKey = [];

    foreach ($fields as $handle => $value) {
        $byKey[$keys[$handle]] = $value;
    }

    $before = Auth::user();
    Auth::setUser(User::query()->findOrFail($by->id));

    try {
        /** @var Content $content */
        $content = app(CreateDraft::class)->execute(new CreateDraftInput($type, $title, ['type' => 'doc', 'content' => $blocks], $byKey, (string) $by->id));

        if ($publish) {
            app(PublishRevision::class)->execute(new PublishRevisionInput($content->id, (string) $content->working_revision_id, (string) $by->id));
        }
    } finally {
        $before === null ? Auth::forgetUser() : Auth::setUser($before);
    }

    return Payloads::item($content->refresh());
}
