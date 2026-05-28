<?php
/**
 * WP_REST_Newspack_Iframe_Controller file.
 *
 * @package WordPress
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

/**
 * Class WP_REST_Newspack_Iframe_Controller.
 */
class WP_REST_Newspack_Iframe_Controller extends WP_REST_Controller {
	const IFRAME_UPLOAD_DIR = '/newspack_iframes/';
	const IFRAME_ENTRY_FILE = 'index.html';

	/**
	 * Constructs the controller.
	 *
	 * @access public
	 */
	public function __construct() {
		$this->namespace = 'newspack-blocks/v1';
	}

	/**
	 * Registers the necessary REST API routes.
	 *
	 * @access public
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/newspack-blocks-iframe-archive',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'import_iframe_from_uploaded_file' ],
					'permission_callback' => function() {
						return current_user_can( 'edit_posts' );
					},
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/newspack-blocks-iframe-archive-from-media',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'import_iframe_archive_from_media_library' ],
					'permission_callback' => function() {
						return current_user_can( 'edit_posts' );
					},
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/newspack-blocks-iframe-mode-from-url',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'get_iframe_mode_from_url' ],
					'permission_callback' => function() {
						return current_user_can( 'edit_posts' );
					},
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/newspack-blocks-remove-iframe-archive',
			[
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove_iframe_archive' ],
					'permission_callback' => [ $this, 'can_upload_archives' ],
				],
			]
		);
	}

	/**
	 * Possible mimes for iframe document source file.
	 *
	 * @return array
	 */
	private static function iframe_document_accepted_file_mimes() {
		$mimes = get_allowed_mime_types();
		return [
			$mimes['pdf']             => 'pdf',
			$mimes['doc']             => 'doc',
			$mimes['docx']            => 'docx',
			$mimes['xla|xls|xlt|xlw'] => 'xls',
			$mimes['xlsx']            => 'xlsx',
			$mimes['pot|pps|ppt']     => 'ppt',
			$mimes['pptx']            => 'pptx',
		];
	}

	/**
	 * Does the current user have enough capabilities to upload archives?
	 * Archives can contain unfiltered HTML/JS/CS files, so they require a higher level of capabilities than single file uploads.
	 *
	 * @return boolean
	 */
	public static function can_upload_archives() {
		/**
		 * Filters whether the current user is allowed to upload archives.
		 *
		 * @param boolean $can_upload_archives Whether the current user is allowed to upload archives.
		 * @param WP_User $user The current user.
		 */
		return apply_filters( 'newspack_blocks_iframe_can_upload_archives', current_user_can( 'manage_options' ), wp_get_current_user() );
	}

	/**
	 * Possible mimes for HTML-based embeds (only if contained in a zip).
	 *
	 * @return array
	 */
	private static function iframe_html_accepted_file_mimes() {
		$mimes = wp_get_mime_types();

		// Allow HTML/CSS/JS.
		$allowed_types = [
			$mimes['htm|html']           => 'html',
			$mimes['css']                => 'css',
			$mimes['js']                 => 'js',
			$mimes['txt|asc|c|cc|h|srt'] => 'css|js',
		];

		// Allow audio/image/video files.
		foreach ( $mimes as $key => $type ) {
			$prefix = explode( '/', $type )[0];
			if ( in_array( $prefix, [ 'audio', 'image', 'video' ], true ) ) {
				$allowed_types[ $type ] = $key;
			}
		}

		return $allowed_types;
	}

	/**
	 * Possible mimes for iframe archive source file.
	 *
	 * @return array
	 */
	private static function iframe_archive_accepted_file_mimes() {
		if ( ! self::can_upload_archives() ) {
			return [];
		}

		return [ 'application/zip' => 'zip' ];
	}

	/**
	 * Possible mimes for iframe source file.
	 *
	 * @param boolean $in_archive If true, the file is inside an archive, which can contain HTML/CSS/JS files.
	 */
	public static function iframe_accepted_file_mimes( $in_archive = false ) {
		$accepted_files = array_merge(
			self::iframe_archive_accepted_file_mimes(),
			self::iframe_document_accepted_file_mimes()
		);

		// Allow unfiltered HTML if it's inside an archive.
		if ( $in_archive ) {
			$accepted_files = array_merge( $accepted_files, self::iframe_html_accepted_file_mimes() );
		}

		return $accepted_files;
	}

	/**
	 * Ensure an uploaded file is a valid type before allowing upload.
	 *
	 * @param array $file_info File info.
	 *
	 * @return string|false The file's verified MIME type if allowed, or false if not allowed.
	 */
	private function validate_file( $file_info ) {
		// Verify that the declared MIME type is allowed.
		if ( ! in_array( $file_info['type'], array_keys( self::iframe_accepted_file_mimes() ), true ) ) {
			return false;
		}

		// Ensure the file is actually a file uploaded via HTTP POST.
		$tmp_file = $file_info['tmp_name'];
		if ( ! is_uploaded_file( $tmp_file ) ) {
			return false;
		}

		// Compare the detected MIME type with the declared one and reject if they mismatch.
		$true_mime = mime_content_type( $tmp_file );
		if ( $true_mime !== $file_info['type'] ) {
			return false;
		}

		return $true_mime;
	}

