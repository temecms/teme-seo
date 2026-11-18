# Teme SEO roadmap

Teme SEO grows in small releases. Each one is finished, tested and documented before it ships.

## 1.0.0

On Teme 1.0. Internal link suggestions, links in and out with the orphaned-items report, cornerstone content, the site-wide audit, the bulk editor for titles and descriptions, the Search Console overview, AI link ideas and keyphrase rewrites, in Teme's eight languages.

## 1.1.0: keyphrases, checks and richer schema (needs Teme 1.1)

- Several focus keyphrases per item, in the editor's Search and social box.
- Checks on where each keyphrase appears, and readability checks, among the editor's checks.
- A score per item, and a score column in the content lists.
- More schema types: recipe, event, course, review, job posting, software, book and product. FAQPage from core's FAQ card, HowTo from its Steps card, and author signals built on core's Person node.
- Importing focus keyphrases and cornerstone marks from Yoast and Rank Math.

## Later

- **Rewrites that know when the draft moved on.** A keyphrase rewrite is kept as a copy of the whole document, so using it after editing the draft again would undo those edits. The compare view shows this, but a rewrite should remember the revision it was made from, and be dropped or flagged once the draft has moved on.
- **Link suggestions on very large sites.** Link suggestions look at the first 5,000 published items, cornerstone items first. Items beyond that are never suggested.
- **Smaller rough edges.**
  - The audit, with no filter, also lists drafts as "Checked once published", where it should list only published items with a problem.
  - Search Console's rows offer an Edit form whose save always refuses.
  - The Search Console fetch is retried even when it failed because Search Console isn't connected.
- **404 suggestions** on Tools › Not found, once Teme offers plugins a place there (FINDINGS.md, wall 3).
