# 無料テンプレート第1弾 実装・検証記録

## 2026-10-28追補: テンプレートのレビュー指摘を修正

PR #146のレビュー中に、テンプレート自体を運用の観点で再点検した。読み込み直後のヒーロー見出しのちらつき（約160msで表示→約385msで消えて再フェードイン）を計測で確認し、以下を日英両方で修正した。

1. ちらつき: `motion.js`は、マウント時点で画面内にあるrevealを演出しない（表示済みの内容を隠さない）。スクロールで入る要素・後から追加／変更された要素は従来どおり演出する。修正後はプレビュー文書で、初回描画後の見出しの不透明度が常に1、スクロール先の工程はreveal演出が動くことを確認。
2. 文字サイズ: 11px未満の表示文字を0件にした（スマホの工程ラベルは番号のみ、英字ラベルはsm以上）。
3. コントラスト: `subtle`を#646b61（サービス背景で4.85:1）、お問い合わせ欄の注記を墨色（4.59:1）に変更。残る指摘はロゴの装飾の「.」のみ。
4. 黒い相談ボタンにキーボードフォーカス時の墨色の枠を指定（白い枠が背景に溶けていた）。
5. 英語版の工程パネルの全角「＋」を半角に。
6. 工程パネルの`aria-label`付き要素に`role="group"`。
7. 英語版の料金を「From $180 / session」の形に。
8. 連絡先を`mailto:`リンクに。

サムネイルを撮り直し（日本語51,076 bytes、英語56,604 bytes）。320〜1440pxで横はみ出しなし。画像は`C:/Users/Inagaki/AppData/Local/Temp/kayzart-business-motion-20261028/review-after/`。

## 2026-10-28追補: Tailwind CSS v4 ユーティリティへの書き直し

AI編集のトークンを抑えるため、日本語版・英語版のスタイルを独自クラス（`.bm-*`、`style.css`約15KB）からHTML上のTailwind v4ユーティリティへ移した。`style.css`は`@theme`の色・フォントのトークン、`@utility wrap`、baseレイヤー（スムーズスクロール・フォーカス枠・スクロール余白、英語版の`<em>`セリフ体）だけの約1KB。HTMLは約13KB→約23KB、合計は約27KB→約25KB。独自の800／880／1100pxをTailwind標準のsm／md／lgへ置き換えた。

- 内蔵TailwindPHPで、任意値、`*:`・`before:`・`max-*`バリアント、`@utility`、`@variant`、`text-balance`、`motion-reduce`、`font-[family-name:…]`がコンパイルできることを事前に確認。
- 書き直し前後の全ページを同条件（実フォント・reduced-motion）で撮影して画素比較。日本語版・英語版とも1440／1200／390／320pxで差分0.00%（1200pxはサムネイル幅のため、サムネイルは撮り直し不要）。1024・900・768・600pxはブレークポイント変更に伴う想定どおりの変化で、崩れ・横はみ出しなし（ヒーローの写真は1024px以上で表示）。
- `.bm-*`クラスは廃止。動きは引き続き`data-kz-*`属性で制御し、E2Eのセレクターも属性ベースへ変更。E2Eに、エディタと公開ページで見出しの太さ・アクセント色・濃色セクション背景がユーティリティから適用されることの確認を追加。
- PHPUnit（テンプレート＋AIフォント）: WP 7.1.3で41件・219 assertions、WP 5.9／PHP 7.4で41件・212 assertions（2 skip）合格。管理画面E2E 3件合格（1.2分）。

比較画像は`C:/Users/Inagaki/AppData/Local/Temp/kayzart-business-motion-20261028/tw-before/`・`tw-after/`（`*-compare-*.png`）。配布ZIPとPlugin Checkは未再実行。

## 2026-10-28追補: 英語版 Business Motion EN

