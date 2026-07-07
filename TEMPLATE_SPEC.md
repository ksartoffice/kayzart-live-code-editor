# Kayzart Free Template Spec

このドキュメントは、無料版 Kayzart Live Code Editor で提供するテンプレート機能 v1 の仕様を定義する。

対象は、Kayzart 管理の外部 API から取得する Tailwind CSS 前提の無料 HTML テンプレートである。Pro テンプレート、テンプレート管理画面、UI 実装、サーバー側 DB 設計の詳細は後続チケットで扱う。

## 目的と対象範囲

無料テンプレート v1 は、新規作成時にユーザーがテンプレートを選び、初期 HTML と Tailwind 用 theme tokens を反映できるようにする機能である。

対象範囲:

- Kayzart 管理の特定 URL/API からテンプレート一覧と詳細を取得する
- 無料テンプレートの HTML を新規投稿作成時の初期 HTML として使う
- テンプレート選択後は Tailwind モードで新規作成する
- テンプレートの対象市場を `jp` または `en` で選択できるようにする
- テンプレート一覧/プレビュー用のスクリーンショット画像 URL を `thumbnailUrl` としてメタ情報に含める
- `@theme` 相当の値は CSS 文字列ではなく theme tokens JSON として扱う
- 無料版で取得できる Pro テンプレート情報は一覧表示用メタ情報までに制限する

対象外:

- 既存投稿へのテンプレート適用
- 既存 HTML への挿入
- 既存 HTML の上書き
- Pro テンプレートの HTML/CSS/JS 本文配信
- テンプレート管理画面
- API の実 URL
- サーバー側 DB スキーマ

## 無料テンプレート v1 の基本方針

- 無料テンプレートは Tailwind CSS モード専用とする。
- テンプレート本文は HTML のみ配信する。
- CSS 文字列、JavaScript、外部アセット参照は配信しない。
- Tailwind 本体、Tailwind コンパイル処理、保存処理はプラグイン側の既存機能を使う。
- theme tokens は安全な JSON として配信し、プラグイン側で検証してから Tailwind 用 CSS に変換する。
- テンプレート詳細取得は、ユーザーがテンプレートを選択して新規作成するタイミングで行う。
- API から取得した内容は、サーバー側で検証済みであっても、プラグイン側で再検証する。

## テンプレートメタ情報

一覧 API は、テンプレートカード表示に必要なメタ情報のみを返す。

必須フィールド:

- `id`: テンプレートの安定識別子。小文字英数字、ハイフン、アンダースコアのみ。
- `title`: UI 表示名。
- `description`: 短い説明文。
- `category`: カテゴリ識別子。
- `market`: 対象市場。`jp` または `en`。
- `tier`: `free` または `pro`。
- `thumbnailUrl`: 一覧/プレビュー表示用のスクリーンショット画像 URL。
- `requiresTailwind`: 無料テンプレートでは常に `true`。
- `available`: 現在のユーザー/プラグインで本文取得できるか。
- `version`: テンプレートのバージョン文字列。

`market` は UI 文言の翻訳言語ではなく、ランディングページのデザイン文脈を表す。`jp` は日本向け、`en` は英語圏向けの構成・余白・訴求・情報量を想定する。

`thumbnailUrl` は一覧/プレビュー表示用メタ情報として許可する。HTML 本文内に画像 URL や外部 URL を含めることは引き続き禁止する。

無料版で Pro テンプレートを一覧表示する場合、`tier: "pro"` と `available: false` を返す。無料版 API は Pro テンプレートの `html`、`theme`、CSS、JS、外部アセット本文を返してはならない。

## テンプレート本文仕様

詳細 API が返す無料テンプレート本文は、単体の HTML 断片である。

許可する内容:

- HTML 要素
- テキスト
- Tailwind CSS クラス
- `aria-*` 属性
- `data-*` 属性
- `role` 属性
- ページ内リンクとしての `href="#..."` または `href="#"`
- 画像を使わない静的レイアウト
- 送信先を持たないフォーム風マークアップ

本文は完全な HTML 文書ではなく、エディタの HTML 欄に入る断片とする。`<!doctype>`, `<html>`, `<head>`, `<body>` は返さない。

無料テンプレートは外部 URL 参照を含めない。画像、フォント、スクリプト、スタイルシート、iframe、フォーム送信先など、外部リソースまたは外部通信につながる値は禁止する。

## Theme Tokens 仕様

無料テンプレートは CSS 文字列を返さず、`theme` フィールドで安全な theme tokens を返す。

例:

```json
{
  "colors": {
    "primary": "#2563eb",
    "accent": "#f97316",
    "surface": "#ffffff",
    "text": "#111827"
  },
  "radius": {
    "sm": "4px",
    "md": "8px",
    "lg": "12px"
  },
  "spacing": {
    "section": "64px"
  }
}
```

許可する値:

- 色は hex のみ。例: `#fff`, `#ffffff`。
- `radius` と `spacing` は数値 + 許可単位のみ。許可単位は `px`, `rem`, `%`。
- キー名は小文字英数字、ハイフン、アンダースコアのみ。

禁止する値:

- CSS 文字列
- selector
- URL
- `var(...)`
- `calc(...)`
- `env(...)`
- `theme(...)`
- 任意の CSS 関数
- `@import`, `@theme`, `@layer` などの CSS at-rule

プラグイン側は、theme tokens を検証した上で Tailwind 用 CSS に変換する。API から `@theme { ... }` のような CSS 文字列を直接受け取って注入してはならない。

