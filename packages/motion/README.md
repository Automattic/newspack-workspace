# Newspack Motion

Shared motion tokens for Newspack projects: easing curves, durations, scales, and the media queries for hover and reduced motion. [MOTION.md](MOTION.md) holds the rules for using them.

The package covers the front end, meaning reader-facing surfaces. Admin screens follow the motion built into `@wordpress/ui` and `@wordpress/components` and don't use it.

## Installation

```bash
npm install newspack-motion
```

Inside the newspack-workspace monorepo, add `"newspack-motion": "workspace:*"` to the consumer's `package.json` instead.

## SCSS

```scss
@use "newspack-motion" as motion;

.button {
	transition: transform motion.$duration-press motion.$ease-out;

	&:active {
		transform: scale(motion.$press-scale);
	}

	@include motion.reduced-motion {
		&:active {
			transform: none;
		}
	}
}
```

The values compile into your stylesheet, so nothing depends on another stylesheet being loaded.

| Variable | Value |
| --- | --- |
| `$ease-out` | `cubic-bezier(0.23, 1, 0.32, 1)` |
| `$ease-in-out` | `cubic-bezier(0.77, 0, 0.175, 1)` |
| `$ease-drawer` | `cubic-bezier(0.32, 0.72, 0, 1)` |
| `$ease-color` | `ease` |
| `$duration-color` | `150ms` |
| `$duration-press` | `160ms` |
| `$duration-indicator` | `200ms` |
| `$duration-reveal` | `200ms` |
| `$duration-modal` | `250ms` |
| `$duration-sheet` | `400ms` |
| `$duration-exit` | `120ms` |
| `$press-scale` | `0.97` |
| `$enter-scale` | `0.96` |
| `$enter-offset` | `4px` |
| `$reveal-scale` | `0.9` |
| `$travel-base-distance` | `240px` |
| `$travel-px-per-ms` | `4` |
| `$duration-travel-max` | `450ms` |

Functions and mixins:

- `motion.travel-duration($distance, $base: motion.$duration-modal)` returns how long a surface takes to travel `$distance` (a `px` length): `$base` up to 240px, then 1ms more for every 4px, up to 450ms. For example, a 410px drawer gets `motion.travel-duration(410px)`, 293ms.

- `@include motion.hover { … }` wraps its content in `@media (hover: hover) and (pointer: fine)`.
- `@include motion.reduced-motion { … }` wraps its content in `@media (prefers-reduced-motion: reduce)`.
- `@include motion.custom-properties(<prefix>)` writes `--<prefix>-ease-out`, `--<prefix>-ease-in-out`, `--<prefix>-ease-drawer`, `--<prefix>-ease-color` and `--<prefix>-duration-color` through `--<prefix>-duration-exit` on the current selector. newspack-ui uses it for its `--newspack-ui-*` properties.

## JavaScript

```js
import { DURATION_MODAL, EASE_COLOR, EASE_OUT, ENTER_SCALE, prefersReducedMotion } from 'newspack-motion';

const reduce = prefersReducedMotion();

element.animate(
	[
		{ opacity: 0, transform: reduce ? 'none' : `scale(${ ENTER_SCALE })` },
		{ opacity: 1, transform: 'none' },
	],
	{
		duration: DURATION_MODAL,
		easing: reduce ? EASE_COLOR : EASE_OUT,
	}
);
```

Exports: `EASE_OUT`, `EASE_IN_OUT`, `EASE_DRAWER`, `EASE_COLOR` (strings), `DURATION_COLOR`, `DURATION_PRESS`, `DURATION_INDICATOR`, `DURATION_REVEAL`, `DURATION_MODAL`, `DURATION_SHEET`, `DURATION_EXIT`, `DURATION_TRAVEL_MAX` (numbers, in milliseconds), `PRESS_SCALE`, `ENTER_SCALE`, `REVEAL_SCALE` (unitless scales), `ENTER_OFFSET` (pixels), `TRAVEL_BASE_DISTANCE` (pixels), `TRAVEL_PX_PER_MS`, `REDUCED_MOTION_QUERY`, `prefersReducedMotion()` and `travelDuration( distance, base = DURATION_MODAL )`, which applies the same rule as `travel-duration()` to a measured distance in pixels. TypeScript types are included.

## License

GPL-2.0-or-later