ユーザー指示で英語版を別ID`business-motion-en`の別デザインとして追加した。構成・動き・ヒーロー写真の方針は日本語版と同じで、本文は英語で書き下ろし（直訳ではない）。見出しは「Less busywork. / More momentum.」。書体はInstrument Sans（400/500/600/700）とInstrument Serif（イタリック、見出しのアクセント・工程番号・FAQのQ）。行間・字間・文字サイズを英文向けに調整し、見出しに`text-wrap: balance`。

- Google Fontsを`Template_Library::GOOGLE_FONTS`の許可リスト（ファミリー・軸・値・代替フォント）に一般化。マニフェストは最大2ファミリー、`wght`は`weights`、`ital`は`italic`で宣言し、それ以外のキー・値・ファミリーは不正パッケージ。AIのフォント認識も同じリストを使い、CSS2 URLの複数`family`を手動で解析する（`text=`付きは無視）。
- 一覧はカード2枚（日本語／English）。各カードのページタイトル欄のIDが重複していたため、IDをカードごとにし、nonceのIDを削除。言語を切り替えても入力済みのタイトルを保持するよう同期。詳細プレビューの説明文を「テンプレートのフォントを読み込む」汎用文へ変更。
- 新しい文言3点の日本語訳を追加し、i18n:build（fuzzy・廃止エントリなし）。readmeの機能一覧・変更履歴・External servicesを英語版フォントに更新。
- サムネイルは積み木写真・実フォント・reduced-motion・1200×900で撮影（55,736 bytes）。英語版の全ページを1440／1024／768／390／320pxで撮影し、横はみ出し・文字のはみ出しなし。ヒーローは1440〜320pxで文字と写真の重なりなし。
- PHPUnit（テンプレート＋AIフォント）: WP 7.1.3／PHP 8.2で41件・219 assertions、WP 5.9／PHP 7.4で41件・208 assertions（2 skip、版依存の既存スキップ）合格。英語パッケージの読込・プレビュー・作成、許可リスト外のフォント宣言の拒否、AIのフォント認識を追加。
- JS全44ファイル・376件、TypeScript、変更PHP3ファイルのPHPCS／PHP 7.4互換性、管理画面E2E 3件（1.2分）合格。E2Eに英語カードのフォント告知・作成言語・プレビュー内容と、言語切り替え時のタイトル保持を追加。英語の管理画面でカードを目視。

ログ・画像は`C:/Users/Inagaki/AppData/Local/Temp/kayzart-business-motion-20261028/hero-en/`、`en-ja-catalog-desktop.png`ほか。**配布ZIPとPlugin Checkは英語版追加後に再実行していない。**

## 2026-10-28追補: ヒーローの作り直しとサムネイル更新

ユーザーの確認を受けてヒーローを作り直した。見出しは「その非効率に、終止符を。」（字間-.02em、最大108px）。PCは左に補足・見出し・説明・CTA、右に角丸の写真の2カラム。左列はテキストの実寸で、写真は残り幅とテキストの高さに合わせるため、文字と写真の間は常にグリッドの隙間（36〜56px）だけになる。写真上の日本語タグ「小さな改善を、着実に積み上げる。」は写真内の左下に置く。880px以下は写真を出さない。「動き出す」パネルの装飾は右下から伸びる3本の棒に変更。矢印・円・英字タグは廃止。

- 写真は本文と同じ`main-visual`を参照。作成ページと詳細プレビューはグレーのプレースホルダー、一覧サムネイルだけ画像生成の積み木写真（`scripts/templates/business-motion-hero.png`、ZIP対象外）を使う。プロンプトは`scripts/templates/README.md`に記録。
- サムネイルは実フォント・reduced-motion・1200×900の実描画からWebP quality 85で再出力（49,434 bytes）。カード注記を「サムネイルの写真は見本です。作成されるページには、ご自身の画像に差し替えられるプレースホルダーが入ります。」へ変更し、i18n:buildでPOT／PO／MO／JSONを更新（fuzzyなし）。
- 1440／1280／1200／1101／1024／900／881／880／801／768／390／320pxで、文字と写真の重なり・タグのはみ出し・工程パネルとの接触・横はみ出しがないことを計測。
- `motion.js`のreveal遅延中のちらつきを`fill: 'backwards'`で修正済み（前節参照）。
- PHPUnitテンプレート29件・154 assertions（WP 7.1.3／PHP 8.2）、管理画面E2E 3件合格（53.8秒）。日本語管理画面の一覧を1440px／390pxで目視。管理者localeは元の未設定に戻した。

