# Handoff — Jobs Sync for Zoho Recruit

Working notes for continuing this build in a new session. Everything below
reflects the repository as it actually stands, not the plan.

- **Working directory:** `C:\Development\jobs-sync-for-zoho-recruit`
- **Git remote:** `https://github.com/senthilnasa/wp-jobs-sync-for-zoho-recruit.git`
- **Toolchain:** PHP 8.2.12, Node 22.16.0, Composer 2.9.2, Docker. No global
  WP-CLI — everything that needs it goes through `wp-env run cli`.
- **Status:** the plugin has been installed, activated and exercised in a real
  WordPress (7.1, PHP 8.1, via wp-env). PHPCS, PHPUnit, Plugin Check, ESLint and
  Stylelint all pass. The WordPress.org listing artwork and screenshots exist.
  It has **never talked to a live Zoho account** — that is the one real gap.

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
| `jobs-sync-for-zoho-recruit.php` | Header, constants, autoloader (`Some_Class` → `includes/class-some-class.php`), activation/deactivation hooks |
| `includes/functions.php` | Global helpers: `jszr_get_setting`, `jszr_capability`, `jszr_verify_admin_request`, `jszr_admin_url`, `jszr_is_connected`, `jszr_get_job_meta`, `jszr_get_apply_url`, `jszr_is_job_active`, `jszr_locate_template`, `jszr_get_template` |
| `uninstall.php` | Self-contained (no plugin classes), multisite-aware, honours the keep/delete settings |

### Core classes (`includes/`)
| Class | Responsibility |
| --- | --- |
| `Plugin` | Container, wiring, activation/deactivation, multisite, capability |
| `Settings` | All options in one non-autoloaded `jszr_settings` option; defaults, sanitize, data-center map |
| `Encryption` | libsodium secretbox, OpenSSL fallback; key from the site salts; `key_fingerprint()` detects salt rotation |
| `Zoho_Auth` | Authorization URL with expiring `state`, callback validation, code exchange, refresh with stampede lock, revoke, circuit breaker (5 failures) |
| `Zoho_API` | `get_records`, `get_record`, `get_deleted_records`, `get_fields`, `test_connection`; 204/304 as success, 429 with `Retry-After`, backoff, one forced refresh on 401 |
| `Field_Metadata` | Fields API discovery, cached with a 12h freshness transient, bundled fallback list |
| `Field_Mapper` | Mapping rows, 11 transforms, salary parsing, timezone-correct dates, hierarchical location path, JSON export/import. **Pure — no DB writes. Best unit-test surface** |
| `Job` | `find_by_zoho_id`, `upsert`, term writing, conflict modes, `deactivate`/`expire`/`orphan`, `is_active`, `get_apply_url`, `counts`. The single writer |
| `Sync` | `start`, `run_now`, `process_batch`, `process_page`, `process_record`, `determine_status`, `is_unpublished`, `finalize`, `deactivate_missing`, `process_deleted_records`, `expire_due_jobs`, `sync_single` |
| `Sync_Queue` | `{prefix}jszr_sync_runs` table, checkpointing, global lock with stale recovery, batch scheduling, cancel, prune |
| `Logger` | `{prefix}jszr_sync_logs` table, levels, secret scrubbing, retention |
| `Notifications` | Failure, connection and threshold emails, throttled |
| `Cron` | `jszr_scheduled_sync`, `jszr_check_expired`, `jszr_prune_logs`, custom `jszr_six_hours` schedule |
| `Post_Type` | CPT + taxonomies + `register_post_meta`, slug stability, rewrite flush on slug change, sitemap toggle |
| `REST_API` | Public `jobs`, `jobs/{id}`, `jobs/filters`; protected `sync`, `sync/status`, `sync/cancel`, `jobs/{id}/resync`, `zoho-fields`, `test-connection`, `webhook`; `build_query_args()` shared with the shortcode; transient cache with version-bump invalidation |
| `Webhook` | Secret-token receiver used **only as a trigger**; re-fetches by ID; rate limited and deduplicated |
| `Structured_Data` | JobPosting JSON-LD; omits anything it cannot state accurately; never for expired/inactive |
| `Templates` | Theme override resolution, `template_include` fallback (classic themes only), expired-job behaviour, asset registration, `register_block_template()` |
| `Shortcode` | `[zoho_jobs]`, `[zoho_job_apply]`, `[zoho_job_meta]`; shared renderer with the block |
| `Blocks` | `register_block_type` from `block.json` with `render_callback` → `Shortcode::render` |
| `Admin` | Menus, settings, `admin_post_*` handlers, list columns/filters/row actions, meta box, notices |
| `Site_Health` | Connection, cron, last-sync, environment tests + debug info |
| `Privacy` | `wp_add_privacy_policy_content()` |
| `CLI` | `wp jszr sync\|status\|expire\|logs\|cancel\|fields\|disconnect` |
| `Upgrader` | Idempotent versioned migrations, first-install seeding |

