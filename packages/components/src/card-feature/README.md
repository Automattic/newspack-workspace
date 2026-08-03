# CardFeature

A card component for presenting a named feature or setting with a predictable, state-driven interaction model. It handles the primary button, an optional "More" dropdown, and a badge automatically based on the `enabled` and `requirements` props.

## Layout rules

- **Maximum 2 cards per row.** Use `<Grid columns={ 2 }>` — never 3 or more. Cards are designed to be read, not scanned, and 3+ columns makes them too narrow for the content.
- The icon is always displayed on the **right-hand side**, aligned to the top of the content.

## States

| State | Condition | Button | Dropdown | Badge |
|---|---|---|---|---|
| **Unmet requirements** | `requirements` is set | "Enable" — disabled (clickable if `requirementsActionable`) | Hidden | Error badge with `requirements` text |
| **Disabled** | `!enabled`, no requirements | "Enable" | Hidden | None |
| **Enabled** | `enabled`, no requirements | "Configure" | Shown if `moreControls` provided | Success badge ("Enabled") |

The card content (title + description) is visually muted (gray text, lighter border) when `requirements` is set.

## Basic usage

```tsx
import { __ } from '@wordpress/i18n';

<CardFeature
	title={ __( 'Metered countdown', 'newspack-plugin' ) }
	description={ __( 'Show a countdown banner letting readers know how many free views they have left.', 'newspack-plugin' ) }
	enabled={ isEnabled }
	onEnable={ () => setEnabled( true ) }
	onConfigure={ () => history.push( '/settings/countdown' ) }
	moreControls={ [
		{ title: __( 'Disable', 'newspack-plugin' ), onClick: () => setEnabled( false ) },
	] }
/>
```

## With unmet requirements

When `requirements` is set the button is disabled and an error badge displays the string. The title and description are visually muted.

```tsx
import { __ } from '@wordpress/i18n';

<CardFeature
	title={ __( 'Metered countdown', 'newspack-plugin' ) }
	description={ __( 'Show a countdown banner letting readers know how many free views they have left.', 'newspack-plugin' ) }
	enabled={ isEnabled }
	requirements={ __( 'Requires metering', 'newspack-plugin' ) }
	onEnable={ () => setEnabled( true ) }
	onConfigure={ () => history.push( '/settings/countdown' ) }
	moreControls={ [
		{ title: __( 'Disable', 'newspack-plugin' ), onClick: () => setEnabled( false ) },
	] }
/>
```

## With a custom icon

The `icon` prop accepts an object, not a bare node. Pass `node` for the icon element, `fill` to control the SVG colour (applied via `currentColor`), `backgroundColor` for a container background, and `radius` for the corner treatment.

The icon container is always **40 × 40 px** with the SVG at **24 × 24 px**. `radius` only applies when `backgroundColor` is set.

```tsx
import { __ } from '@wordpress/i18n';
import { Icon, starFilled } from '@wordpress/icons';

// Icon with fill only
<CardFeature
	title={ __( 'Content gifting', 'newspack-plugin' ) }
	description={ __( 'Let subscribers share gated articles with non-subscribers.', 'newspack-plugin' ) }
	icon={ { node: <Icon icon={ starFilled } />, fill: '#003da5' } }
	enabled={ isEnabled }
	onEnable={ handleEnable }
	onConfigure={ handleConfigure }
	moreControls={ [ { title: __( 'Disable', 'newspack-plugin' ), onClick: handleDisable } ] }
/>

// Icon with circular background
<CardFeature
	title={ __( 'Content gifting', 'newspack-plugin' ) }
	description={ __( 'Let subscribers share gated articles with non-subscribers.', 'newspack-plugin' ) }
	icon={ { node: <Icon icon={ starFilled } />, fill: '#003da5', backgroundColor: '#dfe7f4', radius: 'full' } }
	enabled={ isEnabled }
	onEnable={ handleEnable }
	onConfigure={ handleConfigure }
	moreControls={ [ { title: __( 'Disable', 'newspack-plugin' ), onClick: handleDisable } ] }
/>
```

## With custom button labels

Override `enableLabel` and `configureLabel` to match the context of the feature.

