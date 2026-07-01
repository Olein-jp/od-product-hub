# MVPリリースチェックリスト

> **履歴資料:** この文書はPhase 2のクライアントSDKとPhase 3の自動更新配布を実装する前の、初回MVPリリース判定を記録したものです。現在の提供機能は [README](../README.md)、[SDK README](../packages/client-sdk/README.md)、[更新配布](UPDATE_DELIVERY.md) を参照してください。

## 自動検証

- [x] WordPress 7.0 / PHP 8.3で全統合テストが実行できる。
- [x] PHPCS、PHPStan、PHPUnit、PHP構文検査が成功する。
- [x] 新規/既存ユーザー、ライセンス発行、メール、Checkout、マイページ、Portal、公開APIを統合テストで検証する。
- [x] 支払い失敗、解約、管理停止、重複Webhookを署名付きfixtureで検証する。
- [x] 1万件ログ、保持期間、Cron、payloadマスクを検証する。
- [x] バージョンがプラグインヘッダー、定数、package.json、readme.txtで一致する。
- [x] 配布ZIPへComposer本番依存を同梱し、開発依存を除外する。
- [x] 同じソースとlockファイルから生成したZIPのSHA-256が一致する。
- [x] 配布ZIPに秘密値、顧客fixture、テスト、CI設定、環境ファイル、開発生成物がないことを検査する。
- [x] 配布ZIPを独立した新規WordPressへ導入し、有効化できる。

## 外部サービスを使うリリース判定

- [ ] [STRIPE_CLI_TEST.md](STRIPE_CLI_TEST.md) に従い、テストモードで新規/既存ユーザーの購入を確認した。
- [ ] 更新、支払い失敗、期間終了時解約、Portal、重複Webhook再送を実Stripeイベントで確認した。
- [ ] GitHubのSecret Scanning、Push protection、Dependabot、Private vulnerability reportingを有効化した。
- [ ] `main` RulesetでPHPCS、PHPStan、PHPUnit、WordPress integrationを必須化した。
- [ ] GitHub上の対象コミットでCI全ジョブが成功した。

## セキュリティ・文書

- [x] [THREAT_MODEL.md](THREAT_MODEL.md)でWebhook、公開API、個人情報、管理操作、CI/配布をレビューした。
- [x] SECURITY、Stripe設定、API、運用、プライバシーポリシー例を更新した。
- [x] 初回MVP判定時点では、後続開発予定だったクライアントSDKと自動更新配布を対象外として整理した（現在は実装済み）。
- [x] コード監査と依存監査で既知の重大・高リスク問題がないことを確認する手順を用意した。

外部サービス項目は、実行日、担当者、Stripe CLI/WordPress/PHPのバージョン、合否だけを記録します。秘密値、顧客情報、イベント本文は記録しません。
