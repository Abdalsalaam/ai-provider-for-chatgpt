/**
 * Type shapes mirroring the REST responses from the
 * ai-provider-for-chatgpt/v1 namespace.
 *
 * Keep in sync with ConnectionController / DiagnosticsController /
 * CacheController in src/Rest/.
 */

export type ConnectionStatus = {
	connected: boolean;
	email: string | null;
	account_id: string | null;
	plan_type: string | null;
	is_fedramp: boolean;
	id_token_expired: boolean;
	last_refresh: number | null;
	oauth_client_id: string;
	has_core_api_key_flag: boolean;
	provider: {
		registered: boolean;
		configured: boolean;
	};
	refreshed_at?: number;
};

export type Diagnostics = {
	core_api_key_option: boolean;
	registry: {
		has: boolean;
		configured: boolean;
		error: string | null;
	};
	sdk_models: {
		count: number;
		sample: string[];
		error: string | null;
	};
};

export type RemoteModelEntry = {
	id: string;
	slug: string | null;
	name: string | null;
	model_family_display_name: string | null;
	passes_text_filter: boolean;
};

export type RemoteModelsProbe = {
	http_status: number;
	model_ids: string[];
	text_only_ids: string[];
	raw_keys: string[];
	raw_models: RemoteModelEntry[];
	error: string | null;
};

export type CacheClearResult = {
	deleted: number;
};

export type PairingToken = {
	token: string;
	expires_at: number;
	site_url: string;
	rest_url: string;
};

export type BootstrapData = {
	restNamespace: string;
	restRoot: string;
	nonce: string;
	adminUrl: string;
};

declare global {
	interface Window {
		aiProviderForChatGpt: BootstrapData;
	}
}
