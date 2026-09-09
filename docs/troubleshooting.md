# Troubleshooting

[← Documentation index](../README.md#documentation)

Start with the check button: **Zoho Recruit Jobs → Sync Logs → Run check**. It
tests everything the plugin depends on and tells you which part is broken,
including a live call to Zoho when the site is connected. See
[Run the check first](#run-the-check-first) below.

After that, three places to look, in order:

1. **Tools → Site Health → Status.** Four plugin tests run there, and they catch
   most misconfigurations without you reading a log.
2. **Zoho Recruit Jobs → Sync Logs.** Every run, with counts and error detail.
3. **The dashboard.** Connection state, last successful sync, next scheduled
   sync, job counts.

From the command line, `wp jszr status` shows most of the same in one table.

---

## Run the check first

**Zoho Recruit Jobs → Sync Logs → Run check** works through, in order:

| Check | What a failure means |
| --- | --- |
| Secrets can be encrypted | No libsodium or OpenSSL, or the security salts are missing from `wp-config.php`. Credentials cannot be stored safely until this passes. |
| Credentials entered | No Client ID and Secret yet. Add them on the Settings screen. |
| Connected to Zoho | No refresh token. Run the connection flow. |
| Security keys unchanged | The salts in `wp-config.php` changed after the tokens were stored, so they can no longer be decrypted. Reconnect. |
| Automatic syncing not paused | The circuit breaker tripped after repeated authentication failures. Fix the cause, then reconnect or start a sync by hand. |
| Database tables present | A table is missing. Deactivate and reactivate the plugin. |
| Sync scheduled | No cron event. Re-saving the sync frequency reschedules it. |
| WP-Cron available | `DISABLE_WP_CRON` is set, so a system cron must call `wp-cron.php`. |
| Access token obtained | The refresh token is being refused. See the OAuth errors below. |
| Job openings readable | The connection works but the module cannot be read — usually a scope or a permissions problem on the Zoho user. |
| Field discovery | A warning, not a failure. Without `ZohoRecruit.settings.fields.READ` the mapping screen falls back to the standard field names and everything else keeps working. |

**Download report (.txt)** saves the same thing as a text file, along with the
environment, the settings, the field mapping, the last ten runs and the last
sixty log entries. Everything in it passes through the same scrubber the logs
use, so tokens, secrets and client IDs are removed and the notification address
is left out — it is safe to send to whoever is helping you.

---

## No apply button appears on any job

Zoho Recruit's Job Openings API does not send a link to the public job posting.
There is no field for it. `Website` on a job opening is the client's own site,
it is normally empty, and the plugin's default mapping points the application
URL at it -- so on most accounts every job syncs with an empty application URL
and the apply button has nothing to link to and is not rendered.

The career site does have a stable address, built from the record ID the sync
already stores:

```
https://<your career site>/jobs/Careers/<record id>/
```

Set **Settings → Frontend → Zoho career site address** to the home page of your
career site and the plugin builds that link for every synced job. It may be a
`zohorecruit.com` subdomain, `.zohorecruit.in` on an Indian account, or your own
domain -- `https://careers.example.edu.in` is just as valid. If you do not know
the address, open Zoho Recruit and go to Setup → Career Site; it is also
whatever you already link to from your own site.

### Changing the shape of the link

**Job posting path** sets what comes after that address. The default is:

```
/jobs/Careers/{zoho_id}/
```

Five placeholders are available: `{zoho_id}`, `{job_code}`, `{slug}`, `{title}`
and `{id}`. So a career site arranged differently is a settings change, not a
code change.

The record ID is the only part Zoho actually resolves the posting from, which is
why the default asks for nothing else. Anything after it is decoration -- a live
career site returns the same job for the right title, a deliberately wrong one
and none at all. If you would rather candidates saw a readable link, use:

```
/jobs/Careers/{zoho_id}/{title}
```

`{title}` is derived from the job title, not the WordPress slug, so a job
WordPress had to de-duplicate to `content-writer-3` still links as
`content-writer`.

The path is a path, not a URL. A scheme or a `//host` prefix is stripped rather
than followed, so a mistake here cannot send candidates to another site.

Two things override the career site address, in this order, if you need
something else:

1. an application URL that arrived from Zoho on the record itself
2. **Fallback application URL**, which takes `{zoho_id}`, `{job_code}`,
   `{slug}` and `{id}` placeholders

**Run check** on the Sync Logs screen reports how many active jobs currently
have no application link.

---

## Field discovery fails with a 401

The mapping screen falls back to standard field names, and **Run check** reports
`Field discovery` as a warning. Syncing still works: this scope is only needed
to read the list of fields your account has.

The cause is almost always the scope list rather than the connection. If
**Settings → Connection → OAuth scopes** has been narrowed to just
`ZohoRecruit.modules.jobopening.READ` -- which is a reasonable thing to have
tried while fighting Zoho's "Invalid OAuth Scope" error -- then reconnecting
grants exactly that and nothing else, and `/settings/fields` keeps returning
401 no matter how many times you reconnect.

Set the scopes to both:

```
ZohoRecruit.modules.jobopening.READ
ZohoRecruit.settings.fields.READ
```

then **disconnect and reconnect**. Changing the setting alone does nothing: the
scopes are fixed at the moment the connection is granted.

It is worth fixing even though it is optional, because without it the mapping
screen cannot show your account's real field names and **Run check** cannot tell
you when a mapping points at a field you do not have.

---

## A field is empty on every job

Department blank everywhere, or salary, or the closing date. The sync reports
success, the jobs appear, and one value is simply never there.

This is almost always a field name. Every Zoho account renames and re-purposes
fields, and the plugin's defaults are only the names a stock account uses. A
mapping pointing at a field your account does not have fails silently: nothing
errors, the value is just never found.

**Run check** on the Sync Logs screen now names them. It compares the mapping
against the field list read from your own account and reports anything that is
not there.

To fix one, open **Field Mapping** and re-point the row. The dropdown shows both
the label and the API name, which is what matters. Some real examples from an
account with customised labels:

| Plugin default | What that account actually had |
| --- | --- |
| `Department_Name` | `Client_Name`, relabelled "Department Name" |
| `Salary` | `Salary_Budget` |
| `Expected_Closing_Date` | `Target_Date` |
| `Publish_in_Career_Website` | `Publish` |
| `Website` | no equivalent at all |

The last one is worth knowing: there is no field on a job opening that holds a
link to the public posting, so the apply button is built from the career site
address instead. See [No apply button appears on any job](#no-apply-button-appears-on-any-job).

---

## The website lists more jobs than the career site

If your Zoho career site shows six jobs and the website shows sixteen, the
extra ten are records that exist in Zoho but are not published to the career
site. They sync because **only sync published jobs** is not doing anything.

That setting reads a flag field on the job opening, named under **Settings →
Sync → Publish flag field**. A field that is not present on a record is treated
as "no opinion" rather than as unpublished -- deliberately, so an account that
does not use the flag at all still syncs -- which means a field name that does
not match your account silently disables the whole filter.

To fix it, open **Field Mapping**. The Zoho field list there is read from your
own account, so it shows the real API name of the publish flag. Put that name in
**Publish flag field** and run a full sync. **Run check** on the Sync Logs
screen warns when the configured name is not a field on your account.

This matters more once apply links are switched on: a job that is not published
to the career site still gets a link, and a candidate following it lands on
"this job posting is no longer available".

---

## A job in WordPress no longer matches Zoho

If the conflict setting is set to keep local edits, the sync deliberately leaves
edited fields alone. **Sync Now** on the dashboard overrides that once: it reads
every job and rewrites all of them from Zoho, discarding those edits. Meta that
the plugin does not map — anything another plugin or your theme added — is left
untouched either way.

---

## "Invalid OAuth Scope — Scope does not exist"

Zoho refuses the authorization request when **any one** of the requested
permissions is not a scope it recognises, and its error page does not say which
one. Nothing is wrong with your Client ID, Client Secret or redirect URI when
you see this.

### The module name is singular

Zoho's own documentation contradicts itself. The worked examples on the
[OAuth overview](https://www.zoho.com/recruit/developer-guide/apiv2/oauth-overview.html)
page write:

```
ZohoRecruit.modules.jobopenings.ALL     ← plural, rejected
```

while the table of scope names further down the same page lists:

```
modules.jobopening                      ← singular, accepted
```

The table is the one that matches the authorization server. Versions of this
plugin before the singular fix copied the example, and every connection attempt
failed with "Scope does not exist".

If you are upgrading, the stored value is corrected for you. If you set the
scopes by hand, the working pair is:

```
ZohoRecruit.modules.jobopening.READ,ZohoRecruit.settings.fields.READ
```

### Which permissions are actually needed

| Scope | Needed for |
| --- | --- |
| `ZohoRecruit.modules.jobopening.READ` | Reading job openings. **Required.** |
| `ZohoRecruit.settings.fields.READ` | Filling the Field Mapping dropdowns from your account |

The second is a convenience. Without it the mapping screen falls back to the
standard Zoho Recruit field names, and syncing, publishing and the API all work
unchanged. If Zoho refuses the pair, cut the **Permissions** field on
**Settings → Connection** down to the first scope alone and connect again.

Some accounts want a broader form. In increasing order of access:

```
ZohoRecruit.modules.jobopening.ALL
ZohoRecruit.modules.ALL
```

### Checking a single scope without changing settings

Open this in a browser, with your own client ID and redirect URI, all on one
line. Zoho shows either its consent screen — the scope is fine — or the error.
Close the tab either way; there is no need to approve anything.

```
https://accounts.zoho.com/oauth/v2/auth?response_type=code&access_type=offline
  &client_id=YOUR_CLIENT_ID
  &redirect_uri=YOUR_REDIRECT_URI
  &scope=ZohoRecruit.modules.jobopening.READ
```

Use the accounts domain for your data centre — `accounts.zoho.in`,
`accounts.zoho.eu`, and so on.

Once field discovery is unavailable, **Field Mapping → Reload fields from Zoho**
reports a scope error. That is expected, and the bundled field list is still
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
