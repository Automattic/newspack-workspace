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

It carries no inline margin, so the row it sits in owns the spacing beside it.
In a flex or grid row that is the row's own `gap`; anywhere else the caller sets
it.

Vertically it is a 24px control, `$button-size-small`, and it makes no assumption
about the line it lands on. Beside text on a shorter line it grows the row unless
the row pulls it back, which is the host's call rather than the component's: a row
that is happy at 24px needs nothing. `StatCard.Label` and `SettingsSection` both
trim it to the 20px line their text sits on, so a label carrying one stays level
with a label without.

## What belongs in it

**Supplementary context only.** Anything a reader needs in order to use the
control belongs in visible help text beside it, not behind an affordance they
have to find first. The design system stops short of this: it only rules out
hiding an important description behind a *tooltip*, and offers a popover as the
alternative when space is tight. The stricter line is ours, not the library's.

## Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `description` | `string` | — | The context to reveal. The whole component renders nothing without it. |
| `className` | `string` | — | Additional CSS class on the trigger. |
| `triggerLabel` | `string` | `'More information'` | Accessible name for the trigger. |

Unrecognised props pass through to the trigger, so `id`, `data-*` and event
handlers all land on the button, and a `ref` reaches the button itself. An
`aria-label` names the popup as well as the trigger, so the two never disagree.

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

**Pass it in the calling plugin's own text domain.** The samples here say
`newspack-plugin` because that catalogue picks the package's strings up through
the symlink at `plugins/newspack-plugin/packages/components`. Everywhere else
the package resolves through `node_modules`, which `make-pot` skips, so a
`newspack-plugin`-domained string passed in from another plugin ships
untranslated.

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
- The popup carries the context on its own `aria-describedby` rather than
  folding it into the button's accessible name, so the trigger keeps a short
  name of its own.

The library marks `Popover` "use with caution" next to `@wordpress/components`,
pending a review of overlay compatibility. The portal answers that by opting into
the shared overlay slot, a body-level container the library reserves above the
older z-index map, so the popup clears a core popover instead of tying with it.
Outside a WordPress screen the slot is absent and the stylesheet's own z-index
applies.

`Popover.Popup` is rendered with `variant="unstyled"`, which skips the design
system's own light card surface so the stylesheet can reproduce a tooltip's
appearance instead. That is also why the popup carries no enter animation: the
motion layer ships with the default surface.

## Accessibility

The trigger is a native `<button>` with no action of its own. Activating it
opens the popup and moves focus into it, so the description is the next thing a
keyboard user reaches.

The popup is a `role="dialog"` named by a visually hidden `Popover.Title`. The
title repeats the trigger's name, which is what the design system's own infotip
reference does, and the description carries the prose.

The description keeps the design system's body type rather than a tooltip's
smaller size, because `Popover.Description` renders a `Text` and does not expose
its variant. At the 320px width cap, a long description wraps to about four
lines.
