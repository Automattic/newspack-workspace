# Newspack Motion

How Newspack moves, and the rules a new component should follow so it moves the same way. Motion here explains a change of state: where something came from, where it went, and what it belongs to. It is never decoration.

These rules and this package cover the front end: the reader-facing surfaces such as the theme, blocks, prompts and reader UI. Admin screens follow the motion built into `@wordpress/ui` and `@wordpress/components` and don't use this package.

The shared easing and duration values live in this package, `newspack-motion`. Use them rather than literal values, so a timing change in one place reaches every component. Outside newspack-ui, `@use "newspack-motion" as motion;` and use the compiled values, such as `motion.$duration-modal`, and in script import the same values from `newspack-motion`. They compile into your own stylesheet or bundle, so nothing depends on newspack-ui being loaded and no `var()` fallback is needed. Existing `var(--newspack-ui-*, <fallback>)` uses stay valid; they let a theme override the value, and move to the compiled values when next touched. Inside newspack-ui, use its `--newspack-ui-*` custom properties, which are generated from this package. A few values belong to one component and live beside it, such as newspack-ui's modal step delay; see `plugins/newspack-plugin/src/newspack-ui/MOTION.md`.

These rules cover newspack-ui and every surface that uses this package. The modal checkout's fallback dialog, used only when newspack-ui is not loaded, doesn't follow them.

---

## Tokens

### Easing

| SCSS | Script | newspack-ui | Curve | Use it for |
|------|--------|-------------|-------|------------|
| `$ease-out` | `EASE_OUT` | `--newspack-ui-ease-out` | `cubic-bezier(0.23, 1, 0.32, 1)` | Anything entering or revealing: modals, dropdowns, steps, reveals, the segmented thumb |
| `$ease-in-out` | `EASE_IN_OUT` | `--newspack-ui-ease-in-out` | `cubic-bezier(0.77, 0, 0.175, 1)` | Something already on screen changing shape, like the modal resizing between steps |
| `$ease-drawer` | `EASE_DRAWER` | `--newspack-ui-ease-drawer` | `cubic-bezier(0.32, 0.72, 0, 1)` | Sheets and drawers sliding in from an edge: bottom sheets, the bottom content gate, side drawers |
| `$ease-color` | `EASE_COLOR` | `--newspack-ui-ease-color` | `ease` | Color fades: hover, selection, a focus ring fading out, and the fade that replaces a slide under reduced motion |

### Duration

| SCSS | Script (ms) | newspack-ui | Value | Use it for |
|------|-------------|-------------|-------|------------|
| `$duration-color` | `DURATION_COLOR` | `--newspack-ui-duration-color` | 150ms | Color, border and shadow changes: hover, selection, and a focus ring fading out |
| `$duration-press` | `DURATION_PRESS` | `--newspack-ui-duration-press` | 160ms | Press feedback on buttons, and the checkbox tick appearing |
| `$duration-indicator` | `DURATION_INDICATOR` | `--newspack-ui-duration-indicator` | 200ms | An indicator moving to the current choice, like the segmented thumb |
| `$duration-reveal` | `DURATION_REVEAL` | `--newspack-ui-duration-reveal` | 200ms | Content appearing in place (dropdowns, modal steps, success icons) and content expanding or collapsing in place |
| `$duration-modal` | `DURATION_MODAL` | `--newspack-ui-duration-modal` | 250ms | A centered modal opening or closing and resizing between steps, the centered content gate, the bottom gift and countdown banners, and the fade that replaces a sheet's slide under reduced motion |
| `$duration-sheet` | `DURATION_SHEET` | `--newspack-ui-duration-sheet` | 400ms | A bottom sheet sliding in or out, and its overlay |
| `$duration-exit` | `DURATION_EXIT` | `--newspack-ui-duration-exit` | 120ms | A view leaving while another replaces it in the same place, like a modal step on wider screens |

Larger surfaces get longer durations. No single transition runs longer than 300ms, except travel, which follows the travel rule (below) up to 450ms. Bottom sheets with their overlay and the bottom content gate slide their own height from an edge in `$duration-sheet` (400ms) rather than following the travel rule. Snackbars slide up in the same 400ms on newspack-ui's own snackbar tokens. Carousels keep a fixed 300ms slide rather than following the travel rule, because Swiper's speed also sets how fast a swipe settles, and a longer slide feels sluggish under a finger. Looping indicators, like the spinner and the live badge's pulse, sit outside this. Something small that changes often takes 200ms or less.

