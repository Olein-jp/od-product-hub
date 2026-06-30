<?php
/**
 * Editable plain-text email templates with strict placeholders.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Email;

final class Templates {
	/** @var array<string, mixed> */
	private array $settings;

	/** @param null|array<string, mixed> $settings */
	public function __construct( ?array $settings = null ) {
		$this->settings = $settings ?? (array) get_option( 'odph_settings', array() );
	}

	/** @return array<string, array{label: string, subject: string, body: string, placeholders: list<string>}> */
	public static function definitions(): array {
		return array(
			'purchase_completed' => array(
				'label'        => '購入完了メール',
				'subject'      => '【{site_name}】ご購入ありがとうございます',
				'body'         => "契約手続きが完了しました。\nライセンスキー: {license_key}\n契約情報: {account_url}",
				'placeholders' => array( 'site_name', 'license_key', 'account_url' ),
			),
			'new_user'           => array(
				'label'        => '新規ユーザーのパスワード設定メール',
				'subject'      => '【{site_name}】パスワードを設定してください',
				'body'         => "アカウントを作成しました。\nユーザー名: {user_login}\n次のURLからパスワードを設定してください。\n{password_url}",
				'placeholders' => array( 'site_name', 'user_login', 'password_url' ),
			),
			'payment_failed'     => array(
				'label'        => '支払い失敗メール',
				'subject'      => '【{site_name}】お支払い方法をご確認ください',
				'body'         => "お支払いを確認できませんでした。\n契約情報ページからStripe Customer Portalを開き、お支払い方法をご確認ください。\n{account_url}",
				'placeholders' => array( 'site_name', 'account_url' ),
			),
			'webhook_failed'     => array(
				'label'        => 'Webhook失敗の管理者メール',
				'subject'      => '【{site_name}】Webhook処理に失敗しました',
				'body'         => "Webhook処理に失敗しました。\nイベント: {event_type}\nエラーコード: {error_code}",
				'placeholders' => array( 'site_name', 'event_type', 'error_code' ),
			),
		);
	}

	/** @return array<string, array{subject: string, body: string}> */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::definitions() as $type => $definition ) {
			$defaults[ $type ] = array(
				'subject' => $definition['subject'],
				'body'    => $definition['body'],
			);
		}
		return $defaults;
	}

	public static function is_valid( string $type, string $subject, string $body ): bool {
		$definitions = self::definitions();
		if ( ! isset( $definitions[ $type ] ) || '' === trim( $subject ) || '' === trim( $body ) ) {
			return false;
		}
		preg_match_all( '/\{([a-z_]+)\}/', $subject . "\n" . $body, $matches );
		foreach ( array_unique( $matches[1] ) as $placeholder ) {
			if ( ! in_array( $placeholder, $definitions[ $type ]['placeholders'], true ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string, scalar|null> $values @return array{subject: string, body: string} */
	public function render( string $type, array $values ): array {
		$defaults  = self::defaults();
		$templates = is_array( $this->settings['email_templates'] ?? null ) ? $this->settings['email_templates'] : array();
		$template  = is_array( $templates[ $type ] ?? null ) ? $templates[ $type ] : array();
		$subject   = (string) ( $template['subject'] ?? $defaults[ $type ]['subject'] ?? '' );
		$body      = (string) ( $template['body'] ?? $defaults[ $type ]['body'] ?? '' );
		if ( ! self::is_valid( $type, $subject, $body ) ) {
			$subject = $defaults[ $type ]['subject'];
			$body    = $defaults[ $type ]['body'];
		}
		$replacements = array();
		foreach ( $values as $key => $value ) {
			$replacements[ '{' . $key . '}' ] = trim( wp_strip_all_tags( (string) $value ) );
		}
		return array(
			'subject' => str_replace( array( "\r", "\n" ), ' ', strtr( $subject, $replacements ) ),
			'body'    => strtr( $body, $replacements ),
		);
	}
}
