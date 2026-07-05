/**
 * Ambient declarations for the `co-authors-plus` entry. Global-script form
 * (no top-level imports/exports) so every declaration lands in the global scope.
 */

/**
 * Data localized by Guest_Contributor_Role (includes/plugins/co-authors-plus/class-guest-contributor-role.php).
 */
declare const guestAuthorRole: {
	/** The non-editing contributor role slug. */
	role: string;
	/** Label replacing "Username" for guest authors on the new-user screen. */
	displayNameLabel: string;
	/** 'new' on user-new.php, 'edit' otherwise. */
	screen: string;
};

/**
 * jQuery surface used by this entry, merged into the shared minimal typing
 * (src/shared/globals.d.ts).
 */
interface NewspackJQuery {
	/** Indexed access to the underlying elements, e.g. `$( 'label' )[ 0 ]`. */
	[ index: number ]: HTMLElement;
	change( handler: ( this: HTMLElement ) => void ): NewspackJQuery;
	change(): NewspackJQuery;
	parents( selector: string ): NewspackJQuery;
	ready( handler: ( $: NewspackJQueryStatic ) => void ): NewspackJQuery;
}
