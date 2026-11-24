# SplitSection

The two-column section that settings tabs and item editors are built from: what
the section is on the left, its fields on the right. Every screen gets the same
columns, gaps and header spacing, so sections line up from one screen to the
next.

```jsx
import { SplitSection } from 'newspack-components';
```

The parts hang off one exported object, as `Drawer` and `StatCard` do.

## Usage

```jsx
import { __ } from '@wordpress/i18n';
import { Divider, SplitSection, TextControl } from 'newspack-components';

<>
	<SplitSection.Root>
		<SplitSection.Header
			title={ __( 'Group Names', 'newspack-plugin' ) }
			description={ __( 'Choose how a group bought at checkout is named.', 'newspack-plugin' ) }
		/>
		<SplitSection.Content>
			<TextControl label={ __( 'Name', 'newspack-plugin' ) } withMargin={ false } __nextHasNoMarginBottom __next40pxDefaultSize />
		</SplitSection.Content>
	</SplitSection.Root>
	<Divider alignment="full-width" variant="tertiary" />
	<SplitSection.Root>…</SplitSection.Root>
</>
```

## When to use it

Use it for every section of a settings or edit screen that pairs a title and
description with a group of fields. Content that needs the full width (logs,
card pickers, tables) does not go in one: put its `SectionHeader` above it
instead.

A grid of cards (`CardFeature`, `SettingsCard`) is a different pattern and does
not use this component.

## Parts

### `SplitSection.Root`

The two-column grid, 32px apart. Below 744px it collapses to one column, with
the header above the fields. The columns split 50/50 whatever their content.
It has no outer margin: the space around it comes from the parent `Stack` or the
`Divider` between sections.

Takes `className` and any `div` attribute.

### `SplitSection.Header`

The left column: a `SectionHeader` with `noMargin`, then any children.

- `title`: the section's name. Required.
- `description`: a short line on what the section controls.
- `heading`: heading level, `2` to `6`. Defaults to `2`. Use `3` when the screen
  already has an `h2` above the sections.
- `id`: placed on the header, so a link with `?scrollTo=<id>` scrolls to it.
- `className`: added to the column.
- `children`: shown 16px below the description, such as a notice that applies
  to the whole section. Children stretch to the column width, so wrap a lone
  button in a `Stack` from `@wordpress/ui` to keep its natural width.

### `SplitSection.Content`

The right column: the fields, stacked 24px apart (`Stack gap="xl"`). Fields drop
their own bottom margin so the gap is the only spacing. Newspack `TextControl`
takes `withMargin={ false }` with `__nextHasNoMarginBottom` and
`__next40pxDefaultSize`; core `SelectControl` and `ToggleGroupControl` take both
`__next*` props; `ToggleControl` and `CheckboxControl` take
`__nextHasNoMarginBottom`.

Takes `className` and any `div` attribute.

## Dividers

The divider between sections is not part of the component. Render
`<Divider alignment="full-width" variant="tertiary" />` between two sections,
so the last section on a screen does not end with a line.
