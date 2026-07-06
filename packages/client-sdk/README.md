# OD Product Hub Client SDK

OD Product Hub の公開 REST API を利用して、WordPress プラグインの契約者向けサービスを判定する Composer パッケージです。PHP 8.1 以上、WordPress 6.9 以上を対象にします。

```bash
composer require olein-design/od-product-hub-client
```

```php
use OD_Product_Hub_Client\Client;
use OD_Product_Hub_Client\Config;
use OD_Product_Hub_Client\WordPress\HttpTransport;
use OD_Product_Hub_Client\WordPress\OptionStateStore;

$client = new Client(
	new Config( 'https://hub.example.com', 'my-plugin', '1.0.0', home_url( '/' ) ),
	new HttpTransport(),
	new OptionStateStore( 'my_plugin_contract_state' )
);

$result = $client->verify( get_option( 'my_plugin_license_key', '' ) );
if ( $result->is_service_available() ) {
	// 契約者向けクラウドサービス、サポート導線、追加コンテンツ等を提供する。
}
```

## 状態とキャッシュ

- `active`: Hub が有効と判定。契約者向けサービスを利用できます。
- `inactive` / `expired` / `cancelled` / `suspended`: Hub が無効と判定。直ちに契約者向けサービスだけを停止します。
- `grace`: Hub への通信だけが失敗し、最後の `active` 検証から72時間以内。サービスを一時継続します。
- `unavailable`: 通信失敗か不正レスポンスで、有効な猶予もありません。

通常の `verify()` は24時間キャッシュを使います。管理画面の保存直後や Cron では `verify( $key, true )` で再検証できます。業務上の無効レスポンスは通信障害ではないため、72時間猶予を適用しません。

## API互換性方針

SemVer を採用します。公開クラス、メソッド、レスポンスの既存フィールドは同一メジャー内で維持します。フィールド追加は後方互換、削除・型変更・状態の意味変更はメジャー更新です。未知のレスポンスフィールドは無視し、未知または不正な状態は有効とみなしません。

## セキュリティとGPL

ライセンスキーをログへ出力せず、HTTPS の Hub URL を使用してください。このSDKはGPLコードのローカル実行を妨げるDRMではありません。契約状態によって停止してよいのは、契約者向けの外部サービス、更新配布、サポート等です。プラグイン本体のGPLコード、既存データ、管理画面へのアクセスを暗号化・削除・実行不能化する用途には使用しません。

キーは `ABCD-EFGH-JKLM-NPQR`、任意接頭辞付きの `MYAPP-ABCD-EFGH-JKLM-NPQR`、既存互換の `ODPH-ABCD-EFGH-JKLM-NPQR` を利用できます。SDKは接頭辞を固定せず、キー全体をtrim・大文字化して送信します。接頭辞は秘密情報ではなく、認証強度を高めるものではありません。

## テスト

```bash
composer install
composer test
```

`examples/sample-plugin` はライセンス入力、手動操作、状態表示、日次Cron再検証を含む最小サンプルです。

## WordPress標準アップデート連携

更新配布を使う場合は、プラグインの相対パスと、配布者から別経路で受け取ったEd25519公開鍵を `Config` に固定します。Hubが返した公開鍵をそのまま信頼しません。

```php
$config = new Config(
	'https://hub.example.com',
	'my-plugin',
	'1.0.0',
	home_url( '/' ),
	86400,
	259200,
	plugin_basename( __FILE__ ),
	'stable',
	'MY_BASE64_ED25519_PUBLIC_KEY'
);
( new OD_Product_Hub_Client\WordPress\Updater( $config, get_option( 'my_plugin_license_key', '' ) ) )->register();
```

UpdaterはWordPressの更新一覧とプラグイン詳細へリリース情報を追加し、標準更新時のZIPをSHA-256と固定公開鍵によるEd25519署名で検証します。期限切れ・再利用済みURL、無効契約、改ざんZIPは更新に進みません。
