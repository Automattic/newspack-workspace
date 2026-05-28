# Newspack Colors

A collection of SCSS color tokens for Newspack projects.

## Installation

```bash
npm install newspack-colors
```

## Usage

### SCSS Import

```scss
// Import all colors
@use "newspack-colors" as colors;

// Use color variables
.button {
	background-color: colors.$primary-600;
	color: colors.$neutral-000;
}

.alert-success {
	background-color: colors.$success-000;
	color: colors.$success-500;
}
```

### JavaScript Import

```js
// Import as CSS modules for JavaScript consumption
import colors from 'newspack-colors';

const styles = {
	button: {
		backgroundColor: colors['primary-600'],
		color: colors['neutral-000'],
	}
};
```

## License

GPL-2.0-or-later
