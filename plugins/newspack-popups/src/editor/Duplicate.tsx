/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { Button, Flex, Modal, Notice, TextControl } from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { withSelect, withDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

/**
 * External dependencies
 */
import type { ComponentType } from 'react';

/**
 * Internal dependencies
 */
import type { PromptMeta } from './utils';

/** Props injected by the `withSelect`/`withDispatch` HOCs below. */
interface DuplicateButtonProps {
	autosave: () => Promise< void >;
	campaignGroups?: unknown[];
	duplicateOf?: number;
	isSavingPost: boolean;
	postId: number;
	title: string;
}

const DuplicateButton = ( { autosave, campaignGroups, duplicateOf, isSavingPost, postId, title }: DuplicateButtonProps ) => {
	const [ error, setError ] = useState< string | null >( null );
	const [ modalVisible, setModalVisible ] = useState( false );
	const [ duplicateTitle, setDuplicateTitle ] = useState< string | null >( null );
	const [ duplicated, setDuplicated ] = useState< number | null >( null );

	useEffect( () => {
		setError( null );
		if ( modalVisible && ! duplicateTitle ) {
			getDefaultDupicateTitle();
		}
		if ( ! modalVisible ) {
			setDuplicated( null );
			setDuplicateTitle( null );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ modalVisible ] );

	const getDefaultDupicateTitle = async () => {
		const promptToDuplicate = parseInt( String( duplicateOf || postId ) );
		try {
			const defaultTitle = await apiFetch< string >( {
				path: `/newspack-popups/v1/${ promptToDuplicate }/${ postId }/duplicate`,
			} );

			setDuplicateTitle( defaultTitle );
		} catch ( e ) {
			setDuplicateTitle( title + __( 'copy', 'newspack-popups' ) );
		}
	};

	const duplicatePrompt = async ( popupId: number, titleForDuplicate: string ) => {
		setError( null );
		try {
			const newId = await apiFetch< number >( {
				path: addQueryArgs( `/newspack-popups/v1/${ popupId }/duplicate`, {
					title: titleForDuplicate,
				} ),
				method: 'POST',
			} );

			if ( isNaN( newId ) ) {
				throw new Error( __( 'Error duplicating prompt.', 'newspack-popups' ) );
			}

			// Redirect to edit page for the copy.
			setDuplicated( newId );
		} catch ( e ) {
			setError( ( e as Error )?.message || __( 'Error duplicating prompt.', 'newspack-popups' ) );
		}
	};

	return (
		<>
			<Button isSecondary isBusy={ isSavingPost } disabled={ isSavingPost } onClick={ () => setModalVisible( true ) }>
				{ __( 'Duplicate', 'newspack-popups' ) }
			</Button>
			{ modalVisible && (
				<Modal
					className="newspack-popups__duplicate-modal"
					// translators: %s: title of the duplicated popup.
					title={ sprintf( __( 'Duplicate “%s”', 'newspack-popups' ), title ) }
					onRequestClose={ () => setModalVisible( false ) }
				>
					{ error && (
						<Notice isDismissible={ false } status="error">
							{ error }
						</Notice>
					) }
					{ duplicated ? (
						<>
							<Notice status="success" isDismissible={ false }>
								{ sprintf(
									// translators: %s: title of the duplicated popup.
									__( 'Duplicate of “%s” created as a draft.', 'newspack-popups' ),
									title
								) }
							</Notice>
							{ ( ! campaignGroups || 0 === campaignGroups.length ) && (
								<Notice status="warning" isDismissible={ false }>
									{ __( 'This prompt is currently not assigned to any campaign.', 'newspack-popups' ) }
								</Notice>
							) }
							<Flex justify="flex-end">
								<Button isSecondary onClick={ () => setModalVisible( false ) }>
									{ __( 'Close', 'newspack-popups' ) }
								</Button>
								<Button isPrimary href={ `/wp-admin/post.php?post=${ duplicated }&action=edit` }>
									{ __( 'Edit', 'newspack-popups' ) }
								</Button>
							</Flex>
						</>
					) : (
						<>
							{ ( ! campaignGroups || 0 === campaignGroups.length ) && (
								<Notice status="warning" isDismissible={ false }>
									{ __( 'This prompt will not be assigned to any campaign.', 'newspack-popups' ) }
								</Notice>
							) }
							<TextControl
								disabled={ isSavingPost || null === duplicateTitle }
								label={ __( 'Title', 'newspack-popups' ) }
								value={ duplicateTitle ?? '' }
								onChange={ value => setDuplicateTitle( value ) }
							/>
							<Flex justify="flex-end">
								<Button
									isBusy={ isSavingPost }
									isSecondary
									onClick={ () => {
										setModalVisible( false );
									} }
								>
									{ __( 'Cancel', 'newspack-popups' ) }
								</Button>
								<Button
									isBusy={ isSavingPost }
									disabled={ null === duplicateTitle }
									isPrimary
									onClick={ () => {
										const titleForDuplicate = duplicateTitle!.trim() || title + __( 'copy', 'newspack-popups' );
										// Pre-existing bug (not introduced by this migration, left as-is): the
										// original JS called `autosave().then( duplicatePrompt( postId, titleForDuplicate ) )`.
										// `duplicatePrompt(...)` was invoked immediately as the *argument
										// expression* passed to `.then()`, not wrapped in a callback, so it never
										// actually waited for the autosave to finish before duplicating -- defeating
										// the intended "autosave, then duplicate" order. Reproduced verbatim (same
										// call order, same lack of sequencing) rather than fixed.
										void autosave();
										duplicatePrompt( postId, titleForDuplicate );
									} }
								>
									{ __( 'Duplicate', 'newspack-popups' ) }
								</Button>
							</Flex>
						</>
					) }
				</Modal>
			) }
		</>
	);
};

// compose() is loosely typed (its result takes and returns `unknown`), so the
// composed component is asserted as ComponentType at the trailing boundary.
// Passed as separate arguments (rather than the original single-array form) to match
// compose()'s declared variadic signature -- its real implementation `.flat()`s its
// arguments either way, so this is not a behavior change.
export default compose(
	withSelect( select => {
		const { isSavingPost, getCurrentPostId, getEditedPostAttribute } = select( 'core/editor' ) as {
			isSavingPost: () => boolean;
			getCurrentPostId: () => number;
			getEditedPostAttribute: ( attribute: string ) => unknown;
		};
		const { duplicate_of: duplicateOf } = ( getEditedPostAttribute( 'meta' ) as PromptMeta | undefined ) || ( {} as PromptMeta );
		return {
			postId: getCurrentPostId(),
			isSavingPost: isSavingPost(),
			title: getEditedPostAttribute( 'title' ) as string,
			campaignGroups: getEditedPostAttribute( 'newspack_popups_taxonomy' ) as unknown[] | undefined,
			duplicateOf,
		};
	} ),
	// The mapper returns specifically-typed action props; withDispatch's own signature
	// widens it to an unknown-arg index, so cast the mapper at the boundary.
	withDispatch( ( ( dispatch: ( store: string ) => Record< string, ( ...args: unknown[] ) => unknown > ) => {
		const editorDispatch = dispatch( 'core/editor' );
		const noticesDispatch = dispatch( 'core/notices' );
		return {
			autosave: editorDispatch.autosave as () => Promise< void >,
			createNotice: noticesDispatch.createNotice as ( status: string, content: string ) => void,
		};
	} ) as Parameters< typeof withDispatch >[ 0 ] )
)( DuplicateButton ) as ComponentType;
