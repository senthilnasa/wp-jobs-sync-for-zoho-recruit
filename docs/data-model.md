# Data model

[← Documentation index](../README.md#documentation)

A synchronized job is an ordinary WordPress post. There is no jobs table: the
plugin uses the post type, taxonomy and meta APIs, so everything a theme,
plugin, query or export already knows how to do with posts works on jobs too.

Only two things get custom tables, and both are append-only operational data
that would otherwise bloat `wp_posts` and `wp_options`.

## Post type

| | |
| --- | --- |
| Name | `zoho_job` |
| Public | Yes (configurable — **Settings → Frontend → Publicly visible**) |
| Archive | `/jobs/` (configurable) |
| Single | `/jobs/{slug}/` (configurable) |
| Supports | `title`, `editor`, `excerpt`, `custom-fields`, `thumbnail`, `revisions` |
| REST | `show_in_rest`, base `zoho_job` |
| Capability | Maps to post capabilities; plugin screens require `manage_zoho_recruit` |

Slugs are **stable**. Once a job has a post slug, renaming the job in Zoho does
not change its URL — a careers page that has been shared or indexed keeps
working. Change the slug by editing the post in WordPress.

Filter the registration arguments with `jszr_post_type_args`.

## Taxonomies

| Taxonomy | Hierarchical | Typical source |
| --- | --- | --- |
| `zoho_job_department` | No | `Department_Name` |
| `zoho_job_location` | **Yes** | Country → State → City, or a mapped field |
| `zoho_job_employment_type` | No | `Job_Type` |
| `zoho_job_category` | Yes | Unmapped by default |
| `zoho_job_experience` | No | `Work_Experience` |

All five are public, REST-enabled and available to `WP_Query`. Register your own
with `jszr_taxonomies` and it appears in the mapping UI, the REST filters and
the listing filters without further work.

## Post meta

Every key is protected (leading underscore), so none of it appears in the
Custom Fields box or is editable by an untrusted user. Keys carrying data that
came from Zoho use `_zoho_recruit_`; keys the plugin maintains for itself use
`_jszr_`.

### Synchronized from Zoho

| Meta key | Type | Notes |
| --- | --- | --- |
| `_zoho_recruit_id` | string | The Zoho record ID. **The unique key.** Indexed lookups go through this |
| `_jszr_job_code` | string | Zoho's `Job_Opening_ID`, the human-readable code |
| `_zoho_recruit_status` | string | The raw Zoho status, verbatim |
| `_zoho_recruit_created_time` | string | ISO-8601 UTC |
| `_zoho_recruit_modified_time` | string | ISO-8601 UTC. Drives incremental sync |
| `_zoho_recruit_posted_date` | string | `Y-m-d` |
| `_zoho_recruit_closing_date` | string | `Y-m-d`. Drives expiry |
| `_zoho_recruit_city` | string | |
| `_zoho_recruit_state` | string | |
| `_zoho_recruit_country` | string | |
| `_zoho_recruit_remote` | string | `Yes` / `No` |
| `_zoho_recruit_industry` | string | |
| `_zoho_recruit_client` | string | Lookup name, not the lookup object |
| `_zoho_recruit_positions` | integer | |
| `_zoho_recruit_salary` | string | The raw string as Zoho holds it |
| `_zoho_recruit_salary_min` | number | Parsed |
| `_zoho_recruit_salary_max` | number | Parsed |
| `_zoho_recruit_salary_currency` | string | ISO code, parsed |
| `_zoho_recruit_salary_unit` | string | `HOUR` / `DAY` / `WEEK` / `MONTH` / `YEAR`, parsed |
| `_zoho_recruit_application_url` | string | |
| `_zoho_recruit_source_url` | string | |

### Maintained by the plugin

| Meta key | Purpose |
| --- | --- |
| `_jszr_status` | The **normalised** status: `active`, `inactive`, `expired`, `closed`, `draft`. This is what listings and the API filter on |
| `_zoho_recruit_last_synced` | Timestamp of the last successful write |
| `_jszr_sync_hash` | Per-field hashes of the last synced values, used by the "preserve manual edits" conflict mode |
| `_jszr_mapped_values` | The mapped values as written, shown read-only on the edit screen |
| `_jszr_manual` | Set on a job created by hand in WordPress. **A manual job is never deactivated or orphaned by a sync** |
| `_zoho_recruit_raw_data` | The complete Zoho record as JSON. Never public; can be switched off in **Settings → Sync** to save space |

`_zoho_recruit_raw_data`, `_jszr_sync_hash`, `_jszr_manual` and
`_jszr_mapped_values` are registered with `show_in_rest => false`
unconditionally. The rest are registered for the core `wp/v2/zoho_job`
endpoint only when the matching field is on the public allow-list in
**Settings → Public API** — see [REST API](rest-api.md#field-allow-list).

> `jszr_get_job_meta( $id, 'status' )` resolves to `_zoho_recruit_status` — the
> raw Zoho status. For the normalised one the plugin acts on, use
> `JobsSyncForZohoRecruit\Job::get_status( $id )` or `jszr_is_job_active( $id )`.

## Job status

Two different statuses live on a job, and the distinction matters:

- **The Zoho status** (`_zoho_recruit_status`) is whatever your recruiters set —
  "In-progress", "On-Hold", "Filled", or something specific to your account.
- **The normalised status** (`_jszr_status`) is one of five values the plugin
  understands. **Settings → Sync → Status mapping** translates between them.

| Normalised | Meaning | Post status | In listings |
| --- | --- | --- | --- |
| `active` | Open for applications | publish | Yes |
| `inactive` | Not currently advertised | publish | No |
| `expired` | Past its closing date | publish (configurable) | No |
| `closed` | Filled or cancelled in Zoho | publish | No |
| `draft` | Awaiting approval in Zoho | draft | No |

Expired and inactive jobs stay **published** by default so their URLs keep
resolving; the single page shows a "this position is closed" notice with
`noindex`. Change that in **Settings → Frontend → Expired job behaviour** to
return 410 Gone or redirect to the archive instead.

Filter the mapping with `jszr_status_mapping`, the decision for one record with
`jszr_record_status`, and the post status a normalised status implies with
`jszr_post_status_for_job_status`.

## Options

All non-autoloaded, so a page that never touches the plugin pays nothing.

| Option | Contents |
| --- | --- |
| `jszr_settings` | Every setting, one array |
| `jszr_field_mapping` | The mapping rows |
| `jszr_tokens` | Encrypted client secret, refresh token, access token, expiry and a key fingerprint |
| `jszr_zoho_fields` | Cached Zoho field list |
| `jszr_sync_state` | Last sync time, last successful sync time, last error |
| `jszr_webhook_secret` | Per-site webhook token |
| `jszr_cache_version` | Bumped to invalidate every cached listing at once |
| `jszr_auth_failures` | Consecutive authentication failures, for the circuit breaker |
| `jszr_db_version` | Schema version, drives the upgrade routine |

Nothing in `jszr_tokens` is readable without the site's `AUTH_KEY`,
`SECURE_AUTH_KEY` and `LOGGED_IN_KEY` — see [OAuth setup](oauth-setup.md#where-the-secrets-live).

## Custom tables

Sync runs and log lines are high-churn, append-only rows with a retention
policy. Putting them in `wp_posts` would put them in every "recent content"
query on the site; putting them in `wp_options` would make the options table
grow without bound. Both tables are created with `dbDelta()` on activation and
dropped on uninstall.

### `{prefix}jszr_sync_runs`

One row per sync run. This is also the checkpoint: an interrupted run resumes
from what is stored here.

| Column | Purpose |
| --- | --- |
| `id` | Run ID |
| `type` | `full` / `incremental` |
| `state` | `pending`, `running`, `completed`, `failed`, `partial`, `cancelled` |
| `dry_run` | Whether writes were suppressed |
| `trigger_source` | `manual`, `cron`, `cli`, `webhook` |
| `page`, `page_offset` | **The checkpoint.** Where to resume |
| `per_page` | Records requested per API call |
| `processed`, `total` | Progress |
| `created_count`, `updated_count`, `skipped_count` | |
| `deactivated_count`, `expired_count`, `orphaned_count` | |
| `error_count` | |
| `seen_ids` | JSON list of Zoho IDs seen in this run. What the final "missing job" pass compares against |
| `modified_since` | The `If-Modified-Since` value used |
| `message` | Failure or partial-completion reason |
| `started_at`, `updated_at`, `finished_at` | |

Indexed on `state` and `started_at`.

### `{prefix}jszr_sync_logs`

| Column | Purpose |
| --- | --- |
| `id` | |
| `run_id` | 0 for events outside a run |
| `level` | `debug`, `info`, `warning`, `error` |
| `event` | Short machine-readable name, e.g. `oauth_state_invalid` |
| `message` | Human-readable |
| `context` | JSON, **scrubbed of anything token-shaped before it is written** |
| `created_at` | |

Indexed on `run_id`, `level` and `created_at`. Retention is configurable by
age and by row count in **Settings → Advanced**; the daily `jszr_prune_logs`
cron applies it.

## Finding jobs in code

```php
// Active jobs in one department.
$jobs = new WP_Query(
	array(
		'post_type'      => 'zoho_job',
		'posts_per_page' => 10,
		'tax_query'      => array(
			array(
				'taxonomy' => 'zoho_job_department',
				'field'    => 'slug',
				'terms'    => 'engineering',
			),
		),
		'meta_query'     => array(
			array(
				'key'     => '_jszr_status',
				'value'   => 'active',
			),
		),
	)
);

// One job by its Zoho ID, without writing a meta_query by hand.
$post_id = JobsSyncForZohoRecruit\Job::find_by_zoho_id( '486000000123456' );

// Helpers that do not require knowing the class names.
jszr_is_job_active( $post_id );
jszr_get_job_meta( $post_id, 'closing_date' );
jszr_get_apply_url( $post_id );
```

Prefer `Job::find_by_zoho_id()` over your own meta query: it is the same
indexed lookup the writer uses, which is what guarantees one Zoho record maps
to exactly one post.

## Multisite

Everything above is per site. Each site in a network has its own post type
content, its own taxonomies, its own options and its own pair of tables, named
with that site's table prefix.