画像は`C:/Users/Inagaki/AppData/Local/Temp/kayzart-business-motion-20261028/hero-v5/`ほか。**この変更の後、配布ZIPとPlugin Checkは再実行していない。**前節のZIPは旧ヒーローの検証用で、配布前に再生成・再検査が必要。

## 2026-10-28: Business Motion（日本語）新作と配布物確認

マスタープラン冒頭のBusiness Motion節に従い、`business-motion-ja`（schema2・同梱Module JS・Noto Sans JP宣言）を追加し、旧日本語／英語テンプレートを一覧と配布ZIPから外した。実装中の検証は[引き継ぎメモ](free-template-v1-handoff.md)の中断節に記録済みで、要点は次のとおり。

- PHP全体（WP 7.1.3／PHP 8.2）709件・3,242 assertions合格。WP 5.9／PHP 7.4のテンプレート29件・149 assertions（1 skip：メタ履歴未対応版）。
- JS全44ファイル・375件、TypeScript、変更PHPのPHPCS／PHP 7.4互換性合格。
- 管理画面E2E：WP 7.1.3で3件（51.3秒）、WP 5.9／PHP 7.4で3件（52.8秒）合格。mainのAction Scheduler互換修正の取り込み後、最低環境でも管理画面が開き、作成・編集・公開まで通った。
- 実回線のGoogle Fonts：一覧ではGoogleへの接続なし。プレビュー後の接続先はfonts.googleapis.com／fonts.gstatic.comのみ。JS無効・reduced-motion・フォント遮断・タッチでも本文とCTAが読める。320／390／768／1024／1440pxで横はみ出しなし。

### 再開後の自己レビューと修正

- `motion.js`のrevealが`fill: 'none'`だったため、`data-kz-delay`付きの要素が遅延中に表示され、開始時に不透明度0へ戻ってからフェードインしていた（ヒーローで80〜300msの遅延を使用）。`fill: 'backwards'`へ変更。Chromiumで遅延300msの要素を毎フレーム計測し、修正前は遅延中の最大不透明度1→表示後に0へ落ち込み、修正後は遅延中0・落ち込みなし・最終1を確認した。終了後は元のスタイルへ戻るため、HTMLへ状態を書き戻さない方針は維持。
- 退役した`general-service-lp`は`Template_Library::preview()`から引き続き読める。作成は現行カタログに限定済みで、配布ZIPにはディレクトリが無いため不正パッケージとしてエラーになる。言語別プレビューキャッシュのテストが旧2言語パッケージに依存しているため変更していない。
- 他のschema2読込・JS/head権限（`unfiltered_html`）・フォント認識（宣言済みNoto Sans JPのみ、`text=`なし）・readmeのExternal servicesは計画どおり。

修正後、WP 7.1.3管理画面E2E 3件合格（49.0秒）。

### 配布ZIP

`npm run plugin-zip`で生成。リポジトリ直下の`kayzart-live-code-editor.zip`、**検証用であり公開版ではない**（版は取り込んだmainと同じ3.4.4）。

- 1,835,397 bytes、455ファイル。SHA-256 `e8b2cc23f68d1e9d9b6caf80d7146cddc17370670e547bdd652575714d3bd6e9`。
- `business-motion-ja/`の7点（manifest・index.html・style.css・motion.js・thumbnail.webp・images/landscape.png・LICENSE.txt、計264,045 bytes）、productionバンドル、日本語PO/MO・スクリプト翻訳JSON3点、互換用`kayzart/action-scheduler-legacy`と`woocommerce/action-scheduler`を確認。
- 旧`general-service-lp`、ソースマップ、ルートのdocs／tests／scripts、node_modules、PHPUnit等のdev依存、vendor/bin、環境設定が含まれないことを確認。`src/`はreadme記載のビルド元ソースとして意図的に同梱。tailwindphp自身のdocsは既存の本番依存の一部。
- 展開物への秘密情報パターン検査は、tailwindphpのCSSプロパティ名（`mask-...`）の誤検知のみ。

