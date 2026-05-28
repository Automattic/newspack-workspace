# CardForm

A card component for presenting a named setting or feature with an expandable inline form. When open, the card body reveals children (controls, fields, action buttons) and the header border is removed for a seamless look. Intended for lists of items that can each be independently enabled, edited, or configured without leaving the page.

## Layout rules

- Stack multiple `CardForm` cards inside a `<VStack>` — they are designed to appear as a list.
- The `actions` slot sits to the right of the badge. Keep it to one button; if you need multiple actions, use an `HStack` with `expanded={ false }`.
- The form body (`children`) is only mounted when `isOpen` is `true`.

## States

| State | `isOpen` | `badge` | `actions` example |
|---|---|---|---|
| **Disabled** | `false` | None | "Enable" (secondary) |
| **Enabling** | `true` | None | "Cancel" (tertiary) |
| **Enabled** | `false` | Success badge | "Edit" (tertiary) |
| **Editing** | `true` | Success badge | "Cancel" (tertiary) |

## Basic usage — enable/edit pattern

```tsx
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { CardForm } from '../../../../../packages/components/src';

const [ isOpen, setIsOpen ] = useState( false );
const [ isEnabled, setIsEnabled ] = useState( false );

const handleClose = () => setIsOpen( false );

<CardForm
	title={ __( 'Above Header', 'newspack-plugin' ) }
	description={ __( 'Displays an ad above the site header.', 'newspack-plugin' ) }
	badge={ isEnabled ? { level: 'success', text: __( 'Enabled', 'newspack-plugin' ) } : undefined }
	actions={
		isEnabled ? (
			<Button variant="tertiary" size="compact" onClick={ () => isOpen ? handleClose() : setIsOpen( true ) }>
				{ isOpen ? __( 'Cancel', 'newspack-plugin' ) : __( 'Edit', 'newspack-plugin' ) }
			</Button>
		) : (
			<Button variant="secondary" size="compact" onClick={ () => setIsOpen( true ) }>
				{ __( 'Enable', 'newspack-plugin' ) }
			</Button>
		)
	}
	isOpen={ isOpen }
	onRequestClose={ handleClose }
>
	{ /* form controls */ }
	<Button variant="primary" size="compact" onClick={ handleSave }>
		{ __( 'Update', 'newspack-plugin' ) }
	</Button>
</CardForm>
```

## With a custom badge level

The `badge` prop accepts any `BadgeLevel`. Use `warning` or `error` to communicate a degraded state.

```tsx
<CardForm
	title={ __( 'Above Header', 'newspack-plugin' ) }
	badge={ { level: 'warning', text: __( 'Missing ad unit', 'newspack-plugin' ) } }
	actions={ <Button variant="tertiary" size="compact">{ __( 'Edit', 'newspack-plugin' ) }</Button> }
	isOpen={ false }
/>
```

## Without a badge

Omit `badge` (or pass `undefined`) to show no badge at all.

```tsx
<CardForm
	title={ __( 'Sticky Footer', 'newspack-plugin' ) }
	description={ __( 'Pins an ad to the bottom of the viewport.', 'newspack-plugin' ) }
	actions={
		<Button variant="secondary" size="compact" onClick={ handleEnable }>
			{ __( 'Enable', 'newspack-plugin' ) }
		</Button>
	}
	isOpen={ false }
/>
```

## Props

| Prop | Type | Default | Description |
|---|---|---|---|
| `title` | `string` | — | Card heading (**required**) |
| `description` | `string` | — | Supporting text below the title |
| `badge` | `{ text: string; level?: BadgeLevel }` | — | Badge shown next to the actions slot. Omit or pass `undefined` to hide. |
| `actions` | `React.ReactNode` | — | JSX rendered in the header action area (buttons, dropdowns, etc.) |
| `isOpen` | `boolean` | `false` | When `true`, renders `children` in the card body and removes the header border |
| `onRequestClose` | `() => void` | — | Called when the user presses Escape while focus is inside the open form |
| `titleLevel` | `1 \| 2 \| 3 \| 4 \| 5 \| 6` | `3` | Heading level rendered for `title`. Pick the level that fits the surrounding document outline. |
| `className` | `string` | — | Additional class name applied to the card element |
| `children` | `React.ReactNode` | — | Form content rendered inside the card body when `isOpen` is `true` |

## Accessibility

- The body is rendered as a `role="region"` labelled by the title, so assistive tech announces it as a named region when focus enters.
- On open, focus moves to the first focusable element in the body (or to the region itself if none exist). On close, focus is restored to whatever was focused before opening — typically the trigger button.
- The Escape listener is scoped to the open form's body, so multiple open cards do not all close on a single keypress. If an inner control needs to consume Escape (for example, to close its own menu), call `event.preventDefault()` and CardForm will ignore it.


### `BadgeLevel`

```ts
type BadgeLevel = 'default' | 'info' | 'success' | 'warning' | 'error';
```
