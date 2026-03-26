#!/usr/bin/env php
<?php
/**
 * Newspack Constants Scanner
 *
 * Scans Newspack repositories for NEWSPACK_ constants checked with defined()
 * and generates documentation from their docblocks.
 *
 * Usage:
 *   php class-newspack-constants-scanner.php [options]
 *
 * Options:
 *   --output=FILE     Write output to FILE (default: stdout)
 *   --format=FORMAT   Output format: md (default) or json
 *   --repos=LIST      Comma-separated list of repos to scan (default: all)
 *   --base-path=PATH  Base path to repositories (default: parent of newspack-plugin)
 *   --undocumented    Show only undocumented constants
 *   --help            Show this help message
 *
 * @package Newspack
 */

if ( php_sapi_name() !== 'cli' ) {
	die( 'This script must be run from the command line.' );
}

/**
 * Main scanner class.
 */
class Newspack_Constants_Scanner {

	/**
	 * Default repositories to scan.
	 *
	 * @var array
	 */
	private const DEFAULT_REPOS = [
		'newspack-plugin',
		'newspack-ads',
		'newspack-blocks',
		'newspack-listings',
		'newspack-newsletters',
		'newspack-popups',
		'newspack-manager',
		'newspack-sponsors',
		'newspack-multibranded-site',
		'newspack-network',
		'newspack-theme',
	];

	/**
	 * Directories to exclude from scanning.
	 *
	 * @var array
	 */
	private const EXCLUDE_DIRS = [
		'vendor',
		'node_modules',
		'tests',
		'.git',
		'.github',
		'dist',
		'build',
		'release',
	];

	/**
	 * Constants to exclude from documentation.
	 *
	 * These are internal plugin constants (file paths, versions, rest bases)
	 * that are always defined by the plugin and not user-configurable.
	 *
	 * @var array
	 */
	private const EXCLUDE_CONSTANTS = [
		// newspack-manager internal constants.
		'NEWSPACK_MANAGER_FILE',
		'NEWSPACK_MANAGER_API_KEY_OPTION_NAME',
		'NEWSPACK_MANAGER_REST_BASE',
		// newspack-manager-client internal constants.
		'NEWSPACK_MANAGER_CLIENT_PLUGIN_DIR',
		'NEWSPACK_MANAGER_CLIENT_REST_BASE',
		// newspack-newsletters internal constants.
		'NEWSPACK_NEWSLETTERS_PLUGIN_FILE',
		// newspack-listings internal constants.
		'NEWSPACK_LISTINGS_FILE',
		'NEWSPACK_LISTINGS_PLUGIN_FILE',
		'NEWSPACK_LISTINGS_URL',
		'NEWSPACK_LISTINGS_VERSION',
		// newspack-popups internal constants.
		'NEWSPACK_POPUPS_PLUGIN_FILE',
		// newspack-blocks internal constants.
		'NEWSPACK_BLOCKS__PLUGIN_FILE',
		'NEWSPACK_BLOCKS__BLOCKS_DIRECTORY',
		'NEWSPACK_BLOCKS__PLUGIN_DIR',
		'NEWSPACK_BLOCKS__VERSION',
		// newspack-plugin internal constants.
		'NEWSPACK_PLUGIN_VERSION',
		'NEWSPACK_PLUGIN_FILE',
		'NEWSPACK_PLUGIN_BASEDIR',
		'NEWSPACK_VERSION',
		'NEWSPACK_HANDOFF_RETURN_URL',
		'NEWSPACK_API_URL',
		// newspack-sponsors internal constants.
		'NEWSPACK_SPONSORS_PLUGIN_FILE',
		'NEWSPACK_SPONSORS_URL',
		// newspack-multibranded-site internal constants.
		'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_DIR',
		'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE',
		// newspack-network internal constants.
		'NEWSPACK_NETWORK_PLUGIN_DIR',
		'NEWSPACK_NETWORK_PLUGIN_FILE',
		// newspack-ads internal constants.
		'NEWSPACK_ADS_VERSION',
		'NEWSPACK_ADS_PLUGIN_FILE',
		'NEWSPACK_ADS_BLOCKS_PATH',
		'NEWSPACK_ADS_MEDIA_KIT_URL',
	];

	/**
	 * Manager client repositories (handled separately).
	 *
	 * @var array
	 */
	private const MANAGER_CLIENT_REPOS = [
		'newspack-manager-client',
		'newspack-manager-admin',
	];

