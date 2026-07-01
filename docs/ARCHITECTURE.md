# アーキテクチャ

WordPress Options APIは秘密設定、`wp_odph_*` 独自テーブルは検索・同期対象データに使います。Stripe Webhookを契約状態の正とし、イベントIDの一意制約で冪等性を確保します。REST APIは保存済み状態だけを参照して高速応答し、Stripeへ同期問い合わせしません。

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

RepositoryとServiceは各クラスのコンストラクタへ渡し、`Plugin` がcomposition rootとして既定実装を組み立てます。管理画面URL、`admin_post_*` hook、フォームaction、nonce名、`manage_options` の権限境界はこの分割の外部契約として維持します。

スキーマバージョン、段階的マイグレーション、各テーブルとインデックス、Repositoryの共通契約、UTC保存方針は [DATABASE.md](DATABASE.md) を参照してください。アプリケーション層から直接SQLを発行せず、すべて専用Repositoryを経由します。
