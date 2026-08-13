# CardFeature

A card component for presenting a named feature or setting with a predictable, state-driven interaction model. It handles the primary button, an optional "More" dropdown, and a badge automatically based on the `enabled` and `requirements` props.

## Layout rules

- **Maximum 2 cards per row.** Use `<Grid columns={ 2 }>` — never 3 or more. Cards are designed to be read, not scanned, and 3+ columns makes them too narrow for the content.
- The icon is always displayed on the **right-hand side**, aligned to the top of the content.

## States

| State | Condition | Button | Dropdown | Badge |
|---|---|---|---|---|
| **Unmet requirements** | `requirements` is set | "Enable" — blocked but still focusable, and described by the badge (clickable if `requirementsActionable`) | Shown if `enabled` and `requirementsActionable` (and `moreControls` provided); otherwise hidden | Error badge with `requirements` text |
| **Disabled** | `!enabled`, no requirements | "Enable" | Hidden | None |
| **Enabled** | `enabled`, no requirements | "Configure" | Shown if `moreControls` provided | Success badge ("Enabled") |

When `requirements` is set the title drops to the muted text colour. The description already uses that colour in every state, so the unmet-requirements state is signalled by the title colour plus the error badge.

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

When `requirements` is set, an error badge displays the string and the title drops to the muted text colour. By default the requirement is treated as locked: the button is blocked and the "More" dropdown is hidden, so `onEnable` and `moreControls` have nothing to act on. `onConfigure` is unreachable whenever `requirements` is set at all, locked or not, because the button only reads "Configure" when there is no outstanding requirement.

A blocked button keeps its place in the tab order and is described by the error badge, so a keyboard or screen-reader user reaches it and hears why it will not act. Don't wrap it in your own `disabled` handling, which would undo that.

```tsx
import { __ } from '@wordpress/i18n';

<CardFeature
	title={ __( 'Metered countdown', 'newspack-plugin' ) }
	description={ __( 'Show a countdown banner letting readers know how many free views they have left.', 'newspack-plugin' ) }
	requirements={ __( 'Requires an API-based ESP', 'newspack-plugin' ) }
/>
```

Set `requirementsActionable` when the button is how the reader clears the requirement. It stays clickable and routes to `onEnable`, and an enabled card keeps its "More" dropdown so the feature can still be turned off.

```tsx
import { __ } from '@wordpress/i18n';

<CardFeature
	title={ __( 'Metered countdown', 'newspack-plugin' ) }
	description={ __( 'Show a countdown banner letting readers know how many free views they have left.', 'newspack-plugin' ) }
	enabled={ isEnabled }
	requirements={ __( 'Requires metering', 'newspack-plugin' ) }
	requirementsActionable
	enableLabel={ __( 'Set up metering', 'newspack-plugin' ) }
	onEnable={ () => history.push( '/settings/metering' ) }
	moreControls={ [
		{ title: __( 'Disable', 'newspack-plugin' ), onClick: () => setEnabled( false ) },
	] }
/>
```

## With a custom icon

`icon` takes either a descriptor object or a ready React element. A descriptor gets the standard treatment: pass `node` for the icon element, `fill` for the SVG colour, `backgroundColor` for a container background, and `radius` for the corner treatment. A ready element renders exactly as given, with no container, background or radius, which is the escape hatch for an icon that already carries its own chrome.

A descriptor's container is always **40 × 40 px** with the SVG at **24 × 24 px**. Setting `backgroundColor` without a `radius` gives 2px corners; pass `radius: 'full'` for a circle.

`fill` sets the container's `color`, which the SVG picks up through `fill: currentcolor`. That only recolours single-colour icons that inherit their fill, such as those from `@wordpress/icons`. A vendor's own mark carries `fill` on its paths and keeps its colours, so pair it with `backgroundColor` rather than trying to tint it.

`fill` and `backgroundColor` take any CSS colour. Reach for the Newspack palette when the icon should read as ours, and pass a literal when it should carry a third party's colour.