	/**
	 * Validate the file extension of a file.
	 *
	 * @param string  $file_name The name of the file.
	 * @param boolean $in_archive If true, the file is inside an archive, which can contain HTML/CSS/JS files.
	 *
	 * @return boolean
	 */
	private function validate_file_extension( $file_name, $in_archive = false ) {
		$file_extension = pathinfo( $file_name, PATHINFO_EXTENSION );
		return ! empty( $file_extension ) && in_array( $file_extension, array_values( self::iframe_accepted_file_mimes( $in_archive ) ), true );
	}

	/**
	 * Validate the raw contents of a file inside a .zip archive.
	 *
	 * @param string $contents The contents of the file.
	 *
	 * @return string|false The file's verified MIME type if allowed, or false if not allowed.
	 */
	private function validate_archive_file_contents( $contents ) {
		$finfo  = new finfo( FILEINFO_MIME_TYPE );
		$mime_type = $finfo->buffer( $contents );
		if ( ! in_array( $mime_type, array_keys( self::iframe_accepted_file_mimes( true ) ), true ) ) {
			return false;
		}

		return $mime_type;
	}

	/**
	 * Check whether the given mime_type is a valid archive file type.
	 *
	 * @param string $mime_type The mime type to check.
	 *
	 * @return boolean
	 */
	private function is_archive( $mime_type ) {
		return in_array( $mime_type, array_keys( self::iframe_archive_accepted_file_mimes() ), true );
	}

