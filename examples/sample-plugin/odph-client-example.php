<?php
/**
 * Plugin Name:       OD Product Hub Client Example
 * Description:       OD Product Hub Client SDK の契約検証サンプルです。
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       odph-client-example
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$odph_example_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $odph_example_autoload ) ) {
	require_once $odph_example_autoload;
}

const ODPH_EXAMPLE_CRON = 'odph_client_example_verify';

function odph_example_client(): ?\OD_Product_Hub_Client\Client {
	$hub_url = (string) get_option( 'odph_example_hub_url', '' );
	if ( ! class_exists( '\OD_Product_Hub_Client\Client' ) || '' === $hub_url ) {
		return null;
	}
	return new \OD_Product_Hub_Client\Client(
		new \OD_Product_Hub_Client\Config(
			$hub_url,
			'example-plugin',
			'0.1.0',
			home_url( '/' ),
			86400,
			259200,
			'',
			'stable',
			''
		),
		new \OD_Product_Hub_Client\WordPress\HttpTransport(),
		new \OD_Product_Hub_Client\WordPress\OptionStateStore( 'odph_example_contract_state' )
	);
}

add_action(
	'plugins_loaded',
	static function (): void {
		$hub_url = (string) get_option( 'odph_example_hub_url', '' );
		$key     = (string) get_option( 'odph_example_license_key', '' );
		if ( '' === $hub_url || '' === $key || ! defined( 'ODPH_EXAMPLE_RELEASE_PUBLIC_KEY' ) || ! class_exists( '\OD_Product_Hub_Client\WordPress\Updater' ) ) {
			return;
		}
		$config = new \OD_Product_Hub_Client\Config( $hub_url, 'example-plugin', '0.1.0', home_url( '/' ), 86400, 259200, plugin_basename( __FILE__ ), 'stable', (string) ODPH_EXAMPLE_RELEASE_PUBLIC_KEY );
		( new \OD_Product_Hub_Client\WordPress\Updater( $config, $key ) )->register();
	}
);

function odph_example_activate_plugin(): void {
	if ( ! wp_next_scheduled( ODPH_EXAMPLE_CRON ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', ODPH_EXAMPLE_CRON );
	}
}
register_activation_hook( __FILE__, 'odph_example_activate_plugin' );

function odph_example_deactivate_plugin(): void {
	wp_clear_scheduled_hook( ODPH_EXAMPLE_CRON );
}
register_deactivation_hook( __FILE__, 'odph_example_deactivate_plugin' );

add_action(
	'admin_init',
	static function (): void {
		register_setting( 'odph_example', 'odph_example_hub_url', array( 'sanitize_callback' => 'esc_url_raw' ) );
		register_setting(
			'odph_example',
			'odph_example_license_key',
			array(
				'sanitize_callback' => static function ( mixed $value ): string {
					$key = strtoupper( sanitize_text_field( (string) $value ) );
					return strlen( $key ) >= 19 && strlen( $key ) <= 32 && preg_match( '/^[A-Z0-9-]+$/', $key ) ? $key : '';
				},
			)
		);
	}
);

add_action(
	'admin_menu',
	static function (): void {
		add_options_page( 'ODPH Client Example', 'ODPH Client', 'manage_options', 'odph-client-example', 'odph_example_render_page' );
	}
);

function odph_example_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$client = odph_example_client();
	if ( isset( $_POST['odph_example_action'] ) && check_admin_referer( 'odph_example_contract' ) && null !== $client ) {
		$key    = (string) get_option( 'odph_example_license_key', '' );
		$action = sanitize_key( wp_unslash( $_POST['odph_example_action'] ) );
		if ( 'activate' === $action ) {
			$result = $client->activate( $key );
		} elseif ( 'verify' === $action ) {
			$result = $client->verify( $key, true );
		} elseif ( 'deactivate' === $action ) {
			$result = $client->deactivate( $key );
		}
	}
	$current = isset( $result ) ? $result : ( null === $client ? null : $client->current( (string) get_option( 'odph_example_license_key', '' ) ) );
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'OD Product Hub Client Example', 'odph-client-example' ); ?></h1>
		<?php if ( null === $client ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html__( 'Composer dependencies are not installed.', 'odph-client-example' ); ?></p></div>
		<?php endif; ?>
		<form action="options.php" method="post">
			<?php settings_fields( 'odph_example' ); ?>
			<table class="form-table"><tbody>
			<tr><th scope="row"><label for="odph_example_hub_url">Hub URL</label></th><td><input class="regular-text" type="url" id="odph_example_hub_url" name="odph_example_hub_url" value="<?php echo esc_attr( (string) get_option( 'odph_example_hub_url', '' ) ); ?>" required></td></tr>
			<tr><th scope="row"><label for="odph_example_license_key"><?php echo esc_html__( 'License key', 'odph-client-example' ); ?></label></th><td><input class="regular-text" type="password" id="odph_example_license_key" name="odph_example_license_key" value="<?php echo esc_attr( (string) get_option( 'odph_example_license_key', '' ) ); ?>" autocomplete="off"></td></tr>
			</tbody></table>
			<?php submit_button(); ?>
		</form>
		<?php if ( null !== $current ) : ?>
			<p><strong><?php echo esc_html__( 'Contract state:', 'odph-client-example' ); ?></strong> <?php echo esc_html( $current->status ); ?> — <?php echo esc_html( $current->message ); ?></p>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'odph_example_contract' ); ?>
			<?php
			foreach ( array( 'activate', 'verify', 'deactivate' ) as $action ) :
				?>
				<button class="button" name="odph_example_action" value="<?php echo esc_attr( $action ); ?>"><?php echo esc_html( ucfirst( $action ) ); ?></button>
			<?php endforeach; ?>
		</form>
	</div>
	<?php
}

add_action(
	ODPH_EXAMPLE_CRON,
	static function (): void {
		$client = odph_example_client();
		$key    = (string) get_option( 'odph_example_license_key', '' );
		if ( null !== $client && '' !== $key ) {
			$client->verify( $key, true );
		}
	}
);

/** 契約者向けサービスだけの判定例。GPLコード自体の実行は止めない。 */
function odph_example_has_subscriber_service(): bool {
	$client = odph_example_client();
	$key    = (string) get_option( 'odph_example_license_key', '' );
	return null !== $client && '' !== $key && $client->verify( $key )->is_service_available();
}
