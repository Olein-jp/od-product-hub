# クライアントSDK

Phase 2のSDKは `packages/client-sdk` の独立Composerパッケージとして実装しています。activate/verify/deactivate、24時間キャッシュ、通信障害時だけの72時間猶予、契約者向けサービス判定を提供します。キーの平文は保存せず、キャッシュをキーのフィンガープリントへ結び付けます。

管理画面、手動操作、日次Cronの実装例は `examples/sample-plugin`、パッケージ分離の判断は `docs/adr/0001-client-sdk-package.md`、導入・状態・API互換性・GPL方針は `packages/client-sdk/README.md` を参照してください。SDKはStripeやHub DBへ依存しません。Phase 3ではWordPress更新APIと署名付き一時URLを別ドメインとして追加します。
