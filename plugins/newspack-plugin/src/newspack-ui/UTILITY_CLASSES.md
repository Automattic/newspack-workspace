# Newspack UI Utility Classes

All utility classes must be used inside a `.newspack-ui` wrapper element.

---

## Stack Layout

Stack is the primary layout primitive. It uses flexbox and automatically resets child margins so spacing is controlled by `gap` alone.

**Base class:** `newspack-ui__stack`
Default direction is `row`, default gap is `spacer-2` (12px).

### Direction

| Class | Effect |
|-------|--------|
| `newspack-ui__stack--horizontal` | `flex-direction: row` |
| `newspack-ui__stack--vertical` | `flex-direction: column` |
| `newspack-ui__stack--vertical-below-mobile` | `flex-direction: column` under 600px; combine with `--horizontal` to switch to a row from 600px |

### Gap

`newspack-ui__stack--gap-{n}` — overrides the default gap. `n` is `0–12` or `half`.

| Step | Value |
|------|-------|
| 0 | 0 |
| half | 4px |
| 1 | 8px |
| 2 | 12px |
| 3 | 16px |
| 4 | 20px |
| 5 | 24px |
| 6 | 32px |
| 7 | 36px |
| 8 | 40px |
| 9 | 48px |
| 10 | 56px |
| 11 | 64px |
| 12 | 72px |

### Alignment

`newspack-ui__stack--align-{value}` — sets `align-items`.

| Class | Value |
|-------|-------|
| `--align-start` | `flex-start` |
| `--align-center` | `center` |
| `--align-end` | `flex-end` |
| `--align-stretch` | `stretch` |
| `--align-baseline` | `baseline` |

### Justify

`newspack-ui__stack--justify-{value}` — sets `justify-content`.

| Class | Value |
|-------|-------|
| `--justify-start` | `flex-start` |
| `--justify-center` | `center` |
| `--justify-end` | `flex-end` |
| `--justify-between` | `space-between` |

### Wrap

| Class | Effect |
|-------|--------|
| `newspack-ui__stack--wrap` | `flex-wrap: wrap` |

### Example

```html
<div class="newspack-ui__stack newspack-ui__stack--vertical newspack-ui__stack--gap-3 newspack-ui__stack--align-center">
  <p>Item one</p>
  <p>Item two</p>
</div>
```

---

## Link

`newspack-ui__link` styles a quiet text link for minor actions (small, medium weight, underline at 50% of the text color). It works on `<a>` and `<button>`, and pads its hit area to at least 24px tall, 44px under a coarse pointer, without changing layout.

| Class | Effect |
|-------|--------|
| `newspack-ui__link` | Text link in the text color |
| `newspack-ui__link--muted` | Muted color, no underline (e.g. secondary navigation such as Sign out). Disabled links, such as a resend countdown, also turn muted, at regular weight |

```html
<a class="newspack-ui__link" href="#">Forgot password?</a>
<button type="button" class="newspack-ui__link">Change</button>
```

---

## Spacing (Margin)

`newspack-ui__spacing-{side}--{n}` — sets margin on one side. `n` is `0–12` (same scale as stack gaps above).

Sides: `top`, `bottom`, `left`, `right`.

```html
<h4 class="newspack-ui__font--m newspack-ui__spacing-top--0">No top margin</h4>
<div class="newspack-ui__spacing-bottom--5">24px bottom margin</div>
```

> **Note:** Spacing classes are only necessary when overriding margins outside of a stack context. Prefer using stack gap for spacing between sibling elements.

---

## Typography

`newspack-ui__font--{size}` — sets font size and matching line height.

| Class | Size | Type role |
|-------|------|-----------|
| `newspack-ui__font--2xs` | 12px / 16px | caption |
| `newspack-ui__font--xs` | 14px / 20px | small |
| `newspack-ui__font--s` | 16px / 24px (default) | body |
| `newspack-ui__font--m` | 20px / 28px | title-m |
| `newspack-ui__font--l` | 24px / 32px, 28px / 36px from 782px | title-l |
| `newspack-ui__font--xl` | 32px / 40px | display |
| `newspack-ui__font--2xl` | 34px to 42px | |
| `newspack-ui__font--3xl` | 36px to 48px | |
| `newspack-ui__font--4xl` | 40px to 64px | |
| `newspack-ui__font--5xl` | 42px to 80px | |
| `newspack-ui__font--6xl` | 44px to 96px | |

`2xl` to `6xl` scale with the viewport width between those sizes. `m`, `l` and `xl` also set letter spacing (`--newspack-ui-letter-spacing-title-m`, `-title-l` and `-display`).

The type roles are also available as tokens: `--newspack-ui-font-size-{role}` and `--newspack-ui-line-height-{role}` (unitless).

Headings without a size class get a role by level: `h1` gets title-l, `h2` title-m, and `h3` to `h6` body. This applies inside any `.newspack-ui` element and, when `.newspack-ui` is on `<body>` (logged-in My Account), only inside the My Account content and modals, so the theme's own headings keep their sizes.

Weight modifiers:

| Class | Effect |
|-------|--------|
| `newspack-ui__font--bold` | `font-weight: var(--newspack-ui-font-weight-strong)` |
| `newspack-ui__font--medium` | `font-weight: var(--newspack-ui-font-weight-medium)` |
| `newspack-ui__font--normal` | `font-weight: var(--newspack-ui-font-weight-regular)` |

---

## Color

`newspack-ui__color--{scale}-{step}` — sets `color`.

| Scale | Steps available |
|-------|----------------|
| `neutral` | 0, 5, 10, 20, 30, 40, 50, 55, 60, 70, 75, 80, 90, 100 |
| `primary` | 0, 5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 |
| `success` | 0, 5, 50, 60 |
| `error` | 0, 5, 50, 60, 70, 80 |
| `warning` | 0, 5, 30, 40, 50, 60 |

```html
<p class="newspack-ui__color--neutral-75">Muted text</p>
```

---

## Position

| Class | Effect |
|-------|--------|
| `position-relative` | `position: relative` |
| `position-absolute` | `position: absolute` |
| `position-fixed` | `position: fixed` |
| `position-sticky` | `position: sticky` |
| `position-static` | `position: static` |
| `position-inherit` | `position: inherit` |
| `position-initial` | `position: initial` |

---

## Visibility

| Class | Effect |
|-------|--------|
| `hidden` | `display: none` |
| `overflow-hidden` | `overflow: hidden` |
