# Hooks Documentation

This document provides a comprehensive reference for all filters available in the Newspack Profiles plugin.

## Table of Contents

- [Import Manager Filters](#import-manager-filters)
- [Sitemap Generator Filters](#sitemap-generator-filters)
- [Rewrite Rule Filters](#rewrite-rule-filters)

---

## Import Manager Filters

### `newspack_profiles_import_expiration_in_seconds`

**Description:** Filters the time duration after which an import process is considered expired or stale.

**Location:** `includes/ImportManager.php`

**Input:**
- `int` - Default expiration time in seconds (3600 = 1 hour)

**Returns:**
- `int` - Modified expiration time in seconds

**Default Value:** `3600` (1 hour)

**Example:**
```php
add_filter( 'newspack_profiles_import_expiration_in_seconds', function( $seconds ) {
    // Extend expiration to 2 hours
    return 2 * 60 * 60;
} );
```

---

### `newspack_profiles_import_batch_size`

**Description:** Filters the number of records to process in each batch during import operations.

**Location:** `includes/ImportManager.php`

**Input:**
- `int` - Default batch size (100 records)

**Returns:**
- `int` - Modified batch size

**Default Value:** `100`

**Example:**
```php
add_filter( 'newspack_profiles_import_batch_size', function( $size ) {
    // Process 200 records per batch
    return 200;
} );
```

---

### `newspack_profiles_import_batch_delay_in_seconds`

**Description:** Filters the delay between processing consecutive batches during import to prevent server overload.

**Location:** `includes/ImportManager.php`

**Input:**
- `int` - Default delay in seconds (2 seconds)

**Returns:**
- `int` - Modified delay in seconds

**Default Value:** `2`

**Example:**
```php
add_filter( 'newspack_profiles_import_batch_delay_in_seconds', function( $seconds ) {
    // Add a 5-second delay between batches
    return 5;
} );
```

---

### `newspack_profiles_import_batch_insert_size`

**Description:** Filters the number of rows to insert per SQL INSERT statement during batch imports.

**Location:** `includes/ImportManager.php`

**Input:**
- `int` - Default batch insert size (10 rows)

**Returns:**
- `int` - Modified batch insert size

**Default Value:** `10`

**Example:**
```php
add_filter( 'newspack_profiles_import_batch_insert_size', function( $size ) {
    // Insert 25 rows per statement
    return 25;
} );
```

---

## Sitemap Generator Filters

### `newspack_profiles_sitemap_batch_size`

**Description:** Filters the number of profile entries to process in each batch during sitemap generation.

**Location:** `includes/SitemapGenerator.php`

**Input:**
- `int` - Default batch size (100 entries)

**Returns:**
- `int` - Modified batch size

**Default Value:** `100`

**Example:**
```php
add_filter( 'newspack_profiles_sitemap_batch_size', function( $size ) {
    // Process 500 entries per batch
    return 500;
} );
```

---

### `newspack_profiles_sitemap_cache_expiration_in_seconds`

**Description:** Filters the cache expiration time for generated sitemaps served to users.

**Location:** `includes/SitemapGenerator.php`

**Input:**
- `int` - Default cache expiration in seconds (10800 = 3 hours)

**Returns:**
- `int` - Modified cache expiration in seconds

**Default Value:** `10800` (3 hours)

**Example:**
```php
add_filter( 'newspack_profiles_sitemap_cache_expiration_in_seconds', function( $seconds ) {
    // Cache for 6 hours
    return 6 * 60 * 60;
} );
```

---

### `newspack_profiles_sitemap_batch_delay_in_seconds`

**Description:** Filters the delay between processing consecutive batches during sitemap generation.

**Location:** `includes/SitemapGenerator.php`

**Input:**
- `int` - Default delay in seconds (2 seconds)

**Returns:**
- `int` - Modified delay in seconds

**Default Value:** `2`

**Example:**
```php
add_filter( 'newspack_profiles_sitemap_batch_delay_in_seconds', function( $seconds ) {
    // No delay between batches
    return 0;
} );
```

---

### `newspack_profiles_sitemap_generation_stale_duration_in_seconds`

**Description:** Filters the time after which a sitemap generation process is considered stale and can be restarted.

**Location:** `includes/SitemapGenerator.php`

**Input:**
- `int` - Default stale duration in seconds (3600 = 1 hour)

**Returns:**
- `int` - Modified stale duration in seconds

**Default Value:** `3600` (1 hour)

**Example:**
```php
add_filter( 'newspack_profiles_sitemap_generation_stale_duration_in_seconds', function( $seconds ) {
    // Consider stale after 30 minutes
    return 30 * 60;
} );
```

---

## Rewrite Rule Filters

### `newspack_profiles_base_path`

**Description:** Filters the base URL path for all profile collection URLs. This determines the first segment of the URL structure for profiles.

**Location:** `includes/registrars/RewriteRuleRegistrar.php`

**Input:**
- `string` - Default base path ('profiles')

**Returns:**
- `string` - Modified base path (only alphanumeric characters, hyphens, and slashes are allowed)

**Default Value:** `'profiles'`

**Example:**
```php
add_filter( 'newspack_profiles_base_path', function( $base_path ) {
    // Change base path to 'team-members'
    return 'team-members';
} );
```

**Note:** The returned value is sanitized to only allow valid URL path characters (letters, numbers, hyphens, and slashes). Multiple consecutive slashes are collapsed to a single slash, and leading/trailing slashes are removed. If the sanitized result is empty, the default 'profiles' is used.

**URL Structure Example:**
- Default: `https://example.com/profiles/{collection-slug}/{profile-slug}`
- Modified: `https://example.com/team-members/{collection-slug}/{profile-slug}`

---

## Best Practices

1. **Performance Tuning:** Adjust batch sizes and delays based on your server capabilities and data volume.
2. **Import Expiration:** Increase expiration time if you're importing large datasets that take longer to process.
3. **Caching:** Adjust sitemap cache expiration based on how frequently your profile data changes.
4. **URL Structure:** Choose a meaningful base path that reflects your content structure and update it before creating profile collections to avoid redirect issues.

## Notes

- All time-based filters accept and return values in seconds.
- All size-based filters accept and return integer values.
- Filters should return the same data type they receive as input.
- Changes to URL-related filters (`newspack_profiles_base_path`) may require flushing rewrite rules.
