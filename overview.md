KayzArt Live Code Editor – Live HTML/CSS/JS Editor with Tailwind CSS
===============================================

概要
----
- KayzArt専用のカスタム投稿タイプ `kayzart` を登録し、通常の投稿/固定ページは対象外。
- 新規作成時は専用エディタ (`admin.php?page=kayzart`) へ自動遷移。既存投稿ではブロック/クラシックに「Edit with KayzArt」ボタンを追加して遷移。
- CodeMirror 6 で HTML/CSS/JavaScript を編集し、右側 iframe に実フロントを即時プレビュー。
- 管理一覧には TailwindCSS 使用状態を表示。

編集UI (React)
-------------
- ツールバー: Back、Undo/Redo、タイトル編集、ステータス変更（下書き/レビュー/非公開/公開）、ビューポート切替（Desktop/Tablet/Mobile）、エディタ表示切替、Save、Export、Settings、プレビュー/表示リンク。未保存の変更を表示し、離脱時に警告。
- エディタ: HTML と CSS/JS タブ、JS は Run ボタンでプレビューへ即時実行。
- ペインは左右/上下リサイズ、設定パネルは「Settings」「Elements」タブで開閉。
- Settings タブ: ページテンプレート/出力/レンダリング/外部リソース/表示の各設定を管理。
- 要素タブ: 選択した要素のテキスト/属性を編集（安全なテキストノードのみ）。

プレビューと DOM セレクタ
--------------------------
- `?kayzart_preview=1&post_id=...&token=...` で実フロントを表示し、`<!--kayzart:start-->...<!--kayzart:end-->` 内を差し替え。`kayzart_template_mode` クエリでプレビュー時テンプレートモードを上書き可能。
- parse5 で `data-kayzart-id` を付与し、ホバー/クリックで該当要素をハイライト。
- 選択時に要素タブを開くアクションボタンを表示し、エディタ/設定と選択状態を同期。
- `theme` レイアウトでテーマ側が `the_content` を出力しない場合、プレビューは検出して `standalone` への切り替えを促す。

セットアップ/インポート
----------------------
- 初回はセットアップウィザードで「Normal」/「Tailwind」/「Import JSON」を選択し、`_kayzart_tailwind_locked` で固定。
- Import/Export JSON v1: HTML/CSS/JS、Tailwind、生成CSS、外部スクリプト/スタイル、Shadow DOM/Shortcode/単一ページ公開/Live Highlight。
- Import時はHTMLをそのまま反映（外部画像の取り込みは行わない）。

Tailwind CSS
------------
- Tailwind モードでは TailwindPHP で CSS を自動コンパイル。
- 生成CSSは `_kayzart_generated_css`、ユーザーCSSは `_kayzart_css` に保存。
- プレビューは `KAYZART_SET_CSS` で CSS だけ差し替え可能。

外部アセット (Script / Style)
------------------------------
- 外部スクリプト: https:// のみ、最大 10 件。プレビュー/フロントで読み込み。
- 外部スタイル: https:// のみ、最大 10 件。プレビュー/フロントに `<link>` で読み込み。

Shadow DOM
----------
- 有効化時は `<template shadowrootmode="open">` で隔離し、CSS/JS/外部スタイルを Shadow root 内に適用。

ショートコード
--------------
- ショートコードは設定で有効化した場合のみ出力。`[kayzart post_id="123"]` で埋め込み可能。公開状態/権限をチェックし、Shadow DOM 設定も尊重。
- ショートコード有効時は「単一ページとして公開しない」を切り替え可能。

テンプレートモード
------------------
- 投稿ごとに `default` / `standalone` / `theme` を選択可能（`default` は管理設定の既定値に追従）。
- `standalone`: プラグイン同梱テンプレートで最小構成表示（テーマのヘッダー/フッターなし）。
- `theme`: テーマ標準の単一投稿テンプレートをそのまま使用。

フロント表示
------------
- KayzArt 投稿の本文を出力し、CSS/JS/外部アセットをインラインまたは enqueue。
- Shadow DOM 有効時はホスト要素にテンプレートを差し込み。
- `wpautop`/`shortcode_unautop` を外し、不要な `<p>` 挿入を防止。
- 単一ページ公開を無効化した場合は noindex を付与し、検索/アーカイブから除外。単一ページはリダイレクトまたは 404（`kayzart_single_page_redirect` フィルタ）で制御。

REST API
--------
- `/kayzart/v1/save`: HTML/CSS/JS の保存、Tailwind コンパイル。
- `/kayzart/v1/compile-tailwind`: プレビュー用コンパイル。
- `/kayzart/v1/setup`: セットアップモード決定。
- `/kayzart/v1/import`: JSON インポート。
- `/kayzart/v1/settings`: 各種設定の更新。

保存データ (post_meta)
----------------------
- `_kayzart_css`, `_kayzart_js`
- `_kayzart_tailwind`, `_kayzart_tailwind_locked`, `_kayzart_generated_css`
- `_kayzart_template_mode`, `_kayzart_shadow_dom`, `_kayzart_shortcode_enabled`, `_kayzart_single_page_enabled`
- `_kayzart_external_scripts`, `_kayzart_external_styles`
- `_kayzart_live_highlight`, `_kayzart_setup_required`

管理設定
--------
- `KayzArt > Settings` で投稿スラッグ (`kayzart_post_slug`) を変更可能。
- 同画面で既定テンプレートモード (`kayzart_default_template_mode`: Standalone / Theme) を設定可能。
- 同画面でアンインストール時のデータ削除を設定（`kayzart_delete_on_uninstall`）。

postMessage プロトコル
----------------------
- 親 -> iframe: `KAYZART_INIT`, `KAYZART_RENDER`, `KAYZART_SET_CSS`, `KAYZART_RUN_JS`, `KAYZART_DISABLE_JS`,
  `KAYZART_EXTERNAL_SCRIPTS`, `KAYZART_EXTERNAL_STYLES`, `KAYZART_SET_HIGHLIGHT`, `KAYZART_SET_ELEMENTS_TAB_OPEN`
- iframe -> 親: `KAYZART_READY`, `KAYZART_RENDERED`, `KAYZART_SELECT`, `KAYZART_OPEN_ELEMENTS_TAB`, `KAYZART_MISSING_MARKERS`

拡張タブ API (Pro/Addon 向け)
-----------------------------
- エディタ画面専用アセット注入フック: `kayzart_editor_enqueue_assets`
  - 引数 `$context`: `post_id`, `hook_suffix`, `admin_script_handle`, `admin_style_handle`

- 管理画面 JS から設定パネルタブを追加する API
  - `window.KAYZART_EXTENSION_API.registerSettingsTab(tab)`
  - `tab` の型:
    - `id: string`（`settings` / `elements` は予約済みで使用不可）
    - `label: string`
    - `order?: number`（小さいほど左に表示、既定値 100）
    - `mount(container): void | cleanupFn`
  - 戻り値: `unregister()`（登録タブを解除）

- 読み込み順不同対応
  - KayzArt 本体より先に読み込まれる場合は `window.KAYZART_SETTINGS_TAB_QUEUE` に積む。
  - 本体初期化時にキューを自動取り込みしてタブ登録する。

権限/セキュリティ
-----------------
- KayzArt 投稿かつ `edit_post` を満たす場合のみ編集可能。
- JS/外部スクリプト/外部スタイル/Shadow DOM/ショートコード/単一ページ公開の更新は `unfiltered_html` が必要。
- プレビューは nonce 付き token と `event.origin` を検証。




