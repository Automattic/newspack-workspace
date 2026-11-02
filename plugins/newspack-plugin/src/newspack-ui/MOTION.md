# Newspack UI Motion

newspack-ui follows the shared Newspack motion rules in [`packages/motion/MOTION.md`](../../../../packages/motion/MOTION.md). Read those first. This file keeps only what belongs to newspack-ui's own components.

## Tokens

The `--newspack-ui-ease-*` and `--newspack-ui-duration-*` custom properties in `scss/variables/_index.scss` are generated from the `newspack-motion` package with `@include motion.custom-properties(newspack-ui)`, so they always match it. Use them inside newspack-ui. CSS that ships outside newspack-ui, where they may not be loaded, uses the package's compiled values instead.

Two tokens belong to newspack-ui alone:

| Token | Value | Use it for |
|-------|-------|------------|
| `--newspack-ui-ease-snackbar` | `ease` | Snackbars entering, leaving and stacking |
| `--newspack-ui-duration-snackbar` | 400ms | Snackbars |

## Modal steps

- On screens 600px and wider, a step that leaves fades over 120ms (the package's `$duration-exit`), and the next step fades in over `--newspack-ui-duration-reveal` after `--newspack-ui-modal-step-delay` (100ms). On phones the delay is 0ms and the leaving step is removed at once. Both are set in `scss/_modals.scss`.
- A step sets `--newspack-ui-follow-on-delay` to its own delay plus half of `--newspack-ui-duration-reveal`: 200ms on wider screens, 100ms on phones. The success icon reads it (`scss/elements/_icons.scss`). A new surface with follow-on content should set the same variable. A success icon on a screen that appears without a transition uses `var( --newspack-ui-follow-on-delay, 0ms )`, so it starts immediately.

## Scripts

- `getTransitionTime` in `js/utils` reads an element's computed transition durations and delays, so scripts wait on the transition rather than a timer.
- The bottom sheet's drag (`js/sheet-drag.js`): under reduced motion the sheet stops following the finger, but a long or quick enough swipe still closes it.