### Admin, frontend, block
- `admin/views/`: `dashboard.php`, `settings.php` (6 tabs), `mapping.php`, `logs.php`, `meta-box.php`
- `admin/assets/`, `public/css/jobs.css`, `public/js/jobs.js` (progressive enhancement only)
- `templates/`: `listing.php`, `card.php`, `filters.php`, `pagination.php`, `no-results.php`, `archive.php`, `single.php`, `job-meta.php`, `block-templates/{single,archive}-zoho_job.html`
- `blocks/jobs/`: `block.json` (apiVersion 3), `editor.css`, `src/index.js`, `build/` (generated)

### Project files
`readme.txt`, `LICENSE`, `CHANGELOG.md`, `README.md`, `docs/` (10 documents),
`languages/jobs-sync-for-zoho-recruit.pot`, `composer.json`, `package.json`,
`phpcs.xml`, `phpunit.xml.dist`, `.wp-env.json`, `.gitignore`, `.gitattributes`,
`bin/install-wp-tests.sh`, `bin/smoke-test.php`, `.github/workflows/ci.yml`,
`tests/phpunit/{bootstrap,field-mapper-test,sync-logic-test,rest-api-test}.php`

### WordPress.org listing assets (`.wordpress-org/`, not shipped in the ZIP)
`banner-1544x500.png`, `banner-772x250.png`, `icon-256x256.png`,
`icon-128x128.png`, `screenshot-1..5.png` — all generated, none hand-drawn:

| Source | Produces |
| --- | --- |
| `src/icon.html`, `src/banner.html` | The artwork, as editable HTML/SVG |
| `src/render-assets.mjs` | Renders both to PNG at every required size via Chrome |
| `src/seed-demo.php` | Six realistic jobs, pushed through the real sync path |
| `src/render-screenshots.mjs` | Captures the five screenshots from the live wp-env site |

Re-running them is `node .wordpress-org/src/render-assets.mjs` and
`node .wordpress-org/src/render-screenshots.mjs` (seed the demo jobs first).
Both need Chrome; set `CHROME_PATH` if it is not at the default Windows
location. The artwork is original — no Zoho logo or brand mark is used
anywhere, deliberately.

---

## 4. Decisions worth knowing before you change anything

- **Admin menu placement.** The plugin's screens are **submenus of the job post
  type menu** (`edit.php?post_type=zoho_job&page=jszr-settings`), not a separate
  top-level menu. **Always build these URLs with `jszr_admin_url( $slug, $args )`.**
- **Custom tables, deliberately.** Sync runs and log rows are high-churn,
  append-only data. Job content itself stays 100% native.
- **Expired jobs stay published by default** (`expire_action = inactive`) and the
  single page shows a closed notice with `noindex`.
- **`only_published` is on by default**, reading `Publish_in_Career_Website`.
  A job that has never been published is **skipped entirely**; one that loses the
  flag later is deactivated, not deleted.
- **Template overrides only count in `yourtheme/jobs-sync-for-zoho-recruit/`.**
  Matching a bare `archive.php` used to pick up the theme's own blog template.
- **Block themes never load the classic PHP templates.** They call
  `get_header()`, which a block theme has no answer for.
- **`jszr_verify_admin_request()` is a global function, not a method,** so PHPCS
  and Plugin Check can both see the nonce check at the call site.
- **What ships is the `files` allow-list in `package.json`,** consumed by
  `wp-scripts plugin-zip`. There is no `.distignore` any more — one source of
  truth, and a new dev file cannot reach a release by accident.
- **PHPUnit is pinned to `^9.6`** because that is what the WordPress core test
  suite supports; 10 cannot discover the test classes.
- **TypeScript is pinned to `^5`** because TypeScript 7 breaks the ts-api-utils
  version `@typescript-eslint` 6 depends on, which makes ESLint refuse to start.
- **Front-end colours never key off `prefers-color-scheme`.** They derive from
  `currentcolor` instead. A theme is not obliged to follow the OS preference, and
  keying off it painted white secondary text and white card borders onto light
  themes for any visitor with dark mode enabled.
- **Shell heredocs mangle backslashes in this environment.** Write PHP with the
  file tools, not `cat > file.php <<'EOF'`.

---

## 5. Verified vs. not verified

