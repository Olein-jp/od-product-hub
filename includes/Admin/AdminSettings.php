<?php
/**
 * Administration settings registration, validation, and screen.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

use OD_Product_Hub\API\ClientIpResolver;
use OD_Product_Hub\Database\Installer;
use OD_Product_Hub\Email\Templates;

final class AdminSettings {
	public function register(): void {
		register_setting(
			'odph_settings_group',
			'odph_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Installer::defaults(),
			)
		);
	}

	/** @param mixed $input @return array<string, mixed> */
	public function sanitize( $input ): array {
		$current = get_option( 'odph_settings', Installer::defaults() );
		$input   = is_array( $input ) ? $input : array();
		foreach ( array( 'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret' ) as $secret ) {
			if ( ! empty( $input[ $secret ] ) ) {
				$current[ $secret ] = sanitize_text_field( $input[ $secret ] );
			}
		}
		$current['portal_enabled']  = empty( $input['portal_enabled'] ) ? 0 : 1;
		$current['success_url']     = $this->sanitize_url( 'success_url', $input['success_url'] ?? '', $current );
		$current['cancel_url']      = $this->sanitize_url( 'cancel_url', $input['cancel_url'] ?? '', $current );
		$current['account_page_id'] = absint( $input['account_page_id'] ?? 0 );
		$current['email_from_name'] = sanitize_text_field( $input['email_from_name'] ?? '' );
		$email                      = sanitize_email( $input['email_from_address'] ?? '' );
		if ( '' !== (string) ( $input['email_from_address'] ?? '' ) && ! is_email( $email ) ) {
			add_settings_error( 'odph_settings', 'invalid_email', __( '送信元メールアドレスが不正です。以前の値を維持しました。', 'od-product-hub' ) );
		} else {
			$current['email_from_address'] = $email;
		}
		$current['log_retention_days']  = $this->bounded_integer( 'log_retention_days', $input, $current, 1, 3650 );
		$current['api_rate_limit']      = $this->bounded_integer( 'api_rate_limit', $input, $current, 1, 1000 );
		$current['api_trusted_proxies'] = implode( "\n", ClientIpResolver::normalize_trusted_proxies( sanitize_textarea_field( $input['api_trusted_proxies'] ?? '' ) ) );
		$current['delete_on_uninstall'] = empty( $input['delete_on_uninstall'] ) ? 0 : 1;
		$defaults                       = Templates::defaults();
		$current_templates              = is_array( $current['email_templates'] ?? null ) ? $current['email_templates'] : $defaults;
		$submitted_templates            = is_array( $input['email_templates'] ?? null ) ? $input['email_templates'] : array();
		foreach ( Templates::definitions() as $type => $definition ) {
			$submitted = is_array( $submitted_templates[ $type ] ?? null ) ? $submitted_templates[ $type ] : ( $current_templates[ $type ] ?? $defaults[ $type ] );
			$subject   = sanitize_text_field( $submitted['subject'] ?? '' );
			$body      = sanitize_textarea_field( $submitted['body'] ?? '' );
			if ( Templates::is_valid( $type, $subject, $body ) ) {
				$current['email_templates'][ $type ] = array(
					'subject' => $subject,
					'body'    => $body,
				);
			} else {
				$current['email_templates'][ $type ] = $defaults[ $type ];
				add_settings_error( 'odph_settings', 'invalid_email_template_' . $type, sprintf( '%sが不正なため既定値へ戻しました。', $definition['label'] ) );
			}
		}
		return $current;
	}

	/** @param mixed $value @param array<string, mixed> $current */
	private function sanitize_url( string $key, $value, array $current ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			add_settings_error( 'odph_settings', 'invalid_' . $key, __( 'URLが不正です。以前の値を維持しました。', 'od-product-hub' ) );
			return (string) ( $current[ $key ] ?? '' );
		}
		return $url;
	}

	/** @param array<string, mixed> $input @param array<string, mixed> $current */
	private function bounded_integer( string $key, array $input, array $current, int $minimum, int $maximum ): int {
		$value = filter_var( $input[ $key ] ?? null, FILTER_VALIDATE_INT );
		if ( false === $value || $value < $minimum || $value > $maximum ) {
			add_settings_error( 'odph_settings', 'invalid_' . $key, __( '数値が許容範囲外です。以前の値を維持しました。', 'od-product-hub' ) );
			return (int) ( $current[ $key ] ?? $minimum );
		}
		return $value;
	}

	public function render(): void {
		AdminAccess::guard();
		$s = get_option( 'odph_settings', Installer::defaults() );
		echo '<div class="wrap"><h1>OD Product Hub 設定</h1>';
		settings_errors( 'odph_settings' );
		if ( isset( $_GET['stripe_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after a nonce-protected action.
			$success = 'success' === sanitize_key( wp_unslash( $_GET['stripe_test'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after a nonce-protected action.
			echo '<div class="notice notice-' . ( $success ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $success ? __( 'Stripeへ正常に接続できました。', 'od-product-hub' ) : __( 'Stripeへ接続できませんでした。キーまたは通信環境を確認してください。', 'od-product-hub' ) ) . '</p></div>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( 'odph_settings_group' );
		$fields = array(
			'stripe_secret_key'      => 'Stripe Secret Key',
			'stripe_publishable_key' => 'Stripe Publishable Key',
			'stripe_webhook_secret'  => 'Stripe Webhook Secret',
			'success_url'            => '購入完了URL',
			'cancel_url'             => 'キャンセルURL',
			'email_from_name'        => '送信元名',
			'email_from_address'     => '送信元メール',
			'log_retention_days'     => 'ログ保持日数',
			'api_rate_limit'         => 'APIレート/分',
		);
		echo '<table class="form-table">';
		foreach ( $fields as $key => $label ) {
			$secret = str_contains( $key, 'key' ) || str_contains( $key, 'secret' );
			$value  = $secret ? '' : (string) ( $s[ $key ] ?? '' );
			printf( '<tr><th><label for="odph_%1$s">%2$s</label></th><td><input class="regular-text" type="%3$s" id="odph_%1$s" name="odph_settings[%1$s]" value="%4$s" placeholder="%5$s"></td></tr>', esc_attr( $key ), esc_html( $label ), $secret ? 'password' : 'text', esc_attr( $value ), esc_attr( $secret && ! empty( $s[ $key ] ) ? '設定済み（変更時のみ入力）末尾 ' . substr( $s[ $key ], -4 ) : '' ) );
		}
		printf( '<tr><th><label for="odph_api_trusted_proxies">信頼するプロキシ</label></th><td><textarea class="large-text code" rows="4" id="odph_api_trusted_proxies" name="odph_settings[api_trusted_proxies]">%s</textarea><p class="description">CIDRまたはIPを1行ずつ指定します。未設定時はX-Forwarded-Forを使用しません。</p></td></tr>', esc_textarea( (string) ( $s['api_trusted_proxies'] ?? '' ) ) );
		echo '<tr><th>Customer Portal</th><td><label><input type="checkbox" name="odph_settings[portal_enabled]" value="1" ' . checked( ! empty( $s['portal_enabled'] ), true, false ) . '> 有効化</label></td></tr><tr><th>Webhook URL</th><td><code>' . esc_html( rest_url( 'od-product-hub/v1/stripe/webhook' ) ) . '</code></td></tr></table>';
		echo '<h2>メールテンプレート</h2><p>メールはプレーンテキストで送信されます。使用可能なプレースホルダー以外を含むテンプレートは既定値へ戻ります。</p>';
		$stored_templates = is_array( $s['email_templates'] ?? null ) ? $s['email_templates'] : Templates::defaults();
		foreach ( Templates::definitions() as $type => $definition ) {
			$template = is_array( $stored_templates[ $type ] ?? null ) ? $stored_templates[ $type ] : Templates::defaults()[ $type ];
			echo '<fieldset class="odph-email-template"><legend><strong>' . esc_html( $definition['label'] ) . '</strong></legend>';
			printf( '<p><label for="odph-email-%1$s-subject">件名</label><br><input class="large-text" id="odph-email-%1$s-subject" name="odph_settings[email_templates][%1$s][subject]" value="%2$s"></p>', esc_attr( $type ), esc_attr( (string) $template['subject'] ) );
			printf( '<p><label for="odph-email-%1$s-body">本文</label><br><textarea class="large-text" rows="5" id="odph-email-%1$s-body" name="odph_settings[email_templates][%1$s][body]">%2$s</textarea></p>', esc_attr( $type ), esc_textarea( (string) $template['body'] ) );
			echo '<p class="description">使用可能: ';
			foreach ( $definition['placeholders'] as $placeholder ) {
				echo '<code>{' . esc_html( $placeholder ) . '}</code> ';
			}
			echo '</p></fieldset>';
		}
		printf( '<p><label><input type="checkbox" name="odph_settings[delete_on_uninstall]" value="1" %s> アンインストール時に全データを削除する</label></p>', checked( ! empty( $s['delete_on_uninstall'] ), true, false ) );
		submit_button();
		echo '</form><hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_test_stripe">';
		wp_nonce_field( 'odph_test_stripe' );
		submit_button( __( 'Stripe接続をテスト', 'od-product-hub' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}
}
