<?php

/**
 * Teme SEO, the free first-party SEO plugin: it keeps an index of the
 * site's items and the links between them as content changes, and hands
 * Teme its editor panels, list columns, jobs and its own pages.
 *
 * @package teme/seo
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Teme\Seo;

use Teme\Contracts\Admin\HasPluginArea;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Ai\SiteAi;
use Teme\Contracts\Content\ContentDeleted;
use Teme\Contracts\Content\ContentPublished;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ContentRestored;
use Teme\Contracts\Content\ContentSaved;
use Teme\Contracts\Content\ContentTrashed;
use Teme\Contracts\Content\ContentUnpublished;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Editor\EditorPanel;
use Teme\Contracts\Editor\HasEditorPanels;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Jobs\JobHandler;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Lists\HasListColumns;
use Teme\Contracts\Lists\ListColumn;
use Teme\Contracts\Plugins\Plugin;
use Teme\Contracts\Settings\SecretReader;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Seo\Ai\AskAiForLinks;
use Teme\Seo\Ai\RewriteForKeyphrase;
use Teme\Seo\Area\SeoArea;
use Teme\Seo\Columns\LinksColumn;
use Teme\Seo\Jobs\IndexSite;
use Teme\Seo\Jobs\MarkCornerstone;
use Teme\Seo\Jobs\SuggestLinks;
use Teme\Seo\Links\LinkSuggester;
use Teme\Seo\Panels\KeyphrasePanel;
use Teme\Seo\Panels\LinksPanel;
use Teme\Seo\SearchConsole\FetchSearchConsole;
use Teme\Seo\SearchConsole\SearchConsoleClient;
use Teme\Seo\Storage\Indexer;
use Teme\Seo\Storage\ItemIndex;
use Teme\Seo\Storage\SearchFigures;
use Teme\Seo\Storage\SuggestionStore;

final class TemeSeo implements HasEditorPanels, HasListColumns, HasPluginArea, Plugin, PluginHasJobs
{
    /** The plugin's settings group. */
    public const string SETTINGS = 'plugin.seo';

    private readonly ItemIndex $index;

    private readonly SuggestionStore $suggestions;

    private readonly Indexer $indexer;

    public function __construct(
        private readonly ContentReader $reader,
        private readonly ContentWriter $writer,
        private readonly OutboundHttp $http,
        private readonly SiteAi $ai,
        private readonly SettingsReader $settings,
        private readonly SecretReader $secrets,
    ) {
        $this->index = new ItemIndex;
        $this->suggestions = new SuggestionStore;
        $this->indexer = new Indexer($reader, $this->index, $settings);
    }

    /**
     * Keeps the index in step with the site: each change to an item is read
     * as the person who made it, after it is saved.
     */
    public function register(Hooks $hooks): void
    {
        $hooks->on(ContentSaved::class, fn (ContentSaved $saved) => $this->indexer->heard($saved->item, $saved->actorId));
        $hooks->on(ContentPublished::class, fn (ContentPublished $published) => $this->indexer->heard($published->item, $published->actorId));
        $hooks->on(ContentRestored::class, fn (ContentRestored $restored) => $this->indexer->heard($restored->item, $restored->actorId));
        $hooks->on(ContentUnpublished::class, fn (ContentUnpublished $unpublished) => $this->indexer->heard($unpublished->item, $unpublished->actorId));
        $hooks->on(ContentTrashed::class, fn (ContentTrashed $trashed) => $this->indexer->heard($trashed->item, null, trashed: true));
        $hooks->on(ContentDeleted::class, function (ContentDeleted $deleted): void {
            $this->index->forget($deleted->id);
            $this->suggestions->forget($deleted->id);
        });
    }

    /**
     * Returns the Internal links and Keyphrase rewrite panels.
     *
     * @return array<string, EditorPanel>
     */
    public function editorPanels(): array
    {
        return [
            LinksPanel::ID => new LinksPanel($this->index, $this->suggestions),
            KeyphrasePanel::ID => new KeyphrasePanel($this->suggestions),
        ];
    }

    /**
     * Returns the Links in and Links out columns.
     *
     * @return array<string, ListColumn>
     */
    public function listColumns(): array
    {
        return [
            'links-in' => new LinksColumn($this->index, incoming: true),
            'links-out' => new LinksColumn($this->index, incoming: false),
        ];
    }

    /**
     * Returns the data behind Teme SEO's own pages.
     */
    public function pluginArea(): PluginArea
    {
        return new SeoArea($this->index, new SearchFigures, $this->reader, $this->writer);
    }

    /**
     * Returns a handler for each declared job.
     *
     * @return array<string, JobHandler>
     */
    public function jobs(): array
    {
        $suggester = new LinkSuggester;

        return [
            'index-site' => new IndexSite($this->reader, $this->indexer, $this->index, $this->settings),
            'suggest-links' => new SuggestLinks($this->indexer, $this->index, $this->suggestions, $suggester),
            'cornerstone' => new MarkCornerstone($this->indexer, $this->index),
            'ai-links' => new AskAiForLinks($this->ai, $this->indexer, $this->index, $this->suggestions, $suggester),
            'ai-rewrite' => new RewriteForKeyphrase($this->ai, $this->indexer, $this->suggestions),
            'search-console' => new FetchSearchConsole($this->settings, $this->secrets, new SearchConsoleClient($this->http), new SearchFigures),
        ];
    }
}
