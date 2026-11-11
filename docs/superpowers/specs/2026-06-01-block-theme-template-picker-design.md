# Block-theme default template picker (visual previews)

**Date:** 2026-06-01
**Status:** Design — pending implementation plan
**Scope:** `newspack-plugin`
**Builds on:** PR #169 (`feat/block-theme-template-control`)

## Background

PR #169 adds a control to set the default template applied to newly created
posts and pages, under **Newspack → Settings → Advanced Settings → Default
Templates**. It stores two theme mods (`post_template_default`,
`page_template_default`) and applies the chosen template on `wp_insert_post`
for block themes. The UI is two `SelectControl` dropdowns.

For block themes this is hard to use: a site can have many custom templates
whose names (`Featured`, `Featured-2`, site-created slugs, etc.) are not
descriptive enough to tell apart from a dropdown. The classic Newspack theme
does not have this problem — it exposes a fixed list of three descriptive,
non-user-extensible options.

## Goals

- Replace the block-theme template dropdowns with a **visual picker**: a grid
  of selectable cards, each showing a live, accurate preview of the template,
  with the current default highlighted.
- Support arbitrary **user-created templates**, updating live as templates
  change (no static art, no stale screenshots).
- Keep previews **accurate** — they must reflect the actual template layout
  (the disambiguation value depends on this).
- Stay on **supported, public WordPress APIs**.

## Non-goals

- The **classic theme** keeps the existing two dropdowns. Its templates are
  PHP files (no block markup to preview), and the option set is fixed and
  descriptive. No change there.
- The **destructive "apply template to all existing posts" control**
  (`post_template_all_posts`, in the "Featured Image Position And Post Template
  For All Posts" section) is **out of scope**. See *Reusability* below — the
  picker components are built so that control can adopt the preview card later,
  in its own (safer) framing, as a separate effort.
- No use of the Site Editor "…" row-action menu. That route requires
  private/unsupported WordPress APIs (`registerEntityAction` is fenced behind
  `IS_GUTENBERG_PLUGIN` and `unlock()` in the installed `@wordpress/editor`),
  which is too fragile across the WP versions Newspack runs on.

## Relationship to PR #169

This work is **purely additive** on top of #169, which should land first as the
functional baseline:

- **Unchanged / reused:** theme-mod storage, the `wp_insert_post` apply logic,
  the REST endpoint, the classic-theme dropdown path, and validation.
- **Replaced:** only the *block-theme branch* of the `DefaultTemplates` UI
  component (dropdowns → picker).
- **Extended:** the existing REST endpoint gains a template `id` per block
  option and a block-editor `settings` payload.

Nothing in #169 is reworked or thrown away. Block-theme users see dropdowns in
the interim; because the data model is identical, switching the control later
is functionally transparent to them.

## Approach decision

Accurate previews of arbitrary, live-updating templates rule out static
illustrations and front-end screenshots (a user-created custom template often
isn't assigned to any published post, so there's no URL to capture, and
screenshots go stale). That leaves **live `BlockPreview`** — the same engine the
Site Editor uses for its template thumbnails.

Core renders an accurate preview (`@wordpress/editor`
`dataviews/fields/content-preview`) by:

1. Wrapping the preview in `EditorProvider` fed `getEditorSettings()` (which
   carries theme.json styles).
2. Loading the template's blocks via
   `useEntityBlockEditor( 'postType', 'wp_template', { id } )`.
3. Rendering `<BlockPreview.Async><BlockPreview blocks={ blocks } /></BlockPreview.Async>`.

Template parts (header/footer) and dynamic blocks resolve inside that provider,
which is why the result is faithful. By supplying the same `settings` + template
entity, we get the same fidelity by construction.

`BlockPreview`, `EditorProvider`, and `useEntityBlockEditor` are all public
`@wordpress/*` exports — no `unlock()`/private APIs. (Core reads a background
color via a private `useStyle`; we sidestep that by letting the provider's
styles supply the background, or by setting a neutral one.)

## UX design

The "Default Templates" section becomes theme-aware:

- **Classic theme:** unchanged — the two existing `SelectControl` dropdowns.
- **Block theme:** a visual picker. Two labelled groups, *Default template for
  new posts* and *Default template for new pages*, each a responsive grid of
  preview cards:

```
Default template for new posts
┌──────────┐ ┌──────────┐ ┌──────────┐
│ [preview]│ │ [preview]│ │ [preview]│
│          │ │  ✓ deflt │ │          │
├──────────┤ ├──────────┤ ├──────────┤
│ Default  │ │ Featured │ │ Wide     │
└──────────┘ └──────────┘ └──────────┘
```

- Each card is a selectable control with radio semantics: clicking sets the
  default for that post type and writes the corresponding theme mod via the
  wizard's existing `update()`.
- The selected card is highlighted and carries a **"Default" badge** — the
  at-a-glance "which one is the default" indicator.
- The first card is always **"Default"** (value `default` = WordPress' normal
  template resolution), matching #169's model.
- Cards render the set the endpoint already returns (theme.json
  `customTemplates` + site-created templates; hierarchy templates excluded).

### Loading / empty states

- Previews render async (`BlockPreview.Async`): cards show a skeleton, then
  fill in. No blocking spinner on the whole section.
- If no assignable custom templates exist, show only the single **"Default"**
  card (consistent with the picker rather than falling back to a dropdown).

## Frontend architecture

New `default-templates/` folder under the advanced-settings view:

- **`DefaultTemplates`** (existing component, made theme-aware): classic →
  render today's dropdowns; block theme → render two `TemplatePicker`s (post,
  page).
