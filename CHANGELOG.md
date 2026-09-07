# Changelog

All notable changes to Jobs Sync for Zoho Recruit are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-07

First release.

Requires WordPress 6.6 or newer and PHP 8.1 or newer. The 6.6 floor comes from
the block editor asset, which is built with the automatic JSX runtime and so
depends on the `react-jsx-runtime` script handle WordPress registers from that
version onwards.

### Added

- OAuth 2.0 connection to Zoho Recruit with a random, expiring `state`
  parameter, readable handling of Zoho's `error` responses, automatic access
  token refresh, best-effort revocation on disconnect, and a circuit breaker
  that pauses scheduled syncing after repeated authentication failures.
- Support for every Zoho data center, each mapped to its matching Recruit API
  domain; the API domain Zoho returns with the token is preferred when it is a
  Zoho-owned host.
- Secrets encrypted at rest with libsodium (OpenSSL fallback) using a key
  derived from the site's security salts, plus `JSZR_CLIENT_ID`,
  `JSZR_CLIENT_SECRET` and `JSZR_DATA_CENTER` constants for sites that prefer to
  keep credentials in `wp-config.php`.
- `zoho_job` custom post type with department, location (hierarchical), employment
  type, category and experience taxonomies, a configurable URL slug, and stable
  post slugs that survive Zoho title changes.
- Field mapping screen populated from the Zoho Fields metadata API, with a
  bundled default mapping, per-field transforms, and JSON export and import.
- Full and incremental syncs that run in background batches with checkpointing,
  per-batch time and memory guards, resumption after failure, and a cancel
  action.
- Deactivation of missing jobs only after a fully successful full sync, guarded
  by a configurable safety threshold that marks the run partial and notifies an
  administrator instead of deactivating too much.
- Handling for jobs deleted in Zoho via the deleted-records endpoint, with a
  configurable orphan action that never touches manually created posts.
- Automatic expiry from the closing date on a daily schedule, evaluated in the
  site timezone.
- Public REST API for jobs, a single job and filter terms, with schema-validated
  arguments, an enforced maximum page size, an administrator-controlled field
  allow-list and cache headers.
- Protected REST endpoints for starting, watching and cancelling a sync,
  refreshing one job, reading Zoho fields and testing the connection, all behind
  the `manage_zoho_recruit` capability.
- `[zoho_jobs]`, `[zoho_job_apply]` and `[zoho_job_meta]` shortcodes, and three
  server-rendered blocks — Zoho Recruit Jobs, Job Details and Apply Button — that
  share their renderers with the shortcodes and the archive template. The two
  single-job blocks read the job from block context, so they work in a template,
  a query loop or the editor, and render nothing when there is no job.
- Classic theme templates with theme override support, plus block templates for
  block themes.
- A per-job **Reset to Zoho values** action, in the job list and the metabox,
  for sites running the "preserve fields edited in WordPress" conflict mode. A
  resync respects local edits by design; the reset is the deliberate escape
  hatch, and it replaces only the mapped fields. Also available as
  `force=true` on `POST /jobs/{id}/resync`.
- Filters for the job title and meta description (`jszr_job_title`,
  `jszr_meta_description`), so SEO plugins can read what the plugin would use.
  When no SEO plugin is detected the plugin prints a description itself; when
  one is, it prints nothing and only exposes the values.
- Best-effort page cache purging after a sync for WP Rocket, W3 Total Cache, WP
  Super Cache, LiteSpeed, Cache Enabler, SG Optimizer, Nginx Helper, Kinsta and
  Autoptimize, extensible through `jszr_page_cache_purgers`. Every call is
  guarded, nothing is a dependency, and a purger that throws is logged and
  skipped rather than allowed to fail the sync.
- Taxonomies registered through `jszr_taxonomies` now become REST parameters,
  shortcode attributes, `/jobs/filters` entries and labelled filter dropdowns
  automatically, with no second registration through `jszr_filter_map`.
- JobPosting structured data that omits any property it cannot state accurately,
  and is never emitted for expired or inactive jobs.
- Optional webhook receiver, protected by a per-site secret, rate limited and
  deduplicated; the payload is used only as a trigger and the record is always
  re-read from the API.
- Sync log with per-run statistics and error detail, retention limits, and
  scrubbing that keeps tokens and secrets out of every stored line.
- Site Health tests for the connection, scheduling, sync freshness, encryption
  availability, HTTPS and URL slug conflicts.
- WP-CLI commands under `wp jszr`, including `--dry-run`.
- Privacy policy suggestion, multisite-aware activation and uninstall, and an
  uninstall routine that keeps jobs unless you ask for them to be removed.

[1.0.0]: https://github.com/senthilnasa/wp-jobs-sync-for-zoho-recruit/releases/tag/v1.0.0
