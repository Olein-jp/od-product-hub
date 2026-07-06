# アーキテクチャ

## 販売元Hubと購入者Hub

OD Product Hub自身の有償契約は、購入者サイト内で自己検証しません。販売元が別サイトで運用する上位OD Product Hubが、`od-product-hub` 商品の契約・ライセンス・署名済みリリースを管理します。購入者サイトのOD Product Hubは同梱したclient-sdkを利用し、上位Hubの `activate` / `verify` / `deactivate` / `updates/check` を呼び出します。

購入者Hubが管理する商品、顧客、契約、ライセンス、API、ログ、リリースは、この製品ライセンスから独立しています。販売元契約が失効してもローカル機能や既存データは停止・削除されず、制限されるのはOD Product Hub自身の新規更新と販売元サービスだけです。接続先が自身のサイトと一致する場合は、循環リクエストを避けるため通信前に設定エラーとします。

WordPress Options APIは秘密設定、`wp_odph_*` 独自テーブルは検索・同期対象データに使います。Stripe Webhookを契約状態の正とし、イベントIDの一意制約で冪等性を確保します。REST APIは保存済み状態だけを参照して高速応答し、Stripeへ同期問い合わせしません。

Webhookの状態は `processing` → `success` / `unsupported` / `error` → `exhausted` と遷移します。`success` と `unsupported` だけを処理済みの重複としてHTTP 200で応答します。`error` または5分以上更新されていない `processing` は、条件付きUPDATEで排他的に再claimします。処理中の並行配送はHTTP 409、5回失敗した終端状態はHTTP 503とし、Stripeへ未完了を伝えます。管理者が終端状態を再開した場合も、保存済みのマスク済みpayloadは再生せず、Stripeからの新しい署名付き配送だけを受け付けます。

依存方向は Admin / Frontend / API / Webhook → Service / Repository → WordPress DBです。クライアントSDKは公開REST契約だけに依存し、HubのDBやStripe SDKへ依存しません。更新配信は `Release` ドメインとして分離され、SDKの `WordPress\Updater` がWordPress標準更新へ接続します。

## 公開APIと更新配信

- `API`: 契約検証、商品情報、更新確認、一回利用ダウンロードの公開RESTルートを登録します。
- `Release`: リリース情報、秘密ストレージ、SHA-256・Ed25519署名、短命な一回利用ダウンロード権を管理します。
- `packages/client-sdk`: Hubの公開REST契約を利用し、契約状態のキャッシュとWordPress標準Updater連携を提供します。

RESTの正確な入出力は [API.md](API.md)、SDKの導入は [クライアントSDK README](../packages/client-sdk/README.md)、配布・署名鍵・監視の運用は [UPDATE_DELIVERY.md](UPDATE_DELIVERY.md) を参照してください。

## 管理画面

管理画面は責務ごとに次のクラスへ分割します。

- `AdminMenu`: WordPressの管理hook、メニュー、既存slugとcallbackの配線だけを担当します。
- `DashboardPage`、`ProductPage`、`LicensePage`、`CustomerPage`、`LogsPage`: 各管理画面の描画を個別に担当します。
- `AdminActionHandler`: `admin-post.php` の商品保存・状態変更、ライセンス操作、ログ削除、Stripe接続確認を担当します。
- `AdminSettings`: Settings APIへの登録、設定サニタイズ、設定画面の描画を担当します。
- `AdminSiteHealth`: 設定警告とSite Health診断を担当します。

`Plugin` はcomposition rootとして、共通ライフサイクル、フロントエンドと `admin-post`、RESTとStripe Webhook、管理画面、WP-CLIの登録経路を分離します。REST Controllerは `rest_api_init`、管理画面機能は `is_admin()` の経路、Release CommandはWP-CLIでのみ登録されます。フロント表示では管理画面のフックも登録しません。

管理画面では、`AdminMenu` に明示的なFactoryを注入し、WordPress hookと既存slugだけを先に登録します。Repository、Service、各Page、`AdminActionHandler` は対象画面の描画、`admin-post` 処理、Site Health診断など、その依存が実際に必要になった時点で一度だけ生成します。静的なService Locatorやグローバルコンテナは使いません。管理画面URL、`admin_post_*` hook、フォームaction、nonce名、`manage_options` の権限境界はこの分割の外部契約として維持します。

スキーマバージョン、段階的マイグレーション、各テーブルとインデックス、Repositoryの共通契約、UTC保存方針は [DATABASE.md](DATABASE.md) を参照してください。アプリケーション層から直接SQLを発行せず、すべて専用Repositoryを経由します。
