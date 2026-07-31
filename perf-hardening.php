<?php
/**
 * Plugin Name:  Performance Hardening
 * Plugin URI:   https://github.com/ivanusto/wp-perf-hardening
 * Description:  收斂 WordPress 高成本端點：站內搜尋全表掃描、封存頁 SQL_CALC_FOUND_ROWS、
 *               低價值 Feed、oEmbed、XML-RPC，並為 CDN 提供正確的快取標頭。
 * Version:      1.0.0
 * Requires PHP: 7.4
 * License:      GPL-3.0-or-later
 *
 * 安裝方式：置於 wp-content/mu-plugins/perf-hardening.php（must-use plugin，無需啟用）。
 * 部署後請至「設定 → 固定網址」按一次儲存，或執行 `wp rewrite flush`。
 *
 * 所有行為皆可透過常數關閉或調整，常數請定義於 wp-config.php 中
 * 「That's all, stop editing!」註解之前。完整清單見 README.md。
 *
 * @package PerfHardening
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * 預設值
 * ---------------------------------------------------------------------- */

/** 各功能區塊開關。 */
defined( 'PH_SEARCH_HARDENING' )   || define( 'PH_SEARCH_HARDENING', true );
defined( 'PH_ARCHIVE_HARDENING' )  || define( 'PH_ARCHIVE_HARDENING', true );
defined( 'PH_CACHE_HEADERS' )      || define( 'PH_CACHE_HEADERS', true );
defined( 'PH_HEARTBEAT_TUNING' )   || define( 'PH_HEARTBEAT_TUNING', true );
defined( 'PH_DISABLE_OEMBED' )     || define( 'PH_DISABLE_OEMBED', true );
defined( 'PH_DISABLE_XMLRPC' )     || define( 'PH_DISABLE_XMLRPC', true );
defined( 'PH_HTTP_THROTTLE' )      || define( 'PH_HTTP_THROTTLE', true );
defined( 'PH_MANAGE_ROBOTS_TXT' )  || define( 'PH_MANAGE_ROBOTS_TXT', true );

/** 搜尋防護參數。 */
defined( 'PH_SEARCH_MIN_LEN' )     || define( 'PH_SEARCH_MIN_LEN', 2 );
defined( 'PH_SEARCH_MAX_LEN' )     || define( 'PH_SEARCH_MAX_LEN', 40 );
defined( 'PH_SEARCH_MAX_WORDS' )   || define( 'PH_SEARCH_MAX_WORDS', 4 );
defined( 'PH_SEARCH_MAX_PAGES' )   || define( 'PH_SEARCH_MAX_PAGES', 3 );
defined( 'PH_SEARCH_PER_PAGE' )    || define( 'PH_SEARCH_PER_PAGE', 10 );
/** true 時只比對標題與摘要（需 WP 6.2+），可大幅降低掃描成本但會漏掉內文關鍵字。 */
defined( 'PH_SEARCH_TITLE_ONLY' )  || define( 'PH_SEARCH_TITLE_ONLY', true );
/** 針對非中日韓字元的長字串視為垃圾探測的長度門檻；設為 0 停用。 */
defined( 'PH_SEARCH_LATIN_MAX' )   || define( 'PH_SEARCH_LATIN_MAX', 20 );

/** 封存頁參數。 */
defined( 'PH_ARCHIVE_PER_PAGE' )   || define( 'PH_ARCHIVE_PER_PAGE', 10 );
/** 文章數低於或等於此值的標籤頁標記 noindex；設為 0 停用。 */
defined( 'PH_THIN_TERM_COUNT' )    || define( 'PH_THIN_TERM_COUNT', 2 );
/** 封存頁分頁超過此頁數標記 noindex；設為 0 停用。 */
defined( 'PH_DEEP_PAGE_NOINDEX' )  || define( 'PH_DEEP_PAGE_NOINDEX', 5 );

/**
 * Feed 策略。
 * 'cache'  保留全部 Feed，僅加上快取標頭（最保守，適合有內容合作夥伴的站台）
 * 'strict' author / search / comment feed 回 410，tag feed 保留但拉長快取（預設）
 * 'off'    停用上述全部低價值 Feed，僅保留主 Feed 與分類 Feed
 */
defined( 'PH_FEED_MODE' )          || define( 'PH_FEED_MODE', 'strict' );
/** 移除 <head> 中 tag / author / comment feed 的 discovery link。 */
defined( 'PH_REMOVE_FEED_LINKS' )  || define( 'PH_REMOVE_FEED_LINKS', true );
/** Tag feed 的項目數上限；設為 0 表示不調整。 */
defined( 'PH_TAG_FEED_ITEMS' )     || define( 'PH_TAG_FEED_ITEMS', 10 );
/** 外部 Feed 抓取結果的快取時數。 */
defined( 'PH_FEED_CACHE_HOURS' )   || define( 'PH_FEED_CACHE_HOURS', 6 );

