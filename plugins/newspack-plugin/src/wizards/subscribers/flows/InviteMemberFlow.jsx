/**
 * Flow — invite people to a group by email, on the owner's behalf.
 *
 * Each address gets an email with a join link. An admin setting a group up is
 * usually working from a list, so this takes a pasted list and/or a CSV, shows
 * what the server will do with it, and sends only on confirmation. The server's
 * `/invite-bulk` route does the parsing, the skipping and the seat check, the same
 * route the owner's My Account page uses, so the two can't disagree.
 */

/**
 * WordPress dependencies.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { FormFileUpload, Notice, TextareaControl } from '@wordpress/components';
import { Stack } from '@wordpress/ui';

/**
 * Internal dependencies.
 */
import { Button, Modal } from '../../../../packages/components/src';
import { seatsRemaining } from './capacity';

// Group_Subscription_Bulk_Invite::MAX_INVITES, which the server enforces; named here
// only so the help text can state it before an admin meets the refusal.
const MAX_INVITES = 100;

export default function InviteMemberFlow( { group, actions, onClose, onDone } ) {
	const [ emails, setEmails ] = useState( '' );
	const [ csv, setCsv ] = useState( { name: '', text: '' } );
	const [ preview, setPreview ] = useState( null );
	const [ report, setReport ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const summaryRef = useRef();
	const textareaRef = useRef();
	const isFirstRender = useRef( true );

	const noSeats = 0 === seatsRemaining( group );
	const hasInput = '' !== emails.trim() || '' !== csv.text;

	// Switching step unmounts the button that had focus, so focus follows the step. The
	// first render is left to the modal, which places focus itself.
	useEffect( () => {
		if ( isFirstRender.current ) {
			isFirstRender.current = false;
			return;
		}
		( preview ? summaryRef : textareaRef ).current?.focus();
	}, [ preview ] );

	const readCsv = async event => {
		const file = event.target.files?.[ 0 ];
		event.target.value = '';
		if ( ! file ) {
			return;
		}
		setPreview( null );
		setCsv( { name: file.name, text: await file.text() } );
	};

	const review = async () => {
		setBusy( true );
		setError( '' );
		try {
			setPreview( await actions.inviteBulk( { emails, csv: csv.text } ) );
		} catch ( e ) {
			setError( e?.message || __( 'Something went wrong.', 'newspack-plugin' ) );
		} finally {
			setBusy( false );
		}
	};

	const send = async () => {
		setBusy( true );
		let result;
		try {
			result = await actions.inviteBulk( { emails, csv: csv.text, confirm: true } );
		} catch ( e ) {
			setBusy( false );
			// A refusal carries a reason and created nothing, so the review stays, with
			// Send disabled until the list changes. A request that failed without one may
			// have stopped partway, so there is nothing safe to send again: refresh and
			// point the admin at the table instead.
			if ( e?.code && e?.data?.status < 500 ) {
				setPreview( { ...preview, over_limit: e.message } );
				return;
			}
			setReport( [
				__(
					"The request didn't finish, so some invitations may have been sent. Check the Invitations table before trying again.",
					'newspack-plugin'
				),
			] );
			onDone( '', { keepOpen: true } );
			return;
		}
		// An invitation can exist without having been delivered: `created` decides
		// whether the screen has to refresh, `sent` is the only number worth reporting.
		const sent = result.invited?.length || 0;
		const created = sent + ( result.not_sent?.length || 0 );
		/* translators: %d: number of invitations sent. */
		const sentMessage = sprintf( _n( '%d invitation sent.', '%d invitations sent.', sent, 'newspack-plugin' ), sent );
		setBusy( false );
		if ( result.not_sent?.length || result.failed?.length || 0 === created ) {
			// Keep the modal open over the report: an undelivered invitation holds a seat
			// until it is cancelled, and the Invitations table behind the modal has to show
			// it for the admin to do that. A send that created nothing, because the group
			// changed since the review, says why instead of announcing zero invitations.
			setReport( result.summary || [] );
			if ( created > 0 ) {
				onDone( sent > 0 ? sentMessage : '', { keepOpen: true } );
			}
			return;
		}
		onDone( sentMessage );
	};

	const toInvite = preview?.to_invite?.length || 0;

	const renderReview = () => (
		<>
			<ul className="newspack-subscribers__modal-text" ref={ summaryRef } tabIndex={ -1 }>
				{ ( preview.summary || [] ).map( line => (
					<li key={ line }>{ line }</li>
				) ) }
			</ul>
			{ preview.over_limit && (
				<Notice status="error" isDismissible={ false }>
					{ preview.over_limit }
				</Notice>
			) }
			<Stack gap="sm" align="center" justify="flex-end">
				<Button variant="tertiary" size="compact" disabled={ busy } onClick={ () => setPreview( null ) }>
					{ __( 'Back', 'newspack-plugin' ) }
				</Button>
				<Button
					variant="primary"
					size="compact"
					isBusy={ busy }
					disabled={ busy || 0 === toInvite || !! preview.over_limit }
					accessibleWhenDisabled
					onClick={ send }
				>
					{ sprintf(
						/* translators: %d: number of invitations to send. */
						_n( 'Send %d invite', 'Send %d invites', toInvite, 'newspack-plugin' ),
						toInvite
					) }
				</Button>
			</Stack>
		</>
	);

	const renderInput = () => (
		<>
			<p className="newspack-subscribers__modal-text">
				{ noSeats
					? __(
							'No seats are available. Remove a member, cancel a pending invite, or raise the seat limit before inviting.',
							'newspack-plugin'
					  )
					: __( 'Each address gets an email with a link to join.', 'newspack-plugin' ) }
			</p>
			<TextareaControl
				ref={ textareaRef }
				label={ __( 'Email addresses', 'newspack-plugin' ) }
				help={ sprintf(
					/* translators: %d: the most people one list may invite. */
					__(
						'Separate addresses with commas, spaces or new lines, or upload a CSV file with the addresses in its first column. Up to %d people at a time.',
						'newspack-plugin'
					),
					MAX_INVITES
				) }
				value={ emails }
				onChange={ value => {
					setEmails( value );
					setPreview( null );
				} }
				rows={ 5 }
				disabled={ noSeats || busy }
				__nextHasNoMarginBottom
			/>
			<Stack gap="sm" align="center" wrap="wrap">
				<FormFileUpload
					accept=".csv,text/csv"
					onChange={ readCsv }
					render={ ( { openFileDialog } ) => (
						<Button variant="secondary" size="compact" disabled={ noSeats || busy } onClick={ openFileDialog }>
							{ __( 'Upload CSV', 'newspack-plugin' ) }
						</Button>
					) }
				/>
				{ csv.name && (
					<>
						<span className="newspack-subscribers__modal-text">{ csv.name }</span>
						<Button
							variant="tertiary"
							size="compact"
							disabled={ busy }
							onClick={ () => setCsv( { name: '', text: '' } ) }
							label={ sprintf(
								/* translators: %s: name of the uploaded CSV file. */
								__( 'Remove file %s', 'newspack-plugin' ),
								csv.name
							) }
						>
							{ __( 'Remove file', 'newspack-plugin' ) }
						</Button>
					</>
				) }
			</Stack>
			<Stack gap="sm" align="center" justify="flex-end">
				<Button variant="tertiary" size="compact" disabled={ busy } onClick={ onClose }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" size="compact" isBusy={ busy } disabled={ busy || noSeats || ! hasInput } onClick={ review }>
					{ __( 'Review', 'newspack-plugin' ) }
				</Button>
			</Stack>
		</>
	);

	return (
		<Modal title={ __( 'Invite members', 'newspack-plugin' ) } onRequestClose={ busy ? () => {} : onClose } size="small">
			<Stack direction="column" gap="lg">
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ report && (
					// Something the admin has to act on (cancel or resend), so a warning, listed
					// the way the review is. The joined text is what a screen reader hears.
					<Notice status="warning" isDismissible={ false } spokenMessage={ report.join( ' ' ) }>
						<ul>
							{ report.map( line => (
								<li key={ line }>{ line }</li>
							) ) }
						</ul>
					</Notice>
				) }
				{ ! report && ( preview ? renderReview() : renderInput() ) }
				{ report && (
					<Stack gap="sm" justify="flex-end">
						<Button variant="primary" size="compact" onClick={ onClose }>
							{ __( 'Close', 'newspack-plugin' ) }
						</Button>
					</Stack>
				) }
			</Stack>
		</Modal>
	);
}
