<p align="center">
  <img src=".wordpress.org/banner-772x250.png" alt="AI Provider for ChatGPT" width="772">
</p>

# AI Provider for ChatGPT

A WordPress AI provider plugin that lets the WordPress AI Client call OpenAI models with your **ChatGPT plan** instead of an API key. You connect with OpenAI's [Sign in with ChatGPT](https://developers.openai.com/siwc/token-sharing-open-source) flow for open-source apps; requests go to `https://api.openai.com/v1` and are billed to your ChatGPT Plus, Pro or Go plan.

- **Requires:** WordPress 6.9+, PHP 7.4+ (with `ext-sodium` and `ext-openssl`), a ChatGPT Plus, Pro or Go account. Node 18+ only to build the admin UI.
- **License:** GPL-2.0-or-later.

---

## Why this exists

The official [`ai-provider-for-openai`](https://github.com/WordPress/ai-provider-for-openai) plugin only accepts an API key, which is billed to a separate OpenAI API account; your ChatGPT plan does not count. Sign in with ChatGPT lets an app use the plan of the person who signs in, so this plugin bills AI Client requests to that plan.

## How it works

```
Settings > ChatGPT (browser)         WordPress server                       OpenAI
----------------------------         ----------------                       ------
"Continue with ChatGPT"  --------->  start sign-in: state, nonce and PKCE
                                     verifier kept for 10 minutes
opens the authorize page  ---------------------------------------------->  auth.openai.com
                                                                            (account, agent name,
                                                                             consent)
lands on http://127.0.0.1:1455/auth/callback?code=...  (a page that cannot load)
admin pastes that address  ------->  check the address and state
                                     exchange the code  -------------------> auth.openai.com
                                     verify the ID token (JWKS)  <---------- auth.openai.com
                                     require the plan scope, store tokens
                                     encrypted
AI Client request  --------------->  Bearer token, refresh when due  -----> api.openai.com/v1
                                                                            /responses, /models
weekly cron  --------------------->  refresh under a lock  ---------------> auth.openai.com
"Disconnect"  -------------------->  revoke, then clear  -----------------> auth.openai.com
```

On the first sign-in OpenAI registers the plugin as an agent named "AI Provider for ChatGPT" on your account and issues a client id for this site. The browser never sees a token: the pasted address only carries a one-time code that is useless without the PKCE verifier the server kept.

## Setup

1. **Build the admin UI** (one time, inside the plugin directory):

    ```sh
    composer install
    npm install
    npm run build
    ```

    This produces `build/index.js` and `build/index.asset.php`, which the settings screen loads. If the bundle is missing, an admin notice tells you to run the build.

2. **Activate the plugin** in WordPress.

3. **Connect.** Go to **Settings > ChatGPT** and click **Continue with ChatGPT**. Sign in and approve in the tab that opens. Your browser then lands on a page at `http://127.0.0.1:1455/auth/callback` that cannot load; that is expected. Copy the full address from the address bar, paste it on the settings screen and click **Connect**. The sign-in attempt is valid for 10 minutes.

4. The plugin now appears in the AI provider picker with the models your plan offers.

Accounts without plan usage (Free) are refused at sign-in with a message, and nothing is saved.

### Upgrading from 0.1.x

0.2.0 removes the Codex-based connection. On the first admin page load after the update, the old connection is deleted and administrators see a notice to sign in again. The companion command line tool `@abdalsalaam/chatgpt-wp-connect` is no longer needed and is deprecated on npm.

## Configuration

Optional, in `wp-config.php`:

```php
// Use a client id registered with OpenAI instead of registering this site as an agent.
define( 'CHATGPT_OAUTH_CLIENT_ID', 'oaiapp_...' );
```

When this constant is set, sign-in uses that client id and skips the agent name step.

Filters:

| Filter | Purpose | Default |
| --- | --- | --- |
| `halawa_chatgpt_redirect_uri` | Redirect address sent with sign-in. Only change it for a client id registered with a different redirect. | `http://127.0.0.1:1455/auth/callback` |
| `halawa_chatgpt_bundled_models` | Model ids listed when the model list request fails or returns no listed model. | `gpt-6-luna`, `gpt-5.6-terra`, `gpt-5.6-luna` |

## REST routes

Namespace `ai-provider-for-chatgpt/v1`. Every route requires the `manage_options` capability and the REST nonce; there is no public route.

| Route | Method | Purpose |
| --- | --- | --- |
| `/connection` | GET | Connection status |
| `/connection` | DELETE | Revoke at OpenAI and disconnect |
| `/connection/sign-in` | POST | Start a sign-in, returns the authorize URL |
| `/connection/callback` | POST | Finish a sign-in with the pasted `callback_url` |
| `/connection/refresh` | POST | Refresh the tokens now |
| `/diagnostics` | GET | Provider and connection diagnostics |
| `/diagnostics/remote-models` | GET | Probe `GET /v1/models` |
| `/cache` | DELETE | Clear the cached model list |

## Security model

- **Token storage.** The token record is encrypted at rest with `sodium_crypto_secretbox`, using a 32-byte key derived from your `AUTH_KEY` and `LOGGED_IN_KEY` salts, so a database dump alone does not reveal the tokens. The plugin refuses to read or write tokens when those salts are missing, shorter than 32 characters, or still set to the `wp-config.php` placeholder.
- **Sign-in.** State, nonce and the PKCE verifier stay on the server, one pending sign-in per administrator, valid for 10 minutes and usable once. The pasted address must match the expected redirect exactly. The ID token is verified against OpenAI's published keys (signature, issuer, audience, expiry, nonce) before anything is saved.
- **Refresh.** Tokens are refreshed when the access token is near expiry and once a week by cron, under a database lock so two requests never use the same refresh token. If OpenAI ends the session, the connection is marked as needing a new sign-in and no further requests are sent.
- **Disconnect.** The refresh token is revoked at OpenAI before the local record is deleted.
- **Requests.** Every `/responses` request is sent with `store: false`.

## Troubleshooting

- **The page after approving cannot load.** Expected: copy its address and paste it on the settings screen.
- **"Paste the full address of the page you landed on."** The pasted text is not the `http://127.0.0.1:1455/auth/callback?...` address. Copy the whole address bar, not the ChatGPT page.
- **The sign-in attempt expired, or "No sign-in in progress".** Each attempt is valid for 10 minutes and once. Click **Start again**.
- **"Your ChatGPT account did not grant plan usage".** Plan usage needs ChatGPT Plus, Pro or Go.
- **"ChatGPT session ended".** OpenAI ended the session (for example after you disconnected the app in ChatGPT). Sign in again under **Settings > ChatGPT**.
- **Usage limit reached.** Review your usage at [chatgpt.com/settings/usage](https://chatgpt.com/settings/usage).
- **The model picker shows only the fallback models.** The SDK caches the model list for 24 hours, so after a failed `/v1/models` request the fallback list can stay until the cache is cleared. Clear it under **Settings > ChatGPT**, **Developer tools** menu, **Clear cached model list**.
- **Admin notice "the admin UI bundle is missing".** Run `npm install && npm run build` in the plugin directory.

## Development

```sh
composer install            # PHP deps and linters
npm install                 # JS deps, including wp-env
npm start                   # watch-mode webpack build
npx wp-env start            # local WordPress with the plugin (Docker)
composer test               # PHPUnit on wp-env
composer check-php          # PHPCS (WordPress standards)
composer check-security     # PHPCS security profile
composer check-l18n         # PHPCS text domain and prefixes
composer phpstan -- --memory-limit=2G
npm run typecheck && npm run lint:js
```

Source layout:

```
src/
  Admin/           settings page, assets, upgrade routine and notice
  Authentication/  encrypted token store, request authentication
  Cache/           PSR-16 cache on transients for the SDK model list
  Metadata/        /v1/models directory
  Models/          text generation on /v1/responses, error messages
  OAuth/           PKCE, callback URL, JWKS verifier, token endpoint, sign-in flow, refresher
  Provider/        the AI Client provider
  Rest/            REST controllers for the React admin UI
client/            React admin UI (built into build/)
tests/phpunit/     PHPUnit tests, HTTP mock and fixtures
```

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE) if shipped with the plugin.
