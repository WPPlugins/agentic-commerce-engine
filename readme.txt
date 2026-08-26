=== Agentic Commerce Engine ===
Contributors: authoritas
Tags: ai, seo, woocommerce, ai search, content
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 4.8.0
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
* **What is sent for visitor pageviews (optional, off by default):** one row per published page per day: the page id, the date, the number of views, and the referring site's domain for visits arriving from search engines and AI assistants. No IP addresses, no cookies, no session identifiers, and nothing that could identify an individual visitor. Because nothing identifies a visitor, this reports pageviews and cannot report unique visitors.
* **What is sent for WooCommerce sales (optional, off by default, same setting):** one row per product per day: the product id, units sold, and net revenue. No orders, no customers, no names, no email addresses, no postal addresses.
* **What is sent for A/B testing (optional, off by default):** which version of a product description a visitor was shown, which test that version belonged to, and whether that visitor reached checkout, against a scrambled visitor id. This is the ONLY feature in the plugin that stores a cookie: to show a visitor the same version twice, a random id is kept in their browser. It does nothing at all without the visitor's consent, read through the WordPress Consent API, so your cookie banner decides; with no consent plugin installed nothing is stored and every visitor sees your normal copy. The id is scrambled with a secret unique to your site before anything is sent, so it cannot be linked back to a person, and the same visitor on another site produces a completely different value. No IP addresses, user agents, names, email addresses, order details or customer records are sent. Search engines and AI crawlers are never put into a test, because they carry no cookie and give no consent.
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

= 4.8.0 =
* New: a History tab. Every test you have finished, what it found, and what it was worth, with a place to write down what you learned from each one. Filter by outcome, search by name.
* New: what testing has been worth to your shop, above that list. It counts two things: money won by keeping a version that beat your original, and money kept by not rolling out a version that lost. The second is usually the larger of the two, and both are counted only where a test reached a clear answer and your store reported real order values.
* New: a test that finished by showing your original copy was better now records what not rolling out the challenger was worth. Previously only winners carried a figure, so half of what testing gives you was invisible.
* Fixed: a lift shown on a finished test could be understated. The figure is stored on a logarithmic scale and one of the places that read it was treating it as a plain percentage.

= 4.7.3 =
* New: an experiment now shows what each version is worth. Revenue per shopper over time, a table per version, and what keeping the winner would be worth over a year. It appears only when your store actually reported order values, so a missing panel means revenue is not being tracked rather than that you earned nothing.
* Fixed: the progress step for going live was called "Versions live", the same name as one of the trust checks below it, and the two answer different questions. It is now "Live on your store".
* Fixed: the last two dates on a chart's bottom axis could overlap into one another.

= 4.7.2 =
* Fixed: on the New experiment tab, "Check this design" could appear to do nothing. When the answer was a refusal, such as no products having reached ACE yet, it flashed in a notice that disappeared after a few seconds. The reason now stays on the page until you change the design.

= 4.7.1 =
* The whole plugin is now translatable. Around 120 strings across the AI Search, Content and Experiments screens were printed in English whatever your site language, because they were never passed through WordPress translation. They are now, and a test stops the same thing happening again.
* Fixed: on an experiment, the progress strip could say every product had a second version live while the checks below said fewer than two versions were live. Both were measuring something real and neither said which. The strip now waits for the same thing the check waits for, and explains that only one version being live means both groups read the same words.
* Fixed: the "% live" dial on an experiment had its label cut off at both ends.
* Fixed: a range read "likely between +6.1% to +76.9%".

= 4.7.0 =
* New: the Experiments screen now shows you what is happening, not just what happened. A chart of how each version is doing day by day, a range that visibly narrows as shoppers arrive, and a projected date when the result will be readable.
* New: a plain-English verdict at the top of every test. It says whether you have a winner, whether to keep waiting, or whether something is wrong that would make the numbers meaningless, and it will not call a winner while a check is failing.
* New: keep the winning version for every shopper with one button. It finishes the test, returns everyone to a single version, and queues the winning copy to replace your original.
* New: a five-step progress strip that names whatever is holding a test up, so "I set this up and nothing is happening" has an answer on the screen.
* New: a drop-off chart per version, so you can see whether a version wins at the basket and loses at checkout rather than reading one number.
* New: before you commit credits, the setup screen draws how small a change your traffic could actually detect against how long the test runs. More versions means a longer wait, and now you can see it.
* New: a short glossary of the five terms this screen cannot avoid using.
* Fixed: a chart line no longer drops to zero on a day your site reported nothing. A gap in the data now reads as a gap.
* Fixed: a finished test now takes its settings off your products instead of leaving them there.

= 4.6.1 =
* Fixed: the Experiments screen was blank. It loaded its tabs and then stopped before asking for your tests, so the page sat empty with nothing to click and no error to explain it. It now lists your experiments as intended.
* Fixed: status labels on that screen showed an internal word instead of the real one, and a failed check was coloured the same calm grey as one that passed. A problem with a running test now looks like a problem.

