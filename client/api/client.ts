/**
 * Typed apiFetch wrappers for every REST route the React UI calls.
 */

import apiFetch from '@wordpress/api-fetch';

import type {
	ConnectionStatus,
	Diagnostics,
	RemoteModelsProbe,
	CacheClearResult,
	PairingToken,
} from '../types';

const NS = 'ai-provider-for-chatgpt/v1';

export const api = {
	getConnection(): Promise< ConnectionStatus > {
		return apiFetch< ConnectionStatus >( {
			path: `/${ NS }/connection`,
			method: 'GET',
		} );
	},

	importBundle( bundle: string ): Promise< ConnectionStatus > {
		return apiFetch< ConnectionStatus >( {
			path: `/${ NS }/connection`,
			method: 'POST',
			data: { bundle },
		} );
	},

	disconnect(): Promise< ConnectionStatus > {
		return apiFetch< ConnectionStatus >( {
			path: `/${ NS }/connection`,
			method: 'DELETE',
		} );
	},

	refresh(): Promise< ConnectionStatus > {
		return apiFetch< ConnectionStatus >( {
			path: `/${ NS }/connection/refresh`,
			method: 'POST',
		} );
	},

	issuePairing(): Promise< PairingToken > {
		return apiFetch< PairingToken >( {
			path: `/${ NS }/connection/pairing`,
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
