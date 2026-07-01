<?php
/**
 * Administration configuration notice and Site Health test.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

final class AdminSiteHealth {
	public function configuration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! str_starts_with( (string) get_current_screen()?->id, 'toplevel_page_od-product-hub' ) ) {
			return;
		}
		$result = $this->configuration_status();
		if ( 'good' !== $result['status'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $result['description'] ) . '</p></div>';
		}
	}

	/** @param array<string, mixed> $tests @return array<string, mixed> */
	public function tests( array $tests ): array {
		$tests['direct']['odph_configuration'] = array(
			'label' => __( 'OD Product Hub の本番設定', 'od-product-hub' ),
			'test'  => array( $this, 'configuration_status' ),
		);
		return $tests;
	}

	/** @return array<string, mixed> */
	public function configuration_status(): array {
		$settings = get_option( 'odph_settings', array() );
		$missing  = empty( $settings['stripe_secret_key'] ) || empty( $settings['stripe_publishable_key'] ) || empty( $settings['stripe_webhook_secret'] );
		$https    = is_ssl() || 'local' === wp_get_environment_type() || 'development' === wp_get_environment_type();
		$good     = ! $missing && $https;
		return array(
			'label'       => $good ? __( 'Stripe設定とHTTPSを確認しました', 'od-product-hub' ) : __( 'OD Product Hub の設定を確認してください', 'od-product-hub' ),
			'status'      => $good ? 'good' : 'recommended',
			'badge'       => array(
				'label' => 'OD Product Hub',
				'color' => 'blue',
			),
			'description' => $good ? __( '本番運用に必要なStripe設定とHTTPSが有効です。', 'od-product-hub' ) : __( 'Stripeの3つのキーを設定し、本番環境ではHTTPSを有効にしてください。', 'od-product-hub' ),
			'actions'     => sprintf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=odph-settings' ) ), esc_html__( '設定画面を開く', 'od-product-hub' ) ),
			'test'        => 'odph_configuration',
		);
	}
}
