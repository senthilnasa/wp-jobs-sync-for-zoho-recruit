# Privacy

[← Documentation index](../README.md#documentation)

Short version: the plugin copies **job openings** out of your Zoho Recruit
account into your WordPress site. It never touches candidate data, and it never
sends anything about your visitors to Zoho.

## The third-party service

| | |
| --- | --- |
| Service | Zoho Recruit, operated by Zoho Corporation |
| Endpoints | `accounts.zoho.<tld>` for OAuth, `recruit.zoho.<tld>` for the API |
| Terms | <https://www.zoho.com/terms.html> |
| Privacy policy | <https://www.zoho.com/privacy.html> |

The `<tld>` is whichever data center you configure. No other external service
is contacted, ever — no analytics, no telemetry, no update pings of the
plugin's own.

## When the site talks to Zoho

Only from the server, and only when:

- an administrator connects, tests the connection, or disconnects;
- an administrator starts a sync, or the scheduled sync runs;
- an administrator reloads the field list;
- a webhook you configured triggers a re-fetch of one record.

**Never while rendering a page for a visitor.** A visitor loading the careers
page causes no request to Zoho, under any circumstances. That is a design
constraint of the plugin, not a caching side effect.

## What is read from Zoho

Two read-only scopes:

| Scope | Data |
| --- | --- |
| `ZohoRecruit.modules.jobopening.READ` | Job Opening records |
| `ZohoRecruit.settings.fields.READ` | The names, labels and types of Job Opening fields |

The plugin does not request, and cannot exercise, access to Candidates,
Applications, Interviews, Contacts, Clients as records, or any write scope.

A Job Opening record may name an internal recruiter or owner. That data is only
stored if you map it, and it is only published if you also add it to the public
API allow-list. Neither is the default.

## What is stored in WordPress

| Data | Where | Retention |
| --- | --- | --- |
| Job title, description, location, type, salary, dates and other mapped fields | `zoho_job` posts, taxonomy terms and post meta | Until the job is deleted or the plugin is uninstalled with data deletion on |
| The Zoho record ID | Post meta | As above |
| The complete raw Zoho record | Post meta, `_zoho_recruit_raw_data` | As above. Can be switched off entirely in **Settings → Sync** |
| Client secret, refresh token, access token | The `jszr_tokens` option, **encrypted** | Until you disconnect or uninstall |
| Sync runs and log lines | `{prefix}jszr_sync_runs`, `{prefix}jszr_sync_logs` | Configurable by age and row count, default 30 days / 200 rows |
| Settings and the field mapping | Options | Until uninstalled with data deletion on |

The full list of meta keys is in [Data model](data-model.md#post-meta).

## What is sent to Zoho

The OAuth exchange, and then authenticated `GET` requests. Nothing else. The
plugin holds only read scopes, so it cannot write to your Zoho account even by
accident.

No visitor IP address, user agent, session, search term, page view or any other
information about anyone using your site is transmitted. There is no analytics
or telemetry of any kind, to Zoho or to the plugin's author.

## Candidate data

Not read, not stored, not transmitted.

The plugin publishes job openings and links to an application form. When a
visitor follows an **Apply** link they leave your site for a destination you
configured — a Zoho careers page or your own ATS. From that click onward, that
destination's privacy policy applies, not yours and not this plugin's.

The plugin does not accept applications and does not proxy them.

## Secrets at rest

The client secret and both tokens are encrypted before they are written to the
options table, using libsodium's `crypto_secretbox` where available and
AES-256-CBC with an HMAC-SHA-256 tag otherwise. The key is derived from the
site's `AUTH_KEY`, `SECURE_AUTH_KEY` and `LOGGED_IN_KEY`.

Consequences worth knowing:

- A database dump alone does not expose the credentials — an attacker needs
  `wp-config.php` too.
- Rotating those salts makes the stored tokens unreadable. The plugin detects
  this and asks you to reconnect rather than failing obscurely.
- Secrets are never rendered into a settings field, never included in an
  export, and scrubbed from every log line before it is written.

Credentials can also be defined as constants in `wp-config.php` and kept out of
the database entirely — see [OAuth setup](oauth-setup.md#defining-credentials-in-wp-configphp).

## Privacy policy text

The plugin registers suggested wording with WordPress under **Tools → Privacy →
Privacy Policy Guide**, covering what is copied, that candidate data is not
involved, what is retained, where Apply links lead, and how to disconnect.
Adapt it rather than pasting it verbatim; you know your site's context.

## Personal data export and erasure

The plugin registers no exporter or eraser, because it stores no personal data
about site visitors or users. There is nothing about a data subject for it to
export or erase.

If you have deliberately mapped a Zoho field containing a person's name — a
hiring manager, say — that value is ordinary post meta on a job and is removed
when the job is deleted.

## Disconnecting

**Settings → Connection → Disconnect**, or `wp jszr disconnect`. The plugin
calls Zoho's revoke endpoint on a best-effort basis and deletes every stored
token from the database whether or not the revoke call succeeded. You can also
revoke the connection from your side at
<https://accounts.zoho.com/home#sessions/userconnections>.

Jobs already synced stay published until you change or delete them.

## Uninstall

Deleting the plugin runs `uninstall.php`, which honours two settings from
**Settings → Advanced**:

| Setting | Default | Effect |
| --- | --- | --- |
| Delete synchronized jobs | Off | When on, deletes every `zoho_job` post and its terms |
| Delete plugin data | On | Deletes settings, the mapping, the encrypted tokens, both custom tables, transients and scheduled events |

The conservative default keeps your job content and removes the credentials —
uninstalling never silently destroys content you may still want, but it does
not leave a live Zoho token behind either.

On multisite, uninstall iterates every site in the network.

## Compliance notes

- **GDPR.** The plugin processes no personal data of data subjects visiting
  your site. Job opening data is business information about roles. If you map a
  field naming an individual, you become the controller for that field.
- **Data location.** Job data is copied to your own WordPress database, wherever
  that is hosted. Choosing a Zoho data center determines which Zoho region the
  plugin reads from.
- **Data minimisation.** Two mechanisms help: turn off raw record storage if you
  do not need it for troubleshooting, and keep the public API field allow-list
  as short as your careers page actually requires.
