# Buttons

Adding `has-small-size` or `has-x-small-size` to a Buttons block will reduce its padding and border-radius.

Padding can be controlled in the editor but only with the preset variable. To match our Newspack UI buttons, we need better control.

Note: The font size still needs to be changed to x-small if we're recreating a Newspack UI button-like appearance.

| CLASS NAME       | DESCRIPTION                                                                                                          |
| ---------------- | -------------------------------------------------------------------------------------------------------------------- |
| has-small-size   | The buttons will have a top and bottom padding of 8px and a left and right padding of 16px.                          |
| has-x-small-size | The buttons will have a top and bottom padding of 6px, a left and right padding of 12px, and a border-radius of 4px. |

# Background

Adding `has-background-transparent` to a button renders it with no background at rest and applies a subtle hover tint: the current text colour mixed at 3.5% opacity, which over a white surface approximates #f7f7f7.

The tint only applies while the background is transparent. If a background colour is applied (e.g. `has-accent-background-color`), the class has no effect and the applied colour takes over.

| CLASS NAME                 | DESCRIPTION                                                                                                       |
| -------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| has-background-transparent | Transparent background with a subtle hover tint derived from the current text colour. Inactive when a background colour is set. |

# Position

## Fixed

Adding `is-position-fixed` to a block will make it fixed at the top of the screen. (Note: the location on the screen can be customized via the [CSS property `inset`](https://developer.mozilla.org/en-US/docs/Web/CSS/position).)

Additionally, `is-position-fixed--mobile-only` and `is-position-fixed--desktop-only` can be applied to blocks to fix their position based on the screen size.

| CLASS NAME                      | DESCRIPTION                                                                 |
| ------------------------------- | --------------------------------------------------------------------------- |
| is-position-fixed               | Class required to enable the fixed position.                                |
| is-position-fixed--mobile-only  | The block will be fixed on the screen if it is 781px wide or less.          |
| is-position-fixed--desktop-only | The block will be fixed on the screen if the screen is at least 782px wide. |

## Sticky

Adding `is-position-sticky` to a block will ensure it stays within the viewport and sticks to the top of the page when the content is scrolled. (Note: This can also be added in the Editor via the "Position" panel.)

Additionally, `is-position-sticky--mobile-only` and `is-position-sticky--desktop-only` can be applied to blocks to make them sticky based on the screen size.

| CLASS NAME                       | DESCRIPTION                                                                  |
| -------------------------------- | ---------------------------------------------------------------------------- |
| is-position-sticky               | Class required to enable the sticky position.                                |
| is-position-sticky--mobile-only  | The block will be sticky on the screen if it is 781px wide or less.          |
| is-position-sticky--desktop-only | The block will be sticky on the screen if the screen is at least 782px wide. |
