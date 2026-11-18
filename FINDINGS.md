# Findings: where Teme SEO met a wall

Teme SEO is built only on what Teme offers every plugin. Where a socket was missing or too weak for what the plugin needed, Teme's core was left alone: each wall is written down here, with what the plugin expected and what it does instead, so the maintainers can decide whether core should grow.

## 1. No content fields from a plugin

**Expected:** to declare a "Cornerstone content" field on posts and pages, stored in the item's own fields like core's search fields, edited in the editor and kept with each revision.

**Instead:** the manifest has no key for a plugin's own content fields. The cornerstone mark lives in Teme SEO's own table, `seo_items`, and is set from the Internal links panel's **Cornerstone** action or from the forms on Teme SEO's Links and Titles and descriptions pages. It isn't part of an item's history, and an export of the site doesn't carry it.

## 2. A plugin can't write a secret, or finish an OAuth sign-in

**Expected:** a **Connect Search Console** button that sends the administrator to Google, comes back to the site, and keeps the refresh token as a secret setting.

**Instead:** `PluginSettingsWriter` never writes a `secret`, and a public route runs for nobody, so it can neither tell who came back nor keep what Google answered. The administrator makes an OAuth client in Google Cloud, gets a refresh token for it (the README walks through it), and pastes the client ID, the client secret and the refresh token into Teme SEO's settings. The secrets are kept as secret settings; the access token lives only for one run.

## 3. No place on Tools › Not found

**Expected:** beside each address in core's 404 log, the likeliest live item to send it to, from the site's search and from the item's old addresses, with core's one-click redirect.

**Instead:** nothing. There is no socket on that screen, no contract that reads the 404 log or an item's old addresses, no plugin door to the site's search (`SiteSearch` is not offered to a plugin's constructor), and no door that proposes a redirect. Teme SEO doesn't build this part.

## 4. Core's own checks are drawn in the browser only

**Expected:** to list, on the site-wide audit, every item failing one of core's factual checks, in core's own words.

**Instead:** core's checks are worked out in the editor's JavaScript (`coreChecks()`), with nothing on the server a plugin can ask. The audit makes its own checks from what Teme SEO reads of each item, worded as plainly: no description, a title or description longer than results show, no featured image, images without alt text, no internal links, links readers can't reach, orphaned, thin, and hidden from search engines. The title is measured in characters (60), where core measures pixels, so the two can disagree near the edge.

## 5. A plugin can't learn which fields a type has

**Expected:** to ask "No featured image" only of types that have one.

**Instead:** `ItemSnapshot::$fields` holds the values that are set, so an empty featured image and a type without one look the same. The check is asked of posts only.

## 6. An area's table isn't told which table asks

**Expected:** two tables of the same record on two pages (the audit, and titles and descriptions), each with its own rows.

**Instead:** `PluginArea::rows()` is given the record and the query, never the block, and the query holds only the filters a person chose. Both pages share the `item` record: every row carries both tables' cells, and the order is the same on both (most problems first, then by title). An area has at most three records, so a separate record for each page wasn't possible either: the other two are the links report and Search Console.

## 7. A table's row can't link to the editor

**Expected:** each row of the audit to open its item in the editor.

**Instead:** a row's cells are text, numbers and badges, and its actions are core's (edit, duplicate, enable, disable, delete). **Edit** opens Teme SEO's own form for the item, which fixes the title, the SEO title, the description and the cornerstone mark; anything else is fixed from the content list.

## 8. Several content changes can't be heard without a person

**Expected:** to read an item's new links when its schedule publishes it.

**Instead:** `ContentReader::draft()` reads only for the person the work acts for, and a scheduled publication has nobody signed in. Teme SEO marks such an item to be read again, and its hourly job reads it as the administrator who enabled the plugin.

## 9. The testing kit writes items with an empty body

**Expected:** `draft()` and `publish()` taking a body and field values, so a plugin's tests can write articles with words and links.

**Instead:** Teme SEO's tests write their items through Teme's own Operations in `tests/Support/Writing.php`. A third-party plugin couldn't.

## 10. A plugin under `Teme\` can't declare a hook

**Expected:** a `seo:ItemChecks` hook, so other plugins could add their own checks to the audit.

**Instead:** the loader refuses any hook class in the `Teme\` namespace, which it keeps for core, and Teme SEO's namespace is `Teme\Seo`. The plugin wouldn't start with the hook declared, so it declares none. Core would need either a list of first-party namespaces or a check of the package name.

## 11. The testing kit's fake web answers once per host

**Expected:** to answer Google's token address and its Search Console address differently in one test, or to answer one address differently on each call.

**Instead:** `FakeOutbound::answer()` keeps one answer per host and takes no callback, so a test can't follow a whole fetch through several pages of answers. Teme SEO's tests give each host one answer, and fill the figures tables directly where a test needs more.

## 12. The plugins' analysis can't read a Pest test that uses `$this`

**Expected:** to write tests as the testing kit's guide shows, starting each file with `uses(TemeTestCase::class);` and calling the kit's methods on `$this` inside each `it()`.

**Instead:** the plugins' PHPStan run analyses `plugins/*/tests` without Pest's extension, and Pest declares the closure's `$this` as its own `TestCall`. Every `$this->person()` or `$this->get()` is then an error. Teme SEO's tests are PHPUnit classes on `SeoTestCase`, which extends the kit's `TemeTestCase`: Pest runs them the same, and the analysis knows every type.

## 13. A plugin can read only an item's latest draft

**Expected:** to read what readers see for a published item: its published revision's body and search fields.

**Instead:** `ContentReader::draft()` reads the working revision, and nothing reads the published one. For a published item with unpublished changes, Teme SEO's links, counts, orphans, word count and the audit's search title and description are those of the draft. The Links and Audit pages and the README say "as of the latest draft". Core would need a read of the published revision.
