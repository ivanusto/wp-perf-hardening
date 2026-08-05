# Omni Performance Hardening

一支單檔外掛（可作為一般外掛或 must-use plugin 安裝），用來收斂 WordPress 站台上最消耗資源的幾類請求：站內搜尋的全表掃描、封存頁的 `SQL_CALC_FOUND_ROWS`、低價值 Feed 與外部 oEmbed 探索，並為 CDN 提供正確的快取標頭。

適用情境為**內容量大、爬蟲流量高的站台**（新聞、媒體、內容聚合），特別是文章數上萬、標籤數逼近或超過文章數的站。

## 解決什麼問題

| 症狀 | 成因 | 本外掛的處理 |
|---|---|---|
| PHP-FPM worker 被十秒級請求佔滿 | 站內搜尋 `LIKE '%...%'` 無法使用索引 | 過濾垃圾關鍵字、限縮 `post_type` / `post_status`、選擇性只比對標題與摘要 |
| 資料庫 CPU 持續偏高 | 側邊欄 widget 的 `SQL_CALC_FOUND_ROWS` 掃描全部符合列 | 次要查詢可選擇性套用 `no_found_rows`（預設關閉，見下方警告） |
| 爬蟲抓取量遠超實際內容量 | 每個 term 都有封存頁與 Feed，數量等同 term 總數 | 薄內容標籤頁 `noindex`、低價值 Feed 回 410 |
| CDN 顯示 `cf-cache-status: DYNAMIC` | 404 與封存頁缺少可快取的 `Cache-Control` | 依端點類型送出對應的 `max-age` / `s-maxage` |
| 訪客請求被外部 API 拖住 | 外掛在前台呼叫第三方服務且逾時設定過長 | 僅對「未登入訪客的前台請求」套用逾時上限 |

## 安裝

### 方式 A：一般外掛（建議給需要後台調參的站台）

