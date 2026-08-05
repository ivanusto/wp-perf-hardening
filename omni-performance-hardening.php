<?php
/**
 * Plugin Name:  Omni Performance Hardening
 * Plugin URI:   https://github.com/ivanusto/omni-wp-perf-hardening
 * Description:  Tames the most expensive WordPress endpoints: search table scans, archive SQL_CALC_FOUND_ROWS, low-value feeds, oEmbed and XML-RPC, with CDN-friendly cache headers.
 * Version:      1.8.0
 * Requires PHP: 7.4
 * Requires at least: 5.9
 * Author:       ivanusto
 * Author URI:   https://github.com/ivanusto
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  omni-performance-hardening
 * Domain Path:  /languages
 *
 * 兩種安裝方式：
 * 1. 一般外掛：置於 wp-content/plugins/ 並啟用，於「設定 → Omni 效能強化」調整參數。
 * 2. mu-plugin：置於 wp-content/mu-plugins/omni-performance-hardening.php，無需啟用。
 *    rewrite rules 會於版本變動時自動重建，無需手動 flush。
 *
 * 參數優先序：wp-config.php 的 PH_* 常數 > 後台設定 > 預設值。
 * 已定義的常數會鎖定後台對應欄位。完整清單見 README.md。
 *
 * @package OmniPerformanceHardening
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 同時以 mu-plugin 與一般外掛安裝時，只載入先執行的那份。
if ( defined( 'PH_LOADED' ) ) {
	return;
}
define( 'PH_LOADED', true );

// 用於偵測版本升級，以便重建 rewrite rules；與 PH_* 設定常數無關。
define( 'OMNI_PERFORMANCE_HARDENING_VERSION', '1.8.0' );

// 載入翻譯：源語言為英文，languages/ 內附 zh_TW。
// mu-plugin 模式需將 languages/ 一併放入 mu-plugins/。
// wp.org 託管的翻譯（WP_LANG_DIR/plugins）自 WP 4.6 起由核心自動載入，
// 此處僅補載外掛內附的 languages/，供 GitHub 下載安裝等非目錄來源使用。
add_action( 'init', function () {

	if ( defined( 'WPMU_PLUGIN_DIR' )
		&& 0 === strpos( wp_normalize_path( __FILE__ ), wp_normalize_path( WPMU_PLUGIN_DIR ) ) ) {
		load_muplugin_textdomain( 'omni-performance-hardening', 'languages' );
		return;
	}

	if ( ! is_textdomain_loaded( 'omni-performance-hardening' ) ) {
		$locale = determine_locale();
		load_textdomain(
			'omni-performance-hardening',
			__DIR__ . '/languages/omni-performance-hardening-' . $locale . '.mo',
			$locale
		);
	}
} );

/* -------------------------------------------------------------------------
 * 設定解析：常數 > 後台選項 > 預設值
 * ---------------------------------------------------------------------- */

/**
 * 全部設定的預設值。
 *
 * 鍵名同時對應 wp-config.php 常數（PH_ + 大寫鍵名）與後台欄位。
 */
function omni_performance_hardening_defaults() {
	return array(
		// 功能開關。
		'search_hardening'        => true,
		'archive_hardening'       => true,
		// 會使 found_posts 為 0，可能讓頁面建構器的清單整個不渲染，故預設關閉。
		'secondary_no_found_rows' => false,
		'cache_headers'           => true,
		// 多數站台需要作者頁，預設不收斂；需要時再開啟。
		'author_hardening'        => false,
		'heartbeat_tuning'        => true,
		// 不主動探索未知來源，成本高且對內部嵌入無影響。
		'disable_oembed_external' => true,
		// 會使自家文章的內部嵌入無法顯示（詳見 README），故預設保留路由。
		'disable_oembed_routes'   => false,
		'disable_xmlrpc'          => true,
		'http_throttle'           => true,
		'manage_robots_txt'       => true,
		'block_user_enum'         => true,
		'dashboard_widgets'       => true,

		// 搜尋防護。
		'search_min_len'          => 2,
		'search_max_len'          => 40,
		'search_max_words'        => 4,
		'search_max_pages'        => 3,
		'search_per_page'         => 10,
		'search_title_only'       => true,
		'search_latin_max'        => 20,
		// 會使 found_posts 為 0，佈景主題的搜尋分頁連結隨之消失，故預設關閉。
		'search_no_found_rows'    => false,

		// 封存頁與 Feed。
		'archive_per_page'        => 10,
		'thin_term_count'         => 2,
		'deep_page_noindex'       => 5,
		'feed_mode'               => 'strict',
		'remove_feed_links'       => true,
		'tag_feed_items'          => 10,
		'feed_cache_hours'        => 6,

		// 其他。
		'frontend_timeout'        => 5,
		'heartbeat_edit'          => 60,
		'heartbeat_list'          => 120,
	);
}