### Plugin Check

ZIP展開物を独立した`package-site`（WP 7.1.3、DB `tpl_package_check`）へ導入し、Plugin Check 2.1.0をWP-CLIで実行。`Success: Checks complete. No errors found.`、指摘なし。**Plugin Checkの合格はWordPress.orgの審査で告知のみの方式が承認されることを意味しない。**

### 日本語管理画面

使い捨てサイトの管理者localeを一時的に`ja`へ変更し、1440px／390pxで一覧を撮影・目視した（撮影後にlocaleメタを削除し、元の未設定へ戻した）。「テンプレートの言語」セレクト、カードの「使用フォント：Noto Sans JP — Google Fonts」と「プレビューと作成したページの表示時に、Googleのサーバーからフォントを読み込みます。」がプレビュー／作成ボタンより前に表示され、横はみ出しなし。画面上部のAction Scheduler期限切れ通知は、外部通信を遮断した検証サイトに溜まったジョブによるもので今回の変更とは無関係。

ログ・画像は`C:/Users/Inagaki/AppData/Local/Temp/kayzart-business-motion-20261028/`（`e2e-after-review.log`、`ja-catalog-desktop.png`、`ja-catalog-mobile.png`）。Plugin Checkの結果は`C:/Users/Inagaki/AppData/Local/Temp/kayzart-template-v1-20261026/plugin-check-package-20261028.*`。

### 残ること

commit／push／PR、リリース番号の決定、公開・SVN操作はユーザーの指示待ち。WordPress.org公開前にGoogle Fontsの告知方式をレビューで確認する。

## 2026-10-28追補: プレースホルダーの絵を中央へ移動

ユーザー指定の同梱`images/landscape.png`について、旧バッジ用に残していた下部余白を廃止し、絵を上下左右の中央へ移した。内蔵image_genで既存画像を編集し、768×768へリサンプル・グレースケール化してPNG保存。グレーのみ・文字なしの意匠と寸法は維持し、容量は182,484 bytesとなった。現行の編集プロンプトと旧制作履歴は`scripts/templates/placeholder-prompt.txt`に記録した。

- 暗い画素の境界（輝度160未満）はx=222〜545、y=261〜511。中心は(383.5, 386)で、画像中央から3px以内。
- 全画素のRGBチャンネル差は0。SHA-256は`7343c64c6a2c88f40d1bfb93370667c9027cb66de9901bda57eef194deb40d1d`。
- 指定されたlocalhostのプラグイン画像URLから取得したPNGと、同梱PNGのハッシュが一致。
- 直前に検証した実テンプレートの生成HTML／CSSへ、localhostから配信中の新画像を読み込み、日本語・英語の1200px／390px表示を撮影。6:5のobject-cover・中央配置で絵が欠けないことを目視確認。

元画像のバックアップ・画像検査・撮影は`C:/Users/Inagaki/AppData/Local/Temp/kayzart-placeholder-center-20261028/`。サムネイルは写真入りのため変更していない。ユーザーのuploads内にある既存ページ用の独立画像は今回変更していない。配布ZIPは再生成していない。

## 2026-10-28追補: 一覧サムネイルの収まりを調整

ユーザーの実機指摘に従い、カード全体に24px（600px以下は18px）の内側余白を設けた。サムネイルに独立した薄い全周枠・10pxの角丸・控えめな影を付け、カード端への密着と背景との曖昧な境界を解消。中央の縦罫線は廃止し、画像と説明は28pxの余白で分ける。小画面では上下に並べ、画像も左右の余白を保つ。

