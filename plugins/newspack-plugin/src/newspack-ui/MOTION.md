# Newspack UI Motion

How newspack-ui moves, and the rules a new component should follow so it moves the same way. Motion here explains a change of state: where something came from, where it went, and what it belongs to. It is never decoration.

Shared easing and duration values live as tokens in `scss/variables/_index.scss`. Use them rather than literal values, so a timing change in one place reaches every component. A few values belong to one component and live beside it, such as the modal step's 100ms delay and 120ms exit in `scss/_modals.scss`. Outside newspack-ui, where the tokens may not be loaded, give each `var()` a fallback equal to the token's current value, for example `var( --newspack-ui-duration-modal, 250ms )`.

These rules cover surfaces built on newspack-ui. The modal checkout's fallback dialog, used only when newspack-ui is not loaded, doesn't follow them.

---

## Tokens

### Easing

| Token | Curve | Use it for |
|-------|-------|------------|
| `--newspack-ui-ease-out` | `cubic-bezier(0.23, 1, 0.32, 1)` | Anything entering or revealing: modals, dropdowns, steps, reveals, the segmented thumb |
| `--newspack-ui-ease-in-out` | `cubic-bezier(0.77, 0, 0.175, 1)` | Something already on screen changing shape, like the modal resizing between steps |
| `--newspack-ui-ease-drawer` | `cubic-bezier(0.32, 0.72, 0, 1)` | Bottom sheets, including the bottom content gate |
| `--newspack-ui-ease-snackbar` | `ease` | Snackbars entering, leaving and stacking |

Color fades (hover, selection, a focus ring fading out) use plain `ease`.

### Duration

| Token | Value | Use it for |
|-------|-------|------------|
| `--newspack-ui-duration-color` | 150ms | Color, border and shadow changes: hover, selection, and a focus ring fading out |
| `--newspack-ui-duration-press` | 160ms | Press feedback on buttons, and the checkbox tick appearing |
| `--newspack-ui-duration-indicator` | 200ms | An indicator moving to the current choice, like the segmented thumb |
| `--newspack-ui-duration-reveal` | 200ms | Content appearing in place (dropdowns, modal steps, success icons) and content expanding or collapsing in place |
| `--newspack-ui-duration-modal` | 250ms | A centered modal opening or closing and resizing between steps, the centered content gate, the bottom gift and countdown banners, and the fade that replaces a sheet's slide under reduced motion |
| `--newspack-ui-duration-sheet` | 400ms | A bottom sheet sliding in or out, and its overlay |
| `--newspack-ui-duration-snackbar` | 400ms | Snackbars |

Larger surfaces get longer durations. No single transition runs longer than 300ms, except surfaces that travel their own height from an edge, at 400ms: bottom sheets with their overlay, the bottom content gate, and snackbars. Looping indicators, like the spinner and the live badge's pulse, sit outside this. Something small that changes often takes 200ms or less.

---

## Rules

### Entering and leaving

- **Entrances ease out.** They start fast and settle, so the element feels responsive. Snackbars use `--newspack-ui-ease-snackbar` (plain `ease`) for a softer start as they slide up from the edge, and the fades that replace a slide under reduced motion also use plain `ease`.
- **Never ease in.** An ease-in curve holds still at the moment the reader is looking, so the interface feels slow. Name each property in a transition; never use `transition: all`.
- **Start from somewhere close.** A centered modal scales from `0.96`, a dropdown slides 4px into place, and a success icon or checkbox tick scales from `0.9`. Nothing scales from `0`. Content that replaces other content in place, like a modal step or an accordion panel, can fade alone.
- **Grow from the trigger.** A menu or popover that scales or slides moves away from the edge nearest its trigger, and flips direction when it opens on the other side. Only a centered modal scales from its center.
- **Bottom sheets slide their full height** on `--newspack-ui-ease-drawer` and `--newspack-ui-duration-sheet`. A surface that docks to the bottom edge and holds content the reader has to act on, like the bottom content gate, moves the same way. Snackbars slide up on their own tokens, and the slim bottom banners for gifted articles and countdowns only fade.
- **When one view replaces another, the outgoing view leaves faster.** On screens 600px and wider, a modal step that leaves fades over 120ms. The next step fades in over `--newspack-ui-duration-reveal` after `--newspack-ui-modal-step-delay` (100ms), so the two barely overlap. On phones the leaving step is removed at once. A surface that opens and closes, like a modal, sheet, dropdown or snackbar, uses the same duration both ways.