/**
 * 取得鎖定某設定的常數名稱；未被常數鎖定時回傳空字串。
 *
 * 1.7.0 起 disable_oembed 拆成 external / routes 兩段，舊的
 * PH_DISABLE_OEMBED 仍同時鎖定兩者，既有 wp-config.php 不需修改。
 *
 * @param string $key omni_performance_hardening_defaults() 中的鍵名。
 * @return string 常數名稱或空字串。
 */
function omni_performance_hardening_locking_constant( $key ) {

	$const = 'PH_' . strtoupper( $key );
	if ( defined( $const ) ) {
		return $const;
	}

	if ( defined( 'PH_DISABLE_OEMBED' )
		&& in_array( $key, array( 'disable_oembed_external', 'disable_oembed_routes' ), true ) ) {
		return 'PH_DISABLE_OEMBED';
	}

	return '';
}

/**
 * 讀取後台儲存值並補齊預設值；不套用 wp-config.php 常數。
 *
 * @return array
 */
function omni_performance_hardening_options() {

	$saved = get_option( 'omni_performance_hardening_settings' );
	$saved = is_array( $saved ) ? $saved : array();

	// 1.7.0 之前的單一 disable_oembed 選項：只承接「不主動探索外部來源」
	// 這一半。內部嵌入所依賴的路由一律回到新的預設（保留），避免升級後
	// 站台仍停在自家文章嵌入無法顯示的狀態；需要移除路由者請重新勾選。
	if ( isset( $saved['disable_oembed'] ) && ! isset( $saved['disable_oembed_external'] ) ) {
		$saved['disable_oembed_external'] = (bool) $saved['disable_oembed'];
	}

	return wp_parse_args( $saved, omni_performance_hardening_defaults() );
}

/**
 * 取得單一設定值。
 *
 * @param string $key omni_performance_hardening_defaults() 中的鍵名。
 * @return mixed
 */
function omni_performance_hardening_get( $key ) {
	static $opts = null;

	$const = omni_performance_hardening_locking_constant( $key );
	if ( '' !== $const ) {
		return constant( $const );
	}

	if ( null === $opts ) {
		$opts = omni_performance_hardening_options();
	}

	return isset( $opts[ $key ] ) ? $opts[ $key ] : null;
}

/* -------------------------------------------------------------------------
 * 1. 站內搜尋防護
 *
 * WordPress 搜尋在 wp_posts 上使用 LIKE '%...%'，無法利用索引，
 * 在數萬列的站台上單筆查詢可達十秒等級，且是機器人最常探測的端點。
 * ---------------------------------------------------------------------- */

