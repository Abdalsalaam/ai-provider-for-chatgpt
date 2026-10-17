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
"Disconnect"  -------------------->  revoke, keep registration  ---------> auth.openai.com
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

3. **Connect.** Go to **Settings > ChatGPT**. The screen lists the steps under **How connecting works**:

    1. Click **Continue with ChatGPT**. A ChatGPT sign-in page opens in a new tab.
    2. Sign in, keep the agent name, and approve **Use your ChatGPT plan**.
    3. The tab then shows an error such as "This site can't be reached" for 127.0.0.1. That is expected: nothing is wrong.
    4. Copy the full address from that tab's address bar, come back to the settings tab, paste it (or click **Paste from clipboard**) and click **Connect**. The address starts with `http://127.0.0.1:1455/auth/callback`.

    The sign-in attempt is valid for 10 minutes; **Start again** begins a new one.

4. The plugin now appears in the AI provider picker with the models your plan offers.

Accounts without plan usage (Free) are refused at sign-in with a message, and nothing is saved.

### Upgrading from 0.1.x

1.0.0 replaces the connection method of 0.1.x. On the first admin page load after the update, the old connection is deleted and administrators see a notice to sign in again. The companion command line tool `@abdalsalaam/chatgpt-wp-connect` is no longer needed and is deprecated on npm.

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
| `/connection` | DELETE | Revoke at OpenAI and sign out, keeping the registration; `forget: true` also removes the registration |
| `/connection/sign-in` | POST | Start a sign-in, returns the authorize URL |
| `/connection/callback` | POST | Finish a sign-in with the pasted `callback_url` |
| `/connection/refresh` | POST | Refresh the tokens now |
| `/diagnostics` | GET | Provider and connection diagnostics |
| `/diagnostics/remote-models` | GET | Probe `GET /v1/models` |
| `/cache` | DELETE | Clear the cached model list |

## Security model

- **No developer access.** This plugin runs only on your website. The plugin developer never receives your ChatGPT sign-in, your tokens or anything you send, and has no way to see or use your account. The site talks to OpenAI directly and contacts no other service. The connection cannot read your ChatGPT conversations or memories ([OpenAI help](https://help.openai.com/en/articles/20001542-using-your-chatgpt-plan-in-other-apps-and-sites); the [Sign in with ChatGPT docs](https://developers.openai.com/siwc/token-sharing-open-source) say it "does not grant access to their ChatGPT conversations or other account context").
- **Token storage.** The token record is encrypted at rest with `sodium_crypto_secretbox`, using a 32-byte key derived from your `AUTH_KEY` and `LOGGED_IN_KEY` salts, so a database dump alone does not reveal the tokens. The plugin refuses to read or write tokens when those salts are missing, shorter than 32 characters, or still set to the `wp-config.php` placeholder.
- **Sign-in.** State, nonce and the PKCE verifier stay on the server, one pending sign-in per administrator, valid for 10 minutes and usable once. The pasted address must match the expected redirect exactly. The ID token is verified against OpenAI's published keys (signature, issuer, audience, expiry, nonce) before anything is saved.
- **Refresh.** Tokens are refreshed when the access token is near expiry and once a week by cron, under a database lock so two requests never use the same refresh token. If OpenAI ends the session, the connection is marked as needing a new sign-in and no further requests are sent.
- **Disconnect.** The refresh token is revoked at OpenAI, then the tokens are deleted. The registration (issued client id, site id, and the account's id, email and name) is kept, so signing in again reuses it for the same account. **Use a different ChatGPT account** also deletes the site's copy of the registration (the issued client id and the account's id, email and name); the app stays listed under ChatGPT **Settings > Security and login > Sign in with ChatGPT**, where you can disconnect it.
- **Requests.** Every `/responses` request is sent with `store: false`.

## Troubleshooting

- **"This site can't be reached" after approving.** Expected: the tab is on `http://127.0.0.1:1455/auth/callback`, where nothing is listening. Copy its full address and paste it on the settings screen.
- **"Paste the full address of the page you landed on."** The pasted text is not the `http://127.0.0.1:1455/auth/callback?...` address. Copy the whole address bar, not the ChatGPT page.
- **"Could not read the clipboard."** The browser denied clipboard access. Paste the address with Ctrl+V or Cmd+V. On sites without HTTPS the button is not shown.
- **No ChatGPT tab opened** (for example, the browser blocked the pop-up). Under "Didn't see the ChatGPT tab?", click **Open ChatGPT sign-in**. It reopens the same sign-in page; it does not start a new attempt or reset the 10-minute countdown. Once the attempt has expired the button is disabled; click **Start again**.
- **The sign-in attempt expired, or "No sign-in in progress".** Each attempt is valid for 10 minutes and once. Click **Start again**.
- **"Your ChatGPT account did not grant plan usage".** Plan usage needs ChatGPT Plus, Pro or Go.
- **"That is a different ChatGPT account."** The site keeps the registration of the account it was connected to, and a new sign-in must use that account. To connect another account, click **Use a different ChatGPT account** on the settings page: it revokes any stored tokens and deletes the site's copy of the registration (the issued client id and the account's id, email and name); the app stays listed under ChatGPT **Settings > Security and login > Sign in with ChatGPT**, where you can disconnect it. Then sign in with the other account.
- **"ChatGPT session ended".** OpenAI ended the session (for example after you disconnected the app under ChatGPT **Settings > Security and login > Sign in with ChatGPT**). Sign in again under **Settings > ChatGPT**.
- **Usage limit reached.** Review your usage and per-app limits at [chatgpt.com/settings/usage](https://chatgpt.com/settings/usage).
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
