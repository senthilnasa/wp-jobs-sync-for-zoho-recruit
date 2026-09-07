# Developer hooks

[← Documentation index](../README.md#documentation)

Every hook is prefixed `jszr_`. Put your code in a small plugin or your theme's
`functions.php`; the plugin boots on `plugins_loaded` at priority 5, so hooking
from either is early enough.

---

## Actions

### Job lifecycle

```php
do_action( 'jszr_job_synced',      int $post_id, array $record );
do_action( 'jszr_job_created',     int $post_id, array $record );
do_action( 'jszr_job_updated',     int $post_id, array $record );
do_action( 'jszr_job_deactivated', int $post_id, string $reason );
do_action( 'jszr_job_expired',     int $post_id );
do_action( 'jszr_job_orphaned',    int $post_id, string $action );
```

`jszr_job_synced` fires for every written record; `_created` or `_updated`
follows it. `$record` is the raw Zoho record.

`$reason` on deactivation says why — `missing_from_full_sync` when a confirmed
full sync stopped seeing the record, `missing` otherwise. `$action` on orphaning
is the configured `draft`, `private`, `trash`, `delete` or `none`. Neither fires
for a job created by hand in WordPress.

```php
// Ping Slack when a role opens.
add_action( 'jszr_job_created', function ( $post_id, $record ) {
	wp_remote_post( MY_SLACK_URL, array(
		'body' => wp_json_encode( array(
			'text' => 'New role: ' . get_the_title( $post_id ) . ' ' . get_permalink( $post_id ),
		) ),
	) );
}, 10, 2 );
```

### Sync lifecycle

```php
do_action( 'jszr_sync_started',   string $type, int $run_id );
do_action( 'jszr_sync_completed', array $stats );
do_action( 'jszr_sync_failed',    string $message, array $stats );
do_action( 'jszr_caches_invalidated' );
```

`$type` is `full` or `incremental`. `$stats` carries `run_id`, `type`, `state`,
`processed`, `total`, `created`, `updated`, `skipped`, `deactivated`, `expired`,
`orphaned`, `errors` and the timestamps.

`jszr_sync_completed` is the right place to purge a page cache or rebuild a
static careers page — it fires once per run, after the final batch, not once
per job.

```php
add_action( 'jszr_sync_completed', function ( $stats ) {
	if ( 'completed' === $stats['state'] && function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
} );
```

---

## Filters

### Connection and API

```php
apply_filters( 'jszr_data_centers',  array $centers );
apply_filters( 'jszr_oauth_scopes',  string[] $scopes );
apply_filters( 'jszr_redirect_uri',  string $uri );
apply_filters( 'jszr_module_name',   string $module );          // 'JobOpenings'
apply_filters( 'jszr_api_request_args', array $args, string $path );
apply_filters( 'jszr_api_response',  array $body, int $status, string $path );
apply_filters( 'jszr_retry_backoff', int $seconds, int $attempt );
```

`jszr_module_name` matters if your Zoho edition names the module differently or
you sync a custom module. `jszr_redirect_uri` is for sites behind a proxy whose
public URL differs from what WordPress reports.

```php
// Longer timeout on a slow connection.
add_filter( 'jszr_api_request_args', function ( $args, $path ) {
	$args['timeout'] = 60;
	return $args;
}, 10, 2 );
```

### Mapping and records

```php
apply_filters( 'jszr_default_settings',      array $defaults );
apply_filters( 'jszr_sanitize_settings',     array $clean, array $raw );
apply_filters( 'jszr_mapping_targets',       array $targets );
apply_filters( 'jszr_default_field_mapping', array $mapping );
apply_filters( 'jszr_field_mapping',         array $mapping );
apply_filters( 'jszr_default_zoho_fields',   array $fields );
apply_filters( 'jszr_job_data',              array $data, array $record );
apply_filters( 'jszr_should_sync_record',    bool $should, array $record );
apply_filters( 'jszr_status_mapping',        array $map );
apply_filters( 'jszr_record_status',         string $status, array $record );
apply_filters( 'jszr_post_status_for_job_status', string $post_status, string $status );
apply_filters( 'jszr_strip_inline_styles',   bool $strip );
```

`jszr_job_data` is the most useful of these: it receives the mapped payload
(`post`, `meta`, `terms`, `mapped`) just before it is written, so anything the
mapping UI cannot express belongs here.

```php
// Derive a taxonomy term the mapping cannot express.
add_filter( 'jszr_job_data', function ( $data, $record ) {
	if ( ! empty( $record['Remote_Job'] ) ) {
		$data['terms']['zoho_job_category'][] = 'Remote';
	}
	return $data;
}, 10, 2 );

// Sync only one department.
add_filter( 'jszr_should_sync_record', function ( $should, $record ) {
	$name = $record['Department_Name']['name'] ?? '';
	return 'Engineering' === $name;
}, 10, 2 );
```

A record rejected by `jszr_should_sync_record` counts as skipped. It is still
recorded as "seen", so it is not treated as missing at the end of a full sync.

### Post type and taxonomies

```php
apply_filters( 'jszr_post_type_args', array $args );
apply_filters( 'jszr_taxonomies',     array $taxonomies );
apply_filters( 'jszr_filter_map',      array $map );   // query param => taxonomy
```

```php
// A sixth taxonomy, available everywhere the built-in five are.
add_filter( 'jszr_taxonomies', function ( $taxonomies ) {
	$taxonomies['zoho_job_skill'] = array(
		'label' => 'Skill',
		'args'  => array(
			'labels'            => array( 'name' => 'Skills', 'singular_name' => 'Skill' ),
			'public'            => true,
			'hierarchical'      => false,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array( 'slug' => 'job-skill' ),
		),
	);
	return $taxonomies;
} );
```

That one filter is the whole job. The parameter map is derived from the
registered taxonomies, so the new taxonomy immediately becomes:

- a target in the field mapping dropdowns,
- a `?skill=` parameter on `/jobs`, with the same term resolution and
  `include_children` behaviour as the built-in five,
- an entry in the `/jobs/filters` response,
- a `skill="…"` shortcode attribute,
- a labelled dropdown in the listing filter form.

The parameter name is the taxonomy name with the `zoho_job_` prefix removed, so
`zoho_job_skill` becomes `skill`. A taxonomy registered under some other name
keeps that name as its parameter. Use `jszr_filter_map` only to override that
derivation — to rename a parameter, or to hide a taxonomy from the public API
while keeping it in the admin.

Flush permalinks after adding a taxonomy.

To expose the new taxonomy in the public REST response as well, add it to the
allow-list; the response shape and JSON schema are handled for you.

```php
add_filter( 'jszr_available_rest_fields', function ( $fields ) {
	$fields['skill'] = 'Skill';
	return $fields;
} );
```

### REST and listings

```php
apply_filters( 'jszr_available_rest_fields', array $fields );
apply_filters( 'jszr_rest_query_args',       array $args, WP_REST_Request $request );
apply_filters( 'jszr_rest_job_data',         array $data, WP_Post $post );
apply_filters( 'jszr_listing_query_args',    array $args, array $atts );
```

`jszr_rest_job_data` runs after the allow-list, so a field you add here is
returned even if it is not a setting. Do not use it to publish anything a
visitor should not see.

### Display

```php
apply_filters( 'jszr_template_path',   string $path, string $template );
apply_filters( 'jszr_apply_url',       string $url, int $post_id );
apply_filters( 'jszr_structured_data', array $schema, WP_Post $post );
apply_filters( 'jszr_employment_type_map', array $map );
apply_filters( 'jszr_seo_plugin_handles_schema', bool $detected );
```

```php
// Add UTM parameters to every apply link.
add_filter( 'jszr_apply_url', function ( $url, $post_id ) {
	return add_query_arg( 'utm_source', 'careers-site', $url );
}, 10, 2 );

// Correct the schema for a specific case.
add_filter( 'jszr_structured_data', function ( $schema, $post ) {
	$schema['industry'] = 'Software';
	return $schema;
}, 10, 2 );

// Emit JobPosting even though an SEO plugin also does.
add_filter( 'jszr_seo_plugin_handles_schema', '__return_false' );
```

### Layouts and custom markup

```php
apply_filters( 'jszr_listing_presets',       array $presets );
apply_filters( 'jszr_job_info_presets',      array $presets );
apply_filters( 'jszr_allowed_template_html', array $allowed );
apply_filters( 'jszr_template_tag_values',   array $values, int $post_id );
apply_filters( 'jszr_rendered_template',     string $markup, string $template, int $post_id );
apply_filters( 'jszr_visible_filters',       array $params );
apply_filters( 'jszr_custom_css',            string $css );
```

Layouts written on **Settings → Display** are token templates, never PHP. These
filters extend that system rather than escaping it.

```php
// Add a token of your own. Every value must arrive already escaped: nothing
// here is escaped again when it is substituted.
add_filter( 'jszr_template_tag_values', function ( $values, $post_id ) {
	$values['reference'] = esc_html( 'REF-' . $post_id );

	return $values;
}, 10, 2 );

// Offer another built-in layout in the dropdown.
add_filter( 'jszr_listing_presets', function ( $presets ) {
	$presets['banner'] = '<article class="jszr-job-card my-banner"><h3>{title}</h3>{apply_button}</article>';

	return $presets;
} );

// Allow one more tag in custom templates.
add_filter( 'jszr_allowed_template_html', function ( $allowed ) {
	$allowed['svg'] = array( 'class' => true, 'viewbox' => true );

	return $allowed;
} );

// Hide a filter from the front end while keeping it in the admin.
add_filter( 'jszr_visible_filters', function ( $params ) {
	return array_diff( $params, array( 'category' ) );
} );
```

`jszr_allowed_template_html` is a security boundary. Whatever you add here can
be written into a page by anyone who can edit plugin settings, so add tags, not
`script`, `style` or event handler attributes.

### Titles and meta descriptions

```php
apply_filters( 'jszr_job_title',              string $title, int $post_id, array $parts );
apply_filters( 'jszr_meta_description',       string $description, int $post_id );
apply_filters( 'jszr_seo_plugin_active',      bool $active );
apply_filters( 'jszr_output_meta_description', bool $output );
```

The plugin suggests a title (job title plus the most specific location term)
and a description (excerpt, falling back to the trimmed content). When an SEO
plugin is detected it prints neither and only exposes the values, so an SEO
plugin can read what the plugin would have used:

```php
// Feed the plugin's suggestion into an SEO plugin.
add_filter( 'wpseo_title', function ( $title ) {
	if ( ! is_singular( 'zoho_job' ) ) {
		return $title;
	}

	return JobsSyncForZohoRecruit\SEO::job_title( get_queried_object_id() );
} );

// Build the description from the mapped fields instead.
add_filter( 'jszr_meta_description', function ( $description, $post_id ) {
	$location = jszr_get_job_meta( $post_id, 'city' );

	return sprintf( '%s in %s. Apply now.', get_the_title( $post_id ), $location );
}, 10, 2 );

// Keep the filters available but never print the tag.
add_filter( 'jszr_output_meta_description', '__return_false' );
```

`SEO::job_title()` and `SEO::meta_description()` are safe to call directly; both
are static and take a post ID.

### Page caching

```php
apply_filters( 'jszr_purge_page_cache',   bool $purge );
apply_filters( 'jszr_page_cache_purgers', array $purgers );
do_action( 'jszr_caches_invalidated' );
```

After a sync completes the plugin clears its own transients and then asks any
page caching plugin it recognises to purge. Every call is guarded, and a purger
that throws is logged and skipped rather than allowed to break the sync.

```php
// Teach it about a caching plugin it does not know.
add_filter( 'jszr_page_cache_purgers', function ( $purgers ) {
	$purgers['my-cache'] = function () {
		if ( ! function_exists( 'my_cache_flush' ) ) {
			return false;
		}

		my_cache_flush();

		return true;
	};

	return $purgers;
} );

// Or take over invalidation entirely.
add_filter( 'jszr_purge_page_cache', '__return_false' );
add_action( 'jszr_caches_invalidated', 'my_selective_purge' );
```

### Permissions and notifications

```php
apply_filters( 'jszr_capability',       string $capability );  // 'manage_zoho_recruit'
apply_filters( 'jszr_capability_roles', array $roles );        // roles granted it on activation
apply_filters( 'jszr_notification_recipient', string $email );
```

```php
// Let editors run syncs too.
add_filter( 'jszr_capability', function () {
	return 'edit_others_posts';
} );
```

`jszr_capability` governs every admin screen, every protected REST endpoint and
the CLI equivalents at once. Change it and the whole surface moves together.

### Batching

```php
apply_filters( 'jszr_batch_time_budget', int $seconds );  // default 20
```

Raise it on a host with generous limits to finish a large sync in fewer cron
ticks; lower it if batches are being killed mid-run.

---

## Global helper functions

Stable, prefixed wrappers, so you need not know the class names:

```php
jszr_get_setting( string $key, mixed $fallback = null );
jszr_capability();
jszr_admin_url( string $page = '', array $args = array() );
jszr_is_connected();
jszr_get_job_meta( int $post_id, string $key );   // key with or without prefix
jszr_get_apply_url( int $post_id );
jszr_is_job_active( int $post_id );
jszr_locate_template( string $template );
jszr_get_template( string $template, array $args = array() );
jszr_verify_admin_request( string $action );      // capability + nonce, for your own admin-post handlers
```

Always build links to the plugin's screens with `jszr_admin_url()` — they live
under the job post type menu, not at `admin.php?page=`, and the helper is the
only place that knows it.

## Classes worth knowing

Namespace `JobsSyncForZohoRecruit`.

| Call | Does |
| --- | --- |
| `Job::find_by_zoho_id( $zoho_id )` | The indexed lookup. Returns a post ID or 0 |
| `Job::get_status( $post_id )` | The normalised status |
| `Job::counts()` | Counts by status, cached |
| `Field_Mapper::transforms()` / `::targets()` | What the mapping UI offers |
| `Settings::get( $key, $fallback )` | One setting |
| `plugin()->sync()->run_now( 'full' )` | A synchronous sync, as the CLI does it |

`Field_Mapper` writes nothing to the database — it is pure translation, and the
easiest part of the plugin to test against.
