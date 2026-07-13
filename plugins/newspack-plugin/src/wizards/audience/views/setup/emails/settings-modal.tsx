/**
 * Newspack > Settings > Emails > Settings modal.
 *
 * Hosts the three transactional-email settings (sender_name,
 * sender_email_address, contact_email_address) that previously lived
 * in the Reader Activation "Transactional Emails" prerequisite card.
 * Reads + writes the dedicated /wizard/newspack-settings/emails/settings
 * endpoint introduced in NPPD-1566 — same underlying wp_options keys
 * as the legacy surface, but the endpoint belongs here.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import {
	TextControl,
	__experimentalHStack as HStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
	__experimentalVStack as VStack, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';

/**
 * Internal dependencies.
 */
import { Button, Modal, Notice, useConfirmDialog } from '../../../../../../packages/components/src';
import { WIZARD_STORE_NAMESPACE } from '../../../../../../packages/components/src/wizard/store';
import { useWizardApiFetch } from '../../../../hooks/use-wizard-api-fetch';

type TransactionalEmailSettings = {
	sender_name: string;
	sender_email_address: string;
	contact_email_address: string;
};

// Shape returned by the GET endpoint: the three saved values at top
// level + a `defaults` sub-object with the derived fallbacks. The
// frontend renders saved values as the input's `value` and derived
// defaults as the `placeholder` — keeping publishers from
// accidentally locking in dynamic defaults as static overrides on
// first save.
type TransactionalEmailSettingsResponse = TransactionalEmailSettings & {
	defaults: TransactionalEmailSettings;
};

const EMPTY_SETTINGS: TransactionalEmailSettings = {
	sender_name: '',
	sender_email_address: '',
	contact_email_address: '',
};

// Decode HTML entities for display. Derived defaults come from
// get_bloginfo( 'name' ) / the admin email, which can carry encoded
// entities (e.g. an ampersand as `&amp;`); saved values can too. Decode
// at the data boundary so the inputs show "Tom & Jerry" not
// "Tom &amp; Jerry". sender_name re-saves through sanitize_text_field,
// which does not re-encode, so the round-trip is stable.
const decodeSettings = ( values: TransactionalEmailSettings ): TransactionalEmailSettings => ( {
	sender_name: decodeEntities( values.sender_name ),
	sender_email_address: decodeEntities( values.sender_email_address ),
	contact_email_address: decodeEntities( values.contact_email_address ),
} );

// Mirrors the server-side `is_email()` gate: any string with an `@`, a
// dot in the domain, and no embedded whitespace. Used to disable Save
// before firing a POST that would 400 anyway.
const isValidEmail = ( value: string | undefined | null ): boolean => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( ( value ?? '' ).trim() );

