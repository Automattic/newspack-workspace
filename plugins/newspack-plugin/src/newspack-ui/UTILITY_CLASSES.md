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

### Gap

`newspack-ui__stack--gap-{n}` — overrides the default gap. `n` is `0–12`.

| Step | Value |
|------|-------|
| 0 | 0 |
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

| Class | Size |
|-------|------|
| `newspack-ui__font--2xs` | 2x-small |
| `newspack-ui__font--xs` | x-small |
| `newspack-ui__font--s` | small (default) |
| `newspack-ui__font--m` | medium |
| `newspack-ui__font--l` | large |
| `newspack-ui__font--xl` | x-large |
| `newspack-ui__font--2xl` | 2x-large |
| `newspack-ui__font--3xl` | 3x-large |
| `newspack-ui__font--4xl` | 4x-large |
| `newspack-ui__font--5xl` | 5x-large |
| `newspack-ui__font--6xl` | 6x-large |

Weight modifiers:

| Class | Effect |
|-------|--------|
| `newspack-ui__font--bold` | `font-weight: var(--newspack-ui-font-weight-strong)` |
| `newspack-ui__font--normal` | `font-weight: normal` |

---

## Color

`newspack-ui__color--{scale}-{step}` — sets `color`.

| Scale | Steps available |
|-------|----------------|
| `neutral` | 0, 5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 |
| `primary` | 0, 5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 |
| `success` | 0, 5, 50, 60 |
| `error` | 0, 5, 50, 60 |
| `warning` | 0, 5, 30, 40 |

```html
<p class="newspack-ui__color--neutral-60">Muted text</p>
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
