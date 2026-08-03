# Newspack Icons - Development Guide

Comprehensive developer guidelines for working with the Newspack Icons package.

## Purpose

This package provides custom SVG icons designed specifically for Newspack projects, serving as a supplement to the WordPress icon library when standard icons aren't available.

## When to Use

✅ **Use newspack-icons for:**
- Icons not available in `@wordpress/icons`
- Newspack-specific functionality (e.g., `newspack`, `emailAd`, `curatedList`)
- Custom icons designed for Newspack workflows

❌ **Don't use newspack-icons for:**
- Icons that already exist in `@wordpress/icons` (always check WordPress first)
- Generic/common icons that WordPress provides (e.g., `check`, `close`, `arrowRight`)
- Creating new icons without designer review (use placeholder instead)

## Icon Selection Hierarchy

Follow this step-by-step process when selecting an icon:

1. **Check `@wordpress/icons` first**
   Visit [@wordpress/icons on npm](https://www.npmjs.com/package/@wordpress/icons) and search for the icon you need.

2. **If not available, use `newspack-icons`**
   Check the [Available Icons](#available-icons) list below to see if a custom icon exists.

3. **If still not available, flag for designer review**
   Use a 24×24 ellipsis placeholder icon and request a new icon design from the design team.

## Icon Standards

All icons in this package are designed on a **24×24 grid/viewbox**. This ensures consistency and scalability:

- **Design grid:** All icons use `viewBox="0 0 24 24"`
- **Display flexibility:** Icons can be displayed at different sizes (e.g., 48×48, 40×40) depending on context
- **Scalability:** SVG format allows crisp rendering at any size
- **Consistency:** Uniform grid system ensures visual harmony across the icon set

## Available Icons

This package includes 48 custom icons:

- `accessibility` - Accessibility icon
- `activity` - Activity icon
- `ad` - Advertisement icon
- `account` - Account/user icon
- `ai` - AI icon
- `aiText` - AI text icon
- `archiveLoop` - Archive loop icon
- `aspectLandscape` - Landscape aspect ratio icon
- `aspectPortrait` - Portrait aspect ratio icon
- `aspectSquare` - Square aspect ratio icon
- `ballotBox` - Ballot box icon
- `broadcast` - Broadcast icon
- `browser` - Browser icon
- `collections` - Collections icon
- `contentCarousel` - Content carousel icon
- `contentLocked` - Content locked icon
- `contentLoop` - Content loop icon
- `corrections` - Corrections icon
- `countdown` - Countdown icon
- `curatedList` - Curated list icon
- `emailAd` - Email advertisement icon
- `emailCheck` - Email check icon
- `emailError` - Email error icon
- `emailPremium` - Email premium icon
- `emailSend` - Email send icon
- `gift` - Gift icon
- `hand` - Hand icon
- `iframe` - Iframe icon
- `key` - Key icon
- `logout` - Logout icon
- `mergeTags` - Merge tags icon
- `newspack` - Newspack logo icon
- `newspaper` - Newspaper icon
- `output` - Output icon
- `overlayBottom` - Overlay bottom icon
- `overlayCenter` - Overlay center icon
- `overlayInline` - Overlay inline icon
- `overlayTop` - Overlay top icon
- `phone` - Phone icon
- `playlist` - Playlist icon
- `postAvatar` - Post avatar icon
- `priority` - Priority icon
- `readerRegistration` - Reader registration icon
- `searchEmpty` - Empty search icon
- `tabItem` - Tab item icon
- `tabs` - Tabs icon
- `target` - Target icon
- `theme` - Theme icon

## Usage Examples

### Backend/Admin Usage

Import icons directly from `newspack-icons` in your React/TypeScript components:

```jsx
/**
 * WordPress dependencies
 */
import { Icon, chartBar, settings } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { ad, emailAd, gift } from '../../../../packages/icons';

function MyComponent() {
	return (
		<div>
			{/* WordPress icon */}
			<Icon icon={ chartBar } />
			<Icon icon={ settings } />

			{/* Newspack icon */}
			<Icon icon={ ad } />
			<Icon icon={ emailAd } />
			<Icon icon={ gift } />
		</div>
	);
}
```

### Frontend Usage

For frontend PHP templates, icons must be added to the `Newspack_UI_Icons` class and then used via PHP methods.

#### Step 1: Add Icon to `Newspack_UI_Icons` Class

Edit `includes/class-newspack-ui-icons.php` and add your icon to the `$ui_icons` array:

```php
public static $ui_icons = array(
	// ... existing icons ...
	'myNewIcon' =>
		'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false" class="newspack-ui__svg-icon--my-new-icon">
			<path d="..." />
		</svg>',
);
```

**Important:** Extract the SVG markup from the React component in `packages/icons/src/` and convert it to a plain SVG string with proper attributes (`aria-hidden="true"`, `focusable="false"`, and a CSS class).

#### Step 2: Use Icon in PHP Templates

Use the `print_svg()` method to output the icon:

```php
<?php
use Newspack\Newspack_UI_Icons;
?>

<button class="newspack-ui__button">
	<?php Newspack_UI_Icons::print_svg( 'myNewIcon' ); ?>
	Button Text
</button>
```

Or use `get_svg()` to retrieve the SVG markup:

```php
<?php
$icon_svg = Newspack_UI_Icons::get_svg( 'myNewIcon' );
if ( $icon_svg ) {
	echo wp_kses( $icon_svg, Newspack_UI_Icons::sanitize_svgs() );
}
?>
```

#### Example: Using Icons in UI Components

```php
<div class="newspack-ui__box newspack-ui__box--success">
	<span class="newspack-ui__icon newspack-ui__icon--success">
		<?php Newspack_UI_Icons::print_svg( 'check' ); ?>
	</span>
	<p>Success message with icon</p>
</div>

<button class="newspack-ui__button newspack-ui__button--primary">
	<?php Newspack_UI_Icons::print_svg( 'account' ); ?>
	Account Settings
</button>
```

## Related Packages

- **[@wordpress/icons](https://www.npmjs.com/package/@wordpress/icons)** - WordPress core icon library and `Icon` component (always check this first)
