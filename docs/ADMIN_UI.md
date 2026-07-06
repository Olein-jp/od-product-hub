# 管理画面UI基盤

## 目的と判断基準

OD Product Hubの管理画面は、WordPress 7.0の標準管理画面に自然に馴染み、コア側の配色やアクセシビリティ改善を継承できることを優先します。Issue #41の実装時点ではWPDS専用ドキュメント接続を利用できなかったため、WordPress標準クラス、管理画面CSS変数、Dashicons、Settings APIを安定した基準としました。WPDSを再確認できる環境では、この対応表を先に更新してから新しい独自パターンを追加してください。

全面React化は行いません。現行のサーバー描画、URL、nonce、capability、Settings APIの契約を保ち、対話性が標準HTMLで不足する場合だけWordPress同梱APIを追加します。

## UIインベントリ

| 画面 | 現行要素 | 共通化・後続方針 |
|---|---|---|
| Dashboard | 見出し、指標カード、Webhook/API表、空行 | `page_header`、`card`、`section`、`empty_state`。情報設計は本書のDashboard節 |
| Products | 見出し、検索、編集フォーム、一覧、状態、ページング | `page_header`、`section`、`status_badge`、`empty_state`。一覧UXは #44 |
| Licenses | 検索、一覧、詳細、状態、操作群、ログ | 共通状態・操作群へ段階移行。詳細は #44 |
| Customers | タブ、検索、顧客/契約一覧、状態 | 共通状態・空状態へ段階移行。詳細は #44 |
| Logs | タブ、検索、一覧、詳細、再試行、削除 | 共通通知・状態・操作群へ段階移行。詳細は #44 |
| Settings | 長いフォーム、通知、ライセンス、Stripeテスト | 共通通知・セクションを利用。情報設計は #45 |
| Checkout / Success / Cancel | 商品、送信、結果通知、戻り導線 | 管理画面用DOMは流用せず、原則とトークンだけ参照。詳細は #42 |
| My Account | 契約カード、状態、コピー、請求管理 | 管理画面用DOMは流用せず、原則とトークンだけ参照。詳細は #42 |

## 共通プリミティブ契約

`OD_Product_Hub\Admin\AdminUi` は文字列を受け取り、出力時にエスケープ済みHTMLを返します。呼び出し側は返り値を再エスケープせず、静的解析で安全性を確認します。

- `page_header`: ページごとに1つの `h1` と任意の簡潔な説明。
- `section_start` / `section_end`: `h2` から始まるまとまり。見た目だけのグループには使用しません。
- `card`: 指標と詳細画面へのリンク。カード全体がリンクの場合も可視フォーカスを必須とします。
- `status_badge`: `neutral / success / warning / error / info` のみ。色に加えてドットとテキストで状態を示します。
- `notice`: WordPressの `notice-*` を利用し、エラーは `alert`、その他は `status` とします。
- `empty_state`: 0件の理由と次の行動を表します。テーブル内では適切な `colspan` を持つセルに配置します。
- `action_group`: 関連操作のグループ。主操作は最大1つにし、破壊的操作を主操作にしません。

## CSSとアクセシビリティ

- WordPressの `--wp-admin-theme-color` を優先し、未提供環境向けのフォールバックを持ちます。
- 間隔は4px基準の `--odph-space-*`、境界・文字・状態色は役割別トークンを使います。
- 通常のリンクやフォームにはWordPress標準クラスを優先します。独自CSSは配置、共通プリミティブ、製品固有表示に限定します。
- `:focus-visible` を消さず、強制カラーモードでも輪郭を残します。色だけで意味を伝えません。
- DOM順を視覚順と一致させ、RTLで左右方向に意味を持たせません。
- 320 CSS px相当と200%ズームで、操作や本文が失われない流動レイアウトにします。
- 不要な動きを追加せず、追加する場合は `prefers-reduced-motion` を尊重します。

## 依存と配布

現段階の基盤はPHPとCSSだけで、Gutenbergパッケージや追加npm依存を出荷しません。DashiconsとWordPress標準管理画面CSSはコア同梱を利用します。将来 `@wordpress/components` や `wp.a11y` を使う場合は、対象WordPressバージョンでのハンドル、翻訳、RTL、リリースZIP内容を確認し、依存を明示してenqueueしてください。

## Dashboardの情報設計

Dashboardは、DOM順と視覚順を「要対応」「主要指標」「最近の状態」「クイックアクション」に統一します。要対応はSite Healthの診断結果を再実装せず、criticalとrecommendedの要約および関連画面へのリンクだけを表示します。完全な診断と復旧手順はWordPress標準の「ツール → サイトヘルス」を正とします。

管理メニューのサブメニュー名とページの`h1`は「Dashboard」で一致させます。メニュー順は作業の流れに合わせてDashboard、Products、Customers and subscriptions、Licenses、Logs、Settingsとします。Dashboardには表示設定の対象がないためScreen Optionsは追加せず、画面の読み方とSite Healthとの境界をWordPress標準Helpタブで説明します。
