# Changelog

本專案遵循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.1.0/) 格式，
版本號採用 [語意化版本](https://semver.org/lang/zh-TW/)。

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
