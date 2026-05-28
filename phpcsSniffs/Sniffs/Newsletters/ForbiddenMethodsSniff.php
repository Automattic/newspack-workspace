<?php
/**
 * Forbid external callers from reaching past Newspack_Newsletters_Contacts
 * to invoke internal newsletter-provider methods directly.
 *
 * @package phpcsSniffs
 */

namespace phpcsSniffs\Sniffs\Newsletters;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class ForbiddenMethodsSniff implements Sniff {

	const ERROR_CODE      = 'ForbiddenContactsMethods';
	const ERROR_MESSAGE   = 'Method %s is reserved for internal use and should not be called from this scope. Use methods in Newspack_Newsletters_Contacts class instead to manipulate contacts.';
	const WARNING_CODE    = 'PossibleForbiddenContactsMethods';
	const WARNING_MESSAGE = 'Possible forbidden Newsletters method detected. Method %s from the email provider classes is reserved for internal use and should not be called from this scope. Use methods in Newspack_Newsletters_Contacts class instead to manipulate contacts.';

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
		'update_contact_local_lists',
		'update_contact_lists_handling_local',
		'add_contact_handling_local_list',
		'add_contact_with_groups_and_tags',
		'update_contact_lists',
		'delete_user_subscription',
	];

	/**
	 * Static class targets whose forbidden methods escalate to an error.
	 *
	 * @var string[]
	 */
	private $static_classes = [
		'Newspack_Newsletters_Subscription',
	];

	public function register() {
		return [ T_STRING ];
	}

	public function process( File $phpcs_file, $stack_ptr ) {
		$tokens = $phpcs_file->getTokens();
		$token  = $tokens[ $stack_ptr ];

		if ( ! in_array( $token['content'], $this->methods, true ) ) {
			return;
		}

		$operator = $tokens[ $stack_ptr - 1 ];

		if ( 'T_DOUBLE_COLON' === $operator['type'] ) {
			$class_name = $tokens[ $stack_ptr - 2 ]['content'];
			if ( in_array( $class_name, $this->static_classes, true ) ) {
				$method_name = $class_name . '::' . $token['content'] . '()';
				$phpcs_file->addError(
					sprintf( self::ERROR_MESSAGE, $method_name ),
					$stack_ptr,
					self::ERROR_CODE
				);
			}
		} elseif ( 'T_OBJECT_OPERATOR' === $operator['type'] ) {
			$phpcs_file->addWarning(
				sprintf( self::WARNING_MESSAGE, $token['content'] ),
				$stack_ptr,
				self::WARNING_CODE
			);
		}
	}
}
