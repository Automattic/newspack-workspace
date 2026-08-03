<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Class IframeControllerTest
 *
 * @package Newspack_Blocks
 */

/**
 * Tests that the iframe controller keeps every write inside the uploads directory.
 *
 * Both halves of the destination path are caller-influenced: the containing folder is
 * derived from the source attachment's title, and, for archives, each written file is
 * named by an entry inside the archive. Neither is a trustworthy path component.
 */
class IframeControllerTest extends WP_UnitTestCase_Blocks { // phpcs:ignore

	/**
	 * Markers used by a test, swept after each test.
	 *
	 * @var string[]
	 */
	private $markers = [];

	/**
	 * Remove anything a payload managed to write outside the iframe directory.
	 */
	public function tear_down() {
		foreach ( $this->markers as $marker ) {
			foreach ( $this->find_escapes( $marker ) as $path ) {
				if ( is_dir( $path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rmdir
					@rmdir( $path );
				} elseif ( file_exists( $path ) ) {
					unlink( $path );
				}
			}
		}
		$this->markers = [];
		parent::tear_down();
	}

	/**
	 * Register a payload marker and return it.
	 *
	 * @param string $marker Marker string, unique to the test.
	 * @return string The marker.
	 */
	private function use_marker( $marker ) {
		$this->markers[] = $marker;
		return $marker;
	}

	/**
	 * Find anything named after a payload marker that sits outside the iframe directory.
	 *
	 * Asserting on a fixed escape target is fragile: the document and archive branches nest
	 * their destinations at different depths, so the same payload lands in different places.
	 * Searching for the marker instead states the invariant directly — nothing this endpoint
	 * writes may exist outside the iframe upload directory, wherever it ended up.
	 *
	 * @param string $marker Marker string.
	 * @return string[] Paths found outside the iframe directory, deepest first.
	 */
	private function find_escapes( $marker ) {
		$upload_dir  = wp_upload_dir();
		$content_dir = dirname( $upload_dir['basedir'] );

		if ( ! is_dir( $content_dir ) ) {
			return [];
		}

		$found    = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $content_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $path => $unused ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			// Any iframe directory counts as inside, not just the current month's: uploads are
			// filed by month, so a run that crosses a month boundary would otherwise read last
			// month's perfectly contained files as escapes.
			if ( false !== strpos( $path, $marker ) && false === strpos( $path, WP_REST_Newspack_Iframe_Controller::IFRAME_UPLOAD_DIR ) ) {
				$found[] = $path;
			}
		}

		return $found;
	}

	/**
	 * Assert that a payload marker left nothing outside the iframe upload directory.
	 *
	 * @param string $marker  Marker string.
	 * @param string $message Failure message.
	 */
	private function assert_nothing_escaped( $marker, $message ) {
		$this->assertSame( [], $this->find_escapes( $marker ), $message );
	}

	/**
	 * Create an attachment backed by a real file on disk.
	 *
	 * @param string $title     Attachment post title.
	 * @param string $mime_type Attachment MIME type.
	 * @param string $contents  File contents.
	 * @param string $extension File extension, without the dot.
	 * @return int Attachment ID.
	 */
	private function create_attachment( $title, $mime_type, $contents, $extension ) {
		$upload = wp_upload_bits( 'fixture-' . wp_generate_password( 6, false ) . '.' . $extension, null, $contents );
		$this->assertEmpty( $upload['error'], 'Could not stage the fixture upload.' );

		return wp_insert_attachment(
			[
				'post_title'     => $title,
				'post_mime_type' => $mime_type,
				'post_status'    => 'inherit',
			],
			$upload['file']
		);
	}

	/**
	 * Build a .zip whose entries are named verbatim, including any traversal segments.
	 *
	 * @param array $entries Map of entry name to contents.
	 * @return string Absolute path to the archive.
	 */
	private function create_archive( $entries ) {
		$path = get_temp_dir() . 'newspack-blocks-fixture-' . wp_generate_password( 6, false ) . '.zip';
		$zip  = new ZipArchive();
		$this->assertTrue( true === $zip->open( $path, ZipArchive::CREATE ), 'Could not create the fixture archive.' );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();

		return $path;
	}

	/**
	 * Call the media-library import route's callback.
	 *
	 * @param int $media_id Attachment ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private function import_from_media( $media_id ) {
		$request = new WP_REST_Request( 'POST', '/newspack-blocks/v1/newspack-blocks-iframe-archive-from-media' );
		$request->set_body_params( [ 'media_id' => $media_id ] );

		$controller = new WP_REST_Newspack_Iframe_Controller();
		return $controller->import_iframe_archive_from_media_library( $request );
	}

	/**
	 * A document destination is built from the attachment title, so traversal segments in
	 * that title must not move the copy outside the uploads directory.
	 */
	public function test_document_import_keeps_title_derived_path_inside_uploads() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$marker = $this->use_marker( 'newspack-blocks-escaped-document' );

