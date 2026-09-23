<?php
/**
 * Require every NEWSPACK_ constant checked with defined() to be documented.
 *
 * The constants catalog is built by scanning for `defined( 'NEWSPACK_*' )`
 * guards and reading the `@constant` docblock that documents each one. A guard
 * added without a docblock is invisible to the catalog, so the constant exists
 * but nothing records what it does, what it defaults to, or whether it is safe
 * for a publisher to set.
 *
 * @package phpcsSniffs
 */

namespace phpcsSniffs\Sniffs\Constants;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Flags a defined() guard on a NEWSPACK_ constant that no docblock in the same
 * file documents with a matching `@constant` tag.
 */
class ConstantDocblockSniff implements Sniff {

	const ERROR_CODE    = 'Missing';
	const ERROR_MESSAGE = 'Constant %s is checked here but no @constant docblock in this file documents it. Add a docblock with @constant, @type, @default, @status and @example so the constants catalog can pick it up.';

	/**
	 * Constant names documented by the file currently being walked.
	 *
	 * @var string[]
	 */
	private $documented = [];

	/**
	 * Path the $documented list was built from, used to detect when PHPCS has
	 * moved on to the next file. PHPCS reuses one sniff instance for the whole
	 * run, so without this a file would inherit the previous file's docblocks.
	 *
	 * @var string
	 */
	private $current_file = '';

	/**
	 * Tokens this sniff listens for.
	 *
	 * @return array<int|string>
	 */
	public function register() {
		return [ T_STRING ];
	}

	/**
	 * Processes a token, reporting undocumented constant checks.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  Position of the current token in the stack.
	 * @return void
	 */
	public function process( File $phpcs_file, $stack_ptr ) {
		$tokens = $phpcs_file->getTokens();

		// PHP function names are case-insensitive, so Defined() is a real guard.
		if ( 'defined' !== strtolower( $tokens[ $stack_ptr ]['content'] ) ) {
			return;
		}

		$constant = $this->get_guarded_constant( $phpcs_file, $stack_ptr );
		if ( null === $constant ) {
			return;
		}

		if ( $phpcs_file->path !== $this->current_file ) {
			$this->current_file = $phpcs_file->path;
			$this->documented   = $this->collect_documented( $phpcs_file );
		}

		if ( in_array( $constant, $this->documented, true ) ) {
			return;
		}

		$phpcs_file->addError(
			sprintf( self::ERROR_MESSAGE, $constant ),
			$stack_ptr,
			self::ERROR_CODE
		);
	}

	/**
	 * Reads the NEWSPACK_ constant name out of a defined() call.
	 *
	 * Returns null for anything that is not a plain `defined( 'NEWSPACK_*' )`
	 * call on a literal string: a method or class-constant access that merely
	 * ends in `defined`, a call whose argument is a variable or concatenation,
	 * and any constant outside the NEWSPACK_ namespace.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  Position of the T_STRING holding `defined`.
	 * @return string|null The constant name, or null.
	 */
	private function get_guarded_constant( File $phpcs_file, $stack_ptr ) {
		$tokens = $phpcs_file->getTokens();

		// `$obj->defined(...)`, `Foo::defined(...)` and `function defined()`
		// are not the function we are looking for. A leading namespace
		// separator is, though: `\defined( ... )` is the global function.
		$before = $phpcs_file->findPrevious( Tokens::$emptyTokens, $stack_ptr - 1, null, true );
		if ( false !== $before ) {
			$disqualifying = [ T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ];
			if ( in_array( $tokens[ $before ]['code'], $disqualifying, true ) ) {
				return null;
			}
		}

		$open = $phpcs_file->findNext( Tokens::$emptyTokens, $stack_ptr + 1, null, true );
		if ( false === $open || T_OPEN_PARENTHESIS !== $tokens[ $open ]['code'] ) {
			return null;
		}

		$argument = $phpcs_file->findNext( Tokens::$emptyTokens, $open + 1, null, true );
		if ( false === $argument || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $argument ]['code'] ) {
			return null;
		}

		// Reject anything following the literal other than the closing paren,
		// so `defined( 'NEWSPACK_X' . $suffix )` is not read as NEWSPACK_X.
		$after = $phpcs_file->findNext( Tokens::$emptyTokens, $argument + 1, null, true );
		if ( false === $after || T_CLOSE_PARENTHESIS !== $tokens[ $after ]['code'] ) {
			return null;
		}

		$name = trim( $tokens[ $argument ]['content'], "'\"" );

		return preg_match( '/^NEWSPACK_[A-Z0-9_]+$/', $name ) ? $name : null;
	}

	/**
	 * Collects every constant name documented by an `@constant` tag in the file.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @return string[] Constant names.
	 */
	private function collect_documented( File $phpcs_file ) {
		$tokens     = $phpcs_file->getTokens();
		$documented = [];
		$tag        = $phpcs_file->findNext( T_DOC_COMMENT_TAG, 0 );

		while ( false !== $tag ) {
			if ( '@constant' === strtolower( $tokens[ $tag ]['content'] ) ) {
				$value = $phpcs_file->findNext( T_DOC_COMMENT_STRING, $tag + 1, null, false, null, true );
				if ( false !== $value && preg_match( '/^NEWSPACK_[A-Z0-9_]+/', trim( $tokens[ $value ]['content'] ), $matches ) ) {
					$documented[] = $matches[0];
				}
			}
			$tag = $phpcs_file->findNext( T_DOC_COMMENT_TAG, $tag + 1 );
		}

		return $documented;
	}
}
