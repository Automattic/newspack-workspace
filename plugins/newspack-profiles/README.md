# Newspack Profiles

Turn your Google Sheets or Airtable data into beautiful, SEO-optimized profile pages.

## Overview

Newspack Profiles is a WordPress plugin designed for newsrooms and organizations that need to create dynamic profile pages from external data sources. Whether you're managing election candidates, local businesses, or any other structured data, Newspack Profiles makes it easy to import, display, and maintain your profiles.

## Features

### Data Import
- **Google Sheets Integration** - Import data directly from Google Sheets
- **Airtable Support** - Connect to Airtable tables

### Profile Collections
- Create multiple profile collections, each with its own data source
- Configure custom URL slugs for each collection

### SEO & Performance
- Automatic SEO-optimized URLs
- XML sitemap generation for profiles
- Meta tag management

### Customization
- Pre-built profile patterns in multiple color schemes
- Block editor integration for content customization

## Requirements

- **WordPress:** 6.7 or higher
- **PHP:** 8.1 or higher
- **Required Plugin:** [Remote Data Blocks](https://wordpress.org/plugins/remote-data-blocks/)
- **Object Cache:** Recommended for performance

## Installation

1. Upload the plugin files to `/wp-content/plugins/newspack-profiles/` directory
2. Run `composer install --no-dev` to install PHP dependencies
3. Activate the plugin through the 'Plugins' screen in WordPress
4. Ensure Remote Data Blocks plugin is installed and activated
5. Navigate to **Newspack Profiles** in the WordPress admin menu

## Getting Started

1. **Create a Profile Collection:**
   - Go to Newspack Profiles in the admin menu
   - Click "Add New Collection"
   - Configure your data source (Google Sheets or Airtable)

2. **Customize Display:**
   - Choose from pre-built patterns or create custom templates
   - Configure which fields to display
   - Adjust layout and styling

3. **Setup SEO:**
   - Configure URL and SEO settings for profiles

3. **Publish:**
   - Set collection status to "Published"
   - Profiles will be accessible at `example.com/profiles/your-slug/`

## Architecture

The plugin is built with modern WordPress development practices:

- **PHP 8.1+** with strict typing
- **Composer** for dependency management
- **React/TypeScript** for admin interface
- **WordPress Block Editor** integration
- **REST API** endpoints for data management

### Key Components

- **ProfileCollections** - Manages profile collection configurations
- **ImportManager** - Handles data import from external sources
- **SEOManager** - Handles meta tags and SEO optimization
- **SitemapGenerator** - Creates XML sitemaps for profiles
- **QueryManager** - Manages profile data queries
- **PageTemplateManager** - Handles custom templates

## Development

For development setup and build instructions, see [DEVELOPMENT.md](DEVELOPMENT.md).

## License

This plugin is licensed under the GPL v2 or later.

## Support

For support and contributions, please visit the [GitHub repository](https://github.com/Automattic/newspack-profiles).
