# Building your own jobs frontend

[← Documentation index](../README.md#documentation)

The plugin ships a complete jobs frontend, and by default it renders it. This
page is for the case where you want to build your own: a bespoke archive, a
redesigned job page, or a JavaScript frontend that never reloads.

Nothing here requires editing a file inside the plugin.

---

## The two modes

### Default mode — the plugin renders the job pages

**Settings → Frontend → Default jobs frontend → unchecked.** This is the
default, and it is what every existing site keeps. `/jobs` and
`/jobs/{job-slug}` render the plugin's own archive and single templates.

### Custom mode — you render the job pages

**Settings → Frontend → Default jobs frontend → "Disable the default jobs
frontend".**

The plugin stands down on those two page types. WordPress resolves the template
through its ordinary hierarchy instead, so your theme file, page builder
template or custom controller gets the request with nothing in the way.

What stands down:

- the archive and single job templates
- the plugin's frontend stylesheet and script, on those pages
- the block templates registered for block themes
- the expired-job redirect and `410` response

What does not change, at all:

- the URLs. `/jobs` and `/jobs/{job-slug}` still resolve, still return the right
  queried object, and still are not 404s
- the `zoho_job` post type, its taxonomies and all its meta
- every admin screen, the job list, the editor and the sync controls
- synchronization with Zoho Recruit, on schedule and on demand
- the REST API, including the endpoints below
- the shortcodes and blocks, which still render wherever you place them
- JobPosting structured data and the sitemap
- WP-CLI, webhooks, logging and notifications

The setting is deliberately separate from **Public job pages**. That one
unregisters the URLs entirely; this one keeps them and only stops the plugin
drawing on them.

```php
// Take the pages over conditionally instead of with the setting.
add_filter( 'jszr_frontend_enabled', function ( $enabled ) {
	return ! is_post_type_archive( 'zoho_job' );
} );
```

---

## Template overrides

You do not have to disable anything to restyle the plugin's markup. Copy a
template into your theme, in a directory named after the plugin:

```
your-theme/
    jobs-sync-for-zoho-recruit/
        archive.php        the job archive
        single.php         one job
        listing.php        the listing wrapper: count, grid, pagination
        card.php           one job in a listing
        filters.php        the search and filter form
        hero.php           the careers hero
        no-results.php     nothing matched
        error.php          the listing could not be loaded
        pagination.php     pagination links
        job-meta.php       the fact table on a single job
```

Only that namespaced directory counts. A bare `card.php` at your theme root is
ignored on purpose, so the plugin cannot pick up a theme's own blog template.

The standard WordPress hierarchy also still applies and wins outright: a
`single-zoho_job.php` or `archive-zoho_job.php` in your theme takes the whole
page, and the plugin does not interfere.

Resolution order for any template:

1. `your-theme/jobs-sync-for-zoho-recruit/<file>`
2. the plugin's own `templates/<file>`
3. whatever `jszr_template_path` returns, which always has the last word
4. nothing at all, if the default frontend is disabled

---

## Getting job data

`jszr_get_jobs()` runs the same query the REST endpoint, the shortcode and the
block use, so your markup gets identical results — active-only, closing dates
respected — without you repeating any of that logic.

```php
$listing = jszr_get_jobs( array(
	'search'   => 'developer',
	'location' => 'chennai',
	'per_page' => 12,
	'page'     => 1,
	'orderby'  => 'closing_date',
	'order'    => 'asc',
) );

foreach ( $listing['jobs'] as $job ) : ?>
	<article class="my-job-card">
		<h2><?php echo esc_html( $job['title'] ); ?></h2>
		<p class="code"><?php echo esc_html( $job['job_code'] ); ?></p>
		<a href="<?php echo esc_url( $job['link'] ); ?>">View job</a>
	</article>
<?php endforeach;

printf( 'Page %d of %d, %d jobs', $listing['page'], $listing['pages'], $listing['total'] );
```

`jszr_get_jobs()` accepts `page`, `per_page`, `search`, `orderby`, `order`,
`status`, `department`, `location`, `employment_type`, `category` and
`experience`. It returns `jobs`, `total`, `pages` and `page`.

For one job — in `single.php`, or anywhere you have a post:

```php
$job = jszr_get_job( get_the_ID() );
```

Both return plain arrays shaped exactly like the REST response, so you write
your markup against one documented structure. The fields available are the ones
enabled under **Settings → Public API → Fields**: `id`, `title`, `slug`, `link`,
`content`, `excerpt`, `job_code`, `department`, `location`, `employment_type`,
`experience`, `category`, `city`, `state`, `country`, `remote`, `salary`,
`posted_date`, `closing_date`, `apply_url`, `status`.

Two helpers worth knowing:

```php
jszr_is_job_active( $post_id );   // respects status and closing date
jszr_get_apply_url( $post_id );   // the Zoho Recruit application URL
jszr_apply_link( $post_id );      // a ready-made apply button
```

---

## Hooks

### Actions

```php
do_action( 'jszr_before_jobs', array $params );  // archive, before the listing
do_action( 'jszr_after_jobs',  array $params );  // archive, after the listing
do_action( 'jszr_before_job',  int $post_id );   // single job, before
do_action( 'jszr_after_job',   int $post_id );   // single job, after
```

### Filters

```php
apply_filters( 'jszr_frontend_enabled', bool $enabled );
apply_filters( 'jszr_jobs_template',    string $path, string $theme_template );
apply_filters( 'jszr_job_template',     string $path, string $theme_template );
apply_filters( 'jszr_template_path',    string $path, string $template );
apply_filters( 'jszr_jobs_query_args',  array $query_args, array $args );
apply_filters( 'jszr_listing_query_args', array $args, array $atts );
apply_filters( 'jszr_job_data',         array $job, WP_Post $post );
apply_filters( 'jszr_apply_url',        string $url, int $post_id );
apply_filters( 'jszr_hero_title',       string $title );
```

Examples:

```php
// Point the archive at a template of your own.
add_filter( 'jszr_jobs_template', function () {
	return get_stylesheet_directory() . '/careers/archive.php';
} );

// Add a computed field for your templates.
add_filter( 'jszr_job_data', function ( $job, $post ) {
	$job['is_new'] = get_post_time( 'U', true, $post ) > strtotime( '-14 days' );

	return $job;
}, 10, 2 );

// Show the newest jobs first everywhere a custom listing is used.
add_filter( 'jszr_jobs_query_args', function ( $args ) {
	$args['orderby'] = 'date';
	$args['order']   = 'DESC';

	return $args;
} );
```

The full hook reference is in [hooks.md](hooks.md). Every hook that existed
before still exists and behaves the same way.

---

## AJAX, without redirects

Job search, filtering and pagination are all read operations, so they run over
the public REST API. There is no `admin-ajax.php` layer, and adding one would
mean a second API to keep in step with the first.

### The endpoint

```
GET /wp-json/jobs-sync-zoho-recruit/v1/jobs/render
```

It answers with rendered markup *and* the data behind it:

```json
{
  "success": true,
  "html": "<div class=\"jszr-jobs__results\">…</div>",
  "data": [ { "id": 66685, "title": "Content Writer", "…": "…" } ],
  "pagination": { "page": 1, "pages": 2, "total": 16, "per_page": 10 },
  "redirect": false,
  "message": ""
}
```

`redirect` is always `false`, and that is part of the contract: the response is
the end of the interaction. Nothing asks the browser to navigate.

Accepts everything `jszr_get_jobs()` accepts, plus `style` (`list` or `grid`),
`columns`, `show_filters`, `show_search`, `show_sort`, `show_pagination` and
`show_excerpt`. Invalid values are rejected by the schema with a `400` before
they reach a query.

```js
const params = new URLSearchParams( { search: 'developer', page: '1' } );
const res = await fetch(
	'/wp-json/jobs-sync-zoho-recruit/v1/jobs/render?' + params
);

if ( ! res.ok ) {
	// Show an error state. Do not show "no jobs" -- that is a different thing.
	return;
}

const body = await res.json();

document.querySelector( '#jobs' ).innerHTML = body.html;
```

Prefer `data` if you are rendering markup yourself, and `html` if you want the
plugin's markup without reimplementing it. Take `/jobs` and `/jobs/{id}` instead
when you only want JSON.

### Normal URLs keep working

None of this replaces ordinary navigation. `/jobs`, `/jobs/?jszr_search=writer`,
`/jobs/page/2/` and `/jobs/{job-slug}` are all real, server-rendered,
crawlable URLs, and the filter form is a plain `GET` form that works with
JavaScript switched off. The bundled script is progressive enhancement: it
intercepts the same form, fetches, swaps the results in and calls `pushState`,
so the address bar and the back button keep matching what is on screen. With
scripting off, the form submits and the page loads. Both paths run the same
renderer, so they cannot drift apart.

### Applications

Applications are **not** submitted to WordPress, and there is no application
endpoint to make asynchronous. The apply button links out to the application
form on Zoho Recruit, which is what lets this plugin state that no candidate
data ever touches your site. `jszr_get_apply_url()` gives you that URL, and
`jszr_apply_link()` gives you a ready-made button; where you place either, and
what it looks like, is entirely yours.

---

## A worked example

A theme taking the archive over completely, with the setting switched on:

```php
// your-theme/archive-zoho_job.php
get_header();

$listing = jszr_get_jobs( array(
	'search'   => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
	'per_page' => 12,
	'page'     => max( 1, (int) get_query_var( 'paged' ) ),
) );
?>

<form method="get" role="search">
	<label for="q">Search roles</label>
	<input type="search" id="q" name="q" value="<?php echo esc_attr( $_GET['q'] ?? '' ); ?>" />
	<button type="submit">Search</button>
</form>

<?php if ( empty( $listing['jobs'] ) ) : ?>
	<p>No roles match that search.</p>
<?php else : ?>
	<div class="grid">
		<?php foreach ( $listing['jobs'] as $job ) : ?>
			<article>
				<h2><a href="<?php echo esc_url( $job['link'] ); ?>"><?php echo esc_html( $job['title'] ); ?></a></h2>
				<?php if ( ! empty( $job['location'] ) ) : ?>
					<p><?php echo esc_html( wp_list_pluck( $job['location'], 'name' )[0] ); ?></p>
				<?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php
get_footer();
```

Because the file is `archive-zoho_job.php` in the theme, it would win even
without the setting. The setting matters when your frontend lives somewhere the
template hierarchy does not reach — a page builder template, a headless
frontend, or a route another plugin owns.
