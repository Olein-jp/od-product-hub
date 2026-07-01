# ADR 0001: 契約検証SDKを独立Composerパッケージとして同居させる

- 状態: 採用
- 日付: 2026-07-01

## 決定

SDKを `packages/client-sdk` に独立した `composer.json`、名前空間、テストを持つパッケージとして配置します。安定化まではHubリポジトリ内でAPI変更と結合検証を同時に行い、公開時に同じディレクトリをパッケージ分割または別リポジトリへ移せる境界を維持します。

SDKはHubのDB、Stripe SDK、管理クラスへ依存しません。HTTP Transport、状態Store、Clockをinterface化し、WordPressアダプターだけを同梱します。

## 理由

別リポジトリを先に作ると、Phase 2初期のREST契約変更を複数PRで同期する負担が増えます。一方、HubプラグインのComposerパッケージへ直接含めると、クライアント製品へ不要なStripe依存を持ち込みます。独立パッケージのmonorepo配置なら、単体配布可能性と開発時の追従性を両立できます。

## 境界

- SDKの公開APIはSemVerで管理する。
- Hub REST APIの既存フィールドを破壊的に変更しない。
- SDKは契約者向けサービス判定だけを提供し、GPLコードの実行制御やデータ破壊に使わない。
