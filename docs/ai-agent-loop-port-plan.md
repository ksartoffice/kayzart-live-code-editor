# AIエージェントループ 無料版移植 実装計画

> ブランチ: `feat/ai-agent-loop-free`
> 対象: `kayzart-live-code-editor`（無料版）
> 目的: kayzart-pro の有料AI編集（エージェントループ）を、WordPress 7.0 公式 AI Client を用いて無料版へ移植する。kayzart-server（Node.js・課金・ライセンス・OpenAI直叩き）には依存しない。

## 1. 背景と狙い

現行の有料AI編集は、Node.js 常駐サーバ（`kayzart-server`）上でエージェントループを回し、pro プラグインがサーバ間通信でジョブを作成・ポーリングする構成。本計画では実行主体を WordPress 内（PHP）へ移し、モデル呼び出しを **WordPress 7.0 公式 AI Client（`wp_ai_client_prompt()` + Connectors のAPIキー管理）** に委譲する。

これにより無料版では以下が **不要** になる:

- OpenAI 直叩き（`callResponsesApi`）
- トークン課金（`ai-credit-billing`）／ライセンス／サーバトークン
- server-to-server 通信全般

利用者は自身（または制作会社）のAPIキーだけでAI編集を使える。

## 2. 確定した設計判断

| 項目 | 決定 |
|---|---|
| WordPress要件 | **Kayzart 3.0 から WordPress 7.0 以上を必須**とし、コアの AI Client を利用する。`php-ai-client` は composer 同梱しない。SDK存在確認は破損・無効化を検出する防御として残す。 |
| 権限モデル | カスタム capability `kayzart_ai_edit` を **ロール単位＋ユーザー個別の許可リスト併用**で制御。多テナント（制作会社がキー登録 → 顧客アカウントを管理者がトグル）を想定。 |
| コスト記録 | 無料版では月次回数を制限せず、ジョブ単位のトークン使用量を記録する。利用上限は将来の Pro 機能候補とする。 |
| 実行モデル | **Action Scheduler の単一アクションで完走**（`set_time_limit(0)`）。ジョブ状態をカスタムテーブルに永続化し、既存のポーリングUIを流用。ステップワイズ（1ターン=1アクション）は必要時の後続対応。 |
| クライアント抽象化 | `Kayzart_Ai_Client_Interface` を挟む。当面はコア AI Client 版のみ実装。テスト時はフェイクを注入。 |

## 3. アーキテクチャ（移植後）

```
ブラウザ（AIタブ / window.KAYZART_EXTENSION_API）
  → POST kayzart/v1/ai/jobs        ジョブ作成 + Action Scheduler へ enqueue、即 jobId 返却
  → GET  kayzart/v1/ai/jobs/{id}   ポーリング（status + events + snapshot）
  → POST kayzart/v1/ai/jobs/{id}/cancel
        │
   Action Scheduler ワーカー（バックグラウンド）
     → Kayzart_Ai_Agent::run()      15ターンループ（現 runAgentLoop の移植）
          → Kayzart_Ai_Client       WordPress\AI_Client（function calling + JSON出力）
          → Kayzart_Ai_Tools        スナップショット文字列操作（現 runToolCall* の移植）
          → Kayzart_Ai_Job_Store    進捗 / events / snapshot を DB へ逐次書き込み
```

### 既存の再利用資産（無料版に既にある拡張基盤）

- PHP拡張点: `do_action('kayzart_editor_enqueue_assets', $post_id)`（`includes/class-kayzart-admin.php`）
- JSホストAPI `window.KAYZART_EXTENSION_API`（`src/admin/extensions/settings-tab-registry.ts`）
  - `registerSettingsTab` / `registerToolbarAction`（AIタブUIの注入）
  - `getEditorSnapshot()` → `{html,customHead,css,js,jsMode,baseHash}`
  - `replaceEditorSnapshot()`（AI結果をタブへ反映）
  - `getEditorMode()` / 選択要素コンテキスト / `reloadPreview`
- スナップショット形状（`includes/class-kayzart-snapshot.php` の `Snapshot::for_post`）は現行 `AiEditSnapshot` と一致。
- 設定タブ拡張: `kayzart_settings_tabs` フィルタ。

→ pro の `src/editor-tab/main.tsx` が無料版UIの参照実装になる。

## 4. 現行TS実装との本質的差分（実装前に押さえる点）

