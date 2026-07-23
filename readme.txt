=== Agentic Commerce Engine ===
Contributors: authoritas
Tags: ai, seo, woocommerce, ai search, content
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 4.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bring ACE to WordPress: AI content generation, AI search visibility research, native AI crawler analytics, and full WooCommerce product optimization.

== Description ==

Agentic Commerce Engine (ACE) connects your WordPress site to the ACE platform and puts the whole product in your dashboard. The plugin is free and fully functional on its own; article generation and AI search research additionally use the ACE service. WooCommerce is optional: content, AI search research, and crawler analytics work on any WordPress site, and the full product optimization suite is available when WooCommerce is active.

**Content (any WordPress site)**

* **Generate long-form articles** in wp-admin: pick a content type (built-in layouts, a preset library, or your project's custom types), a topic, keywords, and a locale, and ACE writes the article with your brand voice.
* **Publish as posts** with one click: articles land as drafts (or publish immediately), as Gutenberg blocks or classic HTML, with SEO title and meta description written to your active SEO plugin and Article JSON-LD attached.
* **Two-way with the ACE app:** articles you generate here appear in the ACE Content Hub, and briefs written in the app can be published to WordPress from the plugin.

**AI Search research (any WordPress site)**

* **Launch research runs** against the AI answer engines (ChatGPT, Perplexity, Gemini, and more) straight from wp-admin: manage your prompt set, get AI-suggested prompts from your own catalog, pick engines and markets, estimate, and launch.
* **Rich dashboards:** share of brand mentions, share of answers, competitor comparison, top cited domains, per-prompt results with citations, and trends across runs.

**AI crawler analytics (any WordPress site, works before you even connect)**

* **Native tracking** of AI crawlers hitting your site: GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, PerplexityBot, Google-Extended, Applebot-Extended, Amazonbot, Bytespider, CCBot, and more.
* **Privacy-safe by design:** only the date, the crawler name, the page path, and a count are stored, in your own database. No IP addresses, no full user agents, no query strings, nothing about logged-in users.
* **Charts built in:** trend by crawler, top crawled pages, and a day-by-day heatmap. Optionally report daily aggregates back to ACE to see them alongside your other channels.

**Product optimization (with WooCommerce)**

* **Sync** your products to ACE in batches, safely, even on catalogs of thousands of products.
* **Enrich** products with AI across every content type: titles, descriptions, SEO meta, Product schema, real-life usage scenarios, internal links, and channel feeds.
* **Review** every suggestion with a clear before/after diff, approve per product or in bulk, or turn on auto-apply. Revert any product with one click.
* **Display** the new on-page content with a block, shortcodes, or an automatic append below the product summary.
* **Serve** Google, Microsoft, and Agentic Commerce product feeds straight from your store as feed URLs.

**Built for scale**

Product lists, review, and the served feeds page through your catalog rather than loading it all at once. Enrichment and article generation run as durable jobs on the platform and are polled in the background, so nothing depends on a browser tab staying open. Crawler tracking writes one tiny row per bot hit and prunes itself on a schedule.

== External services ==

This plugin connects to the ACE (Agentic Commerce Engine) platform, operated by Rezolve Ai Limited (Authoritas), to perform work that cannot be done on your own server: large language model content generation and queries against third-party AI answer engines.

**What works without any account or connection**

Every feature the plugin implements locally is available immediately on activation, with no key, no sign-up, and no account of any kind:

* AI crawler analytics: tracking, path storage, retention, and all of the charts and heatmaps on the AI Search screen.
* Publishing preferences: default post status, block or classic format, default author, and Article JSON-LD output.
* The Content screen's Published tab, listing and linking to the ACE articles already in your WordPress database.
* Browsing, searching, filtering and exporting your WooCommerce catalog, and the whole review queue including approve, revert, and applying stored suggestions to your products.
* Product JSON-LD, the on-page blocks and shortcodes, the product feed URLs, and debug logging.

**What needs the ACE service**

Article generation, product enrichment, AI search research runs, credit balances, scheduled catalog sync, auto-apply, and the optional daily crawler report. Writing an article or querying an AI answer engine is work your own server cannot do, so these send a request to ACE and ask for an account. Nothing else is withheld, and none of the features listed above are affected.

* **What is sent on connect:** your site URL and title, the WordPress version, the WooCommerce version when present, and the plugin version. This registers your site and returns a store ID and a webhook secret.
* **What is sent on sync and enrich (WooCommerce):** product data including title, description, short description, SKU, price, stock status, image URLs, categories, tags, and variation IDs, plus the content types you selected.
* **What is sent for article generation:** the title, topic, keywords, locale, and options you enter, and optionally the products you link. The platform uses your project's brand voice and identity to write the article.
* **What is sent for AI search research:** the research prompts you save, the engines and markets you pick, and, when you use prompt suggestions, catalog snippets (product titles, categories, tags). Research runs are executed by the Authoritas AI search platform.
* **What is sent on heartbeat:** a periodic health ping containing your site URL, product counts, and version numbers.
* **Optional crawler report:** if you opt in, a daily summary of AI crawler hits (date, crawler name, page paths, counts) is sent to ACE. This is off by default and never includes IP addresses or user agents.
* **AI processing:** the platform generates content using large language models, including models provided by OpenAI. Data you send is processed by these models to produce the results.
* **ACE terms of service:** https://ace.authoritas.com/terms
* **ACE privacy policy:** https://ace.authoritas.com/privacy
* **OpenAI terms of use:** https://openai.com/policies/terms-of-use
* **OpenAI privacy policy:** https://openai.com/policies/privacy-policy

No data is sent until you enter an API key and connect your site. The local crawler analytics never send data unless you opt in to the daily report.

== Trademarks ==

WooCommerce and WordPress are trademarks of their respective owners. Google is a trademark of Google LLC. Microsoft is a trademark of Microsoft Corporation. OpenAI and ChatGPT are trademarks of OpenAI. Anthropic and Claude are trademarks of Anthropic, PBC. Perplexity is a trademark of Perplexity AI. Apple is a trademark of Apple Inc. Amazon is a trademark of Amazon.com, Inc. This plugin is not affiliated with, sponsored by, or endorsed by any of these companies. Their names are used only to identify the services and crawlers the plugin works with.

== Third-party assets ==

This plugin bundles the Inter typeface (weights 400, 500, and 600) for its admin screens. Inter is licensed under the SIL Open Font License 1.1, which is compatible with the GPL. The full license is included at assets/fonts/Inter-LICENSE.txt.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install the ZIP from Plugins, then Add New, then Upload.
2. Activate the plugin. WooCommerce is optional; with it active you also get the product optimization suite.
3. Optional: to use article generation or AI search research, go to **ACE**, then **Connection**, paste your ACE API key, and click **Connect**. Everything else already works.
4. Open **Content** to generate your first article, **AI Search** to set up research and see crawler analytics, or (with WooCommerce) **Products** to sync and enrich your catalog.

For best results on production sites, configure a real system cron for WordPress so background polling, syncing, and the daily crawler maintenance run on time.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. On a plain WordPress site you get article generation and publishing, the AI search research console, and the crawler analytics. The product sync, enrichment, review, and feeds screens are available when WooCommerce is active.

= Do I need anything other than an API key? =

No. Paste your ACE API key and connect. That is all.

= What does the crawler tracker store? =

Only the date, the crawler name, the page path, and a hit count, in a table in your own database. It never stores IP addresses, full user agents, query strings, or anything about logged-in users. Sites behind a full-page cache will undercount, because cached pages are served without reaching WordPress.

= Will it overwrite my content without asking? =

No. Articles are created as drafts by default, and product suggestions wait in the Review screen until you approve them (unless you turn on auto-apply). You can revert any applied product.

= Does it work with Yoast, Rank Math, or AIOSEO? =

Yes. SEO titles and meta descriptions are written to the active SEO plugin's fields, and ACE suppresses its own schema output when your SEO plugin already emits it.

= Where are my product feeds? =

With WooCommerce active, the Settings screen lists the public feed URLs for Google, Microsoft, and Agentic Commerce. Each one streams your enriched products so it stays fast on large catalogs.

= Will it handle thousands of products? =

Yes. Syncing, enrichment, review, the served feeds, and the auto-apply sweep all work in batches so the plugin stays responsive and does not time out on large stores.

== Screenshots ==

1. The dashboard: credits, optimization coverage, articles, AI crawler hits, and share of brand mentions.
2. The Content screen generating an article with a live progress ring.
3. The AI Search overview with share of answers, competitors, and top cited domains.
4. The crawler analytics tab with per-bot trends, top pages, and the day-by-day heatmap.
5. The Products screen with the content-type picker and category filter.
6. The Review screen showing a before/after diff for each content type.
7. The research run configurator with prompt suggestions and engine selection.
8. The Settings screen with publishing, automation, and crawler tracking options.

== Changelog ==

= 4.3.1 =
* A fresh install now opens on things to do, not on setup. The Dashboard leads with a dismissible "Start here" card of local actions (crawler analytics, catalog browsing, feed URLs, publishing preferences), tailored to whether WooCommerce is active.
* No screen mentions an ACE account on a fresh install. The optional connect notice appears only after the plugin's own crawler analytics have recorded meaningful AI traffic, and it opens with what was measured. Nothing changes about what works: every local feature is on from activation, with or without an account, forever.
* Uninstall now also removes the plugin's per-user dismissal preferences.
* All request input is now read, unslashed, and sanitized directly inside each admin-ajax handler, immediately after its nonce check. Helper methods receive plain values and never touch request data, and no static-analysis suppressions for nonce verification remain anywhere in the plugin.
* Admin scripts and styles are now enqueued from the screen hook suffix WordPress passes to the enqueue callback instead of reading the request URL.
* Product pages no longer carry duplicate structured data: when the plugin outputs its enhanced JSON-LD graph, WooCommerce core's copies of the same entity types (Product, BreadcrumbList) are suppressed, while Woo entities the plugin does not emit are kept.
* Renamed the internal account-link field in error payloads.
* The CSV export no longer raises PHP 8.4+ deprecation notices.
* Bundled the full GPLv2 license text.

= 4.2.0 =
* Every admin screen now renders without an ACE account. Nothing the plugin does on your own site is held back: AI crawler tracking and its settings, publishing preferences, Article and Product JSON-LD, the Published articles list, the product catalog and its export, and the full review queue all work on activation.
* Only the controls that call the ACE service (article generation, product enrichment, research runs, scheduled sync, auto-apply, and the optional daily crawler report) ask for an account, and they now say so in place instead of replacing the screen.
* The API key has moved to its own ACE, then Connection screen. Settings now opens directly on the AI crawler tracking and publishing options, which work straight after activation.
* The dashboard leads with the numbers read from your own site: AI crawler hits and article counts.
* The notice about connecting an account can be dismissed and stays dismissed.
* Nonce verification is an explicit first statement in every admin-ajax handler, checked before any input is read and before any other condition.
* admin-ajax handlers are no longer registered on front-end page loads.
* Corrected the terms of service and privacy policy links in this readme.

= 4.1.0 =
* Product feeds now require a private per-site token in their URLs, with one-click regeneration in Settings, and support conditional requests (Last-Modified/304) so unchanged catalogs cost nothing to re-fetch.
* Admin screens render instantly from cache and fill in live numbers after paint. No wp-admin page load ever waits on the ACE platform, and during an outage the dashboard shows your last known credit balance instead of zeros.
* Hardened the Product JSON-LD output against markup breakout, matching the Article emitter.
* Storefront shortcodes renamed to [agencoen_irl] and [agencoen_internal_links].
* Interactive requests to the platform now fail fast (12 seconds, no retries) while background jobs keep patient retries. Faster AI crawler detection on busy sites.

= 4.0.0 =
* WooCommerce is now optional: the plugin activates on any WordPress site, with the commerce suite available when WooCommerce is active.
* New Content screen: generate long-form articles with your brand voice (built-in, preset, and custom content types), then publish them as posts with SEO meta and Article JSON-LD. Two-way sync with the ACE Content Hub.
* New AI Search console: manage research prompts (with AI suggestions from your catalog), launch runs across AI answer engines, and explore share of answers, competitors, per-prompt citations, and trends.
* New native AI crawler analytics: privacy-safe tracking of GPTBot, ClaudeBot, PerplexityBot, and 20+ other AI crawlers with charts, top pages, and an optional daily report to ACE.
* Redesigned admin matching the ACE app: KPI tiles, segmented tabs, and a dependency-free SVG chart kit (lines, areas, donuts, heatmaps, progress rings).
* Product enrichment now runs on the platform's durable jobs API with webhook-accelerated polling.

== Upgrade Notice ==

= 4.3.1 =
Internal security hardening: every admin-ajax handler now reads and sanitizes its own input right after its nonce check. No action needed on update.

= 4.2.0 =
Every screen and every local feature now works without an ACE account. The API key has moved to its own Connection screen. No action needed on update.

= 4.1.0 =
Feed URLs now carry a private token: copy the new URLs from Settings into Google and Microsoft after updating. Storefront shortcodes are renamed to [agencoen_irl] and [agencoen_internal_links]. Admin screens no longer wait on the platform to render.