		$media_id = $this->create_attachment(
			'../../../../' . $marker,
			'application/pdf',
			'%PDF-1.4 fixture',
			'pdf'
		);

		$this->import_from_media( $media_id );

		$this->assert_nothing_escaped(
			$marker,
			'An attachment title containing upward path segments placed a file outside the iframe directory.'
		);
	}

	/**
	 * Every archive entry is written using its own name, so an entry naming a path outside
	 * the destination folder must not be honoured.
	 *
	 * Run as an administrator because the archive branch is reachable only to users who
	 * pass the archive-upload capability check.
	 */
	public function test_archive_import_keeps_entry_names_inside_uploads() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$marker = $this->use_marker( 'newspack-blocks-escaped-entry' );

		$archive_path = $this->create_archive(
			[
				'../../../../' . $marker . '.html' => '<html><body>escaped</body></html>',
				'index.html'                       => '<html><body>entrypoint</body></html>',
			]
		);

		$media_id = $this->create_attachment(
			'fixture archive',
			'application/zip',
			(string) file_get_contents( $archive_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			'zip'
		);

		$this->import_from_media( $media_id );

		$this->assert_nothing_escaped(
			$marker,
			'An archive entry naming an upward path wrote a file outside the iframe directory.'
		);
	}

	/**
	 * The archive folder is also title-derived, so a traversing title must not relocate the
	 * extracted archive either.
	 *
	 * This one already holds, but only incidentally: directory creation happens to refuse a
	 * path built with upward segments, so the write fails rather than being rejected. Pinned
	 * here so the guarantee survives the destination path being constructed deliberately.
	 */
	public function test_archive_import_keeps_title_derived_folder_inside_uploads() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$marker = $this->use_marker( 'newspack-blocks-escaped-folder' );

		$archive_path = $this->create_archive( [ 'index.html' => '<html><body>entrypoint</body></html>' ] );

		$media_id = $this->create_attachment(
			'../../../../' . $marker,
			'application/zip',
			(string) file_get_contents( $archive_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			'zip'
		);

		$this->import_from_media( $media_id );

		$this->assert_nothing_escaped(
			$marker,
			'An attachment title containing upward path segments relocated an extracted archive.'
		);
	}

	/**
	 * An ordinary document import still lands where the response says it does.
	 *
	 * The containment assertions above are satisfied just as well by an endpoint that
	 * refuses everything, so this pins that the usual case still works.
	 */
	public function test_document_import_still_succeeds() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$media_id = $this->create_attachment( 'Quarterly report (final)', 'application/pdf', '%PDF-1.4 fixture', 'pdf' );
		$response = $this->import_from_media( $media_id );

		$this->assertNotWPError( $response, 'An ordinary document import was refused.' );

		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;

		$this->assertSame( 'document', $data['mode'] ?? '' );
		$this->assertStringContainsString( WP_REST_Newspack_Iframe_Controller::IFRAME_UPLOAD_DIR, $data['src'] ?? '' );

		$upload_dir = wp_upload_dir();
		$this->assertFileExists(
			str_replace( $upload_dir['url'], $upload_dir['path'], $data['src'] ),
			'The response pointed at a file that was never written.'
		);
	}

	/**
	 * An ordinary archive import still extracts and reports its entrypoint.
	 */
	public function test_archive_import_still_succeeds() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$archive_path = $this->create_archive( [ 'index.html' => '<html><body>entrypoint</body></html>' ] );
		$media_id     = $this->create_attachment(
			'Election results',
			'application/zip',
			(string) file_get_contents( $archive_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			'zip'
		);

		$response = $this->import_from_media( $media_id );

		$this->assertNotWPError( $response, 'An ordinary archive import was refused.' );

		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;

		$this->assertSame( 'iframe', $data['mode'] ?? '' );

		$upload_dir = wp_upload_dir();
		$this->assertFileExists(
			str_replace( $upload_dir['url'], $upload_dir['path'], $data['src'] ) . WP_REST_Newspack_Iframe_Controller::IFRAME_ENTRY_FILE,
			'The extracted archive has no entrypoint where the response says it is.'
		);
	}

	/**
	 * Archive handling stays behind the archive-upload capability check.
	 *
	 * Archives may carry unfiltered HTML/CSS/JS, so they are gated more tightly than single
	 * documents. This is a guard against that gate being widened by accident.
	 */
	public function test_archive_import_requires_the_archive_upload_capability() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );

		$this->assertFalse(
			WP_REST_Newspack_Iframe_Controller::can_upload_archives(),
			'An author should not pass the archive-upload capability check.'
		);

		$archive_path = $this->create_archive( [ 'index.html' => '<html><body>entrypoint</body></html>' ] );
		$media_id     = $this->create_attachment(
			'fixture archive',
			'application/zip',
			(string) file_get_contents( $archive_path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			'zip'
		);

		$this->assertWPError(
			$this->import_from_media( $media_id ),
			'An author was able to import an archive.'
		);
	}
}
