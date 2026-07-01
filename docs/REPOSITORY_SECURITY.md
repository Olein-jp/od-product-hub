# GitHubリポジトリ保護設定

リポジトリ管理者がGitHubのSettingsで次を設定します。利用プランやOrganizationポリシーで項目名が異なる場合があります。

## Code security and analysis

- Secret scanningとPush protectionを有効化する。
- Private vulnerability reportingを有効化する。
- Dependabot alerts、security updates、version updatesを有効化する。
- `.github/dependabot.yml` のComposer、npm、GitHub Actions週次更新を維持する。
- 漏洩検知時はGit履歴の修正だけで済ませず、Stripe/GitHub側で必ず秘密値を失効・ローテーションする。

## Branch protection / Ruleset

`main` を対象に次を必須化します。

- Pull request経由の変更と1名以上の承認。
- 新しいコミットで承認を取り消す。
- PHPCS、PHPStan、PHPUnit、WordPress integrationの成功。
- ブランチを最新状態にしてからマージ。
- Force pushと削除を禁止。
- 管理者にもRulesetを適用。

Release package workflowはタグまたは手動実行だけに限定し、`contents: read` でArtifactを生成します。GitHub Releaseへ公開する操作はArtifactの検証後に人が行います。
