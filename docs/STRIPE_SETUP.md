# Stripe設定

recurring Priceを持つProductを作成し、IDを商品管理へ登録します。Webhookでは `checkout.session.completed`、`customer.subscription.created/updated/deleted`、`invoice.paid/payment_failed` を選択し、表示されたSigning secretを設定します。Customer Portalで支払い方法変更・解約を有効にしてください。

テストモードと本番モードのキー・Product・Price・Webhook Secretは別物です。本番切替時はすべてを同じモードで揃え、HTTPSのWebhook URLでテストイベントと実購入を確認します。
