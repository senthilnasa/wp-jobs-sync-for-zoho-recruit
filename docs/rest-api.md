# REST API

[← Documentation index](../README.md#documentation)

Namespace: `jobs-sync-zoho-recruit/v1`
Base URL: `https://example.com/wp-json/jobs-sync-zoho-recruit/v1`

Two groups of endpoints:

- **Public** — read-only job data, shaped by an administrator-controlled
  allow-list. No authentication.
- **Protected** — sync control and Zoho diagnostics. Requires the
  `manage_zoho_recruit` capability.

Nothing here contacts Zoho on a visitor's behalf. The public endpoints read the
local copy, so their latency has nothing to do with Zoho's.

---

## Public endpoints

### `GET /jobs`

A page of jobs.

| Parameter | Type | Default | Notes |
| --- | --- | --- | --- |
| `page` | integer ≥ 1 | `1` | |
| `per_page` | integer | Setting (20) | Capped by **Settings → Public API → Maximum per page**. Above it, **400** |
| `search` | string | — | Matches title, content **and job code** |
| `orderby` | enum | `date` | `date`, `title`, `closing_date`, `posted_date` |
| `order` | enum | `desc` | `asc`, `desc` |
| `status` | enum | `active` | `active`, `inactive`, `expired`, `closed`, `any` |
| `department` | string | — | Comma-separated term slugs or names |
| `location` | string | — | Comma-separated |
| `employment_type` | string | — | Comma-separated |
| `category` | string | — | Comma-separated |
| `experience` | string | — | Comma-separated |

Every parameter is validated against its schema. An out-of-range `per_page` or
an unknown `orderby` returns `400`, it is not silently corrected.

`status=any` is honoured only when **Allow inactive jobs** is enabled or the
caller can `manage_zoho_recruit`. For everyone else it quietly becomes
`active`, so the endpoint cannot be used to enumerate unpublished roles.

```
GET /wp-json/jobs-sync-zoho-recruit/v1/jobs?department=engineering&location=chennai&per_page=20
```

```json
{
  "success": true,
  "data": [
    {
      "id": 142,
      "title": "Senior PHP Developer",
      "slug": "senior-php-developer",
      "link": "https://example.com/jobs/senior-php-developer/",
      "excerpt": "Build and maintain the platform…",
      "content": "<p>…</p>",
      "job_code": "JOB-001",
      "status": "active",
      "department": [ { "name": "Engineering", "slug": "engineering" } ],
      "location":   [ { "name": "Chennai", "slug": "chennai" } ],
      "city": "Chennai",
      "state": "Tamil Nadu",
      "country": "India",
      "remote": "Yes",
      "employment_type": [ { "name": "Full time", "slug": "full-time" } ],
      "experience": [ { "name": "2 - 5 years", "slug": "2-5-years" } ],
      "salary": "INR 1200000 - 1800000 per year",
      "posted_date": "2026-01-05",
      "closing_date": "2026-12-31",
      "apply_url": "https://example.com/apply/job-001"
    }
  ],
  "pagination": { "page": 1, "per_page": 20, "total": 34, "total_pages": 2 }
}
```

`X-WP-Total` and `X-WP-TotalPages` are sent as well, so clients written against
core's conventions work unchanged.

### `GET /jobs/{id}`

One job by WordPress post ID. Same object as a collection entry.

Returns **404** for a job that is not active, unless "Allow inactive jobs" is on
or the caller can manage the plugin — the same response an unknown ID gets, so
the endpoint cannot confirm that a hidden job exists.

### `GET /jobs/filters`

Every term that currently has at least one active job, with counts. This is
what you build a filter UI from — it saves a round trip per taxonomy and never
offers a filter that would return nothing.

```json
{
  "success": true,
  "data": {
    "department": [ { "name": "Engineering", "slug": "engineering", "parent": 0, "count": 12 } ],
    "location":   [ { "name": "Chennai", "slug": "chennai", "parent": 6, "count": 5 } ],
    "employment_type": [],
    "category": [],
    "experience": []
  }
}
```

`parent` is the parent term ID, non-zero for hierarchical locations.

### Field allow-list

The keys in a job object are exactly the fields ticked in **Settings → Public
API → Fields exposed through the API**. Untick `content` and no response
carries it. The default set is the minimum that makes a useful careers page.

Internal values — the Zoho record ID, the raw Zoho JSON, sync hashes — are
never in the list and cannot be added through settings.

The same allow-list governs the core `wp/v2/zoho_job` endpoint: meta is
registered with `show_in_rest` only for fields you allowed, so the two
endpoints cannot disagree about what is public.

### Caching

Responses are cached in the object cache (or a transient) for **Settings →
Public API → Cache TTL** seconds, default 300. Anonymous responses also carry
`Cache-Control: public, max-age=<ttl>`; responses to logged-in users are not
cached and carry no cache header.

The cache is invalidated by a version bump — one option write clears
everything at once — when a sync completes, when a job is created, edited,
trashed or deleted, and when settings change. Set the TTL to `0` to disable
caching.

### Disabling the public API

**Settings → Public API → Enable the jobs API.** When off, all three public
endpoints return 404. The shortcode, block and templates keep working: they
read the database directly, not the API.

---

## Protected endpoints

All require `manage_zoho_recruit` and return 401/403 otherwise. Authenticate as
you would for any WordPress REST call — a logged-in session with an
`X-WP-Nonce` header, or application passwords.

### `POST /sync`

Start a sync. Returns immediately; the work happens in cron batches.

| Parameter | Type | Default |
| --- | --- | --- |
| `type` | `full` \| `incremental` | `incremental` |
| `dry_run` | boolean | `false` |

```json
{ "success": true, "data": { "run_id": 17, "state": "pending" } }
```

Refuses with an error if a sync is already running — there is one lock, and
every trigger respects it.

### `GET /sync/status`

Progress for a run. Without `run_id`, reports the active run, or the most
recent one if none is active.

```json
{
  "success": true,
  "data": {
    "run": {
      "id": 17, "type": "full", "state": "running",
      "processed": 400, "total": 1240, "page": 3,
      "created": 12, "updated": 388, "skipped": 0,
      "deactivated": 0, "expired": 0, "orphaned": 0, "errors": 0
    },
    "state": { "last_sync": "…", "last_success": "…", "last_error": "" },
    "counts": { "total": 128, "active": 96, "inactive": 20, "expired": 12, "closed": 0, "draft": 0 }
  }
}
```

This is what the dashboard polls, which is why a manual sync shows live
progress instead of holding a request open.

### `POST /sync/cancel`

Stops the active run and unschedules its remaining batches.

### `POST /jobs/{id}/resync`

Re-fetches one job from Zoho by its stored record ID and rewrites it. This is
the "Resync from Zoho" row action. It also clears the manual-edit guard for
that job, so it is how you hand a hand-edited job back to Zoho.

### `GET /zoho-fields`

The Job Opening field list used by the mapping screen. `?refresh=true` bypasses
the cache and asks Zoho again.

### `POST /test-connection`

One authenticated call to Zoho. Returns the module name and the number of job
openings it reports, or a readable error.

### `POST /webhook`

Not capability-protected — it is called by Zoho, not by a user. It is protected
by the per-site secret token instead, and **the payload is never trusted as
data**: it is only a trigger to re-fetch the named record over the
authenticated connection. See [Troubleshooting](troubleshooting.md) and
**Settings → Advanced** for the URL.

Disabled unless **Settings → Advanced → Enable webhook** is on, in which case
it returns 404.

---

## Errors

Errors use WordPress's standard shape, with the HTTP status in `data.status`:

```json
{
  "code": "jszr_forbidden",
  "message": "You do not have permission to manage Zoho Recruit synchronization.",
  "data": { "status": 403 }
}
```

| Code | Status | Meaning |
| --- | --- | --- |
| `jszr_rest_disabled` | 404 | The public API is switched off |
| `jszr_job_not_found` | 404 | Unknown, or not visible to this caller |
| `jszr_forbidden` | 401/403 | Capability check failed |
| `rest_invalid_param` | 400 | A parameter failed its schema |
| `jszr_sync_locked` | 409 | A sync is already running |
| `jszr_not_synced` | 400 | Resync asked for on a job that never came from Zoho |
| `jszr_not_connected` | 400 | No Zoho connection |
| `jszr_webhook_forbidden` | 403 | Bad webhook token |
| `jszr_webhook_rate_limited` | 429 | More than 120 webhook calls in an hour |

Zoho's own errors are translated into readable messages before they reach you,
and never include a token or a raw API response.

---

## Extending

```php
// Narrow or widen the collection query.
add_filter( 'jszr_rest_query_args', function ( $args, $request ) {
	$args['meta_query'][] = array(
		'key'     => '_zoho_recruit_remote',
		'value'   => 'Yes',
	);
	return $args;
}, 10, 2 );

// Add a field to every job object.
add_filter( 'jszr_rest_job_data', function ( $data, $post ) {
	$data['team_page'] = get_post_meta( $post->ID, '_my_team_page', true );
	return $data;
}, 10, 2 );
```

Both are documented with the rest in [Hooks](hooks.md).