1. 從 GitHub 下載 ZIP（Code → Download ZIP），或抓 [Releases](https://github.com/ivanusto/omni-wp-perf-hardening/releases) 的外掛壓縮檔
2. 後台「外掛 → 安裝外掛 → 上傳外掛」上傳並啟用
3. 於「設定 → Omni 效能強化」逐項調整參數

啟用與停用時會自動重建 rewrite rules，無需手動 flush。

### 方式 B：mu-plugin（建議給有主機權限、想確保外掛無法被後台停用的站台）

```bash
# must-use plugin 無需啟用，放進去即生效
sudo cp omni-performance-hardening.php /var/www/html/wp-content/mu-plugins/
sudo chown www-data:www-data /var/www/html/wp-content/mu-plugins/omni-performance-hardening.php
sudo php -l /var/www/html/wp-content/mu-plugins/omni-performance-hardening.php
```

外掛會在版本變動時自動重建 rewrite rules（mu-plugin 模式沒有啟用 hook，故改以版本編號比對觸發），一般無需手動處理。若 `/embed/` 之類的路由行為與設定不符，可執行 `wp rewrite flush --path=/var/www/html` 強制重建。

mu-plugin 模式同樣有「設定 → Omni 效能強化」後台頁可用。兩種方式同時安裝時只有先載入的 mu-plugin 版生效。

介面源語言為英文，`languages/` 內附繁體中文（zh_TW）翻譯，依站台語系自動切換。mu-plugin 模式如需翻譯，請將 `languages/` 資料夾一併放入 `mu-plugins/`。

若站台使用頁面快取，安裝後請一併清除。

## 設定

參數有三層優先序：

1. **`wp-config.php` 常數**（最高）— 定義於「That's all, stop editing!」註解之前，適合用版本控管配置的站台；已定義的常數會鎖定後台對應欄位
2. **後台「設定 → Omni 效能強化」** — 存於 options，適合交給站台管理員自行調整
3. **內建預設值**

常數命名規則為 `PH_` + 下表鍵名大寫，例如後台的「作者頁收斂」對應 `PH_AUTHOR_HARDENING`。

### 功能開關

| 常數 | 預設 | 說明 |
|---|---|---|
| `PH_SEARCH_HARDENING` | `true` | 站內搜尋防護 |
| `PH_ARCHIVE_HARDENING` | `true` | 封存頁查詢瘦身 |
| `PH_SECONDARY_NO_FOUND_ROWS` | `false` | 次要查詢略過總筆數計算。**開啟前請詳讀下方警告** |
| `PH_CACHE_HEADERS` | `true` | 端點層級的快取與 robots 標頭 |
| `PH_AUTHOR_HARDENING` | `false` | 作者頁收斂：作者頁 `noindex`、author feed 回 410、robots.txt 封鎖 `/author/`。多數站台需要作者頁，故預設關閉；不希望作者頁進搜尋結果時設為 `true` |
| `PH_HEARTBEAT_TUNING` | `true` | Heartbeat 調頻 |
| `PH_DISABLE_OEMBED_EXTERNAL` | `true` | 停用外部 oEmbed 探索（`embed_oembed_discover`）。不影響核心 provider 清單上的 YouTube、X、Vimeo 等 |
| `PH_DISABLE_OEMBED_ROUTES` | `false` | 停用自家的 oEmbed REST 端點、discovery link 與 `/embed/` 路由。**會使自家文章的內部嵌入失效**，故預設關閉 |
| `PH_DISABLE_XMLRPC` | `true` | 停用 XML-RPC 與 pingback |
| `PH_HTTP_THROTTLE` | `true` | 前台外部 HTTP 請求逾時上限 |
| `PH_MANAGE_ROBOTS_TXT` | `true` | 接管虛擬 robots.txt |
| `PH_BLOCK_USER_ENUM` | `true` | 停用未登入者的 REST 使用者列舉 |
| `PH_DASHBOARD_WIDGETS` | `true` | 移除「WordPress 活動及新聞」與 W3 Total Cache 最新消息 widget |

#### oEmbed 的兩段式設定

1.7.0 之前只有單一的 `PH_DISABLE_OEMBED`，同時移除外部探索與自家端點。後者會讓「貼上自家文章網址自動變成卡片」的內部嵌入失效——WordPress 的內部嵌入依賴 `wp_oembed_register_route`（`/wp-json/oembed/1.0/embed` 端點）、`wp_oembed_add_discovery_links`（`<head>` 的 `json+oembed` 連結）與 `/embed/` 的 rewrite rule，三者缺一即壞，故拆成兩個開關並將路由改為預設保留。

`/embed/` 的爬取成本改以標頭收斂：保留路由時，該端點會送出 `X-Robots-Tag: noindex, follow` 與 `Cache-Control: public, max-age=3600, s-maxage=86400`，重複抓取由 CDN 邊緣吸收。**刻意不用 `Disallow`**——爬蟲被擋在門外就讀不到 `noindex`，既有的 `/embed/` 網址反而可能以「已建立索引但遭封鎖」的狀態長期滯留；`Disallow: /*/embed/` 僅在 `PH_DISABLE_OEMBED_ROUTES` 為 `true`（端點確實已移除）時才會寫入 robots.txt。

**既有的 `PH_DISABLE_OEMBED` 仍然有效**，會同時鎖定上述兩個欄位，`wp-config.php` 不需修改。若站台使用內部嵌入，將其改為 `false`、或移除後改用細分常數：

```php
// 保留內部嵌入所需的端點，僅停用外部探索。
define( 'PH_DISABLE_OEMBED_EXTERNAL', true );
define( 'PH_DISABLE_OEMBED_ROUTES', false );
```

### 搜尋

| 常數 | 預設 | 說明 |
|---|---|---|
| `PH_SEARCH_MIN_LEN` | `2` | 關鍵字最短長度（以 `mb_strlen` 計） |
| `PH_SEARCH_MAX_LEN` | `40` | 關鍵字最長長度 |
| `PH_SEARCH_MAX_WORDS` | `4` | 空白分隔詞數上限，`0` 停用 |
| `PH_SEARCH_MAX_PAGES` | `3` | 搜尋結果頁數上限，`0` 停用 |
| `PH_SEARCH_PER_PAGE` | `10` | 每頁筆數 |
| `PH_SEARCH_TITLE_ONLY` | `true` | 只比對標題與摘要（需 WP 6.2+） |
| `PH_SEARCH_LATIN_MAX` | `20` | 非中日韓長字串的垃圾判定門檻，`0` 停用 |
| `PH_SEARCH_NO_FOUND_ROWS` | `false` | 搜尋查詢略過總筆數計算。**開啟會使搜尋分頁連結消失**（見下方說明） |

`PH_SEARCH_TITLE_ONLY` 是效能與涵蓋率的取捨。中文新聞站的關鍵字多半出現在標題，開啟後掃描成本大幅下降；若使用者反映搜尋不到內文關鍵字，設為 `false` 即可，其餘防護不受影響。

`PH_SEARCH_NO_FOUND_ROWS` 自 1.8.0 起為獨立設定並預設關閉（處理方式同 1.6.0 的 `PH_SECONDARY_NO_FOUND_ROWS`）。1.8.0 之前搜尋防護會無條件套用 `no_found_rows`，`found_posts` 與 `max_num_pages` 因此為 `0`，佈景主題不輸出分頁連結，原本上百頁的搜尋看起來只剩一頁。搜尋不像標籤頁有現成的 term count 可便宜還原總頁數，省下這筆計算就只能捨棄分頁，故交由站台自行取捨。另外注意 `PH_SEARCH_MAX_PAGES`（預設 `3`）仍會讓超過上限的分頁回空結果，需要更深的搜尋分頁請一併調高或設為 `0`。

### 封存頁與 Feed

| 常數 | 預設 | 說明 |
|---|---|---|
| `PH_ARCHIVE_PER_PAGE` | `10` | 封存頁每頁筆數 |
| `PH_THIN_TERM_COUNT` | `2` | 文章數低於此值的標籤頁標記 `noindex`，`0` 停用 |
| `PH_DEEP_PAGE_NOINDEX` | `5` | 分頁超過此頁數標記 `noindex`，`0` 停用 |
| `PH_FEED_MODE` | `'strict'` | 見下表 |
| `PH_REMOVE_FEED_LINKS` | `true` | 移除 `<head>` 中額外 Feed 的 discovery link |
| `PH_TAG_FEED_ITEMS` | `10` | Tag feed 項目數，`0` 不調整 |
| `PH_FEED_CACHE_HOURS` | `6` | 外部 Feed 抓取結果的快取時數 |

`PH_FEED_MODE` 的三種值：

| 值 | 行為 |
|---|---|
| `'cache'` | 保留全部 Feed，僅加上快取標頭。最保守，適合有內容合作夥伴訂閱多種 Feed 的站台 |
| `'strict'` | author / search / comment feed 回 410；tag feed 保留但縮短項目數並拉長 CDN 快取（預設） |
| `'off'` | 上述全部回 410，僅保留主 Feed 與分類 Feed |

**部署前請先確認合作夥伴訂閱的 Feed 路徑。** 若對方訂閱 tag feed，`'off'` 會直接斷線。

### 其他

| 常數 | 預設 | 說明 |
|---|---|---|
| `PH_FRONTEND_TIMEOUT` | `5` | 未登入訪客的前台請求，外部 HTTP 呼叫逾時上限（秒） |
| `PH_HEARTBEAT_EDIT` | `60` | 編輯畫面的 Heartbeat 間隔（秒） |
| `PH_HEARTBEAT_LIST` | `120` | 文章列表的 Heartbeat 間隔（秒） |

## 提供的 Filter

| Filter | 說明 |
|---|---|
| `omni_performance_hardening_reject_search` | `( bool $reject, string $term, WP_Query $query )` 覆寫垃圾搜尋判定 |
| `omni_performance_hardening_search_post_types` | `( array $post_types )` 調整搜尋涵蓋的 post type |
| `omni_performance_hardening_blocked_bots` | `( array $bots )` robots.txt 中封鎖的 UA 清單 |
| `omni_performance_hardening_robots_txt_rules` | `( array $rules )` 覆寫整份 robots.txt，每個元素為一行 |

## 已知的相容性事項

- **`PH_SECONDARY_NO_FOUND_ROWS` 會讓部分佈景主題與頁面建構器的文章清單變成空白。** 這是本外掛唯一可能造成版面「整塊消失」的設定，因此預設關閉。原理是 `no_found_rows` 會使 `found_posts` 與 `max_num_pages` 為 `0`，而不少工具會據此判斷要不要輸出清單——例如 **Elementor 的文章 widget 在 `found_posts` 為 `0` 時會直接中止渲染**，連容器與「找不到文章」提示都不會產生，站長只會看到一片空白。OceanWP + Elementor Pro 的組合已確認會發生。開啟後請逐一巡視首頁、彙整頁與所有含文章清單的頁面，確認都正常再上線。
- **作者頁與日期封存頁的分頁連結會隱藏。** `no_found_rows` 使 `max_num_pages` 為 0，佈景主題因此不輸出上下頁連結。標籤／分類法頁已用 term 的既有 `count` 還原總頁數，不受影響；作者頁與日期頁沒有等價的便宜計數，且深層分頁本就標記 `noindex`，故維持現狀。
- **前台的 Heartbeat script 已停用。** 極少數外掛（如前台即時通知類）依賴前台 Heartbeat，若有此需求將 `PH_HEARTBEAT_TUNING` 設為 `false`。
- **REST 型外掛不受逾時限制。** TTS、翻譯、AI 類外掛常透過 `register_rest_route` 呼叫外部 API，此時 `is_admin()` 為 `false`。本外掛已排除 REST / AJAX / cron / WP-CLI / 已登入者，若仍遇到 `cURL error 28`，將 `PH_HTTP_THROTTLE` 設為 `false`。
- **`PH_REMOVE_FEED_LINKS` 會一併移除分類 Feed 的 discovery link。** 既有的 Feed URL 仍可正常存取，僅影響自動探索。
- **`PH_DISABLE_OEMBED_ROUTES` 會使自家文章的內部嵌入無法顯示。** WordPress 仍會輸出嵌入卡片的 `<iframe>`，但它初始為 `visibility: hidden`，要等 iframe 內容（`/{permalink}/embed/`）回傳高度才會現形；路由移除後該網址回 404，卡片因此永遠不顯示，訪客只看得到後備的 `<blockquote>` 純連結。把內部嵌入當成內容編排一部分的站台請維持預設關閉（1.7.0 起已是預設）。自 1.6.0 以前升級且曾勾選「停用 oEmbed」者，該值不會沿用到路由開關，升級後內部嵌入即恢復；仍想移除路由請於後台重新勾選。
- **既有的嵌入結果會被快取。** WordPress 將 oEmbed 結果存於 `oembed_cache` post type 與 `_oembed_*` postmeta，先前失敗的結果不會自動重抓。恢復端點後請重新儲存該篇文章，或執行 `wp transient delete --all`。
- **robots.txt 僅在無實體檔案時生效。** 若根目錄存在 `robots.txt`，WordPress 的 `robots_txt` filter 不會被呼叫。
- **CDN 若代管 robots.txt**（例如 Cloudflare Managed robots.txt），其內容會附加在本外掛的規則之前，兩者並存。

## 驗證

```bash
SITE=https://example.com

# 垃圾搜尋應快速回應且無結果
curl -s -o /dev/null -w "%{time_total}\n" "$SITE/?s=random+long+spam+keyword+string"

# 正常搜尋
curl -s -o /dev/null -w "%{time_total}\n" "$SITE/?s=keyword"

# 主 Feed 應為 200
curl -s -o /dev/null -D - "$SITE/feed/" | head -1

# author feed 應為 410（Feed 策略非 'cache' 且作者頁收斂開啟時）
curl -s -o /dev/null -D - "$SITE/author/admin/feed/" | head -1

# robots.txt
curl -s "$SITE/robots.txt"
```

內部嵌入（`PH_DISABLE_OEMBED_ROUTES` 為 `false` 時）：

```bash
SITE=https://example.com
POST=$SITE/2026/01/01/some-post/

# oEmbed 端點應回 200 與 JSON
curl -s "$SITE/wp-json/oembed/1.0/embed?url=$POST" | head -c 200

# <head> 應有 discovery link
curl -s "$POST" | grep -o 'json+oembed[^>]*' | head -2

# /embed/ 應回 200，並帶 noindex 與長效 s-maxage
curl -s -o /dev/null -D - "${POST}embed/" | grep -iE "^(HTTP|x-robots|cache-control)"
```

搭配伺服器端指標觀察：

```bash
# 全表掃描的成長速率
sudo mysql -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Handler_read_rnd_next','Select_scan');"

# PHP 慢請求
sudo grep -c 'executing too slow' /var/log/php8.4-fpm.log
```

## 這支外掛不做的事

效能問題往往不只一處，以下項目需要在 WordPress 之外處理：

- **wp-cron 由訪客觸發** — 於 `wp-config.php` 設定 `DISABLE_WP_CRON`，改由系統 cron 執行 `wp cron event run --due-now`
- **`pm.max_children` 過高** — 在低核心數機器上過度並行會造成 CPU 爭用，反而使所有請求變慢
- **CDN 未快取 HTML** — 多數 CDN 預設不快取 HTML，需明確建立快取規則
- **軟 404** — 主題若將失效網址重導向到回應 200 的頁面，搜尋引擎會永久保留失效索引
- **原站可被繞過 CDN 直連** — 需以共用密鑰標頭或防火牆政策限制來源

## 姊妹作品

[Omni Webmaster & SEO Suite](https://wordpress.org/plugins/omni-webmaster-seo-suite/)（已上架 WordPress.org）為同團隊出品的 SEO 與站長工具，與本外掛互補：本外掛負責效能與爬取收斂，SEO Suite 負責曝光與索引。

## 授權

GPL-2.0-or-later