```tsx
import { __ } from '@wordpress/i18n';
import { Icon, starFilled } from '@wordpress/icons';
import colors from 'newspack-colors';

// Newspack palette, fill only
<CardFeature
	title={ __( 'Content gifting', 'newspack-plugin' ) }
	description={ __( 'Let subscribers share gated articles with non-subscribers.', 'newspack-plugin' ) }
	icon={ { node: <Icon icon={ starFilled } />, fill: colors[ 'primary-600' ] } }
	enabled={ isEnabled }
	onEnable={ handleEnable }
	onConfigure={ handleConfigure }
	moreControls={ [ { title: __( 'Disable', 'newspack-plugin' ), onClick: handleDisable } ] }
/>

// A vendor mark on its own brand background, keeping the mark's colours
<CardFeature
	title={ __( 'Mailchimp', 'newspack-plugin' ) }
	description={ __( 'Sync reader activity with your Mailchimp audience.', 'newspack-plugin' ) }
	icon={ { node: <MailchimpMark />, backgroundColor: '#ffe01b', radius: 'full' } }
	enabled={ isEnabled }
	onEnable={ handleEnable }
	onConfigure={ handleConfigure }
	moreControls={ [ { title: __( 'Disable', 'newspack-plugin' ), onClick: handleDisable } ] }
/>

// A ready element, rendered as-is
<CardFeature
	title={ __( 'Mailchimp', 'newspack-plugin' ) }
	description={ __( 'Sync reader activity with your Mailchimp audience.', 'newspack-plugin' ) }
	icon={ <IntegrationIcon provider="mailchimp" /> }
	enabled={ isEnabled }
	onEnable={ handleEnable }
	onConfigure={ handleConfigure }
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
| `titleLevel` | `2`–`6` | `2` | Heading level for the title. Use `3` under a `SectionHeader`, which is itself an h2; use `2` when the cards sit directly under a page's h1 |
| `description` | `string` | — | Supporting text below the title |
| `icon` | `CardFeatureIcon \| ReactElement` | — | Icon displayed on the right. A descriptor gets the 40 × 40 container; a ready element renders as-is. See `CardFeatureIcon` below. |
| `enabled` | `boolean` | `false` | Whether the feature is currently enabled |
| `requirements` | `string` | — | When set, enters the unmet-requirements state. The value is the error badge text and the primary button's accessible description, so write it to read sensibly after the button's label |
| `requirementsActionable` | `boolean` | `false` | When `requirements` is set, keep the primary button clickable so it can remediate the unmet requirement, and keep the "More" dropdown visible on an enabled card (degraded but still operable) |
| `enableLabel` | `string` | `"Enable"` | Label for the primary button in its "Enable" states: not enabled, or enabled with an unmet requirement |
| `configureLabel` | `string` | `"Configure"` | Label for the primary button in its "Configure" state: enabled, with no unmet requirement |
| `onEnable` | `() => void` | — | Called when the primary button is clicked while it reads "Enable". That covers the not-enabled case and the enabled-with-unmet-requirements case, where the feature is on but the requirement is what the button acts on |
| `onConfigure` | `() => void` | — | Called when the primary button is clicked while it reads "Configure", which is the enabled state with no unmet requirements |
| `moreControls` | `MoreControl[]` | — | Items for the "More" dropdown. Shown when `enabled` and either there are no `requirements` or `requirementsActionable` is set |
| `badgeText` | `string` | `"Enabled"` | Badge text shown when enabled. Ignored while `requirements` is set, which takes the badge |
| `badgeLevel` | `BadgeLevel` | `"success"` | Badge level shown when enabled. Ignored while `requirements` is set, which forces an error badge |
| `busy` | `boolean` | `false` | Shows the primary button as busy and blocks it while an action is in flight |
| `className` | `string` | — | Additional class name applied to the card element |

### `CardFeatureIcon`

```ts
type CardFeatureIcon = {
	node: React.ReactNode;       // The icon element to render
	fill?: string;               // SVG fill colour (applied via currentColor)
	backgroundColor?: string;    // Background colour of the 40×40 container
	radius?: 'small' | 'full';   // 'small' = 2px ($radius-small), 'full' = 50% ($radius-round)
	                             // Defaults to 'small' whenever backgroundColor is set,
	                             // and has nothing to round without one.
};
```

### `MoreControl`

```ts
type MoreControl = {
	title: string;
	onClick: () => void;
	icon?: JSX.Element;
};
```