/** 未登入訪客的前台請求，外部 HTTP 呼叫逾時上限（秒）。 */
defined( 'PH_FRONTEND_TIMEOUT' )   || define( 'PH_FRONTEND_TIMEOUT', 5 );

/** Heartbeat 間隔（秒）。 */
defined( 'PH_HEARTBEAT_EDIT' )     || define( 'PH_HEARTBEAT_EDIT', 60 );
defined( 'PH_HEARTBEAT_LIST' )     || define( 'PH_HEARTBEAT_LIST', 120 );

/** 停用未登入者的 REST 使用者列舉端點。 */
defined( 'PH_BLOCK_USER_ENUM' )    || define( 'PH_BLOCK_USER_ENUM', true );

/* -------------------------------------------------------------------------
 * 1. 站內搜尋防護
 *
 * WordPress 搜尋在 wp_posts 上使用 LIKE '%...%'，無法利用索引，
 * 在數萬列的站台上單筆查詢可達十秒等級，且是機器人最常探測的端點。
 * ---------------------------------------------------------------------- */

if ( PH_SEARCH_HARDENING ) {

	add_action( 'parse_query', function ( $query ) {

		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$term = trim( (string) $query->get( 's' ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );

		$reject = ( $len < PH_SEARCH_MIN_LEN || $len > PH_SEARCH_MAX_LEN );

		// 非法 UTF-8 一律視為機器探測。
		if ( ! $reject && function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $term, 'UTF-8' ) ) {
			$reject = true;
		}

		// 詞數界限：垃圾探測多為長句關鍵字。
		if ( ! $reject && PH_SEARCH_MAX_WORDS > 0
			&& preg_match_all( '/\S+/u', $term ) > PH_SEARCH_MAX_WORDS ) {
			$reject = true;
		}

		// 不含中日韓字元的長字串視為垃圾探測（仍允許 AI、Netflix 等短英文詞）。
		if ( ! $reject && PH_SEARCH_LATIN_MAX > 0
			&& $len > PH_SEARCH_LATIN_MAX
			&& ! preg_match( '/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}\x{ac00}-\x{d7af}]/u', $term ) ) {
			$reject = true;
		}

		/**
		 * 允許外部覆寫判定結果。
		 *
		 * @param bool     $reject 是否視為無效搜尋。
		 * @param string   $term   搜尋關鍵字。
		 * @param WP_Query $query  查詢物件。
		 */
		$reject = (bool) apply_filters( 'ph_reject_search', $reject, $term, $query );

		if ( $reject ) {
			$query->set( 'post__in', array( 0 ) );
			$query->set( 'no_found_rows', true );
			return;
		}

		// 限縮查詢範圍，讓 MySQL 得以先用 type_status_date 索引收斂。
		$query->set( 'post_type', apply_filters( 'ph_search_post_types', array( 'post' ) ) );
		$query->set( 'post_status', 'publish' );
		$query->set( 'posts_per_page', PH_SEARCH_PER_PAGE );
		$query->set( 'no_found_rows', true );
		$query->set( 'ignore_sticky_posts', true );

		if ( PH_SEARCH_TITLE_ONLY && version_compare( get_bloginfo( 'version' ), '6.2', '>=' ) ) {
			$query->set( 'search_columns', array( 'post_title', 'post_excerpt' ) );
		}

		if ( PH_SEARCH_MAX_PAGES > 0 && (int) $query->get( 'paged' ) > PH_SEARCH_MAX_PAGES ) {
			$query->set( 'post__in', array( 0 ) );
		}
	}, 20 );
}

/* -------------------------------------------------------------------------
 * 2. 封存頁查詢瘦身
 *
 * 標籤數量常遠超過文章數，等同大量可爬取的動態 URL。
 * SQL_CALC_FOUND_ROWS 會強迫 MySQL 掃完全部符合列才回傳一頁。
 * ---------------------------------------------------------------------- */

if ( PH_ARCHIVE_HARDENING ) {

	add_action( 'pre_get_posts', function ( $query ) {

		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( $query->is_tag() || $query->is_tax() || $query->is_author() || $query->is_date() ) {
			$query->set( 'no_found_rows', true );
			$query->set( 'posts_per_page', PH_ARCHIVE_PER_PAGE );
		}
	}, 20 );

	/**
	 * no_found_rows 會使 max_num_pages 為 0，佈景主題的分頁連結隨之消失。
	 * 標籤／分類法頁可用 term 已儲存的 count 還原總頁數，不需重付
	 * SQL_CALC_FOUND_ROWS 的掃描成本；作者頁與日期頁沒有等價的
	 * 便宜計數，分頁連結維持隱藏（深層分頁本就標記 noindex）。
	 */
	add_filter( 'the_posts', function ( $posts, $query ) {

		if ( is_admin() || ! $query->is_main_query() ) {
			return $posts;
		}

		if ( ( $query->is_tag() || $query->is_tax() ) && $query->get( 'no_found_rows' ) ) {
			$term = $query->get_queried_object();
			if ( $term instanceof WP_Term && $term->count > 0 ) {
				$per_page             = max( 1, (int) $query->get( 'posts_per_page' ) );
				$query->found_posts   = (int) $term->count;
				$query->max_num_pages = (int) ceil( $term->count / $per_page );
			}
		}

		return $posts;
	}, 10, 2 );
}

