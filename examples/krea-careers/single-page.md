# The single job page in Elementor

The design's job page has a hero with the title and three pills, a two-column
body (position summary, responsibilities, skills, application process,
additional information) and a sidebar (status badge, apply button, role details,
related openings). The plugin's own single template is a different layout, so
the page is built in Elementor and the plugin supplies the data.

## 1. Hand the page to Elementor

Settings → **Frontend** → tick **Disable the default jobs frontend**.

Job URLs keep resolving; the plugin simply stops drawing the page, loading its
stylesheet on it, or redirecting expired jobs. Everything else (sync, REST,
shortcodes, structured data, sitemap) is unchanged.

## 2. Create the template

Elementor → Templates → Theme Builder → **Single** → display condition
**Zoho Recruit Jobs → All**.

| Design element | Elementor widget | Content |
| --- | --- | --- |
| Hero title | Heading | Dynamic → Post Title |
| Hero pills (Full time · Sri City · Posted 7 months ago) | Shortcode | `[zoho_job_meta fields="employment_type,location,posted_date"]` with **Job details layout = Inline** on the Display tab |
| Position summary grid | Shortcode | `[zoho_job_meta fields="department,location,experience,employment_type,salary"]` |
| Responsibilities, key responsibilities, additional information | Post Content | the Zoho job description (headings and lists come through as Zoho wrote them) |
| Skills required | — | see "Fields the plugin does not have yet" |
| Application process | Text Editor | static copy from the design |
| Sidebar: status badge | Shortcode | `[zoho_job_meta fields="status"]`, styled as a pill |
| Sidebar: Apply via Zoho Recruit | Shortcode | `[zoho_job_apply label="Apply via Zoho Recruit →"]` |
| Sidebar: Role details | Shortcode | `[zoho_job_meta fields="job_code,employment_type,location,department,salary"]` |
| Sidebar: Related openings | Shortcode | `[zoho_jobs related="department" template="related" per_page="2" show_filters="false" show_search="false" show_pagination="false" show_excerpt="false"]` |

`[zoho_jobs related="department"]` lists other jobs in the same department and
always leaves the current job out. Use `related="category"` to relate by group
instead.

## 3. Fields the plugin does not have yet

Educational qualifications, Reporting to and Skills are Zoho fields with no
built-in mapping target. Three options, cheapest first:

1. Ask the recruiters to put them in the job description under headings. They
   then render through Post Content with no further work.
2. Map them to spare meta targets that exist today: **Meta: Industry** and
   **Meta: Client**, then show them with `[zoho_job_meta fields="industry,client"]`
   and relabel the two under **Filter labels**.
3. Add real targets with the `jszr_mapping_targets` filter in a small site
   plugin, for example `meta:_zoho_recruit_qualifications`, and expose them to
   the shortcode with `jszr_available_job_info_fields`. This is a developer task
   of an hour or so.

## 4. Closed jobs

With the default frontend disabled the plugin no longer shows its "position
closed" notice. `[zoho_job_apply]` already renders nothing for a closed job, and
`{status_label}` / `fields="status"` reads "Closed", so an Elementor
**Display Conditions** rule on the status, or simply the badge, covers it.
