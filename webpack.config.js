/**
 * Default @wordpress/scripts config plus the shared LibreBlocks UI kit,
 * which lives in libreblocks-core and is bundled into this plugin, and the
 * LibreForge admin components.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve?.alias || {} ),
			'@libreblocks/ui': path.resolve( __dirname, '../libreblocks-core/assets/src/ui' ),
			// The form editor reuses LibreForge's list, editor and field builder.
			'@libreforge/admin': path.resolve( __dirname, '../libreforge/assets/src/admin' ),
		},
	},
};
