# Newspack Colors - Development Guide

Comprehensive developer guidelines for working with the Newspack Colors package.

## Purpose

This package provides a color palette specifically for Newspack UI—reader-facing, non-customizable elements like My Account, Reader Activation, and auth modals that should be consistent across all sites.

## When to Use

### Decision Tree

**Backend/Admin UI:**
- ✅ **Use WordPress Design System colors** (`@wordpress/base-styles/colors`)
- ⚠️ **EXCEPTION:** Override WordPress accent color to use `primary-600` (see [Key Color Standards](#key-color-standards))

**Newspack Blocks:**
- ✅ **Block icons MUST use `primary-400`** (see [Key Color Standards](#key-color-standards))
- ❌ **Block styling/layouts:** Use theme color palette (customizable by publishers)

**Frontend - Newspack UI Components:**
- ✅ **Use newspack-colors** for reader features that should be consistent across all sites
- Examples: My Account (`src/my-account/v1/`), Reader Activation, Auth modals

**Frontend - Theme Elements (blocks, layouts):**
- ❌ **DON'T use newspack-colors**
- ✅ **Use theme color palette** (customizable by publishers)
  - Block Theme: Use `theme.json` color tokens
  - Classic Theme: Use `newspack-theme` variables

**Brand Colors (secondary, tertiary, quaternary):**
- ℹ️ **For Newspack brand/marketing use only**
- ❌ **NOT used in product development**
- Listed in package for completeness but should be ignored for development

## Color Usage by Context

### Backend/Admin UI

**Rule:** Use WordPress Design System colors (`@wordpress/base-styles/colors`) for all admin interface elements.

**Exception:** Override WordPress admin accent color to use Newspack `primary-600`:

```scss
@use "~@wordpress/base-styles/colors" as wp-colors;
@use "../../colors/colors.module" as colors;

:root {
	// Override WordPress admin accent color
	--wp-admin-theme-color: #{colors.$primary-600};
	--wp-admin-theme-color--rgb: #{colors.$primary-600--rgb};
	--wp-admin-theme-color-darker-10: #{colors.$primary-700};
	--wp-admin-theme-color-darker-10--rgb: #{colors.$primary-700--rgb};
	--wp-admin-theme-color-darker-20: #{colors.$primary-800};
	--wp-admin-theme-color-darker-20--rgb: #{colors.$primary-800--rgb};
}

// Use WordPress colors for everything else
.checkbox-icon {
	background: white;
	box-shadow: inset 0 0 0 1px wp-colors.$gray-300;

	svg {
		fill: wp-colors.$gray-700;
	}

	&--checked {
		background: wp-colors.$alert-green;
	}
}
```

**Location:** `packages/components/src/style.scss`

### Newspack Blocks

**Rule:** Block icons MUST use `primary-400`. Block styling and layouts use theme colors.

**Example - Avatar Block:**

```js
/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { postAvatar as icon } from '../../../packages/icons';
import colors from '../../../packages/colors/colors.module.scss';

export const settings = {
	icon: {
		src: icon,
		foreground: colors['primary-400'], // ✅ REQUIRED for block icons
	},
};
```

**Reference:** `src/blocks/avatar/index.js`

**All Newspack blocks follow this pattern:**
- `src/blocks/avatar/index.js`
- `src/blocks/byline/index.js`
- `src/blocks/my-account-button/index.js`
- `src/blocks/collections/index.js`
- `src/blocks/reader-registration/index.js`
- All Newspack blocks use `primary-400` for icon foreground

**Block styling:** Blocks support theme color customization via `block.json` color supports and should NOT use newspack-colors for styling.

### Frontend - Newspack UI Components

**Rule:** Use newspack-colors for reader-facing features that must be consistent across all sites.

**Examples:**
- My Account (`src/my-account/v1/`)
- Reader Activation (`includes/reader-activation/`)
- Auth modals (`src/reader-activation-auth/`)

These components use CSS custom properties mapped from newspack-colors:

```scss
@use "../../../../packages/colors/colors.module" as colors;

:root {
	--newspack-ui-color-primary-60: #{colors.$primary-600};
	--newspack-ui-color-neutral-0: #{colors.$neutral-000};
	--newspack-ui-color-neutral-90: #{colors.$neutral-900};
	// ... etc
}
```

**Usage in components:**

```scss
.newspack-ui__button {
	background-color: var(--newspack-ui-color-neutral-90);
	color: var(--newspack-ui-color-neutral-0);

	&:hover {
		background-color: var(--newspack-ui-color-neutral-60);
	}
}
```

**Location:** `src/newspack-ui/scss/variables/_colors.scss`

### Frontend - Theme Elements

**Rule:** DON'T use newspack-colors. Use theme color palette instead.

**Block Theme:**
Use `theme.json` preset colors. The accent color (`--wp--preset--color--accent`) defaults to `#003da5` (matching `primary-600`) but is customizable by publishers.

```scss
// Use theme.json preset colors
.block {
	color: var(--wp--preset--color--accent);
	background-color: var(--wp--preset--color--base);
	border-color: var(--wp--preset--color--base-3);
}
```

**Reference:** [newspack-block-theme theme.json](https://github.com/Automattic/newspack-block-theme/blob/trunk/theme.json)

**Classic Theme:**
Use `newspack-theme` color variables. The primary color (`--newspack-theme-color-primary`) defaults to `#003da5` (matching `primary-600`) but is customizable by publishers.

```scss
// Use newspack-theme variables
.block {
	color: var(--newspack-theme-color-primary);
	background-color: var(--newspack-theme-color-bg-body);
	border-color: var(--newspack-theme-color-border);
}
```

**Reference:** [newspack-theme _colors.scss](https://github.com/Automattic/newspack-theme/blob/trunk/newspack-theme/sass/variables-site/_colors.scss)

**Why:** Theme elements should be customizable by publishers to match their brand.

## Key Color Standards

### Backend Accent Override: `primary-600`

The WordPress admin accent color is overridden to use Newspack `primary-600` (`#003da5`). This ensures consistent branding in the admin interface while maintaining WordPress design system compatibility.

**Implementation:**
```scss
:root {
	--wp-admin-theme-color: #{colors.$primary-600};
}
```

**Location:** `packages/components/src/style.scss`

### Newspack Block Icons: `primary-400`

All Newspack block icons MUST use `primary-400` (`#406ebc`) as the foreground color. This ensures visual consistency in the block editor.

**Implementation:**
```js
import colors from '../../../packages/colors/colors.module.scss';

export const settings = {
	icon: {
		src: icon,
		foreground: colors['primary-400'], // REQUIRED
	},
};
```

**Applies to:** All blocks in `src/blocks/`

## Available Colors

**RGB Variants:** ⚠️ **All colors** (primary, neutral, semantic, and brand) include RGB variants (suffixed with `--rgb`) that return comma-separated RGB values. These are useful for transparency (`rgba(rgb-value, opacity)`) and CSS custom properties.

### Primary Colors (Product Use ✅)

The primary color scale is used throughout Newspack UI:

- `primary-000` through `primary-1000` (full scale)
- Base color (`600`): `#003da5`
- **Most commonly used:** `primary-600` (base), `primary-400` (block icons), `primary-700` (hover states)
- **RGB variants:** All primary colors have `--rgb` variants (e.g., `primary-600--rgb`)

### Neutral Colors (Product Use ✅)

Grayscale palette for text, borders, and backgrounds:

- `neutral-000` through `neutral-1000` (full scale)
- `000`: Pure white (`#fff`)
- `1000`: Pure black (`#000`)
- **Most commonly used:** `neutral-000` (white), `neutral-900` (dark text), `neutral-600` (light text), `neutral-300` (borders)
- **RGB variants:** All neutral colors have `--rgb` variants (e.g., `neutral-900--rgb`)

### Semantic Colors (Product Use ✅)

#### Success Colors
- `success-000`, `success-050`, `success-500`, `success-600`
- Usage: Success messages, positive feedback
- **RGB variants:** All success colors have `--rgb` variants (e.g., `success-500--rgb`)

#### Error Colors
- `error-000`, `error-050`, `error-500`, `error-600`
- Usage: Error messages, destructive actions
- **RGB variants:** All error colors have `--rgb` variants (e.g., `error-500--rgb`)

#### Warning Colors
- `warning-000`, `warning-050`, `warning-300`, `warning-400`
- Usage: Warning messages, caution states
- **RGB variants:** All warning colors have `--rgb` variants (e.g., `warning-300--rgb`)

### Brand Colors (Brand/Marketing Only ❌)

**These colors are NOT used in product development:**

- **Secondary Colors:** `secondary-000` through `secondary-1000`
- **Tertiary Colors:** `tertiary-000` through `tertiary-1000`
- **Quaternary Colors:** `quaternary-000` through `quaternary-1000`

**Purpose:** Listed for completeness but reserved for Newspack brand/marketing materials only.

**RGB variants:** All brand colors also have `--rgb` variants, but these should not be used in product development.

## Usage Examples

### Backend/Admin: WordPress Colors with Accent Override

```scss
@use "~@wordpress/base-styles/colors" as wp-colors;
@use "../../colors/colors.module" as colors;

:root {
	// Override WordPress accent color
	--wp-admin-theme-color: #{colors.$primary-600};
}

.component {
	// Use WordPress colors for everything else
	background: wp-colors.$gray-100;
	border: 1px solid wp-colors.$gray-300;
	color: wp-colors.$gray-900;

	&:hover {
		background: wp-colors.$gray-200;
	}
}
```

### Newspack Blocks: Block Icon Color

```js
/**
 * WordPress dependencies
 */
import { postAuthor as icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import colors from '../../../packages/colors/colors.module.scss';

export const settings = {
	icon: {
		src: icon,
		foreground: colors['primary-400'], // ✅ REQUIRED
	},
};
```

### Frontend: Newspack UI Components

#### SCSS Usage

```scss
@use "../../../../packages/colors/colors.module" as colors;

:root {
	// Map to CSS custom properties
	--newspack-ui-color-primary-60: #{colors.$primary-600};
	--newspack-ui-color-neutral-0: #{colors.$neutral-000};
	--newspack-ui-color-neutral-90: #{colors.$neutral-900};
}

.newspack-ui__button {
	background-color: var(--newspack-ui-color-primary-60);
	color: var(--newspack-ui-color-neutral-0);
	border: 1px solid var(--newspack-ui-color-neutral-30);
}

.newspack-ui__box--success {
	background-color: var(--newspack-ui-color-success-0);
	color: var(--newspack-ui-color-success-60);
	border-left: 4px solid var(--newspack-ui-color-success-50);
}
```

#### Direct SCSS Variable Usage

```scss
@use "../../../../packages/colors/colors.module" as colors;

.component {
	background-color: colors.$primary-600;
	color: colors.$neutral-000;

	// Using RGB for transparency
	&::before {
		background-color: rgba(colors.$primary-600--rgb, 0.1);
	}
}
```

#### JavaScript Usage

```js
import colors from 'newspack-colors';

const styles = {
	button: {
		backgroundColor: colors['primary-600'],
		color: colors['neutral-000'],
	},
	overlay: {
		backgroundColor: `rgba(${colors['primary-600--rgb']}, 0.1)`,
	},
};
```

### Frontend: Theme Elements (What NOT to Do)

❌ **Don't use newspack-colors:**

```scss
// ❌ WRONG - Don't hardcode Newspack colors in theme blocks
.theme-block {
	background-color: colors.$primary-600; // ❌
}
```

✅ **Use theme colors instead:**

```scss
// ✅ CORRECT - Use theme color tokens
.theme-block {
	// Block theme
	background-color: var(--wp--preset--color--accent);
	color: var(--wp--preset--color--base);

	// Classic theme
	background-color: var(--newspack-theme-color-primary);
	color: var(--newspack-theme-color-bg-body);
}
```

## Related Packages

- **[newspack-ui](../newspack-ui/)** - Newspack UI component system that uses these colors via CSS custom properties
- **[@wordpress/base-styles](https://www.npmjs.com/package/@wordpress/base-styles)** - WordPress Design System colors (use for backend/admin)