= 4.6.0 =
* New: an Experiments screen. Set up a test, see which version is winning, and start, pause or stop it, without leaving WordPress. Needs WooCommerce.
* Before you commit any credits, the screen tells you whether your traffic can actually settle the test you have designed. An answer that would never arrive is refused up front rather than after four weeks.
* Setting up a test does not switch on visitor splitting. That stays a separate, deliberate choice in Settings, and it stays off until you make it.
* Fixed: content you approve in ACE now actually reaches this site, and ACE is told when it does. Until now the plugin applied only what it had generated locally and never reported the result, so ACE had no record of anything this site published.
* This is why A/B testing could not run here. On the ACE side a version counts as live only once it has been reported as published, so tests set up for a WooCommerce store never received their settings and never started. Nothing looked wrong from either end.
* Your Published view in ACE will now show WooCommerce alongside other channels, rather than being empty for stores that had in fact published.
* Checking happens hourly rather than daily, so approving a version in ACE and seeing it live on your store is a matter of minutes rather than the next day.
* No new data leaves your site. The report says which item was applied and whether it worked, and nothing else.

= 4.5.0 =
* New: optional per-visitor A/B testing of product descriptions, so an experiment set up in ACE can run on this site and be measured against real customers rather than only against crawler activity. Off by default on every site, including upgrades.
* This is the only feature in the plugin that stores a cookie, and it is gated on the WordPress Consent API rather than on a setting alone. With no consent plugin installed it does nothing: nothing is stored, nothing is sent, and every visitor sees your normal copy.
* The version a visitor sees is decided in the browser, not on the server. On a cached site WordPress often does not run at all, so a server-rendered version would be written into the page cache and then served to everyone, including crawlers. Your page therefore always contains exactly one description, and a visitor in a test fetches theirs separately.
* Crawlers are never put into a test. They carry no cookie and give no consent, which is the same rule applied to a first-time visitor: no user agent is examined anywhere.
* Visitor ids are scrambled with a secret generated for your site alone before anything leaves it. The same visitor on another site produces an unrelated value, so nothing can be joined across sites.
* New setting for the description block's CSS selector, for themes that do not use the standard WooCommerce layout. A selector that matches nothing means the test changes nothing, so this is worth checking before starting one.

= 4.4.0 =
* New: optional visitor pageview counting, so ACE can show what a content change did to real traffic rather than only to AI crawlers. Off by default, with its own setting separate from the crawler report.
* Counting uses a small script on published pages rather than server-side hooks. Page caches serve most visitors without WordPress running at all, so a server-side counter would undercount heavily on exactly the sites that are busiest.
* No cookie, no session, no IP address, hashed or otherwise. Nothing stored can identify a visitor, which is why this reports pageviews and does not claim to report unique visitors. Logged-in editors and administrators are never counted, and neither are crawlers.
* New: optional daily WooCommerce sales totals, one row per product per day with units and net revenue, read from WooCommerce's own analytics tables so the figures match your Analytics screen. No orders, customers, names, email addresses or postal addresses are sent.
* The crawler setting's description now points at the new pageview setting instead of stating that human traffic is never logged, which is only true while that setting is off.

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

= 4.8.0 =
Adds a History tab: every test you have finished, what it found, and what it was worth. It now also counts money kept by not rolling out a version that lost, which was previously invisible. Fixes a lift figure that could read lower than it should on a finished test.

= 4.7.3 =
Experiments now show revenue per version, and two labelling fixes.

= 4.7.2 =
"Check this design" no longer appears to do nothing when the answer is a refusal.

= 4.7.1 =
The plugin is now fully translatable, and an experiment's progress strip no longer contradicts its own checks.

= 4.7.0 =
Experiments can now be read at a glance: charts of how each version is doing, a plain verdict, and one button to keep the winner for everyone.

= 4.6.1 =
Fixes a blank Experiments screen in 4.6.0. If you opened Experiments and saw nothing, this is the fix. Nothing else changes and no setting is touched.

= 4.6.0 =
Adds an Experiments screen for WooCommerce stores, and fixes the reason tests could not run: content approved in ACE now reaches this site, and ACE is told when it lands. This is what A/B testing needed in order to run here at all. Nothing new is collected and no setting changes.

= 4.5.0 =
Adds optional per-visitor A/B testing. It is off by default and stays off after this upgrade. It is the only part of this plugin that stores a cookie, and it does nothing without visitor consent through the WordPress Consent API.

= 4.4.0 =
Adds optional visitor pageview counting and daily WooCommerce sales totals. Both are off by default and neither collects anything that identifies a visitor.

= 4.3.1 =
Internal security hardening: every admin-ajax handler now reads and sanitizes its own input right after its nonce check. No action needed on update.

= 4.2.0 =
Every screen and every local feature now works without an ACE account. The API key has moved to its own Connection screen. No action needed on update.

= 4.1.0 =
Feed URLs now carry a private token: copy the new URLs from Settings into Google and Microsoft after updating. Storefront shortcodes are renamed to [agencoen_irl] and [agencoen_internal_links]. Admin screens no longer wait on the platform to render.