日本語・英語それぞれ1440／1024／768／390／320px幅で、実管理画面のカードを撮影。横はみ出しがないことと、画像の左右に18px以上の余白があることを確認し、PC・スマホの画像を目視した。CSSのみの表示調整で、作成・言語切り替えの処理は変更していない。ログ・画像は`C:/Users/Inagaki/AppData/Local/Temp/kayzart-template-card-frame-20261028/`。

## 2026-10-28追補: 言語切り替えを一覧セレクトへ変更

言語数の増加を見据え、一覧上部の切り替えボタンを「テンプレートの言語」ラベル付きのネイティブselectへ変更した。カード内には選択欄を置かない。選択肢はカタログの対応言語・自言語名から作り、初期値はWordPressのWPLANGに完全一致→基底言語一致→English→最初の対応言語の順で選ぶ。再試行時は直前の言語を優先する。管理者個人の表示言語は使わない。

言語変更時の一覧・画像・プレビュー・作成本文の同期、タイトルとAI指示の保持は維持した。pageshowでも同期し、ブラウザがselectの値を復元した場合のずれを防ぐ。今回追加したのはUIと対応言語の列挙であり、同梱する本文は引き続き日本語・英語の2種類。

- JS全44ファイル・375件合格。単独10件で、第3言語を含む選択とカードの絞り込み、pageshow時の同期も確認。
- テンプレートPHP25件合格。WP 7.1.3／PHP 8.2は134 assertions、WP 5.9／PHP 7.4は132 assertions。サイト言語ja_JPの基底言語への一致と、再試行の初期選択を確認。
- WP 7.1.3管理画面E2E3件合格（50.8秒）。JA／ENの選択、プレビュー、英語作成・保存・公開、既存の要素編集とCSSモード往復を確認。
- 1440px／390pxで両言語の一覧を目視確認。ラベル付きselectを一覧に1つ表示し、カード内の選択欄はなし。横はみ出しなし。
- TypeScript、変更PHP2ファイルのPHPCS／PHP 7.4互換性、i18n:build、git diff --check合格。

ログ・画像は`C:/Users/Inagaki/AppData/Local/Temp/kayzart-language-select-20261028/`。以下のボタンUIの記録は変更前の履歴。配布ZIPは再生成していない。

## 2026-10-28追補: 一覧の日本語／English切り替え

カード内の言語セレクトを削除し、一覧上部へ日本語／Englishの切り替えを追加。選択言語に対応するカードだけを表示し、サムネイル・プレビューURL・作成フォームの言語hidden項目を同期する。ページタイトルとAI指示は保持し、該当カードがない場合は案内を表示する。初期値は引き続きサイトのWPLANG、作成エラー後は再試行の言語を優先する。

- JS全44ファイル・373件合格。単独では8件で、一覧全体の切り替え、片方の言語のみを持つ複数カードの絞り込み、該当なし、入力保持、言語別プレビューキャッシュなどを確認。
- PHPテンプレート25件合格。WP 7.1.3／PHP 8.2は132 assertions、WP 5.9／PHP 7.4は130 assertions。再試行時の初期言語、hidden項目、両言語のプレビューURLのID・言語・nonceを確認。
- WP 7.1.3管理画面の既存E2E3件合格（1.3分）。一覧切り替え→JA／ENプレビュー→英語作成・保存・公開の同期、既存の要素編集とCSSモード往復も確認。一時ページ・添付は回収済み。
- 1440px／390pxで両言語の一覧を撮影・目視確認。横はみ出しなし、カード内のセレクトなし、選択状態を表示。
- TypeScript、変更PHPのPHPCS／PHP 7.4互換性、`i18n:build`、`git diff --check`合格。日本語のグループ名と該当なし文言を追加し、翻訳生成物・通常ビルドを更新。

