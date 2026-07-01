# クライアントSDK（現行仕様）

SDKは `packages/client-sdk` の独立Composerパッケージとして実装しています。activate/verify/deactivate、24時間キャッシュ、通信障害時だけの72時間猶予、契約者向けサービス判定を提供します。キーの平文は保存せず、キャッシュをキーのフィンガープリントへ結び付けます。

管理画面、手動操作、日次Cronの実装例は [`examples/sample-plugin`](../examples/sample-plugin)、パッケージ分離の判断は [ADR 0001](adr/0001-client-sdk-package.md)、導入・状態・API互換性・GPL方針は [SDK README](../packages/client-sdk/README.md) を参照してください。SDKはStripeやHub DBへ依存しません。

WordPress標準アップデート連携も実装済みです。`Config` にプラグインファイル、配信チャンネル、固定Ed25519公開鍵を設定し、`WordPress\Updater::register()` を呼び出します。Updaterは更新確認API、一回利用URL、SHA-256とEd25519署名の検証をWordPress標準更新へ統合します。Hub側の公開・秘密鍵・ストレージ・監視要件は [UPDATE_DELIVERY.md](UPDATE_DELIVERY.md)、REST契約は [API.md](API.md) を参照してください。
