=== Conciera - AI Site Search & Content Finder ===
Contributors:      sungraizfaryad
Tags:              ai, site-search, chatbot, openai, search
Requires at least: 6.0
Tested up to:      7.0
Stable tag:        2.0.0
Requires PHP:      8.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

AI search chatbot that helps visitors find content on your website. Turns questions into a safe search of your own posts, pages, and products.

== Description ==

Conciera is an AI-powered site search chatbot that helps your visitors find content on your website. When someone asks a question in plain language, Conciera searches your own posts, pages, products, and listings, then answers right in the chat — so visitors reach the right page without digging through menus. It combines **conversational search** with **knowledge Q&A** in one chat widget.

**Mode 1 — Smart Search (for sites with listings)**
A visitor says: "Find me apartments in Lisbon under €500K with 3 bedrooms" → Conciera extracts the parameters, searches your WordPress database, and returns a filtered results page plus preview cards — all inside the chat.

**Mode 2 — Knowledge Q&A (for all sites)**
A visitor says: "What is your return policy?" → Conciera searches your indexed content and answers the question with source citations.

**The AI automatically picks the right mode** based on what the visitor is asking.

= Key Features =

* **Auto-discovers your site** — scans post types, taxonomies, and custom fields (ACF, MetaBox, WooCommerce) automatically
* **Function calling** — uses OpenAI's tools API for guaranteed structured search parameters, not fragile text guessing
* **Direct database access** — queries WordPress directly (no HTTP crawling = faster, more accurate)
* **In-chat preview cards** — shows thumbnails, prices, and details before the visitor leaves the chat
* **Results page URL** — generates a filtered archive URL visitors can bookmark or share
* **RAG Q&A** — embeds your content, searches by cosine similarity, answers with source links
* **Custom Q&A pairs** — admins can add specific answers that always take priority
* **Bring Your Own Key** — works with your own OpenAI API key, no SaaS subscription required
* **Privacy-first** — conversation logging is OFF by default; IP addresses are never stored

= Who It's For =

* Real estate directories (find properties by price, bedrooms, location)
* Job boards (find jobs by type, salary, location, remote)
* WooCommerce stores (find products by price, category, attributes)
* Event sites (find events by date, location, category)
* Doctor/medical directories
* Any WordPress site with custom post types

= Requirements =

* PHP 8.0 or higher
* WordPress 6.0 or higher
* OpenAI API key

== Installation ==

1. Upload the `ai-chatmate` folder to `/wp-content/plugins/`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Go to **Conciera → Settings**
4. Enter your OpenAI API key and click **Save Settings**
5. Click **Test Connection** to confirm your key is working
6. Go to **Conciera → Content Indexing** and click **Start Indexing**
7. Add the chat widget with the shortcode `[ai_chatmate]` or enable the floating widget (appears automatically in the footer on every page)

== Frequently Asked Questions ==

= Do I need an OpenAI account? =

Yes. You need an OpenAI API key from platform.openai.com. The plugin uses your key directly — we never see it.

= How much will it cost to run? =

With gpt-4o-mini (our recommended model), each conversation turn costs approximately $0.005 USD. 1,000 conversations per month ≈ $5–10. Content indexing for a 500-page site costs approximately $0.025 (one-time).

= Is my data sent to OpenAI? =

Only the user's chat message and relevant content excerpts (for Q&A mode) are sent to OpenAI. Your full database is never sent. API keys are encrypted before storage and never leave your server.

= Will it slow down my site? =

No. The chat widget JS is less than 10KB gzipped and loads asynchronously. Admin scripts only load on plugin admin pages. All AI processing happens via REST API calls triggered by the visitor — never on page load.

= Does it work with ACF, MetaBox, WooCommerce? =

Yes. The schema discovery engine automatically detects custom fields from ACF, MetaBox, and WooCommerce product attributes.

== External services ==

This plugin connects to the OpenAI API. This is required for the AI chat features: OpenAI turns a visitor's natural-language question into a structured search of your own content and writes the answer.

What is sent, and when: only when a visitor sends a chat message, the plugin sends that message text plus the titles and short excerpts of the matching content from your own site to OpenAI. If you enable the optional Semantic Q&A mode, the text of your selected content is also sent to OpenAI during indexing to generate embeddings. Your API key, your full database, and IP addresses are never sent. Nothing is sent until you add your own OpenAI API key and turn the chat widget on — both are off by default.

The service is provided by OpenAI, L.L.C. Please review their policies:

* Terms of Use: https://openai.com/policies/terms-of-use
* Privacy Policy: https://openai.com/policies/privacy-policy
* API data usage policies: https://openai.com/policies/api-data-usage-policies

== Screenshots ==

1. Settings page — configure your API key and AI provider
2. Chat widget — visitor view with search results and preview cards
3. Content indexing — select post types and monitor indexing progress
4. Schema review — inspect auto-discovered post types and fields
5. Q&A Manager — add custom question-answer pairs

== Changelog ==

= 2.0.0 =
* Rebuilt as a structured-search-first site assistant.
* Natural-language queries are translated into a safe WP_Query over your post types, taxonomies, and custom fields.
* Embeddings / semantic Q&A is now optional and off by default.
* Honest provider scope: OpenAI only in this release.

= 1.0.0 =
* Initial release.
* Settings page — encrypted API key storage, model selection, widget configuration, privacy/GDPR controls.
* OpenAI provider — chat completion (gpt-4o-mini / gpt-4o) and embeddings (text-embedding-3-small).
* Schema discovery — auto-detects post types, taxonomies, and custom fields (ACF, MetaBox, WooCommerce).
* Content indexing — chunker, embedder, background queue (WP-Cron), auto-sync on post save/delete.
* Chat engine — RAG retrieval (cosine similarity), OpenAI function-calling (search_posts), conversation handler with session history and token budgeting.
* Frontend chat widget — floating launcher, accessible dialog, brand colour + position overrides, [ai_chatmate] shortcode.
* Analytics page — monthly API cost history, index health stats, conversation stats (when logging enabled).
* Q&A Manager — admin-configured question/answer pairs matched semantically before RAG (threshold 0.92); REST CRUD API.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
