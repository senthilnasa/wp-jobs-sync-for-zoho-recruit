# Jobs Sync for Zoho Recruit

Synchronize Job Openings from Zoho Recruit into WordPress and publish them with
a shortcode, a block, theme templates or the REST API.

The sync runs in one direction: Zoho Recruit is the source of truth, WordPress
is the published copy. Zoho is never contacted while rendering a page for a
visitor.

- **Plugin slug:** `jobs-sync-for-zoho-recruit`
- **Requires:** WordPress 6.6+, PHP 8.1+
- **License:** GPL-2.0-or-later

## What it does

| Area | Summary |
| --- | --- |
| Connection | OAuth 2.0 with a CSRF-protected callback, automatic token refresh, revocation on disconnect, and a circuit breaker after repeated failures |
| Storage | A `zoho_job` post type with five taxonomies and prefixed post meta — no separate job table |
| Mapping | Field mapping driven by the live field list from your Zoho account, including custom fields |
| Syncing | Full and incremental syncs, batched over WP-Cron, resumable from a checkpoint |
| Safety | Missing-job deactivation only after a fully successful full sync, behind a configurable percentage threshold |
| Publishing | Shortcode, three server-rendered blocks, classic and block theme templates, REST API, JobPosting structured data |
| Appearance | Listing and job-detail layouts, custom HTML via safe tokens, custom CSS, and a configurable search / filter / sort bar — all without touching a theme file |

## Documentation

| Document | Contents |
| --- | --- |
| [Installation](docs/installation.md) | Install, first sync, scheduling, uninstall behaviour |
| [OAuth setup](docs/oauth-setup.md) | Zoho API console, redirect URI, scopes, data centers, `wp-config.php` constants |
| [Field mapping](docs/field-mapping.md) | Targets, transforms, custom fields, conflict modes, import and export |
| [Data model](docs/data-model.md) | Post type, taxonomies, every meta key, both custom tables |
| [REST API](docs/rest-api.md) | Public and protected endpoints, parameters, responses, caching |
| [Templates](docs/templates.md) | Theme overrides, block themes, shortcodes, CSS classes |
| [Frontend customization](docs/frontend-customization.md) | Disabling the default jobs frontend, template overrides, the job data API, AJAX |
| [Hooks](docs/hooks.md) | Every action and filter, with signatures and examples |
| [Testing](docs/testing.md) | Test matrix, how to run PHPUnit, PHPCS and Plugin Check |
| [Troubleshooting](docs/troubleshooting.md) | Common Zoho errors, Site Health, logs |
| [Privacy](docs/privacy.md) | What is read, stored, sent and retained |

## Quick start

1. Activate the plugin.
2. Create a **Server-based Application** at <https://api-console.zoho.com/>.
3. Copy the redirect URI from **Zoho Recruit Jobs → Settings → Connection** into
   that application, then paste its Client ID and Client Secret back into the
   settings screen and pick your data center.
4. Click **Connect to Zoho Recruit**.
5. Open **Field Mapping**, click **Reload fields from Zoho**, and adjust anything
   your account does differently.
6. Click **Full Sync**.

Then publish the jobs however you like:

```
[zoho_jobs per_page="10" show_filters="true" show_search="true" style="grid"]
```

```
GET /wp-json/jobs-sync-zoho-recruit/v1/jobs?department=engineering&per_page=20
```

## Development

```bash
composer install        # PHPCS and the WordPress coding standards
npm install             # block build tooling

npm run build           # build blocks/jobs/build from blocks/jobs/src
composer run lint       # PHPCS against the WordPress standard
npm run env:start       # a local WordPress via wp-env
npm run test:php        # PHPUnit inside wp-env
npm run plugin-zip      # jobs-sync-for-zoho-recruit.zip
```

The built block asset is committed so the plugin runs from a plain checkout with
no build step, and its JSX source ships beside it in `blocks/jobs/src/` so the
code that runs can always be traced back to source.

What goes into the ZIP is the `files` list in `package.json` — an allow-list, so
a new development file never reaches a release by accident.

## Disclaimer

This plugin is an independent integration. It is not affiliated with, endorsed
by, or sponsored by Zoho Corporation. "Zoho" and "Zoho Recruit" are trademarks
of Zoho Corporation.
