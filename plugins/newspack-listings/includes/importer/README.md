# Newspack Listings Importer

This importer is executed via a WP_CLI command, and will import rows from a CSV file as Newspack Listings posts.

## Config

The importer script requires a config file that describes the CSV data and how it maps to Newspack Listings post data. The config file can be named anything and located anywhere inside the Newspack Listings directory, but must be referenced as a parameter in the CLI command (see Usage below for details). The config should define the following constants:

* `NEWSPACK_LISTINGS_IMPORT_MAPPING` - (Required) This should be an associative array with keys named as the WP post data to import to, and their values as the corresponding CSV header name that maps to that data. For example: `'post_title' => 'csv_header_title'`.
* `NEWSPACK_LISTINGS_IMPORT_SEPARATOR` - (Required) This should be a string that defines what character (or set of characters) is used by the CSV file to separate multiple values that exist under a single column. For example, categories might be grouped under a single CSV column separated by a `;`.
* `NEWSPACK_LISTINGS_IMPORT_DEFAULT_POST_TYPE` - (Optional) This lets you set the Newspack Listings post type the importer will default to if it can't determine what post type a CSV row should be imported as. If not defined, the importer will default to importing unknown data as generic listings.

[A sample config file](https://github.com/Automattic/newspack-listings/tree/trunk/includes/importer/config-sample.php) is included in this repo for reference. For field mapping, only the keys present in this sample config will be used by the importer.

## Usage

Once your config file has been created, drop a CSV file to import somewhere in the Newspack Listings plugin directory (it can be in a subdirectory of its own). Optionally, you can also include image files in an `/images` directory in the same location as the CSV file; if it exists, and the CSV file contains image filenames under a column mapped to `_thumbnail_id` in the config, the importer will look for those filenames in the `/images` directory and import them as media attachments, if they exist.

Then, run the following WP_CLI command:

`wp newpack-listings import --file=<path_to_csv_file> --config=<path_to_config_file>`

The `--file` and `--config` parameters are required, and both paths should be relative to the root Newspack Listings plugin directory. The following additional options are optional:

* `--start=<row_number>` - If set to a number, the importer will skip importing any rows before the specified start row.
* `--max-rows=<number_of_rows>` - If set to a number, the importer will stop once this number of rows has been processed.
* `--dry-run` - If this flag is present, the importer will parse the CSV file but will not persist any data to the WordPress database.
