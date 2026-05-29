/**
 * Webpack config for Block Checkout client-side bundles.
 *
 * Each payment method gets its own entry under assets/blocks/build/methods/<id>.js.
 * Shared block extensions (CVS picker, invoice fields) build into shared/<name>.js.
 *
 * Run:
 *   npm install
 *   npm run build       — production bundle
 *   npm run start       — dev watch
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'methods/linepay/index': './assets/blocks/src/methods/linepay/index.js',
		'methods/payuni/index':  './assets/blocks/src/methods/payuni/index.js',
		'methods/ecpay/index':   './assets/blocks/src/methods/ecpay/index.js',
		// Phase 2 (cont.): payuni shipping CVS picker
		// 'shared/cvs-picker/index': './assets/blocks/src/shared/cvs-picker/index.js',
		// Phase 3+: ecpay × 13, newebpay × N, smilepay × N, paynow × N, pchomepay × N
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/blocks/build' ),
		filename: '[name].js',
	},
};