	/**
	 * Base path to repositories.
	 *
	 * @var string
	 */
	private $base_path;

	/**
	 * Repositories to scan.
	 *
	 * @var array
	 */
	private $repos;

	/**
	 * Found constants.
	 *
	 * @var array
	 */
	private $constants = [];

	/**
	 * Constructor.
	 *
	 * @param string $base_path Base path to repositories.
	 * @param array  $repos     Repositories to scan.
	 */
	public function __construct( $base_path, $repos = null ) {
		$this->base_path = rtrim( $base_path, '/' );
		$this->repos     = $repos ?? self::DEFAULT_REPOS;
	}

	/**
	 * Scan all repositories.
	 *
	 * @return array Found constants.
	 */
	public function scan() {
		$this->constants = [];

		foreach ( $this->repos as $repo ) {
			$repo_path = $this->base_path . '/' . $repo;
			if ( is_dir( $repo_path ) ) {
				$this->scan_directory( $repo_path, $repo );
			}
		}

		// Scan manager client repo (detect which folder exists).
		foreach ( self::MANAGER_CLIENT_REPOS as $manager_repo ) {
			$repo_path = $this->base_path . '/' . $manager_repo;
			if ( is_dir( $repo_path ) ) {
				$this->scan_directory( $repo_path, $manager_repo );
				break; // Only scan the first one found.
			}
		}

		// Sort constants by name.
		ksort( $this->constants );

		return $this->constants;
	}

	/**
	 * Check if a repository is a manager client repo.
	 *
	 * @param string $repo Repository name.
	 * @return bool True if it's a manager client repo.
	 */
	private function is_manager_client_repo( $repo ) {
		return in_array( $repo, self::MANAGER_CLIENT_REPOS, true );
	}

