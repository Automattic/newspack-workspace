# Newspack Icons

A collection of custom SVG icons for Newspack projects.

## Installation

```bash
npm install newspack-icons
```

## Usage

```js
import { Icon } from '@wordpress/icons';
import { ad, emailAd, gift } from 'newspack-icons';

// Use with WordPress Icon component
<Icon icon={ ad } />
```

## Available Icons

- `ad` - Advertisement icon
- `emailAd` - Email advertisement icon
- `gift` - Gift icon
- `newspaper` - Newspaper icon
- And more...

## Development

Icons are stored as JS files in the `src` directory that export React components containing SVG data. Each icon is exported through the main `index.js` file.

To add a new icon:
1. Create a JS file in the `src` directory that exports a React component with SVG data
2. Export it in `index.js`
3. Follow the existing naming conventions

## Building

```bash
npm run build
```

## License

GPL-2.0-or-later