const SettingsModal = ( { showModal, closeModal }: { showModal: boolean; closeModal: () => void } ) => {
	const { wizardApiFetch, isFetching, errorMessage, resetError } = useWizardApiFetch( 'newspack-settings/emails/settings' );
	const { addNotice } = useDispatch( WIZARD_STORE_NAMESPACE );

	// `settings` is the editable working copy of the saved values;
	// `initial` is the last-known-saved snapshot for dirty comparison.
	// `defaults` holds the server's derived fallbacks (blog title,
	// no-reply@domain, admin email) used as placeholder hints — never
	// edited by the user, never sent on POST.
	const [ settings, setSettings ] = useState< TransactionalEmailSettings >( EMPTY_SETTINGS );
	const [ initial, setInitial ] = useState< TransactionalEmailSettings >( EMPTY_SETTINGS );
	const [ defaults, setDefaults ] = useState< TransactionalEmailSettings >( EMPTY_SETTINGS );
	// `loaded` gates Save: without it, a publisher could overwrite the
	// real saved values with empty strings if GET failed and they
	// hit Save before realizing.
	const [ loaded, setLoaded ] = useState( false );

	useEffect( () => {
		if ( ! showModal ) {
			return;
		}
		resetError();
		// Reset local snapshot before the GET so reopen renders an
		// empty form (gated from Save by `! loaded`) instead of
		// briefly flashing the previous open's values. Without this,
		// the first paint after reopen renders whatever was in state
		// when the modal last closed — confusing if a concurrent
		// actor (CLI, second admin tab) changed the underlying options
		// between sessions.
		setSettings( EMPTY_SETTINGS );
		setInitial( EMPTY_SETTINGS );
		setDefaults( EMPTY_SETTINGS );
		setLoaded( false );
		wizardApiFetch< TransactionalEmailSettingsResponse >(
			{
				path: '/newspack/v1/wizard/newspack-settings/emails/settings',
				isCached: false,
			},
			{
				onSuccess( result: TransactionalEmailSettingsResponse ) {
					const values = decodeSettings( {
						sender_name: result.sender_name,
						sender_email_address: result.sender_email_address,
						contact_email_address: result.contact_email_address,
					} );
					setSettings( values );
					setInitial( values );
					setDefaults( decodeSettings( result.defaults ) );
					setLoaded( true );
				},
				onError() {
					// Error surfaces via `errorMessage` from the hook;
					// the inline Notice below renders it. Empty handler
					// blocks the rejection from becoming an unhandled
					// promise rejection in the console.
				},
			}
		).catch( () => {
			// See handleSave below for the rationale on swallowing
			// the re-thrown rejection.
		} );
		// `wizardApiFetch` and `resetError` are stable across renders per
		// the hook's contract; including them would re-fire the fetch
		// inappropriately. Mirrors the pattern at emails.tsx mount-time fetch.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ showModal ] );

	const isDirty = JSON.stringify( settings ) !== JSON.stringify( initial );

	// Per-field validation: empty is the intentional "revert to
	// default" path, so it's accepted for every field. Email fields
	// must validate when non-empty. Sender name has no format
	// requirement. Plain expressions rather than `useMemo` — the dep
	// `settings` is a new object reference on every keystroke, so a
	// memo would never hit; the wrapping was misleading without
	// behavior.
	//
	// These per-field flags drive both the aggregate Save gate AND the
	// inline field-level help/`aria-invalid` below, so a disabled Save
	// button always has a visible, screen-reader-announced reason next
	// to the offending field rather than a dead button with no cue.
	const isSenderEmailInvalid = settings.sender_email_address !== '' && ! isValidEmail( settings.sender_email_address );
	const isContactEmailInvalid = settings.contact_email_address !== '' && ! isValidEmail( settings.contact_email_address );
	const isClientSideValid = ! isSenderEmailInvalid && ! isContactEmailInvalid;

	const handleSave = () => {
		if ( isFetching ) {
			return;
		}
		resetError();
		wizardApiFetch< TransactionalEmailSettingsResponse >(
			{
				path: '/newspack/v1/wizard/newspack-settings/emails/settings',
				method: 'POST',
				data: settings,
			},
			{
				onSuccess( result: TransactionalEmailSettingsResponse ) {
					// Reconcile from the server's response so client state
					// reflects post-sanitization values exactly — and so
					// fields that were just cleared flip back to their
					// derived-default placeholder presentation.
					const values = decodeSettings( {
						sender_name: result.sender_name,
						sender_email_address: result.sender_email_address,
						contact_email_address: result.contact_email_address,
					} );
					setInitial( values );
					setSettings( values );
					setDefaults( decodeSettings( result.defaults ) );
					addNotice( {
						message: __( 'Saved.', 'newspack-plugin' ),
						type: 'success',
						id: 'newspack-emails-settings-saved',
					} );
					closeModal();
				},
				onError() {
					// The hook surfaces the message via `errorMessage`
					// and renders it through the inline Notice below.
					// This handler is intentionally empty — its presence
					// keeps `useWizardApiFetch`'s `on('onError', ...)`
					// from no-op'ing into an unhandled promise rejection.
				},
			}
		).catch( () => {
			// `useWizardApiFetch` re-throws after firing `onError` to
			// preserve the contract of an async API. Swallow here so the
			// rejection doesn't surface as "Uncaught (in promise)" in
			// the console — the error UX (inline Notice) is already
			// handled above.
		} );
	};

	const { confirmDialog, requestConfirm } = useConfirmDialog( {
		when: isDirty,
		message: __( 'You have unsaved changes. Discard them?', 'newspack-plugin' ),
		confirmButtonText: __( 'Discard', 'newspack-plugin' ),
		cancelButtonText: __( 'Keep editing', 'newspack-plugin' ),
		isDestructive: true,
	} );

	// All three close paths (Cancel button, X, click-outside, Escape)
	// route through this — `requestConfirm` checks `when` and either
	// pops the discard dialog or invokes `closeModal` immediately.
	const handleClose = () => {
		requestConfirm( closeModal );
	};

	if ( ! showModal ) {
		return null;
	}

	// Field-level message shown (and announced via `aria-describedby`,
	// which `TextControl` wires from `help`) when an email field holds
	// a malformed non-empty value. Declared after the early return so
	// the `__()` call isn't evaluated on the not-rendered path.
	const invalidEmailHelp = __( 'Enter a valid email address, or leave blank to use the default.', 'newspack-plugin' );

	return (
		// The wrapping div is an escape hatch for a ReactNode/ReactElement
		// type mismatch on `confirmDialog` — Fragment-wrapping triggers a
		// TS error against the React 18 strict ReactNode shape. Both
		// Modal and confirmDialog portal to document.body, so this div
		// has no rendered children and occupies a zero-height slot in
		// the Emails view flow. The class is here so future CSS can
		// target it intentionally (or, more importantly, NOT target it
		// via accidental sibling-combinator selectors on unclassed divs).
		<div className="newspack-emails__settings-modal-wrap">
			{ confirmDialog }
			<Modal onRequestClose={ handleClose } size="medium" title={ __( 'Settings', 'newspack-plugin' ) }>
				<p>{ __( 'Configure the sender details and reply-to address for transactional emails sent to your readers.', 'newspack-plugin' ) }</p>
				{ errorMessage && <Notice isError noticeText={ errorMessage } /> }
				<VStack>
					<TextControl
						label={ __( 'Sender Name', 'newspack-plugin' ) }
						help={ __( 'Name to use as the sender of transactional emails. Leave blank to use your site title.', 'newspack-plugin' ) }
						value={ settings.sender_name }
						placeholder={ defaults.sender_name }
						onChange={ ( value: string ) => setSettings( { ...settings, sender_name: value } ) }
					/>
					<TextControl
						label={ __( 'Sender Email Address', 'newspack-plugin' ) }
						help={
							isSenderEmailInvalid
								? invalidEmailHelp
								: __(
										"Email address to use as the sender of transactional emails. Leave blank to use a no-reply address at your site's domain.",
										'newspack-plugin'
								  )
						}
						type="email"
						value={ settings.sender_email_address }
						placeholder={ defaults.sender_email_address }
						aria-invalid={ isSenderEmailInvalid }
						onChange={ ( value: string ) => setSettings( { ...settings, sender_email_address: value } ) }
					/>
					<TextControl
						label={ __( 'Contact Email Address', 'newspack-plugin' ) }
						help={
							isContactEmailInvalid
								? invalidEmailHelp
								: __(
										"This email will be used as 'Reply-To' for transactional emails. Leave blank to use your site's admin email.",
										'newspack-plugin'
								  )
						}
						type="email"
						value={ settings.contact_email_address }
						placeholder={ defaults.contact_email_address }
						aria-invalid={ isContactEmailInvalid }
						onChange={ ( value: string ) => setSettings( { ...settings, contact_email_address: value } ) }
					/>
				</VStack>
				<HStack justify="end">
					<Button variant="tertiary" disabled={ isFetching } onClick={ handleClose }>
						{ __( 'Cancel', 'newspack-plugin' ) }
					</Button>
					<Button
						variant="primary"
						disabled={ ! loaded || isFetching || ! isDirty || ! isClientSideValid }
						loading={ isFetching }
						onClick={ handleSave }
					>
						{ __( 'Save', 'newspack-plugin' ) }
					</Button>
				</HStack>
			</Modal>
		</div>
	);
};

export default SettingsModal;
