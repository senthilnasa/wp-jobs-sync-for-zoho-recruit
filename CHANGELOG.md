# Changelog

All notable changes to Jobs Sync for Zoho Recruit are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-01

Everything in this release is an option that is off by default, or an addition
to what a template may contain. A site that updates and changes nothing looks
and behaves exactly as it did.

### Added

- **Named card templates.** Settings → Display holds any number of extra card
  designs, each with a short name, and a listing picks one with
  `template="name"`. A site with faculty roles on one page and staff openings on
  another no longer has to share a single custom card. `jszr_named_templates`
  filter.
- **Shortcodes panel** on the Display tab: a ready-to-paste shortcode for every
  term in every filterable taxonomy, plus the related-openings pattern.
- Card tags `{posted_ago}` ("3 weeks ago", from Zoho's posted date with the
  publish date as fallback), `{org_name}` (the Structured Data organisation, or
  the site title) and `{status_label}` (Open, Closed or Expired).
- **Filter style**: dropdowns or pills. Pills are radio buttons styled as chips
  and apply on click; the Filter button still submits them without JavaScript.
- **Filter labels**: rename "Department" to "Functional area" or "Category" to
  "School" for visitors without renaming the taxonomy.
- **Application status filter**, opt-in. While it is off, a `jszr_status` URL
  parameter is ignored, so a crafted link cannot list closed jobs.
- **Search settings**: label, placeholder, which of title, summary, description
  and job code a keyword must match, and an optional second text box that
  searches the location terms and each job's city, state and country.
- **Load more** pagination: one button that appends the next page's cards in
  place, falling back to a plain link to the next page.
- `exclude` and `related` shortcode attributes. `related="department"` on a job
  page lists other jobs in the same department and leaves the current one out.
  Both are also REST parameters on `/jobs` and `/jobs/render`, as is
  `location_search`; `template` is accepted by `/jobs/render`.
- Job and archive slugs may be a path (`careers/openings`), so job pages can sit
  under a careers section whose sub-pages are ordinary WordPress pages.
- **Raw HTML** mapping transform. The existing HTML transform strips inline
  styles and empty paragraphs so a description takes the site's typography;
  Raw HTML keeps Zoho's formatting as written -- inline styles, spacing, tables
  -- for a site that wants the description to look exactly as it does in Zoho.
  It still passes `wp_kses_post()`, so scripts, event handlers and frames are
  removed; "raw" means unformatted, not unfiltered.

### Changed

- Custom card and job-details HTML may contain inline `svg` icons (and their
  drawing children), `button`, `picture` and `source`. Inline styles keep
  `list-style`, `inset`, `transition` and a few other layout properties that
  WordPress strips from post content; the widening applies only while the
  plugin filters its own templates, never to post content. Scripts, event
  handler attributes, `use`, frames and forms are still removed.
  `jszr_allowed_template_css` filter.

### Fixed

- Filtering a listing on a page whose URL already carried a query string (for
  example a tracking parameter) produced a URL with two question marks. The
  filter form now replaces the query string instead of appending to it.
- Moving between pages of a listing could end in the "Unable to load jobs"
  panel on sites where the in-place request does not come back as expected
  (a security layer answering XMLHttpRequest differently, a cache or proxy
  returning a stripped page). The pagination links and the filter form always
  point at a real server-rendered page, so the script now falls back to a plain
  navigation to that page instead of showing the panel, and logs the reason to
  the browser console. The panel remains only where navigating would reload
  the current URL. The request itself no longer carries an `X-Requested-With`
  header, which nothing on the server read and which is what lets a firewall
  or proxy treat it differently from a click.
- **Stale rewrite rules are repaired automatically.** The failing page changes
  on a live site turned out to be WordPress answering 404 for every job URL
  that bypassed the page cache: the rules for the post type were missing from
  the site, while the cached `/jobs/` page hid it. The plugin now checks on
  each request that its rules exist and regenerates them when they do not, at
  most once an hour, logging `rewrite_rules_healed`.
- Choosing another page landed the visitor at the top of the page. Each listing
  now has its own `id`, every pagination link carries it as a fragment, and the
  in-place swap scrolls to the start of the listing, so page two opens on the
  jobs whether the request was in place, a plain navigation or the fallback.

