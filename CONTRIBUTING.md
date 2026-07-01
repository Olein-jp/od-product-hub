# コントリビューション

Issueで目的と再現手順を共有し、Pull Requestは小さく保ってください。秘密情報・顧客データ・DBダンプをコミットしないでください。コードはGPL-2.0-or-laterとして提供されます。

## ローカル品質ゲート

クリーンcheckoutを含め、リポジトリのルートで次の単一コマンドを実行してください。

```sh
composer quality
```

このコマンドはルートと `packages/client-sdk` のロックファイルに従って依存関係を導入し、Hub本体のPHPCS・PHPStan・PHPUnit、SDK全体のPHP構文検査、SDK配布コード（`src/`）のPHPCS・PHPStan、SDKのPHPUnit、サンプルプラグインのComposer autoload確認を順番に実行します。WordPress実環境の統合テストは別途 `npm run env:start && npm run test:integration` で実行します。

SDKだけを確認する場合は次を使用できます。

```sh
composer install --working-dir=packages/client-sdk
composer quality --working-dir=packages/client-sdk
```

`packages/client-sdk/composer.lock` はSDKのCI・開発用ツールチェーンを再現するために追跡します。公開ライブラリ利用者の依存解決は引き続き `packages/client-sdk/composer.json` の制約に従います。
