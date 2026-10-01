# Krea careers pages — setup guide

How to build the careers section in the Figma export with the plugin's
settings and shortcodes. Nothing here changes the plugin's default design: every
option below is off until you turn it on, and the look comes from one block of
custom CSS plus two card templates that live in the settings, not in code.

The files in this folder:

| File | Paste into |
| --- | --- |
| `card-faculty.html` | Settings → Display → Named card templates, name `faculty` |
| `card-staff.html` | Settings → Display → Named card templates, name `staff` |
| `card-related.html` | Settings → Display → Named card templates, name `related` |
| `krea.css` | Settings → Display → Custom CSS |
| `single-page.md` | Instructions for the single job page in Elementor |

This folder is not part of the plugin ZIP. It is a worked example for one site.

---

## 1. Decide which Zoho field groups the jobs

The four tabs — SIAS faculty, IFMR GSB faculty, Staff openings, Research
openings — are four pages, each listing the jobs in one group. The group has to
come from Zoho, so every Job Opening needs a field whose value is one of those
four (or whatever Zoho calls them).

On **Zoho Recruit Jobs → Field Mapping**, map that field to **Taxonomy:
Category**. After the next sync the four values appear as terms under **Zoho
Recruit Jobs → Categories**, and the **Shortcodes** panel on the Display tab
lists a ready-made shortcode for each.

The staff card also shows **Functional area** and **School**. In this guide:

- Functional area = the Zoho field mapped to **Taxonomy: Department**
  (`{department}` in the card).
- School = the Zoho field mapped to **Taxonomy: Category** (`{category}`) —
  only if the grouping above is *not* already using Category. If it is, map
  School to **Meta: Industry** instead and change `{category}` to `{industry}`
  in `card-staff.html`.

Whichever you choose, set the visitor-facing names under **Settings → Display →
Filter labels**: Department → "Functional area", Category → "School".

## 2. URLs

Recommended structure, which matches the breadcrumb in the design:

```
/careers/                     the main Careers page (a WordPress page)
/careers/sias-faculty/        sub-pages, children of Careers
/careers/ifmr-gsb-faculty/
/careers/staff-openings/
/careers/research-openings/
/careers/openings/<job>/      one job
```

Settings → **Frontend**:

- **Job URL slug**: `careers/openings`
- **Archive slug**: `careers/openings` (or untick **Public job pages** if the
  plugin's own archive page is not wanted; the pages above do that job).

Save, then visit Settings → Permalinks once so WordPress rebuilds its rules.

The alternative — jobs at `/careers/<job>` — only works if the sub-pages are
*not* children of `/careers/`, because `/careers/sias-faculty` would then be
read as a job slug and 404. Use flat sub-page slugs if you go that way.

## 3. Display settings

Settings → **Display**:

| Setting | Value |
| --- | --- |
| Listing layout | leave as it is (the pages pick a named template) |
| Named card templates | add `faculty`, `staff` and `related` from the three HTML files |
| Filter style | **Pills** |
| Filter labels | Department → Functional area, Category → School |
| Filters to offer | Employment type (faculty and research pages); Department, Category, Location, Employment type (staff page) |
| Application status | on, if the staff page should offer Open / Closed |
| Search label | `Search by keyword` |
| Search placeholder | `Search by keyword` (faculty pages) or `e.g. Web application developer` (staff) |
| Search looks in | Job title, Summary, Job code (untick Description to avoid matching "reports to the manager") |
| Location search | on; placeholder `Location` |
| Pagination | **Load more**, label `Load more listings` |
| Custom CSS | contents of `krea.css` |

Filters are chosen site-wide, so the staff page's richer filter panel and the
faculty pages' pills come from the shortcode: the faculty pages switch the
dropdown filters off and keep only the pills row (see below).

Settings → **Frontend → Apply button**: label `Apply via Zoho Recruit`, open in
a new tab.

Settings → **Structured data**: Organisation name `Krea University`, so
`{org_name}` on the cards reads correctly.

## 4. The pages

Create the five pages in Elementor. The hero (breadcrumb, "Careers at Krea",
intro paragraph, the four tab buttons linking to the sub-pages), the FAQ
accordion and the "Don't see the right role?" banner are ordinary Elementor
sections — the plugin is only the listing in the middle.

Put one **Shortcode** widget on each sub-page:

```
SIAS faculty
[zoho_jobs category="sias-faculty" template="faculty" per_page="10" show_filters="true" show_search="true"]

IFMR GSB faculty
[zoho_jobs category="ifmr-gsb-faculty" template="faculty" per_page="10" show_filters="true" show_search="true"]

Research openings
[zoho_jobs category="research-openings" template="faculty" per_page="10" show_filters="true" show_search="true"]

Staff openings
[zoho_jobs category="staff-openings" template="staff" per_page="10" show_filters="true" show_search="true"]
```

The term slugs come from the Shortcodes panel on the Display tab; the ones
above assume the four group values are named as in the design.

With **Filter style = Pills** every filter on the site is drawn as pills. The
faculty pages in the design show only the employment-type pills, and the staff
page shows a full panel of dropdowns. Two ways to get both:

1. **Simplest:** keep Pills site-wide and offer only Employment type, Department
   and Location under **Filters to offer**. The staff page then shows three pill
   rows instead of dropdowns. Close to the design, no code.
2. **Exact:** keep Pills site-wide and add this to your theme's `functions.php`
   (or a small site plugin) so the staff page draws dropdowns:

   ```php
   add_filter( 'jszr_visible_filters', function ( $filters ) {
       return is_page( 'staff-openings' )
           ? array( 'department', 'category', 'location', 'employment_type' )
           : array( 'employment_type' );
   } );

   add_filter( 'option_jszr_settings', function ( $settings ) {
       if ( is_page( 'staff-openings' ) && is_array( $settings ) ) {
           $settings['filters_style'] = 'dropdowns';
       }
       return $settings;
   } );
   ```

## 5. The single job page

See `single-page.md`. In short: Settings → Frontend → **Disable the default
jobs frontend**, then build an Elementor Theme Builder single template for the
`zoho_job` post type with the three shortcodes `[zoho_job_meta]`,
`[zoho_job_apply]` and `[zoho_jobs related="department" template="related"]`.

## 6. Checklist before going live

- A full sync has run after the mapping change, and the four category terms
  exist.
- Each sub-page shows only its own group (open two tabs and compare).
- Filters apply on click (pills) and the Filter button still works with
  JavaScript off (test by disabling scripts once).
- "Load more listings" appends rather than replacing.
- The apply button opens the Zoho Recruit application in a new tab.
- Job URLs are under `/careers/openings/` and the sub-pages still resolve.