## [1.0.1] - 2026-09-18

### Changed

- The Apply button now links to the **Job Apply URL that Zoho Recruit returns
  for the job**. Zoho Recruit support confirmed that the link is only included
  in a Get Record by ID response when the request carries `publish_URL=true`;
  the list endpoint never sends it. `Zoho_API::get_record()` now always asks
  for it, and the sync fetches each active job that has no stored link once
  more by ID and keeps what it finds. A job is fetched individually at most
  once, and again only on a forced re-sync; a stored link is carried over so
  the overwrite-all conflict mode cannot clear it. The link found this way
  takes precedence over the `Website` mapping row, which is the client's own
  site rather than an application form.
- The career site address is now the fallback for jobs that have no fetched
  link, rather than the only source.

### Added

- **Apply link from Zoho** setting on the Frontend tab (`fetch_apply_url`, on
  by default). Turning it off restores the previous behaviour of building every
  link from the career site address.
- `jszr_record_query_args` filter on the query string of a Get Record by ID
  request, and `jszr_apply_url_fields` filter on the record keys checked for
  the Job Apply URL. Zoho's documentation does not name the key, so the plugin
  tries a short list and then any key mentioning "apply" and "url".
- The diagnostics report and the troubleshooting guide explain how the link is
  fetched and what to do when it still does not appear.

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
- A Display settings tab that covers the customization that previously needed a
  child theme: listing layouts (Default, Card, Compact, Columns, Custom), job
  detail layouts (Default, Inline, Custom), a custom CSS box, and control over
  which search, filter and sort controls visitors get, how the filter bar is laid
  out, and what its labels say.
- Custom layouts are token templates — `{title}`, `{salary}`, `{apply_button}`,
  with `{if:token}` and `{ifnot:token}` conditionals — never PHP. The plugin does
  not evaluate what an administrator types, because an option holding executable
  code is a remote code execution hole waiting for one weak password. Template
  HTML is filtered through an allow-list on save, token values are escaped as
  they are substituted, and unrecognised tokens are dropped rather than printed.
  Custom CSS is stripped of markup, `@import`, `expression()` and script URLs on
  save and again on output.
- A setting for whether the Apply link opens in a new tab or the same tab.
  Applications are completed on Zoho Recruit, not on this site: the plugin links
  out rather than embedding Zoho's form, which is what lets it say no candidate
  data touches WordPress. Every route to that button now runs through one
  builder, so the shortcode, the block, the {apply_button} token and the single
  job template cannot drift apart.
- A sort control for visitors (newest, oldest, title, closing soonest), off by
  default, sharing the same GET form and the same validation as the REST
  endpoint so it keeps working with JavaScript disabled.
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
- A Sync Now button that reads every job from Zoho and rewrites all of them,
  including fields edited in WordPress. The forced flag is stored on the run
  itself rather than held in memory, so it survives the batches the sync is
  broken into and shows up afterwards in the run's own record. Full Sync and
  Incremental Sync are unchanged and still respect the conflict setting.
- A "check everything" button on the Sync Logs screen. It walks the encryption
  support, the credentials, the connection, the security salts, the circuit
  breaker, the database tables, the schedule and WP-Cron, then, when the site is
  connected, obtains an access token and reads the job openings module for real.
  The result is shown as a pass/fail table with a full report underneath, and
  can be downloaded as a plain text file to send to whoever is helping. Every
  value in it goes through the log scrubber first, so tokens, secrets and client
  IDs are removed and the notification address is left out entirely.
- Site Health tests for the connection, scheduling, sync freshness, encryption
  availability, HTTPS and URL slug conflicts.
- WP-CLI commands under `wp jszr`, including `--dry-run`.
- Privacy policy suggestion, multisite-aware activation and uninstall, and an
  uninstall routine that keeps jobs unless you ask for them to be removed.

- A careers hero above the job archive, with the heading, eyebrow and lead all
  filterable, and an escape hatch that removes it entirely.
- A redesigned public listing: a design system of custom properties on
  `.jszr-scope`, job cards carrying the job code and icon-led facts for
  location, employment type, department and experience, a filter bar that holds
  search, filters and sort together, and a two-column job page whose apply panel
  stays in view while the description scrolls. Setting `--jszr-accent` restyles
  the whole thing.
