# Divider

Horizontal rule component with alignment, variant, and margin options.

## Props

| Prop | Type | Default | Description |
|------|------|---------|-------------|
| `alignment` | `'full-width'` \| `'none'` | `'none'` | `full-width` breaks out of the container to span the viewport; `none` stays within the container. |
| `className` | `string` | — | Additional CSS class. |
| `marginBottom` | `number` \| `string` | `64` | Bottom margin (e.g. `64` or `"2rem"`). Capped at 32px on viewports &lt; 783px. |
| `marginTop` | `number` \| `string` | `64` | Top margin (e.g. `64` or `"2rem"`). Capped at 32px on viewports &lt; 783px. |
| `variant` | `'default'` \| `'primary'` \| `'secondary'` \| `'tertiary'` | `'default'` | Line color: `default` uses `$gray-300`; `primary` uses the admin theme color (`--wp-admin-theme-color`); `secondary` uses `$gray-200`; `tertiary` uses `$gray-100`. |

## Usage

```jsx
import { Divider } from 'newspack-components';

<Divider />

<Divider alignment="full-width" variant="primary" />

<Divider marginBottom={ 48 } marginTop={ 32 } variant="secondary" />
```
