<?php
/**
 * Shared, accessible administration UI primitives.
 *
 * @package OD_Product_Hub
 */

namespace OD_Product_Hub\Admin;

final class AdminUi {
	public static function page_header( string $title, string $description = '' ): string {
		$html = '<header class="odph-page-header"><h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== $description ) {
			$html .= '<p class="odph-page-description">' . esc_html( $description ) . '</p>';
		}
		return $html . '</header>';
	}

	public static function section_start( string $title, string $description = '' ): string {
		$html = '<section class="odph-section"><div class="odph-section-header"><h2>' . esc_html( $title ) . '</h2>';
		if ( '' !== $description ) {
			$html .= '<p>' . esc_html( $description ) . '</p>';
		}
		return $html . '</div>';
	}

	public static function section_end(): string {
		return '</section>';
	}

	public static function card( string $title, string $value, string $url = '', string $link_label = '' ): string {
		$body = '<h2 class="odph-card-title">' . esc_html( $title ) . '</h2><p class="odph-card-value">' . esc_html( $value ) . '</p>';
		if ( '' === $url ) {
			return '<div class="card odph-card">' . $body . '</div>';
		}
		$label = '' !== $link_label ? $link_label : $title;
		return '<a class="card odph-card odph-card-link" href="' . esc_url( $url ) . '">' . $body . '<span class="odph-card-action">' . esc_html( $label ) . '</span></a>';
	}

	public static function status_badge( string $label, string $tone = 'neutral' ): string {
		$allowed = array( 'neutral', 'success', 'warning', 'error', 'info' );
		$tone    = in_array( $tone, $allowed, true ) ? $tone : 'neutral';
		return '<span class="odph-status-badge odph-status-badge--' . esc_attr( $tone ) . '"><span class="odph-status-indicator" aria-hidden="true"></span>' . esc_html( $label ) . '</span>';
	}

	public static function notice( string $message, string $type = 'info', bool $dismissible = false ): string {
		$allowed = array( 'success', 'warning', 'error', 'info' );
		$type    = in_array( $type, $allowed, true ) ? $type : 'info';
		$class   = 'notice notice-' . $type . ' odph-notice';
		$class  .= $dismissible ? ' is-dismissible' : '';
		$role    = 'error' === $type ? 'alert' : 'status';
		return '<div class="' . esc_attr( $class ) . '" role="' . esc_attr( $role ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	public static function empty_state( string $title, string $description = '', string $action_url = '', string $action_label = '' ): string {
		$html = '<div class="odph-empty-state"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><h3>' . esc_html( $title ) . '</h3>';
		if ( '' !== $description ) {
			$html .= '<p>' . esc_html( $description ) . '</p>';
		}
		if ( '' !== $action_url && '' !== $action_label ) {
			$html .= '<p><a class="button" href="' . esc_url( $action_url ) . '">' . esc_html( $action_label ) . '</a></p>';
		}
		return $html . '</div>';
	}

	/**
	 * @param list<array{label:string,url:string,primary?:bool}> $actions
	 */
	public static function action_group( array $actions, string $label ): string {
		$html = '<div class="odph-action-group" role="group" aria-label="' . esc_attr( $label ) . '">';
		foreach ( $actions as $action ) {
			$class = ! empty( $action['primary'] ) ? 'button button-primary' : 'button';
			$html .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $action['url'] ) . '">' . esc_html( $action['label'] ) . '</a>';
		}
		return $html . '</div>';
	}
}
