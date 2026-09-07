# CLAUDE.md

Instructions for working in this repository. Loaded automatically each session.

**Jobs Sync for Zoho Recruit** — a public WordPress plugin (WordPress.org
target) that syncs Job Openings one-way from Zoho Recruit into a `zoho_job`
post type and publishes them via shortcode, block, templates and REST.

## Read this first

`HANDOFF.md` is the source of truth for the file map, the decisions already
made, what is verified, and what is outstanding. Read it before changing
anything, and update it when the state changes. Do not reconstruct that
information from the code.

## Naming contract — never drift

| Thing | Value |
| --- | --- |
| Slug / main file | `jobs-sync-for-zoho-recruit` / `jobs-sync-for-zoho-recruit.php` |
| Text domain | `jobs-sync-for-zoho-recruit` |
| Namespace | `JobsSyncForZohoRecruit` |
| Function / option / transient / hook prefix | `jszr_` |
| CSS class prefix | `jszr-` · Constant prefix `JSZR_` |
| REST namespace | `jobs-sync-zoho-recruit/v1` · CLI `wp jszr` |
| Post type | `zoho_job` · Taxonomies `zoho_job_*` |
| Meta | `_zoho_recruit_*` (Zoho values), `_jszr_*` (plugin internals) |
| Capability | `manage_zoho_recruit` |

No slug or file name may begin with "Zoho" — WordPress.org trademark rule.
Describing the plugin as a "Zoho Recruit jobs sync" in prose is fine.

## Invariants — do not break these

1. **One Zoho record maps to exactly one post.** Every write goes through
   `Job::find_by_zoho_id()` behind a named lock. No code path may create a job
   any other way.
2. **A failed sync never deactivates anything.** Missing-job deactivation runs
   only in the final batch of a full sync, only when every page succeeded, and
   only under the configurable percentage threshold.
3. **Zoho is never contacted while rendering a page for a visitor.** The
   frontend reads local WordPress data only.
4. **Manually created jobs are untouchable.** A `zoho_job` post with no
   `_zoho_recruit_id` is never deactivated, expired, orphaned or deleted.
5. **Secrets never leave the server.** Not in logs, REST responses, error
   messages, exports or frontend JavaScript.

## House rules

- **Admin URLs:** always `jszr_admin_url( $slug, $args )`. The plugin's screens
  are submenus of the job post type menu, so a hard-coded `admin.php?page=`
  produces a broken link.
- **Nonce checks:** use the global `jszr_verify_admin_request( $action )` in
  `admin_post_*` handlers, so PHPCS and Plugin Check both see the check at the
  call site.
- **Writing PHP:** use the Write/Edit tools. Bash heredocs strip backslashes on
  this machine, which silently corrupts namespaces and regexes.
- **No AI attribution in the product.** No Claude or AI signature in plugin
  headers, readme, docblocks, changelog or generated assets. (Git commit and PR
  attribution is governed separately by the harness.)
- **Escaping is not optional.** Every echoed value goes through `esc_html`,
  `esc_attr`, `esc_url` or `wp_kses_post`. All Zoho HTML is `wp_kses_post`.
- **Options that can grow are `autoload = false`.** Settings, mapping, tokens,
  field cache.
- **Block source and build must stay in step.** `blocks/jobs/src/index.js` is
  the source; run `npm run build` rather than hand-editing `build/`.

## Commands

```bash
composer install && vendor/bin/phpcs        # coding standards — must be clean
npm install && npm run build                # block assets
npm run lint:js && npm run lint:css

npm run env:start                           # WordPress on :8888 (needs Docker)
npm run test:php                            # PHPUnit inside wp-env
npm run makepot                             # regenerate the .pot
npm run plugin-zip                          # release ZIP

npx wp-env run cli wp eval-file wp-content/plugins/jobs-sync-for-zoho-recruit/bin/smoke-test.php
npx wp-env run cli wp plugin check jobs-sync-for-zoho-recruit
```

No global WP-CLI on this machine — everything goes through `wp-env run cli`.
On Windows Git Bash, prefix `wp-env run` calls containing a path-like argument
with `MSYS_NO_PATHCONV=1`.

## Definition of done for a change

1. `php -l` on every touched file.
2. PHPCS clean (0 errors, 0 warnings).
3. PHPUnit green, with a new test when the change is behavioural.
4. `bin/smoke-test.php` passing if the change touches sync, jobs or REST.
5. Plugin Check clean if the change touches a shipped file.
6. New user-facing strings wrapped in `__()` with the text domain, and the
   `.pot` regenerated.
7. New hooks documented in `docs/hooks.md`.
8. `HANDOFF.md` updated if the change alters state, decisions or what is left.

## Honesty about state

The plugin has never talked to a live Zoho account. OAuth, pagination,
`If-Modified-Since`, the `/deleted` endpoint and every Zoho error code are
written to the documented API but unverified. Multisite and large datasets are
also unexercised. Do not describe any of that as working.
