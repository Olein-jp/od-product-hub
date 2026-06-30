# OD Product Hub

Stripe Checkout / Subscription と WordPress ユーザーを結び、プロダクト、顧客、契約、ライセンスキー、契約検証APIを一元管理する WordPress プラグインです。対象環境は WordPress 7.0、PHP 8.3 以上です。

本プラグインは100% GPL方針のプロダクト販売を想定した契約検証基盤です。GPLコードの利用自体を法的・技術的に制限する目的ではありません。契約終了後も入手済みコードは利用でき、アップデート、サポート、契約者向け資料などのサービス提供可否を検証します。

## 現在の実装

- 独自テーブル（商品、顧客、契約、ライセンス、Webhook/API/管理操作ログ）
- Stripe設定と商品登録の管理画面
- Stripe Checkout、Customer Portal、署名検証済みWebhook
- 運用ダッシュボード、検索・ページング対応ログ、日次ログ保持処理
- Checkout完了時のWordPressユーザー・顧客・契約・ライセンス作成
- `/activate`、`/verify`、`/deactivate`、`/product` REST APIとレート制限
- `[odph_checkout product="example-plugin"]` と `[odph_my_account]`
- 支払い失敗、解約、管理停止を表現できる契約状態モデル

自動アップデート配布とクライアントSDKはMVP外です。

## 開発環境

```bash
npm install
composer install
npm run env:start
```

`wp-env` は WordPress 7.0 / PHP 8.3 を使用し、<http://localhost:8888> で起動します（admin / password）。

```bash
npm test
npm run test:database
npm run test:api
composer lint
composer phpstan
composer test
```

## 初期設定

1. 管理画面の「OD Product Hub → 設定」でStripeキー、Webhook Secret、完了/キャンセルURLを設定します。
2. 商品管理で `prod_...` と recurring `price_...` を登録します。
3. StripeのWebhook URLに `/wp-json/od-product-hub/v1/stripe/webhook` を登録します。
4. 購入ページへ `[odph_checkout product="商品スラッグ"]`、マイページへ `[odph_my_account]` を配置し、設定画面でマイページを指定します。

ローカルWebhook転送例:

```bash
stripe listen --forward-to http://localhost:8888/wp-json/od-product-hub/v1/stripe/webhook
stripe trigger checkout.session.completed
```

## API

`POST /wp-json/od-product-hub/v1/verify`:

```json
{
  "license_key": "ODPH-ABCD-EFGH-JKLM-NPQR",
  "product_slug": "example-plugin",
  "site_url": "https://example.com",
  "plugin_version": "1.0.0",
  "wp_version": "7.0",
  "php_version": "8.3"
}
```

詳細は [docs/API.md](docs/API.md) と [docs/STRIPE_SETUP.md](docs/STRIPE_SETUP.md) を参照してください。利用側は24時間ごとの再検証と、一時的な通信障害に対する72時間の猶予を推奨します。

## 公開リポジトリとプライバシー

Stripe APIキー、Webhook Secret、本番URL、`.env`、`wp-config.php`、DBダンプ、顧客・ライセンス・ログデータをコミットしないでください。本番ではHTTPS、最小権限、バックアップ、秘密情報のローテーション、GitHub Secret Scanningを有効にしてください。

ダッシュボードとログ運用、payloadのマスキング、保持期間、手動削除については [docs/OPERATIONS.md](docs/OPERATIONS.md) を参照してください。

サイトのプライバシーポリシーには、決済をStripeへ委託すること、氏名・メール・契約・認証元URL/IP/User-Agentを契約管理・不正防止・サポート目的で保持すること、保持期間と問い合わせ先を明記してください。

## ライセンス

GPL-2.0-or-later。セキュリティ報告は [SECURITY.md](SECURITY.md) を参照してください。
