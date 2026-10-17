/**
 * Typed apiFetch wrappers for every REST route the React UI calls.
 */

import apiFetch from '@wordpress/api-fetch';

import type {
	CacheClearResult,
	ConnectionStatus,
	Diagnostics,
	DisconnectResult,
	RefreshResult,
	RemoteModelsProbe,
	SignInStart,
} from '../types';

export type { SignInStart } from '../types';

const NS = 'ai-provider-for-chatgpt/v1';

export const api = {
	getConnection(): Promise< ConnectionStatus > {
		return apiFetch< ConnectionStatus >( {
			path: `/${ NS }/connection`,
			method: 'GET',
		} );
	},

	startSignIn(): Promise< SignInStart > {
		return apiFetch< SignInStart >( {
			path: `/${ NS }/connection/sign-in`,
			method: 'POST',
		} );
	},

	completeSignIn( callbackUrl: string ): Promise< ConnectionStatus > {
		return apiFetch< ConnectionStatus >( {
			path: `/${ NS }/connection/callback`,
			method: 'POST',
			data: { callback_url: callbackUrl },
		} );
	},

	disconnect(): Promise< DisconnectResult > {
		return apiFetch< DisconnectResult >( {
			path: `/${ NS }/connection`,
			method: 'DELETE',
		} );
	},

	// Signs out and removes the registration so another account can sign in.
	forgetAccount(): Promise< DisconnectResult > {
		return apiFetch< DisconnectResult >( {
			path: `/${ NS }/connection?forget=true`,
			method: 'DELETE',
		} );
	},

	refresh(): Promise< RefreshResult > {
		return apiFetch< RefreshResult >( {
			path: `/${ NS }/connection/refresh`,
			method: 'POST',
		} );
	},

	getDiagnostics(): Promise< Diagnostics > {
		return apiFetch< Diagnostics >( {
			path: `/${ NS }/diagnostics`,
			method: 'GET',
		} );
	},

	probeRemoteModels(): Promise< RemoteModelsProbe > {
		return apiFetch< RemoteModelsProbe >( {
			path: `/${ NS }/diagnostics/remote-models`,
			method: 'GET',
		} );
	},

	clearCache(): Promise< CacheClearResult > {
		return apiFetch< CacheClearResult >( {
			path: `/${ NS }/cache`,
			method: 'DELETE',
		} );
	},
};
