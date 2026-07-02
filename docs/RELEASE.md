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

## 契約者向け更新として公開

Hub本体のリリース手順と、Hubから販売プラグインを配信する操作は別です。契約者向け更新ZIPの公開・確認・緊急停止には次を使います。

```sh
export ODPH_RELEASE_PRIVATE_KEY="$(secret-manager-read-command)"
wp odph release publish /secure/build/example-plugin-1.3.0.zip \
  --product=example-plugin \
  --version=1.3.0 \
  --plugin-file=example-plugin/example-plugin.php \
  --requires-wp=6.9 \
  --requires-php=8.1 \
  --notes-file=/secure/build/release-notes.txt
unset ODPH_RELEASE_PRIVATE_KEY

wp odph release list --product=example-plugin
wp odph release show 42 --format=json
wp odph release withdraw 42
```

秘密鍵をコマンド引数、シェル履歴、ログへ直接書かないでください。公開鍵をローテーションする場合は新しい版を新鍵で公開し、クライアントプラグインへ対応する公開鍵を安全に固定してから旧リリースを停止します。詳しい信頼境界とインシデント対応は [UPDATE_DELIVERY.md](UPDATE_DELIVERY.md) を参照してください。