### Travel

| SCSS | Script | Value | Use it for |
|------|--------|-------|------------|
| `$travel-base-distance` | `TRAVEL_BASE_DISTANCE` | 240px | Travel up to this distance keeps its base duration |
| `$travel-px-per-ms` | `TRAVEL_PX_PER_MS` | 4 | Past the base distance, 1ms more for every this many pixels |
| `$duration-travel-max` | `DURATION_TRAVEL_MAX` | 450ms | The longest any travel takes |
| `travel-duration($distance, $base: $duration-modal)` | `travelDuration( distance, base = DURATION_MODAL )` | | The duration for a distance in pixels. At the 250ms base: 300px takes 265ms, 410px 293ms, 632px 348ms, 964px 431ms, and 1040px or more 450ms. The cap also applies to the base, so a base above `$duration-travel-max` is shortened to it |

### Scale and distance

| SCSS | Script | Value | Use it for |
|------|--------|-------|------------|
| `$press-scale` | `PRESS_SCALE` | `0.97` | A pressed button |
| `$enter-scale` | `ENTER_SCALE` | `0.96` | Where a centered modal scales from |
| `$enter-offset` | `ENTER_OFFSET` | `4px` (`4` in script) | How far a dropdown slides into place |
| `$reveal-scale` | `REVEAL_SCALE` | `0.9` | Where a success icon or checkbox tick scales from |

### Media queries and custom properties

| SCSS | Script | Output |
|------|--------|--------|
| `@include motion.hover { … }` | | `@media (hover: hover) and (pointer: fine)` |
| `@include motion.reduced-motion { … }` | `REDUCED_MOTION_QUERY`, `prefersReducedMotion()` | `@media (prefers-reduced-motion: reduce)` |
| `@include motion.custom-properties(<prefix>)` | | Every easing and duration above as `--<prefix>-ease-out` through `--<prefix>-duration-exit`, on the current selector. newspack-ui calls it with `newspack-ui`. |

---

## Rules

### Entering and leaving

- **Entrances ease out.** They start fast and settle, so the element feels responsive. Snackbars use plain `ease` for a softer start as they slide up from the edge, and the fades that replace a slide under reduced motion use `$ease-color` (plain `ease`).
- **Never ease in.** An ease-in curve holds still at the moment the reader is looking, so the interface feels slow. Name each property in a transition; never use `transition: all`.
- **Start from somewhere close.** A centered modal scales from `$enter-scale` (0.96), a dropdown slides `$enter-offset` (4px) into place, and a success icon or checkbox tick scales from `$reveal-scale` (0.9). Nothing scales from `0`. Content that replaces other content in place, like a modal step or an accordion panel, can fade alone.
- **Grow from the trigger.** A menu or popover that scales or slides moves away from the edge nearest its trigger, and flips direction when it opens on the other side. Only a centered modal scales from its center.
- **The further a surface travels, the longer it takes.** A surface that travels uses `$ease-drawer`; a space opening in place uses `$ease-out`. A surface that moves across a distance, like a side drawer sliding its width or a space opening by its height, keeps its usual duration up to `$travel-base-distance` (240px), then takes 1ms more for every `$travel-px-per-ms` (4) pixels past it, up to `$duration-travel-max` (450ms). Otherwise a wide drawer covers more ground in the same time as a narrow one and feels faster. Use `motion.travel-duration($distance)` in SCSS or `travelDuration( distance )` in script; both start from `$duration-modal` (250ms), and take a different base as their second argument. Where the distance depends on the viewport, compute one duration per width class at build time, from the width that class is designed for, and measure in script only when it is cheap. Rolling Coverage opens the space for a new entry this way.
- **Bottom sheets slide their full height** on `$ease-drawer` and `$duration-sheet`. A surface that docks to the bottom edge and holds content the reader has to act on, like the bottom content gate, moves the same way. Snackbars slide up on their own tokens, and the slim bottom banners for gifted articles and countdowns only fade. Bottom sheets stay at 400ms rather than following the travel rule.
- **When one view replaces another, the outgoing view leaves faster.** On screens 600px and wider, a modal step that leaves fades over `$duration-exit` (120ms). The next step fades in over `$duration-reveal` after a 100ms delay, so the two barely overlap. On phones the leaving step is removed at once. A surface that opens and closes, like a modal, sheet, dropdown or snackbar, uses the same duration both ways.
- **A surface that takes over from another in the same place doesn't make an entrance.** The dim and the surface's position stay as they are, the outgoing surface goes at once, and only the incoming content fades in, over `$duration-reveal` on `$ease-out`. The height jumps to fit the new content. The sign-in modal hands over to email verification this way after a reader registers, and verification hands back the same way when the reader sends the code.
- **Backdrops only fade.** A backdrop or scrim behind a modal, sheet, drawer or overlay fades on the same timing as its surface; it never slides or scales with it.

