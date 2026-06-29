# REST API

Base URLは `/wp-json/od-product-hub/v1`、JSON、HTTPS必須です。

- `POST /activate`: 初回契約検証
- `POST /verify`: 定期契約検証
- `POST /deactivate`: サイト側の解除記録（ライセンス自体は停止しない）
- `GET /product?product_slug=...`: 公開商品情報

POST共通項目は `license_key`、`product_slug`（必須）、`site_url`、`plugin_version`、`wp_version`、`php_version`です。応答はStripe Customer/Subscription IDや個人情報を含みません。`active` 以外は `invalid_license`、`subscription_inactive`、`subscription_payment_failed`、`license_suspended` などの `error_code` を返します。HTTP 429はレート制限です。
