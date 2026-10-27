# 無料テンプレート第1弾 引き継ぎメモ

## 2026-10-27の追加指示

ユーザーの実機確認を受け、プレビュー再表示時のDesktop／Mobile不整合を修正し、開始方法・プレビュー操作・カードのボタンを調整した。さらに同じLPの英語本文と英語サムネイルを同梱し、カード内の「ページの言語」で日本語／Englishを選べるようにした。現在の仕様はマスタープラン、追加分の検証は検証記録を参照する。

言語の初期値はサイトのWPLANGが日本語ならja、それ以外はen。管理者の表示言語とは分ける。プレビュー・サムネイル・作成本文を同じ選択に揃え、ページに由来言語を保存する。英語版は静的な同梱データで、外部翻訳サービスは使わない。

Action Schedulerの別修正はmainへ取り込み、3.4.4として公開済み。ただし、この機能ブランチにはまだmainをマージしていない。取り込み後の最低環境E2E、PRレビュー、リリース番号の決定は残る。今回のUI・言語・グレーのプレースホルダー追加は、以下のc77b2baまでの記録と区別する。配布ZIPは検証用で公開しない。

以下はc77b2baまでの記録。**現在の結果は[実装・検証記録](free-template-v1-validation.md)冒頭の追補が正本**。

更新: 2026-10-27（Asia/Tokyo）。ユーザーの再開指示を受け、残る実装・検証・配布物確認を実施した。P1〜P4の機能は実装済み。当時残したのは別修正のAction Scheduler対応、その後の最低環境E2E、PRレビュー・リリース手順。

2026-10-27には、PHP全687件・JS全368件、実際の要素編集とCSSモード往復／再読込のE2E2件、AI導線の回帰E2E7件、日本語PC／スマホ表示、生成ZIPのPlugin Checkを確認した。CSSモード切り替えによる元ソース消失と、Plugin Checkの入口警告も修正済み。ZIPは検証用で公開していない。

追加の環境: `core-7.1.3/`、`develop-7.1.3/wp-tests-config.php`、`prepend-7.1.3.php`、DB `tpl_713_unit`。ZIP確認専用の`package-site/`・DB `tpl_package_check`も作成済み。E2E用のAI fixtureは撤去し、テスト用の偽APIキーを削除、管理者ロケールを英語に戻した。

2026-10-27の確認後、今回起動したHTTPサーバーを停止し、検証用MariaDBを正常終了した。テストのページ・添付は回収済み。ローカル環境を使う場合は下記手順で再起動する。

**以下は2026-10-26の中断時点の記録。未完了表や「再開後の残作業」は当時のもので、現在の残作業としては扱わない。** 検証環境の場所・秘密情報を表示しない注意・別修正のユーザー判断は引き続き有効。

## 再開の入口

- リポジトリ: `C:/Users/Inagaki/Documents/develop/kayzart-workspace/kayzart-live-code-editor`
- ブランチ: `feat/bundled-free-template-v1`
- 計画: [マスタープラン](free-template-v1-master-plan.md)
- 実装はWIP。mainへのマージ・push・PR・タグ・SVN配布・バージョン変更は今回行っていない。
- 最初に`git status`とこのメモを確認する。既存変更を捨てない。サブエージェントの使用依頼はない。

## 実装した内容

- 同梱の日本語汎用サービスLP1点。Tailwind＋Standalone。写真入りWebPサムネイル、実ページ用PNGプレースホルダー、manifest・HTML・CSS・GPLライセンス。
- Add newにテンプレート／AI／白紙の入口。AI未設定時はテンプレートを初期表示。写真は見本である旨と本文言語を表示する。
- 詳細プレビューをクリック時に同一サイトから遅延取得。sandbox＋CSP、PC／390px切り替え、transientキャッシュ。閲覧でページ・添付を作らない。
- テンプレート専用フォームから固定ページの下書きを作成。AI指示は送らず、AIフォームの入力は保持する。
- ページごとにローカルPNGをメディアへコピーし、本文のURLを置換する。元ファイルを移動・上書きしない。HTML／CSS／生成CSSをDB保存。
- Normal CSSバッファを未作成（クライアントでnull）に保ち、Tailwindからの初回切り替えに備える。完全な初期リビジョンを作成する。
- 権限・nonce・ID・manifest・ファイル境界・画像を検証。要求UUIDとadd_option予約で二重作成を防止。失敗時は今回作成した下書き・添付だけ回収する。
- 複製は由来ID／版だけ保持し、要求UUID／完了マーカーを引き継がない。
- 作成直後はコードを畳み、要素パネルと一度だけの案内を表示する。
- 日本語40文言、POT／PO更新、readmeの使い方・FAQ・Unreleased欄、CIのWP 6.3／6.4追加。

主な新規コードは`includes/class-kayzart-template-{library,page-creator,admin}.php`と`assets/admin/template-library.js`。素材は`assets/templates/free/general-service-lp/`。画像生成のプロンプト・権利・サムネイル撮影手順は[scripts/templates/README.md](../scripts/templates/README.md)に保存した。元写真は開発用で配布ZIPから除外する。

