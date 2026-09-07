# Templates and display

[← Documentation index](../README.md#documentation)

Four ways to put jobs on a page, all reading the same local data through the
same renderer:

| | Best for |
| --- | --- |
| The `/jobs/` archive | A careers section that works out of the box |
| `[zoho_jobs]` | A listing inside an existing page |
| The **Zoho Recruit Jobs** block | The same, placed visually |
| Your own `WP_Query` | Full control — see [Data model](data-model.md#finding-jobs-in-code) |

The shortcode and the block share one PHP renderer, so what you see in the
editor is what visitors get, and a template override applies to both.

## Shortcode

```
[zoho_jobs]
```

| Attribute | Default | Notes |
| --- | --- | --- |
| `per_page` | Setting (20) | |
| `search` | — | Fixed keyword |
| `department` | — | Term slugs or names, comma separated |
| `location` | — | |
| `employment_type` | — | |
| `category` | — | |
| `experience` | — | |
| `orderby` | `date` | `date`, `title`, `closing_date`, `posted_date` |
| `order` | `desc` | `asc`, `desc` |
| `status` | `active` | Rarely worth changing |
| `style` | Setting (`list`) | `list` or `grid` |
| `layout` | Setting (`default`) | `default`, `card`, `compact`, `table`, `custom` |
| `columns` | `3` | Grid only, 1–4 |
| `show_filters` | Setting (on) | Taxonomy dropdowns |
| `show_search` | Setting (on) | Keyword box |
| `show_sort` | Setting (off) | Sort dropdown |
| `show_pagination` | `true` | |
| `show_excerpt` | `true` | |

Attributes named "Setting" above default to whatever **Settings → Display** says,
so a site can change every listing at once and still override one of them here.

```
[zoho_jobs per_page="12" style="grid" columns="3" show_filters="true" show_search="true"]
[zoho_jobs department="engineering" location="chennai" show_pagination="false"]
```

Two more, for use on a single job page (or inside a loop):

```
[zoho_job_apply]                     Apply button
[zoho_job_apply label="Apply now"]
[zoho_job_meta]                      The job's meta table
```

Frontend CSS and JS are enqueued only when one of these actually renders. A page
without jobs on it loads nothing.

## Changing the markup without touching a file

**Settings → Display** covers the cases that used to need a child theme.

### Listing layout

Five choices: **Default** (the standard card), **Card** (image, badges and a
summary), **Compact** (one line per job), **Columns** (aligned columns on wide
screens, stacked on narrow ones), and **Custom**.

Custom means you write the HTML. It is a *token template*, not PHP — the plugin
never evaluates what you type, because an option that holds executable code is a
remote code execution hole waiting for one weak administrator password. Tokens
give the same layout freedom safely.

```html
<article class="jszr-job-card my-card">
	<h3><a href="{permalink}">{title}</a></h3>
	{if:location}<p class="my-card__where">{location}</p>{/if:location}
	{if:salary}<p class="my-card__pay">{salary}</p>{/if:salary}
	{ifnot:salary}<p class="my-card__pay">Salary on application</p>{/ifnot:salary}
	{view_link}
</article>
```

- `{token}` is replaced with that value, escaped for output. A token with no
  value on a job becomes nothing at all, and a token the plugin does not
  recognise is removed rather than printed back at a visitor.
- `{if:token}…{/if:token}` keeps its contents only when the value is present, so
  a missing salary does not leave a stray label or separator behind.
- `{ifnot:token}…{/ifnot:token}` is the opposite, for "not stated" fallbacks.

The **Available tags** table on that screen lists every token for your site,
including any taxonomy you registered through `jszr_taxonomies`. `{view_link}`,
`{apply_button}` and `{thumbnail}` produce ready-made markup; the rest are plain
values.

The HTML is filtered through an allow-list when you save: structural tags, links
and images are kept, and `script`, `style`, `iframe`, `form` and event handler
attributes are removed. Starting from a preset with the **Start from…** buttons
gives you working markup to edit rather than an empty box.

### Job details layout

The same three-way choice for the facts block on a single job: **Default** (a
labelled list), **Inline** (pills in a row), or **Custom** with your own token
template. The **Fields to show** checkboxes control which facts appear in the
two built-in layouts; a custom template decides for itself.

### Search, filters and sorting

Choose which taxonomy filters appear and in what order, whether the search box
and sort dropdown are on by default, whether the bar is laid out inline or
stacked (for a sidebar), and the placeholder and button text. A filter whose
taxonomy has no terms yet is left out automatically.

Everything still works with JavaScript off: the bar is a plain GET form.

### Custom CSS

A CSS box that loads only on pages showing jobs, and after the plugin
stylesheet so it wins. Every class the plugin prints starts with `jszr-`.

```css
.jszr-job-card { border-color: #0b5cff; }
.jszr-job-card__title { font-size: 1.3rem; }
```

Markup, `@import`, `expression()` and script URLs are stripped — on save and
again on output, because the settings form is not the only thing that can write
that option.

### Precedence

1. A template file in your theme (`yourtheme/jobs-sync-for-zoho-recruit/card.php`)
2. A shortcode or block `layout` attribute
3. **Settings → Display**
4. The plugin's default card

A theme override wins outright: the layout settings never run when one exists.

## Blocks

Three blocks, all server-rendered, so every editor preview comes from the same
PHP as the front end.

| Block | Use | Attributes |
| --- | --- | --- |
| **Zoho Recruit Jobs** | A listing, anywhere | The shortcode attributes, in the inspector |
| **Job Details** | Inside a single job template | Which facts to show, as toggles |
| **Apply Button** | Inside a single job template | An optional label override |

**Zoho Recruit Jobs** supports wide and full alignment, an anchor, margin and
padding, font size, and two style variations (Default, Bordered).

**Job Details** and **Apply Button** read the job from block context
(`postId`), so they work inside a single job template, a query loop, or the
post editor for a job. Placed anywhere with no job in context they render
nothing rather than erroring.

**Apply Button** also renders nothing when the job is closed or has no
application URL — the same decision the `[zoho_job_apply]` shortcode makes, and
the reason a closed job's page shows the "position closed" notice instead of a
dead button.

## Classic theme templates

When a classic theme is active and provides no `archive-zoho_job.php` or
`single-zoho_job.php` of its own, the plugin supplies these:

| Template | Renders |
| --- | --- |
| `archive.php` | The archive and taxonomy archives |
| `single.php` | One job |
| `listing.php` | The listing wrapper — count, list or grid, pagination |
| `card.php` | One job in a listing |
| `filters.php` | The filter and search form |
| `pagination.php` | Pagination links |
| `no-results.php` | The empty state |
| `job-meta.php` | The meta table on a single job |

### Overriding one

Copy the file into a directory named after the plugin in your theme:

```
yourtheme/jobs-sync-for-zoho-recruit/card.php
yourtheme/jobs-sync-for-zoho-recruit/single.php
yourtheme/jobs-sync-for-zoho-recruit/filters.php
```

The directory name matters. Only `jobs-sync-for-zoho-recruit/<file>` counts as
an override — a bare `card.php` at your theme root is ignored, deliberately, so
the plugin can never pick up a theme file that happens to share a name.

Child themes take precedence over parents, as usual.

To resolve or render a template from your own code:

```php
$path = jszr_locate_template( 'card.php' );

jszr_get_template( 'card.php', array( 'post_id' => $post_id ) );
```

Move the whole set somewhere else with the `jszr_template_path` filter.

### Theme templates win outright

If your theme has its own `archive-zoho_job.php`, `single-zoho_job.php` or
`taxonomy-zoho_job_department.php`, WordPress uses it and the plugin steps
aside completely.

## Block themes

Under a block theme the plugin does **not** load the classic PHP templates —
they call `get_header()`, which a block theme has no answer for. It registers
two block templates instead:

| Template | Title |
| --- | --- |
| `jobs-sync-for-zoho-recruit//single-zoho_job` | Single Job |
| `jobs-sync-for-zoho-recruit//archive-zoho_job` | Jobs Archive |

Both appear in **Appearance → Editor → Templates**, where you can edit them
like any other template; your edit is saved to the theme and takes over.

They are built from ordinary core blocks plus the plugin's own, so you can
rearrange them freely:

- **Zoho Recruit Jobs** — the listing
- **Job Details** — the facts table
- **Apply Button** — the apply link

The `[zoho_job_meta]` and `[zoho_job_apply]` shortcodes render exactly the same
markup, for classic templates and for pages built before the blocks existed.

Block template registration needs WordPress 6.7. On 6.6 a block theme falls
back to its own generic templates, which render job posts correctly if plainly.

## CSS

Every class is prefixed `jszr-` and follows BEM. The stylesheet is deliberately
thin — spacing, layout and a light border, no colours that fight a theme.

```
jszr-jobs                    Listing root (jszr-jobs--list / --grid)
jszr-jobs__results           Results region (aria-live)
jszr-jobs__count             "12 jobs"
jszr-jobs__list  __item
jszr-job-card                One card
  __title  __meta  __excerpt  __footer  __link  __closing  __remote
jszr-filters                 Filter form
  __field  __field--search  __actions  __submit  __reset
jszr-pagination
jszr-no-results
jszr-archive                 Classic archive wrapper
  __header  __title  __description
jszr-job                     Single job wrapper
  __title  __header  __content  __apply  __closed
jszr-job-meta                Meta table
  __item  __label  __value
jszr-apply-button
jszr-block                   Block wrapper
```

Dequeue the stylesheet and write your own if you prefer:

```php
add_action( 'wp_enqueue_scripts', function () {
	wp_dequeue_style( 'jszr-jobs' );
}, 100 );
```

## Accessibility and no-JavaScript

The listing works with JavaScript disabled. Filters are a real `<form>` that
submits by GET; the JS only adds convenience.

- Semantic markup: `<article>` per job, one `<h2>` per card, a real form.
- Every control has a label; the reset link is a link.
- The results region is an ARIA live region, so a screen reader hears the count
  change after a filter.
- Focus outlines are never removed.
- Nothing depends on colour alone.

Filter parameters are prefixed so they cannot collide with a theme or another
plugin: `?jszr_department=engineering&jszr_search=php&jszr_page=2`.

## Expired jobs

**Settings → Frontend → Expired job behaviour**:

| Option | What a visitor gets |
| --- | --- |
| Show a notice *(default)* | The page still loads, with a "this position is closed" notice and a `noindex` robots tag |
| 410 Gone | The correct HTTP answer for a job that is really gone — search engines drop it fastest |
| Redirect to the archive | A 302 to `/jobs/` |

Expired jobs never appear in listings, the API, the feed or structured data
regardless of which you pick.

## Structured data

Active single job pages carry a `JobPosting` JSON-LD block: title, description,
`datePosted`, `validThrough`, employment type mapped to schema.org's
enumeration, hiring organization from **Settings → Structured data**, a
`PostalAddress`, `TELECOMMUTE` and applicant location requirements for remote
roles, identifiers, and `baseSalary` when the salary parsed unambiguously.

Anything it cannot state accurately it leaves out — an incomplete `JobPosting`
is better than a wrong one. Nothing is emitted for inactive or expired jobs.

If Yoast or Rank Math is already outputting `JobPosting`, the plugin detects it
and stays quiet rather than putting two on the page. Override with
`jszr_seo_plugin_handles_schema`, disable it entirely in settings, or edit the
graph with `jszr_structured_data`.

## The apply button

The URL comes from the mapped application URL field. If a record has none, the
plugin falls back to the template in **Settings → Frontend → Apply URL
template**, which understands `{zoho_id}`, `{job_code}` and `{slug}`:

```
https://careers.example.com/apply/{job_code}
```

Optional UTM parameters are appended from settings. Links open in a new tab
with `rel="noopener"`. Change the final URL with `jszr_apply_url`, or the label
in settings.

The plugin does not accept applications. It publishes jobs and hands the
candidate to Zoho or to your ATS page.
