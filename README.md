# OD Product Hub

Stripe Checkout / Subscription と WordPress ユーザーを結び、プロダクト、顧客、契約、ライセンスキー、契約検証APIを一元管理する WordPress プラグインです。対象環境は WordPress 7.0、PHP 8.3 以上です。

本プラグインは100% GPL方針のプロダクト販売を想定した契約検証基盤です。GPLコードの利用自体を法的・技術的に制限する目的ではありません。契約終了後も入手済みコードは利用でき、アップデート、サポート、契約者向け資料などのサービス提供可否を検証します。

## 現在の実装

- 独自テーブル（商品、顧客、契約、ライセンス、Webhook/API/管理操作ログ）
- Stripe設定と商品登録の管理画面
- Stripe Checkout、Customer Portal、署名検証済みWebhook
- 運用ダッシュボード、検索・ページング対応ログ、日次ログ保持処理
- Checkout完了時のWordPressユーザー・顧客・契約・ライセンス作成
- `/activate`、`/verify`、`/deactivate`、`/product`、`/updates/check`、`/downloads/{token}` REST APIとレート制限
- ComposerクライアントSDK、24時間キャッシュ、通信障害時の72時間猶予
- WordPress標準更新と連携するUpdater、署名済みZIP、一回利用の更新URL
- `[odph_checkout product="example-plugin"]` と `[odph_my_account]`
- 支払い失敗、解約、管理停止を表現できる契約状態モデル

## ドキュメント

- Hub APIを利用するプラグイン開発者: [クライアントSDK導入ガイド](packages/client-sdk/README.md)
- RESTルートとレスポンス契約: [REST API](docs/API.md)
- 自動更新、署名鍵、秘密ストレージの運用: [更新配布](docs/UPDATE_DELIVERY.md)
- Hub本体の構成と依存境界: [アーキテクチャ](docs/ARCHITECTURE.md)
- Hub配布ZIPの生成と新規環境試験: [リリース手順](docs/RELEASE.md)
- Stripe設定と本番運用: [Stripe設定](docs/STRIPE_SETUP.md) / [運用](docs/OPERATIONS.md)

[MVPリリースチェックリスト](docs/MVP_RELEASE_CHECKLIST.md) は初回MVP判定時点の履歴資料です。現在の提供機能一覧としては使用しません。

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
npm run test:integration
npm run test:i18n
npm run test:release
composer lint
composer phpstan
composer test
```

## 対応言語と翻訳

プラグインの原文は英語で、日本語翻訳を同梱しています。WordPressのサイト言語に応じて管理画面、購入・契約画面、通知、既定のメールテンプレートが切り替わります。商品名や商品説明、保存済みのカスタムメールテンプレートなど、管理者が入力したデータは自動翻訳されません。

翻訳を更新する場合は `npm run build:i18n` で `languages/od-product-hub.pot` を再生成し、`languages/od-product-hub-ja.po` を更新してから、次のコマンドでMOファイルを生成します。

```bash
msgfmt --check --check-format \
  --output-file=languages/od-product-hub-ja.mo \
  languages/od-product-hub-ja.po
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

詳細は [docs/API.md](docs/API.md) と [docs/STRIPE_SETUP.md](docs/STRIPE_SETUP.md) を参照してください。利用側では [クライアントSDK](packages/client-sdk/README.md) が24時間キャッシュと、一時的な通信障害に対する72時間の猶予を実装しています。

実Stripeイベントの最終確認は、テストモード限定の [docs/STRIPE_CLI_TEST.md](docs/STRIPE_CLI_TEST.md) に従ってください。

## 公開リポジトリとプライバシー

Stripe APIキー、Webhook Secret、本番URL、`.env`、`wp-config.php`、DBダンプ、顧客・ライセンス・ログデータをコミットしないでください。本番ではHTTPS、最小権限、バックアップ、秘密情報のローテーション、GitHub Secret Scanningを有効にしてください。

ダッシュボードとログ運用、payloadのマスキング、保持期間、手動削除については [docs/OPERATIONS.md](docs/OPERATIONS.md) を参照してください。

サイトのプライバシーポリシーには、決済をStripeへ委託すること、氏名・メール・契約・認証元URL/IP/User-Agentを契約管理・不正防止・サポート目的で保持すること、保持期間と問い合わせ先を明記してください。記載例は [docs/PRIVACY_POLICY_EXAMPLE.md](docs/PRIVACY_POLICY_EXAMPLE.md) です。

脅威と対策は [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md)、GitHub側のSecret ScanningとBranch protection手順は [docs/REPOSITORY_SECURITY.md](docs/REPOSITORY_SECURITY.md) にまとめています。

## ライセンス

GPL-2.0-or-later。セキュリティ報告は [SECURITY.md](SECURITY.md) を参照してください。
