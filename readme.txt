=== Omni Performance Hardening ===
Contributors: ivanusto
Tags: performance, cache, search, feed, xml-rpc
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tames expensive WordPress endpoints — search scans, archive queries, low-value feeds, oEmbed, XML-RPC — with CDN-friendly cache headers.

== Description ==

Built for large, crawl-heavy content sites (news, media, aggregators) where bot traffic on dynamic endpoints eats database CPU and PHP-FPM workers.

* **Search hardening** — filters junk search probes (length, word count, invalid UTF-8, long non-CJK strings), narrows the query to indexed columns, optionally matches titles/excerpts only (WP 6.2+).
* **Archive slimming** — drops `SQL_CALC_FOUND_ROWS` on tag/author/date archives; tag archive pagination is restored from the stored term count at zero cost. The same optimisation for widget and page-builder queries is available as an opt-in setting.
* **Endpoint cache headers** — correct `Cache-Control` / `X-Robots-Tag` for feeds, search, tag pages, author pages, deep pagination and 404s, so your CDN can absorb bot traffic.
* **Feed policy** — three modes (cache / strict / off) for low-value feeds; author, search and comment feeds can return 410.
* **Author page hardening** — a single switch for author-page noindex, author feed 410 and the robots.txt `/author/` block. Off by default, since most sites want author archives indexed.
* **Heartbeat tuning, oEmbed/XML-RPC disabling, REST user enumeration blocking, frontend external HTTP timeout cap, managed virtual robots.txt, removal of remote-fetching dashboard news widgets.**

Every feature has its own on/off switch — nothing is forced on you.

Settings live in **Settings → Omni Performance Hardening** (admin UI). Any setting can also be pinned via a `PH_*` constant in `wp-config.php`; pinned constants lock the corresponding admin field. Priority: constants > admin settings > defaults.

The plugin also works as a must-use plugin: drop `omni-performance-hardening.php` into `wp-content/mu-plugins/`.

**Sister plugin**: [Omni Webmaster & SEO Suite](https://wordpress.org/plugins/omni-webmaster-seo-suite/) — SEO and webmaster tooling from the same team. This plugin keeps your site fast and crawl-efficient; the SEO suite handles visibility and indexing.

Full documentation (Traditional Chinese): https://github.com/ivanusto/wp-perf-hardening

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload, then activate. Rewrite rules are rebuilt automatically on activation and deactivation.
2. Adjust parameters under Settings → Omni Performance Hardening.
3. Optionally pin per-site values with `PH_*` constants in `wp-config.php` (see the GitHub README for the full list).

== Frequently Asked Questions ==

= Does it break my content partners' feeds? =

The main feed and category feeds are always kept. Check which feed paths your partners subscribe to before choosing the `off` feed mode. With the defaults, `strict` mode only returns 410 for search and comment feeds; author feeds are included only if you enable author page hardening.

= A post list on my site went blank. =

Turn off "Skip counts on widget queries". That setting drops the total-row count on secondary queries, which sets `found_posts` to 0; page builders that read that value — Elementor's post widgets in particular — stop rendering the list entirely. It is off by default for this reason.

= A plugin making external API calls started timing out. =

The frontend HTTP timeout cap excludes REST, AJAX, cron, WP-CLI and logged-in users, so this should be rare. If it happens, disable "Frontend external request throttle" or raise the timeout.

== Changelog ==

= 1.6.0 =
* Skipping row counts on widget and page-builder queries is now an opt-in setting, off by default. It previously always applied and could silently blank out post lists in themes and page builders that read `found_posts` (Elementor's post widgets among them).

= 1.5.0 =
* Author page hardening is now off by default — most sites want their author archives indexed. Enable it in the settings when you don't.
* Dashboard news widget removal is now an opt-out setting instead of always-on behaviour.
* Corrected the sister plugin's name to Omni Webmaster & SEO Suite.

= 1.4.0 =
* Renamed to Omni Performance Hardening; slug and text domain are now `omni-performance-hardening`. `PH_*` wp-config constants are unchanged.
* Global functions and filter hooks now use the `omni_performance_hardening_` prefix.
* Bundled translations now load via load_textdomain() (Plugin Check clean); wp.org-hosted translations keep loading automatically.
* Settings page links to the sister plugin Omni Webmaster SEO Suite.

= 1.3.0 =
* Renamed global functions and filter hooks from the `ph_` prefix to `perf_hardening_` (Plugin Check compliance). `PH_*` wp-config constants are unchanged.

= 1.2.0 =
* Internationalized all UI strings (text domain `perf-hardening`); English source with bundled Traditional Chinese (zh_TW) translation.

= 1.1.0 =
* Admin settings page; settings priority: wp-config constants > admin options > defaults.
* Standalone author-page hardening switch.
* Installable as a regular plugin (auto rewrite-rules handling) or as a mu-plugin.
* XML-RPC now fully disabled via `xmlrpc_methods` (anonymous methods such as `pingback.ping` included).
* License changed to GPL-2.0-or-later.

= 1.0.0 =
* Initial release: search hardening, archive slimming, endpoint cache headers, feed policy, heartbeat tuning, oEmbed/XML-RPC disabling, REST user enumeration blocking, frontend HTTP timeout cap, managed robots.txt.
