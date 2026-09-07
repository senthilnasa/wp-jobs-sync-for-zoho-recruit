# Handoff — Jobs Sync for Zoho Recruit

Working notes for continuing this build in a new session. Everything below
reflects the repository as it actually stands, not the plan.

- **Working directory:** `C:\Development\jobs-sync-for-zoho-recruit`
- **Git remote:** `https://github.com/senthilnasa/wp-jobs-sync-for-zoho-recruit.git`
  (repository initialised locally, remote added, **nothing committed or pushed yet**)
- **Toolchain present:** PHP 8.2.12, Node 22.16.0. No `composer install` or
  `npm install` has been run, so `vendor/` and `node_modules/` do not exist.
- **Status:** 70 files written. Every PHP file passes `php -l`. No PHPCS run, no
  PHPUnit run, no runtime testing in a real WordPress install yet.

---

## 1. Naming contract (do not drift from this)

| Thing | Value |
| --- | --- |
| Plugin name | Jobs Sync for Zoho Recruit |
| Slug / folder / main file | `jobs-sync-for-zoho-recruit` / `jobs-sync-for-zoho-recruit.php` |
| Text domain | `jobs-sync-for-zoho-recruit` |
| PHP namespace | `JobsSyncForZohoRecruit` |
| Function / option / transient / hook prefix | `jszr_` |
| CSS class prefix | `jszr-` |
| Constant prefix | `JSZR_` |
| REST namespace | `jobs-sync-zoho-recruit/v1` |
| WP-CLI command | `wp jszr` |
| Post type | `zoho_job` |
| Taxonomies | `zoho_job_department`, `zoho_job_location` (hierarchical), `zoho_job_employment_type`, `zoho_job_category`, `zoho_job_experience` |
| Meta prefix | `_zoho_recruit_` (plus `_jszr_` for plugin-internal keys) |
| Capability | `manage_zoho_recruit` |

No slug or file name begins with "Zoho" (WordPress.org trademark rule).

---

## 2. Architecture as built

```
Zoho Recruit API v2
   ↓  Zoho_Auth (OAuth, encrypted tokens)
   ↓  Zoho_API  (HTTP, pagination, retries, error decoding)
   ↓  Sync      (what happens to each record)
   ↓  Field_Mapper (Zoho record → WP payload; pure translation)
   ↓  Job       (the only writer; enforces one-record-one-post)
   ↓  zoho_job CPT + taxonomies + post meta
   ↓  REST_API / Shortcode / Blocks / Templates / Structured_Data
```

Three invariants the code is built around:

1. **No duplicates.** Every write resolves the post through
   `Job::find_by_zoho_id()` behind a named transient lock.
2. **No mass deactivation on failure.** Jobs are deactivated for being "missing"
   only in the final batch of a full sync, and only when every page succeeded.
   A configurable percentage threshold (default 50%) aborts that step, marks the
   run `partial` and emails an administrator.
3. **Nothing hits Zoho during a page view.** The frontend reads local data only.

---

## 3. File map

### Bootstrap
| File | Role |
| --- | --- |
| `jobs-sync-for-zoho-recruit.php` | Header, constants (`VERSION`, `DB_VERSION`, `JSZR_*`), autoloader (`Some_Class` → `includes/class-some-class.php`), activation/deactivation hooks |
| `includes/functions.php` | Global helpers: `jszr_get_setting`, `jszr_admin_url`, `jszr_capability`, `jszr_is_connected`, `jszr_get_job_meta`, `jszr_get_apply_url`, `jszr_is_job_active`, `jszr_locate_template`, `jszr_get_template` |
| `uninstall.php` | Self-contained (no plugin classes), multisite-aware, honours the keep/delete settings, registers taxonomies temporarily so `wp_delete_term()` works |

