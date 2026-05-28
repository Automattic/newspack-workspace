# Newspack Components - Development Guide

Comprehensive developer guidelines for working with the Newspack Components package. If you're building or changing a wizard or settings screen, start with [When to Use](#when-to-use) and [Design & layout at a glance](#design--layout-at-a-glance), then [Usage](#usage) and [Available Components](#available-components).

## Contents

- [Purpose](#purpose)
- [When to Use](#when-to-use)
- [Component Selection Hierarchy](#component-selection-hierarchy)
- [Design & layout at a glance](#design--layout-at-a-glance)
- [Available Components](#available-components)
- [Usage](#usage)
- [Import Patterns](#import-patterns)
- [Common WordPress Components](#common-wordpress-components-used-alongside-newspack-components)
- [Styling](#styling)
- [Component Patterns](#component-patterns) *(for contributors)*
- [Component Development Guidelines](#component-development-guidelines)
- [Related Packages](#related-packages)
- [Testing](#testing)

## Purpose

This package provides custom React components designed specifically for Newspack backend/admin interfaces (wizards, settings pages, etc.). These components are built on top of WordPress components and provide Newspack-specific functionality, styling, and patterns.

**In short:** For backend/admin screens use Newspack components first (Card, ActionCard, SectionHeader, etc.), fall back to WordPress components when needed, and follow the spacing scale and hierarchy patterns so UIs stay consistent. For block editor UI use WordPress components (and `AutocompleteTokenField` only when you need autocomplete). For reader-facing UI use Newspack UI or theme components, not this package.

**Design-wise:** Backend UIs should feel consistent with the WordPress admin, with clear visual hierarchy (section → card → controls) and predictable spacing. Use the same components and spacing scale across wizards so design and code stay aligned; when introducing a new pattern or layout, align with design (and designer review) before implementing.

## When to Use

### Decision Tree

**Backend/Admin UI (Wizards, Settings Pages):**
- ✅ **Use Newspack components first** - Check if a Newspack component exists for your use case
- ⚠️ **Fallback to WordPress components** - If no Newspack component exists, use `@wordpress/components`
- Examples: Dashboard, Settings, Audience Management, Setup Wizard

**Gutenberg Blocks:**
- ✅ **Use WordPress components** - Block editor UI should use `@wordpress/components` for consistency with the block editor
- ⚠️ **Exception:** `AutocompleteTokenField` - Can be used in blocks when autocomplete functionality is needed
- Examples: All blocks in `src/blocks/` use WordPress components

**Frontend (Reader-facing UI, including block render output):**
- ❌ **Don't use Newspack components** - Use Newspack UI (`src/newspack-ui/`) or theme components instead
- Examples: My Account, Reader Activation modals, Auth screens

## Component Selection Hierarchy

Follow this step-by-step process when selecting a component:

1. **Check Newspack components first**
   - Review the [Available Components](#available-components) list below
   - Check `packages/components/src/` directory for component implementations
   - Newspack components are optimized for backend/admin workflows

2. **If not available, use WordPress components**
   - Visit [@wordpress/components Storybook](https://wordpress.github.io/gutenberg/?path=/docs/docs-introduction--page)
   - Check [@wordpress/components npm package](https://www.npmjs.com/package/@wordpress/components)
   - Cross-reference Storybook documentation and NPM package code with the version of `@wordpress/components` installed via `package.json` to confirm that the installed package contains the expected component(s)
   - WordPress components provide standard admin UI patterns

3. **If still not available, flag for designer review**
   - Use a placeholder component and request a new component design from the design team
   - Don't create new components without designer review
   - Once approved, follow existing Newspack component patterns (see [Component Patterns](#component-patterns))
   - Ensure it's reusable and follows WordPress design system principles
   - Consider if it should be a Newspack component or a wizard-specific component

## Design & layout at a glance

When building a screen, use the **spacing scale** (8px unit: 16, 24, 32, 48, 64) and prefer **VStack** for vertical stacks and **HStack** for related items in a row; use **Grid** for real multi-column layouts. Structure content as **SectionHeader → Card → ActionCard → controls**, with **Divider** between sections and primary actions in **`.newspack-buttons-card`**. Use the same **breakpoints** (e.g. 744px, 1128px) as existing components. Full detail: [Spacing scale](#spacing-scale-design-system), [Layout (HStack / VStack / Grid)](#layout-when-to-use-hstack-vstack-grid), [Responsive breakpoints](#responsive-breakpoints), [Visual hierarchy patterns](#visual-hierarchy-patterns), [Component states](#component-states). For code examples by context and wizard patterns, see [Usage](#usage).

## Available Components

### Layout Components

- **`Card`** – Container for a logical block of content; use for grouping related settings. Default vertical margin is 32px so cards stack with consistent rhythm. Use `noBorder` when cards sit inside another card (e.g. ActionCard children).
- **`Grid`** – Use for laying out several items in columns (e.g. multiple controls or cards). Default gap is 32px; use `columns` and `gutter` modifiers (8, 16, 24, 32) when you need tighter or looser spacing. For a single row or a simple vertical stack, prefer **VStack** (or HStack) in new code; Grid is still used that way in many places and is fine to leave as-is until we refactor.
- **`Divider`** – Use between logical sections (e.g. between ActionCards) to separate content without another card. Margins are 32px (64px at larger breakpoints) so spacing stays consistent with the rest of the layout.
- **`SectionHeader`** – Use to start a new section; pair with a short description so the section’s goal is clear. Top margin (64px) and bottom (32px) create clear separation from previous content and the section body.
- **`BoxContrast`** – High-contrast content box for emphasis.

### Form Components

- **`Button`** - Enhanced button component (wraps WordPress Button with routing support)
- **`TextControl`** - Text input with Newspack styling and required field support
- **`RadioControl`** - Radio button group control
- **`ColorPicker`** - Color selection component
- **`ImageUpload`** - Image upload and selection component
- **`FormTokenField`** - Token input field for tags/categories; prefer the Newspack component over the core `FormTokenField` because it also supports a `description` prop for help text like other controls.
- **`AutocompleteTokenField`** - Autocomplete token field (can be used in block editor)
- **`AutocompleteWithSuggestions`** - Autocomplete with custom suggestions
- **`AutocompleteWithLatestPosts`** - Autocomplete with latest posts
- **`CategoryAutocomplete`** - Category-specific autocomplete
- **`CustomSelectControl`** - Custom select dropdown component

### Content Components

- **`ActionCard`** – Use when one concept (e.g. a feature or setting) can be toggled on/off and may have extra content below. Internal padding (24px default; 16px/8px for isMedium/isSmall) and 24px between regions keep hierarchy clear; expandable content uses 24px top padding and 24px between siblings.
- **`Notice`** – Use for outcome feedback (success/error/warning) or short contextual messages. Vertical margin is 32px so notices don’t collide with cards; keep one primary message per area when possible.
- **`Waiting`** – Loading state indicator.
- **`ProgressBar`** – Progress indicator.
- **`Accordion`** – Collapsible content sections.
- **`StepsList`** / **`StepsListItem`** – Step-by-step list components.
- **`StyleCard`** – Style preview card.

### Wizard Components

- **`Wizard`** - Main wizard container with tabbed navigation and data fetching
- **`withWizard`** - Higher-order component for wizard screens (legacy pattern, class-based)
  - Provides plugin management, error handling, loading states
  - Used in older wizards like Setup Wizard
  - Passes props: `wizardApiFetch`, `setError`, `isLoading`, `pluginRequirements`, etc.
  - Do not use for new view components; use `Wizard` and/or `withWizardScreen` instead
- **`withWizardScreen`** - Higher-order component for wizard screens (modern pattern, function-based)
  - Provides header, tabbed navigation, button actions, handoff messages
  - Used in newer wizards like Audience Management
  - Passes props: `renderPrimaryButton`, plus all original component props
- **`TabbedNavigation`** - Tab navigation component
- **`Footer`** - Wizard footer component
- **`Handoff`** - Handoff message component for external integrations
- **`HandoffMessage`** - Handoff message display component

### Plugin Management Components

- **`PluginInstaller`** - Plugin installation and activation component
- **`PluginToggle`** - Plugin enable/disable toggle
- **`PluginSettings`** - Plugin settings configuration component

### Utility Components

- **`Modal`** - Modal dialog component
- **`Popover`** - Popover component
- **`WebPreview`** - Web preview iframe component
- **`NewspackIcon`** - Newspack icon wrapper component
- **`InfoButton`** - Info button with tooltip
- **`GlobalNotices`** - Global notice system component

### Settings Components

- **`Settings`** - Settings container component
- **`SortableNewsletterListControl`** - Sortable newsletter list selector

### Utilities & Hooks

- **`hooks`** - Custom React hooks (e.g., `useObjectState`, `usePrompt`, `useOnClickOutside`)
- **`utils`** - Utility functions (e.g., `confirmAction`, color utilities)
- **`Router`** - Proxied React Router import (use instead of direct `react-router-dom` import)
  - Note: This package currently uses [React Router v5](https://v5.reactrouter.com/). Please refer to v5 documentation for API details.

## Import Patterns

### Standard Import Pattern

Follow this import order (each group separated by a blank line with a JSDoc comment):

```jsx
/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * WordPress dependencies
 */
import { CheckboxControl, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import {
	ActionCard,
	Button,
	Card,
	Notice,
	TextControl,
} from '../../../../../packages/components/src';
```

### Importing from packages/components

**Within this monorepo:** Use relative paths (no webpack alias is configured):

```jsx
// ✅ CORRECT - Within newspack-plugin monorepo
import { Button, Card, Notice } from '../../../../../packages/components/src';
```

**As an npm package:** If `newspack-components` is installed as a dependency in another plugin, import from the package name:

```jsx
// ✅ CORRECT - When installed as npm package
import { Button, Card, Notice } from 'newspack-components';
```

**Import individual components** – Import only what you need; do not import the whole namespace. List named imports **alphabetically** (e.g. from `@wordpress/components` or `newspack-components`):

```jsx
// ✅ CORRECT – alphabetical
import { Button, Card, Notice } from '../../../../../packages/components/src';

// ❌ WRONG – Don't import all
import * as Components from '../../../../../packages/components/src';
```

### Router Import Pattern

**Always use the proxied router** - Never import `react-router-dom` directly in source code:

```jsx
// ✅ CORRECT
import Router from '../../../../../packages/components/src/proxied-imports/router';
const { HashRouter, Route, Switch } = Router;

// ❌ WRONG - Don't import directly
import { HashRouter } from 'react-router-dom';
```

**Exception:** Tests may import `react-router-dom` directly.

## Usage

This section shows how to use components **by context** (backend, blocks, frontend) and **common patterns** (Wizard, withWizardScreen, withWizard, hooks, AutocompleteTokenField in blocks). Use it together with [Available Components](#available-components) and [Import Patterns](#import-patterns).

### Backend/Admin UI

**Rule:** Use Newspack components as the primary choice, falling back to WordPress components when needed.

**Common import pattern:**
```jsx
/**
 * WordPress dependencies
 */
import { CheckboxControl, ExternalLink, RangeControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import {
	ActionCard,
	Button,
	Card,
	Grid,
	Notice,
	Divider,
	SectionHeader,
	TextControl,
	Waiting,
} from '../../../../../packages/components/src';
```

**Example – Audience Setup Wizard (real reference):**
```jsx
import { CheckboxControl, ExternalLink, RangeControl } from '@wordpress/components';
import {
	ActionCard,
	Button,
	Card,
	Grid,
	Notice,
	Divider,
	PluginInstaller,
	SectionHeader,
	TextControl,
	Waiting,
	withWizardScreen,
} from '../../../../../packages/components/src';

export default withWizardScreen( ( { config, updateConfig } ) => {
	return (
		<>
			<Notice noticeText={ __( 'Audience Management is enabled.', 'newspack-plugin' ) } isSuccess />
			<Card noBorder>
				<ActionCard
					title={ __( 'Present newsletter signup after checkout', 'newspack-plugin' ) }
					toggleChecked={ config.use_custom_lists }
					toggleOnChange={ value => updateConfig( 'use_custom_lists', value ) }
				>
					<Grid columns={ 4 }>
						<RangeControl
							label={ __( 'Initial list size', 'newspack-plugin' ) }
							value={ config.newsletter_list_initial_size }
							onChange={ value => updateConfig( 'newsletter_list_initial_size', parseInt( value ) ) }
						/>
					</Grid>
				</ActionCard>
			</Card>
			<div className="newspack-buttons-card">
				<Button variant="primary" onClick={ saveConfig }>
					{ __( 'Save Settings', 'newspack-plugin' ) }
				</Button>
			</div>
		</>
	);
} );
```

**Reference:** `src/wizards/audience/views/setup/setup.js`

For wizard shells and hooks see [Common patterns](#common-patterns) below.

### Gutenberg Blocks

**Rule:** Use WordPress components for blocks. Only use `AutocompleteTokenField` from Newspack when autocomplete is needed.

**Example – Collections block:**
```jsx
import { PanelBody, TextControl } from '@wordpress/components';
import { AutocompleteTokenField } from '../../../../packages/components/src';

function InspectorPanel( { attributes, setAttributes } ) {
	return (
		<PanelBody title={ __( 'Settings', 'newspack-plugin' ) }>
			<TextControl
				label={ __( 'Title', 'newspack-plugin' ) }
				value={ attributes.title }
				onChange={ value => setAttributes( { title: value } ) }
			/>
			<AutocompleteTokenField
				label={ __( 'Categories', 'newspack-plugin' ) }
				value={ attributes.categories }
				onChange={ value => setAttributes( { categories: value } ) }
			/>
		</PanelBody>
	);
}
```

**Reference:** `src/blocks/collections/components/InspectorPanel.jsx`

**Why:** Blocks should match the block editor; WordPress components keep that consistency.

### Frontend (Reader-facing UI)

**Rule:** Don’t use Newspack components. Use Newspack UI (`src/newspack-ui/`) or theme components instead.

**Why:** This package is for backend/admin only; reader-facing UI uses other systems.

### Common patterns

#### Wizard (modern)

Use the `Wizard` component for tabbed wizard UIs with data fetching and tabbed navigation:

```jsx
/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { GlobalNotices, Notice, Wizard } from '../../../../../packages/components/src';
import sections from './sections';

function Dashboard() {
	return (
		<Fragment>
			<GlobalNotices />
			<Wizard
				headerText={ __( 'Newspack / Dashboard', 'newspack-plugin' ) }
				sections={ sections }
				renderAboveSections={ () => (
					<>
						<BrandHeader />
						<SiteStatuses />
					</>
				) }
			/>
		</Fragment>
	);
}
```

### Using withWizardScreen HOC (Modern Pattern)

Use `withWizardScreen` for modern wizard screens that need header, navigation, and button actions:

```jsx
/**
 * Internal dependencies
 */
import { withWizardScreen, ActionCard, Button, Card } from '../../../../../packages/components/src';

export default withWizardScreen( ( { config, updateConfig, saveConfig, renderPrimaryButton } ) => {
	return (
		<Card noBorder>
			<ActionCard
				title={ __( 'Feature', 'newspack-plugin' ) }
				toggleChecked={ config.enabled }
				toggleOnChange={ value => updateConfig( 'enabled', value ) }
			/>
		</Card>
	);
} );
```

**When to use:** Modern wizard screens (Audience Management, Settings sections, etc.). Use when you need more precise control over the layout and routing structure in a wizard view than the `Wizard` component can provide.

### Using withWizard HOC (Legacy Pattern)

Use `withWizard` for legacy wizard screens that need plugin management and error handling:

```jsx
/**
 * Internal dependencies
 */
import { withWizard, Card, PluginInstaller } from '../../../../../packages/components/src';

function MyWizardScreen( { wizardApiFetch, setError, isLoading, pluginRequirements } ) {
	return (
		<>
			{ pluginRequirements }
			<Card>
				{/* Wizard content */}
			</Card>
		</>
	);
}

export default withWizard( MyWizardScreen, [ 'required-plugin-slug' ] );
```

**When to use:** Legacy wizards (Setup Wizard, older wizard implementations). Do not use for new wizards.

### Using Hooks and Utilities

```jsx
/**
 * Internal dependencies
 */
import { hooks, utils } from '../../../../../packages/components/src';

function MyComponent() {
	// useObjectState hook for managing object state
	const [ data, setData ] = hooks.useObjectState( {
		field1: '',
		field2: false,
	} );

	// Update nested field
	setData( { field1: 'new value' } );

	// Confirm action utility
	const handleDelete = () => {
		if ( utils.confirmAction( __( 'Are you sure?', 'newspack-plugin' ) ) ) {
			// Proceed with deletion
		}
	};
}
```

### Using AutocompleteTokenField in the Block Editor

```jsx
/**
 * WordPress dependencies
 */
import { PanelBody } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { AutocompleteTokenField } from '../../../../packages/components/src';

function InspectorPanel( { attributes, setAttributes } ) {
	return (
		<PanelBody>
			<AutocompleteTokenField
				label={ __( 'Categories', 'newspack-plugin' ) }
				value={ attributes.categories }
				onChange={ value => setAttributes( { categories: value } ) }
				suggestions={ [ 'News', 'Opinion', 'Sports' ] }
			/>
		</PanelBody>
	);
}
```

## Common WordPress Components Used Alongside Newspack Components

When Newspack components don't provide what you need, use these WordPress components. The list below reflects actual usage across the codebase (wizards, blocks, packages/components, other scripts).

### Form controls

- **`CheckboxControl`** – Checkbox input (very common in wizards and blocks)
- **`ToggleControl`** – Toggle switch (common in settings and content gates)
- **`RangeControl`** – Numeric range slider
- **`TextControl`** – Single-line text input
- **`TextareaControl`** – Multi-line text input
- **`SelectControl`** – Select dropdown
- **`FormTokenField`** – Core token/tag input (e.g. categories, tags); for wizards and settings UIs prefer the Newspack `FormTokenField` wrapper, which adds a `description` prop for help text like other controls.
- **`__experimentalNumberControl as NumberControl`** – Number input (use with `@wordpress/no-unsafe-wp-apis` eslint comment if needed)
- **`__experimentalToggleGroupControl`** / **`__experimentalToggleGroupControlOption`** – Toggle group (e.g. content gifting, countdown banner, contribution meter; use with eslint comment for unsafe APIs)

### Layout

- **`__experimentalHStack as HStack`** – Horizontal stack (in use, e.g. Audience > Subscriptions)
- **`__experimentalVStack as VStack`** – Vertical stack (preferred for new layout when you need vertical stacking; use same import pattern as HStack)
- **`Flex`** / **`FlexItem`** – Flex layout (used in Nextdoor sidebar; consider HStack/VStack for new code when appropriate)

### Panels and structure (blocks and admin)

- **`Panel`** / **`PanelBody`** / **`PanelHeader`** / **`PanelRow`** – Block editor panels and rows
- **`BaseControl`** – Base wrapper for form controls (label + help text)
- **`useBaseControlProps`** – Hook for base control props (e.g. collection meta CTAs)
- **`CardHeader`** / **`CardBody`** – Card layout (e.g. Nextdoor settings)
- **`__experimentalHeading as Heading`** – Heading component (use with eslint comment for unsafe APIs when needed)

### Buttons, links, menus

- **`Button`** – Button (blocks and some admin; prefer Newspack `Button` in wizards)
- **`ExternalLink`** – Link that opens in a new tab (very common for docs/help)
- **`MenuItem`** – Item inside dropdown/menu
- **`DropdownMenu`** – Dropdown menu (e.g. content gates, webhooks, ad units)

### Feedback and overlays

- **`Spinner`** – Loading spinner
- **`Notice`** – Inline notice (success/error/warning); prefer Newspack `Notice` in wizards when it fits
- **`Placeholder`** – Empty state in blocks
- **`Modal`** – Modal dialog
- **`Popover`** – Popover (e.g. webhooks endpoint actions, corrections modal)
- **`Tooltip`** – Tooltip (e.g. site statuses, info)

### Block editor UI

- **`ToolbarButton`** / **`ToolbarGroup`** – Block toolbar buttons and groups
- **`Icon`** – Icon wrapper (from `@wordpress/icons`; pass an icon from `@wordpress/icons` or newspack-icons)

### Other

- **`Draggable`** – Drag-and-drop reorder (e.g. ActionCard, collection meta CTAs)
- **`DatePicker`** / **`DateTimePicker`** – Date/time picker (e.g. contribution meter, corrections modal)
- **`ClipboardButton`** – Copy-to-clipboard button (e.g. Salesforce)
- **`SVG`** / **`Path`** – Inline SVG (e.g. NewspackIcon, Nextdoor)

**Icons:** Do not use Dashicons. Use **`@wordpress/icons`** first, then **newspack-icons** ([packages/icons](../icons/DEVELOPMENT.md)). Use the `Icon` component from `@wordpress/icons` with an icon from either library. See the [Icons Development Guide](../icons/DEVELOPMENT.md) for the full selection hierarchy.

### Importing experimental components

Experimental components (e.g. `HStack`, `VStack`, `NumberControl`, `ToggleGroupControl`, `Heading`) live under `@wordpress/components` and may be prefixed with `__experimental`. Use the same import style as the rest of the codebase and add an eslint disable for unsafe APIs when required:

```jsx
import {
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalNumberControl as NumberControl, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	ExternalLink,
} from '@wordpress/components';
```

**Example – form controls and layout:**
```jsx
import {
	CheckboxControl,
	ExternalLink,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	RangeControl,
} from '@wordpress/components';
import { ActionCard, Card } from '../../../../../packages/components/src';

<ActionCard title="Settings">
	<HStack>
		<RangeControl
			label={ __( 'Value', 'newspack-plugin' ) }
			value={ config.value }
			onChange={ value => updateConfig( 'value', value ) }
		/>
		<CheckboxControl
			label={ __( 'Enable', 'newspack-plugin' ) }
			checked={ config.enabled }
			onChange={ value => updateConfig( 'enabled', value ) }
		/>
	</HStack>
</ActionCard>
```

## Styling

Newspack components use SCSS with BEM-ish naming conventions and a consistent spacing scale so layouts stay visually consistent.

### Naming and structure

- **Prefix:** `newspack-` (e.g. `.newspack-card`, `.newspack-button`)
- **Modifiers:** Use `--` for modifiers (e.g. `.newspack-card--no-border`)
- **Elements**: Use `__` for elements that are part of a larger block-level component (e.g. `.newspack-card__header-content`)
- **WordPress colors:** Use WordPress design system colors (see [Colors Development Guide](../colors/DEVELOPMENT.md))
- **Custom styles:** Component-specific styles live in `packages/components/src/{component}/style.scss`

### Spacing scale (design system)

Spacing is based on an **8px unit**. Use these values so new styles match existing components:

| Value | Use in components | Typical use |
|-------|-------------------|-------------|
| **8px** | Tight gaps, badge padding, small padding (e.g. ActionCard is-small) | Inline or dense UI |
| **16px** | Gaps between related controls, buttons card gap, margins inside ActionCard region-children for Card/Grid/TextControl | Related items, form rows |
| **24px** | Default ActionCard region padding, toggle/region gaps, expandable content padding and sibling spacing | Default internal padding and gaps within a card |
| **32px** | Card vertical margin, Grid default gap and margin, SectionHeader first-child top, Notice margin, Divider margin | Section rhythm, between blocks |
| **48px** | SectionHeader container margin-top, Card horizontal padding (small screens) | Section separation |
| **64px** | SectionHeader top margin, Divider margins (large breakpoint), buttons card margin, Card horizontal padding (large screens) | Major section separation |

**In code:** Card uses `margin: 32px 0` and `padding: 16px 48px` (32px 64px at 744px+). ActionCard uses 24px for region padding and 24px between regions; region-children use `padding: 0 24px 24px` (0 32px 32px for is-medium). Grid uses `grid-gap: 32px` and `margin: 32px 0` by default, with optional gutter classes (`__gutter-8`, `__gutter-16`, etc.). When adding new components or overrides, prefer these values (or 8px multiples) instead of ad-hoc spacing.

### Layout: when to use HStack, VStack, Grid

- **HStack** – A few related items in a row (e.g. label + control, or two related controls). Use when the relationship is “these belong together horizontally.”
- **VStack** – Stack blocks vertically with consistent spacing. **Prefer VStack** for a single column of items, lists of settings, or stacked sections when you want vertical rhythm without custom margins. For new code, favour VStack over using Grid as a single row.
- **Grid (Newspack)** – A set of items in columns (e.g. multiple cards or form groups). Default 32px gap; use `columns` and gutter modifiers. Use when the layout is genuinely a grid of items (multiple columns). There are many existing examples where Grid is used as a single row—that’s not wrong, but we’d use VStack (or HStack) for that in new code and may update those over time.

Prefer these patterns (and the spacing scale above) over one-off margins so layout stays consistent with Card, ActionCard, and SectionHeader.

### Responsive breakpoints

Components use consistent breakpoints so layouts behave predictably across viewports. Use these when adding or changing responsive styles:

| Breakpoint | Typical use |
|------------|-------------|
| **600px** | Narrow viewport overrides (e.g. wizard layout) |
| **744px** | Card padding, ActionCard region layout (title + toggle side-by-side), Grid columns (2–3), modal, withWizardScreen layout |
| **783px** | Wizard content width, Divider full margin |
| **960px** | Modal width, wizard layout |
| **961px** | Wizard layout |
| **1128px** | Grid 3–4 columns |
| **1224px** | Large wizard layout |

Prefer these values over new breakpoints so behaviour stays consistent with Card, Grid, and wizard shells.

### Visual hierarchy patterns

Common composition patterns keep screens predictable. Use these as a reference when building new wizards or settings:

**Settings screen (Wizard-based):**
```
Wizard
  GlobalNotices
  SectionHeader          ← 64px top margin
  Card                   ← 32px margin
    ActionCard            ← 24px padding, optional toggle
      Grid or VStack      ← 32px gap
        TextControl / SelectControl / etc.
    Divider              ← 32px / 64px margin
    ActionCard
  .newspack-buttons-card  ← 64px margin, 16px gap
    Button (primary)
```

**Wizard step (withWizardScreen):**
```
withWizardScreen
  Notice (optional)
  Card noBorder
    ActionCard (toggle + description)
      [Expandable content when enabled]
    Divider
    ActionCard
  .newspack-buttons-card
    Button
```

**Form group:** Use `Grid columns={4}` (or VStack) with TextControl, SelectControl, CheckboxControl, etc. inside a Card or ActionCard; spacing between controls follows the [spacing scale](#spacing-scale-design-system) (e.g. 16px for related rows).

### Component states

Components rely on WordPress design system states where applicable; a few Newspack-specific behaviours:

- **ActionCard (clickable)** – Hover: `box-shadow: 0 4px 8px rgba(black, 0.08)`; transition 125ms ease-in-out. Use for cards that navigate or open.
- **Button** – Primary, secondary, disabled, and link variants follow `@wordpress/components` Button; focus and hover come from WordPress base styles.
- **Toggle (inside ActionCard)** – Checked/unchecked and focus states from WordPress ToggleControl; label is visually hidden but available for accessibility.
- **Notice** – Success (green), error (red), warning (yellow), info (gray) variants; use for feedback only and one primary message per area when possible.

When adding new interactive components, preserve focus visibility and use the same state patterns (hover shadow, transition) so the UI feels consistent.

### Example

```scss
.newspack-card {
	border-radius: 2px;
	border: 1px solid wp-colors.$gray-300;
	margin: 32px 0;
	padding: 16px 48px;

	@media screen and (min-width: 744px) {
		padding: 32px 64px;
	}

	&--no-border {
		border: 0;
		padding: 0;
	}
}
```

## Related Packages

- **[newspack-colors](../colors/)** – Color palette used by components (via WordPress design system)
- **[newspack-icons](../icons/)** – Custom icons used in components
- **[@wordpress/components](https://www.npmjs.com/package/@wordpress/components)** – WordPress component library (fallback when Newspack components don’t exist)
- **[@wordpress/base-styles](https://www.npmjs.com/package/@wordpress/base-styles)** – WordPress Design System styles and colors

**Design resources:** For layout, components, and spacing, refer to the [WordPress Figma Library](https://www.figma.com/community/file/1149596986784498103/wordpress-design-library) and the [Block Editor Handbook](https://developer.wordpress.org/block-editor/reference-guides/components/). The **Components Demo** (`/wp-admin/admin.php?page=newspack-components-demo`) is the live reference for how Newspack components look and behave; use it to confirm spacing and hierarchy when building new screens.

## Component Patterns

*For contributors adding or changing components.*

### Wrapping WordPress Components

Many Newspack components wrap WordPress components to add Newspack-specific functionality:

**Example - Button Component:**
```tsx
import { Button as BaseComponent } from '@wordpress/components';
import Router from '../proxied-imports/router';

const Button = ( { href, onClick, ...otherProps } ) => {
	const history = useHistory();
	// Newspack-specific logic: handle both href and onClick
	if ( href && onClick ) {
		// Await onClick then redirect
	}
	return <BaseComponent { ...otherProps } />;
};
```

**Example - TextControl Component:**
```tsx
import { TextControl as BaseComponent } from '@wordpress/components';

const TextControl = ( { required, isWide, ...otherProps } ) => {
	// Newspack-specific styling and required field handling
	return (
		<div className="newspack-text-control--required">
			<BaseComponent className={ classes } required={ required } { ...otherProps } />
		</div>
	);
};
```

### Standalone Components

Some components are built from scratch for Newspack-specific use cases:

**Example - ActionCard Component:**
```jsx
// Custom component with toggle, actions, expandable content, etc.
const ActionCard = ( {
	title,
	description,
	toggleChecked,
	toggleOnChange,
	children,
	// ... many other props
} ) => {
	// Custom implementation
};
```

### Functional vs Class Components

Modern React APIs prefer functional components over class components, even though both are currently still supported. Some older Newspack components are still class-based, while newer components are functional. When creating new Newspack components, use functional components instead of class components.

**Example - Functional component (correct):**
```jsx
// ✅ CORRECT - example from the Divider component
/**
 * Divider
 */

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * External dependencies
 */
import classNames from 'classnames';

const Divider = ( { alignment = 'none', className = undefined, marginBottom = 64, marginTop = 64, variant = 'default', ...otherProps } ) => {
	const classes = classNames(
		'newspack-divider',
		className,
		alignment && `newspack-divider--alignment-${ alignment }`,
		variant && `newspack-divider--variant-${ variant }`
	);

	const style = {
		'--divider-margin-bottom': typeof marginBottom === 'number' ? `${ marginBottom }px` : marginBottom,
		'--divider-margin-top': typeof marginTop === 'number' ? `${ marginTop }px` : marginTop,
	};

	return <hr className={ classes } style={ style } { ...otherProps } />;
};

export default Divider;
```

**Example - Class component (avoid in new code):**
```jsx
// ❌ WRONG – Example from the older `Popover` component
/**
 * WordPress dependencies
 */
import { Popover as BaseComponent } from '@wordpress/components';
import { Component } from '@wordpress/element';

/**
 * Internal dependencies
 */
import './style.scss';

/**
 * External dependencies
 */
import classnames from 'classnames';

/**
 * Popover
 */
class Popover extends Component {
	/**
	 * Render
	 */
	render() {
		const { className, padding, ...otherProps } = this.props;
		const classes = classnames( 'newspack-popover', padding && 'newspack-popover__padding-' + padding, className );
		return <BaseComponent className={ classes } { ...otherProps } />;
	}
}

Popover.defaultProps = {
	padding: false,
};

export default Popover;
```

## Component Development Guidelines

When creating new components:

1. **Check if WordPress component exists** – Consider wrapping/extending WordPress components first.
2. **Align with design** – For new patterns or layouts (e.g. a new card style or wizard step), get designer review before implementing so spacing, hierarchy, and component choice match the design system.
3. **Follow import patterns** – Use the standard import order with JSDoc comments.
4. **Translate static strings** – Always wrap user-facing text in translation functions (`__()`, `_e()`, `_n()`, etc.) from `@wordpress/i18n` with the `'newspack-plugin'` text domain. This includes labels, button text, error messages, help text, and any other strings displayed to users.
5. **Prefer functional components** – Use functional components instead of class components for new components. See [Functional vs Class Components](#functional-vs-class-components) for examples.
6. **Use TypeScript** – Prefer `.tsx` for new components (codebase is migrating to TypeScript).
7. **Add PropTypes or TypeScript types** – Document component props.
8. **Include styles** – Add component-specific styles in `style.scss`; use the [spacing scale](#spacing-scale-design-system) (8px multiples: 16, 24, 32, 48, 64) so new components match Card, ActionCard, and Grid.
9. **Follow naming conventions** – Use BEM-ish naming with `newspack-` prefix.
10. **Use WordPress design system** – Leverage WordPress colors and the same spacing values as existing components.
11. **Export from index.js** – Add component to `packages/components/src/index.js`.
12. **Document usage** – Add JSDoc comments and update this guide.
13. **Components Demo** - If the component is complex or would benefit from demo examples, add it to the [Components Demo page](#testing).

## Testing

Components can be tested using the Components Demo page:

- **URL:** `/wp-admin/admin.php?page=newspack-components-demo`
- **Location:** `src/wizards/componentsDemo/index.js`
- **Purpose:** Visual testing and documentation of component usage
