/**
 * Webpack config for the plugin's React admin UI.
 *
 * Delegates to @wordpress/scripts. The default DependencyExtractionPlugin
 * externalizes every `@wordpress/*` import to a `wp.*` global, but several of
 * those globals are not registered as script handles in WordPress 6.9 core
 * (`wp-ui`, `wp-theme`) or are too new to rely on. We replace the plugin with
 * a configured instance that bundles those locally and mirrors the default
 * external mapping for everything else.
 *
 * @package Halawa\ChatGptAiProvider
 */

const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const PACKAGES_TO_BUNDLE = new Set( [
	'@wordpress/admin-ui',
	'@wordpress/ui',
	'@wordpress/theme',
	'@wordpress/private-apis',
	'@wordpress/primitives',
	// `wp-icons` is not a registered script handle in WP 6.9 core — the icons
	// are normally bundled inside whichever package uses them. Bundle ours
	// rather than depend on a handle WP_Scripts will reject.
	'@wordpress/icons',
] );

const camelCaseDash = ( s ) =>
	s.replace( /-([a-z])/g, ( _, c ) => c.toUpperCase() );

function requestToExternal( request ) {
	if ( PACKAGES_TO_BUNDLE.has( request ) ) {
		return undefined;
	}
	if ( request === 'react' ) return 'React';
	if ( request === 'react-dom' ) return 'ReactDOM';
	if ( request === 'react/jsx-runtime' ) return [ 'ReactJSXRuntime' ];
	const m = request.match( /^@wordpress\/([\w-]+)$/ );
	if ( m ) return [ 'wp', camelCaseDash( m[ 1 ] ) ];
	return undefined;
}

function requestToHandle( request ) {
	if ( PACKAGES_TO_BUNDLE.has( request ) ) {
		return undefined;
	}
	if ( request === 'react' || request === 'react-dom' ) return request;
	if ( request === 'react/jsx-runtime' ) return 'react-jsx-runtime';
	const m = request.match( /^@wordpress\/([\w-]+)$/ );
	if ( m ) return 'wp-' + m[ 1 ];
	return undefined;
}

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'client/index.tsx' ),
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( p ) => p.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionPlugin( {
			injectPolyfill: true,
			useDefaults: false,
			requestToExternal,
			requestToHandle,
		} ),
	],
};
