# Newspack GA4 reader params → GTM

Make Newspack's reader context (`logged_in`, `is_reader`, `is_donor`, …) available as
GA4 custom dimensions on sites that tag GA4 **through Google Tag Manager** rather than
through Site Kit's own gtag.

## Why this is needed

Newspack injects reader params into **Site Kit's gtag** config. On a site whose GA4
pageview is fired by a **GTM container** instead, those params never reach GA4, so the
dimensions show `(not set)` on `page_view` and the GA4-automatic events. (Newspack's own
`np_*` events still carry the params – only the GTM-fired events are blind.)

This template bridges that gap: newspack-plugin pushes the params to `window.dataLayer`,
GTM reads them as Data Layer Variables, and you attach them to your GA4 tag.

## Prerequisite

The site must run a newspack-plugin version that pushes reader params to the dataLayer
(the `window.dataLayer.push({ logged_in: … })` change). Verify on the live front-end:

```js
// In the browser console on any front-end page:
window.dataLayer.find( e => 'logged_in' in e )
```

If that returns an object, you're good. If `undefined`, the plugin isn't pushing yet –
stop here until it ships.

## The params

| dataLayer key | Scope | Values |
|---|---|---|
| `logged_in` | every page | `yes` / `no` |
| `is_reader` | every page | `yes` / `no` |
| `is_newsletter_subscriber` | every page | `yes` / `no` |
| `is_donor` | every page | `yes` / `no` |
| `is_subscriber` | every page | `yes` / `no` |
| `post_id` | singular views | numeric |
| `author` | singular views | name(s) |
| `categories` | singular / category archive | comma-separated |
| `group` | when Content Gate is enabled | anon group IDs / `none` |
| `access_source` | every page, when Content Gate is enabled | product name / `subscription` / `one_time_purchase` / `group` / `institution` / `domain` / `reader_data` / `metering_eligible` / `gated` / `no_custom_access_gate` |

`email_hash` is intentionally **not** in the dataLayer (kept out of third-party reach).

> **Privacy note:** everything pushed to `window.dataLayer` is readable by *every* tag in
> the container – not just Google's GA4 tag, but any third-party tags too. The params above
> are intentionally coarse and anonymized (yes/no flags, anonymized group IDs, no PII), and
> `email_hash` is excluded for exactly this reason. Don't extend this set with reader PII.

## Step 1 – Import the variables

`newspack-ga4-reader-params.gtm.json` is a GTM container export containing:

- the **10 Data Layer Variables** (named `DLV - Newspack - <key>`), and
- one **`Newspack - GA4 Reader Params (config settings)`** variable that bundles all 10 as
  Google-tag configuration parameters – so you wire them with a single reference.