	/**
	 * Recursively scan a directory for PHP files.
	 *
	 * @param string $dir  Directory to scan.
	 * @param string $repo Repository name.
	 */
	private function scan_directory( $dir, $repo ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				function ( $file, $key, $iterator ) {
					// Skip excluded directories.
					if ( $iterator->hasChildren() ) {
						return ! in_array( $file->getFilename(), self::EXCLUDE_DIRS, true );
					}
					// Only process PHP files.
					return $file->isFile() && $file->getExtension() === 'php';
				}
			)
		);

		foreach ( $iterator as $file ) {
			$this->scan_file( $file->getPathname(), $repo );
		}
	}

	/**
	 * Scan a single PHP file for constant checks.
	 *
	 * @param string $file_path Full path to the file.
	 * @param string $repo      Repository name.
	 */
	private function scan_file( $file_path, $repo ) {
		$content = file_get_contents( $file_path );
		if ( false === $content ) {
			return;
		}

		// Find all defined('NEWSPACK_...') patterns.
		// Match: defined( 'NEWSPACK_...' ) or defined('NEWSPACK_...')
		$pattern = '/defined\s*\(\s*[\'"]NEWSPACK_([A-Z0-9_]+)[\'"]\s*\)/';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return;
		}

		$lines = explode( "\n", $content );

		foreach ( $matches[0] as $index => $match ) {
			$constant_name = 'NEWSPACK_' . $matches[1][ $index ][0];

			// Skip excluded constants.
			if ( in_array( $constant_name, self::EXCLUDE_CONSTANTS, true ) ) {
				continue;
			}

			$offset = $match[1];

			// Calculate line number.
			$line_number = substr_count( substr( $content, 0, $offset ), "\n" ) + 1;

			// Get relative path.
			$relative_path = str_replace( $this->base_path . '/', '', $file_path );

			// Extract the docblock if present.
			$docblock = $this->extract_docblock( $lines, $line_number - 1 );

			// Parse docblock if found.
			$parsed_doc = $docblock ? $this->parse_docblock( $docblock, $constant_name ) : null;

			// Get the line context.
			$context = trim( $lines[ $line_number - 1 ] ?? '' );

			// Store or merge with existing constant data.
			if ( ! isset( $this->constants[ $constant_name ] ) ) {
				$this->constants[ $constant_name ] = [
					'name'        => $constant_name,
					'type'        => $parsed_doc['type'] ?? null,
					'default'     => $parsed_doc['default'] ?? null,
					'status'      => $parsed_doc['status'] ?? null,
					'description' => $parsed_doc['description'] ?? null,
					'example'     => $parsed_doc['example'] ?? null,
					'locations'   => [],
				];
			}

			// Add this location.
			$this->constants[ $constant_name ]['locations'][] = [
				'repo'        => $repo,
				'file'        => $relative_path,
				'line'        => $line_number,
				'context'     => $context,
				'has_docblock' => ! empty( $docblock ),
			];

			// Update documentation if this location has a more complete docblock.
			if ( $parsed_doc ) {
				$const = &$this->constants[ $constant_name ];
				if ( empty( $const['type'] ) && ! empty( $parsed_doc['type'] ) ) {
					$const['type'] = $parsed_doc['type'];
				}
				if ( empty( $const['default'] ) && ! empty( $parsed_doc['default'] ) ) {
					$const['default'] = $parsed_doc['default'];
				}
				if ( empty( $const['status'] ) && ! empty( $parsed_doc['status'] ) ) {
					$const['status'] = $parsed_doc['status'];
				}
				if ( empty( $const['description'] ) && ! empty( $parsed_doc['description'] ) ) {
					$const['description'] = $parsed_doc['description'];
				}
				if ( empty( $const['example'] ) && ! empty( $parsed_doc['example'] ) ) {
					$const['example'] = $parsed_doc['example'];
				}
			}
		}
	}

	/**
	 * Extract docblock preceding a line.
	 *
	 * @param array $lines       Array of file lines.
	 * @param int   $target_line Target line index (0-based).
	 * @return string|null Docblock content or null.
	 */
	private function extract_docblock( $lines, $target_line ) {
		// Look backwards from the target line for a docblock.
		$docblock_lines = [];
		$in_docblock    = false;

		for ( $i = $target_line - 1; $i >= 0 && $i >= $target_line - 30; $i-- ) {
			$line = trim( $lines[ $i ] ?? '' );

			// Skip empty lines between docblock and code.
			if ( empty( $line ) && ! $in_docblock ) {
				continue;
			}

			// Check for docblock end.
			if ( preg_match( '/\*\/\s*$/', $line ) ) {
				$in_docblock      = true;
				$docblock_lines[] = $line;
				continue;
			}

			// Check for docblock start.
			if ( preg_match( '/^\s*\/\*\*/', $line ) ) {
				if ( $in_docblock ) {
					$docblock_lines[] = $line;
					break;
				}
			}

			// Inside docblock.
			if ( $in_docblock ) {
				$docblock_lines[] = $line;
			} else {
				// Non-docblock code encountered, stop searching.
				break;
			}
		}

		if ( empty( $docblock_lines ) ) {
			return null;
		}

		// Reverse to get correct order.
		return implode( "\n", array_reverse( $docblock_lines ) );
	}

	/**
	 * Parse a docblock into structured data.
	 *
	 * @param string $docblock      Raw docblock string.
	 * @param string $constant_name Expected constant name.
	 * @return array|null Parsed docblock data, or null if @constant tag is missing or mismatched.
	 */
	private function parse_docblock( $docblock, $constant_name ) {
		$result = [
			'type'        => null,
			'default'     => null,
			'status'      => null,
			'description' => null,
			'example'     => null,
		];

		// Require @constant tag to exist and match the constant being processed.
		if ( ! preg_match( '/@constant\s+(\S+)/', $docblock, $matches ) || $matches[1] !== $constant_name ) {
			return null;
		}

		// Extract @type.
		if ( preg_match( '/@type\s+(.+)$/m', $docblock, $matches ) ) {
			$result['type'] = trim( $matches[1] );
		}

		// Extract @default.
		if ( preg_match( '/@default\s+(.+)$/m', $docblock, $matches ) ) {
			$result['default'] = trim( $matches[1] );
		}

		// Extract @status.
		if ( preg_match( '/@status\s+(\S+)/', $docblock, $matches ) ) {
			$result['status'] = trim( $matches[1] );
		}

		// Extract @example.
		if ( preg_match( '/@example\s+(.+)$/m', $docblock, $matches ) ) {
			$result['example'] = trim( $matches[1] );
		}

		// Extract description (text not starting with @).
		$lines            = explode( "\n", $docblock );
		$description_lines = [];
		$in_description   = false;

		foreach ( $lines as $line ) {
			$line = preg_replace( '/^\s*\*\s?/', '', $line ); // Remove leading * and space.
			$line = trim( $line );

			// Skip opening/closing (handles /**, */, and / after * is stripped).
			if ( preg_match( '/^\/\*\*|^\*\/|^\/$/', $line ) ) {
				continue;
			}

			// Skip tags.
			if ( preg_match( '/^@/', $line ) ) {
				$in_description = false;
				continue;
			}

			// Collect description lines.
			if ( ! empty( $line ) || $in_description ) {
				$in_description      = true;
				$description_lines[] = $line;
			}
		}

		$description = trim( implode( "\n", $description_lines ) );
		if ( ! empty( $description ) ) {
			$result['description'] = $description;
		}

		return $result;
	}

	/**
	 * Generate markdown output.
	 *
	 * @param bool $undocumented_only Show only undocumented constants.
	 * @return string Markdown content.
	 */
	public function to_markdown( $undocumented_only = false ) {
		$output = "# Newspack Constants Reference\n\n";
		$output .= "> Auto-generated documentation for NEWSPACK_ constants.\n";
		$output .= "> Generated: " . date( 'Y-m-d H:i:s' ) . "\n\n";

		// Filter if needed.
		$constants = $this->constants;
		if ( $undocumented_only ) {
			$constants = array_filter(
				$constants,
				function ( $const ) {
					return empty( $const['description'] );
				}
			);
		}

		if ( empty( $constants ) ) {
			$output .= "_No constants found._\n";
			return $output;
		}

		// Split constants into general and manager-client groups.
		$general_constants = [];
		$manager_constants = [];

		foreach ( $constants as $name => $const ) {
			$primary_repo = $const['locations'][0]['repo'] ?? '';
			if ( $this->is_manager_client_repo( $primary_repo ) ) {
				$manager_constants[ $name ] = $const;
			} else {
				$general_constants[ $name ] = $const;
			}
		}

		// General constants section.
		if ( ! empty( $general_constants ) ) {
			$output .= "## Summary\n\n";
			$output .= "| Constant | Type | Status | Repository |\n";
			$output .= "|----------|------|--------|------------|\n";

			foreach ( $general_constants as $const ) {
				$type   = $const['type'] ?? '_unknown_';
				$status = $const['status'] ?? '_undocumented_';
				$repo   = $const['locations'][0]['repo'] ?? '_unknown_';

				$output .= sprintf(
					"| `%s` | %s | %s | %s |\n",
					$const['name'],
					$type,
					$status,
					$repo
				);
			}

			$output .= "\n---\n\n";
			$output .= "## Constants\n\n";

			// Detailed sections for general constants.
			$output .= $this->generate_constant_details( $general_constants );
		}

		// Manager Dashboard constants section.
		if ( ! empty( $manager_constants ) ) {
			$output .= "## Manager Dashboard Constants\n\n";
			$output .= "> These constants only apply to the Newspack Manager dashboard, not to individual Newspack sites.\n\n";

			$output .= "### Summary\n\n";
			$output .= "| Constant | Type | Status | Repository |\n";
			$output .= "|----------|------|--------|------------|\n";

			foreach ( $manager_constants as $const ) {
				$type   = $const['type'] ?? '_unknown_';
				$status = $const['status'] ?? '_undocumented_';
				$repo   = $const['locations'][0]['repo'] ?? '_unknown_';

				$output .= sprintf(
					"| `%s` | %s | %s | %s |\n",
					$const['name'],
					$type,
					$status,
					$repo
				);
			}

			$output .= "\n";

			// Detailed sections for manager constants.
			$output .= $this->generate_constant_details( $manager_constants );
		}

		return $output;
	}

	/**
	 * Generate detailed markdown entries for constants.
	 *
	 * @param array $constants Constants to document.
	 * @return string Markdown content.
	 */
	private function generate_constant_details( $constants ) {
		$output = '';

		foreach ( $constants as $const ) {
			$output .= "### {$const['name']}\n\n";

			$output .= "| | |\n";
			$output .= "|---|---|\n";
			$output .= sprintf( "| **Type** | `%s` |\n", $const['type'] ?? '_unknown_' );
			$output .= sprintf( "| **Default** | %s |\n", $const['default'] ?? '_not documented_' );
			$output .= sprintf( "| **Status** | `%s` |\n", $const['status'] ?? 'undocumented' );

			$primary = $const['locations'][0] ?? null;
			if ( $primary ) {
				$output .= sprintf( "| **Location** | `%s:%d` |\n", $primary['file'], $primary['line'] );
			}

			$output .= "\n";

			if ( ! empty( $const['description'] ) ) {
				$output .= $const['description'] . "\n\n";
			} else {
				$output .= "_No description available._\n\n";
			}

			if ( ! empty( $const['example'] ) ) {
				$output .= "**Example:**\n";
				$output .= "```php\n";
				$output .= $const['example'] . "\n";
				$output .= "```\n\n";
			}

			// Show other locations if multiple.
			if ( count( $const['locations'] ) > 1 ) {
				$output .= "**Also used in:**\n";
				foreach ( array_slice( $const['locations'], 1 ) as $loc ) {
					$output .= sprintf( "- `%s:%d`\n", $loc['file'], $loc['line'] );
				}
				$output .= "\n";
			}

			$output .= "---\n\n";
		}

		return $output;
	}

	/**
	 * Generate JSON output.
	 *
	 * @return string JSON content.
	 */
	public function to_json() {
		return json_encode(
			[
				'generated'  => date( 'Y-m-d H:i:s' ),
				'constants'  => array_values( $this->constants ),
				'total'      => count( $this->constants ),
				'documented' => count(
					array_filter(
						$this->constants,
						function ( $c ) {
							return ! empty( $c['description'] );
						}
					)
				),
			],
			JSON_PRETTY_PRINT
		);
	}
}