### Core classes (`includes/`)
| Class | Responsibility | Notes |
| --- | --- | --- |
| `Plugin` | Container, component wiring, activation/deactivation, multisite, capability | `plugin()->auth()`, `->api()`, `->sync()`, `->queue()`, `->logger()`, `->mapper()`, `->metadata()`, `->webhook()` |
| `Settings` | All options in one non-autoloaded `jszr_settings` option; defaults, sanitize, data-center map | `Settings::data_centers()` maps each accounts domain to its Recruit API domain |
| `Encryption` | libsodium secretbox, OpenSSL AES-256-CBC + HMAC fallback; key from `AUTH_KEY`/`SECURE_AUTH_KEY`/`LOGGED_IN_KEY`; `key_fingerprint()` detects salt rotation | |
| `Zoho_Auth` | Authorization URL with expiring `state`, callback validation, code exchange, refresh with stampede lock, revoke on disconnect, circuit breaker (5 failures) | Redirect URI: `admin-post.php?action=jszr_oauth_callback` |
| `Zoho_API` | `get_records`, `get_record`, `get_deleted_records`, `get_fields`, `test_connection`; 204/304 as success, 429 with `Retry-After`, exponential backoff, one forced token refresh on 401, Zoho error-code translation | |
| `Field_Metadata` | Fields API discovery, cached in `jszr_zoho_fields` + 12h freshness transient, bundled default field list as fallback | |
| `Field_Mapper` | Mapping rows `{zoho_field, target, transform}`, 11 transforms, salary parsing, timezone-correct date handling, hierarchical location path, JSON export/import | Pure — no DB writes. Best unit-test surface. |
| `Job` | `find_by_zoho_id`, `upsert`, term writing (incl. hierarchical paths), conflict modes, `deactivate`/`expire`/`orphan`, `is_active`, `get_apply_url`, `counts` | The single writer |
| `Sync` | `start`, `run_now` (CLI), `process_batch`, `process_page`, `process_record`, `determine_status`, `finalize`, `deactivate_missing`, `process_deleted_records`, `expire_due_jobs`, `sync_single` | Time/memory guards via `should_yield()` |
| `Sync_Queue` | `{prefix}jszr_sync_runs` table, checkpointing (`page`, `page_offset`, `seen_ids`), global lock with stale-lock recovery, batch scheduling, cancel, prune | |
| `Logger` | `{prefix}jszr_sync_logs` table, levels, secret scrubbing (`scrub`/`scrub_string`), retention | |
| `Notifications` | Failure, connection and threshold emails, throttled to one per kind per 6h | |
| `Cron` | `jszr_scheduled_sync`, `jszr_check_expired`, `jszr_prune_logs`, custom `jszr_six_hours` schedule, full-vs-incremental decision | |
| `Post_Type` | CPT + taxonomies + `register_post_meta` (REST visibility driven by the allow-list), slug stability, rewrite flush on slug change, sitemap toggle | |
| `REST_API` | Public `jobs`, `jobs/{id}`, `jobs/filters`; protected `sync`, `sync/status`, `sync/cancel`, `jobs/{id}/resync`, `zoho-fields`, `test-connection`, `webhook`; `build_query_args()` shared with the shortcode; job-code search via `posts_join`/`posts_search`/`posts_groupby`; transient cache with a version-bump invalidation | |
| `Webhook` | Secret-token receiver used **only as a trigger**; re-fetches the record by ID; rate limited and deduplicated | |
| `Structured_Data` | JobPosting JSON-LD; omits anything it cannot state accurately; never for expired/inactive jobs | |
| `Templates` | Theme override resolution, `template_include` fallback, archive query filtering, expired-job behaviour (notice/410/redirect), asset registration, `register_block_template()` when available | |
| `Shortcode` | `[zoho_jobs]`, `[zoho_job_apply]`, `[zoho_job_meta]`; shared renderer with the block | |
| `Blocks` | `register_block_type` from `block.json` with `render_callback` → `Shortcode::render` | |
| `Admin` | Menus, settings registration, all `admin_post_*` handlers, list columns/filters/row actions, meta box, notices | |
| `Site_Health` | Connection, cron, last-sync, environment (encryption/HTTPS/slug conflict) tests + debug info | |
| `Privacy` | `wp_add_privacy_policy_content()` | |
| `CLI` | `wp jszr sync|status|expire|logs|cancel|fields|disconnect` | |
| `Upgrader` | Idempotent versioned migrations, first-install seeding | |

### Admin, frontend, block
- `admin/views/`: `dashboard.php`, `settings.php` (6 tabs), `mapping.php`, `logs.php`, `meta-box.php`
- `admin/assets/css/admin.css`, `admin/assets/js/admin.js` (mapping rows + sync progress polling via `wp.apiFetch`)
- `public/css/jobs.css`, `public/js/jobs.js` (progressive enhancement only)
- `templates/`: `listing.php`, `card.php`, `filters.php`, `pagination.php`, `no-results.php`, `archive.php`, `single.php`, `job-meta.php`, `block-templates/{single,archive}-zoho_job.html`
- `blocks/jobs/`: `block.json` (apiVersion 3), `src/index.js` (JSX source), `build/index.js` (shipped, unminified, `wp.element.createElement`), `build/index.asset.php`, `build/editor.css`

### Project files
`readme.txt` (with the third-party service disclosure), `LICENSE` (real GPL-2.0
text, downloaded), `CHANGELOG.md`, `README.md`, `composer.json`, `package.json`,
`phpcs.xml`, `phpunit.xml.dist`, `.wp-env.json`, `.gitignore`, `.distignore`,
`bin/build-zip.sh`, `bin/install-wp-tests.sh`, `.github/workflows/ci.yml`,
`tests/phpunit/{bootstrap,field-mapper-test,sync-logic-test,rest-api-test}.php`

