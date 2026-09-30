=== AgentSteamer WP AI Lang ===
Contributors: mantelgreen
Tags: multilingual, translation, language, i18n, ai
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A self-contained WordPress multilingual plugin: language switching, browser-language auto-redirect, linked language versions, and AI-powered translation via your own LLM provider.

== Description ==

AgentSteamer WP AI Lang makes any WordPress site multilingual without depending on an external platform. Each translation is a real post/page linked into a translation group, so every language gets its own URL, SEO metadata and sitemap entry.

Official site: [https://www.agentsteamer.com](https://www.agentsteamer.com)

= Features =

**Languages**
* Add/edit/sort languages (code, locale, URL segment, direction, flag)
* One default language; optional hidden prefix for the default language
* URL strategies: directory (`example.com/en/`) or query (`?asl_lang=en`)

**Language versions**
* Assign a language to posts and pages
* Link translations of the same content into a translation group
* Manually duplicate + link a language version as a draft
* Language column and filter on the post list

**Automatic switching**
* Detect the visitor's browser language and redirect once (configurable)
* Remember the visitor's choice with a cookie
* `?no_redirect=1` escape hatch; no redirect loops

**AI translation**
* Configure OpenAI-compatible, Anthropic Claude, Google Gemini or a custom endpoint
* Translate title / content / excerpt / SEO fields into a linked draft
* Glossary (fixed translations and do-not-translate terms)
* Review queue with apply / reject / rollback

**Multilingual SEO**
* `hreflang` alternates + `x-default`, `<html lang>` / `dir`, `og:locale`
* Per-language URLs and canonical

**Developer interfaces**
* Template tag `agentsteamer_lang_switcher()` and shortcode `[agentsteamer_lang_switcher]`
* WP-CLI: `wp agentsteamer-lang lang-list` / `lang-add` / `lang-delete`
* REST: `agentsteamer-lang/v1`
* Hooks: `agentsteamer_lang_*`

= Integrations =

When the AgentSteamer SEO/GEO plugin (`agentsteamer-wp-ai-geo`) is active, this plugin bridges to it automatically: per-language sitemap entries and indexing submissions, while `hreflang` is owned by this plugin to avoid duplicates.

== Installation ==

1. Upload the `agentsteamer-wp-ai-lang` folder to `/wp-content/plugins/`.
2. Activate the plugin on the Plugins screen.
3. Go to **AgentSteamer Lang → Languages** to add your languages and pick a default.
4. Go to **AgentSteamer Lang → Settings** to configure URL/detection and your LLM provider.

== Frequently Asked Questions ==

= Does it require an external service? =

No. Translation uses the LLM provider you configure. No telemetry.

= Does it conflict with Polylang or WPML? =

Run only one multilingual plugin at a time. If another is active, this plugin shows a warning.

= Will AI publish translations automatically? =

No. AI translations are created as drafts and go to the review queue; nothing is published until you approve it.

== Changelog ==

= 0.1.0 =
* Initial release: language management, directory/query URLs, language taxonomy, translation groups, browser-language detection with cookie memory, language switcher (widget/shortcode/template tag), hreflang / html lang / og:locale, AI translation provider settings, glossary, review queue, WP-CLI and REST scaffolding.
