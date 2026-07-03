# 契約者向け自動アップデート配布

この文書はHub運用者向けの正本です。クライアントプラグインへの導入コードは [SDK README](../packages/client-sdk/README.md)、更新確認・ダウンロードのREST契約は [API.md](API.md)、Hub全体の依存境界は [ARCHITECTURE.md](ARCHITECTURE.md) を参照してください。

## 構成と信頼境界

`odph_releases` は商品、SemVer、`stable` / `beta` チャンネル、プラグインファイル、互換条件、リリースノート、秘密ストレージ上のZIPパス、SHA-256、Ed25519署名と公開鍵を保持します。`odph_downloads` はライセンスとリリースへ結び付いた短命・一回利用のダウンロード権と結果を保持します。ZIPはメディアライブラリへ置かず、既定では `wp-content/odph-private-releases` に保存します。本番ではWebルート外を `ODPH_RELEASE_STORAGE_PATH` に指定してください。

公開処理は次の順序です。

1. SodiumでEd25519鍵をオフライン生成し、秘密鍵はSecret Manager等に保存する。公開鍵はクライアントプラグインへ固定する。
2. `ReleaseService::publish()` へZIP、商品ID、バージョン、チャンネル、プラグインファイル、互換条件を渡す。サービスが秘密ストレージへコピーし、SHA-256と署名をDBへ保存する。
3. SDKが現在の `plugin_version` を付けて `POST /od-product-hub/v1/updates/check` を呼ぶ。Hubは商品・ライセンス・契約とパッケージ署名を検証し、公開版がクライアント版より新しい場合だけ5分以内（設定範囲60〜900秒）の一回利用URLを発行する。同一版またはクライアント側が新しい場合はDBへダウンロード権を書き込まず、`success` と `update_available: false` だけを返す。不正形式またはバージョン省略はHTTP 400とし、ダウンロード権を発行しない。
4. ダウンロード時にURL署名、有効期限、未使用状態、リリース署名、保存ZIPのハッシュを検証し、DB上で原子的に権利をclaimしてから配信する。
5. SDKは `upgrader_pre_download` でZIPを取得し、固定済み公開鍵、署名、SHA-256を再検証してWordPress標準Upgraderへ渡す。

## 鍵生成と公開例

秘密鍵をリポジトリ、DB、ログへ保存しないでください。次の値はSecret Managerへ直接登録します。

```php
$keypair = sodium_crypto_sign_keypair();
$private = base64_encode( sodium_crypto_sign_secretkey( $keypair ) );
$public  = base64_encode( sodium_crypto_sign_publickey( $keypair ) );
```

公開操作の正式な入口は `wp odph release` です。GitHub Releaseは「成果物の取得元」に限定し、取得後にHubの秘密ストレージへ取り込んで署名します。GitHubのURLをそのまま契約者へ返さないため、GitHub連携の有無と契約認可を分離できます。

秘密鍵はコマンド引数へ渡しません。`ODPH_RELEASE_PRIVATE_KEY` 環境変数、同名定数、またはSecret Manager連携用の `odph_release_private_key` filterからBase64形式の秘密鍵を解決します。環境変数は定数より優先され、filterは最終値を置き換えられます。秘密鍵は処理中のメモリだけで使用し、DB、標準出力、管理操作ログへ保存しません。

```sh
export ODPH_RELEASE_PRIVATE_KEY="$(secret-manager-read-command)"
wp odph release publish /secure/build/example-plugin-1.3.0.zip \
  --product=example-plugin \
  --version=1.3.0 \
  --channel=stable \
  --plugin-file=example-plugin/example-plugin.php \
  --requires-wp=6.9 \
  --requires-php=8.1 \
  --notes-file=/secure/build/release-notes.txt
unset ODPH_RELEASE_PRIVATE_KEY
```

`publish` は商品、SemVer、`stable` / `beta`、プラグインファイル、WordPress/PHP要件、ZIP内の危険なパスを検証します。同じ商品・版・チャンネルは再登録できず、各チャンネルでは直前に記録した版より新しいSemVerだけを公開できます。署名またはDB登録に失敗した場合はコピー済みZIPを削除し、曖昧な差し替えや孤立ファイルを残しません。

```sh
wp odph release list --product=example-plugin
wp odph release show 42 --format=json
wp odph release withdraw 42
```

`list` / `show` は秘密ストレージのパスと署名本体を表示せず、SHA-256と公開鍵fingerprintを表示します。`withdraw` は再実行可能で、途中障害後も未使用トークンの失効をやり直せます。すべてのコマンド操作は成功・失敗とも `admin_logs` に記録されます。実行主体を残す場合は `--user=<管理者ID>` を指定してください。

## SDK

`Config` の `plugin_file` と `release_public_key` を指定して `WordPress\Updater::register()` を呼ぶと、`update_plugins`、`plugins_api`、標準更新処理へ接続します。ベータ版を受け取る場合だけ `channel` を `beta` にします。Hubレスポンス内の公開鍵が固定鍵と一致しない場合、更新は拒否されます。

## 負荷試験と監視

本番投入前に、想定ピークの2倍で `updates/check` とダウンロードを別々に試験します。目標は更新確認p95 500ms未満、エラー率1%未満、同一トークンの成功回数が必ず1回です。CDNを使う場合も認可前のURLをキャッシュせず、ZIP本体はトークン検証後にのみ配信してください。`api_logs.action=update_check` と `downloads.result` を監視し、403、429、`rejected` の急増を通知します。

WordPressの「ツール → サイトヘルス → ステータス」では、Sodium/Zip、秘密ストレージの書込可否、Webルート外配置、全公開リリースのパッケージ存在・SHA-256・Ed25519署名を確認できます。拡張不足、書込不可、署名不一致は「致命的な問題」として表示されます。Webルート内の保存は動作可能でも本番推奨に満たないため「おすすめの改善」となります。

署名不一致を検出した場合は対象リリースを直ちに `wp odph release withdraw <id>` で停止し、パッケージを隔離してビルド元を調査してください。ストレージの実パス、署名本体、秘密鍵はSite Healthのコピー可能な情報へ出力されません。

## インシデント対応

- URL漏洩: 有効期限を待たず対象download行を `rejected` にし、必要ならライセンスを再発行する。一回利用なので既使用URLは再利用できません。
- ZIP改ざん: `wp odph release withdraw <id>` で対象releaseを即時停止し、ストレージを隔離する。停止時には新規更新権の発行を止め、未使用の既存ダウンロード権も無効化します。新鍵で再署名する前にビルド元とCIを調査します。
- 秘密鍵漏洩: 旧公開鍵を使うリリースを停止し、新しい鍵ペアを生成してクライアントへ安全に公開鍵更新を配布します。鍵ローテーション期間は旧鍵と新鍵を製品バージョン単位で管理します。
- 高負荷: 429と `Retry-After` を維持し、WAF/CDNのIP制限を追加する。契約判定や一回利用claimを省略しません。

DBバックアップにはダウンロード履歴が含まれます。サイトURLとIPの保持期間をプライバシーポリシーに明記し、通常のログ削除方針に合わせて削除してください。
