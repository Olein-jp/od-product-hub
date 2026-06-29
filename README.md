# OD Product Hub

OD Product Hub WordPress プラグインの開発リポジトリです。

## 必要な環境

- Node.js 20.18 以上
- npm
- Docker

## セットアップ

```bash
npm install
npm run env:start
```

起動後、<http://localhost:8888> にアクセスできます。

- ユーザー名: `admin`
- パスワード: `password`

プラグインは自動的にマウント・有効化されます。

## コマンド

```bash
npm run env:start    # WordPress 開発環境を起動
npm run env:stop     # WordPress 開発環境を停止
npm run env:destroy  # WordPress 開発環境とデータを削除
npm run env:logs     # ログを表示
npm run env:cli -- plugin list  # WP-CLI を実行
npm test             # 最低限の構文チェック
```

`.wp-env.json` の `core` と `phpVersion` は `null` にしてあり、`wp-env` が提供する最新の安定版 WordPress と、その既定 PHP バージョンを利用します。
