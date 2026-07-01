# リリース手順

## リリース候補の生成

```sh
npm ci
composer install
npm run test:version
npm run build:release
```

`dist/od-product-hub-<version>.zip` が生成されます。生成時にはComposerの本番依存だけを再構築し、PHP構文、必須ファイル、バージョン同期、秘密値らしい文字列、開発依存・テスト・環境ファイルの混入を検査します。ファイル時刻を固定しているため、同じソースとlockファイルから同一内容を再生成できます。

## 新規WordPressへの導入試験

```sh
npm run test:release
npm run test:release:reproducible
```

生成ZIPをテストコンテナ内の独立したWordPressへ `wp plugin install --activate` で導入し、WordPress 7.0、PHP 8.3以上、プラグインバージョン、有効化時のデバッグログを検証します。通常の開発サイトとは別のテーブル接頭辞を使用します。再現性テストは同じソースから2回生成したZIPのSHA-256が一致することを確認します。

## 公開前確認

1. [MVP_RELEASE_CHECKLIST.md](MVP_RELEASE_CHECKLIST.md) を埋める。
2. `composer audit` と `npm audit --omit=dev` を実行する。
3. GitHub ActionsのPHPCS、PHPStan、PHPUnit、WordPress integrationが成功していることを確認する。
4. [STRIPE_CLI_TEST.md](STRIPE_CLI_TEST.md) のテストモード試験を実施する。
5. `v<version>` タグを作成する。Release package workflowが同じZIPをArtifactとして生成する。

リリースZIP、DBダンプ、ログ、StripeキーをGitへ追加しないでください。