	/**
	 * Receive the iframe content, handle it, and returns the URL and the folder name.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function import_iframe_from_uploaded_file( $request ) {
		$response = [];
		$files    = $request->get_file_params();

		if ( count( $files ) > 0 && array_key_exists( 'iframe_file', $files ) ) {
			$iframe_file   = $files['iframe_file'];
			$iframe_folder = pathinfo( $iframe_file['name'] )['filename'] . '-' . wp_generate_password( 8, false );
			$is_valid_file = $this->validate_file( $iframe_file );

			if ( $this->is_archive( $is_valid_file ) ) {
				$response = $this->process_iframe_archive( $request, $iframe_folder, $iframe_file['tmp_name'] );
			} elseif ( $is_valid_file ) {
				$file_extension = pathinfo( $iframe_file['name'], PATHINFO_EXTENSION );
				$response       = $this->process_iframe_document( $request, "$iframe_folder.$file_extension", $iframe_file['tmp_name'] );
			} else {
				$response = new WP_Error(
					'newspack_blocks',
					sprintf(
						// Translators: %s is a list of supported file extensions for uploading.
						__( "Unsupported filetype. Please make sure it's one of the supported filetypes: % s", 'newspack-blocks' ),
						implode( ', ', array_values( self::iframe_document_accepted_file_mimes() ) )
					),
					[ 'status' => '400' ]
				);
			}
		} else {
			$response = new WP_Error(
				'newspack_blocks',
				__( 'Could not upload this file.', 'newspack-blocks' ),
				[ 'status' => '400' ]
			);
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Receive the iframe archive, unzip it, and returns the URL and the folder name.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function import_iframe_archive_from_media_library( $request ) {
		$response = [];
		$data     = $request->get_body_params();

		if ( array_key_exists( 'media_id', $data ) ) {
			$media      = get_post( $data['media_id'] );
			$media_path = get_attached_file( $data['media_id'] );

			if ( $media_path && in_array( $media->post_mime_type, array_keys( self::iframe_document_accepted_file_mimes() ), true ) ) {
				$iframe_folder  = $media->post_title . '-' . wp_generate_password( 8, false );
				$file_extension = self::iframe_document_accepted_file_mimes()[ $media->post_mime_type ];

				$response = $this->process_iframe_document( $request, "$iframe_folder.$file_extension", $media_path );
			} elseif ( $media_path && in_array( $media->post_mime_type, array_keys( self::iframe_archive_accepted_file_mimes() ), true ) ) {
				$iframe_folder = $media->post_title . '-' . wp_generate_password( 8, false );

				$response = $this->process_iframe_archive( $request, $iframe_folder, $media_path );
			} else {
				$response = new WP_Error(
					'newspack_blocks',
					__( 'Please choose a valid (.zip) assets archive.', 'newspack-blocks' ),
					[ 'status' => '400' ]
				);
			}
		} else {
			$response = new WP_Error(
				'newspack_blocks',
				__( 'Please choose a valid (.zip) assets archive.', 'newspack-blocks' ),
				[ 'status' => '400' ]
			);
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Receive the iframe content, handle it, and returns the URL and the folder name.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_iframe_mode_from_url( $request ) {
		$data = $request->get_body_params();
		$mode = 'iframe';

		// If we need to remove the previous document, it's a good place to do it here.
		if ( array_key_exists( 'iframe_url', $data ) && ! empty( $data['iframe_url'] ) ) {
			$head = wp_safe_remote_head( $data['iframe_url'] );
			if ( is_wp_error( $head ) ) {
				return rest_ensure_response( $head );
			}
			$content_type = $head['headers']->offsetGet( 'content-type' );
			if ( ! empty( $content_type ) && in_array( $content_type, array_keys( self::iframe_document_accepted_file_mimes() ), true ) ) {
				$mode = 'document';
			}
		}

		return rest_ensure_response( [ 'mode' => $mode ] );
	}

	/**
	 * Process iframe source from uploaded document file or from media file.
	 *
	 * @param WP_REST_REQUEST $request Request object.
	 * @param string          $document_filename Document filename.
	 * @param string          $media_source_path iframe assets zip file path.
	 * @return WP_REST_Response
	 */
	private function process_iframe_document( $request, $document_filename, $media_source_path ) {
		if ( ! $this->validate_file_extension( $document_filename, false ) ) {
			return new WP_Error(
				'newspack_blocks',
				sprintf(
					// Translators: %s is a list of supported file extensions for uploading.
					__( "Unsupported filetype. Please make sure it's one of the supported filetypes: % s", 'newspack-blocks' ),
					implode( ', ', array_values( self::iframe_document_accepted_file_mimes() ) )
				),
				[ 'status' => '400' ]
			);
		}

		$wp_upload_dir     = wp_upload_dir();
		$iframe_upload_dir = $wp_upload_dir['path'] . self::IFRAME_UPLOAD_DIR;
		$iframe_path       = $iframe_upload_dir . $document_filename;
		$data              = $request->get_body_params();

		// Create iframe directory if not existing.
		if ( ! file_exists( $iframe_upload_dir ) ) {
			wp_mkdir_p( $iframe_upload_dir );
		}

		// If we need to remove the previous document, it's a good place to do it here.
		if ( array_key_exists( 'archive_folder', $data ) && ! empty( $data['archive_folder'] ) ) {
			$deleted = $this->remove_folder( $data['archive_folder'] );
			if ( is_wp_error( $deleted ) ) {
				return $deleted;
			}
		}

		// Copy uploaded document file to destination.
		if ( copy( $media_source_path, $iframe_path ) ) {
			return [
				'mode' => 'document',
				'src'  => $wp_upload_dir['url'] . self::IFRAME_UPLOAD_DIR . $document_filename,
				'dir'  => path_join( $wp_upload_dir['subdir'] . self::IFRAME_UPLOAD_DIR, $document_filename ),
			];
		}

		return new WP_Error(
			'newspack_blocks',
			__( 'Could not upload your document.', 'newspack-blocks' ),
			[ 'status' => '400' ]
		);
	}

	/**
	 * Process iframe archive from uploaded zip or from media file.
	 *
	 * @param WP_REST_REQUEST $request Request object.
	 * @param string          $iframe_folder where to extract the iframe assets.
	 * @param string          $media_path iframe assets zip file path.
	 * @return WP_REST_Response
	 */
	private function process_iframe_archive( $request, $iframe_folder, $media_path ) {
		$invalid_file_error = new WP_Error(
			'newspack_blocks',
			sprintf(
					// Translators: %s is a list of supported file extensions for uploading.
				__( "Error reading the archive. Please make sure it's a valid .zip archive file and contains only supported filetypes: %s", 'newspack-blocks' ),
				implode( ', ', array_values( self::iframe_document_accepted_file_mimes() ) )
			),
			[ 'status' => '400' ]
		);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return $invalid_file_error;
		}
		global $wp_filesystem;

		$wp_upload_dir     = wp_upload_dir();
		$iframe_upload_dir = $wp_upload_dir['path'] . self::IFRAME_UPLOAD_DIR;
		$iframe_path       = trailingslashit( $iframe_upload_dir . $iframe_folder );
		$data              = $request->get_body_params();

		// create iframe directory if not existing.
		if ( ! file_exists( $iframe_upload_dir ) ) {
			wp_mkdir_p( $iframe_upload_dir );
		}

		// Extract files from archive.
		$zip    = new ZipArchive();
		$opened = $zip->open( $media_path );
		if ( ! $opened ) {
			return $invalid_file_error;
		}

		$contains_valid_files = false;

