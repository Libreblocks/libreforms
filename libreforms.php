<?php
/**
 * Plugin Name:       LibreForms
 * Plugin URI:        https://libreblocks.com/libreforms
 * Description:       Form builder: contact forms, sign-ups and front-end submissions that create posts, with e-mails, saved entries and webhooks.
 * Version:           0.2.1
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Requires Plugins:  libreblocks-core, libreforge
 * Author:            LibreBlocks
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       libreforms
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LIBREFORMS_VERSION', '0.2.1' );
define( 'LIBREFORMS_FILE', __FILE__ );
define( 'LIBREFORMS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIBREFORMS_URL', plugin_dir_url( __FILE__ ) );
define( 'LIBREFORMS_REQUIRES_CORE', '0.3.0' );
define( 'LIBREFORMS_REQUIRES_FORGE', '0.8.4' );

/**
 * Boots once LibreBlocks Core and LibreForge are ready.
 *
 * @param LibreBlocks\Forge\Plugin $forge
 */
function libreforms_boot( $forge ) {
	$core = libreblocks();

	if ( ! defined( 'LIBREFORGE_VERSION' ) || version_compare( LIBREFORGE_VERSION, LIBREFORMS_REQUIRES_FORGE, '<' ) ) {
		add_action( 'admin_notices', function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				/* translators: %s: required LibreForge version */
				esc_html( sprintf( __( 'LibreForms needs LibreForge %s or newer. Please update LibreForge.', 'libreforms' ), LIBREFORMS_REQUIRES_FORGE ) )
			);
		} );
		return;
	}

	$compatible = $core->products()->register( 'libreforms', array(
		'name'          => 'LibreForms',
		'version'       => LIBREFORMS_VERSION,
		'file'          => LIBREFORMS_FILE,
		'requires_core' => LIBREFORMS_REQUIRES_CORE,
		'menu'          => 'libreforms',
	) );

	if ( ! $compatible ) {
		return;
	}

	LibreBlocks\Core\Autoloader::register( 'LibreBlocks\\Forms\\', LIBREFORMS_DIR . 'src/' );
	LibreBlocks\Forms\Plugin::instance()->boot( $core, $forge );
}

if ( did_action( 'libreforge/loaded' ) && class_exists( 'LibreBlocks\\Forge\\Plugin' ) ) {
	libreforms_boot( LibreBlocks\Forge\Plugin::instance() );
} else {
	add_action( 'libreforge/loaded', 'libreforms_boot' );
}

register_activation_hook( __FILE__, function () {
	if ( class_exists( 'LibreBlocks\\Forms\\Plugin' ) ) {
		LibreBlocks\Forms\Plugin::instance()->activate();
	}
} );

add_action( 'admin_notices', function () {
	if ( ! did_action( 'libreforge/loaded' ) ) {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'LibreForms needs the LibreBlocks Core and LibreForge plugins. Please install and activate them.', 'libreforms' )
		);
	}
} );