- Four distinct listing states rather than two. A request in flight shows
  skeleton cards, an empty result shows an empty state that says whether it is
  empty because nothing is open or because a filter excluded everything, and a
  failed request shows an error panel with a retry button instead of claiming
  there are no jobs. The enhanced path swaps in server-rendered HTML, so the
  markup cannot drift from the no-JavaScript path, which still works.
- A redesigned admin: statistics tiles linking into pre-filtered job lists, a
  recent-jobs table that becomes labelled rows rather than a sideways scroll on
  a phone, a Source column, dropdown filters for department, location and
  employment type, job-code search, and an edit-screen panel grouped into
  synchronization, basic information and actions.

- A **Disable default jobs frontend** setting, off by default. Turning it on
  stops the plugin rendering the job archive and single job pages -- templates,
  frontend assets, block templates and the expired-job redirect -- so a theme,
  a page builder or a custom frontend can own them. The URLs, the post type,
  the admin, the sync, the REST API and the structured data are untouched. It
  is deliberately separate from *Public job pages*, which unregisters the URLs
  altogether.
- `jszr_get_jobs()` and `jszr_get_job()`, which run the same query and return
  the same shape as the REST API, so a hand-written template cannot end up with
  a different set of jobs than the shortcode or the block.
- `jszr_before_jobs`, `jszr_after_jobs`, `jszr_before_job` and `jszr_after_job`
  actions, and `jszr_frontend_enabled`, `jszr_jobs_template`,
  `jszr_job_template`, `jszr_jobs_query_args` and `jszr_job_data` filters.
- `GET /jobs/render`, which answers with rendered markup, the data behind it and
  pagination in one response, with `redirect` always false, so a custom
  frontend can search, filter and page without a navigation. Added to the
  existing REST namespace rather than as a second admin-ajax layer.

- A **Zoho career site address** setting. Zoho's Job Openings API sends no link
  to the public job posting -- there is no field for it -- so on most accounts
  every job synced with an empty application URL and no apply button was ever
  rendered. Given the address of your career site the plugin now builds the
  link from the record ID the sync already stores. The record ID is what
  resolves the posting; anything after it is decoration and is ignored by Zoho,
  so a de-duplicated WordPress slug cannot break it.
- A **Start again** section on the Advanced settings tab, which deletes the
  synchronized jobs, the sync history and the field mapping so a full sync can
  rebuild them. Un-mapping a field stops the plugin writing it but does not
  remove what it already wrote, so a mapping mistake can outlive the mapping by
  hundreds of posts; this is the way back. Zoho keeps the originals, jobs added
  by hand in WordPress are never touched, and the connection and settings are
  left alone. Each part is chosen separately and the word DELETE has to be
  typed.
- Each source for the apply link is now checked for being a link before it is
  accepted, and a source that is not one is skipped rather than used. A live
  site had every apply button pointing at `http://ZR_17_JOB`: a job code had
  reached the resolver, and `esc_url_raw()` -- a sanitizer, not a validator --
  turned the bare word into a host. Because the result was non-empty it counted
  as a hit, so the career site link was never reached. Absolute http(s) links
  and site-relative paths are accepted; anything else falls through to the next
  source, and a job with nothing usable gets no button rather than a broken one.
- A **Job posting path** setting alongside it, so the shape of that link is
  configuration rather than a constant. It defaults to
  `/jobs/Careers/{zoho_id}/` and accepts `{zoho_id}`, `{job_code}`, `{slug}`,
  `{title}` and `{id}`. Career sites run on custom domains as often as on
  zohorecruit.com, and an account that arranges its postings differently should
  not need a code change to link to them. The value is treated as a path: a
  scheme or a `//host` prefix is stripped rather than followed, so it cannot
  redirect candidates to another site.
- The diagnostic report counts active jobs with no application link, so an
  invisible apply button reports itself.
- The diagnostic report checks the whole field mapping against the Zoho field
  names this site has actually seen -- both the module layout and the last
  synced record, because the two differ and Posting_Title is on records without
  being on the layout -- and names anything that appears in neither. Every
  Zoho account renames fields, and a mapping pointing at one that does not exist
  fails silently: the sync succeeds and one value is quietly empty on every job
  forever. The configured publish flag field is checked the same way, since a
  wrong name there silently disables "only sync published jobs".

