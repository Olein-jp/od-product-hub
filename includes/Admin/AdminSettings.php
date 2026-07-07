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
		$section = sanitize_key( (string) ( $input['_section'] ?? 'all' ) );
		if ( in_array( $section, array( 'all', 'payment' ), true ) ) {
			foreach ( array( 'stripe_secret_key', 'stripe_publishable_key', 'stripe_webhook_secret' ) as $secret ) {
				if ( ! empty( $input[ $secret ] ) ) {
					$current[ $secret ] = sanitize_text_field( $input[ $secret ] );
				}
			}
			$current['portal_enabled'] = empty( $input['portal_enabled'] ) ? 0 : 1;
			$current['success_url']    = $this->sanitize_url( 'success_url', $input['success_url'] ?? '', $current );
			$current['cancel_url']     = $this->sanitize_url( 'cancel_url', $input['cancel_url'] ?? '', $current );
		}
		if ( in_array( $section, array( 'all', 'pages' ), true ) ) {
			$current['account_page_id'] = absint( $input['account_page_id'] ?? 0 );
		}
		if ( in_array( $section, array( 'all', 'email' ), true ) ) {
			$current['email_from_name'] = sanitize_text_field( $input['email_from_name'] ?? '' );
			$email                      = sanitize_email( $input['email_from_address'] ?? '' );
			if ( '' !== (string) ( $input['email_from_address'] ?? '' ) && ! is_email( $email ) ) {
				add_settings_error( 'odph_settings', 'invalid_email', __( 'The sender email address is invalid. The previous value was preserved.', 'od-product-hub' ) );
			} else {
				$current['email_from_address'] = $email;
			}
			$this->sanitize_templates( $input, $current );
		}
		if ( in_array( $section, array( 'all', 'api' ), true ) ) {
			$current['api_rate_limit']      = $this->bounded_integer( 'api_rate_limit', $input, $current, 1, 1000 );
			$current['api_trusted_proxies'] = implode( "\n", ClientIpResolver::normalize_trusted_proxies( sanitize_textarea_field( $input['api_trusted_proxies'] ?? '' ) ) );
		}
		if ( in_array( $section, array( 'all', 'data' ), true ) ) {
			$current['log_retention_days']  = $this->bounded_integer( 'log_retention_days', $input, $current, 1, 3650 );
			$current['delete_on_uninstall'] = empty( $input['delete_on_uninstall'] ) ? 0 : 1;
		}
		return $current;
	}

	/** @param array<string, mixed> $input @param array<string, mixed> $current */
	private function sanitize_templates( array $input, array &$current ): void {
		$defaults            = Templates::defaults();
		$current_templates   = is_array( $current['email_templates'] ?? null ) ? $current['email_templates'] : $defaults;
		$submitted_templates = is_array( $input['email_templates'] ?? null ) ? $input['email_templates'] : array();
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
		$s       = get_option( 'odph_settings', Installer::defaults() );
		$tabs    = array(
			'license' => __( 'Product license', 'od-product-hub' ),
			'payment' => __( 'Payments', 'od-product-hub' ),
			'pages'   => __( 'Pages', 'od-product-hub' ),
			'email'   => __( 'Email', 'od-product-hub' ),
			'api'     => __( 'API / Network', 'od-product-hub' ),
			'data'    => __( 'Data retention', 'od-product-hub' ),
		);
		$current = sanitize_key( wp_unslash( $_GET['tab'] ?? 'payment' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
		$current = isset( $tabs[ $current ] ) ? $current : 'payment';
		echo '<div class="wrap odph-settings"><h1>' . esc_html__( 'OD Product Hub Settings', 'od-product-hub' ) . '</h1><nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Settings sections', 'od-product-hub' ) . '">';
		foreach ( $tabs as $tab => $label ) {
			printf(
				'<a class="nav-tab %1$s" href="%2$s" %3$s>%4$s</a>',
				$tab === $current ? 'nav-tab-active' : '',
				esc_url(
					add_query_arg(
						array(
							'page' => 'odph-settings',
							'tab'  => $tab,
						),
						admin_url( 'admin.php' )
					)
				),
				$tab === $current ? 'aria-current="page"' : '',
				esc_html( $label )
			); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- aria-current is a fixed allow-listed string.
		}
		echo '</nav>';
		settings_errors( 'odph_settings' );
		if ( 'license' === $current ) {
			$this->render_product_license();
			echo '</div>';
			return;
		}
		if ( isset( $_GET['stripe_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after a nonce-protected action.
			$success = 'success' === sanitize_key( wp_unslash( $_GET['stripe_test'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag after a nonce-protected action.
			echo AdminUi::notice( $success ? __( 'Connected to Stripe successfully.', 'od-product-hub' ) : __( 'Could not connect to Stripe. Check the keys and network connection, then try again.', 'od-product-hub' ), $success ? 'success' : 'error', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
		}
		echo '<form method="post" action="options.php"><input type="hidden" name="odph_settings[_section]" value="' . esc_attr( $current ) . '">';
		settings_fields( 'odph_settings_group' );
		$this->render_section( $current, $s );
		submit_button();
		echo '</form>';
		if ( 'payment' === $current ) {
			echo '<section class="odph-section odph-secondary-action"><h2>' . esc_html__( 'Stripe connection test', 'od-product-hub' ) . '</h2><p>' . esc_html__( 'Save any key changes before testing. The test checks connectivity without displaying or logging secret values.', 'od-product-hub' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="odph_test_stripe">';
			wp_nonce_field( 'odph_test_stripe' );
			submit_button( __( 'Test Stripe connection', 'od-product-hub' ), 'secondary', 'submit', false );
			echo '</form></section>';
		}
		echo '</div>';
	}

	/** @param array<string, mixed> $settings */
	private function render_section( string $section, array $settings ): void {
		if ( 'payment' === $section ) {
			echo AdminUi::section_start( __( 'Payments', 'od-product-hub' ), __( 'Configure Stripe credentials, checkout destinations, Customer Portal, and the webhook endpoint.', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
			echo '<table class="form-table" role="presentation">';
			$this->secret_row( 'stripe_secret_key', 'Stripe Secret Key', ! empty( $settings['stripe_secret_key'] ) );
			$this->secret_row( 'stripe_publishable_key', 'Stripe Publishable Key', ! empty( $settings['stripe_publishable_key'] ) );
			$this->secret_row( 'stripe_webhook_secret', 'Stripe Webhook Secret', ! empty( $settings['stripe_webhook_secret'] ) );
			$this->text_row( 'success_url', __( 'Purchase success URL', 'od-product-hub' ), (string) ( $settings['success_url'] ?? '' ), __( 'Required. Use an HTTPS page customers see after a successful purchase.', 'od-product-hub' ), 'url' );
			$this->text_row( 'cancel_url', __( 'Checkout cancel URL', 'od-product-hub' ), (string) ( $settings['cancel_url'] ?? '' ), __( 'Required. Use an HTTPS page customers see after canceling checkout.', 'od-product-hub' ), 'url' );
			echo '<tr><th scope="row">Customer Portal</th><td><label for="odph_portal_enabled"><input id="odph_portal_enabled" type="checkbox" name="odph_settings[portal_enabled]" value="1" ' . checked( ! empty( $settings['portal_enabled'] ), true, false ) . '> ' . esc_html__( 'Allow customers to open Stripe Customer Portal', 'od-product-hub' ) . '</label><p class="description">' . esc_html__( 'Optional. Customers can manage payment methods and subscriptions from their account page.', 'od-product-hub' ) . '</p></td></tr>';
			$webhook = rest_url( 'od-product-hub/v1/stripe/webhook' );
			echo '<tr><th scope="row">Webhook URL</th><td><div class="odph-copy-field"><code id="odph-webhook-url">' . esc_html( $webhook ) . '</code><button type="button" class="button odph-copy-button" data-copy-target="odph-webhook-url">' . esc_html__( 'Copy URL', 'od-product-hub' ) . '</button><span class="screen-reader-text odph-copy-status" aria-live="polite"></span></div><p class="description">' . esc_html__( 'Add this endpoint to the Stripe webhook settings.', 'od-product-hub' ) . '</p></td></tr></table>';
			echo AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static helper output.
			return;
		}
		if ( 'pages' === $section ) {
			echo AdminUi::section_start( __( 'Pages', 'od-product-hub' ), __( 'Choose the customer account page used for licenses, downloads, and billing actions.', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
			echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="odph_account_page_id">' . esc_html__( 'Account page', 'od-product-hub' ) . '</label></th><td>';
			wp_dropdown_pages(
				array(
					'name'              => 'odph_settings[account_page_id]',
					'id'                => 'odph_account_page_id',
					'selected'          => absint( $settings['account_page_id'] ?? 0 ),
					'show_option_none'  => esc_html__( 'Select a page', 'od-product-hub' ),
					'option_none_value' => '0',
				)
			);
			echo '<p class="description">' . esc_html__( 'Required. Select a published page containing the odph_my_account shortcode.', 'od-product-hub' ) . '</p>';
			$this->account_page_status( absint( $settings['account_page_id'] ?? 0 ) );
			echo '</td></tr></table>' . AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static helper output.
			return;
		}
		if ( 'email' === $section ) {
			echo AdminUi::section_start( __( 'Email', 'od-product-hub' ), __( 'Configure the sender and plain-text messages sent for customer and license events.', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
			echo '<table class="form-table" role="presentation">';
			$this->text_row( 'email_from_name', __( 'Sender name', 'od-product-hub' ), (string) ( $settings['email_from_name'] ?? '' ), __( 'Optional. Defaults to the WordPress site name when empty.', 'od-product-hub' ) );
			$this->text_row( 'email_from_address', __( 'Sender email', 'od-product-hub' ), (string) ( $settings['email_from_address'] ?? '' ), __( 'Optional. Enter a valid address authorized by your mail provider.', 'od-product-hub' ), 'email' );
			echo '</table><h3>' . esc_html__( 'Email templates', 'od-product-hub' ) . '</h3><p>' . esc_html__( 'Unsupported placeholders reset that template to its default when saved.', 'od-product-hub' ) . '</p>';
			$stored = is_array( $settings['email_templates'] ?? null ) ? $settings['email_templates'] : Templates::defaults();
			foreach ( Templates::definitions() as $type => $definition ) {
				$template = is_array( $stored[ $type ] ?? null ) ? $stored[ $type ] : Templates::defaults()[ $type ];
				echo '<fieldset class="odph-email-template"><legend><strong>' . esc_html( $definition['label'] ) . '</strong></legend>';
				printf( '<p><label for="odph-email-%1$s-subject">%3$s</label><br><input class="large-text" id="odph-email-%1$s-subject" name="odph_settings[email_templates][%1$s][subject]" value="%2$s"></p>', esc_attr( $type ), esc_attr( (string) $template['subject'] ), esc_html__( 'Subject', 'od-product-hub' ) );
				printf( '<p><label for="odph-email-%1$s-body">%3$s</label><br><textarea class="large-text" rows="5" id="odph-email-%1$s-body" name="odph_settings[email_templates][%1$s][body]">%2$s</textarea></p>', esc_attr( $type ), esc_textarea( (string) $template['body'] ), esc_html__( 'Body', 'od-product-hub' ) );
				echo '<p class="description">' . esc_html__( 'Available placeholders:', 'od-product-hub' ) . ' ';
				foreach ( $definition['placeholders'] as $placeholder ) {
					echo '<code>{' . esc_html( $placeholder ) . '}</code> ';
				}
				echo '</p></fieldset>';
			}
			echo AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static helper output.
			return;
		}
		if ( 'api' === $section ) {
			echo AdminUi::section_start( __( 'API / Network', 'od-product-hub' ), __( 'Control request throttling and which proxies may supply forwarded client addresses.', 'od-product-hub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
			echo '<table class="form-table" role="presentation">';
			$this->text_row( 'api_rate_limit', __( 'API requests per minute', 'od-product-hub' ), (string) ( $settings['api_rate_limit'] ?? '' ), __( 'Required. Enter a whole number from 1 to 1000.', 'od-product-hub' ), 'number', '1', '1000' );
			printf( '<tr><th scope="row"><label for="odph_api_trusted_proxies">%1$s</label></th><td><textarea class="large-text code" rows="6" id="odph_api_trusted_proxies" name="odph_settings[api_trusted_proxies]" aria-describedby="odph_api_trusted_proxies-description">%2$s</textarea><p class="description" id="odph_api_trusted_proxies-description">%3$s</p></td></tr>', esc_html__( 'Trusted proxies', 'od-product-hub' ), esc_textarea( (string) ( $settings['api_trusted_proxies'] ?? '' ) ), esc_html__( 'Optional. Enter one CIDR or IP address per line. Forwarded addresses are ignored when empty.', 'od-product-hub' ) );
			echo '</table>' . AdminUi::section_end(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static helper output.
			return;
		}
		echo '<section class="odph-section odph-danger-zone"><div class="odph-section-header"><h2>' . esc_html__( 'Data retention and Danger Zone', 'od-product-hub' ) . '</h2><p>' . esc_html__( 'Control operational log retention and irreversible cleanup performed when the plugin is uninstalled.', 'od-product-hub' ) . '</p></div><table class="form-table" role="presentation">';
		$this->text_row( 'log_retention_days', __( 'Log retention days', 'od-product-hub' ), (string) ( $settings['log_retention_days'] ?? '' ), __( 'Required. Logs older than this value are removed by the scheduled cleanup. Enter 1 to 3650.', 'od-product-hub' ), 'number', '1', '3650' );
		echo '<tr><th scope="row">' . esc_html__( 'Delete data on uninstall', 'od-product-hub' ) . '</th><td><label for="odph_delete_on_uninstall"><input id="odph_delete_on_uninstall" type="checkbox" name="odph_settings[delete_on_uninstall]" value="1" ' . checked( ! empty( $settings['delete_on_uninstall'] ), true, false ) . '> ' . esc_html__( 'Permanently delete OD Product Hub data when uninstalling', 'od-product-hub' ) . '</label><p class="description">' . esc_html__( 'Danger: products, customers, subscriptions, licenses, releases, logs, settings, and scheduled data will be deleted. Deactivating the plugin does not trigger this deletion.', 'od-product-hub' ) . '</p></td></tr></table></section>';
	}

	private function secret_row( string $key, string $label, bool $configured ): void {
		$status = $configured ? __( 'Saved', 'od-product-hub' ) : __( 'Not configured', 'od-product-hub' );
		$tone   = $configured ? 'success' : 'warning';
		printf( '<tr><th scope="row"><label for="odph_%1$s">%2$s</label></th><td>%3$s<br><input class="regular-text" type="password" autocomplete="new-password" id="odph_%1$s" name="odph_settings[%1$s]" value="" aria-describedby="odph_%1$s-description"><p class="description" id="odph_%1$s-description">%4$s</p></td></tr>', esc_attr( $key ), esc_html( $label ), wp_kses_post( AdminUi::status_badge( $status, $tone ) ), esc_html( $configured ? __( 'Leave blank to keep the saved value, or enter a new value to replace it.', 'od-product-hub' ) : __( 'Required. Enter the value supplied by Stripe.', 'od-product-hub' ) ) );
	}

	private function text_row( string $key, string $label, string $value, string $description, string $type = 'text', string $minimum = '', string $maximum = '' ): void {
		$bounds = '' !== $minimum ? ' min="' . esc_attr( $minimum ) . '" max="' . esc_attr( $maximum ) . '"' : '';
		printf( '<tr><th scope="row"><label for="odph_%1$s">%2$s</label></th><td><input class="regular-text" type="%3$s" id="odph_%1$s" name="odph_settings[%1$s]" value="%4$s" aria-describedby="odph_%1$s-description"%6$s><p class="description" id="odph_%1$s-description">%5$s</p></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $type ), esc_attr( $value ), esc_html( $description ), $bounds ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bounds are escaped fixed attributes.
	}

	private function account_page_status( int $page_id ): void {
		$page = $page_id ? get_post( $page_id ) : null;
		if ( ! $page ) {
			echo AdminUi::notice( __( 'No account page is selected.', 'od-product-hub' ), 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
			return;
		}
		$published = 'publish' === $page->post_status;
		$shortcode = has_shortcode( (string) $page->post_content, 'odph_my_account' );
		if ( $published && $shortcode ) {
			echo AdminUi::notice( __( 'The selected page is published and contains the account shortcode.', 'od-product-hub' ), 'success' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
			return;
		}
		$message = ! $published ? __( 'The selected account page is not published.', 'od-product-hub' ) : __( 'The selected page does not contain the odph_my_account shortcode.', 'od-product-hub' );
		echo AdminUi::notice( $message, 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes output.
		echo '<p><a class="button" href="' . esc_url( get_edit_post_link( $page_id, 'raw' ) ) . '">' . esc_html__( 'Edit account page', 'od-product-hub' ) . '</a></p>';
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