ログ・画像は`C:/Users/Inagaki/AppData/Local/Temp/kayzart-list-language-20261028/`。`list-ja-1440.png`、`list-en-390.png`など。配布ZIPは今回再生成していない。最低環境の管理画面E2E、main取り込み、PR・公開は引き続き別途必要。

## 2026-10-28: 無料サービス紹介の磨き直し

抽象的な汎用コピーを、架空の小規模Web／デザインスタジオの作例へ変更。Web制作・ブランド／販促・更新サポートの3サービスと、提供範囲が分かる3料金例、4段階の依頼フローを用意した。価格と連絡先はサンプルと明示し、実在する事業の実績や架空の口コミは追加していない。

生成り・チャコール・青を基調に、タイポグラフィ・余白・罫線を整理。ヒーロー写真を6:5の長方形へ縮小し、アーチと重なりバッジを廃止した。日本語・英語の本文／CSSと、各言語の実画面から撮った写真入りサムネイルを更新。グレーのみ・文字なしのPNG素材は変更していない。

| 対象 | 結果 |
|---|---|
| テンプレートPHP・WP 7.1.3／PHP 8.2 | 25件・132 assertions合格 |
| テンプレートPHP・WP 5.9／PHP 7.4 | 25件・130 assertions合格。最低環境の管理画面E2Eを意味しない |
| 実管理画面E2E・WP 7.1.3 | 既存3件合格（1.5分）。画像／文章の要素編集、保存・公開、CSSモード往復・再読込、JA／ENの選択・表示・保存 |
| 表示 | 両言語の320／390／768／1024／1440px幅で横はみ出しなし、全ページ内アンカーの参照先あり。PC・スマホを目視 |
| サムネイル | 実際の各言語HTMLを1200×900で撮影、WebP quality 85。日本語62,818 bytes、英語63,074 bytes |
| TypeScript | `npx tsc --noEmit`合格 |
| 差分 | `git diff --check`合格 |

既存テストの言語判定用見出しを新しい本文へ更新。保存失敗のfixtureは旧ブランド文言への依存を除き、非空のページ本文を壊して失敗回収を検証する。初回はこの旧文言と日本語だけに存在した改行クラスで2件失敗したため、fixtureと両言語のクラスを揃えて上記25件を再実行した。

撮影・ログは`C:/Users/Inagaki/AppData/Local/Temp/kayzart-service-refine-20261028/`。制作仕様は`scripts/templates/README.md`を参照する。既に作成したページのDBコピーや、ユーザー指定の既存メディアは今回変更していない。以下の配布ZIP・Plugin Checkは以前の版の記録であり、今回ZIPを再生成したとは扱わない。

## 2026-10-27追補: 汎用プレースホルダーの再制作

ユーザーの実機確認・追加指定に従い、同梱PNGをグレーのみ・文字なしの画像ピクトグラムへ置き換えた。正方形768×768、162,784 bytes。日本語・英語のimg属性とmanifestの寸法も同期した。制作方法と最終プロンプトは`scripts/templates/README.md`と`placeholder-prompt.txt`に記録。

- 全画素でRGBの各チャンネル値が一致することを検証（最大差0）。画像自体に文字はない。
- 同じローカルプレビュー生成処理で、日本語・英語それぞれのPC幅／390px幅を撮影・目視確認。アーチ形で不自然な枠線が残らず、下部のバッジと主アイコンが重ならない。
- 既存PHPテストのパッケージ読み込み・英語版の共有素材・完全な下書きとメディア登録・画像エディタなしの登録を確認。4件・33 assertions合格（WP 7.1.3／PHP 8.2）。
- ユーザーが指定した既存の`landscape-2.png`もバックアップ後に置き換え、対象の添付画像だけメタデータ・派生サイズを再生成。HTTPで配信されるPNGと同梱PNGのSHA-256が一致することを確認した。
- SHA-256: `46ddc30d386f795adcd43d7740d4cc8223f89aa3401763518d4bf0efa8f681a5`。

