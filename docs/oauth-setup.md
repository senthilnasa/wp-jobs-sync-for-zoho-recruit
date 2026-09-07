# OAuth setup

[← Documentation index](../README.md#documentation)

The plugin authenticates with Zoho Recruit using OAuth 2.0. You never paste an
access token into WordPress: you register a client once at Zoho, approve the
connection in your browser, and the plugin keeps a refresh token from then on.

## 1. Copy the redirect URI

Open **Zoho Recruit Jobs → Settings → Connection**. The redirect URI is shown
there, ready to copy. It is always:

```
https://example.com/wp-admin/admin-post.php?action=jszr_oauth_callback
```

Two things matter:

- **It must be the exact string.** Zoho compares it character for character,
  including the scheme, the `www.` (or its absence) and the trailing query.
- **It should be HTTPS.** Zoho rejects plain HTTP redirect URIs for anything
  other than `localhost`. Site Health warns you if the site is not on HTTPS.

If your site lives at a URL that differs from what WordPress reports — behind a
reverse proxy, say — filter it:

```php
add_filter( 'jszr_redirect_uri', function () {
	return 'https://careers.example.com/wp-admin/admin-post.php?action=jszr_oauth_callback';
} );
```

## 2. Create the client at Zoho

1. Go to <https://api-console.zoho.com/> and sign in with the account that owns
   the Zoho Recruit data. Use the console for **your** data center — if your
   Recruit account is on `zoho.in`, use `api-console.zoho.in`.
2. **Add Client → Server-based Applications.**
3. Fill in:

   | Field | Value |
   | --- | --- |
   | Client Name | Anything, e.g. "Careers site sync" |
   | Homepage URL | `https://example.com` |
   | Authorized Redirect URIs | The redirect URI you copied in step 1 |

4. Create it, then copy the **Client ID** and **Client Secret**.

The client secret is a credential for your whole Zoho account. Treat it the way
you would a database password.

## 3. Enter the credentials in WordPress

Back in **Settings → Connection**:

1. Paste the Client ID and Client Secret.
2. Choose your **data center**. This is the single most common thing to get
   wrong — a client created on `zoho.in` will not authenticate against
   `accounts.zoho.com`, and the error Zoho returns is not always obvious.
3. Save.

### Supported data centers

| Key | Accounts host | Recruit API host |
| --- | --- | --- |
| `com` | `accounts.zoho.com` | `recruit.zoho.com` |
| `eu` | `accounts.zoho.eu` | `recruit.zoho.eu` |
| `in` | `accounts.zoho.in` | `recruit.zoho.in` |
| `au` | `accounts.zoho.com.au` | `recruit.zoho.com.au` |
| `jp` | `accounts.zoho.jp` | `recruit.zoho.jp` |
| `ca` | `accounts.zohocloud.ca` | `recruit.zohocloud.ca` |
| `uk` | `accounts.zoho.uk` | `recruit.zoho.uk` |
| `sa` | `accounts.zoho.sa` | `recruit.zoho.sa` |

Add a region Zoho introduces later with the `jszr_data_centers` filter — see
[Hooks](hooks.md).

## 4. Connect

Click **Connect to Zoho Recruit**. You are sent to Zoho, you approve the
request, and Zoho sends you back to the redirect URI. The plugin exchanges the
one-time code for a refresh token and an access token, and the Connection tab
now shows the connected state and the token expiry.

### Scopes requested

The plugin asks for exactly two, both read-only:

| Scope | Why |
| --- | --- |
| `ZohoRecruit.modules.jobopenings.READ` | Read job openings — the records it syncs |
| `ZohoRecruit.settings.fields.READ` | Read the Job Opening field list, so the mapping screen can offer your real fields |

It never requests write access, and it never requests access to candidates,
interviews or any other module. If you need to change the list — a custom
module, for instance — use the `jszr_oauth_scopes` filter, and remember that
changing scopes requires reconnecting.

## How the callback is protected

- The connect button is behind the `manage_zoho_recruit` capability and a nonce.
- Starting the flow stores a random `state` value in a 15-minute transient,
  keyed by its own hash and bound to the user who started it.
- The callback rejects a request whose `state` is missing, expired, unknown or
  belongs to a different user, and logs the rejection.
- The `error` parameter Zoho can return on the callback is decoded into a
  readable message rather than being ignored.

## Where the secrets live

| Value | Storage |
| --- | --- |
| Client ID | `jszr_settings` option, plain (it is not secret) |
| Client Secret | Encrypted in the `jszr_tokens` option |
| Refresh token | Encrypted in the `jszr_tokens` option |
| Access token | Encrypted in the `jszr_tokens` option, with its expiry |

Encryption uses libsodium's `crypto_secretbox` when the extension is available,
and AES-256-CBC with an HMAC-SHA-256 tag through OpenSSL otherwise. The key is
derived from the site's `AUTH_KEY`, `SECURE_AUTH_KEY` and `LOGGED_IN_KEY`.

Because the key comes from the salts, **rotating your salts invalidates the
stored tokens.** The plugin notices (it stores a fingerprint of the key
alongside the ciphertext), stops trying to use them, and asks you to reconnect
rather than failing in a confusing way.

Secrets are never rendered into a settings field — the inputs show a masked
placeholder — and the logger scrubs anything that looks like a token before a
line is written.

## Defining credentials in `wp-config.php`

For sites where configuration is deployed rather than typed, define any of:

```php
define( 'JSZR_CLIENT_ID',     '1000.XXXXXXXXXXXXXXXXXXXXXXXX' );
define( 'JSZR_CLIENT_SECRET', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
define( 'JSZR_DATA_CENTER',   'in' );
```

Any value defined as a constant wins, and its settings field becomes read-only
with a note explaining why. The OAuth *tokens* are still obtained through the
browser flow and stored in the database — a refresh token cannot be
pre-provisioned.

## Token lifecycle

Access tokens last an hour. The plugin refreshes one automatically when it is
within the expiry margin, behind a short-lived lock so two concurrent requests
cannot both spend the refresh token. If an API call still comes back `401`, it
forces exactly one refresh and retries once — never in a loop.

### When refreshing fails

A refresh token stops working if it is revoked at Zoho, if the client is
deleted, or if the salts changed. When that happens the plugin:

1. Records the failure and increments a counter.
2. Shows a persistent admin notice asking you to reconnect.
3. After five consecutive authentication failures, opens a **circuit breaker**:
   scheduled syncs stop attempting to authenticate at all, so the site does not
   hammer Zoho or fill the log with the same error.
4. Optionally emails the address in **Settings → Sync**.

Saving credentials again, or reconnecting, resets the breaker.

## Disconnecting

**Settings → Connection → Disconnect**, or `wp jszr disconnect`. The plugin
calls Zoho's revoke endpoint on a best-effort basis and then deletes every
stored token from the database, whether or not the revoke call succeeded.

Disconnecting does not touch the jobs already synced. They stay published until
you change them.

## Troubleshooting the connection

| Symptom | Cause |
| --- | --- |
| `invalid_client` | Client ID or secret is wrong, or belongs to a different data center |
| `redirect_uri_mismatch` | The URI at Zoho is not byte-identical to the one shown in the settings |
| `invalid_code` | The authorization code was reused or has expired — start again |
| `OAUTH_SCOPE_MISMATCH` on a sync | Scopes were changed after connecting; reconnect |
| `INVALID_TOKEN` on every call | The refresh token was revoked; reconnect |
| "The connection request could not be verified" | The `state` expired (15 minutes) or you started the flow as a different user |

More in [Troubleshooting](troubleshooting.md).
