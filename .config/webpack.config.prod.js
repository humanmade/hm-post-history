/**
 * .config/webpack.config.prod.js :
 * This file defines the production build configuration
 */
const { helpers, externals, presets } = require( '@humanmade/webpack-helpers' );
const { filePath } = helpers;

module.exports = presets.production( {
	name: 'frontend',
	externals,
	entry: {
		frontend: filePath( 'assets/src/scripts/frontend.js' ),
	},
	output: {
		path: filePath( 'assets/build' ),
	},
} );