撮影・元画像のバックアップは`C:/Users/Inagaki/AppData/Local/Temp/kayzart-placeholder-polish-20261027/`。最新の目視結果は`gray-ja-mobile.png`と`gray-en-desktop.png`など。以下のZIP検証は画像更新前の記録であり、公開前にZIPを再生成する。

## 2026-10-27追補: UI調整と日本語・英語の選択

ユーザーの追加指示により、1デザインに日本語・英語の本文と写真入りサムネイルを用意した。カードの「ページの言語」で両者を選び、サムネイル・詳細プレビュー・作成本文を揃える。サイトのWPLANGが日本語なら日本語、それ以外は英語が初期値。管理者の表示言語を本文の初期値には使わない。

- `index.en.html`と`thumbnail.en.webp`を追加。英語本文は全セクションを用意し、CSS・画像プレースホルダー・写真の元データは共通。英語サムネイルは実際の英語HTMLを1200×900で撮影しWebPへ書き出した（72,666 bytes）。
- manifestの`localizations.en`を検証し、許可済みのja／enだけを読み込む。プレビューのHTML言語とキャッシュを分ける。作成本文のrootにもlangを付け、由来言語を投稿メタへ保存する。
- 言語違いで同じ要求UUIDを再送するとエラーにし、先に作成したページを返したり上書きしたりしない。失敗時の再表示でも選択言語を保持する。複製は由来言語を引き継ぐ。
- 開始方法とプレビュー幅を一体型の切り替えUIへ変更。Closeは別配置にし、カードの入力欄・ボタンも調整。Mobileで閉じて開き直すと、表示幅・選択状態がDesktopへ揃う。

| 確認 | 結果 |
|---|---|
| JS全体 | 44ファイル・371件合格 |
| PHP全体・WP 7.1.3／PHP 8.2 | 693件・3,108 assertions合格 |
| 最終テンプレートPHP・WP 7.1.3 | 25件・132 assertions合格 |
| 最終テンプレートPHP・WP 5.9／PHP 7.4.33 | 25件・130 assertions合格。実管理画面E2Eの代わりにはしない |
| TypeScript | 最終E2E・共通ヘルパーの変更後にtsc合格 |
| PHP本体3ファイル | PHPCS・PHP 7.4以上の互換性チェック合格 |
| 実管理画面E2E・WP 7.1.3 | 既存2件＋言語切り替え／英語保存・公開1件、計3件合格（1.2分） |
| 翻訳・ビルド | i18n:build、plugin-zip合格。新しい4文言の日本語訳・MOも確認 |
| Plugin Check 2.1.0 | 新ZIPを別サイトへ導入して実行。指摘なし |
| 配布ZIP | 367ファイル・1,578,741 bytes。両言語の本文・サムネイル・共有素材・翻訳が作業ツリーとハッシュ一致。開発ファイルなし |

PHP全体実行後にプレビューURL生成を整理し、最終25件で両言語URLのID・言語・nonceを検証した。日本語の実要素編集は既存E2Eで確認し、追加した英語E2Eは拡張APIで見出しを変更してからUIで保存し、RESTで公開・公開ページの英語本文／lang／390pxの幅／アンカーを確認する。JA→EN→JAのプレビューとブラウザキャッシュの混在も回帰対象にした。

初期の英語E2Eが停止したため、残った一時ページ・メディアを共通ヘルパーで回収し、修正した3件を再実行して合格した。最終確認では固定ページは元のSample Page／Privacy Policyだけ。テストで有料AIは呼ばない。

目視用画像・実行ログは`C:/Users/Inagaki/AppData/Local/Temp/kayzart-template-locales-20261027/`。`language-card-desktop.png`、`language-card-mobile.png`、`english-preview-desktop.png`、`english-preview-mobile-mode.png`を確認した。

以下は英語版追加前の記録。Action Schedulerの別修正はmainへ入り3.4.4として公開済みだが、このブランチへの取り込みと最低環境のテンプレートE2Eは残る。新ZIPも機能ブランチの検証用で、公開版ではない。

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
