# InfoButton

An icon button that reveals supplementary context on hover, tap or activation.
It renders nothing but the button and its popup; most often it is placed next to
a label, explaining the setting beside it.

```jsx
import { InfoButton } from 'newspack-components';

<InfoButton description={ __( 'Number of articles read in the last 30 day period.', 'newspack-plugin' ) } />
```

The component imports its own stylesheet, so the barrel ships the CSS with it.
There is nothing separate to import.

## What belongs in it

**Supplementary context only.** Anything a reader needs in order to use the
control belongs in visible help text beside it, not behind an affordance they
have to find first. The design system makes the same point: content that matters
to understanding an element should not be hidden.

## Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `description` | `string` | — | **Required.** The context to reveal. Renders nothing when empty. |
| `className` | `string` | — | Additional CSS class on the trigger. |
| `triggerLabel` | `string` | `'More information'` | Accessible name for the trigger. |

Unrecognised props pass through to the trigger, so `id`, `data-*` and event
handlers all land on the button.

## Name each one for its setting

**Pass `triggerLabel` whenever a screen holds more than one.** The default name
is the same for every instance, so a screen with a dozen of them gives a screen
reader user a dozen identical entries in its controls list with nothing to tell
them apart.

```jsx
<InfoButton
	description={ criteria.description }
	triggerLabel={ sprintf(
		// translators: %s is the name of the setting being explained.
		__( 'More information about %s', 'newspack-plugin' ),
		criteria.name
	) }
/>
```

## Built on Popover, not Tooltip

It looks like a tooltip and is not one. `@wordpress/ui` documents its `Tooltip`
as visual-only, not exposed to assistive technology, and unavailable on touch
devices, so it "should not be used for infotips, descriptions, or dynamic status
messages". Both components gate hover on a mouse-like pointer, but only a
popover trigger also opens on press, which is what makes it reachable by tap.

The consequences, all of which a tooltip would lose:

- Hover opens it on a desktop after 200ms, and a 200ms close delay means
  overshooting the 24px trigger does not dismiss it instantly.
- A tap opens it on a touch device.
- Escape closes it and returns focus to the trigger.
- The context is linked with `aria-describedby` rather than becoming the
  button's accessible name, so the trigger keeps a short name of its own.

`Popover.Popup` is rendered with `variant="unstyled"`, which skips the design
system's own light card surface so the stylesheet can reproduce a tooltip's
appearance instead. That is also why the popup carries no enter animation: the
motion layer ships with the default surface.

## Accessibility

The trigger is a native `<button>` with no action of its own. Activating it
opens the popup; nothing else happens.

The popup is a `role="dialog"` named by a visually hidden `Popover.Title`. The
title repeats the trigger's name, which is what the design system's own infotip
reference does, and the description carries the prose.

The description keeps the design system's body type rather than a tooltip's
smaller size, because `Popover.Description` renders a `Text` and does not expose
its variant. At the 320px width cap, a long description wraps to about four
lines.