| 論点 | 現行（Responses API） | 移植後（WP AI Client） |
|---|---|---|
| 会話継続 | `previous_response_id` で差分のみ送信 | **`$messages` 配列を毎ターン再送**。ループは全履歴を保持 |
| ツール宣言 | `tools[]` JSON | `FunctionDeclaration[]` を `using_function_declarations()` |
| 最終JSON強制 | 毎ターン `json_schema` strict | **仕上げターンのみ `asJsonResponse()`**、通常ターンはツールのみ |
| モデル固定 | `gpt-5.4-mini` | 固定不可 → モデル選好の表明のみ（**要PoC検証**） |
| 実行時間 | 常駐で無制限 | ASワーカーで `set_time_limit(0)`、単一アクションで完走 |

## 5. フェーズ分解とファイル一覧

### Phase 0: 基盤・依存・可用性ゲート（完了）

- リリース基準: Kayzart `3.0.0`、WordPress `7.0` 以上、PHP `7.4` 以上。
- composer: `woocommerce/action-scheduler:4.0.0`。`php-ai-client` は同梱しない。
- DBマイグレーション: `wp_kayzart_ai_jobs` テーブル（`job_uuid, post_id, user_id, request_id, status, payload_json, snapshot_json, events_json, usage_json, error, created_at, updated_at`）。`register_activation_hook` + `dbDelta`、スキーマバージョン管理。
- capability `kayzart_ai_edit` の定義（activation で管理者ロールへ付与）。
- **可用性判定** `Ai_Availability::get_status()`: (1) 機能ゲート、(2) AI Client SDK、(3) Connectors のプロバイダ、(4) Action Scheduler。結果を editor へ localize。
- 変更: `kayzart-live-code-editor.php`（新規ファイルの require、`plugins_loaded` で条件付き init）。

### Phase 1: ツール層の移植（約1〜1.5日、テスト重視）

純粋関数なので最初に移植＋ユニットテストで固める（最大の再利用資産）。

- 新規 `includes/ai/class-kayzart-ai-tools.php` — 現 `ai-jobs.ts` の tool 実体の移植: `search_text` / `read_document` / `get_selected_context` / `replace_string`（完全一致・曖昧検出・空from初期化）/ `replace_many` / `set_js_mode`。`computeBaseHash`（FNV-1a）も移植。
- 新規 `includes/ai/class-kayzart-ai-tool-schema.php` — `buildToolDefinitions` 相当 → `FunctionDeclaration` 配列生成、編集ポリシー（normal/tailwind、CSS明示意図キーワード）。
- テスト `tests/test-ai-tools.php` — 0件マッチ→エラー、曖昧→エラー、replaceAll、初期化 等の挙動を再現。

### Phase 2: プロンプト＋エージェントループ（約2日）

- 新規 `includes/ai/class-kayzart-ai-prompt.php` — システムプロンプト（セキュリティ規則含む）と `buildUserPrompt`（leading context / selected context policy）の移植。
- 新規 `includes/ai/class-kayzart-ai-client.php` — `WordPress\AI_Client` のラッパー（インターフェース `Kayzart_Ai_Client_Interface`）。`generate($messages, $declarations, $json_mode)` を提供。可用性判定を内包。
- 新規 `includes/ai/class-kayzart-ai-agent.php` — `runAgentLoop` / `runFinalizationTurns` の移植: 15ターン、`$messages` 蓄積、`FunctionCall` 抽出→ツール実行→`FunctionResponse` 追記、反復失敗ガード、最低1編集チェック、仕上げターン。進捗は Job Store 経由で events 書き込み。
- テスト `tests/test-ai-agent.php` — フェイクAI Client 注入でループ分岐（ツール実行→終了、上限到達、失敗リカバリ）を検証。

### Phase 3: ジョブ基盤＋REST（完了）

- DBスキーマ v2: cancel・開始／終了／期限日時と投稿単位の一意ロックを追加。条件付き状態遷移、requestId 冪等性、最大300イベント、7日保持を実装。
- `includes/ai/class-kayzart-ai-job-store.php`: ジョブ作成、claim、イベントの比較付き更新、キャンセル／タイムアウト／完了、usage・snapshot・error 保存、日次削除を実装。
- `includes/ai/class-kayzart-ai-worker.php`: Action Scheduler の worker・timeout・cleanup、10分期限、失敗フック、`kayzart_ai_client` フィルター、deactivation 時の未完了キャンセルを実装。
- `includes/rest/class-kayzart-rest-ai.php`: `POST /ai/jobs`、`GET /ai/jobs/{id}`、`POST /ai/jobs/{id}/cancel` を実装。nonce、`edit_post`、`kayzart_ai_edit`、本人／管理者アクセス、入力サイズ、404秘匿を検証する。
- 無料版は月次回数を制限せず、各ジョブの `usage` のみを保存・返却する。回数・費用上限は将来の Pro 機能候補へ移した。

