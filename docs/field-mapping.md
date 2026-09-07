# Field mapping

[← Documentation index](../README.md#documentation)

No two Zoho Recruit accounts have the same fields. The mapping screen is how
you tell the plugin which of *your* Zoho fields becomes which part of a
WordPress job.

**Zoho Recruit Jobs → Field Mapping.**

A mapping is a list of rows. Each row is three things:

```
Zoho field  →  WordPress target  (through a transform)
Posting_Title  →  Post: Title      (Plain text)
Job_Description →  Post: Description (HTML, sanitized)
Department_Name →  Taxonomy: Department (Use lookup name)
```

Order does not matter. Two rows may read the same Zoho field into different
targets; two rows writing the *same* field into the *same* target are
de-duplicated when you save.

## Loading your real field list

Click **Reload fields from Zoho**. The plugin calls the Fields metadata API for
the Job Openings module and caches the result in the `jszr_zoho_fields` option,
refreshing it automatically every 12 hours. Every dropdown then lists your
actual fields — API name, label and type — including custom ones.

Until you have connected, the dropdowns fall back to a bundled list of the
standard Zoho Recruit field names so the screen is still usable.

From the command line: `wp jszr fields --refresh`.

> A Zoho **API name** is not the label you see in the Recruit UI. "Job Type"
> may be `Job_Type`; a custom field may be `Publish_in_Career_Website` or
> `CustomField5`. Always map by API name — the dropdown shows both.

## Targets

| Target | Writes to |
| --- | --- |
| Post: Title | `post_title` |
| Post: Description | `post_content` |
| Post: Excerpt | `post_excerpt` |
| Taxonomy: Department | `zoho_job_department` |
| Taxonomy: Location | `zoho_job_location` (hierarchical) |
| Taxonomy: Employment type | `zoho_job_employment_type` |
| Taxonomy: Category | `zoho_job_category` |
| Taxonomy: Experience | `zoho_job_experience` |
| Meta: … | One of the `_zoho_recruit_*` / `_jszr_*` keys — see [Data model](data-model.md) |

Register more targets with `jszr_mapping_targets`, and more taxonomies with
`jszr_taxonomies`; both are documented in [Hooks](hooks.md).

## Transforms

A transform decides how a raw Zoho value becomes a WordPress value.

| Transform | Behaviour |
| --- | --- |
| Plain text | `sanitize_text_field()`. The safe default |
| HTML (sanitized) | `wp_kses_post()`, with inline `style` attributes stripped |
| Plain text with paragraphs | `wpautop()` — for description fields Zoho stores as plain text |
| Date (Y-m-d) | Parses the Zoho value and renders `Y-m-d` in the site timezone |
| Date and time | Parses and stores an ISO-8601 timestamp, offset-correct |
| Number | Float |
| Whole number | Integer |
| Yes / No | Zoho booleans and their string spellings → `1` / `0` |
| Join list with commas | For multi-select picklists: `["A","B"]` → `A, B` |
| Use lookup name | For lookup and owner objects: takes the `name` from `{"id":…,"name":…}` |
| URL | `esc_url_raw()`, rejecting anything that is not a URL |

Picking the wrong one is usually harmless and always reversible — change it and
run a sync.

### Zoho types and what to choose

| Zoho field type | Transform |
| --- | --- |
| Single line, email, phone, picklist | Plain text |
| Multi line (plain) | Plain text with paragraphs |
| Multi line (rich text) | HTML (sanitized) |
| Multi-select picklist | Join list with commas, or map to a taxonomy |
| Lookup (Client_Name, Account, …) | Use lookup name |
| Owner / user | Use lookup name |
| Date | Date |
| Date/time | Date and time |
| Boolean / checkbox | Yes / No |
| Number, currency, percent | Number or Whole number |
| URL | URL |

## Mapping to taxonomies

A row targeting a taxonomy creates terms on demand. A single value becomes one
term; an array (a multi-select picklist, or a "Join list" value) becomes several.
Terms are matched by name, so "Engineering" from Zoho joins the existing
`Engineering` term rather than making a duplicate.

### Hierarchical locations

`zoho_job_location` is hierarchical. If you do not map anything to it directly,
the plugin builds the term path from the country, state and city meta:

```
India
└── Tamil Nadu
    └── Chennai
```

The job is assigned the deepest term, so a query for `India` still finds it
through the hierarchy. Map a field to the location taxonomy explicitly and that
takes precedence — the derived path is only a fallback.

## Values the plugin derives for you

Even with no row for them, the mapper fills in:

- **Post title fallback.** If nothing maps to the title, it tries
  `Posting_Title`, then `Job_Opening_Name`, then `Job_Opening_ID`, and finally
  falls back to "Untitled job". A job is never created with an empty title.
- **Excerpt.** If no excerpt is mapped, the first 40 words of the description.
- **Salary breakdown.** From the raw salary string it parses `salary_min`,
  `salary_max`, `salary_currency` and `salary_unit`, recognising ten currency
  codes and symbols and the usual period words ("per year", "/hr", "monthly").
  The raw string is always kept as well. Structured data only publishes a
  `baseSalary` when the parse produced something unambiguous.
- **Location path**, as above.

## Only syncing published jobs

**Settings → Sync → Only sync jobs marked as published in Zoho** is on by
default and reads the field named in **Publish field** — `Publish_in_Career_Website`
unless you change it.

If your account calls that field something else, put its API name in that
setting. If you set the publish field to a name that does not exist, every job
looks unpublished and nothing syncs — a common first-run surprise, and one the
dry run will show you before it costs you anything.

A job that loses the flag is **deactivated locally, not deleted**, and follows
the normal safety rules.

## Conflict handling

**Settings → Sync → Conflict handling** decides what a sync is allowed to
overwrite when a job already exists in WordPress:

| Mode | Behaviour |
| --- | --- |
| Zoho overwrites everything | Every mapped field is rewritten on every sync |
| Zoho updates mapped fields only *(default)* | Fields you never mapped are left alone; mapped fields are rewritten |
| Preserve fields edited in WordPress | As above, but a mapped field whose WordPress value no longer matches what the last sync wrote is treated as hand-edited and skipped |

The third mode works by storing a hash of each field's last synced value in
`_jszr_sync_hash`. On the next sync, if the current value still hashes to what
Zoho last wrote, it is safe to update; if it does not, someone edited it in
WordPress and the plugin backs off.

That guard is deliberate, so **Resync from Zoho** respects it too: a resync
re-reads the record but still leaves your edits alone.

To hand a job back to Zoho, use **Reset to Zoho values**, which appears beside
the resync action on the job list and in the Zoho Recruit metabox whenever this
mode is active. It asks for confirmation, then rewrites every mapped field from
the current record and re-arms the guard against the new values.

The reset is per-job and deliberately narrower than *Zoho always overwrites*:
it replaces the mapped fields and nothing else, so meta another plugin attached
to the job survives.

Switching the whole site back to *Zoho updates mapped fields only* has the same
effect on every job at once, with no way to undo it — prefer the per-job reset
unless you really do want to abandon every local edit.

## Export and import

**Export mapping** downloads the rows as JSON. **Import mapping** reads one
back. Use it to move a configuration from staging to production without
retyping it.

**Settings → Advanced** has the same pair for the settings themselves. Neither
export ever contains the client secret or any token — moving a mapping between
sites never moves a credential.

## Reset

**Reset to defaults** deletes your mapping and restores the shipped one:

| Zoho field | Target | Transform |
| --- | --- | --- |
| `Posting_Title` | Post: Title | Plain text |
| `Job_Description` | Post: Description | HTML |
| `Job_Summary` | Post: Excerpt | Plain text |
| `Job_Opening_ID` | Meta: Job code | Plain text |
| `Job_Opening_Status` | Meta: Zoho status | Plain text |
| `Department_Name` | Taxonomy: Department | Use lookup name |
| `Job_Type` | Taxonomy: Employment type | Plain text |
| `Work_Experience` | Taxonomy: Experience | Plain text |
| `Industry` | Meta: Industry | Plain text |
| `City` / `State` / `Country` | Meta: City / State / Country | Plain text |
| `Remote_Job` | Meta: Remote | Yes / No |
| `Salary` | Meta: Salary | Plain text |
| `Number_of_Positions` | Meta: Positions | Whole number |
| `Date_Opened` | Meta: Posted date | Date |
| `Expected_Closing_Date` | Meta: Closing date | Date |
| `Created_Time` / `Modified_Time` | Meta: Created / Modified | Date and time |
| `Client_Name` | Meta: Client | Use lookup name |
| `Website` | Meta: Application URL | URL |

## Status mapping

Separate from field mapping, **Settings → Sync → Status mapping** translates
Zoho's `Job_Opening_Status` values into the four states the plugin understands —
`active`, `inactive`, `expired`, `closed`. Anything unrecognised falls back to
the configured default status.

Only `active` jobs appear in listings and the public API by default. See
[Data model](data-model.md#job-status).

## Changing a mapping later

Mapping changes apply from the next sync onward; they do not rewrite existing
jobs on their own. To apply a change immediately, run a full sync — or use
**Resync from Zoho** on one job to check the result before committing to it.

Removing a row stops the plugin writing that field. It does **not** delete the
values already stored on existing jobs.