- **`TemplatePicker`** (`postType`, options, value, onChange): renders the grid
  for one post type, maps options to `TemplateCard`s, owns selection and the
  "Default" badge.
- **`TemplateCard`** (`template`, `selected`, `onSelect`): one selectable
  preview. Renders the `BlockPreview` for the template's blocks via
  `useEntityBlockEditor( 'postType', 'wp_template', { id } )`, scaled to a
  thumbnail (`viewportWidth`).

### Data flow

- The block-editor **`settings`** object is provided once at the top of the
  block-theme branch (one `EditorProvider` wrapping both pickers) and shared by
  all cards — fetched from the endpoint, not recomputed per card.
- Template **entities** come from `@wordpress/core-data`, keyed by the `id` the
  endpoint now returns alongside `label`/`value`.
- Selection writes the same theme mods through the existing wizard `update()`;
  storage and apply logic are untouched.

### Performance

- Previews are heavy (each ≈ a mini editor). Render async and lazily; only
  mount the block-theme branch's previews when shown.
- Share a single `settings`/`EditorProvider` across all cards.
- Cap `viewportWidth` so previews scale cleanly into thumbnails.

### Thumbnail framing (spike finding)

- A wide `viewportWidth` makes templates with empty content areas look sparse
  and oddly positioned. `viewportWidth ≈ 1000` plus a **fixed-aspect crop**
  (e.g. 4:3, `overflow: hidden`, top-aligned) yields uniform cards that read
  like the Site Editor's template grid.
- Async renders need **loading skeletons** so cards don't pop in jarringly.

## Backend

Reuse from #169 unchanged: theme-mod storage, `maybe_set_default_template` on
`wp_insert_post`, validation, classic option list.

Extend the existing endpoint
`GET /newspack/v1/wizard/newspack-settings/default-templates` (permission stays
`manage_options`):

- Add each block template's **`id`** (entity ID) to its option object, so the
  frontend can load blocks via `useEntityBlockEditor`.
- Add a **`settings`** key built server-side with `get_block_editor_settings()`
  + a `WP_Block_Editor_Context`, so previews render with theme.json styles.
  (Delivery option A — bundled into the existing response — chosen over a
  separate route for a single round-trip; the payload is large but admin-only
  and fetched once.)

All new behavior is gated to `wp_is_block_theme()`; the classic path touches
none of it.

