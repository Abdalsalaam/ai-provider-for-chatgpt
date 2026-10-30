/**
 * Type shapes mirroring the REST responses from the
 * ai-provider-for-chatgpt/v1 namespace.
 *
 * Keep in sync with ConnectionController / DiagnosticsController /
 * CacheController in src/Rest/.
 */

export type ConnectionStatus = {
	connected: boolean;
	needs_signin: boolean;
	email: string | null;
	name: string | null;
	subject: string | null;
	client_id: string | null;
	scopes: string[];
	plan_usage_enabled: boolean;
	expires_at: number | null;
	last_refresh: number | null;
	show_first_signin_notice: boolean;
	redirect_uri: string;
	usage_url: string;
	provider: {
		registered: boolean;
		configured: boolean;
	};
};

export type SignInStart = {
	authorize_url: string;
	redirect_uri: string;
	expires_at: number;
};

export type DisconnectResult = ConnectionStatus & { revoked: boolean };

export type RefreshResult = ConnectionStatus & { refreshed_at: number };

// Error codes POST /connection/callback returns as the WP_Error code.
export type CallbackErrorCode =
	| 'no_pending'
	| 'bad_callback'
	| 'state_mismatch'
	| 'access_denied'
	| 'registration_incomplete'
	| 'exchange_failed'
	| 'id_token_invalid'
	| 'plan_not_enabled'
	| 'account_mismatch';

export type Diagnostics = {
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
	raw_models: RemoteModelEntry[];
	error: string | null;
};

export type SettingsModel = {
	id: string;
	name: string;
	// Null when the account's model list did not report them.
	efforts: string[] | null;
	default_effort: string | null;
};

export type ModelSettings = {
	default_model: string;
	reasoning_effort: string;
	models: SettingsModel[];
	// Plugins the AI plugin's Connector Approval experiment is blocking.
	approval: { callers: string[]; url: string } | null;
};

export type CacheClearResult = {
	deleted: number;
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
