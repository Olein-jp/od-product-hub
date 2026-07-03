# REST API

Base URLは `/wp-json/od-product-hub/v1`、本文はJSONです。本番環境ではHTTPSが必須です。レスポンスに氏名、メールアドレス、WordPressユーザーID、Stripe Customer/Product/Price/Subscription IDは含まれません。

## エンドポイント

- `POST /activate`: 初回契約検証。成功時に最終検証日時を更新します。
- `POST /verify`: 定期契約検証。成功時に最終検証日時を更新します。
- `POST /deactivate`: サイト側の解除をログへ記録します。ライセンス状態と最終検証日時は変更しません。
- `GET /product?product_slug=...`: active商品の公開情報を返します。
- `POST /updates/check`: 契約と署名済み公開版を検証し、更新がある場合だけ一回利用URLを返します。
- `GET /downloads/{token}`: 更新確認で発行した短命・一回利用トークンを検証し、署名済みZIPを返します。
- 各ルートへの `OPTIONS`: WordPress REST APIが引数のJSON Schemaと許可メソッドを返します。

## POST入力Schema

| 項目 | 必須 | 制約 |
| --- | --- | --- |
| `license_key` | はい | `ODPH-XXXX-XXXX-XXXX-XXXX`、大文字、曖昧文字 `I/O/0/1` なし |
| `product_slug` | はい | 小文字英数字と単語間のハイフン、最大191文字 |
| `site_url` | いいえ | HTTP/HTTPS、最大255文字、URL内のユーザー名・パスワードは禁止 |
| `plugin_version` | いいえ | 数字をドットで区切ったバージョン、最大32文字 |
| `wp_version` | いいえ | 同上 |
| `php_version` | いいえ | 同上 |
| `channel` | 更新確認のみ | `stable` または `beta`。省略時は `stable` |

`updates/check` に限り `plugin_version` は必須です。形式に合わない値、または省略時はHTTP 400となり、ダウンロード権を発行しません。公開版が未登録の場合は正常な「更新なし」としてHTTP 200を返します。公開版のZIPが消失している場合はHTTP 503 `release_package_missing`、SHA-256またはEd25519署名検証に失敗した場合はHTTP 503 `release_package_integrity_failed` を返します。どちらもダウンロード権を発行せず、レスポンスにファイルパス、鍵、内部例外を含めません。

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

署名検証済みの公開版が `plugin_version` より新しい場合だけ、`updates/check` は `update_available: true` と `release` を返し、ダウンロード権を1件発行します。`download_url` は `release` 内にあり、既定で5分以内（設定可能範囲60〜900秒）に期限切れとなり、一度だけ使用できます。

```json
{
  "success": true,
  "update_available": true,
  "release": {
    "version": "1.3.0",
    "plugin_file": "example-plugin/example-plugin.php",
    "channel": "stable",
    "release_notes": "Security and compatibility fixes.",
    "requires_wp": "6.9",
    "requires_php": "8.1",
    "sha256": "64文字のSHA-256ハッシュ",
    "signature": "Base64形式のEd25519署名",
    "public_key": "Base64形式のEd25519公開鍵",
    "download_url": "https://hub.example.com/wp-json/od-product-hub/v1/downloads/ONE_TIME_TOKEN",
    "expires_at": "2026-07-02T12:05:00+00:00"
  }
}
```

クライアントはレスポンス内の `public_key` を単独では信頼せず、プラグインへ固定した公開鍵との一致、ZIPのSHA-256、Ed25519署名を検証します。詳細は [クライアントSDK](../packages/client-sdk/README.md) と [更新配布](UPDATE_DELIVERY.md) を参照してください。

同一版、またはクライアント側が新しい版の場合、ZIPのハッシュ・署名検証とダウンロード権の発行を行わず、次の最小レスポンスを返します。`release` と `download_url` は含みません。

```json
{
  "success": true,
  "update_available": false
}
```

`plugin_version` がバージョン形式に合わない場合は `rest_invalid_param`、省略時は `rest_missing_callback_param` とHTTP 400を返します。公開版がない場合は、ダウンロード権を発行せず上記の最小レスポンスを返します。公開版のパッケージを検証できない場合はHTTP 503と前述の安定した `error_code` を返します。有効な契約を確認できない場合はHTTP 403、`success: false`、契約判定に対応する `error_code` を返します。

## 更新ZIPダウンロード

`GET /downloads/{token}` は成功時に `application/zip` を返します。トークンは更新確認を行ったライセンス、リリース、サイトURLへ結び付けられ、期限内でも一度しか成功しません。

- 不正、期限切れ、使用済みトークン: HTTP 403 `odph_download_invalid`
- 同時利用などclaim後の再利用: HTTP 403 `odph_download_replayed`
- ZIP欠落、SHA-256またはEd25519署名不一致: HTTP 410 `odph_package_invalid`
- ダウンロード試行のレート制限超過: HTTP 429 `odph_rate_limited`

## 契約状態とerror_code

`activate`、`verify`、`deactivate` の業務上の契約エラーはHTTP 200、`success: false` で返します。`updates/check` は有効な契約を必須とするため、同じ契約判定エラーをHTTP 403で返します。

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
- `403`: 本番環境でHTTPSを使用していない、更新確認で契約が無効、またはダウンロードトークンが無効
- `404`: 商品が存在しない、またはactiveではない
- `410`: 更新ZIPが存在しない、または完全性を検証できない
- `429`: レート制限超過

## レート制限

レート制限は「解決したクライアントIP + RESTルート」を1分単位で集計します。bucketはSHA-256化して専用DBテーブルへ保存し、MySQLのatomic upsertで複数PHPプロセスからの同時更新を直列化します。Transientのread-modify-writeやRedis等の永続オブジェクトキャッシュには依存しないため、キャッシュ構成にかかわらず同じ制限が適用されます。期限切れカウンターは日次cleanupで削除されます。応答には `X-RateLimit-Limit`、`X-RateLimit-Remaining`、`X-RateLimit-Reset` を含み、HTTP 429では `Retry-After` も返します。DB更新に失敗した場合は安全側へ倒して429とし、制限超過リクエストはAPIログへ保存しないため、大量リクエストでログが無制限に増えません。

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
