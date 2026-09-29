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
 * DUPLICATED IN newspack-manager, at the same path. That repository lints with
 * its own composer dependencies and cannot reference this directory, so it
 * carries a copy rather than installing one. Change both together. The sniff
 * is small and changes rarely; if that stops being true, split it into a
 * `phpcodesniffer-standard` package and require it from both instead.
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
	 * Class constants declared in the file currently being walked that hold a
	 * NEWSPACK_ constant name, keyed by the class constant's own name.
	 *
	 * @var string[]
	 */
	private $class_constants = [];

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

		if ( $phpcs_file->path !== $this->current_file ) {
			$this->current_file    = $phpcs_file->path;
			$this->documented      = $this->collect_documented( $phpcs_file );
			$this->class_constants = $this->collect_class_constants( $phpcs_file );
		}

		$constant = $this->get_guarded_constant( $phpcs_file, $stack_ptr );
		if ( null === $constant ) {
			return;
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
		if ( false === $argument ) {
			return null;
		}

		// A guard can name its constant through a class constant rather than a
		// literal: `defined( self::FEATURE_FLAG_NAME )`. Resolve it against the
		// class constants declared in this file, so the flags written that way
		// are covered rather than silently exempt.
		$scopes = [ T_SELF, T_STATIC, T_PARENT, T_STRING ];
		if ( in_array( $tokens[ $argument ]['code'], $scopes, true ) ) {
			$operator = $phpcs_file->findNext( Tokens::$emptyTokens, $argument + 1, null, true );
			if ( false === $operator || T_DOUBLE_COLON !== $tokens[ $operator ]['code'] ) {
				return null;
			}
			$member = $phpcs_file->findNext( Tokens::$emptyTokens, $operator + 1, null, true );
			if ( false === $member || T_STRING !== $tokens[ $member ]['code'] ) {
				return null;
			}
			$closer = $phpcs_file->findNext( Tokens::$emptyTokens, $member + 1, null, true );
			if ( false === $closer || T_CLOSE_PARENTHESIS !== $tokens[ $closer ]['code'] ) {
				return null;
			}
			return $this->class_constants[ $tokens[ $member ]['content'] ] ?? null;
		}

		if ( T_CONSTANT_ENCAPSED_STRING !== $tokens[ $argument ]['code'] ) {
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
	 * Maps the class constants in a file that hold a NEWSPACK_ constant name.
	 *
	 * Only a plain string literal counts, so `const FLAG = self::PREFIX . '_X';`
	 * resolves to nothing rather than to a guessed name. Keyed by the class
	 * constant's own name, which is what the guard writes.
	 *
	 * This mirrors collect_class_constants() in
	 * bin/class-newspack-constants-scanner.php; the two read the same shape and
	 * should be changed together.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @return string[] Class constant name => NEWSPACK_ constant name.
	 */
	private function collect_class_constants( File $phpcs_file ) {
		$tokens = $phpcs_file->getTokens();
		$map    = [];
		$const  = $phpcs_file->findNext( T_CONST, 0 );

		while ( false !== $const ) {
			$name = $phpcs_file->findNext( Tokens::$emptyTokens, $const + 1, null, true );
			if ( false !== $name && T_STRING === $tokens[ $name ]['code'] ) {
				$equal = $phpcs_file->findNext( Tokens::$emptyTokens, $name + 1, null, true );
				if ( false !== $equal && T_EQUAL === $tokens[ $equal ]['code'] ) {
					$value = $phpcs_file->findNext( Tokens::$emptyTokens, $equal + 1, null, true );
					if ( false !== $value && T_CONSTANT_ENCAPSED_STRING === $tokens[ $value ]['code'] ) {
						// The declaration has to end right after the literal, so
						// a concatenation is not read as the whole value.
						$after = $phpcs_file->findNext( Tokens::$emptyTokens, $value + 1, null, true );
						$ends  = false !== $after && in_array( $tokens[ $after ]['code'], [ T_SEMICOLON, T_COMMA ], true );
						$held  = trim( $tokens[ $value ]['content'], "'\"" );
						if ( $ends && preg_match( '/^NEWSPACK_[A-Z0-9_]+$/', $held ) && ! isset( $map[ $tokens[ $name ]['content'] ] ) ) {
							$map[ $tokens[ $name ]['content'] ] = $held;
						}
					}
				}
			}
			$const = $phpcs_file->findNext( T_CONST, $const + 1 );
		}

		return $map;
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
