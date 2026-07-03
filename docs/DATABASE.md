# データベース設計

## マイグレーション

スキーマの現在バージョンは `OD_Product_Hub\Database\Schema::VERSION`、適用済みバージョンは `odph_schema_version` option に保存します。プラグイン有効化時と通常起動時に未適用バージョンを昇順で実行し、各段階が成功した後にだけ option を更新します。テーブル変更は `dbDelta()` で行うため、既存行を削除せずカラムとインデックスを調整できます。

新しい変更は `Installer::migrations()` に新バージョンを追加し、過去の migration を書き換えず段階的に適用します。日時はすべて UTC の `Y-m-d H:i:s` で保存し、画面表示時のみ `UtcDateTime::to_site()` で WordPress のサイトタイムゾーンへ変換します。REST API の ISO 8601 時刻は UTC です。

## Repository 契約

永続データごとに専用Repositoryを設け、共通基底クラスが次の戻り値を統一します。

- `create(array): int`: 作成したID。失敗時は `DatabaseException`
- `find(int): ?object`: 1行または `null`
- `update(int, array): bool` / `delete(int): bool`: 対象行を変更・削除した場合 `true`
- `search(array, int, int, string, string): RepositoryPage`: 行、総件数、ページ、ページサイズ、総ページ数

DBの詳細エラーはサーバーログだけへ記録し、呼び出し側には内部情報を含まない `DatabaseException` を返します。SQLは Repository とスキーマのライフサイクル処理へ限定します。

## テーブルとインデックス

| テーブル | 主な用途 | 一意インデックス | 検索インデックス |
|---|---|---|---|
| `wp_odph_products` | 商品とStripe Priceの対応 | `slug` | `stripe_price_id`, `status` |
| `wp_odph_customers` | WPユーザーとStripe Customerの対応 | `stripe_customer_id` | `wp_user_id`, `email` |
| `wp_odph_subscriptions` | Stripe Subscription状態 | `stripe_subscription_id` | `customer_id`, `product_id`, `stripe_status` |
| `wp_odph_licenses` | ライセンスキーと契約状態 | `license_key` | `license_key_hash`, `product_id`, `customer_id`, `subscription_id`, `status` |
| `wp_odph_webhook_logs` | Webhook冪等性、試行履歴、処理結果 | `stripe_event_id` | `result`, `created_at` |
| `wp_odph_api_logs` | 契約検証API監査 | なし | `license_id`, `product_id`, `action`, `result`, `created_at` |
| `wp_odph_admin_logs` | 管理操作監査 | なし | `user_id`, `action`, `created_at` |
| `wp_odph_rate_limits` | 公開APIの原子的レート制限 | `bucket_hash` | `expires_at` |

実際の接頭辞はサイトの `$wpdb->prefix` に従います。外部キー制約は WordPress の運用互換性を優先して設けず、参照整合性は Service / Repository 層で管理します。

カラム構成は次のとおりです（全テーブルの `id` は unsigned bigint の自動採番です）。

- `products`: `name`, `slug`, `description`, `stripe_product_id`, `stripe_price_id`, `status`, `created_at`, `updated_at`
- `customers`: `wp_user_id`, `stripe_customer_id`, `email`, `name`, `created_at`, `updated_at`
- `subscriptions`: `customer_id`, `product_id`, `stripe_subscription_id`, `stripe_status`, `current_period_start`, `current_period_end`, `cancel_at_period_end`, `payment_failed_at`, `created_at`, `updated_at`
- `licenses`: `product_id`, `customer_id`, `subscription_id`, `license_key`, `license_key_hash`, `status`, `issued_at`, `expires_at`, `last_verified_at`, `created_at`, `updated_at`
- `webhook_logs`: `stripe_event_id`, `event_type`, `payload`, `result`, `error_message`, `attempt_count`, `duplicate_count`, `last_attempt_at`, `last_received_at`, `created_at`
- `api_logs`: `license_id`, `product_id`, `action`, `result`, `site_url`, `ip_address`, `user_agent`, `error_code`, `created_at`
- `admin_logs`: `user_id`, `action`, `object_type`, `object_id`, `details`, `created_at`
- `rate_limits`: `bucket_hash`, `request_count`, `expires_at`, `created_at`, `updated_at`。bucketはクライアントIPとRESTルートを含む値のSHA-256で、生のIPやルートは保存しません。

## レート制限カウンター

標準Transientは複数PHPプロセス間のread-modify-writeを原子的にできず、オブジェクトキャッシュ経路とDB経路でカウントが分裂する可能性があります。そのため `wp_odph_rate_limits` をRedisの有無にかかわらない唯一の正本とし、`INSERT ... ON DUPLICATE KEY UPDATE` と接続単位の `LAST_INSERT_ID()` で各増分の確定値を取得します。期限切れ行は `odph_cleanup_logs` の固定サイズbatchで削除します。

## テスト

`npm run test:database` は wp-env の分離された tests 環境で、新規作成、旧バージョンからの更新、全RepositoryのCRUDとページネーション、一意制約、`delete_on_uninstall` の両設定を確認します。テスト終了時に空の最新スキーマを再作成します。
