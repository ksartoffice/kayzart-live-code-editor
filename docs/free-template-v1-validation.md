# 無料テンプレート第1弾 実装・検証記録

更新: 2026-10-27（Asia/Tokyo）。対象ブランチ: `feat/bundled-free-template-v1`。

**機能実装とWP 7.1.3での確認は完了。リリースは未実施。** ユーザー決定により既存Action Schedulerの互換性修正を別件に分けているため、最低環境の受入と公開前レビューを残す。

## 実装結果

- Add newに「テンプレートから／AIで作成／白紙から」を追加。AI未設定ではテンプレートを初期表示する。AI権限を持たない利用者にはAI入口を出さない。
- 日本語の汎用サービスLP1点を同梱。名前・説明は翻訳可能で、日本語本文であることを表示する。
- サムネイルは写真入り。写真は使用イメージであり、作成されるページにはプレースホルダー画像が入ると、選択前に説明する。
- 詳細は実際のプレースホルダー版を表示。ユーザーが開いたときに同一サイトのGETで取得し、sandbox＋CSPで隔離する。PC／390px幅を切り替えられる。
- 専用フォームで固定ページの下書きを作成する。AI指示を送らず、元フォームの指示は保持する。
- PNGをページごとにWordPressメディアへコピーしてURLを置換し、HTML・CSS・生成CSSを保存。Tailwind＋Standalone、Normalバッファ未作成、対応環境では完全な初期リビジョンを作る。
- 権限・nonce・テンプレートID・パッケージ構造・許可パスを検証。二重要求を抑止し、捕捉できる失敗では今回のページ・添付だけ回収する。複製へ要求固有メタを引き継がない。
- 初回はコードを畳み、要素パネルと差し替え案内を表示する。

主なコードは`includes/class-kayzart-template-{library,page-creator,admin}.php`、`assets/admin/template-library.js`、既存Admin／エディタへの接続部分。素材・プロンプト・権利の記録は[scripts/templates/README.md](../scripts/templates/README.md)。

## 2026-10-27に修正したこと

1. Plugin Checkが指摘した`REQUEST_METHOD`のunslash／sanitizeを、作成・プレビューの両入口へ追加した。
2. 実操作E2Eで、Tailwind→Normal→Tailwindの往復により元ソースが生成CSSに置き換わることを発見。CSSモデルの変更イベントが同期的にモード別バッファを更新するため、モデルを差し替える前に現在のモードを更新するよう修正した。往復、保存、再読込のE2Eで回帰を防ぐ。

## 自動検証

| 対象 | 結果 |
|---|---|
| JavaScript全体 | 最終変更後に44ファイル・368テスト合格 |
| TypeScript | 最終変更後に`npx tsc --noEmit`合格 |
| PHP全体・WP 7.1.3／PHP 8.2 | 687テスト・3,057 assertions合格 |
| PHP入口修正後のテンプレート単独・WP 7.1.3 | 19テスト・74 assertions合格 |
| PHP規約・PHP互換性 | 変更PHP本体5ファイルのPHPCS合格。PHP 7.4以上の設定 |
| テンプレートE2E・WP 7.1.3／PHP 8.2 | 最終2件合格（33.2秒） |
| AIオンボーディング回帰E2E | direct／connectors／fallbackの7件合格（1.6分）。接続状態はfixture、実AI通信なし |
| productionビルド・ZIP生成 | `npm run plugin-zip`合格。本番依存でZIPを作成し、開発依存も復元済み |
| Plugin Check 2.1.0 | **生成ZIPを展開した別のWordPressへ導入して実行**。`Checks complete. No errors found.`、指摘なし |

PHP全体実行の後に行ったPHP本体変更は入口のリクエスト値処理のみで、当該変更後に関連19件とPHPCSを確認した。WordPress管理画面の検証は、既存データと分離した使い捨て環境で実施した。

### E2Eで確認した範囲

