<?php
/**
 * Constants scanner.
 *
 * Scans checkouts for NEWSPACK_ constants guarded with defined() and builds a
 * catalog from their docblocks. Pure PHP: no WordPress functions, so it runs
 * from the CLI in CI and under PHPUnit alike.
 *
 * @package Newspack_Workspace
 */

/**
 * Constants scanner.
 */
class Newspack_Constants_Scanner {

	/**
	 * Envelope schema version.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Directories never descended into.
	 */
	private const EXCLUDE_DIRS = [ 'vendor', 'node_modules', 'tests', '.git', 'dist', 'build' ];

	/**
	 * Max lines to look back from a `defined()` guard for its docblock.
	 * Bounded so a large file with no nearby docblock doesn't force scanning
	 * back to the top of it; generous enough to span a realistic docblock
	 * plus any blank or comment lines between it and the guard.
	 */
	private const DOCBLOCK_LOOKBACK_LINES = 100;

	/**
	 * Sources to scan: name => [ path, branch, sha ].
	 *
	 * @var array
	 */
	private $sources;

	/**
	 * Every constant found, documented or not, keyed by name.
	 *
	 * @var array
	 */
	private $found = [];

	/**
	 * Constructor.
	 *
	 * @param array $sources Map of source name => [ 'path' => string, 'branch' => ?string, 'sha' => ?string ].
	 */
	public function __construct( array $sources ) {
		$this->sources = [];
		foreach ( $sources as $name => $source ) {
			$this->sources[ $name ] = [
				'path'   => rtrim( $source['path'], '/' ),
				'branch' => $source['branch'] ?? null,
				'sha'    => $source['sha'] ?? null,
			];
		}
	}

	/**
	 * Scan every source.
	 *
	 * @return array Documented constants keyed by name, sorted by name.
	 */
	public function scan(): array {
		$this->found = [];
		foreach ( $this->sources as $name => $source ) {
			if ( is_dir( $source['path'] ) ) {
				$this->scan_directory( $source['path'], $name );
			}
		}
		ksort( $this->found );
		return $this->get_documented();
	}

	/**
	 * Documented constants, keyed by name.
	 *
	 * @return array
	 */
	private function get_documented(): array {
		return array_filter(
			$this->found,
			function ( $constant ) {
				return $constant['documented'];
			}
		);
	}

	/**
	 * Constants with a defined() guard but no matching docblock anywhere.
	 *
	 * @return array Keyed by name.
	 */
	public function get_undocumented(): array {
		return array_filter(
			$this->found,
			function ( $constant ) {
				return ! $constant['documented'];
			}
		);
	}