/**
 * Parse command line arguments.
 *
 * @param array $argv Command line arguments.
 * @return array Parsed options.
 */
function parse_args( $argv ) {
	$options = [
		'output'       => null,
		'format'       => 'md',
		'repos'        => null,
		'base_path'    => null,
		'undocumented' => false,
		'help'         => false,
	];

	foreach ( $argv as $arg ) {
		if ( strpos( $arg, '--output=' ) === 0 ) {
			$options['output'] = substr( $arg, 9 );
		} elseif ( strpos( $arg, '--format=' ) === 0 ) {
			$options['format'] = substr( $arg, 9 );
		} elseif ( strpos( $arg, '--repos=' ) === 0 ) {
			$options['repos'] = explode( ',', substr( $arg, 8 ) );
		} elseif ( strpos( $arg, '--base-path=' ) === 0 ) {
			$options['base_path'] = substr( $arg, 12 );
		} elseif ( $arg === '--undocumented' ) {
			$options['undocumented'] = true;
		} elseif ( $arg === '--help' || $arg === '-h' ) {
			$options['help'] = true;
		}
	}

	return $options;
}

/**
 * Show help message.
 */
function show_help() {
	echo <<<HELP
Newspack Constants Scanner

Scans Newspack repositories for NEWSPACK_ constants checked with defined()
and generates documentation from their docblocks.

Usage:
  php class-newspack-constants-scanner.php [options]

Options:
  --output=FILE     Write output to FILE (default: stdout)
  --format=FORMAT   Output format: md (default) or json
  --repos=LIST      Comma-separated list of repos to scan (default: all)
  --base-path=PATH  Base path to repositories
  --undocumented    Show only undocumented constants
  --help, -h        Show this help message

Examples:
  php class-newspack-constants-scanner.php
  php class-newspack-constants-scanner.php --output=NEWSPACK_CONSTANTS.md
  php class-newspack-constants-scanner.php --format=json --output=constants.json
  php class-newspack-constants-scanner.php --repos=newspack-plugin,newspack-ads
  php class-newspack-constants-scanner.php --undocumented

HELP;
}