/**
 * 次要查詢一律關閉 SQL_CALC_FOUND_ROWS。
 * 側邊欄、相關文章、熱門文章等 widget 不需要總筆數。
 * 若某個次要查詢確實需要分頁，於該處明確設定 'no_found_rows' => false。
 */
add_action( 'pre_get_posts', function ( $query ) {

	if ( is_admin() || $query->is_main_query() ) {
		return;
	}

	if ( ! isset( $query->query_vars['no_found_rows'] ) || false === $query->query_vars['no_found_rows'] ) {
		$query->set( 'no_found_rows', true );
	}
}, 5 );

/* -------------------------------------------------------------------------
 * 3. 端點層級的快取標頭與 Feed 策略
 * ---------------------------------------------------------------------- */

if ( PH_CACHE_HEADERS ) {

	add_action( 'template_redirect', function () {

		// 3a. Feed。
		if ( is_feed() ) {

			$low_value = is_author() || is_search() || is_comment_feed();

			if ( 'off' === PH_FEED_MODE ) {
				$low_value = $low_value || is_tag();
			}

			if ( 'cache' !== PH_FEED_MODE && $low_value ) {
				status_header( 410 );
				header( 'Cache-Control: public, max-age=86400, s-maxage=604800' );
				header( 'X-Robots-Tag: noindex, nofollow', true );
				exit;
			}

			if ( is_tag() ) {
				header( 'Cache-Control: public, max-age=1800, s-maxage=21600' );
				header( 'X-Robots-Tag: noindex, follow', true );
				return;
			}

			// 主 Feed 與分類 Feed：內容合作夥伴常態使用，維持較短的更新週期。
			header( 'Cache-Control: public, max-age=300, s-maxage=1800' );
			return;
		}

		// 3b. 搜尋頁。
		if ( is_search() ) {
			header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
			header( 'Cache-Control: public, max-age=60, s-maxage=1800' );
			return;
		}

		// 3c. 標籤頁：薄內容標記 noindex，避免搜尋引擎反覆回爬。
		if ( is_tag() ) {
			if ( PH_THIN_TERM_COUNT > 0 ) {
				$term = get_queried_object();
				if ( $term instanceof WP_Term && $term->count <= PH_THIN_TERM_COUNT ) {
					header( 'X-Robots-Tag: noindex, follow', true );
				}
			}
			header( 'Cache-Control: public, max-age=300, s-maxage=7200' );
			return;
		}

		// 3d. 作者頁與深層分頁。
		$deep = PH_DEEP_PAGE_NOINDEX > 0
			&& is_archive()
			&& (int) get_query_var( 'paged' ) > PH_DEEP_PAGE_NOINDEX;

		if ( is_author() || $deep ) {
			header( 'X-Robots-Tag: noindex, follow', true );
		}
	}, 1 );

	/**
	 * 3e. 404 快取標頭。
	 *
	 * 掛在較晚的優先度，以覆蓋主題或其他外掛送出的 nocache_headers()。
	 * 讓 CDN 得以吸收機器人產生的大量重複 404；s-maxage 保守設定，
	 * 避免文章上架初期的暫時性 404 被長時間快取。
	 */
	add_action( 'template_redirect', function () {
		if ( is_404() ) {
			header( 'Cache-Control: public, max-age=300, s-maxage=600', true );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}, 999 );
}

if ( PH_TAG_FEED_ITEMS > 0 ) {
	add_filter( 'pre_option_posts_per_rss', function ( $value ) {
		if ( is_feed() && is_tag() ) {
			return PH_TAG_FEED_ITEMS;
		}
		return $value;
	} );
}

if ( PH_REMOVE_FEED_LINKS ) {
	// 移除 tag / author / comment feed 的 discovery link。
	// 注意：這同時會移除分類 Feed 的 discovery link，已知合作夥伴的既有 URL 不受影響。
	remove_action( 'wp_head', 'feed_links_extra', 3 );
}

/* -------------------------------------------------------------------------
 * 4. Heartbeat 調頻
 *
 * 後台為真實編輯者使用，不可封鎖，僅降低頻率。
 * ---------------------------------------------------------------------- */

if ( PH_HEARTBEAT_TUNING ) {

	add_filter( 'heartbeat_settings', function ( $settings ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$settings['interval'] = ( $screen && 'edit' === $screen->base )
			? PH_HEARTBEAT_LIST
			: PH_HEARTBEAT_EDIT;

		$settings['minimalInterval'] = PH_HEARTBEAT_EDIT;

		return $settings;
	} );

	// 前台不需要 Heartbeat。
	add_action( 'init', function () {
		if ( ! is_admin() ) {
			wp_deregister_script( 'heartbeat' );
		}
	}, 1 );
}

/* -------------------------------------------------------------------------
 * 5. 停用 oEmbed 端點
 *
 * /{permalink}/embed/ 是常見的爬蟲深淵，且多數新聞站並不依賴它。
 * ---------------------------------------------------------------------- */

if ( PH_DISABLE_OEMBED ) {

	add_action( 'init', function () {
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		add_filter( 'embed_oembed_discover', '__return_false' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	}, 9999 );

	add_filter( 'rewrite_rules_array', function ( $rules ) {
		foreach ( $rules as $rule => $rewrite ) {
			if ( false !== strpos( $rewrite, 'embed=true' ) ) {
				unset( $rules[ $rule ] );
			}
		}
		return $rules;
	} );
}

/* -------------------------------------------------------------------------
 * 6. 外部 HTTP 請求節流
 *
 * 注意：http_request_timeout filter 只影響預設值，呼叫端明確傳入
 * timeout 時無效，因此改用 http_request_args。
 *
 * 僅限制「未登入訪客的前台頁面請求」——REST / AJAX / 後台 / cron /
 * WP-CLI / 已登入者一律不限制。TTS、翻譯、AI 類外掛常透過 REST 呼叫
 * 外部 API 且 is_admin() 為 false，若一併套用會造成 cURL error 28。
 * ---------------------------------------------------------------------- */

add_filter( 'wp_feed_cache_transient_lifetime', function () {
	return PH_FEED_CACHE_HOURS * HOUR_IN_SECONDS;
}, 100 );

if ( PH_HTTP_THROTTLE ) {

	add_filter( 'http_request_args', function ( $args, $url ) {

		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return $args;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $args;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $args;
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return $args;
		}

		$args['timeout'] = min( (int) ( $args['timeout'] ?? PH_FRONTEND_TIMEOUT ), PH_FRONTEND_TIMEOUT );

		return $args;
	}, 100, 2 );
}

// 移除會觸發外部抓取的儀表板 widget。
add_action( 'wp_dashboard_setup', function () {
	remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
	remove_meta_box( 'dashboard_secondary', 'dashboard', 'side' );
	remove_meta_box( 'w3tc_latest', 'dashboard', 'normal' );
}, 100 );

/* -------------------------------------------------------------------------
 * 7. XML-RPC / pingback / REST 使用者列舉
 * ---------------------------------------------------------------------- */

if ( PH_DISABLE_XMLRPC ) {

	add_filter( 'xmlrpc_enabled', '__return_false' );

	add_filter( 'wp_headers', function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	} );
}

