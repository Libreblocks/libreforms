<?php
/**
 * Gutenberg block: Form.
 *
 * @package LibreBlocks\Forms
 */

namespace LibreBlocks\Forms;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	public function __construct( private View $view ) {}

	public function hook(): void {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		$asset_file = LIBREFORMS_DIR . 'build/blocks.asset.php';

		if ( ! is_file( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_register_script( 'libreforms-blocks', LIBREFORMS_URL . 'build/blocks.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'libreforms-blocks', 'libreforms', LIBREFORMS_DIR . 'languages' );
		Assets::register();

		register_block_type( LIBREFORMS_DIR . 'blocks/form', [
			'title'           => __( 'Form', 'libreforms' ),
			'description'     => __( 'Shows a form made in LibreForms.', 'libreforms' ),
			'render_callback' => function ( $a ) {
				$html = $this->view->form( [ 'form_id' => (int) ( $a['formId'] ?? 0 ) ] );

				return '' === $html ? '' : sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $html );
			},
		] );
	}
}