// Main execution.
$options = parse_args( $argv );

if ( $options['help'] ) {
	show_help();
	exit( 0 );
}

// Determine base path.
$base_path = $options['base_path'];
if ( ! $base_path ) {
	// Default: repos directory (assumes this script is in newspack-docker/bin/).
	$base_path = dirname( __DIR__ ) . '/repos';
}

// Create scanner and run.
$scanner   = new Newspack_Constants_Scanner( $base_path, $options['repos'] );
$constants = $scanner->scan();

// Generate output.
if ( $options['format'] === 'json' ) {
	$output = $scanner->to_json();
} else {
	$output = $scanner->to_markdown( $options['undocumented'] );
}

// Write output.
if ( $options['output'] ) {
	if ( false === file_put_contents( $options['output'], $output ) ) {
		fwrite( STDERR, "Error: Failed to write to {$options['output']}\n" );
		exit( 1 );
	}
	echo "Output written to: {$options['output']}\n";

	// Show summary.
	$total = count( $constants );
	$documented = count(
		array_filter(
			$constants,
			function ( $c ) {
				return ! empty( $c['description'] );
			}
		)
	);
	echo "Total constants: {$total}\n";
	echo "Documented: {$documented}\n";
	echo "Undocumented: " . ( $total - $documented ) . "\n";
} else {
	echo $output;
}
