=== Jobs Sync for Zoho Recruit ===
Contributors: senthilnasa
Tags: jobs, careers, recruitment, job board, hiring
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Synchronize Job Openings from Zoho Recruit into WordPress and publish them with a shortcode, a block, templates or the REST API.

== Description ==

Jobs Sync for Zoho Recruit copies Job Openings from your own Zoho Recruit account into WordPress, where they become ordinary posts you can theme, filter, index and link to.

The sync runs in one direction only: Zoho Recruit is the source of truth, WordPress is the published copy. Visitors never cause a request to Zoho — every page is served from local WordPress data.

**What it does**

* Connects to Zoho Recruit with OAuth 2.0. You never paste an access token, and no credentials are hard coded.
* Stores each job as a `zoho_job` post with taxonomies for department, location, employment type, category and experience.
* Maps Zoho fields to WordPress fields through a mapping screen that reads the real field list from your account, including custom fields.
* Runs full and incremental syncs in the background, in batches, so a large account never times out a web request.
* Resumes an interrupted sync from its last checkpoint, and never deactivates jobs because a sync failed halfway.
* Handles closed, expired and deleted jobs according to rules you choose.
* Publishes jobs through a shortcode, three blocks, theme templates, the WordPress REST API and JobPosting structured data.
* Lets you restyle the listing and the job details from the admin: pick a layout, write your own HTML with simple tags, add custom CSS, and choose which search, filter and sort controls visitors get - all without editing a theme file.

**Safety first**

Two rules shape the whole plugin:

1. A job is only deactivated for being "missing" after a full sync has read every page without a single error.
2. If a completed sync would still deactivate more than a configurable share of your jobs (50% by default), it deactivates nothing, marks the run partial and emails an administrator.

**Not a candidate plugin**

This plugin reads Job Openings. It does not read, store or transmit candidate records, applications or any other personal data from Zoho Recruit, and it does not accept applications: the Apply button links out to your existing application form.

**Shortcode**

`[zoho_jobs per_page="10" show_filters="true" show_search="true" style="grid"]`

Supported attributes: `per_page`, `search`, `department`, `location`, `employment_type`, `category`, `experience`, `orderby`, `order`, `style`, `columns`, `show_filters`, `show_search`, `show_pagination`, `show_excerpt`.

Two smaller shortcodes are available for single job layouts: `[zoho_job_apply]` and `[zoho_job_meta]`.

**Blocks**

Three blocks, all server rendered so the editor preview is the real output:

* **Zoho Recruit Jobs** - a listing, with the shortcode options in the inspector.
* **Job Details** - the department, location, employment type and other facts for one job.
* **Apply Button** - a link to the application form, which renders nothing when a job is closed or has no application URL.

The two single-job blocks read the job from block context, so they work inside a single job template, a query loop, or the editor.

**REST API**

`GET /wp-json/jobs-sync-zoho-recruit/v1/jobs`
`GET /wp-json/jobs-sync-zoho-recruit/v1/jobs/{id}`
`GET /wp-json/jobs-sync-zoho-recruit/v1/jobs/filters`

Only active jobs are returned by default, and only the fields an administrator has allowed. Sync endpoints require authentication and the `manage_zoho_recruit` capability.

**WP-CLI**

`wp jszr sync --full`, `wp jszr sync --incremental --dry-run`, `wp jszr status`, `wp jszr expire`, `wp jszr logs`, `wp jszr cancel`, `wp jszr fields`, `wp jszr disconnect`.

== Third-party service disclosure ==

This plugin connects to **Zoho Recruit**, a third-party service operated by Zoho Corporation, using credentials that you create in your own Zoho account. The plugin is not affiliated with, endorsed by, or sponsored by Zoho Corporation.

Requests are made to the Zoho region you select in the settings, for example:

* `https://accounts.zoho.com` (and the `.eu`, `.in`, `.com.au`, `.jp`, `.uk`, `.sa` and `zohocloud.ca` equivalents) — OAuth authorization, token exchange, token refresh and token revocation.
* `https://recruit.zoho.com` (and the matching regional domains) — reading Job Openings, reading records deleted in Zoho, and reading field metadata.

**What is sent to Zoho:** your OAuth client ID, client secret and tokens, plus ordinary request parameters such as page number and page size. No information about visitors to your website is sent.

**What is received from Zoho:** Job Opening records and Job Opening field definitions.

**When requests happen:** during the OAuth connection, when an administrator runs or tests a sync, on the schedule you configure, and when an optional webhook you configure in Zoho triggers a refresh. No request is ever made while rendering a page for a visitor.

Zoho terms of service: https://www.zoho.com/terms.html
Zoho privacy policy: https://www.zoho.com/privacy.html
Zoho Recruit API documentation: https://www.zoho.com/recruit/developer-guide/apiv2/

