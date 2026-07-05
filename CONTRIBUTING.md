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

## PHP互換性とCIマトリクス

Hub本体のComposer要件はPHP 8.3以上です。GitHub Actionsの `Hub quality` は正式対応するPHP 8.3、8.4、8.5でPHPCS・PHPStan・PHPUnitを実行します。Client SDKは独立した公開パッケージとしてPHP 8.1以上を維持し、`Client SDK quality` でPHP 8.1〜8.5を検証します。SDKの開発用lockはPHP 8.3基準であるため、PHP 8.1 jobだけは `composer.json` から互換バージョン（PHPUnit 10系を含む）を解決し、それ以外はlockを使います。要件の下限を変更する場合は、`composer.json`、SDKの `composer.json`、README類、CIマトリクスを同じPull Requestで同期してください。

WordPress統合テストは実行時間とのバランスを取り、最低対応のPHP 8.3と正式対応範囲の最新版PHP 8.5を対象にします。`WP_ENV_PHP_VERSION` によってホスト側だけでなくDocker内のWordPress/CLIも同じPHP版へ切り替えます。リリース成果物の生成・再現性・新規インストール検証は基準環境のPHP 8.3へ固定し、PHP版の違いでZIP内容が変化しないようにします。

正式リリース前のPHPを試験導入する場合は、matrixの各要素に `experimental` フラグを持たせ、jobへ `continue-on-error: ${{ matrix.experimental }}` を設定します。experimental jobは観測目的に限り、PHP 8.3と正式対応最新版の必須jobを置き換えてはいけません。正式対応へ昇格する際は `experimental: false` にして、Composer依存、全品質ゲート、WordPress統合テストが成功することを確認します。