## 検証結果と限界

| 検証 | 結果／注意点 |
|---|---|
| JavaScript全体 | 44ファイル／368テスト合格。後述の最後のUI文言調整後は未再実行 |
| TypeScript | `npx tsc --noEmit`合格。最後のE2E編集後は未再実行 |
| PHP全体（WP 7.0／PHP 8.2） | 682テスト／3,045 assertions合格。後から追加した5テストはこの全体実行に含まない |
| テンプレート単独（WP 5.9／PHP 7.4） | 最終19テスト／72 assertions合格。PHPUnitでは管理画面依存の停止を検出できなかった点に注意 |
| テンプレート＋Snapshot（WP 6.3／PHP 7.4） | 30テスト／90 assertions、8 skip。skipは6.4以上専用の履歴テスト |
| テンプレート＋Snapshot（WP 6.4／PHP 7.4） | 30テスト／154 assertions合格 |
| 関連PHP（WP 6.4／PHP 7.4） | 追加5テスト前の102テスト／404 assertionsも合格 |
| E2E（WP 7.0／PHP 8.2） | プレビュー→作成→画像／文章変更→保存→公開→後片付けが合格。最終実行22.3秒。ただしこの時点の変更操作は拡張APIを使用 |
| E2E最新版のspec | **未実行**。最後に実際のプレビュークリックと要素パネルで画像URL／文章を編集する手順へ強化した。次回最優先で実行する |
| 最低環境のE2E | **失敗・未達成**。既存Action Schedulerのfatalで管理画面が開かない。下記参照 |
| ビルド | productionビルド合格。既存の500kB超チャンク警告あり |
| PHPCS／PHP互換性 | 変更したPHP本体5ファイルで合格。最後の例外回収4行の追加後は未再実行 |
| Plugin Check | disposableサイトに導入・有効化まで。**検査は未実行** |
| 配布ZIP | **未作成・未検証** |

最後の変更は、白紙選択時の見出し更新、AI権限なしのAI入口非表示、サムネイル全体を見せる2列配置、作成フック例外後の回収、PHPの追加5テスト、実操作版E2E。これらを理由に最終検証が残っている。すべての受入条件を合格にしない。

## 別修正に分けるリリース阻害要因（ユーザー決定済み）

**今回のブランチで依存ライブラリの互換性修正を広げない。別修正として切り分け、今回のリリース前の必須対応として記録する**、というユーザー回答がある。再確認は不要。

同梱Action Scheduler 4.0.0の`classes/ActionScheduler_RecurringActionScheduler.php:29`が、WP 5.9管理画面のinitで未定義の`wp_is_serving_rest_request()`を呼びfatalになる。依存側の公称最低要件もWP 6.8。これはテンプレート処理より前に起きる既存の不整合で、vendorの直接編集・依存のダウングレード・グローバル関数のpolyfill・最低WP要件の変更は行っていない。

