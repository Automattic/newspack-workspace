<?php
/**
 * Forbid newsletter-internal code outside the public-API classes from
 * reaching past Newspack_Newsletters_Contacts.
 *
 * Service-provider directories are exempt — the integrations live there
 * and have to call provider methods to do their job.
 *
 * @package phpcsSniffs
 */

namespace phpcsSniffs\Sniffs\Newsletters;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class ForbiddenContactsMethodsSniff implements Sniff {

	const ERROR_CODE    = 'ForbiddenContactsMethods';
	const ERROR_MESSAGE = 'Method %s is reserved for internal use and should not be called from this scope. Use methods in Newspack_Newsletters_Contacts class instead.';

	/**
	 * Methods that should not be called from outside the allowed scopes.
	 *
	 * @var string[]
	 */
	private $methods = [
		'add_contact',
		'add_esp_local_list_to_contact',
		'remove_esp_local_list_from_contact',
		'add_tag_to_contact',
		'remove_tag_from_contact',
		'update_contact_lists_handling_local',
		'add_contact_with_groups_and_tags',
		'add_contact_to_provider',
		'update_contact_lists',
		'upsert_contact',
	];

	/**
	 * Classes that own the public API and are therefore allowed to call
	 * the internal methods above.
	 *
	 * @var string[]
	 */
	private $allowed_classes = [
		'Newspack_Newsletters_Subscription',
		'Newspack_Newsletters_Contacts',
	];

	/**
	 * The class currently being walked.
	 *
	 * @var string
	 */
	private $current_class = '';

	public function register() {
		return [ T_CLASS, T_STRING ];
	}

	public function process( File $phpcs_file, $stack_ptr ) {
		// Service-provider classes legitimately call internal methods.
		$path_parts = explode( DIRECTORY_SEPARATOR, $phpcs_file->path );
		$count      = count( $path_parts );
		$ancestors  = [
			$path_parts[ $count - 2 ] ?? '',
			$path_parts[ $count - 3 ] ?? '',
		];
		if ( in_array( 'service-providers', $ancestors, true ) ) {
			return;
		}

		$tokens = $phpcs_file->getTokens();
		$token  = $tokens[ $stack_ptr ];

		if ( T_CLASS === $token['code'] ) {
			$this->current_class = $tokens[ $stack_ptr + 2 ]['content'] ?? '';
			return;
		}

		if ( ! in_array( $token['content'], $this->methods, true ) ) {
			return;
		}

		$operator = $tokens[ $stack_ptr - 1 ];
		if ( 'T_DOUBLE_COLON' !== $operator['type'] && 'T_OBJECT_OPERATOR' !== $operator['type'] ) {
			return;
		}

		if ( in_array( $this->current_class, $this->allowed_classes, true ) ) {
			return;
		}

		$method_name = $tokens[ $stack_ptr - 2 ]['content']
			. $tokens[ $stack_ptr - 1 ]['content']
			. $token['content'] . '()';

		$phpcs_file->addError(
			sprintf( self::ERROR_MESSAGE, $method_name ),
			$stack_ptr,
			self::ERROR_CODE
		);
	}
}
