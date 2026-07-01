# Stripe設定

recurring Priceを持つProductを作成し、IDを商品管理へ登録します。Webhookでは `checkout.session.completed`、`customer.subscription.created/updated/deleted`、`invoice.paid/payment_failed` を選択し、表示されたSigning secretを設定します。Customer Portalで支払い方法変更・解約を有効にしてください。

購入ページには `[odph_checkout product="商品スラッグ"]`、購入完了ページには `[odph_checkout_success]` を配置します。キャンセルページには `[odph_checkout_cancel return_url="https://example.com/purchase/"]` を配置し、購入ページの同一サイトURLを指定してください。設定画面の購入完了URL・キャンセルURLはこれらの固定ページへ向けます。

マイページには `[odph_my_account]` を配置し、その固定ページを設定画面のマイページに指定します。Customer Portalを有効化すると、ログイン中のWordPressユーザーに同期されたStripe Customerだけを使ってPortal Sessionを作成します。Customer未同期時やPortal無効時はボタンの代わりに案内が表示されます。

テストモードと本番モードのキー・Product・Price・Webhook Secretは別物です。本番切替時はすべてを同じモードで揃え、HTTPSのWebhook URLでテストイベントと実購入を確認します。

本番Secretをローカルのシェル履歴、Issue、CIログ、`.env`、DBダンプへ記録しないでください。管理画面は保存済みSecretを再表示しません。漏洩時はStripe Dashboardで即時ローテーションし、古いSecretを失効させます。

リリース前の購入、契約更新、支払い失敗、解約、重複Webhookの確認手順は [STRIPE_CLI_TEST.md](STRIPE_CLI_TEST.md) を参照してください。