```tsx
import { __ } from '@wordpress/i18n';

<CardFeature
	title={ __( 'Apple News', 'newspack-plugin' ) }
	description={ __( 'Automatically publish articles to Apple News.', 'newspack-plugin' ) }
	enabled={ isEnabled }
	enableLabel={ __( 'Connect', 'newspack-plugin' ) }
	configureLabel={ __( 'Manage connection', 'newspack-plugin' ) }
	onEnable={ handleConnect }
	onConfigure={ () => history.push( '/settings/apple-news' ) }
	moreControls={ [ { title: __( 'Disconnect', 'newspack-plugin' ), onClick: handleDisconnect } ] }
/>
```

## With a custom badge

Override `badgeText` and `badgeLevel` to change the badge shown when the feature is enabled. Available levels: `default`, `info`, `success`, `warning`, `error`.

```tsx
import { __ } from '@wordpress/i18n';

<CardFeature
	title={ __( 'Stripe', 'newspack-plugin' ) }
	description={ __( 'Accept payments via Stripe.', 'newspack-plugin' ) }
	enabled={ isEnabled }
	badgeText={ __( 'Live mode', 'newspack-plugin' ) }
	badgeLevel="info"
	onEnable={ handleEnable }
	onConfigure={ () => history.push( '/settings/stripe' ) }
	moreControls={ [ { title: __( 'Disable', 'newspack-plugin' ), onClick: handleDisable } ] }
/>
```

## With multiple "More" controls

`moreControls` accepts any number of items, each with a `title`, `onClick`, and an optional `icon`.

```tsx
import { __ } from '@wordpress/i18n';

<CardFeature
	title={ __( 'Newsletters', 'newspack-plugin' ) }
	description={ __( 'Send newsletters directly from the WordPress editor.', 'newspack-plugin' ) }
	enabled={ isEnabled }
	onEnable={ handleEnable }
	onConfigure={ () => history.push( '/settings/newsletters' ) }
	moreControls={ [
		{ title: __( 'Edit', 'newspack-plugin' ), onClick: handleEdit },
		{ title: __( 'Preview', 'newspack-plugin' ), onClick: handlePreview },
		{ title: __( 'Disable', 'newspack-plugin' ), onClick: handleDisable },
	] }
/>
```

## Props

| Prop | Type | Default | Description |
|---|---|---|---|
| `title` | `string` | — | Card heading (**required**) |
| `description` | `string` | — | Supporting text below the title |
| `icon` | `CardFeatureIcon` | — | Icon displayed on the right. See `CardFeatureIcon` below. |
| `enabled` | `boolean` | `false` | Whether the feature is currently enabled |
| `requirements` | `string` | — | When set, enters the unmet-requirements state; value is used as the error badge text |
| `requirementsActionable` | `boolean` | `false` | When `requirements` is set, keep the primary button clickable so it can remediate the unmet requirement |
| `enableLabel` | `string` | `"Enable"` | Primary button label when not enabled |
| `configureLabel` | `string` | `"Configure"` | Primary button label when enabled |
| `onEnable` | `() => void` | — | Called when the primary button is clicked and the feature is not enabled |
| `onConfigure` | `() => void` | — | Called when the primary button is clicked and the feature is enabled |
| `moreControls` | `MoreControl[]` | — | Items for the "More" dropdown, shown only when enabled |
| `badgeText` | `string` | `"Enabled"` | Badge text shown when enabled |
| `badgeLevel` | `BadgeLevel` | `"success"` | Badge level shown when enabled |
| `className` | `string` | — | Additional class name applied to the card element |

### `CardFeatureIcon`

```ts
type CardFeatureIcon = {
	node: React.ReactNode;       // The icon element to render
	fill?: string;               // SVG fill colour (applied via currentColor)
	backgroundColor?: string;    // Background colour of the 40×40 container
	radius?: 'small' | 'full';   // 'small' = 2px ($radius-small), 'full' = 50% ($radius-round)
	                             // Only applied when backgroundColor is set.
};
```

### `MoreControl`

```ts
type MoreControl = {
	title: string;
	onClick: () => void;
	icon?: React.ReactNode;
};
```