### Block-editor assets on the settings page (spike finding)

The Newspack Settings admin page does **not** load the block-editor scripts or
styles by default, and `BlockPreview` hard-depends on them:

- **Scripts:** without `wp-block-library` (and `wp-block-editor`), the JS
  globals are undefined and the picker throws (`registerCoreBlocks` on
  `undefined`). These must be enqueued so they load *before* the wizard bundle.
  The real implementation should add them as dependencies of the
  `newspack-wizards` script registration (scoped to the settings page + block
  theme), not by mutating an already-registered script's `deps` array as the
  spike did.
- **Styles:** `wp-block-library` + `wp-edit-blocks` are needed for accurate
  block rendering inside the preview iframe.
- Core blocks must be registered client-side (`registerCoreBlocks()` from
  `@wordpress/block-library`), guarded so it runs once. Note `wp-block-library`
  may already register them on load — avoid duplicate-registration warnings.

This enqueue belongs in the settings wizard's `enqueue_scripts_and_styles()`,
gated to `wp_is_block_theme()` and the settings page.

## Reusability (future: bulk "all posts" control)

`post_template_all_posts` overwrites the template on every existing single post
— a destructive, one-shot action (confirm dialog; resets to `none` after save).
The same name-disambiguation problem applies, so previews would help there too,
**but** the picker's "highlight the current default" metaphor doesn't fit a
control with no persistent selection, and a casual card-grid affordance is
dangerous for an irreversible bulk overwrite.

Therefore: build `TemplateCard` (and ideally `TemplatePicker`) as reusable
components, but do **not** adopt them in the bulk control as part of this work.
A future effort can reuse the card in a clearly distinct, safer framing
(collapsed/opt-in, explicit "Apply to all single posts…" action, confirm
preserved, no persistent "Default" badge).

## Implementation order

1. **Fidelity spike — DONE (2026-06-01).** Rendered the site's real templates
   via `BlockPreview` + localized `get_block_editor_settings()` inside the
   wizard. Result: previews are accurate (theme fonts, header/footer, logo,
   post-content placeholder) for theme and plugin/user-created templates alike.
   Approach validated. Findings folded into the *Backend* (asset enqueue) and
   *Thumbnail framing* sections above.
2. Endpoint: add `id` per block option + `settings` payload.
3. Settings-page asset enqueue (block-editor scripts/styles, block theme only).
4. `TemplateCard`, `TemplatePicker`, theme-aware `DefaultTemplates`.
5. Wire selection to existing `update()`; loading skeletons + empty state.
6. Tests.

## Edge cases

- **No custom templates:** show only the "Default" card.
- **Stored default no longer exists** (template deleted): handled by #169's
  `validate_template` on apply; the picker should fall back to highlighting
  "Default" when the stored value isn't in the option set.
- **Theme switch** (block ↔ classic): UI branches on `wp_is_block_theme()`;
  stored block-theme mods are simply ignored under a classic theme.
- **Template parts / dynamic blocks:** rely on core-data resolution within the
  provider, same as the Site Editor; accept the same (minor) imperfections.

## Testing

- **PHP:** extend `tests/unit-tests/default-templates.php` to assert block
  options include `id`, and that the endpoint response includes the `settings`
  key for block themes and omits the picker payload for classic.
- **JS:** component tests for `TemplatePicker`/`TemplateCard` — renders a card
  per option, marks the stored value as default, calls `update()` on select,
  shows the "Default" card and empty state. Mock `@wordpress/block-editor`
  `BlockPreview` and `useEntityBlockEditor` (don't render a real editor in
  jsdom).

## Risks / open questions

- **Preview fidelity in a wizard context** is the central risk — de-risked by
  the spike (step 1).
- **Performance** with many templates (two grids of heavy previews). Mitigated
  by async/lazy mount + shared settings; revisit if a site has many templates.
- **`settings` payload size** in the endpoint response — acceptable for a
  single admin screen, but confirm no unexpected bloat.