== Installation ==

1. Upload the plugin through **Plugins → Add New → Upload Plugin**, or install it from the plugin directory, then activate it.
2. In the [Zoho API Console](https://api-console.zoho.com/), create a **Server-based Application**.
3. Copy the **Redirect URI** shown on **Zoho Recruit Jobs → Settings → Connection** into the application's *Authorized Redirect URIs* field. It must match exactly.
4. Paste the application's Client ID and Client Secret into the same settings screen, choose the data center your Zoho account is hosted in, and save.
5. Click **Connect to Zoho Recruit** and approve the requested permissions.
6. Open **Field Mapping**, click **Reload fields from Zoho**, and adjust the mapping if your account uses custom fields.
7. Return to the dashboard and click **Full Sync**.

The plugin requests the minimum scopes it needs: `ZohoRecruit.modules.jobopenings.READ` and `ZohoRecruit.settings.fields.READ`.

== Frequently Asked Questions ==

= Does this send candidate data anywhere? =

No. The plugin only reads Job Openings and Job Opening field definitions. It never requests candidate modules, and it never sends visitor data to Zoho.

= What happens if a sync fails halfway through? =

Nothing is deactivated. The run is recorded as failed with the error, and the next run resumes from its checkpoint. Jobs are only deactivated for being missing after a full sync reads every page successfully.

= Can I keep my credentials out of the database? =

Yes. Define `JSZR_CLIENT_ID`, `JSZR_CLIENT_SECRET` and `JSZR_DATA_CENTER` in `wp-config.php`. The settings screen then shows those values as read-only. Anything stored in the database is encrypted with a key derived from your site's security salts.

= I edited a job in WordPress. Will the next sync overwrite it? =

That depends on the conflict setting. The default, *Zoho updates mapped fields only*, refreshes the mapped fields and leaves everything else alone. Choose *Preserve fields edited in WordPress* to keep your manual edits: the plugin stores a hash of each field at sync time and skips any field whose value has changed since.

= A job was deleted in Zoho. What happens on the website? =

Whatever you choose: move to draft (the default), make private, move to trash, delete permanently, or do nothing. Deletions are detected through Zoho's deleted-records endpoint, and jobs you created by hand in WordPress are never touched.

= Do I need WP-Cron? =

Only for scheduled syncing. If `DISABLE_WP_CRON` is set, point a system cron job at `wp-cron.php`, or run `wp jszr sync` from your own scheduler. The plugin warns you if scheduled syncing cannot run.

= Can I change the job URLs? =

Yes, on the Frontend settings tab. Rewrite rules are flushed automatically when the slug changes. Existing job slugs stay stable even when the job title changes in Zoho, so published links keep working.

= Where do candidates actually apply? =

On Zoho Recruit. The Apply button is a link out to the application URL from your Zoho record (or a fallback URL you configure) - the plugin does not embed Zoho's form in an iframe and does not accept applications itself. That is what lets it say no candidate data ever touches WordPress. You can choose whether the link opens in a new tab or the same tab.

= Can I change how the jobs look without editing theme files? =

Yes. **Settings -> Display** has layouts for the listing (Default, Card, Compact, Columns) and for the job details block (Default, Inline), a custom HTML option for both, a custom CSS box, and controls for which search, filter and sort options visitors see.

Custom HTML uses simple tags rather than PHP: `{title}`, `{location}`, `{salary}`, `{apply_button}` and so on, with `{if:salary}...{/if:salary}` to drop a section when a job has no value for it. The screen lists every tag available on your site. The plugin never runs what you type - storing executable code in a setting would be a security hole - and the HTML is filtered through an allow-list when you save.

= How do I customize the markup? =

Copy any file from the plugin's `templates/` directory into `yourtheme/jobs-sync-for-zoho-recruit/` and edit it there. Block themes can use the bundled block templates or build their own layout in the Site Editor with the Job Details and Apply Button blocks.

= Does it work on multisite? =

Yes. Each site holds its own connection, settings and jobs. Network activation runs the setup on every existing site and on sites created later. There is no network-wide Zoho connection in this version.

= Will it duplicate jobs? =

No. Every write path resolves the job by its Zoho record ID through a single lookup guarded by a lock, so full syncs, incremental syncs, cron, webhooks, CLI runs and retries all converge on the same post.

== Screenshots ==

1. A job listing on the front end, rendered by the block, with search and filters.
2. A single job page: the mapped meta, the description and the Apply button.
3. The jobs list in the admin, with Zoho status, job code, closing date and last sync.
4. The field mapping screen, where each Zoho field is pointed at a WordPress field.
5. The connection settings, with the redirect URI to register in the Zoho API console.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