Everything in Steps 1–2 happens in **Google Tag Manager** ([tagmanager.google.com](https://tagmanager.google.com)),
not the GA4 dashboard — GA4 has no import surface for this.

1. In Tag Manager, open the container that fires the site's GA4 tag, then **Admin →
   Import Container**.
2. Choose the JSON file.
3. Select an **existing workspace** (create a throwaway one to review first).
4. Choose **Merge → Rename conflicting tags, triggers, and variables** (safe; never
   overwrites your existing config).
5. Preview the changes (should be 11 new variables, nothing else), then **Confirm**.

### Already imported an earlier version?

The whole fix below happens in **Tag Manager** — the same container you imported into the
first time. Nothing needs to change in the GA4 dashboard: the dimension registration from
your original setup still applies, and on Access Control sites Newspack registers
`access_source` there automatically.

Merge → *Rename conflicting* never overwrites, so the incoming
`Newspack - GA4 Reader Params (config settings)` variable arrives renamed (GTM appends a
suffix) and your GA4 tag stays wired to the **old** bundle — which has no `access_source`
row. The dataLayer variable imports fine; the tag just never reads it, and the dimension
stays `(not set)`. Two ways to fix it, either is fine:

- Re-import choosing **Overwrite** for that one config-settings variable, or
- Open your existing bundle variable and add the row by hand: parameter `access_source`,
  value `{{DLV - Newspack - access_source}}`.

Then confirm in **GTM Preview** that `access_source` actually arrives on a `page_view` —
the rename is silent, so the tag looks correctly wired until you check the event. Finally,
**Submit → Publish** the container version. Preview only shows your draft workspace; live
traffic keeps the old container until you publish.

## Step 2 – Attach the params to your GA4 tag

Open your GA4 pageview tag – the **Google Tag** / **GA4 Configuration** tag that fires on
*All Pages* – open **Configuration settings**, and set the **Configuration settings
variable** field to:

```
{{Newspack - GA4 Reader Params (config settings)}}
```

That's the whole wiring step – config-level params propagate to `page_view` and every
subsequent event, so all 10 reader params now ride your existing pageview.

<details>
<summary>Manual alternative (if you'd rather not use the bundling variable)</summary>

Add each param as an individual **configuration parameter** row on the tag instead:

| Configuration parameter | Value |
|---|---|
| `logged_in` | `{{DLV - Newspack - logged_in}}` |
| `is_reader` | `{{DLV - Newspack - is_reader}}` |
| `is_newsletter_subscriber` | `{{DLV - Newspack - is_newsletter_subscriber}}` |
| `is_donor` | `{{DLV - Newspack - is_donor}}` |
| `is_subscriber` | `{{DLV - Newspack - is_subscriber}}` |
| `post_id` | `{{DLV - Newspack - post_id}}` |
| `author` | `{{DLV - Newspack - author}}` |
| `categories` | `{{DLV - Newspack - categories}}` |
| `group` | `{{DLV - Newspack - group}}` |
| `access_source` | `{{DLV - Newspack - access_source}}` |

</details>

## Step 3 – Register the GA4 custom dimensions

This is the one step that happens in the **GA4 dashboard**
([analytics.google.com](https://analytics.google.com)), not Tag Manager. GA4 only reports
event params it's told to keep. In GA4 → **Admin → Custom definitions → Create custom
dimension**, add an **event-scoped** dimension for each param you want to report on
(Dimension name of your choice; **Event parameter** = the exact key, e.g. `logged_in`).
Several may already exist – don't duplicate. On sites with Access Control enabled,
Newspack registers `access_source` automatically, so check before adding it by hand.

## Step 4 – Verify and publish

1. GTM → **Preview**, load the site.
2. Confirm the `DLV - Newspack - *` variables resolve (e.g. `logged_in = no`).
3. GA4 → **Admin → DebugView**: open a `page_view` and confirm `logged_in` (and the
   others) appear as parameters with real values – not `(not set)`.
4. Back in GTM, **Submit → Publish** the container version. Live traffic keeps serving
   the previous container until this step — Preview alone ships nothing.

## Watch-outs

- **Don't run two GA4 pageview tags into the same property.** If Site Kit's gtag *and* a
  GTM tag both fire `page_view` to the same measurement ID, pageviews are double-counted.
  Pick one tagging path per property (for GTM-tier sites, keep GTM and turn off Site Kit's
  GA snippet).
- **Treat anonymous `institution` counts as approximate.** Institutional access can be
  matched by IP, so an anonymous visitor's `access_source` (and `group`) is baked into the
  page HTML. Full-page caching serves that same HTML to the *next* anonymous visitor on the
  same URL, whatever network they're on — so `institution` can be over-reported and
  under-reported for anonymous traffic. Logged-in readers bypass the page cache and are
  unaffected.
- **Watch for a drifted "Google tag".** A property's Google tag (`GT-…`) can be configured
  to deliver to a *different* measurement ID than the one you report on, sending your
  param-rich hits to a property nobody reads. Confirm in GA4 Admin → Data streams →
  *Configure tag settings* that the tag's destination is the property you actually report
  on.