## 禁止事項

無料テンプレートの HTML 本文では以下を禁止する。

- `<script>`
- `<link>`
- `<iframe>`
- `<style>`
- `<object>`
- `<embed>`
- `<base>`
- `<meta>`
- `<noscript>`
- `onClick`, `onload` などの `on*` 属性
- `style` 属性
- 外部 URL を含む属性値
- `form action`
- `src`
- `srcset`
- `poster`
- `href` の外部 URL
- `target="_blank"` を伴う外部遷移
- JavaScript URL。例: `javascript:...`
- Data URL。例: `data:...`
- tracking pixel、外部フォーム、外部埋め込みにつながる値

無料版 API は以下を返してはならない。

- Pro テンプレートの HTML 本文
- Pro テンプレートの CSS 本文
- Pro テンプレートの JavaScript 本文
- Pro テンプレートの外部アセット URL 一覧
- 無料テンプレート用の任意 CSS 文字列

## API レスポンス最小仕様

一覧 API の最小レスポンス:

```json
{
  "templates": [
    {
      "id": "hero-saas-01",
      "title": "SaaS Hero",
      "description": "A simple Tailwind hero section for product landing pages.",
      "category": "landing",
      "market": "en",
      "tier": "free",
      "thumbnailUrl": "https://templates.example.com/thumbs/hero-saas-01.webp",
      "requiresTailwind": true,
      "available": true,
      "version": "1.0.0"
    },
    {
      "id": "pricing-pro-01",
      "title": "Pricing Pro",
      "description": "A richer pricing layout available in Kayzart Pro.",
      "category": "pricing",
      "market": "jp",
      "tier": "pro",
      "thumbnailUrl": "https://templates.example.com/thumbs/pricing-pro-01.webp",
      "requiresTailwind": true,
      "available": false,
      "version": "1.0.0"
    }
  ]
}
```

詳細 API の最小レスポンス:

```json
{
  "id": "hero-saas-01",
  "version": "1.0.0",
  "market": "en",
  "html": "<section class=\"mx-auto max-w-6xl px-6 py-20\"><h1 class=\"text-4xl font-bold text-gray-950\">Launch faster</h1><p class=\"mt-4 text-lg text-gray-600\">Start with a clean Tailwind layout.</p></section>",
  "theme": {
    "colors": {
      "primary": "#2563eb",
      "accent": "#f97316"
    },
    "radius": {
      "md": "8px"
    },
    "spacing": {
      "section": "64px"
    }
  },
  "checksum": "sha256:example"
}
```

無料版で `tier: "pro"` または `available: false` のテンプレート詳細を要求した場合、API は本文を返さず、権限不足を示すエラーを返す。

## 検証・サニタイズ方針

無料テンプレートは、サーバー側とプラグイン側の両方で検証する。

サーバー側:

- テンプレート登録時に禁止タグ、禁止属性、外部 URL、禁止 theme token を検査する。
- `market` が `jp` または `en` であることを検査する。
- `thumbnailUrl` が Kayzart 管理ドメイン配下であることを検査する。
- 公開前に checksum を生成する。
- 無料版からの Pro テンプレート詳細取得を拒否する。

プラグイン側:

- 詳細取得後に禁止タグを検査する。
- 禁止属性を検査する。
- 外部 URL を検査する。
- `market` が `jp` または `en` であることを検査する。
- `thumbnailUrl` はメタ情報としてのみ扱い、HTML 本文へ挿入しない。
- theme tokens のキーと値を検査する。
- checksum がある場合は本文と theme の整合性を検証する。
- 違反があるテンプレートは新規作成に使わない。
- 違反時はユーザーに一般的なエラーを表示し、詳細は開発者向けログに残す。

検証失敗時、プラグインは受け取った HTML や theme tokens を部分的に修正して適用しない。テンプレート全体を拒否する。

## WordPress.org 向け外部通信説明メモ

無料版は WordPress.org 配布を想定し、外部通信について readme と管理画面上で説明する。

説明に含める内容:

- テンプレート一覧と詳細を Kayzart 管理サーバーから取得すること
- 通信はテンプレート選択 UI を開いた時、またはテンプレートを選択した時に行うこと
- 取得する内容はテンプレートメタ情報、対象市場、`thumbnailUrl`、無料テンプレート HTML、theme tokens であること
- 無料テンプレートでは外部 JavaScript、外部 CSS、外部アセット URL を本文として配信しないこと
- 送信するサイト情報、プラグインバージョン、ロケールなどがある場合は、その項目
- 送信しない情報がある場合は、その項目
- 利用規約 URL
- プライバシーポリシー URL

無料版のテンプレート配信は、外部から実行コードを受け取る仕組みにしない。CSS についても、任意 CSS 文字列ではなく theme tokens として扱う。

## 将来の Pro テンプレートとの差分メモ

Pro テンプレートでは、無料版より広い表現を扱う可能性がある。

後続仕様で決める項目:

- Pro テンプレート本文の取得認証
- 外部 URL の許可条件
- 外部 CSS/JS の扱い
- インライン `<script>` の扱い
- `on*` 属性の扱い
- iframe や外部埋め込みの扱い
- 適用前の危険度表示
- 管理者権限または `unfiltered_html` 権限との関係

無料版 API は、Pro テンプレートの存在をメタ情報として示してよい。ただし、Pro テンプレートの実体となる HTML/CSS/JS、外部アセット URL、実行コードに相当する情報は返さない。