- AI未設定の一覧、写真説明、プレースホルダーの詳細、スマホ幅切り替え。
- 下書きの初期レイアウト、uploads画像、Normalバッファのnull。
- 実際にプレビューの画像と見出しをクリックし、要素パネルから画像URL・文章を変更。保存・公開表示でも反映されること。
- 公開ページの背景色、画像の読込、390pxで横はみ出しがないこと、ページ内アンカー先が存在すること。
- Tailwind→Normalで生成CSSを受け継ぎ、Tailwindへ戻るとソースが復元すること。保存・再読込後に両方のバッファと見た目が残ること。
- テンプレート経路でAIジョブPOSTがないこと。ブラウザの外部通信は遮断し、PHP側も既存mu-pluginで外部HTTP・メールを遮断した。PHPUnitではテンプレートのプレビュー／作成時のHTTP呼び出しがゼロであることを別途確認する。
- 一時ページ・添付は共通ヘルパーのfinallyで削除し、削除成功を検証する。

PHPの19件は、許可ID、プレビュー隔離、保存内容・初期履歴、再送、ページごとの画像、権限、固定ページ無効、アップロード／保存／メタデータ処理／投稿フックの失敗回収、unfiltered_htmlなし、HTTPなし、予約競合、複製、画像エディタなし、履歴無効、不正nonceを対象とする。実際の同時プロセスへの負荷試験や全障害の強制終了試験を実施したとは扱わない。

### 対応バージョン境界（2026-10-26の記録）

| 環境 | 結果 |
|---|---|
| WP 5.9／PHP 7.4、テンプレートPHPUnit | 19テスト・72 assertions合格。管理画面の受入を意味しない |
| WP 6.3／PHP 7.4、テンプレート＋Snapshot | 30テスト・90 assertions。6.4以上専用の履歴テスト8件は仕様に従いskip |
| WP 6.4／PHP 7.4、テンプレート＋Snapshot | 30テスト・154 assertions合格 |

CIへWP 6.3／6.4の組を追加済み。リモートCIはこの作業では実行していない。

## 目視確認

英語・日本語の一覧を確認。日本語の名前・説明・言語バッジ・写真説明、PCのカード、390pxの一覧、詳細プレビューのPC／スマホ幅が成立し、ブラウザのJavaScriptエラーはゼロだった。検証用サイトの管理者ロケールは英語へ戻した。

撮影物はローカル検証環境の`qa-ja-desktop.png`、`qa-ja-mobile.png`、`qa-ja-preview-desktop.png`、`qa-ja-preview-mobile.png`。E2Eの`test-results/template-editor.png`と`template-published.png`は次回実行で消える一時成果物。

## 配布物

- ファイル: リポジトリ直下の`kayzart-live-code-editor.zip`。Git対象外。**検証用ビルドであり、公開済み版ではない**。版は3.4.3のまま。
- 容量: **1,501,199 bytes**、365ファイル。
- `manifest.json`・`index.html`・`style.css`・`thumbnail.webp`・`images/landscape.png`・`LICENSE.txt`を確認。
- 新しいPHP／JS、productionバンドル、日本語MOとスクリプト翻訳JSON3ファイルを確認。
- 開発用元写真、ルートのscripts／docs／tests、node_modules、環境設定、ソースマップ、開発用PHPUnit／vendor/binが含まれないことを検査した。ライブラリ自身の文書は既存の本番依存の一部として残る。
- テンプレート資産の合計は94,425 bytes（HTML 10,894、CSS 454、manifest 439、サムネイル72,998、PNG 9,117、LICENSE 523）。

Plugin Checkはソース作業ツリーでは開発用ファイルも検出するため、最終判定は本番依存で生成したZIPの展開物で行った。

## 公開前に残すこと

1. **別修正のAction Scheduler互換性対応。** 既存4.0.0がWP 5.9管理画面で未定義関数を呼んでfatalになる。ユーザーは今回の機能ブランチから切り分ける方針を選択済み。vendorの直接編集・依存のダウングレード・最低WP要件変更はしていない。
2. その修正後、WP 5.9／PHP 7.4でテンプレートの主要E2Eを実行する。現在は**最低環境の受入未達成**。
3. PRレビュー、公開版番号・公開日の決定、最終配布物の生成、通常のリリース手順。

mainへのマージ、push、PR作成、タグ、SVN更新、本番配布はこの検証作業では行っていない。
