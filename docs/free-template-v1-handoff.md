# 無料テンプレート第1弾 引き継ぎメモ

## 2026-10-28再開: 残作業を完了

下記中断節の「再開後に残る作業」1〜5を実施した。詳細は[実装・検証記録](free-template-v1-validation.md)冒頭。

- 自己レビューで`motion.js`のreveal遅延中のちらつきを修正（`fill: 'backwards'`）。修正後のWP 7.1.3 E2E 3件合格。
- 配布ZIPを再生成・検査し、独立サイトのPlugin Check 2.1.0で指摘なし。現在ルートのZIPは新作入りの検証用ビルド（公開版ではない）。
- 日本語管理画面のカードをPC／スマホで目視確認。管理者localeは元の未設定へ戻した。
- 検証用のHTTPサーバーと専用MariaDBは停止済み。
- 機能変更は未コミット・未push。commit／PR／リリース／SVNはユーザーの指示待ち。

## 中断時点: Business Motion実装（2026-10-28・ユーザーの停止指示）

**ユーザーが「途中だけど一旦ストップして」と指示したため、ここで作業を止めた。再開指示まで実装・検証を続けない。** 最新仕様はマスタープラン冒頭のBusiness Motion節。以下の過去の「英語版あり」「JSなし」「外部通信なし」は現在の新作には適用しない。

### 現在の状態

- ブランチは`feat/bundled-free-template-v1`。既存の未コミット変更を一時退避してmainをマージし、戻した。readmeのUnreleasedと3.4.4履歴を両方残して競合を解消。マージコミットは`0d97122`。機能変更は未コミット・未push。既存の未コミット変更も保持している。プラグイン版は取り込んだmainと同じ3.4.4で、新規リリースの版上げではない。
- 新パッケージ`assets/templates/free/business-motion-ja/`を作成。企業の業務支援を作例に、Noto Sans JP 400/500/700/900、白・墨色・オレンジ・淡い青のヒーローと本文を制作。HTML/CSSの円・矢印・3工程パネル、中央配置済みのグレーPNG1枚を使用。サムネイルは実フォント・実画面の1200×900からWebP quality85へ出力、45,454 bytes。
- 外部ライブラリなしの`motion.js`を作成。Moduleの既存mount/onCleanupを利用し、`data-kz-motion`・`data-kz-effect`・時間／遅延／距離の属性で制御。属性変更・追加削除・reduced-motion・タッチ・非表示タブに対応。アニメーションの状態はHTMLへ書き戻さない。
- カタログは新作日本語1点のみ。旧日本語・英語はソースに残すが一覧とZIPから除外。schema1は既知の旧パッケージの読込用として維持し、新規作成は現行カタログだけを許可。
- schema2の同梱JS・実行モード・フォント宣言を読み込み、JS/headもDBと初期リビジョンへ保存。作成には既存のページ／画像権限に加えてunfiltered_htmlが必要。詳細iframeはallow-scriptsのみのsandbox＋nonce/Blob対応CSPで、既存ローカルruntimeを再利用。閉じるとsrcdocを外す。
- カードに使用フォント・外部通信のタイミング・Googleプライバシーリンクを記載。ユーザー決定により**チェックボックス・確認ダイアログ・全体設定は追加しない**。一覧ではGoogleへ接続せず、プレビュー以後の表示で接続する。readmeのExternal servicesも更新。告知方式のorg審査適合を確約していない。
- AIはJS／jsModeを引き続き変更不可。HTML属性による動きの編集をプロンプトへ記載。現在のheadに存在する既知のGoogle Fontsだけを利用可能フォントへ追加する。外部リンク追加を許可するような出力ポリシーの緩和は行っていない。
- i18n:build実行済み。新しい文言8点の日本語訳を追加し、POT／PO、無視対象のMO／JSON／distを更新。制作メモとマスタープランは新仕様を先頭に追記済み。

### ここまでの検証

- PHP全体・WP7.1.3/PHP8.2: **709件・3242 assertions合格**（phpunit-final.log）。新しい複製・履歴復元・AI属性変更／JS書換拒否・フォント認識を含む。
- WP5.9/PHP7.4のテンプレート最終: **29件・149 assertions、1件skip**（メタ履歴が未対応のため）。別途フォントを含む39件・179 assertions、1件skipも合格。
- JS全体: **44ファイル・375件合格**。TypeScript、変更PHP6ファイルのPHPCS/PHP7.4互換性も合格。
- WP7.1.3実管理画面E2E最終: **3件合格・51.3秒**（e2e-verified.log）。編集・保存・公開、公開時JS実行、HTML属性による動き変更、reduced-motion、DOM追加削除、JS/head保持、CSSモード往復を確認。
- WP5.9/PHP7.4実管理画面E2E: **3件合格・52.8秒**（e2e-5.9.log）。mainの互換修正取り込み後、従来のfatalなしで管理画面を開き、新テンプレートの編集・公開まで確認できた。
- 実回線のGoogle Fonts: 詳細sandboxでNoto Sans JPの読込を確認。一覧ではフォント接続なし、プレビュー後はfonts.googleapis.comとfonts.gstatic.comへ接続。JS無効／reduced-motion／フォント遮断／タッチでも本文・CTAが読め、390pxで横はみ出しなし。
- 320/390/768/1024/1440pxの静止画確認済み。最初の静止画の後にオレンジ背景上の文字を墨色へ変更し、小画面の工程説明を読みやすくした。実回線のdetail-desktop.pngなどはその変更後。
- 初期E2Eでheadの`&#038;`と`&amp;`の正規化差を発見し、生成headを揃えて修正。追加したDOM削除テストはAPIの意図的なiframe再読込と衝突したため、同じ文書内のDOM変更を試す形に修正し合格。未回収の一時ページはなく、両検証サイトとも元のSample Page／Privacy Policyだけ。

