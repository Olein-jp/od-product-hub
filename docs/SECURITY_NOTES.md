# セキュリティノート

公開APIは管理者認証を受け取らず、ライセンスキーを共有秘密として最小情報のみ返します。管理画面は `manage_options`、変更操作はnonceも必須です。WebhookはStripe署名を必須とし、受信payloadは管理者だけが扱います。ログ表示を拡張する場合はメール、住所、カード関連データをマスクしてください。

信頼するプロキシを設定しない限り `X-Forwarded-For` は使用しません。Checkout/Portalは現在のユーザーと同一サイトの戻り先を検証し、Stripe SDKが返すURLだけへ遷移します。全体の信頼境界、残余リスク、CI/配布の対策は [THREAT_MODEL.md](THREAT_MODEL.md) を参照してください。
