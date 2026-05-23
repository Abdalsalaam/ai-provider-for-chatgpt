/**
 * Entry point for the React-based Settings → ChatGPT admin page.
 */

import apiFetch from '@wordpress/api-fetch';
import { createRoot } from '@wordpress/element';

import { App } from './App';
import './style.css';

const bootstrap = window.aiProviderForChatGpt;

if ( bootstrap ) {
	apiFetch.use( apiFetch.createNonceMiddleware( bootstrap.nonce ) );
	apiFetch.use( apiFetch.createRootURLMiddleware( bootstrap.restRoot ) );
}

const container = document.getElementById( 'ai-provider-for-chatgpt-root' );
if ( container ) {
	createRoot( container ).render( <App /> );
}
