=== Performance Hardening ===
Contributors: ivanusto
Tags: performance, cache, search, feed, xml-rpc
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tames the most expensive WordPress endpoints: search table scans, archive queries, low-value feeds, oEmbed and XML-RPC, with CDN-friendly cache headers.

== Description ==

Built for large, crawl-heavy content sites (news, media, aggregators) where bot traffic on dynamic endpoints eats database CPU and PHP-FPM workers.

* **Search hardening** — filters junk search probes (length, word count, invalid UTF-8, long non-CJK strings), narrows the query to indexed columns, optionally matches titles/excerpts only (WP 6.2+).
* **Archive slimming** — drops `SQL_CALC_FOUND_ROWS` on tag/author/date archives and all secondary queries; tag archive pagination is restored from the stored term count at zero cost.
* **Endpoint cache headers** — correct `Cache-Control` / `X-Robots-Tag` for feeds, search, tag pages, author pages, deep pagination and 404s, so your CDN can absorb bot traffic.
* **Feed policy** — three modes (cache / strict / off) for low-value feeds; author, search and comment feeds can return 410.
* **Author page hardening** — a single switch for author-page noindex, author feed 410 and the robots.txt `/author/` block; turn it off on sites that want author archives indexed.
* **Heartbeat tuning, oEmbed/XML-RPC disabling, REST user enumeration blocking, frontend external HTTP timeout cap, managed virtual robots.txt.**

Settings live in **Settings → 效能強化** (admin UI). Any setting can also be pinned via a `PH_*` constant in `wp-config.php`; pinned constants lock the corresponding admin field. Priority: constants > admin settings > defaults.

The plugin also works as a must-use plugin: drop `perf-hardening.php` into `wp-content/mu-plugins/`.

Full documentation (Traditional Chinese): https://github.com/ivanusto/wp-perf-hardening

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload, then activate. Rewrite rules are rebuilt automatically on activation and deactivation.
2. Adjust parameters under Settings → 效能強化.
3. Optionally pin per-site values with `PH_*` constants in `wp-config.php` (see the GitHub README for the full list).

== Frequently Asked Questions ==

= Does it break my content partners' feeds? =

The main feed and category feeds are always kept. Check which feed paths your partners subscribe to before choosing the `off` feed mode; `strict` (default) only returns 410 for author, search and comment feeds.

= A plugin making external API calls started timing out. =

The frontend HTTP timeout cap excludes REST, AJAX, cron, WP-CLI and logged-in users, so this should be rare. If it happens, disable "前台外部請求節流" or raise the timeout.

== Changelog ==

= 1.1.0 =
* Admin settings page; settings priority: wp-config constants > admin options > defaults.
* Standalone author-page hardening switch.
* Installable as a regular plugin (auto rewrite-rules handling) or as a mu-plugin.
* XML-RPC now fully disabled via `xmlrpc_methods` (anonymous methods such as `pingback.ping` included).
* License changed to GPL-2.0-or-later.

= 1.0.0 =
* Initial release: search hardening, archive slimming, endpoint cache headers, feed policy, heartbeat tuning, oEmbed/XML-RPC disabling, REST user enumeration blocking, frontend HTTP timeout cap, managed robots.txt.
