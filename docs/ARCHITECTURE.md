# アーキテクチャ

WordPress Options APIは秘密設定、`wp_odph_*` 独自テーブルは検索・同期対象データに使います。Stripe Webhookを契約状態の正とし、イベントIDの一意制約で冪等性を確保します。REST APIは保存済み状態だけを参照して高速応答し、Stripeへ同期問い合わせしません。

依存方向は Admin / Frontend / API / Webhook → Service / Repository → WordPress DBです。Phase 2のSDKは公開REST契約だけに依存し、Phase 3のリリース配信を別ドメインとして追加できます。
