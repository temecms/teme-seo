# Changelog

All notable changes to Teme SEO are listed here. Teme SEO follows [Semantic Versioning](https://semver.org/); its version is its own, apart from Teme's.

## 1.0.2

### Fixed

- The Search Console job fails once when the site isn’t connected, instead of retrying.
- Search Console rows no longer open an edit form whose save always refused.
- The Audit with no filter lists only published items that have a problem, not drafts.
- The Titles and descriptions table drops Status so the description and the rest fit; Status stays as a filter.
- A keyphrase rewrite is no longer offered once the draft has moved on from the revision it was made from, so using it can’t undo later edits.

## 1.0.1

### Changed

- Teme SEO has its own repository, temecms/teme-seo, and is listed in Teme's directory. It needs Teme 1.3.1 or later, the first Teme that installs plugins requiring teme/contracts.

### Fixed

- A better average position on Search Console (a lower number) shows as a good change, not a drop.
- Posts without a featured image are counted on their own at the top of the Audit; they no longer make every post look like it has a problem.
- Cornerstone content is one line (Yes or No) with a **Change** action, instead of a choice that stays open in the editor panel.
- The area is named **Teme SEO**, matching the plugin and the menu.
- Search Console draws clicks and impressions as two charts, so the clicks line is readable.
- The Audit table reads problems the hourly job already stored, so it stays within its time budget on larger sites.

## 1.0.0

Shipped inside Teme's own repository, before Teme SEO had a download of its own; 1.0.1 is the first release here.

### Added

- Internal link suggestions in the editor's **Internal links** panel, from the draft's phrases to your published items, cornerstone items first, and more ideas from your site's AI connection.
- **Links in** and **Links out** columns for the content lists, and the **Links** page listing orphaned items.
- Cornerstone content, set from the Internal links panel or Teme SEO's forms.
- The **Audit** page: every published item with a problem, filterable by problem.
- The **Titles and descriptions** page, to find and fix items' titles, SEO titles and descriptions one after another, each saved as a new revision.
- The **Keyphrase rewrite** panel, which asks your site's AI connection to rewrite the introduction, the headings or a few paragraphs around a keyphrase, shown word by word before you use it.
- The **Search Console** page: clicks, impressions, click rate and position over the last 28 days, by day, with the top searches and pages.
- Translations into German, Spanish, French, Hindi, Italian, Japanese and Brazilian Portuguese.
