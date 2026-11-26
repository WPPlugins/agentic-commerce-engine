=== Rezolve Ai ===
Contributors: authoritas
Tags: ai, seo, woocommerce, ai search, content
Requires at least: 6.5
Tested up to: 7.2
Requires PHP: 8.0
Stable tag: 4.34.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bring Rezolve Ai to WordPress: content writing, AI search visibility research, native AI crawler analytics, and full WooCommerce product optimization.

== Description ==

Rezolve Ai connects your WordPress site to the Rezolve Ai platform and puts the whole product in your dashboard. The plugin is free and fully functional on its own; article generation and AI search research additionally use the Rezolve Ai service. WooCommerce is optional: content, AI search research, and crawler analytics work on any WordPress site, and the full product optimization suite is available when WooCommerce is active.

**Content (any WordPress site)**

* **Generate long-form articles** in wp-admin: pick a content type (built-in layouts, a preset library, or your project's custom types), a topic, keywords, and a locale, and Rezolve Ai writes the article with your brand voice.
* **Publish as posts** with one click: articles land as drafts (or publish immediately), as Gutenberg blocks or classic HTML, with SEO title and meta description written to your active SEO plugin and Article JSON-LD attached.
* **Two-way with the Rezolve Ai app:** articles you generate here appear in the Rezolve Ai Content Hub, and briefs written in the app can be published to WordPress from the plugin.

**AI Search research (any WordPress site)**

* **Launch research runs** against the AI answer engines (ChatGPT, Perplexity, Gemini, and more) straight from wp-admin: manage your prompt set, get AI-suggested prompts from your own catalog, pick engines and markets, and launch.
* **Rich dashboards:** share of brand mentions, share of answers, competitor comparison, top cited domains, per-prompt results with citations, and trends across runs.

**AI crawler analytics (any WordPress site, works before you even connect)**

* **Native tracking** of AI crawlers hitting your site: GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, PerplexityBot, Google-Extended, Applebot-Extended, Amazonbot, Bytespider, CCBot, and more.
* **Privacy-safe by design:** only the date, the crawler name, the page path, and a count are stored, in your own database. No IP addresses, no full user agents, no query strings, nothing about logged-in users.
* **Charts built in:** trend by crawler, top crawled pages, and a day-by-day heatmap. Optionally report daily aggregates back to Rezolve Ai to see them alongside your other channels.

**Product optimization (with WooCommerce)**

* **Sync** your products to Rezolve Ai in batches, safely, even on catalogs of thousands of products.
* **Enrich** products with AI across every content type: titles, descriptions, SEO meta, Product schema, real-life usage scenarios, internal links, highlights, product Q&A, product attributes, image alt text, and channel feeds.
* **Review** every suggestion with a clear before/after diff and the quality scores Rezolve Ai measured for it (content quality, SEO, AI readability and brand voice), next to the product's images and a link to its live page. Approve per product or in bulk, or turn on auto-apply. Revert any product with one click.
* **Display** the new on-page content with blocks, shortcodes ([agencoen_irl], [agencoen_internal_links], [agencoen_highlights], [agencoen_product_qa]), or automatic placement on the product page.
* **Serve** Google, Microsoft, and Agentic Commerce product feeds straight from your store as feed URLs.

**Built for scale**

Product lists, review, and the served feeds page through your catalog rather than loading it all at once. Enrichment and article generation run as durable jobs on the platform and are polled in the background, so nothing depends on a browser tab staying open. Crawler tracking writes one tiny row per bot hit and prunes itself on a schedule.

== External services ==

This plugin connects to the Rezolve Ai platform, operated by Rezolve Ai Limited, to perform work that cannot be done on your own server: large language model content generation and queries against third-party AI answer engines.

**What works without any account or connection**

Every feature the plugin implements locally is available immediately on activation, with no key, no sign-up, and no account of any kind:

* AI crawler analytics: tracking, path storage, retention, and all of the charts and heatmaps on the AI Search screen.
* Publishing preferences: default post status, block or classic format, default author, and Article JSON-LD output.
* The Content screen's Published tab, listing and linking to the Rezolve Ai articles already in your WordPress database.
* Browsing, searching, filtering and exporting your WooCommerce catalog, and the whole review queue including approve, revert, and applying stored suggestions to your products.
* Product JSON-LD, the on-page blocks and shortcodes, the product feed URLs, and debug logging.

**What needs the Rezolve Ai service**

Article generation, product enrichment, AI search research runs, credit balances, scheduled catalog sync, auto-apply, and the optional daily crawler report. Writing an article or querying an AI answer engine is work your own server cannot do, so these send a request to Rezolve Ai and ask for an account. Nothing else is withheld, and none of the features listed above are affected.

* **What is sent on connect:** your site URL and title, the WordPress version, the WooCommerce version when present, and the plugin version. This registers your site and returns a store ID and a webhook secret.
* **What is sent on sync and enrich (WooCommerce):** product data including title, description, short description, SKU, price, stock status, image URLs, categories, tags, and variation IDs, plus the content types you selected.
* **Custom fields sent on sync (WooCommerce, from 4.24.0):** the product content you keep outside the main fields, so Rezolve Ai can write from it: ACF field values, public custom fields (post meta), product attributes, custom product tabs, and custom product taxonomies such as brand. Fields that look private are never sent: protected meta (keys beginning with an underscore), and anything named like a cost, margin, supplier detail, key, token, password, email address, phone number or bank detail. Fields you leave out in Settings, under Custom fields, are never sent either. Developers can change what is sent with the `agencoen_custom_fields` and `agencoen_custom_field_is_private` filters.
* **What is sent for visitor pageviews (optional, off by default):** one row per published page per day: the page id, the date, the number of views, and the referring site's domain for visits arriving from search engines and AI assistants. No IP addresses, no cookies, no session identifiers, and nothing that could identify an individual visitor. Because nothing identifies a visitor, this reports pageviews and cannot report unique visitors.
* **What is sent for WooCommerce sales (optional, off by default, same setting):** one row per product per day: the product id, units sold, and net revenue. No orders, no customers, no names, no email addresses, no postal addresses.
* **What is sent for orders from AI shopping agents (from 4.31.0, same setting):** when a shopping agent sends a shopper with a buy link from Rezolve Ai, the link carries a short reference that Rezolve Ai issued and that names no product, agent or person. The plugin keeps it with the cart and on the order, and the daily report sends, per day and per reference: the number of visits the link opened, the number of orders, and each product's id, units and net revenue. No order numbers, customers, names, email addresses or postal addresses are sent.
* **What is sent for A/B testing (optional, off by default):** which version of a product description a visitor was shown, which test that version belonged to, and whether that visitor reached checkout, against a scrambled visitor id. This is the ONLY feature in the plugin that stores a cookie: to show a visitor the same version twice, a random id is kept in their browser. It does nothing at all without the visitor's consent, read through the WordPress Consent API, so your cookie banner decides; with no consent plugin installed nothing is stored and every visitor sees your normal copy. The id is scrambled with a secret unique to your site before anything is sent, so it cannot be linked back to a person, and the same visitor on another site produces a completely different value. No IP addresses, user agents, names, email addresses, order details or customer records are sent. Search engines and AI crawlers are never put into a test, because they carry no cookie and give no consent.
* **What is sent for article generation:** the title, topic, keywords, locale, and options you enter, and optionally the products you link. The platform uses your project's brand voice and identity to write the article.
* **What is sent for AI search research:** the research prompts you save, the engines and markets you pick, and, when you use prompt suggestions, catalog snippets (product titles, categories, tags). Research runs are executed by the Authoritas AI search platform.
* **What is sent on heartbeat:** a periodic health ping containing your site URL, product counts, and version numbers, and from 4.33.0 your store's country as set in WooCommerce (Settings, General, Store Address). The country lets Rezolve Ai state where you sell from in your OpenAI product file. Nothing is sent for it when no country is set.
* **Policy pages (WooCommerce, from 4.34.0):** with the heartbeat, at most once a day unless one changes, the public addresses of your privacy policy page (Settings, Privacy), your terms and conditions page and your refund and returns page (both under WooCommerce, Settings, Advanced). Rezolve Ai states them in the seller columns of your ChatGPT product file. Only the page addresses are sent, never their text, and only for pages that are published, not password protected, and not empty. Nothing is said about whether you accept returns, because WooCommerce has no setting that states it.
* **Optional crawler report:** if you opt in, a daily summary of AI crawler hits (date, crawler name, page paths, counts) is sent to Rezolve Ai. This is off by default and never includes IP addresses or user agents. With the report on, requests from AI agents that sign them (Web Bot Auth, used by agents such as ChatGPT's) are also sent so Rezolve Ai can check each signature against the keys the agent publishes: the time, method, host, path and query, and the signature headers the agent sent with any other header its signature covers. At most 200 a day. Never IP addresses, user agents, cookies or authorization headers.
* **AI processing:** the platform generates content using large language models, including models provided by OpenAI. Data you send is processed by these models to produce the results.
* **Rezolve Ai terms of service:** https://ace.authoritas.com/terms
* **Rezolve Ai privacy policy:** https://ace.authoritas.com/privacy
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
3. Optional: to use article generation or AI search research, go to **Rezolve Ai**, then **Connection**, paste your Rezolve Ai API key, and click **Connect**. Everything else already works.
4. Open **Content** to generate your first article, **AI Search** to set up research and see crawler analytics, or (with WooCommerce) **Products** to sync and enrich your catalog.

For best results on production sites, configure a real system cron for WordPress so background polling, syncing, and the daily crawler maintenance run on time.

== Frequently Asked Questions ==

= Do I need WooCommerce? =

No. On a plain WordPress site you get article generation and publishing, the AI search research console, and the crawler analytics. The product sync, enrichment, review, and feeds screens are available when WooCommerce is active.

= Do I need anything other than an API key? =

No. Paste your Rezolve Ai API key and connect. That is all.

= What does the crawler tracker store? =

Only the date, the crawler name, the page path, and a hit count, in a table in your own database. It never stores IP addresses, full user agents, query strings, or anything about logged-in users. Sites behind a full-page cache will undercount, because cached pages are served without reaching WordPress.

= Will it overwrite my content without asking? =

No. Articles are created as drafts by default, and product suggestions wait in the Review screen until you approve them (unless you turn on auto-apply). You can revert any applied product.

= Does it work with Yoast, Rank Math, or AIOSEO? =

Yes. SEO titles and meta descriptions are written to the active SEO plugin's fields, and Rezolve Ai suppresses its own schema output when your SEO plugin already emits it.

= Where are my product feeds? =

With WooCommerce active, the Settings screen lists the public feed URLs for Google, Microsoft, and Agentic Commerce. Each one streams your enriched products so it stays fast on large catalogs. The Agentic Commerce feed is for other AI shopping agents; ChatGPT reads the ChatGPT product file instead (see the next question).

= How do I get my products into ChatGPT? =

Connect your site to Rezolve Ai and sync your catalog. Rezolve Ai then builds a ChatGPT product file from it, in the format OpenAI asks for, and keeps it up to date. Open **Rezolve Ai, Settings** and copy the URL under ChatGPT product file. In ChatGPT Business Hub, claim your business and verify your identity, then add that URL as a hosted URL for your products. ChatGPT reads the whole file from that address, so there is nothing to upload again.

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

= 4.34.3 =
* Changed: tested with WooCommerce 11.3, so WooCommerce no longer lists the plugin as untested on current versions.

= 4.34.2 =
* Fixed: the four Rezolve Ai blocks (IRL scenario cards, Internal links, Highlights, Product Q&A) use the current block API, so the block editor no longer warns that they are deprecated. In the editor each block can now be selected like any other, and its preview uses the same styles as your storefront.

= 4.34.1 =
* Fixed: three screen messages that include a number or a name (products left out of enrichment, the brand voice you are writing as, and an article's word count) now tell translators what fills them in, so translated screens read correctly.
* Changed: tested with WordPress 7.2, and the plugin passes WordPress Plugin Check with no errors.

= 4.34.0 =
* New: Settings shows your ChatGPT product file URL, with a Copy button and the steps to add it in ChatGPT Business Hub. Rezolve Ai builds the file from your synced catalog in the format OpenAI asks for, so ChatGPT shopping can list your products. The Agentic Commerce feed under Channel feeds is now labelled as being for other AI agents, because ChatGPT does not read it.
* New: your ChatGPT product file names your privacy policy, terms and conditions, and refund and returns pages. The plugin sends their public addresses with the heartbeat, only for pages that are published and have text in them. Whether you accept returns is never guessed from a page.

= 4.33.0 =
* Added: the hourly heartbeat sends your store's country, as set in WooCommerce under Settings, General, Store Address, so your OpenAI product file can say where you sell from. Nothing is sent for it when no country is set.
* Changed: some Dashboard and Settings wording describes the content Rezolve Ai writes plainly, for example "Auto-apply suggestions without review".
* Changed: a product split's chart draws what the test is measured on. A test read on Shopping impressions, clicks, orders, revenue or AI crawler pulls used to show a visits chart above its result; the chart, its title and the text above it now name the test's own goal. A test read on visits looks the same as before.

= 4.32.1 =
* Fixed: a product's structured data no longer claims a price is valid for another year. That date was made up, not read from the store. A running sale with an end date now states the day it ends, and any other price states no end date.
* Fixed: a stock status the plugin does not recognise, such as one added by another extension, was reported to search engines as in stock. Availability is now left out instead.

= 4.32.0 =
* New: AI agents that sign their requests are checked. Some agents, such as ChatGPT's agent and part of Google's, sign every request with Web Bot Auth, so they can be told apart from anything that copies their name. With the daily crawler report on, the plugin keeps such requests as they arrived, at most 200 a day, and sends them with the report; Rezolve Ai checks each signature against the keys the agent publishes and shows the verified agents under Signed agents in AI crawler activity. No IP addresses, user agents, cookies or authorization headers are kept or sent.

= 4.31.1 =
* Changed: a product split's visits chart shows each group's average visits per product, so the bigger group no longer looks ahead before the test has started. The days before the test are shaded and labelled, the day it started is marked, and the text above the chart says which days are the baseline and what to watch once the test is running. The legend says how many products are in each group, and dates read as short days that no longer crowd on a narrow screen.

= 4.31.0 =
* New: orders sent by AI shopping agents are counted. A buy link Rezolve Ai hands a shopping agent carries a short reference. When a shopper opens it, the plugin keeps the reference with the cart and saves it on the order, in both the classic and the block checkout. With daily sales totals turned on, the report adds the visits those links opened and the orders and revenue they produced, so Rezolve Ai can show what agents sold apart from people clicking through from AI assistants. No order numbers, customers or email addresses are sent.
* New: when you create a product split, choose how many products to hold back unchanged: Choose for me, Half, or About a fifth. The products step says how many that will be.
* Changed: a running product split says how long is left (for example, "About 48 days to go") and shows how many products are in each group, instead of the shopper panels it never uses.
* Fixed: a product split whose new content is already on your store no longer reads as having no version live, and the screen now says the new content is live.

= 4.30.1 =
* Changed: Edit live on a product in the control group of a product split is refused while the experiment runs, with the same Held back line Review shows for Approve. A live edit writes to the product at once, so it would change the products the experiment compares against. Edits to suggestions still waiting in Review are saved as usual. If Rezolve Ai cannot be asked, the edit is not saved and you are asked to try again.

= 4.30.0 =
* Changed: a product in the control group of a product split is no longer applied while the experiment runs. Approve, Approve all and auto-apply leave it waiting in Review, and the product says which experiment holds it back and until when. Once the experiment releases it, it can be approved as usual.
* Changed: Approve all says how many products it left waiting because an experiment holds them back. If Rezolve Ai cannot be asked, nothing is applied and you are asked to try again.

= 4.29.0 =
* New: an experiment can watch other goals beside its main one. When you split products, tick Added to cart, Orders, Revenue or Visits under Also measure on the New experiment form. The main goal still decides the result.
* New: the experiment page shows an Also measured table after What it found, with each goal's change and its range, or a sentence saying why there is no figure yet (for example, no orders on those products before the test began).

= 4.28.0 =
* New: content is written in your store's own format. Rezolve Ai learns how your product descriptions and custom fields are written from your own products (the parts, their order, the formatting and the length) and writes new descriptions and rewrites the same way, keeping your details lists and links exactly as they are.
* New: Settings, Field formats shows what was learned for each field. Pick a preset or Rezolve Ai's own layout instead, choose which parts stay exactly as they are, and preview a format on one of your products for free.
* New: Your own template on the Field formats card. Build a field's format from parts (a details list, paragraphs, bullet points, a heading, questions and answers, your closing sentence) in the order you want, each with its own counts and style. It starts like your store's current format, and the free preview follows every change.
* New: fill a custom field where a product has none. Turn on Fill when empty for an ACF field or a custom field, see how many products and about how many credits first, and review every fill before it is published. A field with a list of choices is filled with one of its choices, and Revert removes a fill again.
* Changed: an enriched description is published with its layout (details lists, paragraphs and links) instead of as plain text.
* Changed: Review shows how a description in your store's format will look, and labels a filled field as new, with Check the facts on stories and meanings.
* Changed: after the update your catalog is synced once in the background, so Rezolve Ai can see the choices of your select, radio and button group fields. This does not enrich or charge for anything.

= 4.27.0 =
* Changed: the admin screens work from the keyboard and with a screen reader throughout, following WCAG 2.2 Level A and AA.
* Changed: the content type picker keeps focus inside it while it is open, closes with Escape, and puts you back on the button that opened it.
* Changed: help tooltips open on focus and on a click or tap as well as on hover, stay open while you move onto them, and close with Escape. Each help button is named after the chart it explains.
* Changed: tabs move with the arrow keys, Home and End, and each tab is tied to the panel it shows. The Products status filters work the same way.
* New: every chart has a short spoken summary and a Show data table button that lists all of its numbers, so no value is only in a hover tooltip.
* Fixed: every search box, filter and field has a label, each screen has one main heading, tables have column headers and captions, and the Review expand buttons name their product and say whether they are open.
* Fixed: connection errors, batch progress and loading placeholders are announced, a button you press keeps keyboard focus while it works, and a message that carries a link stays on screen until you close it.
* Fixed: field edges, focus rings, chart labels and small text meet the contrast minimums, the help and expand buttons are larger, and animations stop when your system asks for reduced motion.
* Fixed: on the storefront, related product links and product questions show a visible focus ring, and the link outline follows your theme's text colour.

= 4.26.0 =
* Changed: products that a running experiment is measuring are left out of an enrichment run, so their content does not change in the middle of a test. The confirmation says how many were left out, and those products go back to Not enriched instead of waiting at Queued.

= 4.25.2 =
* Changed: AI Search no longer shows an Estimate credits button. Research runs do not use your Rezolve Ai credits, so there was no cost to estimate, and the screen now says so.

= 4.25.1 =
* Fixed: content sent to a field your theme shows as plain text, such as the short description in a block theme, keeps a space between its items instead of running them together.

= 4.25.0 =
* New: choose where each kind of generated content goes. Product Q&A, highlights, IRL scenario cards, internal links and product specifications can stay in the Rezolve Ai block, go into one of your own fields (an ACF text area or editor, a custom field, or the short description), or be added as a section at the start or end of the product description. Set it in Settings, Where content goes; the same choice is shared with the Rezolve Ai web app.
* New: the Rezolve Ai block can sit in a product tab of its own, next to Description and Additional information.
* Changed: a field you send content to is replaced when that content is published, and Revert on the Review screen puts back what it held. Rezolve Ai stops reading that field as product information, so it never feeds its own output back in.
* Changed: a new or reverted product description keeps the Rezolve Ai sections you chose to put in it.

= 4.24.4 =
* Fixed: on Review, a rewritten custom field keeps its lines. Care instructions and other fields written on several lines no longer run together, and a field that holds formatted text reads as paragraphs.

= 4.24.3 =
* Fixed: after an update, Rezolve Ai learns the new version straight away, so features that need it (such as rewriting your custom fields) are offered at once instead of up to an hour later.
* Fixed: turning Rewrite on for a custom field now offers "Your custom fields" in the enrichment picker right away.
* Fixed: the quality scores on Review name the custom fields group properly.

= 4.24.2 =
* Fixed: the Rewrite switches in the Custom fields table now draw as switches. WordPress's own checkbox styles had turned them into small squares.

= 4.24.1 =
* Changed: the Custom fields card in Settings is now a table you can search and filter by status (used, rewriting, left out, not used), 25 fields to a page, with a switch to turn rewriting on or off for each field that can be rewritten.
* Added: select several custom fields and leave them out, or use them again, in one step.
* Changed: bringing a field back now says your catalog is resyncing, so you know when it reaches every product.
* Fixed: an empty selection bar no longer shows when nothing is selected.

= 4.24.0 =
* Added: Rezolve Ai now writes from the content you keep outside the main product fields: ACF fields, custom fields, product attributes, custom product tabs and custom taxonomies such as brand. Fields that look private (costs, supplier details, keys, passwords, email addresses, phone numbers) are never sent.
* Added: a Custom fields card in Settings lists every field found on your products, what each is used for, and lets you leave any of them out.
* Added: let Rezolve Ai rewrite a custom field you choose, such as care instructions, and write it back after you approve it. A field you change after the suggestion is written is left as it is, and Revert restores the original.
* Changed: the product's short description is now sent alongside its description instead of only when the description is empty.
* Upgrading sends your catalog once more in the background so its custom fields reach Rezolve Ai. This does not generate or charge for anything by itself.

= 4.23.0 =
* Added: choose the products a test runs over by category, collection, product type, brand or tag, instead of one category at a time. Picking values inside one filter means any of them; picking across two filters means both.
* Added: describe the products you want to test in your own words and Rezolve Ai suggests a set from your own catalog, which you can edit before you use it.
* Added: search your catalog to add a product the filters missed, or take one out, and the count updates as you go.
* Added: save a selection under a name and reuse it on the next test. A saved group re-reads your catalog each time, so products added since you saved it are included.
* Changed: the product count and the size of the control group now come from Rezolve Ai rather than being worked out in your browser, so the number you see is the number the test will use. A selection too small for a control group now says so before you start, not after.

= 4.21.0 =
* Added: the Experiments list shows where each test is: its new versions being written, waiting for your review, on their way to your store, or the day of its planned run, with a progress bar wherever the count is known.
* Added: a test's own page draws that progress inside its current step. A step that is waiting on you says so in amber, with what to do.

= 4.20.1 =
* Fixed: an image with no alt text now reaches Rezolve Ai with none. It used to be sent with the product name in its place, so Rezolve Ai treated every such image as already described. After this update your catalog syncs once in the background to correct what was sent before.
* Added: when content goes live on your store, Rezolve Ai now receives the product's page, so its review queue can open the live product.

= 4.20.0 =
* Added: a View live page link on every product on the Review screen, waiting or applied. It opens the product's page on your store in a new tab, and appears once the product is published.
* Added: each product on the Review screen shows its main image. Open a product to see all of its images, the main image first and then the gallery, each with the alt text it has now. Select an image to open it at full size.

= 4.19.1 =
* Fixed: approving or dismissing content this site wrote itself no longer changes different content for the same product in Rezolve Ai. Only the content you actually decided on is marked approved, dismissed or live there.

= 4.19.0 =
* Added: test IRL scenario cards, highlights, questions and answers, product titles, schema markup, image alt text, product attributes and your shopping feeds, not only the description. Split products, so some get the new content and some are held back, and see which content changed sales.
* Added: orders and revenue as goals when you split products.
* Changed: the content, versions and goals the Experiments form offers now come from Rezolve Ai, so they stay current without updating the plugin.
* Changed: a result broken down by content type is labelled with that content type.
* Fixed: AI citations is no longer offered as a goal, because it cannot be measured yet.

= 4.18.1 =
* Fixed: an approval made in Rezolve Ai no longer waits for ever when your edit cannot be reported. After a day it applies, so one stuck edit cannot hold up everything else waiting to reach your products.
* Fixed: when applying a change to your product fails, your edited version is still sent to Rezolve Ai, so a later approval there cannot overwrite it.

= 4.18.0 =
* Added: edit suggestions before you approve them. On the Review screen, open a product and choose Edit on its title, description, SEO title, meta description, highlights, questions and answers, specifications or image alt text. Approving then applies your version.
* Added: edit content that is already live. Applied products on the Review screen can now be opened, and Edit live saves your change straight to the store for that one piece of content, leaving everything else as it is.
* Added: the description is edited in the WordPress classic editor. Highlights, answers, specifications and alt text are plain text, with character counts and the recommended lengths for titles, meta descriptions and alt text.
* Added: your edits reach Rezolve Ai too, so the web review queue and your other stores show the text you wrote.
* Changed: when you edit live questions and answers, the FAQ structured data on the product is updated to match.
* Changed: editing live specifications updates the ones Rezolve Ai added. Attributes you added to a product yourself are never changed.
* Fixed: an approval made in Rezolve Ai no longer overwrites an edit you made here before Rezolve Ai had received it. It waits, and then applies with your edit.
* Fixed: several specification rows with the same name are now added as one attribute with every value, instead of keeping only the first.
* Note: revert still restores each product as it was before Rezolve Ai changed it, however many times you have edited it since.

= 4.17.0 =
* Changed: quality scores are now real. Earlier versions showed "Score 90" for every enriched product, a fixed number that nothing had measured. The Review screen, the Score column and "Highest score" sort on Products, the dashboard chart and the CSV export now show the scores Rezolve Ai measures for your content, and say "Not scored" where nothing has been measured yet.
* Added: open a product on the Review screen to see Content Quality, SEO Score, AI Readability and Brand Voice for each piece of content, with the reasoning behind them, whether a score is out of date, and a Re-score button.
* Added: the dashboard's score chart counts products that are not scored yet, rather than leaving them out.
* Removed: the old fixed score is deleted from your products when you update, so it cannot be mistaken for a measurement.
* Note: scores are read from Rezolve Ai about once an hour in the background, a batch of products at a time, and whenever you open the Review screen.
* Fixed: AI Search showed a "Share of brand mentions" figure that counted every AI answer naming any brand, not just yours, so a brand no answer had mentioned could show 57%. The AI Search Overview and the dashboard now show "Your brand in AI answers", measured on your brand alone. How often answers name any brand is shown separately, as context.
* Fixed: the Competitors table showed each brand's share of answers as a count ("7.14 answer mentions"). It now shows a percentage, and your brand always appears on the brand charts, even at 0%.
* Changed: the AI Search trend compares your brand with any brand on a 0 to 100% scale and notes when runs asked different AI engines. "Most cited domains" highlights your own site. The "Brand presence" donut is removed.
* Added: when no answer names your brand, the Overview shows the exact brand name answers are matched against, since a registered company name can miss answers that use a shorter one.

= 4.16.1 =
* Fixed: a product you approved straight after enrichment could reappear in Review a minute later, marked ready to review again, because the same results were collected twice. Content you applied stayed live; only the review status was reset. With auto-apply on, the second collection could apply the same content again. Each enrichment is now collected exactly once, and a product you have already approved, dismissed or sent for enrichment again is left alone.
* Fixed: if the results of an enrichment could not be fetched, the products waiting on them could be marked as failed or left waiting for good. The plugin now tries again.

= 4.16.0 =
* Added: five more things to enrich. Product title, highlights (short benefit bullets), product Q&A, product attributes and image alt text now appear in the enrichment picker, once your Rezolve Ai account supports them.
* Added: highlights and Q&A show on the product page, positioned the same way as scenario cards and internal links. Place them yourself with the new [agencoen_highlights] and [agencoen_product_qa] shortcodes or editor blocks if you prefer.
* Added: approved Q&A is also published as FAQ structured data, but only on pages where the questions are actually shown, and never twice.
* Added: product attributes are added to your products as custom attributes. Attributes a product already has are kept exactly as they are, and variations are never touched.
* Added: image alt text replaces the alt text on each product image when you approve it. The review screen shows every image with its current and proposed alt text first. Revert puts the old text back, except on images whose alt text you have edited yourself since.
* Added: highlights, Q&A and alt text approved in the Rezolve Ai web app now reach this site too.
* Fixed: the product title suggestion was listed on the review screen but never filled in, so a new title could not be approved from WordPress.
* Fixed: a product whose review details were already cleared did not tell Rezolve Ai which content had been applied to it.

= 4.15.0 =
* Added: what you approve, dismiss or revert on this site now shows up in Rezolve Ai too. Until now the two review screens could not see each other, so a product you had already dealt with here still looked untouched there, and the counts on your Rezolve Ai dashboard were missing everything you had done from WordPress.
* Added: a one-time catch-up runs after this update and sends the decisions you already made, so your history appears rather than only what you do from now on. It runs in the background, a few products at a time.
* Note: nothing new is collected about your shoppers, and nothing is applied to your products that was not already applied. This only reports decisions you had already made.

= 4.14.0 =
* Added: A/B tests now count the basket step. The "Where shoppers drop off" chart shows how many shoppers each version got as far as adding to a basket, not just how many read the page and how many checked out. A version that wins at the basket and loses at checkout tells you something a single conversion number cannot.
* Fixed: until now that step was never counted on WooCommerce, and the chart drew it as a flat zero, which reads as "nobody added anything to a basket". It also made the basket look like the worst step of every test. Shops still on an older version now see the step left out with a note, instead of a zero that was never measured.

= 4.13.2 =
* Improved: an A/B test now looks for your description in several of the usual places instead of one. If your theme names that block differently, the test used to run for weeks swapping nothing and report no difference between two versions nobody was ever shown. Setting the block selector yourself still wins; it is now tried first rather than instead of the rest.

= 4.13.1 =
* Fixed: A/B tests could not run at all. Fetching the live test settings and sending results back both stopped on an internal error, so a test set up in Rezolve Ai never reached the shop and never reported anything. Pausing or stopping a test from this screen failed with a critical error for the same reason.
* Fixed: even once that was past, the shop read the list of running tests from the wrong place, so it always saw an empty list and removed the test settings it already had. A test could reach a product page and then be taken back off it on the next check-in.
* Also fixed, on the Rezolve Ai side and already live for every shop with no update needed here: the test settings sent to WooCommerce shops pointed at a part of the page that only exists on Shopify themes, so a running test showed every shopper the same words.

= 4.13.0 =
* Fixed: shops selling variable products were being shown to AI shopping agents as out of stock, even when everything was in stock. Agents that believed it refused to buy. The plugin now sends each product's real stock status, so what agents see matches your shop.
* Fixed: prices were shown to agents in US dollars whatever currency your shop uses. Your shop currency is now sent with every sync.
* New: every variation of a variable product is now sent in full, with its own price, SKU, stock status and options. Agents used to see one generic "Default" option priced at your cheapest variation; they now see the real choice, and can send a shopper to the exact variation they picked.
* Buy links now add the exact variation a shopper chose to the basket, rather than the parent product.

= 4.12.0 =
* Rezolve Ai can now tell you when this site is not sending it anything to measure. If you have connected an account but left daily reporting off, the plugin says so and offers to switch it on. Nothing about what is collected has changed, and both traffic settings still start switched off: this asks, it does not decide for you.
* The hourly check-in now reports whether traffic tracking and reporting are on, so the results, A/B testing and AI referral screens in Rezolve Ai can tell "switched off" apart from "nothing happened yet".

= 4.11.0 =
* The plugin is now called Rezolve Ai. Same plugin, same settings, same connection: only the name has changed. You do not need to reconnect or re-enter your API key.

= 4.10.0 =
* New: AI shopping agents can now find your shop. The plugin publishes a Universal Commerce Protocol profile at /.well-known/ucp on your own domain, which is where agents like ChatGPT and Perplexity look when someone asks them to find a product.
* What they see is the enriched product copy you have approved in Rezolve Ai, not your raw product data.
* New: agents can send a shopper straight to a filled basket. A link with the products already chosen lands on your cart with everything in it, ready to check out.
* You stay in control of the sale. Nothing is bought through Rezolve Ai, and no payment details ever pass through it. The shopper always finishes on your own checkout.
* Turn it on per project in Rezolve Ai, under UCP. It is off until you switch it on, and switching it off stops this site publishing within the hour.

= 4.9.1 =
* Fixed: your SEO title and meta description were reported as applied even on sites with no SEO plugin installed, where there is no field to write them to. Rezolve Ai now says so instead, and the content stays in the queue and applies by itself once you install one. Previously it was marked done and never applied, even after you installed an SEO plugin later.
* Fixed: a product could show "Meta tags" on its applied list when nothing had actually been written.
* New: Settings now tells you when no SEO plugin is detected, so you know before you run enrichment rather than after.

= 4.9.0 =
* New: WooCommerce product fields. Pick it in Rezolve Ai and your enriched product data is written onto the product itself: product tags, specification attributes shown in the Additional information tab, and your SEO title and meta description.
* Tags and attributes are only ever added. Anything already on the product stays, and a specification you set yourself is never overwritten. Revert takes back exactly what Rezolve Ai added and leaves the rest alone, including anything you added afterwards.
* Product categories are never changed. Rezolve Ai can suggest one for you to look at, but it will not move your products between categories, because that would rearrange your shop menus and category pages.
* SEO title and meta description need Yoast SEO, Rank Math or All in One SEO installed, since that is where WordPress stores them.

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
* Fixed: on the New experiment tab, "Check this design" could appear to do nothing. When the answer was a refusal, such as no products having reached Rezolve Ai yet, it flashed in a notice that disappeared after a few seconds. The reason now stays on the page until you change the design.

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
* Fixed: content you approve in Rezolve Ai now actually reaches this site, and Rezolve Ai is told when it does. Until now the plugin applied only what it had generated locally and never reported the result, so Rezolve Ai had no record of anything this site published.
* This is why A/B testing could not run here. On the Rezolve Ai side a version counts as live only once it has been reported as published, so tests set up for a WooCommerce store never received their settings and never started. Nothing looked wrong from either end.
* Your Published view in Rezolve Ai will now show WooCommerce alongside other channels, rather than being empty for stores that had in fact published.
* Checking happens hourly rather than daily, so approving a version in Rezolve Ai and seeing it live on your store is a matter of minutes rather than the next day.
* No new data leaves your site. The report says which item was applied and whether it worked, and nothing else.

= 4.5.0 =
* New: optional per-visitor A/B testing of product descriptions, so an experiment set up in Rezolve Ai can run on this site and be measured against real customers rather than only against crawler activity. Off by default on every site, including upgrades.
* This is the only feature in the plugin that stores a cookie, and it is gated on the WordPress Consent API rather than on a setting alone. With no consent plugin installed it does nothing: nothing is stored, nothing is sent, and every visitor sees your normal copy.
* The version a visitor sees is decided in the browser, not on the server. On a cached site WordPress often does not run at all, so a server-rendered version would be written into the page cache and then served to everyone, including crawlers. Your page therefore always contains exactly one description, and a visitor in a test fetches theirs separately.
* Crawlers are never put into a test. They carry no cookie and give no consent, which is the same rule applied to a first-time visitor: no user agent is examined anywhere.
* Visitor ids are scrambled with a secret generated for your site alone before anything leaves it. The same visitor on another site produces an unrelated value, so nothing can be joined across sites.
* New setting for the description block's CSS selector, for themes that do not use the standard WooCommerce layout. A selector that matches nothing means the test changes nothing, so this is worth checking before starting one.

= 4.4.0 =
* New: optional visitor pageview counting, so Rezolve Ai can show what a content change did to real traffic rather than only to AI crawlers. Off by default, with its own setting separate from the crawler report.
* Counting uses a small script on published pages rather than server-side hooks. Page caches serve most visitors without WordPress running at all, so a server-side counter would undercount heavily on exactly the sites that are busiest.
* No cookie, no session, no IP address, hashed or otherwise. Nothing stored can identify a visitor, which is why this reports pageviews and does not claim to report unique visitors. Logged-in editors and administrators are never counted, and neither are crawlers.
* New: optional daily WooCommerce sales totals, one row per product per day with units and net revenue, read from WooCommerce's own analytics tables so the figures match your Analytics screen. No orders, customers, names, email addresses or postal addresses are sent.
* The crawler setting's description now points at the new pageview setting instead of stating that human traffic is never logged, which is only true while that setting is off.

= 4.3.1 =
* A fresh install now opens on things to do, not on setup. The Dashboard leads with a dismissible "Start here" card of local actions (crawler analytics, catalog browsing, feed URLs, publishing preferences), tailored to whether WooCommerce is active.
* No screen mentions a Rezolve Ai account on a fresh install. The optional connect notice appears only after the plugin's own crawler analytics have recorded meaningful AI traffic, and it opens with what was measured. Nothing changes about what works: every local feature is on from activation, with or without an account, forever.
* Uninstall now also removes the plugin's per-user dismissal preferences.
* All request input is now read, unslashed, and sanitized directly inside each admin-ajax handler, immediately after its nonce check. Helper methods receive plain values and never touch request data, and no static-analysis suppressions for nonce verification remain anywhere in the plugin.
* Admin scripts and styles are now enqueued from the screen hook suffix WordPress passes to the enqueue callback instead of reading the request URL.
* Product pages no longer carry duplicate structured data: when the plugin outputs its enhanced JSON-LD graph, WooCommerce core's copies of the same entity types (Product, BreadcrumbList) are suppressed, while Woo entities the plugin does not emit are kept.
* Renamed the internal account-link field in error payloads.
* The CSV export no longer raises PHP 8.4+ deprecation notices.
* Bundled the full GPLv2 license text.

= 4.2.0 =
* Every admin screen now renders without a Rezolve Ai account. Nothing the plugin does on your own site is held back: AI crawler tracking and its settings, publishing preferences, Article and Product JSON-LD, the Published articles list, the product catalog and its export, and the full review queue all work on activation.
* Only the controls that call the Rezolve Ai service (article generation, product enrichment, research runs, scheduled sync, auto-apply, and the optional daily crawler report) ask for an account, and they now say so in place instead of replacing the screen.
* The API key has moved to its own Rezolve Ai, then Connection screen. Settings now opens directly on the AI crawler tracking and publishing options, which work straight after activation.
* The dashboard leads with the numbers read from your own site: AI crawler hits and article counts.
* The notice about connecting an account can be dismissed and stays dismissed.
* Nonce verification is an explicit first statement in every admin-ajax handler, checked before any input is read and before any other condition.
* admin-ajax handlers are no longer registered on front-end page loads.
* Corrected the terms of service and privacy policy links in this readme.

= 4.1.0 =
* Product feeds now require a private per-site token in their URLs, with one-click regeneration in Settings, and support conditional requests (Last-Modified/304) so unchanged catalogs cost nothing to re-fetch.
* Admin screens render instantly from cache and fill in live numbers after paint. No wp-admin page load ever waits on the Rezolve Ai platform, and during an outage the dashboard shows your last known credit balance instead of zeros.
* Hardened the Product JSON-LD output against markup breakout, matching the Article emitter.
* Storefront shortcodes renamed to [agencoen_irl] and [agencoen_internal_links].
* Interactive requests to the platform now fail fast (12 seconds, no retries) while background jobs keep patient retries. Faster AI crawler detection on busy sites.

= 4.0.0 =
* WooCommerce is now optional: the plugin activates on any WordPress site, with the commerce suite available when WooCommerce is active.
* New Content screen: generate long-form articles with your brand voice (built-in, preset, and custom content types), then publish them as posts with SEO meta and Article JSON-LD. Two-way sync with the Rezolve Ai Content Hub.
* New AI Search console: manage research prompts (with AI suggestions from your catalog), launch runs across AI answer engines, and explore share of answers, competitors, per-prompt citations, and trends.
* New native AI crawler analytics: privacy-safe tracking of GPTBot, ClaudeBot, PerplexityBot, and 20+ other AI crawlers with charts, top pages, and an optional daily report to Rezolve Ai.
* Redesigned admin matching the Rezolve Ai app: KPI tiles, segmented tabs, and a dependency-free SVG chart kit (lines, areas, donuts, heatmaps, progress rings).
* Product enrichment now runs on the platform's durable jobs API with webhook-accelerated polling.

== Upgrade Notice ==

= 4.34.3 =
Tested with WooCommerce 11.3.

= 4.34.2 =
The Rezolve Ai blocks are updated for the current block editor: no deprecation warnings, and previews styled like your storefront.

= 4.34.1 =
Tested with WordPress 7.2, and translated screens now describe counts and names correctly.

= 4.34.0 =
Copy your ChatGPT product file URL from Settings into ChatGPT Business Hub, and your file now names your privacy, terms, and returns pages.

= 4.33.0 =
Your store's country now reaches your OpenAI product file, and a product split's chart draws the goal the test is measured on, such as Shopping impressions, instead of always drawing visits.

= 4.32.1 =
Structured data now states only what your store says: a sale's real end date, and no availability for a stock status the plugin does not recognise.

= 4.32.0 =
With the daily crawler report on, AI agents that sign their requests are checked, so you can see which agents really visited.

= 4.31.1 =
A product split's visits chart marks the day the test started and compares the two groups per product.

= 4.31.0 =
Counts orders that AI shopping agents send through Rezolve Ai buy links, and lets you choose how many products a product split holds back.

= 4.30.1 =
Edit live is refused for products an experiment holds in its control group, with the reason shown.

= 4.30.0 =
Products an experiment holds in its control group stay waiting in Review until it releases them, with the reason shown.

= 4.29.0 =
Experiments can also measure cart adds, orders, revenue and visits beside the main goal, shown in a new Also measured table.

= 4.28.0 =
New content follows your store's own format, with presets, a free preview, and filling empty custom fields.

= 4.27.0 =
Keyboard and screen reader access across every admin screen, data tables for every chart, and higher contrast.

= 4.26.0 =
Products in a running experiment are left out of enrichment, and the screen tells you how many.

= 4.25.2 =
AI Search no longer offers a credit estimate for a run. Research runs do not use your credits.

= 4.25.1 =
Highlights and other lists sent to the short description no longer run together in block themes.

= 4.25.0 =
Choose where Q&A, highlights, IRL cards, links and specifications go: the Rezolve Ai block, your own fields, a section of the description, or a product tab.

= 4.24.4 =
Rewritten custom fields keep their lines on Review.

= 4.24.3 =
New features are offered as soon as you update, and custom field rewrites appear in the picker as soon as you turn them on.

= 4.24.2 =
Fixes how the Rewrite switches look in the Custom fields table.

= 4.24.1 =
The Custom fields card becomes a searchable table with bulk actions and a rewrite switch for each field.

= 4.24.0 =
Rezolve Ai now writes from your custom fields, ACF fields, attributes, tabs and brand, never sends fields that look private, and can rewrite the custom fields you choose.

= 4.21.0 =
See where each experiment is on the Experiments screen, from writing its new versions to the day of its run.

= 4.20.1 =
Images with no alt text now reach Rezolve Ai as undescribed instead of carrying the product name, and content that goes live now sends its product page.

= 4.20.0 =
See every product image and its current alt text on the Review screen, and open any product's live page from its row.

= 4.19.1 =
Your approvals and dismissals on this site now only update the matching content in Rezolve Ai.

= 4.19.0 =
Test every kind of content on your products, not only the description, and measure orders and revenue when you split products.

= 4.18.1 =
Fixes for edits made on this site: they can no longer hold up other approved changes, and a failed apply no longer loses your version.

= 4.18.0 =
Edit any suggestion on the Review screen before approving it, or edit content that is already live. Your edits are sent to Rezolve Ai, and revert still restores the original product.

= 4.17.0 =
Replaces the fixed "Score 90" shown on every product with the quality scores Rezolve Ai actually measures, and shows the scores for each piece of content, with re-scoring, on the Review screen. Also corrects AI Search, which counted answers naming any brand as mentions of yours.

= 4.16.1 =
Fixes approved products reappearing in Review shortly after enrichment, and a case where auto-apply could apply the same content twice. Recommended for everyone on 4.16.0.

= 4.16.0 =
Adds highlights, product Q&A, product attributes, image alt text and product title enrichment. Approving a title or alt text now writes it onto the live product, and approving alt text replaces what each image had. Revert undoes both.

= 4.13.0 =
Important if you sell variable products. AI shopping agents were being told your stock was empty and your prices were in dollars, and were refusing to buy as a result. This release sends your real stock status, your shop currency, and every variation with its own price and options.

= 4.11.0 =
Renamed to Rezolve Ai. No action needed: your connection and settings carry over.

= 4.10.0 =
Your shop can now be found by AI shopping agents. The plugin publishes a Universal Commerce Protocol profile on your own domain, so agents can search your products and send shoppers to a ready-filled basket. Off until you turn it on in Rezolve Ai, and no sale or payment ever goes through us.

= 4.9.1 =
Fixes SEO titles and meta descriptions being reported as applied on sites with no SEO plugin installed. Affected content was marked done and could never apply, even after installing one later. It now waits in the queue and applies by itself. Recommended for anyone who has run enrichment without Yoast SEO, Rank Math or All in One SEO.

= 4.9.0 =
Rezolve Ai can now write product tags and specification attributes onto your products, alongside the SEO title and meta description it already wrote. It only ever adds: your existing tags and specifications are kept, and Revert takes back exactly what it added. Your product categories are never changed.

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
Adds an Experiments screen for WooCommerce stores, and fixes the reason tests could not run: content approved in Rezolve Ai now reaches this site, and Rezolve Ai is told when it lands. This is what A/B testing needed in order to run here at all. Nothing new is collected and no setting changes.

= 4.5.0 =
Adds optional per-visitor A/B testing. It is off by default and stays off after this upgrade. It is the only part of this plugin that stores a cookie, and it does nothing without visitor consent through the WordPress Consent API.

= 4.4.0 =
Adds optional visitor pageview counting and daily WooCommerce sales totals. Both are off by default and neither collects anything that identifies a visitor.

= 4.3.1 =
Internal security hardening: every admin-ajax handler now reads and sanitizes its own input right after its nonce check. No action needed on update.

= 4.2.0 =
Every screen and every local feature now works without a Rezolve Ai account. The API key has moved to its own Connection screen. No action needed on update.

= 4.1.0 =
Feed URLs now carry a private token: copy the new URLs from Settings into Google and Microsoft after updating. Storefront shortcodes are renamed to [agencoen_irl] and [agencoen_internal_links]. Admin screens no longer wait on the platform to render.
