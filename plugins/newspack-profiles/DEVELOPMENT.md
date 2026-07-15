# Development Guide

This guide covers the development workflow for Newspack Profiles plugin.

## Prerequisites

- **Node.js:** 18.x or higher (check `.nvmrc` for exact version)
- **npm:** 9.x or higher
- **PHP:** 8.1 or higher
- **Composer:** 2.x or higher
- **WordPress:** 6.7 or higher

## Initial Setup

### 1. Use Correct Node Version

If you have `nvm` installed:
```bash
nvm use
```

### 2. Install Node Dependencies

```bash
npm install
```

This will install all required packages including:
- WordPress Scripts
- React & TypeScript
- Tailwind CSS
- ESLint, Prettier, and other development tools

### 3. Install PHP Dependencies

For development:
```bash
composer install
```

For production:
```bash
composer install --no-dev --optimize-autoloader
```

## Development Workflow

### Development Build (Watch Mode)

Start the development server with hot module replacement:

```bash
npm start
```

Or for hot reload:
```bash
npm run start:hot
```

> **Note:** Development mode requires `SCRIPT_DEBUG` to be enabled in your `wp-config.php`:
> ```php
> define( 'SCRIPT_DEBUG', true );
> ```

This will:
- Watch for file changes
- Automatically rebuild on save
- Enable source maps for debugging
- Run in development mode with unminified output

**Output:** Files are built to the `/dist` directory

### Production Build

Create optimized production builds:

```bash
npm run build
```

This will:
- Minify JavaScript and CSS
- Optimize assets for production
- Generate asset dependency files
- Remove source maps
- Tree-shake unused code

**Output:** Optimized files in the `/dist` directory

## Available Scripts

### Build & Development
- `npm start` - Start development server with watch mode
- `npm run start:hot` - Start with hot module replacement
- `npm run build` - Create production build

### Code Quality
- `npm run format` - Format code with Prettier
- `npm run lint:js` - Lint JavaScript/TypeScript files
- `npm run lint:style` - Lint CSS/SCSS files
- `npm run lint:pkg-json` - Validate package.json

### Utilities
- `npm run plugin-zip` - Create a distributable plugin ZIP file
- `npm run check-engines` - Verify Node.js and npm versions
- `npm run check-licenses` - Check dependency licenses

### PHP Code Quality
```bash
# Check PHP code standards
composer run phpcs

# Auto-fix PHP code style issues
composer run phpcbf
```

## Code Standards

### JavaScript/TypeScript
- **Linting:** ESLint with WordPress config
- **Formatting:** Prettier
- **Style Guide:** WordPress JavaScript Coding Standards

### PHP
- **Standard:** WordPress Coding Standards
- **Compatibility:** PHPCompatibility for PHP 8.1+
- **Type Safety:** Strict typing enabled

### CSS/SCSS
- **Linting:** Stylelint with WordPress config
- **Framework:** Tailwind CSS 4.x

## Building for Distribution

1. **Update version numbers** in:
   - `newspack-profiles.php`
   - `package.json`
   - `readme.txt`

2. **Run production build:**
   ```bash
   npm run build
   composer install --no-dev --optimize-autoloader
   ```

3. **Create plugin ZIP:**
   ```bash
   npm run plugin-zip
   ```

4. **Test the ZIP file:**
   - Install on a fresh WordPress instance
   - Verify all functionality works
   - Check for any console errors

