# Troubleshooting

[← Documentation index](../README.md#documentation)

Three places to look, in order:

1. **Tools → Site Health → Status.** Four plugin tests run there, and they catch
   most misconfigurations without you reading a log.
2. **Zoho Recruit Jobs → Sync Logs.** Every run, with counts and error detail.
3. **The dashboard.** Connection state, last successful sync, next scheduled
   sync, job counts.

From the command line, `wp jszr status` shows most of the same in one table.

---

## "Invalid OAuth Scope — Scope does not exist"

Zoho refuses the authorization request when **any one** of the requested
permissions is not one it recognises for your account, and its error page does
not say which one. Nothing is wrong with your Client ID, Client Secret or
redirect URI when you see this.

The plugin asks for two permissions:

| Scope | Needed for |
| --- | --- |
| `ZohoRecruit.modules.jobopenings.READ` | Reading job openings. **Required.** |
| `ZohoRecruit.settings.fields.READ` | Filling the Field Mapping dropdowns from your account |

The second is a convenience. Without it the mapping screen falls back to the
standard Zoho Recruit field names and everything else — syncing, publishing,
the API — works unchanged.

**The fix:** on **Settings → Connection**, clear the **Permissions** field down
to just the first scope, save, and connect again.

```
ZohoRecruit.modules.jobopenings.READ
```

Some accounts accept a broader form instead. If the minimum above is also
refused, try one of these, in order of how much access they grant:

```
ZohoRecruit.modules.jobopenings.ALL
ZohoRecruit.modules.ALL
```

To check a scope without changing anything, open this in a browser, replacing
the client ID and redirect URI with your own. Zoho either shows its consent
screen — meaning the scope is fine — or the error. You can close the tab
without approving either way.

```
https://accounts.zoho.com/oauth/v2/auth?response_type=code&access_type=offline
  &client_id=YOUR_CLIENT_ID
  &redirect_uri=YOUR_REDIRECT_URI
  &scope=ZohoRecruit.modules.jobopenings.READ
```

Use the accounts domain for your data centre — `accounts.zoho.in`,
`accounts.zoho.eu` and so on — and put it all on one line.

Once field discovery is unavailable, **Field Mapping → Reload fields from Zoho**
will report a scope error. That is expected, and the bundled field list is still
there to map against.

## Site Health tests

### Zoho Recruit connection

| Result | Meaning |
| --- | --- |
| Connected | A valid refresh token is stored |
| Credentials have not been entered | Add the Client ID and Secret |
| Not connected | Credentials are stored but OAuth was never completed |
| **Stored credentials can no longer be decrypted** | The security keys in `wp-config.php` changed. Reconnect — see below |
| **Syncing is paused after repeated failures** | The circuit breaker opened. Reconnect to reset it |

### Zoho Recruit scheduled sync

Confirms `jszr_scheduled_sync` is on the schedule and reports when it next
runs. Warns when `DISABLE_WP_CRON` is set, since scheduled syncing then depends
on a system cron job you have to configure yourself.

If it says "not scheduled", re-save **Settings → Sync** — saving reschedules.

### Zoho Recruit last sync

Warns when no sync has ever succeeded, or when the last success is older than
the configured frequency by a wide margin.

### Zoho Recruit environment

Checks that an encryption backend (libsodium or OpenSSL) is available, that the
site is reachable over HTTPS for the OAuth callback, and that the job slug does
not collide with another post type or a page.

---

## Connection problems

### "Stored Zoho credentials can no longer be decrypted"

The tokens are encrypted with a key derived from `AUTH_KEY`, `SECURE_AUTH_KEY`
and `LOGGED_IN_KEY`. Changing those — a fresh `wp-config.php`, a security
plugin rotating salts, a staging site copied without them — makes the stored
tokens unreadable.

The plugin notices and stops rather than failing confusingly. Reconnect on
**Settings → Connection**. Your synced jobs are untouched.

### "Syncing is paused after repeated failures"

After five consecutive authentication failures the circuit breaker opens:
scheduled syncs stop even trying, so the site does not hammer Zoho or fill the
log with the same line. Fix the cause, then save credentials again or
reconnect — either resets it.

### `redirect_uri_mismatch`

The redirect URI registered at Zoho is not byte-identical to the one the plugin
shows. Watch for `http` versus `https`, a missing or extra `www.`, and a
trailing slash. Copy it from **Settings → Connection**, do not retype it.

### `invalid_client`

Wrong Client ID or Secret — or, far more often, **the right credentials against
the wrong data center**. A client created in the India console does not
authenticate against `accounts.zoho.com`.

### `OAUTH_SCOPE_MISMATCH`

The connection was made before a scope was added. Disconnect and reconnect.

### The callback says the request could not be verified

The `state` token expired (it lives 15 minutes) or you started the flow as one
user and finished as another. Start again from **Connect to Zoho Recruit**.

---

## Sync problems

### Nothing syncs, and the log says every record was skipped

Almost always the **publish filter**. **Settings → Sync → Only sync jobs marked
as published in Zoho** is on by default and reads the field named in **Publish
field**, `Publish_in_Career_Website` unless you changed it.

If your account calls that field something else, the plugin reads a field that
does not exist, decides nothing is published, and skips everything. Run
`wp jszr fields` (or **Reload fields from Zoho**) to find the real API name, or
turn the setting off.

A **dry run** shows this in seconds without writing anything.

### The sync stops partway

Look at the run's state in the sync log:

| State | Meaning |
| --- | --- |
| `running` | Still going. Large accounts take several cron ticks |
| `completed` | Finished, every page succeeded |
| `partial` | Finished, but the deactivation step was skipped — see below |
| `failed` | Stopped on an error. The message says which |
| `cancelled` | Someone pressed Cancel |

A run stuck in `running` with no progress usually means cron is not firing.
Check the Site Health cron test and try `wp cron event run --due-now`.

An interrupted run resumes from its checkpoint — it does not restart from page
one, and it never re-creates jobs it already wrote.

### A sync finished "partial" and nothing was deactivated

The safety threshold did its job. If a completed full sync would deactivate
more than **Settings → Sync → Deactivation safety threshold** percent of your
jobs (50% by default), the plugin refuses, marks the run partial and emails the
administrator.

This is what protects a careers page from a half-answered API. Investigate
before overriding: usually the publish field changed, a filter was added, or
Zoho returned a truncated result. Raising the threshold to 100 disables the
guard.

### Jobs went missing after an incremental sync

They should not. An incremental sync returns only changed records, and the
plugin never treats absence from an incremental response as deletion —
only a **fully successful full sync** can deactivate a job for being missing.

If jobs did disappear, check the orphan setting: **Settings → Sync → Deleted in
Zoho** may be set to Trash or Delete. Draft is the default and the safe choice.

### Duplicate jobs

Should be impossible: every write resolves the post through an indexed lookup
on the Zoho record ID, behind a named lock. If you see duplicates, one copy is
almost certainly a job created by hand in WordPress rather than imported —
check for a Zoho ID in the job's metabox. Manual jobs are deliberately left
alone by every sync.

### "Another sync process holds the lock"

A sync is already running, or one crashed and left the lock behind. Stale locks
clear themselves; to force it, press **Cancel running sync** on the dashboard
or run `wp jszr cancel`.

### Dates are a day out

Zoho sends ISO-8601 timestamps with the account's UTC offset. The plugin
converts them using the **site** timezone from **Settings → General**. If dates
look shifted, that setting is probably on a UTC offset rather than a named
timezone — set an actual city and re-sync.

---

## Zoho API errors

These are translated into readable messages before they reach the log.

| Zoho code | Meaning | Fix |
| --- | --- | --- |
| `INVALID_TOKEN` | The access token is invalid | Reconnect |
| `AUTHENTICATION_FAILURE` | Zoho could not authenticate the request | Reconnect |
| `OAUTH_SCOPE_MISMATCH` | A required scope is missing | Disconnect and reconnect |
| `INVALID_MODULE` | The module does not exist in this account | Check the edition; filter `jszr_module_name` |
| `NO_PERMISSION` | The connected user cannot read job openings | Grant that user access in Zoho |
| `INVALID_DATA` | Zoho rejected a parameter | Usually a mapping pointing at a field that no longer exists |
| `INVALID_URL_PATTERN` | Wrong endpoint for this account | Almost always the wrong data center |
| `TOO_MANY_REQUESTS` | Rate limited | Handled automatically — see below |
| `INTERNAL_ERROR` | Zoho's side | Retried automatically |

### HTTP statuses

`204` (no records) and `304` (not modified) are **successes**, not errors — an
empty account or an incremental sync with nothing new is a normal outcome.

`429` is honoured: the plugin waits for `Retry-After` when Zoho sends one, and
otherwise backs off exponentially, up to a bounded number of attempts. Transient
`5xx` and timeouts get the same treatment. There is no infinite retry anywhere.

---

## Display problems

### `/jobs/` returns 404

Permalinks. **Settings → Permalinks → Save Changes** flushes the rules. This is
the usual symptom after changing the job slug or activating with plain
permalinks.

### The job archive shows the theme's blog layout

Your theme has its own `archive-zoho_job.php`, which correctly wins. To use the
plugin's layout, remove it or copy the plugin's `archive.php` into
`yourtheme/jobs-sync-for-zoho-recruit/archive.php` and edit that.

### A template override is ignored

The directory name has to be exactly `jobs-sync-for-zoho-recruit/` inside your
theme. A bare `card.php` at the theme root is deliberately not picked up.

### Jobs do not appear in a listing

Only `active` jobs are listed. Check the job's normalised status in its
metabox, then the status mapping, then whether a closing date has passed.

### The listing shows stale results

Responses cache for **Settings → Public API → Cache TTL** seconds. Syncs and
job edits clear it; a page-cache plugin in front of WordPress may not. Set the
TTL to `0` while debugging.

### The block shows nothing in the editor

It renders on the server. If the preview is empty but the front end is fine,
the editor's REST request is being blocked — check the browser console and any
security plugin filtering `/wp-json/`.

---

## Debug logging

**Settings → Advanced → Debug logging**, with `WP_DEBUG` on, writes plugin
events to WordPress's `debug.log` in addition to the sync log.

Tokens, secrets and passwords are scrubbed before anything is written, to both
destinations. There is no configuration that will make the plugin log a
credential.

Turn debug logging off when you are done — it is verbose.

## Reporting a problem

Useful to include:

- **Tools → Site Health → Info → Jobs Sync for Zoho Recruit**, which reports
  the version, data center, module, encryption backend, last sync and job
  counts.
- The failing run's row from the sync log, with its error detail.
- WordPress and PHP versions.

Never include the client secret or a token. Nothing in the Site Health panel or
the sync log contains one.