		// Validate all files inside the archive. Throw error if we encounter any unsupported files.
		for ( $file_index = 0; $file_index < $zip->numFiles; $file_index++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( ! $this->validate_file_extension( $zip->getNameIndex( $file_index ), true ) ) {
				return $invalid_file_error;
			}
			$contents = $zip->getFromIndex( $file_index );
			if ( ! $this->validate_archive_file_contents( $contents ) ) {
				return $invalid_file_error;
			}
			$stat = $zip->statIndex( $file_index );
			if ( $stat && isset( $stat['name'] ) ) {
				if ( ! file_exists( dirname( $iframe_path . $stat['name'] ) ) ) {
					wp_mkdir_p( dirname( $iframe_path . $stat['name'] ) );
				}
				$put = $wp_filesystem->put_contents( $iframe_path . $stat['name'], $contents );
				if ( ! $put ) {
					return $invalid_file_error;
				}
				$contains_valid_files = true;
			}
		}

		$zip->close();

		// If we didn't extract any files, raise an error.
		if ( ! $contains_valid_files ) {
			return $invalid_file_error;
		}

		// Check for required entrypoint file.
		$directory    = new \RecursiveDirectoryIterator( $iframe_path );
		$entry_path = false;
		foreach ( new \RecursiveIteratorIterator( $directory ) as $file ) {
			if ( $file->getFilename() === self::IFRAME_ENTRY_FILE ) {
				// Convert absolute path to URL.
				$entry_path = str_replace( $wp_upload_dir['path'], $wp_upload_dir['url'], trailingslashit( dirname( $file->getFileInfo() ) ) );
				break;
			}
		}

		// If we found the entrypoint file, return the iframe URL and folder.
		if ( $entry_path ) {
			// Remove previous version if it exists.
			if ( array_key_exists( 'archive_folder', $data ) && ! empty( $data['archive_folder'] ) ) {
				$deleted = $this->remove_folder( $data['archive_folder'] );
				if ( is_wp_error( $deleted ) ) {
					return $deleted;
				}
			}

			$response = [
				'mode' => 'iframe',
				'src'  => $entry_path,
				'dir'  => trailingslashit( path_join( $wp_upload_dir['subdir'] . self::IFRAME_UPLOAD_DIR, $iframe_folder ) ),
			];
		} else {
			// If we can't find the entrypoint file, remove the uploaded archive data and raise an error.
			$this->remove_folder( $wp_upload_dir['subdir'] . self::IFRAME_UPLOAD_DIR . $iframe_folder );
			return new WP_Error(
				'newspack_blocks',
				/* translators: %s: iframe entry file (e.g. index,html) */
				sprintf( __( 'Required %s entrypoint file was not found in the archive.', 'newspack-blocks' ), self::IFRAME_ENTRY_FILE ),
				[ 'status' => '400' ]
			);
		}

		return $response;
	}

	/**
	 * Delete iframe archive, this is called when changing the iframe source.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function remove_iframe_archive( $request ) {
		$data     = $request->get_json_params();
		$response = [];

		if ( array_key_exists( 'archive_folder', $data ) && ! empty( $data['archive_folder'] ) ) {
			$deleted = $this->remove_folder( $data['archive_folder'] );
			if ( is_wp_error( $deleted ) ) {
				$response = $deleted;
			}
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Remove a folder. Called to remove the iframe archive folder.
	 *
	 * @param string $folder folder path to remove.
	 * @return boolean|WP_Error True if the folder was removed, false if it was not, or a WP_Error if there was an error.
	 */
	private function remove_folder( $folder ) {
		$deleted = false;
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . '/wp-admin/includes/class-wp-filesystem-direct.php';
		}

		// Ensure that the folder path is inside the uploads directory.
		$wp_upload_dir  = wp_upload_dir();
		$wp_upload_path = realpath( $wp_upload_dir['basedir'] );
		$folder_path    = realpath( $folder );
		if ( ! $folder_path || strpos( $folder_path, $wp_upload_path ) !== 0 ) {
			return new WP_Error(
				'newspack_blocks',
				__( 'Could not delete the directory. Invalid path.', 'newspack-blocks' ),
				[ 'status' => '400' ]
			);
		}

		// Ensure the folder path matches the /year/month/upload-dir/ structure.
		$folder_path_regex = '/^\/\d{4}\/\d{2}' . preg_quote( self::IFRAME_UPLOAD_DIR, '/' ) . '/';
		$can_delete = preg_match( $folder_path_regex, $folder );
		if ( $can_delete ) {
			$file_system = new WP_Filesystem_Direct( false );
			$deleted = $file_system->rmdir( $wp_upload_path . $folder, true );
		}
		if ( ! $deleted ) {
			return new WP_Error(
				'newspack_blocks',
				__( 'Could not delete the directory.', 'newspack-blocks' ),
				[ 'status' => '400' ]
			);
		}
		return $deleted;
	}
}