if ( PH_BLOCK_USER_ENUM ) {

	add_filter( 'rest_endpoints', function ( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		return $endpoints;
	} );
}

/* -------------------------------------------------------------------------
 * 8. robots.txt
 *
 * 僅在網站根目錄沒有實體 robots.txt 時生效。
 * Sitemap 位址自動偵測 Yoast / Rank Math，否則採用核心的 wp-sitemap.xml。
 * ---------------------------------------------------------------------- */

if ( PH_MANAGE_ROBOTS_TXT ) {

	add_filter( 'robots_txt', function ( $output, $public ) {

		if ( '1' != $public ) {
			return $output;
		}

		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			$sitemap = home_url( '/sitemap_index.xml' );
		} else {
			$sitemap = home_url( '/wp-sitemap.xml' );
		}

		$rules = array(
			'User-agent: *',
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
			'Disallow: /*?s=',
			'Disallow: /search/',
			'Disallow: /tag/*/feed/',
			'Disallow: /author/',
			'Disallow: /*/embed/',
			'Disallow: /xmlrpc.php',
			'Disallow: /wp-json/',
			'',
		);

		foreach ( apply_filters( 'ph_blocked_bots', array( 'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot' ) ) as $bot ) {
			$rules[] = 'User-agent: ' . $bot;
			$rules[] = 'Disallow: /';
			$rules[] = '';
		}

		$rules[] = 'Sitemap: ' . $sitemap;

		/**
		 * 允許覆寫整份 robots.txt 規則。
		 *
		 * @param array $rules 每個元素為一行。
		 */
		$rules = apply_filters( 'ph_robots_txt_rules', $rules );

		return implode( "\n", $rules ) . "\n";
	}, 10, 2 );
}
