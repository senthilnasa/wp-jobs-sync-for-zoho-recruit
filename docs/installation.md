# Installation

[← Documentation index](../README.md#documentation)

## Requirements

| | |
| --- | --- |
| WordPress | 6.6 or newer |
| PHP | 8.1 or newer |
| Extensions | `openssl` or `sodium` (for encrypting the stored Zoho tokens), `json` |
| Outbound HTTPS | The site must be able to reach `accounts.zoho.*` and `recruit.zoho.*` |
| A Zoho Recruit account | With permission to create a client in the Zoho API console |

The plugin talks to Zoho only from the server, on a schedule or when you press
a button. It never contacts Zoho while rendering a page for a visitor, so a
slow or unreachable Zoho API can never slow down or break the front end.

## Install

### From a ZIP

1. Build or download `jobs-sync-for-zoho-recruit-<version>.zip`.
2. **Plugins → Add New → Upload Plugin**, choose the file, install, activate.

To build the ZIP from a checkout:

```bash
npm install
npm run build
npm run plugin-zip
```

The archive lands in `dist/`. It contains the plugin runtime, the compiled
block asset and its JSX source, but no development tooling — see `.distignore`.

### From a checkout

Clone the repository into `wp-content/plugins/jobs-sync-for-zoho-recruit` and
activate it. The compiled block asset is committed, so the plugin runs without
a build step.

## What activation does

- Registers the `zoho_job` post type and its five taxonomies.
- Creates two custom tables, `{prefix}jszr_sync_runs` and `{prefix}jszr_sync_logs`.
- Grants the `manage_zoho_recruit` capability to the administrator role.
- Schedules three cron events: the sync, the expiry check and the log prune.
- Flushes rewrite rules once, so `/jobs/` starts working immediately.

Nothing is fetched from Zoho on activation.

## First run

1. **Zoho Recruit Jobs → Settings → Connection.** Copy the redirect URI shown
   there and create a client in the Zoho API console — the full walkthrough is
   in [OAuth setup](oauth-setup.md).
2. Paste the Client ID and Client Secret back into the Connection tab, choose
   your data center, and save.
3. Click **Connect to Zoho Recruit** and approve the request at Zoho.
4. **Field Mapping → Reload fields from Zoho.** The dropdowns now list the
   fields that exist in *your* account, including custom ones. Adjust anything
   the defaults got wrong — see [Field mapping](field-mapping.md).
5. Optionally run a **Dry run** first: it fetches everything and reports what
   it would create, update and deactivate without writing a row.
6. Click **Full Sync**. Progress appears live on the dashboard.

A first sync of a few hundred jobs typically finishes in one or two batches. A
large account is processed in the background over several cron ticks; you can
leave the page.

## Scheduling

**Settings → Sync** controls how often the plugin syncs:

| Setting | Meaning |
| --- | --- |
| Sync frequency | Hourly, every six hours, twice daily or daily |
| Scheduled sync type | Which kind of sync cron runs — incremental is the default |
| Full sync interval | How many days may pass before a scheduled run is promoted to a full sync anyway |

Incremental runs ask Zoho only for records modified since the last successful
sync, and separately ask the `/deleted` endpoint what disappeared. A periodic
full sync is still worth keeping, because only a full sync can notice a job
that quietly stopped being returned at all.

### If WP-Cron is disabled

WordPress fires cron on page views. On a low-traffic careers site that can mean
syncs run late. If `DISABLE_WP_CRON` is set, the plugin says so in Site Health
and on the dashboard, and you should drive cron from the system scheduler:

```bash
*/5 * * * * cd /path/to/wordpress && wp cron event run --due-now > /dev/null 2>&1
```

Or trigger the sync directly:

```bash
0 * * * * cd /path/to/wordpress && wp jszr sync --incremental > /dev/null 2>&1
```

See [WP-CLI](#wp-cli) below and [Troubleshooting](troubleshooting.md).

## Publishing the jobs

Any of these work, and all of them read local WordPress data:

- The archive at `/jobs/` (the slug is configurable in **Settings → Frontend**).
- `[zoho_jobs per_page="10" show_filters="true" style="grid"]` in any page.
- The **Zoho Recruit Jobs** block.
- `GET /wp-json/jobs-sync-zoho-recruit/v1/jobs`.

See [Templates](templates.md) and the [REST API](rest-api.md) reference.

## WP-CLI

```bash
wp jszr status                  # connection, schedule and job counts
wp jszr sync --full
wp jszr sync --incremental
wp jszr sync --dry-run
wp jszr expire                  # apply closing dates now
wp jszr logs --limit=20
wp jszr logs --errors
wp jszr cancel                  # stop a running sync
wp jszr fields --refresh        # list the Zoho fields available for mapping
wp jszr disconnect --yes
```

CLI runs take the same lock as every other trigger, so a cron sync and a
`wp jszr sync` can never run over each other.

## Updating

Upgrades run a versioned migration once, keyed on the `jszr_db_version` option.
Settings, mappings, tokens and synced jobs are preserved. If a release changes
the data model, the migration is idempotent and safe to re-run.

## Uninstall

Deleting the plugin from the Plugins screen runs `uninstall.php`, which honours
two settings from **Settings → Advanced**:

| Setting | Default | Effect |
| --- | --- | --- |
| Delete synchronized jobs on uninstall | Off | When on, every `zoho_job` post and its terms are deleted |
| Delete plugin data on uninstall | On | Removes settings, the field mapping, OAuth tokens, both custom tables, transients and scheduled events |

Deactivating the plugin does none of this: it only unschedules the cron events.
Your jobs stay where they are, and reactivating picks up where you left off.

On multisite, uninstall iterates every site in the network and cleans each one.

## Multisite

The plugin is site-scoped. Each site in a network has its own connection, its
own field mapping, its own jobs and its own tables. Network-activating it runs
the activation routine for every existing site, and for each new site created
afterwards.

There is no network-level administration screen: connect each site
individually. If several sites should show the same jobs, connect them to the
same Zoho account and let each keep its own copy.
