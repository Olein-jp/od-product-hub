# セキュリティポリシー

サポート対象は最新リリースです。脆弱性は公開Issueへ書かず、GitHub Security AdvisoryのPrivate vulnerability reportingから報告してください。報告には再現手順、影響範囲、対象バージョンを含め、Stripeキー、Webhook Secret、ライセンスキー、顧客情報、未加工ログを添付しないでください。

StripeキーまたはWebhook Secretが漏洩した場合はStripe Dashboardで直ちにローテーションし、WebhookログとAPIログを調査してください。顧客データ漏洩が疑われる場合はサイトを保全し、法令と組織のインシデント対応手順に従って関係者へ通知してください。

公開前には `composer audit`、`npm audit --omit=dev`、配布ZIP検査、全CIを実行します。管理者はWordPress/GitHub/StripeへMFAと最小権限を適用し、GitHubのSecret Scanning、Push protection、Dependabot、Branch rulesetを有効化してください。詳細は [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) と [docs/REPOSITORY_SECURITY.md](docs/REPOSITORY_SECURITY.md) を参照してください。
