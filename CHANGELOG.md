# Changelog

本專案遵循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.1.0/) 格式，
版本號採用 [語意化版本](https://semver.org/lang/zh-TW/)。

## [1.9.0] - 2026-08-05

### 變更

- **`PH_SEARCH_TITLE_ONLY` 預設改為 `false`。** 原本預設只比對標題與摘要，
  只出現在文章內文的關鍵字一律搜尋不到——使用者搜得到什麼，取決於編輯有沒有
  把該詞寫進標題，這對訪客而言是無法預期的行為。改為預設搜尋內文，涵蓋率
  優先；搜尋負載確實成為問題的站台再開啟。
  **注意**：開啟此項會讓搜尋回到對 `post_content` 做 `LIKE '%關鍵字%'`，
  亦即本外掛原本要收斂的全表掃描，文章數多的站台請觀察 DB 負載後再決定
- **`PH_SEARCH_MAX_PAGES` 預設由 `3` 改為 `10`。** 1.8.0 讓搜尋分頁連結恢復
  顯示後，只有 3 頁上限會使訪客看得到卻點不到第 4 頁以後的結果。放寬至 10 頁，
  仍保留對深層分頁探測的上限；完全不需要上限者設為 `0`

**既有站台請注意**：後台設定一經儲存，所有欄位都會寫入 options，此後預設值
的變動不再影響該站。要套用新預設，請於「設定 → Omni 效能強化」手動取消勾選
「只比對標題與摘要」並將「結果頁數上限」改為 `10`，或於 `wp-config.php` 定義
對應常數。全新安裝與從未儲存過設定的站台則直接適用新預設。

### 新增

- **後台設定頁的每個欄位都有說明文字了。** `search_min_len`、`search_max_len`、
  `search_per_page`、`archive_per_page` 與兩個 Heartbeat 間隔原本是沒有任何
  提示的數字框；儲存時套用的夾限（每頁筆數最小 1、Heartbeat 限制在 15–300）
  也一併寫入說明，先前在介面上完全看不到
- 三個與「筆數計算」有關的設定改用白話說明。原本以 `SQL_CALC_FOUND_ROWS`、
  `found_posts` 等詞撰寫，對於正在決定要不要勾選的站長並無意義；技術細節
  留在 README.md 與本檔。封存頁那條另外寫明標籤／分類法頁的分頁會保留、
  作者頁與日期頁不會——此差異先前只記載於 README.md

## [1.8.0] - 2026-08-05

### 修正

- **搜尋查詢的 `no_found_rows` 改為可選設定並預設關閉**（`PH_SEARCH_NO_FOUND_ROWS`）。
  搜尋防護原本無條件套用此優化且沒有開關，`found_posts` 與 `max_num_pages`
  因此為 `0`，佈景主題不輸出分頁連結——原本上百頁的搜尋結果看起來只剩一頁。
  處理方式同 1.6.0 的次要查詢（`PH_SECONDARY_NO_FOUND_ROWS`）：拆成獨立設定、
  預設關閉，確認不需要搜尋分頁後再自行開啟。
  搜尋沒有如標籤頁 term count 的便宜計數可還原總頁數，省下計算與保留分頁
  兩者無法兼得，故交由站台取捨。
  注意 `PH_SEARCH_MAX_PAGES`（預設 `3`）仍會讓超過上限的分頁回空結果，
  需要更深的搜尋分頁請一併調高或設為 `0`

## [1.7.0] - 2026-08-01

### 修正

- **停用 oEmbed 不再連自家文章的內部嵌入一起關掉。** 原本單一的
  `PH_DISABLE_OEMBED` 會一併移除 `wp_oembed_register_route`（`/wp-json/oembed/1.0/embed`
  端點）、`wp_oembed_add_discovery_links`（`<head>` 的 `json+oembed` 連結）與
  `/embed/` 的 rewrite rule，而 WordPress 的內部嵌入（貼上自家網址自動變成卡片）
  三者缺一即失效。`<iframe>` 仍會輸出，但它要等 `/embed/` 回傳高度才會由
  `visibility: hidden` 現形，路由移除後該網址回 404，卡片因此永遠不顯示，
  只剩後備的純連結，且畫面上沒有任何線索指出原因。
  於實際站台（cyberq.tw）重現，並於 WordPress 7.0.2 驗證修正

### 變更（不相容）

- **`PH_DISABLE_OEMBED` 拆成兩個獨立開關**，取捨不同的兩件事不再綁在一起：

  | 常數 | 預設 | 行為 |
  |---|---|---|
  | `PH_DISABLE_OEMBED_EXTERNAL` | `true` | 停用 `embed_oembed_discover`，不對未知網址發出探測請求 |
  | `PH_DISABLE_OEMBED_ROUTES` | `false` | 停用自家的 oEmbed REST 端點、discovery link 與 `/embed/` 路由 |

  **舊的 `PH_DISABLE_OEMBED` 仍然有效**，會同時鎖定兩個欄位，既有 `wp-config.php`
  不需修改；使用內部嵌入的站台將其設為 `false`，或改用上述細分常數。
  後台曾勾選「停用 oEmbed」者，升級後該值只沿用到外部探索，路由回到新的預設
  （保留），內部嵌入即恢復正常——仍想移除路由請於後台重新勾選

### 新增

- **`/embed/` 端點的快取與 robots 標頭**（隨「快取與 robots 標頭」開關）：
  送出 `X-Robots-Tag: noindex, follow` 與 `Cache-Control: public, max-age=3600, s-maxage=86400`。
  以標頭而非封鎖來收斂爬取成本，功能不受影響
- robots.txt 的 `Disallow: /*/embed/` 改為僅在 `PH_DISABLE_OEMBED_ROUTES` 為 `true`
  時寫入。端點保留時用 `Disallow` 會讓爬蟲讀不到 `noindex`，既有網址可能以
  「已建立索引但遭封鎖」的狀態長期滯留，改以 `noindex` 控制較精準
- 版本升級時自動重建 rewrite rules（比對 `omni_performance_hardening_version` 選項）。
  升級不會觸發啟用 hook，mu-plugin 模式更是完全沒有該 hook，原本需手動 flush

## [1.6.0] - 2026-07-31

### 修正

- **次要查詢的 `no_found_rows` 改為可選設定並預設關閉**（`PH_SECONDARY_NO_FOUND_ROWS`）。
  此行為原本無條件套用且沒有開關，會使 `found_posts` 與 `max_num_pages` 為 `0`；
  凡是依該值判斷是否輸出清單的佈景主題或頁面建構器都會整塊變空白——已確認
  **Elementor 的文章 widget 在 `found_posts` 為 `0` 時會直接中止渲染**，
  連容器與「找不到文章」提示都不會產生，站長無從得知原因。
  於實際站台（OceanWP + Elementor Pro）重現並修正。
  需要此優化者可於後台開啟，開啟後請確認站上所有文章清單仍正常顯示

## [1.5.0] - 2026-07-31

送審 WordPress.org 前的最後調整。

### 變更

- **作者頁收斂（`PH_AUTHOR_HARDENING`）預設改為 `false`。** 多數站台需要作者頁被索引，
  只有少數站台需要收斂，故改為預設關閉、需要時再開啟。連帶影響：預設情況下
  author feed 不再回 410（`strict` 模式僅對 search / comment feed 生效）、
  作者頁不再送出 `noindex`、robots.txt 不再封鎖 `/author/`。
  **既有站台若要維持原本的收斂行為，請於 `wp-config.php` 定義
  `define( 'PH_AUTHOR_HARDENING', true );` 或於後台開啟該項**
- 儀表板新聞 widget 的移除改為可關閉的設定（`PH_DASHBOARD_WIDGETS`，預設 `true`），
  原本為無條件執行。此行為會移除其他外掛（W3 Total Cache）的 widget，
  依 wp.org 審核準則應交由使用者控制
- 姊妹外掛名稱更正為 **Omni Webmaster & SEO Suite**（原缺少 `&`）

## [1.4.0] - 2026-07-31

### 變更（不相容）

- 外掛更名為 **Omni Performance Hardening**；slug 與 text domain 改為
  `omni-performance-hardening`（主檔與翻譯檔一併更名），與 WordPress.org
  送審時由名稱產生的 slug 對齊。**`PH_*` 常數不變**，既有站台不受影響；
  mu-plugin 部署者更新時請改放 `omni-performance-hardening.php` 並移除舊檔
- 全域函式與 filter hook 前綴改為 `omni_performance_hardening_`
- 後台設定的 option 名稱改為 `omni_performance_hardening_settings`
  （尚無正式站使用後台設定，無實際影響）

### 修正

- 內附翻譯改以 `load_textdomain()` 載入（Plugin Check 不再出現
  `load_plugin_textdomain` discouraged 警告）；wp.org 託管翻譯仍由核心自動載入

### 新增

- 說明（readme）與設定頁加入姊妹外掛
  [Omni Webmaster SEO Suite](https://wordpress.org/plugins/omni-webmaster-seo-suite/) 的介紹連結

## [1.3.0] - 2026-07-31

依官方 Plugin Check（PCP）檢測結果調整，為上架 WordPress.org 準備。

### 變更（不相容）

- 全域函式與 filter hook 前綴由 `ph_` 改為 `perf_hardening_`：
  `perf_hardening_reject_search`、`perf_hardening_search_post_types`、
  `perf_hardening_blocked_bots`、`perf_hardening_robots_txt_rules`。
  **`PH_*` 常數不變**，既有站台的 wp-config.php 設定不受影響
- readme.txt 短描述縮短至 150 字元內
- `.gitattributes` 加入 `export-ignore`，`git archive` 產出的發佈包不含開發檔案

## [1.2.0] - 2026-07-31

### 新增

- 字串國際化：介面改以英文為源語言（text domain `perf-hardening`），
  `languages/` 內附繁體中文（zh_TW）翻譯（.po / .mo / .l10n.php）與 POT 樣板
- 依安裝模式自動選用 `load_plugin_textdomain` 或 `load_muplugin_textdomain`；
  mu-plugin 模式如需翻譯，將 `languages/` 一併放入 `mu-plugins/`
- 外掛名稱與描述亦隨語系翻譯（於 Docker WordPress 7.0.2 en_US 與 zh_TW 實測）

## [1.1.0] - 2026-07-31

### 新增

- 後台設定頁（設定 → 效能強化）：所有參數皆可於後台調整，無需接觸 `wp-config.php`
- 參數三層優先序：`wp-config.php` 常數 > 後台設定 > 預設值；已定義的常數會鎖定後台對應欄位
- 作者頁收斂獨立開關（`PH_AUTHOR_HARDENING`）：控制作者頁 `noindex`、author feed 410
  與 robots.txt 的 `/author/` 封鎖，供想保留作者頁曝光的站台關閉
- 以一般外掛安裝的支援：啟用／停用時自動重建 rewrite rules；oEmbed 開關切換時亦自動重建
- 同時以 mu-plugin 與一般外掛安裝時的重複載入保護

### 變更

- 設定讀取由常數改為 `ph_get()` 統一解析；既有以常數部署的站台行為不變
- 授權由 GPL-3.0-or-later 改為 GPL-2.0-or-later，為上架 WordPress.org 外掛目錄準備
- 新增 WordPress.org 格式的 readme.txt（Tested up to 7.0，於 Docker WordPress 7.0.2 實測）

### 修正

- XML-RPC 現在確實全面停用：原本只掛 `xmlrpc_enabled`，該 filter 僅影響需驗證的方法，
  `pingback.ping` 等匿名方法仍可呼叫；補上 `xmlrpc_methods` 清空方法表

## [1.0.0] - 2026-07-31

首次發布。由單一站台的實務調校整理為可跨站重用的版本，所有行為改由常數控制。

### 新增

- 站內搜尋防護：長度、詞數、非法 UTF-8 與非中日韓長字串的垃圾判定；限縮 `post_type` / `post_status`；
  選擇性只比對標題與摘要（WP 6.2+ 的 `search_columns`）；限制結果頁數
- 封存頁查詢瘦身：tag / tax / author / date 封存頁關閉 `SQL_CALC_FOUND_ROWS`；
  標籤／分類法頁以 term count 還原 `max_num_pages`，分頁連結不受影響
- 次要查詢一律套用 `no_found_rows`
- 端點層級快取標頭：Feed、搜尋頁、標籤頁、作者頁、深層分頁、404
- Feed 策略三段式設定（`cache` / `strict` / `off`）
- 薄內容標籤頁與深層分頁的 `X-Robots-Tag: noindex`
- Heartbeat 調頻，前台停用
- 停用 oEmbed 端點與 `/embed/` 路由
- 停用 XML-RPC、pingback、未登入者的 REST 使用者列舉
- 前台外部 HTTP 請求逾時上限（排除 REST / AJAX / cron / WP-CLI / 已登入者）
- 虛擬 robots.txt，自動偵測 Yoast / Rank Math 或核心 sitemap 位址
- Filter：`ph_reject_search`、`ph_search_post_types`、`ph_blocked_bots`、`ph_robots_txt_rules`
