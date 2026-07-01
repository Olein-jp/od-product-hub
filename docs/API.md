# REST API

Base URLは `/wp-json/od-product-hub/v1`、本文はJSONです。本番環境ではHTTPSが必須です。レスポンスに氏名、メールアドレス、WordPressユーザーID、Stripe Customer/Product/Price/Subscription IDは含まれません。

## エンドポイント

- `POST /activate`: 初回契約検証。成功時に最終検証日時を更新します。
- `POST /verify`: 定期契約検証。成功時に最終検証日時を更新します。
- `POST /deactivate`: サイト側の解除をログへ記録します。ライセンス状態と最終検証日時は変更しません。
- `GET /product?product_slug=...`: active商品の公開情報を返します。
- `POST /updates/check`: 契約と署名済み公開版を検証し、更新がある場合だけ一回利用URLを返します。
- `OPTIONS /activate`、`/verify`、`/deactivate`、`/product`: 引数のJSON Schemaを返します。

## POST入力Schema

| 項目 | 必須 | 制約 |
| --- | --- | --- |
| `license_key` | はい | `ODPH-XXXX-XXXX-XXXX-XXXX`、大文字、曖昧文字 `I/O/0/1` なし |
| `product_slug` | はい | 小文字英数字と単語間のハイフン、最大191文字 |
| `site_url` | いいえ | HTTP/HTTPS、最大255文字、URL内のユーザー名・パスワードは禁止 |
| `plugin_version` | いいえ | 数字をドットで区切ったバージョン、最大32文字 |
| `wp_version` | いいえ | 同上 |
| `php_version` | いいえ | 同上 |

`updates/check` に限り `plugin_version` は必須です。形式に合わない値、または省略時はHTTP 400となり、ダウンロード権を発行しません。

不正な入力はWordPress REST API標準の `rest_invalid_param` とHTTP 400を返し、APIログへ保存しません。

```json
{
  "license_key": "ODPH-ABCD-EFGH-JKLM-NPQR",
  "product_slug": "example-plugin",
  "site_url": "https://client.example.com/",
  "plugin_version": "1.2.3",
  "wp_version": "7.0.0",
  "php_version": "8.3.12"
}
```

## 成功レスポンス

`activate` と `verify` はHTTP 200で次の形を返します。日時はUTCのISO 8601です。

```json
{
  "success": true,
  "status": "active",
  "license": {
    "key_masked": "ODPH-ABCD-****-****-NPQR",
    "expires_at": null,
    "last_verified_at": "2026-06-30T00:00:00+00:00"
  },
  "subscription": {
    "status": "active",
    "current_period_end": "2026-07-30T00:00:00+00:00",
    "cancel_at_period_end": false
  },
  "product": {
    "slug": "example-plugin",
    "name": "Example Plugin"
  },
  "checked_at": "2026-06-30T00:00:00+00:00",
  "message": "License is active."
}
```

`deactivate` は同じ安全な契約情報に `"deactivated": true` を加え、`status` は `active` のまま返します。これはサイト解除の記録であり、キー自体の無効化ではありません。

## 更新確認レスポンス

署名検証済みの公開版が `plugin_version` より新しい場合だけ、`updates/check` は `update_available: true`、リリース情報、短命・一回利用の `download_url` を返し、ダウンロード権を1件発行します。

同一版、またはクライアント側が新しい版の場合、ダウンロード権は発行せず、次の最小レスポンスを返します。`release` と `download_url` は含みません。

```json
{
  "success": true,
  "update_available": false
}
```

`plugin_version` がバージョン形式に合わない場合は `rest_invalid_param`、省略時は `rest_missing_callback_param` とHTTP 400を返します。公開版がない場合やパッケージ署名を検証できない場合も、ダウンロード権を発行せず上記の最小レスポンスを返します。

## 契約状態とerror_code

業務上の契約エラーはHTTP 200、`success: false` で返します。

| `status` | `error_code` | 意味 |
| --- | --- | --- |
| `inactive` | `invalid_license` | 形式は正しいが、キーと商品の組み合わせが存在しない |
| `inactive` | `license_inactive` | ライセンスが無効 |
| `inactive` | `product_inactive` | 商品が停止中 |
| `inactive` | `subscription_inactive` | Stripe契約が有効・試用中ではない |
| `inactive` | `payment_failed` | 支払い失敗、`past_due`、`unpaid` |
| `expired` | `license_expired` | ライセンス期限切れ |
| `cancelled` | `license_cancelled` | ライセンスまたはStripe契約が解約済み |
| `suspended` | `license_suspended` | 管理者による停止。Stripe同期では解除されない |

```json
{
  "success": false,
  "status": "suspended",
  "error_code": "license_suspended",
  "message": "License is suspended."
}
```

## HTTPコード

- `200`: 商品取得成功、契約検証成功、または業務上の契約エラー
- `400`: JSON Schemaに合わない入力
- `403`: 本番環境でHTTPSを使用していない
- `404`: 商品が存在しない、またはactiveではない
- `429`: レート制限超過

## レート制限

レート制限は「解決したクライアントIP + RESTルート」を1分単位で集計します。応答には `X-RateLimit-Limit`、`X-RateLimit-Remaining`、`X-RateLimit-Reset` を含み、HTTP 429では `Retry-After` も返します。制限超過リクエストはAPIログへ保存しないため、大量リクエストでログが無制限に増えません。

```json
{
  "success": false,
  "error_code": "rate_limited",
  "message": "Too many requests."
}
```

## 信頼するプロキシ

初期状態では `X-Forwarded-For` を一切信頼せず、接続元の `REMOTE_ADDR` を使用します。ロードバランサーやCDN配下では管理画面の「信頼するプロキシ」にIPまたはCIDRを1行ずつ設定してください。信頼済みの接続元から届いた場合だけ、`X-Forwarded-For` を右から検証して最初の非信頼IPをクライアントとして採用します。

コードから追加する場合は `odph_trusted_proxy_cidrs` フィルターを使用できます。

## 契約テストと性能目標

`npm run test:api` は、OPTIONS Schema、全状態、HTTPコード、JSON本文、情報非開示、信頼プロキシ、429ヘッダー、ログ境界、`last_verified_at`、deactivateの非破壊性をWordPress実環境で検証します。同じ環境・データで7回計測した中央値が500ms未満であることも確認します。

ライセンスキーはパスワードと同等の共有秘密として扱い、ログ、解析ツール、問い合わせ本文へ平文で記録しないでください。漏洩時は管理画面から再発行します。
