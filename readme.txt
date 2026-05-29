=== AI Provider for ChatGPT ===
Contributors: abdalsalaam
Tags: ai, chatgpt, openai, ai-provider, chatbot
Requires at least: 6.9
Tested up to: 7.0
Stable tag: 0.1.2
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds ChatGPT (OpenAI) as a first-class provider for the WordPress AI Client, authenticated with a ChatGPT account instead of an API key.

== Description ==

AI Provider for ChatGPT registers OpenAI with the WordPress AI Client and authenticates each request using an OAuth token associated with a ChatGPT account (Free / Plus / Pro). API usage is billed against the connected ChatGPT subscription rather than a separate OpenAI API account.

Any plugin or theme that uses the AI Client can call ChatGPT models the same way it calls Kimi, Google, or Anthropic.

**Features:**

* Text generation with ChatGPT models (GPT-4o, GPT-4.1, o-series, and others exposed by the connected account)
* Multi-turn chat history
* Function calling and tool use
* Structured output via JSON schema
* Dynamic model discovery from the connected account
* Settings screen for connecting an account and selecting a default model

**Requirements:**

* PHP 7.4 or higher, with the `sodium` and `openssl` extensions
* A ChatGPT Free, Plus, or Pro account (Business / Edu / Enterprise are not supported)
* WordPress 7.0+ (AI Client is bundled in core), or WordPress 6.9 with the [WordPress/php-ai-client](https://github.com/WordPress/php-ai-client) package installed

**Privacy & data sharing:**

When this plugin is used, prompts and content you send through the AI Client are transmitted to OpenAI's servers for processing. Review the [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy/) and [Terms of Use](https://openai.com/policies/terms-of-use/) before enabling this provider on a production site. See the **External services** section below for full details on what data is sent and when.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/ai-provider-for-chatgpt/`, or install through the WordPress plugin browser.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Visit **Settings → ChatGPT** and follow the on-screen instructions to connect a ChatGPT account.
4. Optionally pick a default model from the same screen.

== Frequently Asked Questions ==

= How do I connect a ChatGPT account? =

On **Settings → ChatGPT**, click **Connect with ChatGPT**. The page shows a one-line `npx` command — run it on your laptop, sign in to OpenAI in the browser window that opens, and the WordPress tab connects automatically. No file copying.

If you'd rather use the Codex CLI bundle you already have, the same screen has an **Advanced** option to paste or upload `~/.codex/auth.json`.

= Does this plugin work without the WordPress AI Client? =

No. The plugin registers ChatGPT with the AI Client provider registry, so the AI Client must be available — either bundled in WordPress 7.0+ or installed as a separate package on 6.9.

= Which models are supported? =

Any chat-completion model exposed by the OpenAI Responses API for the connected account. The list is refreshed automatically.

= Does it support image generation? =

Not yet. This release covers text generation, chat, tool use, and structured output.

= How are tokens stored? =

Tokens are stored in the WordPress options table on your own site, encrypted at rest with `sodium_crypto_secretbox` keyed off `wp-config.php` salts. They are never transmitted anywhere except to OpenAI when fulfilling a request or refreshing the access token.

== External services ==

This plugin relies on OpenAI's online APIs, an external service operated by OpenAI, L.L.C. The connection is required so the WordPress AI Client can authenticate with your ChatGPT account and route requests to ChatGPT models from your site. The plugin contacts two OpenAI-operated domains: `auth.openai.com` (token refresh) and `chatgpt.com` (model list and text generation). No data is sent to OpenAI until you connect an account and one of the actions below occurs.

The plugin contacts the following endpoints:

* **`POST https://auth.openai.com/oauth/token`** — called when the stored access token is about to expire, to obtain a fresh one. The request transmits the OAuth `client_id`, the refresh token (decrypted only in transit from your WordPress options table), and the `refresh_token` grant type. No prompt or user content is sent.
* **`GET https://chatgpt.com/backend-api/codex/models`** — called from the **Settings → ChatGPT** screen (including the optional diagnostics "remote models" probe) and when the AI Client refreshes its model list. The access token (`Authorization` header), the `ChatGPT-Account-ID` header, and a `client_version` query parameter are sent so OpenAI can return the list of models available to the connected account. No prompt or user content is sent.
* **`POST https://chatgpt.com/backend-api/codex/responses`** — called whenever any plugin or theme on your site uses the WordPress AI Client to generate text with a ChatGPT model. The request includes the access token, the `ChatGPT-Account-ID` of the connected account, and the prompt/messages, system instructions, tool definitions, and any other parameters supplied by the calling code (for example, conversation history, JSON schema for structured output, or files attached to the prompt).

Your OAuth tokens are stored encrypted in your site's WordPress options table and are only transmitted to OpenAI to authenticate the requests above. The plugin does not send your data to any other third-party service.

These services are provided by OpenAI. By connecting an account and using this provider you agree to OpenAI's terms:

* Terms of Use: [https://openai.com/policies/terms-of-use/](https://openai.com/policies/terms-of-use/)
* Privacy Policy: [https://openai.com/policies/privacy-policy/](https://openai.com/policies/privacy-policy/)

== Screenshots ==

1. WordPress dashboard - Settings → ChatGPT screen.

== Changelog ==

= 0.1.2 =

* Prefixed all plugin-owned options, transients, and hooks with `halawa_chatgpt_` to avoid collisions in WordPress's shared namespaces.
* Documented every external service the plugin contacts and corrected the model-list endpoint URL in the readme.
* Made the public CLI-pairing REST route's intentionally-public design explicit (`__return_true`) while keeping its single-use-token security boundary and per-IP rate limiting.
* Added uninstall cleanup that removes the plugin's stored options and transients.

= 0.1.1 =

* Fixed a "Missing the output key" error from the Codex `/responses` stream.

= 0.1.0 =

* Initial release.
* ChatGPT text generation models with dynamic model discovery.
* Function calling and structured (JSON schema) output.
* Encrypted token storage with automatic refresh.
* Default-model selection under Settings → ChatGPT.

== Upgrade Notice ==

= 0.1.2 =
Unique `halawa_chatgpt_` prefixes, fuller external-service documentation, a clearer public pairing endpoint, and uninstall cleanup.