### Sequence

- **A follow-on element starts halfway through the entrance of what it belongs to.** Overlapping entrances read as one movement; strict sequencing reads as a pause. Its delay is the delay of what it follows plus half of `$duration-reveal`. In newspack-ui, a modal step publishes that delay for its success icon.
- **With no entrance to follow, start at once.** A follow-on element on a screen that appears without a transition has no delay.
- **Close the gap only after an exit finishes.** When something leaves and the content around it has to close up, as when a snackbar outside the stack is dismissed, wait for the fade to end before collapsing the space, so nothing moves under an element the reader can still see. Inside the stack, the remaining snackbars move up while the leaving one slides away, so the group moves as one.

### Interaction

- **Hover effects only apply to devices that can hover.** Wrap them in `@include motion.hover`, which is `@media ( hover: hover ) and ( pointer: fine )`, so a tap on a phone doesn't leave a sticky hover state. A rule that only keeps an element at its resting style on hover, to cancel a theme's hover style, is not an effect and stays outside the query. When an effect also has to beat a theme's hover style, restate the resting values on `:hover` outside the query and put only the change inside it.
- **Focus rings appear at once.** Keyboard users need to see where focus is the moment it moves, so newspack-ui's focus mixins set `transition: none`. That removes every transition on the element while it has focus, not just the ring's, so restate any that must keep running. The ring fades out when focus leaves only if the element's resting state transitions the properties the ring uses: `box-shadow`, plus `border-color` for `focus-edge`. List them there. If the ring is rounded with `$radius` but the element is square at rest, set that radius at rest too, or, where rounding the element would change how it looks (like a tab's underline), add `border-radius` to the resting transition. Either way the ring's corners no longer snap square when focus leaves: a radius set at rest keeps them rounded, and a transitioned radius squares them off along with the fade.
- **Fills still fade under focus.** If a control's fill changes while it has focus, like a checkbox toggled with Space, restate the transition for the property that draws the fill after the focus mixin, as the checkbox does with `background-color`. A radio draws its dot with the same `box-shadow` as its ring, so its dot changes at once under keyboard focus.
- **Press feedback scales a button to `$press-scale` (0.97)** over `$duration-press`. Buttons that pick one of a set, like tabs, segmented controls and dropdown items, set `transform: none` and don't scale.

### States

Hover, press, focus and disabled change color by one set of amounts, mixed with `color-mix(in oklab, …)`. newspack-ui publishes them as `--newspack-ui-color-mix-hover` and `--newspack-ui-color-mix-pressed` and its `neutral-fill` properties; the block theme publishes the same amounts as `--wp--custom--state--*` from `settings.custom.state` in its `theme.json`. Plugins read newspack-ui's properties with a literal fallback, since they also run under the classic theme.

| State | Amount | How it mixes |
|-------|--------|--------------|
| Hover | 85% | The element's own color at 85%: text and icons toward transparent, a filled button toward black |
| Press | 75% | The same mix at 75% |
| Fill at rest, hover, press | 7%, 13%, 19% | The ink mixed over the surface, for chips, ghost and icon buttons (which start transparent and fill 7% then 13%) |
| Inactive | 65.5% | An inactive indicator, like a carousel bullet, mixed toward transparent (80% where a publisher has chosen the color) |
| Disabled | 45% text on a 7% fill | |

