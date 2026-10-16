<?php
/**
 * Front-end script and styles, loaded only on pages that show a form.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public const HANDLE = 'libreforms';

	public static function register(): void {
		wp_register_script( self::HANDLE, LIBREFORMS_URL . 'assets/front/libreforms.js', [], LIBREFORMS_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_register_style( self::HANDLE, LIBREFORMS_URL . 'assets/front/libreforms.css', [], LIBREFORMS_VERSION );

		wp_localize_script( self::HANDLE, 'libreformsData', [
			'endpoint' => esc_url_raw( rest_url( 'libreblocks/v1/forms/' ) ),
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'i18n'     => [
				'sending'  => __( 'Sending…', 'libreforms' ),
				'error'    => __( 'The form could not be sent. Please try again.', 'libreforms' ),
				'required' => __( 'This field is required.', 'libreforms' ),
			],
		] );
	}

	public static function enqueue(): void {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			self::register();
		}

		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
	}
}
