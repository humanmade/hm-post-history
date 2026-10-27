/**
 * This file defines the development build configuration
 */
const {helpers, externals, presets} = require('@humanmade/webpack-helpers');
const {choosePort, filePath, cleanOnExit} = helpers;

module.exports = choosePort(8080).then(port =>
	presets.development({
		name: 'frontend',
		devServer: {
			port,
		},
		externals,
		entry: {
			browser: filePath('assets/src/scripts/browser.js'),
		},
		output: {
			path: filePath('assets/build'),
			publicPath: `http://localhost:${port}/hm-post-history-frontend/`
		},
	})
);

// Clean up manifests on exit.
cleanOnExit([
	filePath('assets/build/asset-manifest.json'),
])