- The field discovery warning says what to change. "Reconnect to grant the
  requested permissions" is no help when the permission was never requested, so
  when `ZohoRecruit.settings.fields.READ` is missing from the configured scopes
  the report now says to add it before reconnecting.

### Fixed

- The PHPUnit workflow failed on a fresh runner with `svn: command not found`.
  `bin/install-wp-tests.sh` checks the WordPress test suite out of
  `develop.svn.wordpress.org`, and Subversion is not on the ubuntu-24.04 image
  that `ubuntu-latest` now resolves to. The workflow installs Subversion and the
  MySQL client -- the script calls `mysqladmin` too -- before running the
  script, and verifies both are present.

- Job listings could be served indefinitely from a stale page cache. The purger
  covered nine caching plugins but not Breeze, the one that ships with
  Cloudways, so a site running it kept serving whatever the archive looked like
  when the page was first cached -- including an empty one -- however many times
  the sync ran afterwards. Breeze is covered now, along with WP Fastest Cache,
  Hummingbird, Comet Cache, WP Engine and Pantheon.
- Keyword search returned nothing on sites running a search plugin. The listing
  asked for its keywords through `s`, which SearchWP, Search & Filter and
  Relevanssi all take over, and a plugin that has not indexed the job post type
  answers with nothing at all. The listing now matches title, summary, body and
  job code in its own WHERE clause, so it no longer depends on `s`, on core
  having built a search clause, or on a JOIN added by another filter.
- The job archive rendered through two different code paths: it looped the main
  query when nothing was filtered and handed over to the shared renderer once a
  filter appeared. The plain archive therefore had no result count, no sort
  control and a thinner empty state than the filtered one, and a bug in either
  path was invisible from the other. Both now go through the shared renderer.
- The job list in the admin registered ten columns before the title column had
  anywhere to go. The rarely scanned ones now start hidden, and Screen Options
  brings any of them back.

- A sync run abandoned by a killed process no longer blocks every later sync.
  The lock already expired on its own, but the run row kept saying "running"
  forever, and `Sync::start()` refuses to begin while a run is active — so one
  crashed batch would have stopped syncing permanently, cron included, until an
  administrator noticed and clicked Cancel. A run with no update for well over a
  batch cycle *and* nothing queued to continue it is now closed automatically.
  Found by killing a batch mid-run during testing.
- The default records-per-batch is now 200, matching the records-per-request
  default. A batch reads one API page and writes `batch_size` records from it,
  so the previous default of 50 made every 200-record page be read four times,
  quadrupling the Zoho API credits a sync spent. Site Health now warns when the
  two are configured that way on purpose.
- `uninstall.php` guards its function declarations, so including it twice in one
  process cannot fatal on a redeclaration.
- Data migrations run on `init` rather than `plugins_loaded`. The upgrade
  routine flushes rewrite rules, and `$wp_rewrite` does not exist that early, so
  registering the post type there was a fatal error. The bug had been latent
  since the routine was written — nothing had ever bumped the data version, so
  the path never ran. The first bump would have white-screened every site on
  upgrade. A test now asserts the hook.
- The job openings OAuth scope is `ZohoRecruit.modules.jobopening.READ`, with
  the module name singular. Zoho's documentation writes the plural in its
  worked examples and the singular in its scope-name table on the same page;
  only the singular is accepted, and the plural fails every connection attempt
  with "Invalid OAuth Scope / Scope does not exist", naming neither the scope
  nor the reason. A stored plural value is corrected on upgrade.
- The requested OAuth permissions are editable on the Connection screen. Zoho
  refuses an authorization request when any single scope is unrecognised for
  that account, and its error does not say which one, so a hard-coded list left
  a site with no way forward. Only `ZohoRecruit.modules.jobopenings.READ` is
  actually required; the field-discovery scope is a convenience that already
  falls back to a bundled field list, and it can now be removed without editing
  code. The error message for a refused scope says so.

[1.0.0]: https://github.com/senthilnasa/wp-jobs-sync-for-zoho-recruit/releases/tag/v1.0.0