	/**
	 * Recursively scan a directory for PHP files.
	 *
	 * @param string $dir    Directory.
	 * @param string $source Source name.
	 */
	private function scan_directory( string $dir, string $source ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				function ( $file, $key, $iterator ) {
					if ( $iterator->hasChildren() ) {
						return ! in_array( $file->getFilename(), self::EXCLUDE_DIRS, true );
					}
					return $file->isFile() && 'php' === $file->getExtension();
				}
			)
		);

		foreach ( $iterator as $file ) {
			$this->scan_file( $file->getPathname(), $source );
		}
	}

	/**
	 * Scan one PHP file.
	 *
	 * @param string $file_path Absolute path.
	 * @param string $source    Source name.
	 */
	private function scan_file( string $file_path, string $source ): void {
		$content = file_get_contents( $file_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		if ( false === $content ) {
			return;
		}

		$code_only = $this->strip_non_matchable( $content );

		$pattern = '/\bdefined\s*\(\s*[\'"]NEWSPACK_([A-Z0-9_]+)[\'"]\s*\)/';
		if ( ! preg_match_all( $pattern, $code_only, $matches, PREG_OFFSET_CAPTURE ) ) {
			return;
		}

		$lines         = explode( "\n", $content );
		$relative_path = substr( $file_path, strlen( $this->sources[ $source ]['path'] ) + 1 );

		foreach ( $matches[0] as $index => $match ) {
			$constant_name = 'NEWSPACK_' . $matches[1][ $index ][0];
			$line_number   = substr_count( substr( $code_only, 0, $match[1] ), "\n" ) + 1;
			$docblock      = $this->extract_docblock( $lines, $line_number - 1 );
			$parsed        = $docblock ? $this->parse_docblock( $docblock, $constant_name ) : null;

			if ( ! isset( $this->found[ $constant_name ] ) ) {
				$this->found[ $constant_name ] = [
					'name'        => $constant_name,
					'type'        => null,
					'default'     => null,
					'status'      => null,
					'description' => null,
					'example'     => null,
					'documented'  => false,
					'locations'   => [],
				];
			}

			$this->found[ $constant_name ]['locations'][] = [
				'source'       => $source,
				'file'         => $relative_path,
				'line'         => $line_number,
				'has_docblock' => null !== $parsed,
			];

			if ( $parsed ) {
				$constant               = &$this->found[ $constant_name ];
				$constant['documented'] = true;
				foreach ( [ 'type', 'default', 'status', 'description', 'example' ] as $field ) {
					if ( null === $constant[ $field ] && null !== $parsed[ $field ] ) {
						$constant[ $field ] = $parsed[ $field ];
					}
				}
				unset( $constant );
			}
		}
	}

	/**
	 * Replace every comment, string literal, heredoc/nowdoc body and stretch
	 * of inline HTML with whitespace, keeping newlines in place, so the
	 * `defined()` guard regex only ever runs over real code.
	 *
	 * Comments never contained a real guard to begin with. Strings, heredocs
	 * and inline HTML can *quote* a guard (e.g. an error message or docblock
	 * example built as a string) without the code actually checking it, which
	 * would otherwise be catalogued as a phantom constant.
	 *
	 * The one string that must survive is the quoted constant name inside a
	 * real guard, e.g. `defined( 'NEWSPACK_X' )` — that argument is itself a
	 * `T_CONSTANT_ENCAPSED_STRING`. Blanking it like any other string would
	 * defeat the scanner entirely, so a string literal is only blanked when
	 * it is not immediately preceded (ignoring whitespace and comments) by
	 * `defined (`; heredoc/nowdoc bodies and inline HTML are always blanked,
	 * since PHP's own tokenizer never presents either as that argument.
	 *
	 * Byte length and line numbers stay identical to the input, so offsets
	 * found in the result still apply to the original content and `$lines`.
	 *
	 * @param string $content PHP source.
	 * @return string
	 */
	private function strip_non_matchable( string $content ): string {
		$stripped = '';
		$recent   = []; // Last two significant (non-whitespace, non-comment) tokens.

		$track = function ( $id, $text ) use ( &$recent ) {
			$recent[] = [
				'id'   => $id,
				'text' => $text,
			];
			if ( count( $recent ) > 2 ) {
				array_shift( $recent );
			}
		};

		$blank = function ( string $text ): string {
			return preg_replace( '/[^\n]/', ' ', $text );
		};

		foreach ( token_get_all( $content ) as $token ) {
			if ( ! is_array( $token ) ) {
				$stripped .= $token;
				if ( '' !== trim( $token ) ) {
					$track( null, $token );
				}
				continue;
			}

			list( $id, $text ) = $token;

			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				$stripped .= $blank( $text );
				continue;
			}

			if ( T_WHITESPACE === $id ) {
				$stripped .= $text;
				continue;
			}

			if ( T_CONSTANT_ENCAPSED_STRING === $id && $this->is_defined_guard_argument( $recent ) ) {
				$stripped .= $text;
				$track( $id, $text );
				continue;
			}

			if ( in_array( $id, [ T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML ], true ) ) {
				$stripped .= $blank( $text );
				$track( $id, $text );
				continue;
			}

			$stripped .= $text;
			$track( $id, $text );
		}

		return $stripped;
	}

	/**
	 * Whether the last two significant tokens before a string literal are a
	 * call to `defined` followed by `(`, i.e. the string is that call's
	 * argument.
	 *
	 * The callee can tokenize as plain `T_STRING` (`defined(...)`), or as
	 * `T_NAME_FULLY_QUALIFIED` / `T_NAME_QUALIFIED` when it carries a
	 * namespace prefix (`\defined(...)`, `Foo\defined(...)`) — PHP folds the
	 * leading backslash and any namespace segments into that single token,
	 * so only its tail is checked. Matched case-insensitively, since PHP
	 * function names are.
	 *
	 * @param array $recent Up to the last two significant tokens, each
	 *                       [ 'id' => int|null, 'text' => string ]; a plain
	 *                       (non-token) character carries a null id.
	 * @return bool
	 */
	private function is_defined_guard_argument( array $recent ): bool {
		if ( 2 !== count( $recent ) ) {
			return false;
		}

		list( $callee, $paren ) = $recent;

		if ( null !== $paren['id'] || '(' !== $paren['text'] ) {
			return false;
		}

		if ( T_STRING === $callee['id'] ) {
			return 0 === strcasecmp( $callee['text'], 'defined' );
		}

		if ( in_array( $callee['id'], [ T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ], true ) ) {
			return (bool) preg_match( '/(?:^|\\\\)defined$/i', $callee['text'] );
		}

		return false;
	}

	/**
	 * Extract the docblock immediately preceding a line.
	 *
	 * @param array $lines       File lines.
	 * @param int   $target_line 0-based index of the guard line.
	 * @return string|null
	 */
	private function extract_docblock( array $lines, int $target_line ): ?string {
		$docblock_lines = [];
		$in_docblock    = false;

		for ( $i = $target_line - 1; $i >= 0 && $i >= $target_line - self::DOCBLOCK_LOOKBACK_LINES; $i-- ) {
			$line = trim( $lines[ $i ] ?? '' );

			if ( '' === $line && ! $in_docblock ) {
				continue;
			}

			if ( ! $in_docblock && preg_match( '/^(\/\/|#)/', $line ) ) {
				continue;
			}

			if ( preg_match( '/\*\/\s*$/', $line ) ) {
				$in_docblock      = true;
				$docblock_lines[] = $line;
				continue;
			}

			if ( preg_match( '/^\s*\/\*\*/', $line ) && $in_docblock ) {
				$docblock_lines[] = $line;
				break;
			}

			if ( $in_docblock ) {
				$docblock_lines[] = $line;
			} else {
				break;
			}
		}

		if ( empty( $docblock_lines ) ) {
			return null;
		}

		return implode( "\n", array_reverse( $docblock_lines ) );
	}

	/**
	 * Parse a docblock.
	 *
	 * @param string $docblock      Raw docblock.
	 * @param string $constant_name Constant the docblock must name in @constant.
	 * @return array|null Parsed fields, or null when @constant is missing or names another constant.
	 */
	private function parse_docblock( string $docblock, string $constant_name ): ?array {
		if ( ! preg_match( '/@constant\s+(\S+)/', $docblock, $matches ) || $matches[1] !== $constant_name ) {
			return null;
		}

		$result = [
			'type'        => null,
			'default'     => null,
			'status'      => null,
			'description' => null,
			'example'     => null,
		];

		if ( preg_match( '/@type\s+(.+)$/m', $docblock, $matches ) ) {
			$result['type'] = trim( $matches[1] );
		}
		if ( preg_match( '/@default\s+(.+)$/m', $docblock, $matches ) ) {
			$result['default'] = trim( $matches[1] );
		}
		if ( preg_match( '/@status\s+(\S+)/', $docblock, $matches ) ) {
			$result['status'] = trim( $matches[1] );
		}
		if ( preg_match( '/@example\s+(.+)$/m', $docblock, $matches ) ) {
			$result['example'] = trim( $matches[1] );
		}

		$description_lines = [];
		foreach ( explode( "\n", $docblock ) as $line ) {
			$line = trim( preg_replace( '/^\s*\*\s?/', '', $line ) );
			if ( preg_match( '/^\/\*\*|^\*\/|^\/$/', $line ) ) {
				continue;
			}
			if ( preg_match( '/^@/', $line ) ) {
				break;
			}
			$description_lines[] = $line;
		}
		$description = trim( implode( "\n", $description_lines ) );
		if ( '' !== $description ) {
			$result['description'] = $description;
		}

		return $result;
	}

	/**
	 * Strip the internal `documented` flag from a constant row.
	 *
	 * @param array $constant Constant.
	 * @return array
	 */
	private function public_row( array $constant ): array {
		unset( $constant['documented'] );
		return $constant;
	}

	/**
	 * The JSON envelope as an array.
	 *
	 * @return array
	 */
	public function to_envelope(): array {
		$sources = [];
		foreach ( $this->sources as $name => $source ) {
			$sources[] = [
				'name'   => $name,
				'branch' => $source['branch'],
				'sha'    => $source['sha'],
			];
		}

		return [
			'schema_version' => self::SCHEMA_VERSION,
			'generated_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'sources'        => $sources,
			'constants'      => array_values( array_map( [ $this, 'public_row' ], $this->get_documented() ) ),
		];
	}

	/**
	 * The JSON envelope as a string.
	 *
	 * @return string
	 */
	public function to_json(): string {
		return (string) json_encode( $this->to_envelope(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Markdown output.
	 *
	 * @param bool $undocumented_only List undocumented constants instead of the catalog.
	 * @return string
	 */
	public function to_markdown( bool $undocumented_only = false ): string {
		if ( $undocumented_only ) {
			$output  = "# Undocumented Newspack constants\n\n";
			$output .= "> Constants guarded with defined() that have no matching @constant docblock.\n\n";
			$output .= "| Constant | Locations |\n|---|---|\n";
			foreach ( $this->get_undocumented() as $constant ) {
				$locations = array_map(
					function ( $location ) {
						return sprintf( '%s:%s:%d', $location['source'], $location['file'], $location['line'] );
					},
					$constant['locations']
				);
				$output .= sprintf( "| `%s` | %s |\n", $constant['name'], implode( '<br>', $locations ) );
			}
			return $output;
		}

		$output  = "# Newspack Constants Reference\n\n";
		$output .= '> Auto-generated from @constant docblocks. Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";
		$output .= "| Constant | Type | Status | Sources |\n|---|---|---|---|\n";
		$documented = $this->get_documented();
		foreach ( $documented as $constant ) {
			$sources = array_unique( array_column( $constant['locations'], 'source' ) );
			$output .= sprintf(
				"| `%s` | %s | %s | %s |\n",
				$constant['name'],
				$constant['type'] ?? '_unknown_',
				$constant['status'] ?? '_unknown_',
				implode( ', ', $sources )
			);
		}
		$output .= "\n";
		foreach ( $documented as $constant ) {
			$output .= sprintf( "## `%s`\n\n", $constant['name'] );
			if ( $constant['description'] ) {
				$output .= $constant['description'] . "\n\n";
			}
			$output .= sprintf( "| | |\n|---|---|\n| **Type** | %s |\n", $constant['type'] ?? '_unknown_' );
			$output .= sprintf( "| **Default** | %s |\n", $constant['default'] ?? '_not documented_' );
			$output .= sprintf( "| **Status** | `%s` |\n", $constant['status'] ?? 'unknown' );
			if ( $constant['example'] ) {
				$output .= sprintf( "| **Example** | `%s` |\n", $constant['example'] );
			}
			$output .= "\n**Locations**\n\n";
			foreach ( $constant['locations'] as $location ) {
				$output .= sprintf( "- `%s` %s:%d\n", $location['source'], $location['file'], $location['line'] );
			}
			$output .= "\n---\n\n";
		}
		return $output;
	}
}
