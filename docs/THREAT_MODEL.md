# 脅威モデル

## 保護対象と信頼境界

保護対象はStripe APIキー・Webhook Secret、顧客の氏名/メール/契約情報、ライセンスキー、管理操作、Webhook/APIログです。信頼境界は、公開REST API、StripeからのWebhook、WordPress管理画面、購入/Portalへの外部リダイレクト、DB、メール、GitHub Actionsと配布ZIPです。

| 境界 | 主な脅威 | 実装済み対策 | 残余リスクと運用 |
| --- | --- | --- | --- |
| Stripe Webhook | 署名偽造、リプレイ、並行重複、巨大/機密payload | Stripe署名検証、イベントID一意制約、原子的claim、結果分類、保存前/表示時マスク | Secret漏洩時はローテーション。ログ保持期間とアクセス権を維持する |
| 公開API | キー総当たり、入力肥大、IP偽装、情報列挙 | 厳格Schema、キー形式、最小レスポンス、HTTPS強制、レート制限、信頼プロキシallowlist、業務エラーの均一化 | 分散攻撃はWAF/CDNも併用。ライセンスキー漏洩時は再発行する |
| 管理画面 | CSRF、権限昇格、XSS、秘密値の再表示 | `manage_options`、変更時nonce、入力サニタイズ、出力エスケープ、秘密設定を空欄表示 | 管理者アカウントへMFA、最小人数、監査ログ確認を適用する |
| 個人情報 | 過剰保存、ログ漏洩、保持超過 | API応答からPII/Stripe ID除外、payloadマスク、ハッシュ化したメールログ、保持期間削除 | DB/バックアップの暗号化、法令に沿う保持期間と削除手順が必要 |
| Checkout/Portal | 任意URL、他人のCustomer利用、外部誘導 | 同一サイトの戻り先検証、現在ユーザーに同期したCustomerのみ使用、Stripe SDKが返すURLだけへ遷移 | Stripeアカウント侵害に備えMFAと権限分離を行う |
| DB/ライセンス | SQL injection、状態競合、管理停止の上書き | allowlist付きRepository、prepared SQL、トランザクション、一意制約、suspended保護 | DBユーザーを最小権限にし、バックアップ復元試験を行う |
| CI/配布 | Secret混入、依存改ざん、開発物同梱、版ずれ | lockファイル、読み取り専用Actions権限、Dependabot、ZIP内容/秘密値/本番依存/版同期検査 | Branch protection、Secret Scanning、private vulnerability reportingをGitHubで有効化する |

## レビュー結論

既知の重大・高リスク問題は、自動テストとコード監査の範囲では確認されていません。外部サービス設定、管理者アカウント、ホスティング、バックアップ、本番Stripeアカウントはプラグイン外の信頼領域であり、[REPOSITORY_SECURITY.md](REPOSITORY_SECURITY.md)と[SECURITY.md](../SECURITY.md)の運用が必要です。
