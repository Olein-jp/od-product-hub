=== OD Product Hub ===
Contributors: olein
Tags: stripe, subscriptions, license management, product hub
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

StripeのサブスクリプションとWordPressユーザーを結び、契約者向けサービスのライセンス認証を管理します。

== Description ==

OD Product Hubは、Stripe Checkout、Customer Portal、署名付きWebhook、契約・ライセンス管理、公開契約検証API、運用ログを一元化するWordPressプラグインです。

GPLコードの利用自体を制限せず、有効な契約に付随するアップデート、サポート、契約者向けサービスの提供可否を検証します。

= 主な機能 =

* Stripe CheckoutとCustomer Portal
* 新規・既存WordPressユーザーへの契約同期
* 冪等なWebhook処理と支払い失敗・解約状態の同期
* ライセンスの発行、停止、再開、再発行
* activate / verify / deactivate / product / updates REST API
* ComposerクライアントSDKとWordPress標準アップデート連携
* SHA-256・Ed25519署名検証と短命な一回利用URLによる更新ZIP配布
* 上位HubによるOD Product Hub自身の製品ライセンス認証と署名付き更新
* ダッシュボード、検索可能な運用ログ、ログ保持処理

SDKの導入は packages/client-sdk/README.md、REST契約は docs/API.md、更新配布と署名鍵の運用は docs/UPDATE_DELIVERY.md を参照してください。

== Installation ==

1. 配布ZIPを「プラグインのアップロード」からインストールして有効化します。
2. 「OD Product Hub → 設定」でStripeテストモードのキーとWebhook Secretを設定します。
   販売元から製品ライセンスキーを受け取っている場合は、同じ画面で認証します。
3. recurring Priceを商品管理へ登録します。
4. 購入、完了、キャンセル、マイページの固定ページへ各ショートコードを配置します。
5. 本番切替前に同じStripeモードのキー、Product、Price、Webhook EndpointでE2E試験を行います。

== Frequently Asked Questions ==

= カード番号は保存しますか？ =

保存しません。決済カード情報はStripeが取り扱います。

= 契約終了後にGPLコードは動かなくなりますか？ =

いいえ。入手済みGPLコードの利用を停止するものではありません。契約者向けサービスの提供可否だけを検証します。

== Privacy ==

契約管理と不正防止のため、氏名、メール、契約情報、認証元URL/IP/User-Agentを保存します。Webhook payloadの個人・支払い関連情報はマスクし、運用ログは設定した保持期間後に削除します。サイトのプライバシーポリシーへ利用目的、委託先Stripe、保持期間、問い合わせ先を記載してください。

== Changelog ==

= 0.1.0 =
* Initial MVP release candidate.
