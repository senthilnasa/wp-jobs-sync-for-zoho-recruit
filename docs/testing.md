# Testing

[← Documentation index](../README.md#documentation)

Four layers, cheapest first:

| Layer | Command | Needs |
| --- | --- | --- |
| PHP syntax | `find . -name '*.php' | xargs -n1 php -l` | PHP |
| Coding standards | `composer run lint` | `composer install` |
| Unit and integration | `npm run test:php` | Docker (wp-env) |
| Live smoke test | `wp eval-file bin/smoke-test.php` | A running WordPress |
| Plugin Check | `wp plugin check jobs-sync-for-zoho-recruit` | The Plugin Check plugin |

## Setting up

```bash
composer install     # PHPCS, WPCS, PHPCompatibility, PHPUnit 9.6
npm install          # block build tooling and wp-env
npm run env:start    # WordPress on :8888, test site on :8889
```

`npm run env:start` needs Docker Desktop running. It takes a few minutes the
first time while it pulls images.

## Coding standards

```bash
composer run lint      # PHPCS against the WordPress standard
composer run lint:fix  # PHPCBF, for what can be fixed mechanically
```

The ruleset is `phpcs.xml`: the full `WordPress` standard plus
`PHPCompatibilityWP` at `8.1-`, minimum WordPress 6.6.

**The bar is zero errors and zero warnings.** Where a rule is deliberately
suppressed there is a `phpcs:ignore` with a reason on the same line — an
unexplained suppression should not survive review.

## PHPUnit

```bash
npm run test:php
```

That runs the suite inside wp-env against the WordPress test suite. To run it
outside Docker instead:

```bash
bash bin/install-wp-tests.sh wordpress_test root root localhost latest
vendor/bin/phpunit
```

PHPUnit is pinned to `^9.6` because that is what the WordPress core test suite
supports. PHPUnit 10 loads the bootstrap but cannot discover test classes whose
file names follow the WordPress convention.

| File | Covers |
| --- | --- |
| `tests/phpunit/field-mapper-test.php` | Transforms, salary parsing, timezone handling, the derived location path, mapping sanitization |
| `tests/phpunit/sync-logic-test.php` | Status mapping, the expiry decision, the publish filter, duplicate prevention, the deactivation threshold |
| `tests/phpunit/rest-api-test.php` | Pagination, filters, sorting, the field allow-list, `per_page` capping, inactive-job enumeration, endpoint permissions |

`Field_Mapper` is the best unit-test surface in the plugin: it writes nothing to
the database, so its tests are pure input/output.

> `WP_UnitTestCase` unregisters every meta key between tests, and `init` does
> not fire again. A test that depends on registered meta has to replay
> `Post_Type::register_meta()` in its `set_up()` — `rest-api-test.php` does.

## The live smoke test

`bin/smoke-test.php` pushes three synthetic Zoho records through the real sync
path — the mapper, the writer, the taxonomies, the REST layer, the shortcode
and the structured-data builder — and asserts 37 things about the result. It is
how you exercise the whole pipeline without a Zoho account.

```bash
npx wp-env run cli wp eval-file wp-content/plugins/jobs-sync-for-zoho-recruit/bin/smoke-test.php
npx wp-env run cli wp eval-file wp-content/plugins/jobs-sync-for-zoho-recruit/bin/smoke-test.php cleanup
```

It writes real posts, so run it against a development site only. `cleanup`
removes what it created; run it before a re-run so you start clean.

It is not shipped in the release ZIP.

## Plugin Check

```bash
npx wp-env run cli wp plugin install plugin-check --activate
npx wp-env run cli wp plugin check jobs-sync-for-zoho-recruit
```

Run against a **checkout**, Plugin Check also inspects development files —
`phpunit.xml.dist`, `bin/`, `tests/`, dotfiles — none of which ship. What
matters is that nothing is reported against a file in the `files` list in
`package.json`. Check the packaged ZIP with `npm run plugin-zip` if you want
the exact review view.

Two warnings are expected and accepted: `Logger::get_entries()` and
`Sync_Queue::increment()` assemble SQL from an internal column allow-list
before `$wpdb->prepare()`, which the sniff cannot see through. Every table
identifier goes through `prepare()`'s `%i` placeholder.

## JavaScript and CSS

```bash
npm run lint:js
npm run lint:css
npm run build
```

`blocks/jobs/build/` is committed so the plugin runs from a plain checkout. If
you edit `blocks/jobs/src/index.js`, rebuild and commit the result.

## Continuous integration

`.github/workflows/ci.yml` runs on every push and pull request:

- PHP syntax on 8.1, 8.2 and 8.3
- PHPCS
- PHPUnit against MySQL 8
- ESLint, Stylelint and the block build

## Manual test matrix

Automation does not cover everything. Before a release, walk these.

### Authentication

- [ ] First connection end to end
- [ ] Callback with a missing or tampered `state` is rejected
- [ ] Callback as a different user than the one who started is rejected
- [ ] Expired access token refreshes silently
- [ ] Revoked refresh token surfaces an admin notice
- [ ] Circuit breaker opens after five failures and closes on reconnect
- [ ] Rotating the salts in `wp-config.php` produces the "cannot decrypt"
      Site Health result, not a fatal error
- [ ] Disconnect revokes at Zoho and clears the tokens

### Synchronization

- [ ] Empty account (HTTP 204) reports success, not failure
- [ ] One job
- [ ] More than 200 jobs, so pagination runs
- [ ] More than 2,000 jobs, over several cron batches
- [ ] New, updated, closed and expired jobs
- [ ] A job deleted in Zoho, through the `/deleted` endpoint
- [ ] The publish filter, on and off
- [ ] An API failure mid-run, then resume from the checkpoint
- [ ] Two triggers at once — the second must refuse, not duplicate
- [ ] Manual-edit preservation in conflict mode 3
- [ ] The deactivation threshold blocks a suspicious full sync
- [ ] Dates land correctly with the site in a non-UTC timezone
- [ ] A webhook refreshes exactly one record

### REST API

- [ ] Pagination, search, every filter, every sort
- [ ] Invalid parameters return 400
- [ ] `per_page` above the maximum returns 400
- [ ] Anonymous callers cannot reach `/sync`
- [ ] An inactive job returns 404 on the single endpoint
- [ ] The field allow-list is honoured on both this API and `wp/v2/zoho_job`

### Frontend

- [ ] Shortcode, block, classic theme, block theme
- [ ] A template override in `yourtheme/jobs-sync-for-zoho-recruit/`
- [ ] Filtering with JavaScript disabled
- [ ] Keyboard navigation and visible focus throughout
- [ ] All three expired-job behaviours
- [ ] Structured data passes Google's Rich Results test
- [ ] No duplicate `JobPosting` when Yoast or Rank Math is active

### WordPress

- [ ] Activate, deactivate, reactivate
- [ ] Upgrade from a previous version leaves settings intact
- [ ] Uninstall, both keep and delete
- [ ] Multisite, network activated and per site
- [ ] A new site created in a network gets the tables and schedule
- [ ] A non-standard `$table_prefix`
- [ ] PHP 8.1 through the current release

## What has been verified

As of version 1.0.0, on WordPress 7.1 with PHP 8.1 in wp-env:

- PHPCS: 0 errors, 0 warnings across 46 files.
- PHPUnit: 47 tests, 84 assertions, all passing.
- Smoke test: 37 checks, all passing.
- Plugin Check: nothing against any file that ships.
- Every admin screen rendered — dashboard, all six settings tabs, field
  mapping, sync logs, the job list table with its custom columns and filter.
- The block renders in the editor and on the front end, with no console errors.
- Archive and single pages render on both a block theme (Twenty Twenty-Five)
  and a classic theme (Twenty Twenty-One), with no PHP notices.
- No-JavaScript filtering, keyword search matching a job code, and REST
  filtering all confirmed against a live site.

**Not yet verified:** anything requiring a real Zoho Recruit account. The OAuth
exchange, pagination over a live dataset, `If-Modified-Since`, the `/deleted`
endpoint and Zoho's error codes are all written to the published API but have
not been run against it. Multisite has not been exercised either.