### Phase 4: フロントエンドUI（完了）

- `src/editor-ai/`: 無料版専用のREST契約、APIエラー処理、ポーリング、実行中ジョブ復元、AI編集React UIを実装。ライセンス、credits、model、サーバー履歴、バージョンDBへの依存は持たない。
- `vite.config.ai.ts`: `@wordpress/element` と `@wordpress/i18n` を外部依存にし、`assets/dist/ai-editor.js` と `ai-editor.css` を通常管理画面バンドルの後に生成する。
- AIタブ、ツールバー、Elementsパネル、プレビュー選択操作を追加。promptは8KB、選択コンテキストは20件、画面内メッセージは100件、戻す／再適用用snapshotは20組を上限とする。
- 実行中はエディタをロックしてイベントを表示し、キャンセルと全終端状態を処理する。完了snapshotは自動反映するが投稿は自動保存せず、既存の保存操作へ委ねる。
- 会話履歴と完了結果はページ再読み込みで破棄する。実行中ジョブだけを `sessionStorage` の `kayzart.ai.activeJob.{postId}` に保存し、再読み込みまたはAIタブ再表示時にポーリングを再開する。
- `window.KAYZART.ai` にジョブREST URL、Connectors URL、管理権限を追加し、機能ゲート、SDK、プロバイダー、Schedulerの原因別案内を実装。`kayzart_ai_edit` 非保有者にはAI資産を出力しない。
- Kayzart 3.0以上では `kayzart-pro` の旧AIタブ、ツールバー、プレビュー操作を抑止し、Proのライセンス設定、REST、バージョン履歴は維持する。
- DBジョブ一覧、AI編集履歴UI、履歴ツールはPhase 4の対象外とする。

### Phase 5: ゲーティング・権限UI・仕上げ（約1日）

- 新規 `includes/ai/class-kayzart-ai-access.php`:
  - ロール許可リスト＋ユーザー個別許可リスト（オプション保存）を `user_has_cap` フィルタで `kayzart_ai_edit` に写像。
  - 管理者は常時可、顧客アカウントはトグル。
- 管理設定タブ「AI編集の権限」UI（`kayzart_settings_tabs` フィルタ相乗り、ロールのチェックボックス群＋ユーザー個別追加）。
- editor側ゲーティング: プロバイダ未設定→Connectors導線、権限無し→タブ非表示。SDK無しは通常の利用条件ではなく診断エラーとして扱う。
- レート制限、監査ログ、i18n、`readme.txt` / `overview.md` に拡張ポイント追記。
- E2E（`/verify`）: 指示→ジョブ→ツール実行→スナップショット適用→保存を実機確認。

## 6. 概算工数

**約9〜10営業日**（PoC が通っている前提）。最大の不確実要素は Phase 2 の「モデル非固定下での `replace_string` 完全一致編集の安定性」。

## 7. 主要リスク

1. **モデル固定不可**: Connectors 任せのため、非力なモデルだと完全一致編集が失敗ループに入りやすい（現行の反復失敗ガード相当が必須）。→ PoC で先に検証。
2. **Connectors のキー非暗号化（既知）**: env/PHP定数での設定を推奨する運用案内が必要。プラグイン側はキーを保持しない設計なので責任範囲は限定的。
3. **function calling + strict JSON 同時利用のプロバイダ差**: 通常ターンはツールのみ、最終要約だけ `asJsonResponse()` に分離。
4. **ホスティングのタイムアウト**: 単一ASアクション完走が厳しい環境向けにステップワイズ化を後続で用意。
5. **WP AI Client SDK のAPI名は流動的**: 実装前に導入バージョンの実物で確認する。
6. **無料開放によるコスト**: 無料版は利用者自身の Connectors API キーを使い、ジョブごとの token usage を記録する。組織単位の上限管理は将来の Pro 機能候補。

## 8. 次アクション候補

- Phase 5: ロール／ユーザー単位のAI編集権限UI、運用案内、i18n、リリース文書、E2Eの仕上げ。
- 実プロバイダーでの編集成功系はリリース前に最小件数で再確認し、モデル非固定下の `replace_string` 安定性を評価する。