### 再開後に残る作業

1. 最終差分の自己レビュー。とくにschema2と退役パッケージ、JS/head権限、フォント認識・外部通信の説明を確認する。
2. **新しい配布ZIPはまだ生成していない。Plugin Checkも新作では未実行。** 現在ルートのZIPは旧版として扱い、配布・インストールへ使わない。`npm run plugin-zip`で生成し、同梱モジュール・manifest・PNG・thumbnail・翻訳・互換ASの存在と、旧テンプレート・開発ファイル・秘密情報の不在を検査する。
3. そのZIPを独立したPlugin Checkサイトへ導入して検査する。**composerがdev依存を一時削除するため、plugin-zipとPHPUnit／ブラウザ検証を並行しない。** Plugin Checkの合格はorgの告知方式の承認を意味しない。
4. 日本語の管理画面でカードの新しい訳文を目視する。今あるcatalog-desktop.png等は英語の管理者UIで、i18n:build前の撮影。日本語にする場合は使い捨てサイトの管理者localeを一時変更し、検証後に元に戻す（現在localeメタなし）。
5. 実装・検証記録の冒頭へ上の結果と最終ZIP／Plugin Check結果をまとめる。現在その文書の先頭は前のプレースホルダー調整の記録であり、**この中断節が最新結果**。readme・制作メモ・マスタープラン冒頭は新作の内容へ更新済み。
6. UI・公開ページの最終確認後に結果を報告。機能のcommit／push／PR／リリース／SVNはユーザーの指示範囲を確認して扱い、勝手に公開しない。

### 検証環境・ログ

ログと画像: `C:/Users/Inagaki/AppData/Local/Temp/kayzart-business-motion-20261028/`。静止画static-*.pngの一部は初期版。最終サムネイル元はthumbnail.png、実フォントのsandboxはdetail-desktop.png・detail-mobile-mode.png、JS無効などはno-js-390.png等。live-preview.logに実通信の確認結果がある。

環境は従来の`kayzart-template-v1-20261026`、専用MariaDB3318、WP7.1.3のsite:8093とWP5.9のcore-5.9:8094を使用。認証はそのenv.jsonから必要な値だけプロセスへ読み、内容を表示しない。**停止時に今回のHTTPサーバー2本と専用MariaDBを正常終了する。** 元のlocalhost/XAMPPやユーザーの実ページには停止操作をしない。

## 2026-10-28追補: 言語数の増加に備えてセレクトへ変更

最新の指示で、一覧の日本語／Englishボタンを「テンプレートの言語」ラベル付きのネイティブselectへ変更した。カード内には選択欄を置かない。選択肢は`Template_Library::available_locales()`でカタログの言語宣言から取得し、初期値はWordPressのサイト言語に完全一致→基底言語一致→English→最初の対応言語の順で選ぶ。管理者個人の表示言語は使わない。再試行時の言語・タイトル保持と、プレビュー／作成の同期は維持する。

ブラウザが戻る操作などでselectを復元した場合はpageshowでも同期し、見えている言語と送信する言語のずれを防ぐ。現在提供している本文は日本語・英語のままで、新しい言語の翻訳は今回追加していない。以下の一覧ボタンの記述は変更前の履歴として扱う。

## 2026-10-28追補: 一覧を日本語／Englishで切り替え

ユーザーの追加指示に従い、カード内の言語セレクトを削除し、一覧上部へ日本語／Englishの切り替えを移した。選択言語に対応するカードだけを表示し、サムネイル・詳細プレビュー・作成フォームの言語を同期する。入力済みのページタイトルとAI指示は保持する。初期値は引き続きサイトのWPLANG、作成エラーの再試行では直前の言語を優先する。該当テンプレートがない場合の案内も用意した。

現在の同梱テンプレートは引き続き1デザイン・日本語／英語。以下のカード内選択や言語フィルター未実装という記述は、各作業時点の記録。現在の仕様はマスタープラン、確認結果は検証記録の最新追補を参照する。

## 2026-10-28: 無料サービス紹介の磨き直し

汎用のページ構成に、架空の小規模Web／デザインスタジオという具体的な作例を載せた。本文・料金例・提供範囲・依頼フローを整理し、生成り／チャコール／青のデザインへ変更。画像は6:5の長方形とし、アーチと重なりバッジを廃止した。日本語・英語・両サムネイルを更新し、グレーのみ・文字なしのPNGはそのまま共用する。

変更は新しく選ぶテンプレートへ適用する。既に作ったページのHTML／CSSはDBの独立コピーのため自動変更しない。言語フィルターや有料テンプレートの実装は今回の範囲に含めていない。検証結果は[実装・検証記録](free-template-v1-validation.md)冒頭を参照する。

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
