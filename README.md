# Teme SEO

Teme SEO is the free SEO plugin for [Teme](https://github.com/temecms/teme), made by Teme's maintainers. It adds to what Teme already does for search: Teme keeps your titles, descriptions, social cards, structured data, sitemaps, robots.txt, canonical addresses and redirects, and Teme SEO helps you with the links between your items, the state of the whole site, and how Google sees it.

It needs Teme 1.0 or later. It is MIT licensed, like Teme.

## What it does

**Internal links as you write.** In the editor, open **Internal links** from the Tools menu and press **Find links**. Teme SEO reads your draft and suggests links from its phrases to your own published items, cornerstone items first, leaving out items you already link to. Use a suggestion and the phrase becomes a link in your draft, stored by the item's ID so it never breaks when an address changes; discard it and it isn't offered again. **Ask AI for links** asks your site's AI connection for more ideas.

**Links in and out.** The content lists can show **Links in** and **Links out** for every item: turn them on from the list's Columns menu. The counts are exact, because Teme stores every internal link by the ID of the item it leads to. They are counted as of each item's latest draft, so a link you added but haven't published yet already counts. The **Links** page lists the orphaned items: published items no other published item links to.

**Cornerstone content.** Mark your most important items as cornerstone content from the **Cornerstone** action in the Internal links panel, or from the forms on Teme SEO's pages. They come first in link suggestions and in the orphaned report.

**The audit.** The **Audit** page lists every published item with a problem: no description, a title or description longer than search results show, no featured image, images without alt text, no internal links, links readers can't reach, orphaned, thin, or hidden from search engines. Filter it by problem, and open an item's form to fix its title and description at once. Each item is checked as of its latest draft, so a published item with unpublished changes is checked as it will be, not as readers see it now. Drafts are listed only to the people who may edit them.

**Titles and descriptions in bulk.** The **Titles and descriptions** page lists every item's title, SEO title and meta description, so you can search them, find the ones missing, and fix them one after another. Each change is saved as a new revision of the item, as the editor saves it, with the same checks and your own permissions.

**Keyphrase rewrites.** The **Keyphrase rewrite** panel asks your site's AI connection to rewrite the introduction, the headings or a few paragraphs of your draft around a keyphrase you give. You see the rewrite word by word beside your article before you use it, and a paragraph that holds a link is never rewritten.

**Search Console.** Once connected, the **Search Console** page shows your clicks, impressions, click rate and average position over the last 28 days, each with its change from the 28 days before, a chart by day, and your top searches and top pages.

Teme SEO's pages are under **Teme SEO** in the admin menu, for administrators and editors. Administrators can give the **Use Teme SEO's pages** permission to other roles.

## Settings

Find them under **Settings › Plugin settings › Teme SEO**.

- **Fewest words**: a published item with fewer words is listed as thin in the audit. 300 unless you change it.
- **Read Search Console** and its connection, below.
- **Read every item's links again** rebuilds the links report and the audit from scratch. Teme SEO keeps them up to date as you write, and every hour it reads any item a change marked, so you rarely need it. Press it after Teme SEO has been switched off for a while: it also clears items that were unpublished or deleted in the meantime.

## Connecting Search Console

Teme SEO reads Search Console through Google's API, with an OAuth client you make for your site. It asks only to read.

1. In the [Google Cloud console](https://console.cloud.google.com/), create a project, or choose one, and enable the **Google Search Console API** in it.
2. Under **APIs & Services › OAuth consent screen**, set up the consent screen. An internal app is enough for a Google Workspace account. Otherwise choose an external app, add yourself as a test user, and then set its **Publishing status** to **In production**. Google lets a refresh token for an app still in testing expire after 7 days, and the daily fetch would start failing a week later. You don't need Google's verification for an app only you use: when you sign in, Google warns that the app isn't verified, and you can go on.
3. Under **APIs & Services › Credentials**, create an **OAuth client ID** of the type **Web application**, and add `https://developers.google.com/oauthplayground` as an authorized redirect URI. Note the client ID and the client secret.
4. Open the [OAuth 2.0 Playground](https://developers.google.com/oauthplayground). In its settings (the gear icon), tick **Use your own OAuth credentials** and enter your client ID and secret. In the list of APIs, enter the scope `https://www.googleapis.com/auth/webmasters.readonly`, press **Authorize APIs**, and sign in with the Google account that can see your property in Search Console. Then press **Exchange authorization code for tokens** and copy the **refresh token**. Once you have it, you can remove the Playground's redirect URI from your OAuth client.
5. In Teme SEO's settings, switch on **Read Search Console**, and fill in the **Property** as Search Console names it (`sc-domain:example.com` for a domain property, or the address prefix such as `https://example.com/`), the **OAuth client ID**, the **OAuth client secret** and the **Refresh token**. The secret and the token are stored encrypted and never shown again.
6. Press **Fetch Search Console now**, or wait for the daily fetch. The run shows in **Tools › Jobs**, with what went wrong if Google refused.

Teme asks you to agree, when you enable Teme SEO, that it may reach `oauth2.googleapis.com` and `searchconsole.googleapis.com`. It reaches nothing else.

## What it sends to your AI connection

The AI features use your site's own AI connection, set up under **Settings › AI**: Teme SEO has no AI key of its own. **Ask AI for links** sends the draft's words and a shortlist of your published titles; **Keyphrase rewrite** sends the keyphrase, the title and the parts of the draft it rewrites. Each request counts against your site's AI budget and Teme SEO's own, which you set on Settings › AI, and is listed in **Tools › AI activity**. Nothing is sent until you press a button.

## Requirements

- Teme 1.0 or later.
- For Search Console: a Google account that can see your property, and an OAuth client as above.
- For the AI features: an AI connection in **Settings › AI**, with a model chosen for Teme SEO's two tasks.

What comes next is listed in [ROADMAP.md](ROADMAP.md), and changes in [CHANGELOG.md](CHANGELOG.md).