**Verified in a live WordPress 7.1 / PHP 8.1 (wp-env)**
- PHPCS: 0 errors, 0 warnings across 46 files.
- PHPUnit: 47 tests, 84 assertions, all passing.
- `bin/smoke-test.php`: 37 checks, all passing.
- Plugin Check: nothing reported against any file that ships.
- ESLint and Stylelint clean; `npm run build` produces the shipped bundle.
- Every admin screen rendered: dashboard, all six settings tabs, field mapping,
  sync logs, and the job list table with its custom columns and status filter.
- The block renders in the editor and on the front end, no console errors.
- Archive and single pages render under both Twenty Twenty-Five (block) and
  Twenty Twenty-One (classic) with a clean `debug.log`.
- No-JS filtering, keyword search matching a job code, REST filtering,
  schema validation (400s), and inactive-job 404s all confirmed over HTTP.
- The five listing screenshots are photographs of these real screens, seeded
  with six demo jobs written through the plugin's own sync path.

**Not verified — the remaining risk**
- **Never talked to Zoho.** OAuth, pagination, `If-Modified-Since`, the
  `/deleted` endpoint and every Zoho error code are written to the documented
  API but untested against a live account. This is the single biggest gap.
- Multisite has not been exercised at all.
- Large-dataset behaviour (2,000+ jobs over many cron batches) is untested.
- The upgrade path has no previous version to upgrade from yet.

---

## 6. What is left to do

1. **Connect a real Zoho Recruit account and walk the flow.** Everything else is
   secondary to this. Watch for: the exact publish-field name, the real field
   API names, whether `Job_Opening_ID` is what your account calls the job code,
   and how the status values map.
2. **`Contributors: senthilnasa` in `readme.txt` must be a real WordPress.org
   account**, or the readme will not parse on the directory. Decide the final
   `Author` and `Plugin URI` values at the same time — they currently point at
   the GitHub repo.
3. Two more screenshots are worth adding once you are connected: the dashboard
   with a real sync in progress, and the sync log with real per-run statistics.
   Neither can be photographed honestly until a sync has actually run. Add them
   as `screenshot-6/7.png` with matching captions in `readme.txt`.
4. Confirm "Tested up to" in `readme.txt` against whatever WordPress is current
   at submission time. It says 7.1 because that is what it was tested on.
5. Multisite pass: network activate, create a site, confirm tables and schedule
   appear, uninstall.
6. Consider renaming the shortcodes to `[jszr_jobs]` etc., keeping the current
   `[zoho_*]` names as aliases. Shortcode names are effectively permanent once
   sites have them in post content, and `[zoho_jobs]` is generic enough that
   another Zoho plugin could plausibly claim it. Cheap now, breaking later.

---

## 7. Useful commands

```bash
# Lint every PHP file (works with no dependencies installed)
find . -path ./node_modules -prune -o -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

composer install && vendor/bin/phpcs        # coding standards
npm install && npm run build                # block assets
npm run lint:js && npm run lint:css

npm run env:start                           # WordPress on :8888
npm run test:php                            # PHPUnit inside wp-env
npm run makepot                             # regenerate the .pot
npm run plugin-zip                          # jobs-sync-for-zoho-recruit.zip

# The live smoke test (writes real posts; dev sites only)
npx wp-env run cli wp eval-file wp-content/plugins/jobs-sync-for-zoho-recruit/bin/smoke-test.php
npx wp-env run cli wp eval-file wp-content/plugins/jobs-sync-for-zoho-recruit/bin/smoke-test.php cleanup

# Plugin Check
npx wp-env run cli wp plugin install plugin-check --activate
npx wp-env run cli wp plugin check jobs-sync-for-zoho-recruit

# Regenerate the WordPress.org listing artwork and screenshots
node .wordpress-org/src/render-assets.mjs
npx wp-env run cli wp eval-file   wp-content/plugins/jobs-sync-for-zoho-recruit/.wordpress-org/src/seed-demo.php
node .wordpress-org/src/render-screenshots.mjs
```

On Windows, prefix `wp-env run` calls that contain a path-like argument with
`MSYS_NO_PATHCONV=1`, or Git Bash rewrites `/wordpress-phpunit` into a Windows
path.

---

## 8. Prompt to resume in a new session

> Continue the WordPress plugin in
> `C:\Development\jobs-sync-for-zoho-recruit` (repo:
> `https://github.com/senthilnasa/wp-jobs-sync-for-zoho-recruit.git`).
> Read `HANDOFF.md` first — it lists what exists, the naming contract, the
> decisions already made, and what is outstanding. Continue from section 6.
> Keep the `jszr_` / `JSZR_` / `JobsSyncForZohoRecruit` naming, build admin URLs
> with `jszr_admin_url()`, and write PHP with the file tools rather than shell
> heredocs (heredocs strip backslashes here).