---

## 4. Decisions worth knowing before you change anything

- **Admin menu placement.** The plugin's screens are **submenus of the job post
  type menu** (`edit.php?post_type=zoho_job&page=jszr-settings`), not a separate
  top-level menu. That gives one menu and, importantly, lets an editor without
  `manage_zoho_recruit` still reach the job list. **Always build these URLs with
  `jszr_admin_url( $slug, $args )`** — there are no hard-coded `admin.php?page=`
  strings left, and adding one would break the link.
- **Custom tables, deliberately.** Sync runs and log rows are high-churn,
  append-only data; putting them in `wp_posts`/`wp_options` would bloat every
  "recent content" query. Job content itself stays 100% native.
- **Expired jobs stay published by default** (`expire_action = inactive`) and the
  single page shows a closed notice with `noindex`. Listings and the API filter
  them out by meta, not by post status.
- **`only_published` is on by default**, reading `Publish_in_Career_Website`.
  Sites whose Zoho field is named differently must change it in Sync settings.
- **Shell heredocs mangle backslashes in this environment.** PHP files with
  namespaces or regexes were written with the Write/Edit tools for that reason.
  Do not go back to `cat > file.php <<'EOF'` for PHP.

---

## 5. Verified vs. not verified

**Verified**
- Every `.php` file passes `php -l` (70 files, clean).
- Naming prefixes are consistent across the codebase.
- No hard-coded credentials anywhere; no `admin.php?page=` links remain.

**Not verified — this is the real remaining risk**
- Never activated in a WordPress install. No screen has been rendered.
- No PHPCS run (needs `composer install`).
- No PHPUnit run (needs the WP test suite via `bin/install-wp-tests.sh`).
- No Plugin Check (PCP) run.
- Never talked to the Zoho API. OAuth flow, pagination, `If-Modified-Since`,
  the `/deleted` endpoint and error codes are all written to the documented API
  but untested against a live account.
- `npm run build` has not been run; `blocks/jobs/build/index.js` is hand-written
  to be equivalent to `src/index.js`. **If you edit one, mirror the other.**

---

## 6. What is left to do

### Blocking for a first release
1. `composer install && vendor/bin/phpcs` — fix every error.
2. `npm install && npm run build` — confirm the generated bundle works, then
   decide whether to keep the hand-written `build/index.js` or the generated one.
3. Install into a real WordPress (`npm run env:start`), then walk the flow:
   activate → credentials → connect → reload fields → full sync → archive page →
   single page → shortcode → block → REST → uninstall.
4. Run Plugin Check and resolve everything it flags.
5. Run PHPUnit (`bash bin/install-wp-tests.sh …` then `vendor/bin/phpunit`).

### Documentation still to write (`docs/`, referenced from `README.md` — the
directory does not exist yet, so those links are currently broken)
- `docs/installation.md`
- `docs/oauth-setup.md`
- `docs/field-mapping.md`
- `docs/data-model.md`
- `docs/rest-api.md`
- `docs/templates.md`
- `docs/hooks.md`
- `docs/testing.md`
- `docs/troubleshooting.md`
- `docs/privacy.md`

### Also outstanding
- `languages/jobs-sync-for-zoho-recruit.pot` — the `languages/` directory exists
  but is empty. Generate with `npm run makepot` (needs WP-CLI) or a script.
- `.wordpress-org/` assets: `banner-1544x500.png`, `banner-772x250.png`,
  `icon-256x256.png`, `icon-128x128.png`, `screenshot-1..5.png`. The directory
  exists but is empty, and `readme.txt` already describes five screenshots.
- First git commit and push to the remote (nothing is committed yet).
- Decide the real `Author` / `Plugin URI` values before submitting to
  WordPress.org; they currently point at the GitHub repo and `senthilnasa`.

---

## 7. Useful commands

```bash
# Lint every PHP file (works with no dependencies installed)
find . -path ./node_modules -prune -o -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

composer install && vendor/bin/phpcs        # coding standards
npm install && npm run build                # block assets
npm run env:start && npm run test:php       # WordPress + PHPUnit
npm run plugin-zip                          # dist/*.zip
```

---

## 8. Prompt to resume in a new session

> Continue building the WordPress plugin in
> `C:\Development\jobs-sync-for-zoho-recruit` (repo:
> `https://github.com/senthilnasa/wp-jobs-sync-for-zoho-recruit.git`).
> Read `HANDOFF.md` first — it lists what exists, the naming contract, the
> decisions already made, and what is outstanding. Continue from section 6.
> Keep the `jszr_` / `JSZR_` / `JobsSyncForZohoRecruit` naming, build admin URLs
> with `jszr_admin_url()`, and write PHP with the file tools rather than shell
> heredocs (heredocs strip backslashes here).
