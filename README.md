# WordPress Performance Hardening

一支 must-use plugin，用來收斂 WordPress 站台上最消耗資源的幾類請求：站內搜尋的全表掃描、封存頁的 `SQL_CALC_FOUND_ROWS`、低價值 Feed 與 oEmbed 端點，並為 CDN 提供正確的快取標頭。

適用情境為**內容量大、爬蟲流量高的站台**（新聞、媒體、內容聚合），特別是文章數上萬、標籤數逼近或超過文章數的站。

## 解決什麼問題

| 症狀 | 成因 | 本外掛的處理 |
|---|---|---|
| PHP-FPM worker 被十秒級請求佔滿 | 站內搜尋 `LIKE '%...%'` 無法使用索引 | 過濾垃圾關鍵字、限縮 `post_type` / `post_status`、選擇性只比對標題與摘要 |
| 資料庫 CPU 持續偏高 | 側邊欄 widget 的 `SQL_CALC_FOUND_ROWS` 掃描全部符合列 | 次要查詢一律 `no_found_rows` |
| 爬蟲抓取量遠超實際內容量 | 每個 term 都有封存頁與 Feed，數量等同 term 總數 | 薄內容標籤頁 `noindex`、低價值 Feed 回 410 |
| CDN 顯示 `cf-cache-status: DYNAMIC` | 404 與封存頁缺少可快取的 `Cache-Control` | 依端點類型送出對應的 `max-age` / `s-maxage` |
| 訪客請求被外部 API 拖住 | 外掛在前台呼叫第三方服務且逾時設定過長 | 僅對「未登入訪客的前台請求」套用逾時上限 |

## 安裝

```bash
# must-use plugin 無需啟用，放進去即生效
sudo cp perf-hardening.php /var/www/html/wp-content/mu-plugins/
sudo chown www-data:www-data /var/www/html/wp-content/mu-plugins/perf-hardening.php
sudo php -l /var/www/html/wp-content/mu-plugins/perf-hardening.php

# 重建 rewrite rules（oEmbed 路由移除後必要）
sudo -u www-data wp rewrite flush --path=/var/www/html
```

若站台使用頁面快取，請一併清除。

## 設定

所有行為皆由常數控制，定義於 `wp-config.php` 中「That's all, stop editing!」註解之前。未定義者採用預設值。

### 功能開關

| 常數 | 預設 | 說明 |
|---|---|---|
| `PH_SEARCH_HARDENING` | `true` | 站內搜尋防護 |
| `PH_ARCHIVE_HARDENING` | `true` | 封存頁查詢瘦身 |
| `PH_CACHE_HEADERS` | `true` | 端點層級的快取與 robots 標頭 |
| `PH_HEARTBEAT_TUNING` | `true` | Heartbeat 調頻 |
| `PH_DISABLE_OEMBED` | `true` | 停用 oEmbed 端點與 `/embed/` 路由 |
| `PH_DISABLE_XMLRPC` | `true` | 停用 XML-RPC 與 pingback |
| `PH_HTTP_THROTTLE` | `true` | 前台外部 HTTP 請求逾時上限 |
| `PH_MANAGE_ROBOTS_TXT` | `true` | 接管虛擬 robots.txt |
| `PH_BLOCK_USER_ENUM` | `true` | 停用未登入者的 REST 使用者列舉 |

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

`PH_SEARCH_TITLE_ONLY` 是效能與涵蓋率的取捨。中文新聞站的關鍵字多半出現在標題，開啟後掃描成本大幅下降；若使用者反映搜尋不到內文關鍵字，設為 `false` 即可，其餘防護不受影響。

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
| `ph_reject_search` | `( bool $reject, string $term, WP_Query $query )` 覆寫垃圾搜尋判定 |
| `ph_search_post_types` | `( array $post_types )` 調整搜尋涵蓋的 post type |
| `ph_blocked_bots` | `( array $bots )` robots.txt 中封鎖的 UA 清單 |
| `ph_robots_txt_rules` | `( array $rules )` 覆寫整份 robots.txt，每個元素為一行 |

## 已知的相容性事項

- **作者頁與日期封存頁的分頁連結會隱藏。** `no_found_rows` 使 `max_num_pages` 為 0，佈景主題因此不輸出上下頁連結。標籤／分類法頁已用 term 的既有 `count` 還原總頁數，不受影響；作者頁與日期頁沒有等價的便宜計數，且深層分頁本就標記 `noindex`，故維持現狀。
- **前台的 Heartbeat script 已停用。** 極少數外掛（如前台即時通知類）依賴前台 Heartbeat，若有此需求將 `PH_HEARTBEAT_TUNING` 設為 `false`。
- **REST 型外掛不受逾時限制。** TTS、翻譯、AI 類外掛常透過 `register_rest_route` 呼叫外部 API，此時 `is_admin()` 為 `false`。本外掛已排除 REST / AJAX / cron / WP-CLI / 已登入者，若仍遇到 `cURL error 28`，將 `PH_HTTP_THROTTLE` 設為 `false`。
- **`PH_REMOVE_FEED_LINKS` 會一併移除分類 Feed 的 discovery link。** 既有的 Feed URL 仍可正常存取，僅影響自動探索。
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

# author feed 應為 410（PH_FEED_MODE 非 'cache' 時）
curl -s -o /dev/null -D - "$SITE/author/admin/feed/" | head -1

# robots.txt
curl -s "$SITE/robots.txt"
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

## 授權

GPL-3.0-or-later