- **Mix in oklab.** Every state mix uses `color-mix(in oklab, …)`, so the same amount darkens a yellow and a blue by the same perceived step. A mix toward transparent only sets alpha, so the space makes no difference there; use oklab anyway so every rule reads the same.
- **A dark fill that lightens takes a bigger step.** In oklab, 85% of a black fill toward white lands at about rgb(11, 11, 11), which still reads as black. A near-black fill (relative luminance under 0.02, which includes the theme's `#1E1E1E` contrast) can't darken visibly, so it lightens toward its icon or text by the same 85% and 75% shares instead, as newspack-ui's dark button does. From pure black even that step lands at about rgb(11, 11, 11), so the lift has a floor: never less than 25% of the icon color in on hover and 40% on press (`--wp--custom--state--lighten-hover` and `--lighten-pressed` in the block theme), which is near rgb(34, 34, 34) and rgb(72, 72, 72). The floor is the one exception to the amounts. It applies to the X and Threads sharing icons, a prompt's close button with a near-black background and the featured-image close button; a colored dark fill, like a `#003DA5` close button, still darkens toward black like a button.
- **A custom fill is mixed on the server.** A button whose fill is a publisher's own color only exists as an inline style, so the block theme mixes its hover and press colors in PHP, in oklab, and sets them on the button as custom properties the stylesheet reads. A near-black fill lightens by the exception above; any other fill darkens by the standard step. Where no server can see the fill, as in a plugin's own markup or a translucent fill, a layer of `color-mix(in oklab, black 15%, transparent)` sits over it on hover and 25% on press. An overlay composites in sRGB, so it approximates the oklab step rather than matching it. Never dim a button with `opacity`: it fades the text too and fails AA.
- **Focus changes nothing but the ring.** A focused element keeps its resting color, fill and underline.
- **Muted links brighten on hover.** A link or text button that rests in the muted gray (`--wp--preset--color--contrast-3` in the block theme, newspack-ui's text-subtle) moves to the text color on hover and keeps its own underline toggle, and only one that rests in the full text color dims to 85%, because mixing a muted gray toward transparent drops it below AA.
- **Indicators mix, they don't fade.** An inactive carousel bullet is its color mixed 65.5% toward transparent, not `opacity: 0.655`, so its focus ring stays at full strength.
- **Disabled text is the ink at 45% over the surface on a 7% fill**, never `opacity: 0.5`. Loading states keep `opacity: 0.5` on the surface as a whole.

### Interruption and placement

- **Prefer transitions to keyframes** for anything a reader can reverse halfway, like the segmented thumb, the content gate or a dropdown. A transition picks up from where the element is; a keyframe animation restarts.
- **Wait on the transition, not a timer.** To act after a transition ends, read the element's computed duration and delay instead of hard-coding a timeout, so the script follows the tokens and any theme override. newspack-ui does this with `getTransitionTime` in `plugins/newspack-plugin/src/newspack-ui/js/utils`.
- **Don't animate placement or a reset.** A first placement, a re-measure after a resize, or a return to the first step jumps straight to its end state. Turn transitions off for that frame, then turn them back on.
- **Move and resize with transform, opacity and clip-path.** They don't move surrounding content. Resize a surface by clipping it, as the modal does between steps. Animate height or margin only when the content around it has to move too, as when a snackbar collapses, and width or height only on a layer nothing else flows around, like the segmented thumb.
- **Animate from measured sizes, not `auto`.** Not every browser can animate to or from `height: auto`. Measure the element and animate between pixel values, as the snackbars do. Where a jump in browsers without support is acceptable, scope `interpolate-size: allow-keywords` to the one element that needs it. Never set it on `:root`, because it changes height transitions for the whole page, including the theme and other plugins.

### Reduced motion

- **Keep fades, drop movement.** Under `prefers-reduced-motion: reduce` (`@include motion.reduced-motion`, or `prefersReducedMotion()` in script), remove transforms, slides, scaling and size changes, and keep opacity changes so a state change is still visible.
- **Replace a slide with a fade.** A surface that appears only by moving, like a bottom sheet or the bottom content gate, fades in over `$duration-modal` on `$ease-color` (plain `ease`) instead.
- **Stop decorative loops at their resting state.** The live badge's pulse becomes a static ring. A loading spinner keeps turning, because its motion is the message.
- **Gestures stop following the finger.** A bottom sheet doesn't track a drag, but a long enough or quick enough swipe still closes it.
- **Scroll without smoothing.** A script asks for `behavior: 'smooth'` only when reduced motion is off.
- **Themes may go further.** The classic Newspack theme sets every transition and animation duration to near zero under reduced motion, which also removes fades and stops looping animations, the spinner included. Transition delays still apply under that rule, so keep them short.

### Forced colors

- **Hide decorative motion surfaces.** In forced-colors mode, hide anything that only exists to animate, like the segmented thumb, and keep the existing outline as the selected state.