根拠: [WordPressの当該関数（6.5導入）](https://developer.wordpress.org/reference/functions/wp_is_serving_rest_request/)、[Action Scheduler 4.0.0の公式案内](https://wordpress.org/support/topic/action-scheduler-4-0-0-is-here-please-read-before-posting/)。PHPUnitではこの管理画面固有の経路が通らないため、19テスト合格だけで5.9実機合格とは扱わない。

## 再開後の残作業（推奨順）

1. 最新版E2E specをWP 7.1.3で実行し、実際の要素パネル操作を確認する。全体90秒のtimeoutを設定済み（従来30秒は全体フローに余裕がなかった）。必要なら保存・再編集、Tailwind→Normal→Tailwindの実操作も確認する。
2. 日本語管理画面、PC／スマホ、初期レイアウトと案内を目視。最後のカード2列変更後のスクリーンショットは未取得。
3. AIオンボーディング回帰E2E。既存specにAI入口クリックを追加済み。fixtureはまだ導入していない。`tests/e2e/fixtures/ai-onboarding.php`と`KAYZART_ONBOARDING_E2E=1`の条件を読む。実APIへ送信しない。
4. JS全体・TypeScript・PHP全体・変更PHPのPHPCSを最終版で確認。WP 7.1.3の対応テストも実行する。過剰に同じテストを繰り返さない。
5. Plugin Checkと配布ZIP確認。ビルド前に`assets/dist/`の実在ファイルと絶対パスを確認してクリーンする。dist／vendorを手編集・コミットしない。既存`npm run plugin-zip`を利用する。
6. ZIPにmanifest・HTML・CSS・サムネイル・PNG・LICENSE・翻訳生成物が含まれ、`scripts/templates/demo-workspace.png`／docs／テスト／秘密情報が入らないか検査する。
7. 最終diffを自己レビューし、計画の進捗・受入条件を実測に合わせ更新する。リリース番号、mainへの反映、公開はまだ決めていない。最低環境の受入は別修正後に行う。

## ローカル検証環境

環境本体はPlaywright出力先の**外**にある:

`C:/Users/Inagaki/AppData/Local/Temp/kayzart-template-v1-20261026`

このフォルダは削除していないが、Temp配下なので消えている場合は再構築する。**検証サイトやDBを`test-results/`へ作らない**。Playwrightが開始時に消去する。

- `mysql/`: 今回だけのMariaDBデータ。127.0.0.1:3318。ローカル検証専用root／パスワードなし。既存のXAMPPデータと分離。
- `site/`: 127.0.0.1:8093用。PHP 8.2。停止直前に公式7.1.3を展開しDB更新コマンドは正常終了。**7.1.3のブラウザ動作は未確認**。プラグインのjunctionはこの作業ツリー。Plugin Check有効。
- `core-5.9/`: 127.0.0.1:8094用。PHP 7.4。独立DB、同じプラグインへのjunction。前述のAS fatalあり。
- `core-6.3/`、`core-6.4/`: 公式配布物のPHPUnit用コア。
- `develop-5.9/`、`develop-6.3/`、`develop-6.4/`: 対応版の公式テスト、`wp-tests-config.php`。
- `develop-7.1.3/`: 公式テストを取得・展開済み。**専用unit設定はまだ作っていない**。
- DB: `tpl_unit`（WP7.0全体）、`tpl_59_unit`、`tpl_63_unit`、`tpl_64_unit`、`tpl_browser`、`tpl_browser_min`。`tpl_min_unit`は初回準備時の空DB。
- `unit-prepend.php`: WP7.0全体用の独立DB設定。`prepend-5.9.php`／`prepend-6.3.php`／`prepend-6.4.php`は版別設定。
- `env.json`: E2Eアカウントのランダムパスワード等。**中身を表示・Gitへ保存しない**。必要な値だけプロセス環境へ読み込む。
- `site/wp-content/mu-plugins/template-fixture.php`等でメールとWordPress HTTPを遮断。ブラウザ側もE2Eで外部通信を遮断。

退勤時に、今回のPHPサーバー2本と独立MariaDBを停止した。MariaDBは`mysqladmin shutdown`で正常終了させた。再開時は起動が必要。

```powershell
$templateRoot = 'C:/Users/Inagaki/AppData/Local/Temp/kayzart-template-v1-20261026'
Start-Process 'C:/xampp8.2/mysql/bin/mysqld.exe' -ArgumentList '--no-defaults',"--datadir=$templateRoot/mysql",'--port=3318','--bind-address=127.0.0.1','--standalone','--console' -WorkingDirectory "$templateRoot/mysql" -WindowStyle Hidden
Start-Process 'C:/xampp8.2/php/php.exe' -ArgumentList '-S','127.0.0.1:8093','-t',"$templateRoot/site" -WindowStyle Hidden

$templateTestEnv = Get-Content -LiteralPath "$templateRoot/env.json" -Raw | ConvertFrom-Json
$env:WP_BASE_URL = 'http://127.0.0.1:8093'
$env:WP_ADMIN_USER = $templateTestEnv.admin_user
$env:WP_ADMIN_PASS = $templateTestEnv.admin_password
$env:KAYZART_TEMPLATE_E2E = '1'
node node_modules/@playwright/test/cli.js test tests/e2e/template-library.spec.ts --reporter=line
```

PHPUnitは既定のユーザーDBを使わず、必ず独立設定を指定する:

```powershell
php -d auto_prepend_file=C:/Users/Inagaki/AppData/Local/Temp/kayzart-template-v1-20261026/unit-prepend.php vendor/bin/phpunit

# WP6.4／PHP7.4の関連テスト例。PATHはテストが起動する子PHPにも適用する。
$env:PATH = 'C:/xampp7.4/php;' + $env:PATH
$env:WP_TESTS_DIR = "$templateRoot/develop-6.4/tests/phpunit"
& C:/xampp7.4/php/php.exe -d auto_prepend_file=C:/Users/Inagaki/AppData/Local/Temp/kayzart-template-v1-20261026/prepend-6.4.php vendor/bin/phpunit --filter 'Test_Template_Library|Test_Kayzart_Snapshot_Revisions'
```

注意: 元のXAMPP MySQLデータには開始時点から起動時エラーがあり、修復は行っていない。そのデータをテストのために削除・修復・上書きしない。検証中のPowerShell標準Expand-Archive／Invoke-WebRequestが遅かったため、公式ZIPの取得・展開にはcurl.exe／tar.exeを利用した。版別コアはdevelopの`6.4-src`等ではなく公式配布物を使う（srcの版文字列は履歴対応判定で正式6.4より前と判定される）。

## 明日の依頼例

> `docs/free-template-v1-handoff.md`を読んで、無料テンプレート第1弾の実装を続けてください。Action Schedulerの互換性は別修正にする方針を維持し、残る検証と仕上げを進めてください。
