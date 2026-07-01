# Stripe CLI E2E試験

この試験は必ずStripeのテストモードとローカルWordPressで行います。本番キー、実顧客、実決済は使用しません。Stripe CLIの認証情報やWebhook Secretをコマンド出力、Issue、CIログへ貼り付けないでください。

## 事前準備

1. Stripe CLIを公式手順でインストールし、テスト用アカウントへログインする。
2. WordPressのStripe設定を同じテストモードのキーへ揃える。
3. recurring Priceを商品へ登録し、購入・完了・キャンセル・マイページを設定する。
4. 別ターミナルで次を実行し、表示されたSigning secretをWordPressへ保存する。

```sh
stripe listen --forward-to http://localhost:8888/wp-json/od-product-hub/v1/stripe/webhook
```

プロトコル上の6イベントをまとめて送る場合は、テストモードであることを確認してから実行します。

```sh
ODPH_STRIPE_TEST_MODE_CONFIRMED=yes scripts/stripe-cli-smoke.sh
```

## アプリケーション状態E2E

| 操作 | 期待結果 |
| --- | --- |
| 未登録メールでCheckoutを完了 | WPユーザー、顧客、契約、有効ライセンスが1件ずつ作成され、パスワード設定メールが送信される |
| 既存WPユーザーのメールで購入 | 既存ユーザーへ顧客が関連付き、ユーザーは重複しない |
| `customer.subscription.updated` | 期間、状態、解約予約が同期される |
| テストカード `4000000000000341` で請求失敗 | `payment_failed_at` が入り、契約検証APIは `payment_failed` を返す |
| Customer Portalで期間終了時解約 | 期間中はactive、契約終了イベント後はcancelledになる |
| マイページからPortalを開く | ログインユーザー本人のStripe CustomerだけでSessionが作られる |
| `/activate`、`/verify`、`/deactivate` | API文書どおりの状態、HTTPコード、マスク済みキーを返す |

## 重複Webhook

Stripe Dashboardで対象Webhook Endpoint IDを確認し、同じEvent IDを再送します。

```sh
stripe events resend evt_... --webhook-endpoint=we_...
```

再送後もユーザー、契約、ライセンスは増えず、Webhookログの重複回数だけが増えることを確認します。イベントID、Endpoint IDはテスト用でも公開Issueへ貼り付けません。

## 証跡

秘密値や個人情報を含めず、実施日時、WordPress/PHP/Stripe CLIのバージョン、各項目の合否、Webhookログの結果分類だけを[MVP_RELEASE_CHECKLIST.md](MVP_RELEASE_CHECKLIST.md)へ記録します。
