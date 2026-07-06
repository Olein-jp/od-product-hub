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
use OD_Product_Hub\VendorLicense\ProductLicenseService;

final class AdminSettings {
	public function __construct( private readonly ?ProductLicenseService $product_license = null ) {}

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
			add_settings_error( 'odph_settings', 'invalid_email', __( 'The sender email address is invalid. The previous value was preserved.', 'od-product-hub' ) );
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
				/* translators: %s: email template label. */
				add_settings_error( 'odph_settings', 'invalid_email_template_' . $type, sprintf( __( '%s was invalid and has been reset to its default.', 'od-product-hub' ), $definition['label'] ) );
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
			add_settings_error( 'odph_settings', 'invalid_' . $key, __( 'The URL is invalid. The previous value was preserved.', 'od-product-hub' ) );
			return (string) ( $current[ $key ] ?? '' );
		}
		return $url;
	}

	/** @param array<string, mixed> $input @param array<string, mixed> $current */
	private function bounded_integer( string $key, array $input, array $current, int $minimum, int $maximum ): int {
		$value = filter_var( $input[ $key ] ?? null, FILTER_VALIDATE_INT );
		if ( false === $value || $value < $minimum || $value > $maximum ) {
			add_settings_error( 'odph_settings', 'invalid_' . $key, __( 'The number is outside the allowed range. The previous value was preserved.', 'od-product-hub' ) );
			return (int) ( $current[ $key ] ?? $minimum );
		}
		return $value;
	}

	public function render(): void {
		AdminAccess::guard();
		$s = get_option( 'odph_settings', Installer::defaults() );
		echo '<div class="wrap"><h1>' . esc_html__( 'OD Product Hub Settings', 'od-product-hub' ) . '</h1>';
		settings_errors( 'odph_settings' );
		$this->render_product_license();
		if ( isset( $_GET['stripe_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after a nonce-protected action.
			$success = 'success' === sanitize_key( wp_unslash( $_GET['stripe_test'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after a nonce-protected action.
			echo '<div class="notice notice-' . ( $success ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $success ? __( 'Connected to Stripe successfully.', 'od-product-hub' ) : __( 'Could not connect to Stripe. Check the keys and network connection.', 'od-product-hub' ) ) . '</p></div>';
		}
		echo '<form method="post" action="options.php">';
		settings_fields( 'odph_settings_group' );
		$fields = array(
			'stripe_secret_key'      => 'Stripe Secret Key',
			'stripe_publishable_key' => 'Stripe Publishable Key',
			'stripe_webhook_secret'  => 'Stripe Webhook Secret',
			'success_url'            => __( 'Purchase success URL', 'od-product-hub' ),
			'cancel_url'             => __( 'Checkout cancel URL', 'od-product-hub' ),
			'email_from_name'        => __( 'Sender name', 'od-product-hub' ),
			'email_from_address'     => __( 'Sender email', 'od-product-hub' ),
			'log_retention_days'     => __( 'Log retention days', 'od-product-hub' ),
			'api_rate_limit'         => __( 'API requests per minute', 'od-product-hub' ),
		);
		echo '<table class="form-table">';
		foreach ( $fields as $key => $label ) {
			$secret      = str_contains( $key, 'key' ) || str_contains( $key, 'secret' );
			$value       = $secret ? '' : (string) ( $s[ $key ] ?? '' );
			$placeholder = '';
			if ( $secret && ! empty( $s[ $key ] ) ) {
				/* translators: %s: last four characters of the stored secret. */
				$placeholder = sprintf( __( 'Configured; enter a value only to change it. Ends in %s', 'od-product-hub' ), substr( $s[ $key ], -4 ) );
			}
			printf( '<tr><th><label for="odph_%1$s">%2$s</label></th><td><input class="regular-text" type="%3$s" id="odph_%1$s" name="odph_settings[%1$s]" value="%4$s" placeholder="%5$s"></td></tr>', esc_attr( $key ), esc_html( $label ), $secret ? 'password' : 'text', esc_attr( $value ), esc_attr( $placeholder ) );
		}
		echo '<tr><th><label for="odph_account_page_id">' . esc_html__( 'Account page', 'od-product-hub' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'odph_settings[account_page_id]',
				'id'                => 'odph_account_page_id',
				'selected'          => absint( $s['account_page_id'] ?? 0 ),
				'show_option_none'  => esc_html__( 'Select a page', 'od-product-hub' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__( 'Select a published page containing the odph_my_account shortcode.', 'od-product-hub' ) . '</p></td></tr>';
		printf( '<tr><th><label for="odph_api_trusted_proxies">%1$s</label></th><td><textarea class="large-text code" rows="4" id="odph_api_trusted_proxies" name="odph_settings[api_trusted_proxies]">%2$s</textarea><p class="description">%3$s</p></td></tr>', esc_html__( 'Trusted proxies', 'od-product-hub' ), esc_textarea( (string) ( $s['api_trusted_proxies'] ?? '' ) ), esc_html__( 'Enter one CIDR or IP address per line. X-Forwarded-For is ignored when this field is empty.', 'od-product-hub' ) );
		echo '<tr><th>Customer Portal</th><td><label><input type="checkbox" name="odph_settings[portal_enabled]" value="1" ' . checked( ! empty( $s['portal_enabled'] ), true, false ) . '> ' . esc_html__( 'Enable', 'od-product-hub' ) . '</label></td></tr><tr><th>Webhook URL</th><td><code>' . esc_html( rest_url( 'od-product-hub/v1/stripe/webhook' ) ) . '</code></td></tr></table>';
		echo '<h2>' . esc_html__( 'Email templates', 'od-product-hub' ) . '</h2><p>' . esc_html__( 'Emails are sent as plain text. Templates containing unsupported placeholders are reset to their defaults.', 'od-product-hub' ) . '</p>';
		$stored_templates = is_array( $s['email_templates'] ?? null ) ? $s['email_templates'] : Templates::defaults();
		foreach ( Templates::definitions() as $type => $definition ) {
			$template = is_array( $stored_templates[ $type ] ?? null ) ? $stored_templates[ $type ] : Templates::defaults()[ $type ];
			echo '<fieldset class="odph-email-template"><legend><strong>' . esc_html( $definition['label'] ) . '</strong></legend>';
			printf( '<p><label for="odph-email-%1$s-subject">%3$s</label><br><input class="large-text" id="odph-email-%1$s-subject" name="odph_settings[email_templates][%1$s][subject]" value="%2$s"></p>', esc_attr( $type ), esc_attr( (string) $template['subject'] ), esc_html__( 'Subject', 'od-product-hub' ) );
			printf( '<p><label for="odph-email-%1$s-body">%3$s</label><br><textarea class="large-text" rows="5" id="odph-email-%1$s-body" name="odph_settings[email_templates][%1$s][body]">%2$s</textarea></p>', esc_attr( $type ), esc_textarea( (string) $template['body'] ), esc_html__( 'Body', 'od-product-hub' ) );
			echo '<p class="description">' . esc_html__( 'Available placeholders:', 'od-product-hub' ) . ' ';
			foreach ( $definition['placeholders'] as $placeholder ) {
				echo '<code>{' . esc_html( $placeholder ) . '}</code> ';
			}
			echo '</p></fieldset>';
		}
		printf( '<p><label><input type="checkbox" name="odph_settings[delete_on_uninstall]" value="1" %1$s> %2$s</label></p>', checked( ! empty( $s['delete_on_uninstall'] ), true, false ), esc_html__( 'Delete all data when uninstalling', 'od-product-hub' ) );
		submit_button();
		echo '</form><hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_test_stripe">';
		wp_nonce_field( 'odph_test_stripe' );
		submit_button( __( 'Test Stripe connection', 'od-product-hub' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	private function render_product_license(): void {
		$license = $this->product_license ?? new ProductLicenseService();
		$current = $license->current();
		$status  = $current->status;
		$labels  = array(
			'active'      => __( 'Active', 'od-product-hub' ),
			'grace'       => __( 'Grace period (vendor Hub unavailable)', 'od-product-hub' ),
			'inactive'    => __( 'Inactive', 'od-product-hub' ),
			'expired'     => __( 'Expired', 'od-product-hub' ),
			'cancelled'   => __( 'Cancelled', 'od-product-hub' ),
			'suspended'   => __( 'Suspended', 'od-product-hub' ),
			'unavailable' => __( 'Vendor Hub unavailable or misconfigured', 'od-product-hub' ),
			'unverified'  => __( 'Not activated', 'od-product-hub' ),
			'deactivated' => __( 'Deactivated', 'od-product-hub' ),
		);
		if ( 'payment_failed' === $current->error_code ) {
			$labels['inactive'] = __( 'Payment failed', 'od-product-hub' );
		} elseif ( 'invalid_license' === $current->error_code ) {
			$labels['inactive'] = __( 'Invalid license key', 'od-product-hub' );
		}
		echo '<h2>' . esc_html__( 'OD Product Hub product license', 'od-product-hub' ) . '</h2>';
		echo '<p>' . esc_html__( 'This license controls only vendor updates and support. Local features and existing data remain available regardless of contract status.', 'od-product-hub' ) . '</p>';
		if ( $license->is_self_reference() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The vendor Hub points to this site. License requests are disabled to prevent a request loop.', 'od-product-hub' ) . '</p></div>';
		} elseif ( '' === $license->hub_url() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The vendor Hub URL is not configured in this distribution.', 'od-product-hub' ) . '</p></div>';
		} elseif ( 'unverified' === $status ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The product license has not been activated.', 'od-product-hub' ) . '</p></div>';
		} elseif ( 'grace' === $status ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The vendor Hub is unavailable. The last active verification is temporarily accepted during the grace period.', 'od-product-hub' ) . '</p></div>';
		} elseif ( 'unavailable' === $status ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The vendor Hub could not be reached. An existing active contract may remain in its grace period.', 'od-product-hub' ) . '</p></div>';
		} elseif ( in_array( $status, array( 'inactive', 'expired', 'cancelled', 'suspended' ), true ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The product contract is not active. New vendor updates are unavailable, but OD Product Hub continues to run.', 'od-product-hub' ) . '</p></div>';
		}
		$hub_display = '' !== $license->hub_url() ? $license->hub_url() : '—';
		echo '<table class="form-table"><tr><th>' . esc_html__( 'Contract status', 'od-product-hub' ) . '</th><td>' . esc_html( $labels[ $status ] ?? $status ) . '</td></tr><tr><th>' . esc_html__( 'Last checked', 'od-product-hub' ) . '</th><td>' . esc_html( 0 < $current->checked_at ? wp_date( 'Y-m-d H:i:s', $current->checked_at ) : __( 'Never', 'od-product-hub' ) ) . '</td></tr><tr><th>' . esc_html__( 'Vendor Hub', 'od-product-hub' ) . '</th><td><code>' . esc_html( $hub_display ) . '</code></td></tr></table>';
		if ( '' === $license->license_key() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_vendor_license"><input type="hidden" name="license_operation" value="activate"><p><label for="odph_vendor_license_key">' . esc_html__( 'Product license key', 'od-product-hub' ) . '</label><br><input class="regular-text" type="password" autocomplete="off" id="odph_vendor_license_key" name="license_key" required></p>';
			wp_nonce_field( 'odph_vendor_license_activate' );
			submit_button( __( 'Activate product license', 'od-product-hub' ), 'secondary', 'submit', false );
			echo '</form>';
			return;
		}
		foreach (
			array(
				'verify'     => __( 'Recheck contract status', 'od-product-hub' ),
				'deactivate' => __( 'Deactivate product license', 'od-product-hub' ),
			) as $operation => $label
		) {
			echo '<form class="odph-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_vendor_license"><input type="hidden" name="license_operation" value="' . esc_attr( $operation ) . '">';
			wp_nonce_field( 'odph_vendor_license_' . $operation );
			submit_button( $label, 'secondary', 'submit', false );
			echo '</form> ';
		}
	}
}
