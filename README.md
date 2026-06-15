<p align="center">
  <img src=".wordpress.org/banner-772x250.png" alt="AI Provider for ChatGPT" width="772">
</p>

# AI Provider for ChatGPT

A WordPress AI provider plugin that lets WP 7.0's built-in AI features call OpenAI using your **ChatGPT account** (Free / Plus / Pro) instead of an API key. Requests are routed through the same backend Codex CLI uses, so usage is billed against your existing ChatGPT subscription.

- **Requires:** WordPress 6.9+, PHP 7.4+ (with `ext-sodium` and `ext-openssl`), Node 18+ for the build / pairing CLI.
- **License:** GPL-2.0-or-later.

---

## Why this exists

The official [`ai-provider-for-openai`](https://github.com/WordPress/ai-provider-for-openai) plugin only accepts an API key, which is billed against a separate OpenAI API account — your ChatGPT Plus/Pro subscription does not count. This plugin routes requests through `chatgpt.com/backend-api/codex` using the same auth mechanism Codex CLI uses, so usage is charged against your ChatGPT plan.

## How it works

```
┌──────────────────────────┐   one-time, on any machine with a browser
│  Pairing CLI             │   $ npx @abdalsalaam/chatgpt-wp-connect <site> <token>
│  (or Codex CLI)          │   → PKCE flow on http://localhost:1455
└──────────────┬───────────┘   → posts the OAuth bundle to your site
               │
               │ one-time pairing token (10 min TTL, single-use)
               ▼
┌──────────────────────────┐   stored AES-encrypted in wp_options
│  WordPress plugin        │   - id_token, access_token, refresh_token, account_id
│  Settings → ChatGPT      │   - refresh handled from the WP server (8-day window)
│                          │   - API calls → chatgpt.com/backend-api/codex/responses
└──────────────────────────┘
```

The OAuth authorize step only works from `http://localhost:1455` because that's the only redirect URI in OpenAI's allowlist for the public Codex client. **Token refresh and API calls have no such restriction**, so once the bundle is paired, the WordPress server handles everything else on its own.

## Setup

1. **Build the admin UI** (one-time, run inside the plugin directory):

    ```sh
    composer install
    npm install
    npm run build
    ```

    This produces `build/index.js` + `build/index.asset.php` that the plugin enqueues on the settings screen. If the bundle is missing, an admin notice tells you to run the build.

2. **Activate the plugin** in WordPress.

3. **Connect.** Go to **Settings → ChatGPT** and click **Connect with ChatGPT**. The page shows a one-line command — run it on any machine that has a browser:

    ```sh
    npx @abdalsalaam/chatgpt-wp-connect https://your-site.example <pairing-token>
    ```

    The CLI opens an OpenAI sign-in page in your default browser, runs the OAuth PKCE flow on `127.0.0.1:1455`, then posts the resulting bundle to a one-time pairing endpoint on your site. The WordPress tab connects automatically — no copy/paste of secrets.

    The companion CLI source lives at [`Abdalsalaam/chatgpt-wp-connect`](https://github.com/Abdalsalaam/chatgpt-wp-connect) and is published to npm as `@abdalsalaam/chatgpt-wp-connect`.

4. **(Advanced)** If you already use Codex CLI, expand **Advanced** on the settings page and paste or upload `~/.codex/auth.json` instead.

5. The plugin now appears in the WP AI provider picker. Models exposed by the Codex backend for your plan show up automatically.

## Configuration

All of these are optional. Drop into `wp-config.php`:

```php
// Override the OAuth client_id used for token refresh.
// Defaults to the public Codex CLI client.
define( 'CHATGPT_OAUTH_CLIENT_ID', 'app_EMoamEEZ73f0CkXaXp7hrann' );
```

Filters:

| Filter | Purpose | Default |
| --- | --- | --- |
| `ai_provider_chatgpt_codex_client_version` | Codex CLI version reported on every request — determines which model set the backend exposes. | `0.133.0` |
| `ai_provider_chatgpt_bundled_models` | Fallback list of model IDs added on top of whatever `/models` returns. | GPT‑5 family + Codex Mini |
| `ai_provider_chatgpt_pair_rate_limit` | Max public-endpoint pair-redeem attempts per IP per minute. | `10` |

Action hooks:

| Action | Fires | Use case |
| --- | --- | --- |
| `ai_provider_chatgpt_paired` | After a successful CLI pairing redemption. | Audit logging, session invalidation, notifications. |

## Security model

**Token storage.** The OAuth bundle is encrypted at rest with `sodium_crypto_secretbox`, using a 32-byte key derived from your `AUTH_KEY` and `LOGGED_IN_KEY` salts. A SQL dump alone is insufficient to recover tokens. The plugin refuses to read or write tokens when those salts are missing, shorter than 32 chars, or still set to the wp-config placeholder.

**Pairing endpoint.** The CLI-facing `POST /wp-json/ai-provider-for-chatgpt/v1/connection/pair` route is public by necessity (the CLI has no admin cookie), but is gated by:

- A 256-bit single-use token issued via an admin-only endpoint, hashed (SHA-256) before storage.
- 10-minute TTL.
- Atomic single-use: redemption uses `delete_transient`'s atomic boolean return, so two concurrent redemptions cannot both win.
- Issuing a new token revokes any prior outstanding token.
- Per-IP rate limit on the public route (10/min by default, filter above).
- Generic error responses — parser internals never leak to unauthenticated callers.

**Token refresh.** Refresh happens server-side every 8 days (or reactively on a 401). The refresh token rotates on every exchange.

## Troubleshooting

- **"Pairing token expired" / countdown reached 0:0.** The token is valid for 10 minutes. Click **Try again** to mint a new one. Only the most-recently issued token is valid.
- **"Could not copy the command".** Browser blocked clipboard access (common on non-HTTPS admin URLs). Select the displayed command manually.
- **HTTP 429 on `/connection/pair`.** Too many redemption attempts from one IP in the last minute. Wait, or raise the cap with the `ai_provider_chatgpt_pair_rate_limit` filter.
- **Models list is empty / "registry check failed".** Open **Settings → ChatGPT → Diagnostics**, click **Probe Codex backend**. If the probe returns an HTTP 4xx, the stored access token is stale; click **Refresh tokens** or re-pair.
- **Admin notice "the admin UI bundle is missing".** Run `npm install && npm run build` in the plugin directory.

## Reference

| Concern | This plugin |
| --- | --- |
| Auth | OAuth bundle obtained via the companion `@abdalsalaam/chatgpt-wp-connect` CLI (Codex-style PKCE flow), or pasted from Codex CLI's `~/.codex/auth.json`. |
| Refresh | `POST auth.openai.com/oauth/token` (refresh_token grant) from the WP server, every 8 days. |
| API base | `https://chatgpt.com/backend-api/codex` |
| Headers | `Authorization: Bearer <access_token>` + `ChatGPT-Account-ID: <uuid>` (+ `X-OpenAI-Fedramp: true` when applicable). |
| Billing | Your ChatGPT subscription. |
| Data residency | Consumer ChatGPT — **no API DPA**. |

## Disclosures

- **Reused first-party client_id.** The OAuth consent screen will say "Codex CLI" because that is the OpenAI-owned app whose client_id is used. If OpenAI revokes or rotates that client, the plugin breaks the same day.
- **No API DPA.** Prompts may be used by OpenAI for training unless the connected ChatGPT account has training opt-out enabled.
- **Only Free / Plus / Pro tiers** are eligible. Business / Edu / Enterprise are not.
- **Image generation is not supported** on the Codex backend; use the standard `ai-provider-for-openai` plugin with an API key if you need DALL·E or gpt-image.
- Two long-lived refresh tokens (one on your laptop in `~/.codex/auth.json`, one on the WP server) coexist after import. Running `codex login` again on your laptop does not invalidate the WP-side token.

## Development

```sh
composer install            # PHP deps + linters
npm install                 # JS deps
npm start                   # watch-mode webpack build
composer check-php          # PHPCS (PSR-12 + WP standards)
composer check-security     # PHPCS security profile
composer phpstan            # PHPStan level 2
```

Source layout:

```
src/
├── Admin/              admin menu + asset enqueue
├── Authentication/    OAuth bundle storage, pairing tokens, request signer
├── Cache/              PSR-16 ↔ transient adapter
├── Metadata/           Codex /models directory
├── Models/             text-generation model targeting /responses
├── OAuth/              JWT claims reader, refresh exchange
├── Provider/           the AbstractApiProvider implementation
└── Rest/               REST controllers for the React admin UI
client/                React admin UI (built into build/)
```

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE) if shipped with the plugin.