if ( omni_performance_hardening_get( 'search_hardening' ) ) {

	add_action( 'parse_query', function ( $query ) {

		if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$term = trim( (string) $query->get( 's' ) );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $term, 'UTF-8' ) : strlen( $term );

		$reject = ( $len < omni_performance_hardening_get( 'search_min_len' ) || $len > omni_performance_hardening_get( 'search_max_len' ) );

		// 非法 UTF-8 一律視為機器探測。
		if ( ! $reject && function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $term, 'UTF-8' ) ) {
			$reject = true;
		}

		// 詞數界限：垃圾探測多為長句關鍵字。
		if ( ! $reject && omni_performance_hardening_get( 'search_max_words' ) > 0
			&& preg_match_all( '/\S+/u', $term ) > omni_performance_hardening_get( 'search_max_words' ) ) {
			$reject = true;
		}

		// 不含中日韓字元的長字串視為垃圾探測（仍允許 AI、Netflix 等短英文詞）。
		if ( ! $reject && omni_performance_hardening_get( 'search_latin_max' ) > 0
			&& $len > omni_performance_hardening_get( 'search_latin_max' )
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
		$reject = (bool) apply_filters( 'omni_performance_hardening_reject_search', $reject, $term, $query );

		if ( $reject ) {
			$query->set( 'post__in', array( 0 ) );
			$query->set( 'no_found_rows', true );
			return;
		}

		// 限縮查詢範圍，讓 MySQL 得以先用 type_status_date 索引收斂。
		$query->set( 'post_type', apply_filters( 'omni_performance_hardening_search_post_types', array( 'post' ) ) );
		$query->set( 'post_status', 'publish' );
		$query->set( 'posts_per_page', omni_performance_hardening_get( 'search_per_page' ) );
		$query->set( 'ignore_sticky_posts', true );

		/**
		 * no_found_rows 會使 found_posts 與 max_num_pages 為 0，佈景主題因此
		 * 不輸出分頁連結，上百頁的搜尋結果看起來只剩一頁。1.8.0 起改為
		 * 預設關閉的獨立設定（同 1.6.0 對次要查詢的處理）；搜尋沒有如
		 * 標籤頁 term count 的便宜計數可還原總頁數，省下計算就只能捨棄分頁。
		 */
		if ( omni_performance_hardening_get( 'search_no_found_rows' ) ) {
			$query->set( 'no_found_rows', true );
		}

		if ( omni_performance_hardening_get( 'search_title_only' ) && version_compare( get_bloginfo( 'version' ), '6.2', '>=' ) ) {
			$query->set( 'search_columns', array( 'post_title', 'post_excerpt' ) );
		}

		if ( omni_performance_hardening_get( 'search_max_pages' ) > 0 && (int) $query->get( 'paged' ) > omni_performance_hardening_get( 'search_max_pages' ) ) {
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

if ( omni_performance_hardening_get( 'archive_hardening' ) ) {

	add_action( 'pre_get_posts', function ( $query ) {

		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( $query->is_tag() || $query->is_tax() || $query->is_author() || $query->is_date() ) {
			$query->set( 'no_found_rows', true );
			$query->set( 'posts_per_page', omni_performance_hardening_get( 'archive_per_page' ) );
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
 * 次要查詢關閉 SQL_CALC_FOUND_ROWS（預設關閉，需明確開啟）。
 *
 * 側邊欄、相關文章、熱門文章等 widget 並不需要總筆數，省下的全表掃描相當可觀。
 *
 * 但 no_found_rows 會使 found_posts 與 max_num_pages 為 0，而不少佈景主題與
 * 頁面建構器會據此決定是否輸出清單——例如 Elementor 的文章 widget 在
 * found_posts 為 0 時會直接中止渲染，整個區塊連容器都不會產生，站長只會
 * 看到空白而無從得知原因。因此預設關閉，由使用者確認版面正常後再開啟。
 */
if ( omni_performance_hardening_get( 'secondary_no_found_rows' ) ) {

	add_action( 'pre_get_posts', function ( $query ) {

		if ( is_admin() || $query->is_main_query() ) {
			return;
		}

		if ( ! isset( $query->query_vars['no_found_rows'] ) || false === $query->query_vars['no_found_rows'] ) {
			$query->set( 'no_found_rows', true );
		}
	}, 5 );
}

/* -------------------------------------------------------------------------
 * 3. 端點層級的快取標頭與 Feed 策略
 * ---------------------------------------------------------------------- */

if ( omni_performance_hardening_get( 'cache_headers' ) ) {

	add_action( 'template_redirect', function () {

		// 3a. 內部文章嵌入的 iframe 內容頁（/{permalink}/embed/）。
		//
		// 這是爬蟲的高頻目標，但內部嵌入需要它正常運作，因此不封鎖而是
		// 以標頭收斂：noindex 讓搜尋引擎讀得到並主動排除（Disallow 反而
		// 會讓既有網址停在「已建立索引但遭封鎖」狀態），長效 s-maxage
		// 讓重複抓取由 CDN 邊緣吸收。內容隨文章更新，故 max-age 保守。
		if ( is_embed() ) {
			header( 'X-Robots-Tag: noindex, follow', true );
			header( 'Cache-Control: public, max-age=3600, s-maxage=86400', true );
			return;
		}

		// 3b. Feed。
		if ( is_feed() ) {

			$low_value = ( is_author() && omni_performance_hardening_get( 'author_hardening' ) )
				|| is_search() || is_comment_feed();

			if ( 'off' === omni_performance_hardening_get( 'feed_mode' ) ) {
				$low_value = $low_value || is_tag();
			}

			if ( 'cache' !== omni_performance_hardening_get( 'feed_mode' ) && $low_value ) {
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

		// 3c. 搜尋頁。
		if ( is_search() ) {
			header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
			header( 'Cache-Control: public, max-age=60, s-maxage=1800' );
			return;
		}

		// 3d. 標籤頁：薄內容標記 noindex，避免搜尋引擎反覆回爬。
		if ( is_tag() ) {
			if ( omni_performance_hardening_get( 'thin_term_count' ) > 0 ) {
				$term = get_queried_object();
				if ( $term instanceof WP_Term && $term->count <= omni_performance_hardening_get( 'thin_term_count' ) ) {
					header( 'X-Robots-Tag: noindex, follow', true );
				}
			}
			header( 'Cache-Control: public, max-age=300, s-maxage=7200' );
			return;
		}

		// 3e. 作者頁與深層分頁。
		$deep = omni_performance_hardening_get( 'deep_page_noindex' ) > 0
			&& is_archive()
			&& (int) get_query_var( 'paged' ) > omni_performance_hardening_get( 'deep_page_noindex' );

		if ( ( is_author() && omni_performance_hardening_get( 'author_hardening' ) ) || $deep ) {
			header( 'X-Robots-Tag: noindex, follow', true );
		}
	}, 1 );

	/**
	 * 3f. 404 快取標頭。
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

add_filter( 'pre_option_posts_per_rss', function ( $value ) {
	$items = (int) omni_performance_hardening_get( 'tag_feed_items' );
	if ( $items > 0 && is_feed() && is_tag() ) {
		return $items;
	}
	return $value;
} );

if ( omni_performance_hardening_get( 'remove_feed_links' ) ) {
	// 移除 tag / author / comment feed 的 discovery link。
	// 注意：這同時會移除分類 Feed 的 discovery link，已知合作夥伴的既有 URL 不受影響。
	remove_action( 'wp_head', 'feed_links_extra', 3 );
}

/* -------------------------------------------------------------------------
 * 4. Heartbeat 調頻
 *
 * 後台為真實編輯者使用，不可封鎖，僅降低頻率。
 * ---------------------------------------------------------------------- */

if ( omni_performance_hardening_get( 'heartbeat_tuning' ) ) {

	add_filter( 'heartbeat_settings', function ( $settings ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		$settings['interval'] = ( $screen && 'edit' === $screen->base )
			? omni_performance_hardening_get( 'heartbeat_list' )
			: omni_performance_hardening_get( 'heartbeat_edit' );

		$settings['minimalInterval'] = omni_performance_hardening_get( 'heartbeat_edit' );

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
 * 5. oEmbed
 *
 * 分成兩段獨立控制，因為兩者的取捨完全不同：
 *
 * - 外部探索（預設停用）：對未列於核心 provider 清單的網址發出 HTTP 請求
 *   探測 oEmbed 端點。成本高、失敗率高，且與自家內容無關。
 * - 自家端點與 /embed/ 路由（預設保留）：/{permalink}/embed/ 確實是爬蟲
 *   深淵，但 WordPress 的「內部文章嵌入」（貼上自家網址自動變成卡片）
 *   正是靠這組路由、REST 端點與 <head> 的 discovery link 運作。整組移除
 *   會讓把嵌入當內容編排的站台直接壞掉，故改為需明確開啟。
 *   保留路由時，/embed/ 頁面會送出 noindex 與長效 CDN 快取標頭（見第 3 節），
 *   以標頭而非封鎖來收斂爬取。
 * ---------------------------------------------------------------------- */

if ( omni_performance_hardening_get( 'disable_oembed_external' ) ) {

	add_action( 'init', function () {
		add_filter( 'embed_oembed_discover', '__return_false' );
	}, 9999 );
}

if ( omni_performance_hardening_get( 'disable_oembed_routes' ) ) {

	add_action( 'init', function () {
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
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
	return (int) omni_performance_hardening_get( 'feed_cache_hours' ) * HOUR_IN_SECONDS;
}, 100 );

if ( omni_performance_hardening_get( 'http_throttle' ) ) {

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

		$cap = (int) omni_performance_hardening_get( 'frontend_timeout' );

		$args['timeout'] = min( (int) ( $args['timeout'] ?? $cap ), $cap );

		return $args;
	}, 100, 2 );
}

// 移除會觸發外部抓取的儀表板 widget（WordPress 活動及新聞、W3 Total Cache 最新消息）。
if ( omni_performance_hardening_get( 'dashboard_widgets' ) ) {

	add_action( 'wp_dashboard_setup', function () {
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
		remove_meta_box( 'dashboard_secondary', 'dashboard', 'side' );
		remove_meta_box( 'w3tc_latest', 'dashboard', 'normal' );
	}, 100 );
}

/* -------------------------------------------------------------------------
 * 7. XML-RPC / pingback / REST 使用者列舉
 * ---------------------------------------------------------------------- */

if ( omni_performance_hardening_get( 'disable_xmlrpc' ) ) {

	// xmlrpc_enabled 只擋「需要驗證」的方法，pingback.ping 與
	// system.* 等匿名方法不受影響，必須連方法表一併清空。
	add_filter( 'xmlrpc_enabled', '__return_false' );
	add_filter( 'xmlrpc_methods', '__return_empty_array' );

	add_filter( 'wp_headers', function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	} );
}

if ( omni_performance_hardening_get( 'block_user_enum' ) ) {

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

if ( omni_performance_hardening_get( 'manage_robots_txt' ) ) {

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
		);

		if ( omni_performance_hardening_get( 'author_hardening' ) ) {
			$rules[] = 'Disallow: /author/';
		}

		// 僅在路由已移除時封鎖 /embed/。保留路由時改以 X-Robots-Tag: noindex
		// 控制索引：Disallow 會讓爬蟲讀不到 noindex，既有網址反而可能以
		// 「已建立索引但遭封鎖」的狀態長期滯留。
		if ( omni_performance_hardening_get( 'disable_oembed_routes' ) ) {
			$rules[] = 'Disallow: /*/embed/';
		}

		$rules = array_merge( $rules, array(
			'Disallow: /xmlrpc.php',
			'Disallow: /wp-json/',
			'',
		) );

		foreach ( apply_filters( 'omni_performance_hardening_blocked_bots', array( 'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot' ) ) as $bot ) {
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
		$rules = apply_filters( 'omni_performance_hardening_robots_txt_rules', $rules );

		return implode( "\n", $rules ) . "\n";
	}, 10, 2 );
}

/* -------------------------------------------------------------------------
 * 9. 啟用／停用與版本升級時的 rewrite rules 處理
 *
 * 刪除 rewrite_rules 使其於「下一個」請求依當時的外掛狀態重建，
 * 避免在狀態轉換中的本請求以錯誤的 filter 集合重建。
 * mu-plugin 模式不會觸發這兩個 hook，改由下方的版本比對涵蓋。
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, function () {
	delete_option( 'rewrite_rules' );
} );

register_deactivation_hook( __FILE__, function () {
	delete_option( 'rewrite_rules' );
} );

/**
 * 版本升級處理。
 *
 * oEmbed 設定會改變 rewrite rules 的內容，而升級本身不觸發啟用 hook
 * （mu-plugin 模式連啟用 hook 都沒有），因此以版本編號比對來偵測。
 * 刪除後由核心於本請求依當下的 filter 集合重建，站長無需手動 flush。
 */
add_action( 'init', function () {

	if ( OMNI_PERFORMANCE_HARDENING_VERSION === get_option( 'omni_performance_hardening_version' ) ) {
		return;
	}

	delete_option( 'rewrite_rules' );
	update_option( 'omni_performance_hardening_version', OMNI_PERFORMANCE_HARDENING_VERSION );
}, 5 );

/* -------------------------------------------------------------------------
 * 10. 後台設定頁
 * ---------------------------------------------------------------------- */

if ( is_admin() ) {

	/** 欄位定義：區塊標題 => [ 鍵名 => [ 型別, 標籤, 說明 ] ]。 */
	function omni_performance_hardening_settings_fields() {
		return array(
			__( 'Feature switches', 'omni-performance-hardening' ) => array(
				'search_hardening'  => array( 'bool', __( 'Search hardening', 'omni-performance-hardening' ), __( 'Filter junk search probes and narrow the query scope', 'omni-performance-hardening' ) ),
				'archive_hardening' => array( 'bool', __( 'Archive query slimming', 'omni-performance-hardening' ), __( 'Skips the same count on tag, taxonomy, author and date archives, and caps how many posts each page shows. Tag and taxonomy archives keep their pagination — the total is rebuilt from the post count WordPress already stores for every term — but author and date archives lose their page links', 'omni-performance-hardening' ) ),
				'secondary_no_found_rows' => array( 'bool', __( 'Skip counts on widget queries', 'omni-performance-hardening' ), __( 'Skips counting matches on queries that are not the page\'s main one — sidebar widgets, related posts, page-builder listings. Those lists rarely show a total, so the count is usually wasted work. But anything that reads it to decide whether to render will come up empty: Elementor post widgets in particular leave a blank where the list should be, with nothing on screen to explain why. Off by default. If you turn it on, walk through every page that shows a post list and confirm they all still appear', 'omni-performance-hardening' ) ),
				'cache_headers'     => array( 'bool', __( 'Cache & robots headers', 'omni-performance-hardening' ), __( 'Send Cache-Control and X-Robots-Tag per endpoint type for feeds, search, tag pages and 404s', 'omni-performance-hardening' ) ),
				'author_hardening'  => array( 'bool', __( 'Author page hardening', 'omni-performance-hardening' ), __( 'Author pages get noindex, author feeds return 410 and robots.txt blocks /author/. Off by default; enable it only on sites that do not want author archives in search results', 'omni-performance-hardening' ) ),
				'heartbeat_tuning'  => array( 'bool', __( 'Heartbeat tuning', 'omni-performance-hardening' ), __( 'Slow down admin polling and disable the frontend Heartbeat', 'omni-performance-hardening' ) ),
				'disable_oembed_external' => array( 'bool', __( 'Disable external oEmbed discovery', 'omni-performance-hardening' ), __( 'Stops WordPress from probing arbitrary URLs for an oEmbed endpoint when a link is pasted. Providers on the built-in list — YouTube, X, Vimeo and the rest — still embed normally', 'omni-performance-hardening' ) ),
				'disable_oembed_routes'   => array( 'bool', __( 'Disable oEmbed endpoints and /embed/ routes', 'omni-performance-hardening' ), __( 'Removes this site\'s own oEmbed REST endpoint, its discovery links and the /embed/ pages. Off by default: WordPress needs all three to turn a pasted link to your own post into an embed card, so enabling this leaves those embeds as plain links. Crawl load on /embed/ is already handled with noindex and a long CDN cache. Rewrite rules rebuild automatically after toggling', 'omni-performance-hardening' ) ),
				'disable_xmlrpc'    => array( 'bool', __( 'Disable XML-RPC', 'omni-performance-hardening' ), __( 'Including pingback', 'omni-performance-hardening' ) ),
				'http_throttle'     => array( 'bool', __( 'Frontend external request throttle', 'omni-performance-hardening' ), __( 'Cap external HTTP timeouts on frontend requests from logged-out visitors', 'omni-performance-hardening' ) ),
				'manage_robots_txt' => array( 'bool', __( 'Manage robots.txt', 'omni-performance-hardening' ), __( 'Only takes effect when no physical robots.txt exists in the site root', 'omni-performance-hardening' ) ),
				'block_user_enum'   => array( 'bool', __( 'Block REST user enumeration', 'omni-performance-hardening' ), __( 'Logged-out visitors cannot access /wp/v2/users', 'omni-performance-hardening' ) ),
				'dashboard_widgets' => array( 'bool', __( 'Remove news dashboard widgets', 'omni-performance-hardening' ), __( 'Removes the WordPress Events and News widget, and the W3 Total Cache news widget if present. These fetch remote data every time the dashboard loads', 'omni-performance-hardening' ) ),
			),
			__( 'Search', 'omni-performance-hardening' ) => array(
				'search_min_len'    => array( 'int', __( 'Minimum keyword length', 'omni-performance-hardening' ), __( 'Shorter keywords are rejected before any query runs. Counted in characters, so two-character CJK words still pass', 'omni-performance-hardening' ) ),
				'search_max_len'    => array( 'int', __( 'Maximum keyword length', 'omni-performance-hardening' ), __( 'Longer keywords are treated as junk probes and rejected', 'omni-performance-hardening' ) ),
				'search_max_words'  => array( 'int', __( 'Word count limit', 'omni-performance-hardening' ), __( 'Whitespace-separated words; 0 disables', 'omni-performance-hardening' ) ),
				'search_max_pages'  => array( 'int', __( 'Result pages limit', 'omni-performance-hardening' ), __( '0 disables', 'omni-performance-hardening' ) ),
				'search_per_page'   => array( 'int', __( 'Results per page', 'omni-performance-hardening' ), __( 'Search results page only; archives have their own setting below. Minimum 1', 'omni-performance-hardening' ) ),
				'search_title_only' => array( 'bool', __( 'Match titles and excerpts only', 'omni-performance-hardening' ), __( 'Requires WP 6.2+. Cuts scan cost sharply but misses keywords that appear only in post content', 'omni-performance-hardening' ) ),
				'search_latin_max'  => array( 'int', __( 'Non-CJK long string threshold', 'omni-performance-hardening' ), __( 'Strings longer than this without CJK characters are treated as junk probes; 0 disables', 'omni-performance-hardening' ) ),
				'search_no_found_rows' => array( 'bool', __( 'Skip result count on search', 'omni-performance-hardening' ), __( 'Skips counting how many posts match the search. Counting them is expensive — the database has to scan every match just to reach a total — but themes need that total to work out how many pages of results exist. Without it no pagination links are drawn and results stop at page one. Off by default; before 1.8.0 it was always on. Turn it on only if visitors never page past the first screen of search results', 'omni-performance-hardening' ) ),
			),
			__( 'Archives & feeds', 'omni-performance-hardening' ) => array(
				'archive_per_page'  => array( 'int', __( 'Archive posts per page', 'omni-performance-hardening' ), __( 'Applies to tag, taxonomy, author and date archives. Minimum 1', 'omni-performance-hardening' ) ),
				'thin_term_count'   => array( 'int', __( 'Thin tag threshold', 'omni-performance-hardening' ), __( 'Tag pages with this many posts or fewer are marked noindex; 0 disables', 'omni-performance-hardening' ) ),
				'deep_page_noindex' => array( 'int', __( 'Deep pagination threshold', 'omni-performance-hardening' ), __( 'Pages beyond this number are marked noindex; 0 disables', 'omni-performance-hardening' ) ),
				'feed_mode'         => array( 'select', __( 'Feed policy', 'omni-performance-hardening' ), __( 'Check which feed paths your content partners subscribe to before deploying', 'omni-performance-hardening' ) ),
				'remove_feed_links' => array( 'bool', __( 'Remove extra feed discovery links', 'omni-performance-hardening' ), __( 'Also removes category feed discovery links; existing feed URLs keep working', 'omni-performance-hardening' ) ),
				'tag_feed_items'    => array( 'int', __( 'Tag feed items', 'omni-performance-hardening' ), __( '0 leaves the default untouched', 'omni-performance-hardening' ) ),
				'feed_cache_hours'  => array( 'int', __( 'External feed cache hours', 'omni-performance-hardening' ), __( 'Cache lifetime for fetch_feed() results', 'omni-performance-hardening' ) ),
			),
			__( 'Other', 'omni-performance-hardening' ) => array(
				'frontend_timeout'  => array( 'int', __( 'Frontend external timeout (seconds)', 'omni-performance-hardening' ), __( 'Cap on external HTTP calls during frontend requests from logged-out visitors', 'omni-performance-hardening' ) ),
				'heartbeat_edit'    => array( 'int', __( 'Editor Heartbeat interval (seconds)', 'omni-performance-hardening' ), __( 'How often the editor checks for autosaves and post locks. Higher means fewer admin-ajax calls; clamped to 15-300', 'omni-performance-hardening' ) ),
				'heartbeat_list'    => array( 'int', __( 'List screen Heartbeat interval (seconds)', 'omni-performance-hardening' ), __( 'Polling interval on post list screens; clamped to 15-300', 'omni-performance-hardening' ) ),
			),
		);
	}

	add_action( 'admin_menu', function () {
		add_options_page(
			__( 'Omni Performance Hardening', 'omni-performance-hardening' ),
			__( 'Omni Performance Hardening', 'omni-performance-hardening' ),
			'manage_options',
			'omni-performance-hardening',
			'omni_performance_hardening_render_settings_page'
		);
	} );

	// 儲存：非 Settings API 的精簡表單，POST 回同一頁。
	add_action( 'admin_init', function () {

		if ( ! isset( $_POST['ph_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'ph_save_settings', 'ph_settings_nonce' );

		$defaults = omni_performance_hardening_defaults();
		$old      = omni_performance_hardening_options();
		$post     = wp_unslash( $_POST );
		$new      = array();

		foreach ( $defaults as $key => $default ) {

			// 常數鎖定的欄位不在表單中，保留原儲存值。
			if ( '' !== omni_performance_hardening_locking_constant( $key ) ) {
				$new[ $key ] = $old[ $key ];
				continue;
			}

			if ( 'feed_mode' === $key ) {
				$mode        = isset( $post['ph_feed_mode'] ) ? (string) $post['ph_feed_mode'] : $default;
				$new[ $key ] = in_array( $mode, array( 'cache', 'strict', 'off' ), true ) ? $mode : $default;
			} elseif ( is_bool( $default ) ) {
				$new[ $key ] = ! empty( $post[ 'ph_' . $key ] );
			} else {
				$new[ $key ] = isset( $post[ 'ph_' . $key ] ) ? max( 0, (int) $post[ 'ph_' . $key ] ) : $default;
			}
		}

		// 個別下限，避免 0 造成極端行為。
		$new['search_per_page']  = max( 1, $new['search_per_page'] );
		$new['archive_per_page'] = max( 1, $new['archive_per_page'] );
		$new['frontend_timeout'] = max( 1, $new['frontend_timeout'] );
		$new['feed_cache_hours'] = max( 1, $new['feed_cache_hours'] );
		$new['heartbeat_edit']   = min( 300, max( 15, $new['heartbeat_edit'] ) );
		$new['heartbeat_list']   = min( 300, max( 15, $new['heartbeat_list'] ) );

		update_option( 'omni_performance_hardening_settings', $new );

		// /embed/ 路由開關影響 rewrite rules：刪除後於下一個請求依新設定重建。
		if ( $old['disable_oembed_routes'] !== $new['disable_oembed_routes'] ) {
			delete_option( 'rewrite_rules' );
		}

		wp_safe_redirect( add_query_arg(
			array(
				'page'    => 'omni-performance-hardening',
				'updated' => '1',
			),
			admin_url( 'options-general.php' )
		) );
		exit;
	} );

	function omni_performance_hardening_render_settings_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$opts = omni_performance_hardening_options();

		echo '<div class="wrap"><h1>' . esc_html__( 'Omni Performance Hardening', 'omni-performance-hardening' ) . '</h1>';

		// 僅用於顯示儲存成功通知，不處理任何資料，毋需 nonce。
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Settings saved. Cached pages will reflect changes after the cache expires or is purged.', 'omni-performance-hardening' )
				. '</p></div>';
		}

		printf(
			'<p>%s</p>',
			sprintf(
				/* translators: 1: PH_* 2: wp-config.php */
				esc_html__( 'Priority: %1$s constants in %2$s > settings on this page > defaults. Fields pinned by a defined constant are locked.', 'omni-performance-hardening' ),
				'<code>PH_*</code>',
				'<code>wp-config.php</code>'
			)
		);
		echo '<form method="post">';
		wp_nonce_field( 'ph_save_settings', 'ph_settings_nonce' );

		foreach ( omni_performance_hardening_settings_fields() as $section => $fields ) {

			echo '<h2>' . esc_html( $section ) . '</h2>';
			echo '<table class="form-table" role="presentation">';

			foreach ( $fields as $key => $field ) {

				list( $type, $label, $desc ) = $field;

				$const  = omni_performance_hardening_locking_constant( $key );
				$locked = ( '' !== $const );
				$value  = $locked ? constant( $const ) : $opts[ $key ];
				$name   = 'ph_' . $key;

				echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>';

				if ( 'bool' === $type ) {
					printf(
						'<input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s%3$s>',
						esc_attr( $name ),
						checked( (bool) $value, true, false ),
						disabled( $locked, true, false )
					);
				} elseif ( 'select' === $type ) {
					$modes = array(
						'cache'  => __( 'cache — keep all feeds, only add cache headers', 'omni-performance-hardening' ),
						'strict' => __( 'strict — author / search / comment feeds return 410 (default)', 'omni-performance-hardening' ),
						'off'    => __( 'off — keep only the main feed and category feeds', 'omni-performance-hardening' ),
					);
					echo '<select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"' . disabled( $locked, true, false ) . '>';
					foreach ( $modes as $mode => $text ) {
						echo '<option value="' . esc_attr( $mode ) . '"' . selected( (string) $value, $mode, false ) . '>' . esc_html( $text ) . '</option>';
					}
					echo '</select>';
				} else {
					printf(
						'<input type="number" class="small-text" id="%1$s" name="%1$s" value="%2$s" min="0"%3$s>',
						esc_attr( $name ),
						esc_attr( (int) $value ),
						disabled( $locked, true, false )
					);
				}

				if ( '' !== $desc ) {
					echo '<p class="description">' . esc_html( $desc ) . '</p>';
				}
				if ( $locked ) {
					printf(
						'<p class="description">%s</p>',
						sprintf(
							/* translators: %s: constant name, e.g. PH_SEARCH_HARDENING */
							esc_html__( 'Locked by %s; remove the constant from wp-config.php to edit it here.', 'omni-performance-hardening' ),
							'<code>' . esc_html( $const ) . '</code>'
						)
					);
				}

				echo '</td></tr>';
			}

			echo '</table>';
		}

		submit_button( __( 'Save Settings', 'omni-performance-hardening' ) );
		echo '</form>';

		printf(
			'<hr><p class="description">%s</p>',
			sprintf(
				/* translators: %s: linked sister plugin name */
				esc_html__( 'Sister plugin: %s — SEO and webmaster tooling from the same team. This plugin keeps your site fast and crawl-efficient; the SEO suite handles visibility and indexing.', 'omni-performance-hardening' ),
				'<a href="https://wordpress.org/plugins/omni-webmaster-seo-suite/" target="_blank" rel="noopener">Omni Webmaster &amp; SEO Suite</a>'
			)
		);

		echo '</div>';
	}
}