### Sequence

- **A follow-on element starts halfway through the entrance of what it belongs to.** Overlapping entrances read as one movement; strict sequencing reads as a pause. A modal step sets `--newspack-ui-follow-on-delay` to its own delay plus half of `--newspack-ui-duration-reveal` (200ms on wider screens, 100ms on phones), and the success icon reads it. A new surface with follow-on content should set the same variable.
- **With no entrance to follow, start at once.** A success icon on a screen that appears without a transition uses `var( --newspack-ui-follow-on-delay, 0ms )`, so it starts immediately.
- **Close the gap only after an exit finishes.** When something leaves and the content around it has to close up, as when a snackbar outside the stack is dismissed, wait for the fade to end before collapsing the space, so nothing moves under an element the reader can still see. Inside the stack, the remaining snackbars move up while the leaving one slides away, so the group moves as one.

### Interaction

- **Hover effects only apply to devices that can hover.** Wrap them in `@media ( hover: hover ) and ( pointer: fine )`, so a tap on a phone doesn't leave a sticky hover state. A rule that only keeps an element at its resting style on hover, to cancel a theme's hover style, is not an effect and stays outside the query. When an effect also has to beat a theme's hover style, restate the resting values on `:hover` outside the query and put only the change inside it.
- **Focus rings appear at once.** Keyboard users need to see where focus is the moment it moves, so the focus mixins set `transition: none`. That removes every transition on the element while it has focus, not just the ring's, so restate any that must keep running. The ring fades out when focus leaves only if the element's resting state transitions the properties the ring uses: `box-shadow`, plus `border-color` for `focus-edge`. List them there.
- **Fills still fade under focus.** If a control's fill changes while it has focus, like a checkbox toggled with Space, restate the transition for the property that draws the fill after the focus mixin, as the checkbox does with `background-color`. A radio draws its dot with the same `box-shadow` as its ring, so its dot changes at once under keyboard focus.
- **Press feedback scales a button to `0.97`** over `--newspack-ui-duration-press`. Buttons that pick one of a set, like tabs, segmented controls and dropdown items, set `transform: none` and don't scale.

### Interruption and placement

- **Prefer transitions to keyframes** for anything a reader can reverse halfway, like the segmented thumb, the content gate or a dropdown. A transition picks up from where the element is; a keyframe animation restarts.
- **Wait on the transition, not a timer.** To act after a transition ends, read the element's computed duration and delay (`getTransitionTime` in `js/utils`) instead of hard-coding a timeout, so the script follows the tokens and any theme override.
- **Don't animate placement or a reset.** A first placement, a re-measure after a resize, or a return to the first step jumps straight to its end state. Turn transitions off for that frame, then turn them back on.
- **Move and resize with transform, opacity and clip-path.** They don't move surrounding content. Resize a surface by clipping it, as the modal does between steps. Animate height or margin only when the content around it has to move too, as when a snackbar collapses, and width or height only on a layer nothing else flows around, like the segmented thumb.
- **Animate from measured sizes, not `auto`.** Not every browser can animate to or from `height: auto`. Measure the element and animate between pixel values, as the snackbars do. Where a jump in browsers without support is acceptable, scope `interpolate-size: allow-keywords` to the one element that needs it. Never set it on `:root`, because it changes height transitions for the whole page, including the theme and other plugins.

### Reduced motion

- **Keep fades, drop movement.** Under `prefers-reduced-motion: reduce`, remove transforms, slides, scaling and size changes, and keep opacity changes so a state change is still visible.
- **Replace a slide with a fade.** A surface that appears only by moving, like a bottom sheet or the bottom content gate, fades in over `--newspack-ui-duration-modal` on plain `ease` instead.
- **Stop decorative loops at their resting state.** The live badge's pulse becomes a static ring. A loading spinner keeps turning, because its motion is the message.
- **Gestures stop following the finger.** A bottom sheet doesn't track a drag, but a long enough or quick enough swipe still closes it.
- **Scroll without smoothing.** A script asks for `behavior: 'smooth'` only when reduced motion is off.
- **Themes may go further.** The classic Newspack theme sets every transition and animation duration to near zero under reduced motion, which also removes fades and stops looping animations, the spinner included. Transition delays still apply under that rule, so keep them short.

### Forced colors

- **Hide decorative motion surfaces.** In forced-colors mode, hide anything that only exists to animate, like the segmented thumb, and keep the existing outline as the selected state.
