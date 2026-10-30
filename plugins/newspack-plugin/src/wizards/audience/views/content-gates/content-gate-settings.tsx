/**
 * WordPress dependencies.
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { decodeEntities } from '@wordpress/html-entities';
import { createInterpolateElement, useMemo, useRef } from '@wordpress/element';
import { moreVertical } from '@wordpress/icons';
import { Badge, Card, Stack } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import { Divider, Router, TooltipBadge, useConfirmDialog } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { useWizardApiFetch } from '../../../hooks/use-wizard-api-fetch';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { getEditGateLayoutUrl, getGateStatus, getGateStatusBadgeIntent, getPriorityWarningLabel, getPriorityWarnings } from './utils';
import { getGateSummarySections } from './gate-summary';
import { useAccessRuleOptions } from './use-access-rule-options';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from './consts';

const { useHistory } = Router;

const TitleTag = 'h3';

export default function ContentGateSettings( {
	gate,
	updateGatesData,
	slug = AUDIENCE_CONTENT_GATES_WIZARD_SLUG,
	isNewsletter = false,
}: {
	gate: Gate;
	updateGatesData: ( gates: Gate[] ) => void;
	slug?: string;
	isNewsletter?: boolean;
} ) {
	const history = useHistory();
	const accessRuleOptions = useAccessRuleOptions();
	const wizardData = useWizardData( slug ) as ContentGatesWizardData;
	const gates = ( wizardData?.gates || [] ) as Gate[];
	const siteMeter = wizardData?.config?.site_meter;
	const siteAutoSignup = wizardData?.config?.auto_signup;
	// Premium newsletter gates are left out: their screen has no priority modal to act on a
	// warning, and they match lists by ID, which the overlap check doesn't model.
	const priorityWarning = useMemo( () => ( isNewsletter ? undefined : getPriorityWarnings( gates )[ gate.id ] ), [ gates, gate.id, isNewsletter ] );
	const { wizardApiFetch, isFetching, resetError } = useWizardApiFetch( slug );
	const { addNotice, resetNotices } = useDispatch( WIZARD_STORE_NAMESPACE );
	const { confirmDialog: deleteDialog, requestConfirm: requestDelete } = useConfirmDialog( {
		title: __( 'Are you sure?', 'newspack-plugin' ),
		confirmButtonText: __( 'Delete', 'newspack-plugin' ),
		isDestructive: true,
		message: createInterpolateElement(
			sprintf(
				// translators: %s is the gate title.
				__( 'This will <strong>permanently delete</strong> “%s” and cannot be undone.', 'newspack-plugin' ),
				gate.title
			),
			{ strong: <strong /> }
		),
	} );

	const updateStatus = useRef< ( status: GateStatus ) => void >( undefined );
	const handleStatusChange = ( status: GateStatus ) => {
		if ( isFetching ) {
			return;
		}
		resetError();
		resetNotices();
		const prevStatus = gate.status;
		const gateTitle = gate.title;
		const _gate = {
			...gate,
			status,
		};
		wizardApiFetch< Gate >(
			{
				path: `/newspack/v1/wizard/${ slug }/${ gate.id }`,
				method: 'POST',
				data: { gate: _gate },
			},
			{
				onSuccess( data: Gate ) {
					updateGatesData( gates.map( g => ( g.id === data.id ? data : g ) ) );
					addNotice( {
						message: sprintf(
							// translators: 1: the gate title, or "Content" if we can't determine the gate title. 2: the gate status.
							__( '%1$s gate %2$s.', 'newspack-plugin' ),
							gateTitle ? `"${ gateTitle }"` : __( 'Content', 'newspack-plugin' ),
							prevStatus === 'publish' ? __( 'disabled', 'newspack-plugin' ) : __( 'enabled', 'newspack-plugin' )
						),
						type: 'success',
						id: 'content-gate-status-changed',
						actions: [ { label: __( 'Undo', 'newspack-plugin' ), onClick: () => updateStatus.current?.( prevStatus ) } ],
					} );
				},
				onError( fetchError: WpFetchError ) {
					addNotice( {
						message: decodeEntities( fetchError.message ),
						type: 'error',
						id: 'content-gate-status-error',
					} );
				},
			}
		);
	};
	updateStatus.current = handleStatusChange;

	const handleDelete = () => {
		resetError();
		resetNotices();
		wizardApiFetch(
			{
				path: `/newspack/v1/wizard/${ slug }/${ gate.id }`,
				method: 'DELETE',
			},
			{
				onSuccess() {
					const deletedGate = gates.find( g => g.id === gate.id );
					const newGates = gates.filter( g => g.id !== gate.id );
					updateGatesData( newGates );
					addNotice( {
						message: sprintf(
							// translators: %s is the gate title, or "Content" if we can't determine the deleted gate title.
							__( '%s gate deleted.', 'newspack-plugin' ),
							deletedGate?.title ? `“${ deletedGate.title }”` : __( 'Content', 'newspack-plugin' )
						),
						type: 'success',
						id: 'content-gate-deleted',
					} );
				},
				onError( fetchError: WpFetchError ) {
					addNotice( {
						message: decodeEntities( fetchError.message ),
						type: 'error',
						id: 'content-gate-delete-error',
					} );
				},
			}
		);
	};

	const handleDuplicate = () => {
		resetError();
		resetNotices();
		wizardApiFetch< Gate >(
			{
				path: `/newspack/v1/wizard/${ slug }/${ gate.id }/duplicate`,
				method: 'POST',
			},
			{
				onSuccess( data: Gate ) {
					// The copy is appended to the end of the list server-side.
					updateGatesData( [ ...gates, data ] );
					addNotice( {
						message: sprintf(
							// translators: %s is the title of the newly created copy.
							__( '“%s” gate created as inactive.', 'newspack-plugin' ),
							data.title
						),
						type: 'success',
						id: 'content-gate-duplicated',
						actions: [ { label: __( 'Edit', 'newspack-plugin' ), onClick: () => history.push( `/edit/${ data.id }` ) } ],
					} );
				},
			}
		);
	};

	// Menu items read out of context, so name the gate; the visible label comes first so voice control still matches it.
	const withGateTitle = ( label: string ) =>
		gate.title
			? sprintf(
					// translators: 1: a menu action, such as "Edit". 2: the gate title.
					_x( '%1$s: %2$s', 'menu action and the gate it applies to', 'newspack-plugin' ),
					label,
					decodeEntities( gate.title )
			  )
			: undefined;
	const statusLabel = gate.status !== 'publish' ? __( 'Set to Active', 'newspack-plugin' ) : __( 'Set to Inactive', 'newspack-plugin' );
	const actions: { label: string; ariaLabel?: string; action?: () => void; href?: string; disabled?: boolean; destructive?: boolean }[][] = [
		[
			{
				label: __( 'Edit', 'newspack-plugin' ),
				ariaLabel: withGateTitle( __( 'Edit', 'newspack-plugin' ) ),
				action: () => history.push( `/edit/${ gate.id }` ),
				disabled: isFetching,
			},
			{
				label: statusLabel,
				ariaLabel: withGateTitle( statusLabel ),
				action: () => updateStatus.current?.( gate.status === 'publish' ? 'draft' : 'publish' ),
				disabled: isFetching,
			},
			{
				label: __( 'Duplicate', 'newspack-plugin' ),
				ariaLabel: withGateTitle( __( 'Duplicate', 'newspack-plugin' ) ),
				action: handleDuplicate,
				disabled: isFetching,
			},
			{
				label: __( 'Delete', 'newspack-plugin' ),
				ariaLabel: withGateTitle( __( 'Delete', 'newspack-plugin' ) ),
				action: () => requestDelete( handleDelete ),
				disabled: isFetching,
				destructive: true,
			},
		],
	];
	const hasRegistrationLayout = ! isNewsletter && gate.registration?.active && gate.registration.gate_layout_id;
	const hasCustomAccessLayout =
		! isNewsletter && gate.custom_access?.active && gate.custom_access.access_rules?.length > 0 && gate.custom_access.gate_layout_id;
	const layoutOptions: { label: string; ariaLabel?: string; action?: () => void; href?: string }[] = [];
	if ( hasRegistrationLayout ) {
		layoutOptions.push( {
			label: __( 'Edit Registered Access Layout', 'newspack-plugin' ),
			ariaLabel: withGateTitle( __( 'Edit Registered Access Layout', 'newspack-plugin' ) ),
			href: getEditGateLayoutUrl( gate.id, 'registration' ),
		} );
	}
	if ( hasCustomAccessLayout ) {
		layoutOptions.push( {
			label: __( 'Edit Paid Access Layout', 'newspack-plugin' ),
			ariaLabel: withGateTitle( __( 'Edit Paid Access Layout', 'newspack-plugin' ) ),
			href: getEditGateLayoutUrl( gate.id, 'custom_access' ),
		} );
	}
	if ( layoutOptions.length > 0 ) {
		actions.push( layoutOptions );
	}

	const actionsLabel = gate.title
		? sprintf(
				// translators: %s is the gate title.
				__( 'Gate actions: %s', 'newspack-plugin' ),
				decodeEntities( gate.title )
		  )
		: undefined;

	return (
		<>
			{ deleteDialog }
			<Card.Root className="newspack-content-gates__gate" id={ String( gate.id ) }>
				<Card.Header render={ <Stack direction="row" gap="md" align="center" justify="space-between" /> }>
					<Stack direction="row" gap="sm" wrap="wrap" align="center" className="newspack-content-gates__gate__heading">
						<Card.Title render={ <TitleTag /> } className="newspack-content-gates__gate__title">
							<a href={ `#/edit/${ gate.id }` }>{ gate.title }</a>
						</Card.Title>
						<Badge intent={ getGateStatusBadgeIntent( gate.status ) }>{ getGateStatus( gate.status ) }</Badge>
						{ priorityWarning && <TooltipBadge label={ getPriorityWarningLabel() } tooltip={ priorityWarning } intent="low" /> }
					</Stack>
					<DropdownMenu
						className="newspack-content-gates__gate__actions"
						icon={ moreVertical }
						label={ actionsLabel || __( 'More actions', 'newspack-plugin' ) }
					>
						{ ( { onClose } ) =>
							actions.map( ( group, groupIndex ) => (
								<MenuGroup key={ groupIndex }>
									{ group.map( item => (
										<MenuItem
											key={ item.label }
											aria-label={ item.ariaLabel }
											{ ...( item.href ? { href: item.href } : {} ) }
											onClick={
												item.action
													? () => {
															onClose();
															item.action?.();
													  }
													: undefined
											}
											disabled={ item.disabled || false }
											isDestructive={ item.destructive || false }
										>
											{ item.label }
										</MenuItem>
									) ) }
								</MenuGroup>
							) )
						}
					</DropdownMenu>
				</Card.Header>
				<Card.Content render={ <Stack direction="column" gap="lg" /> }>
					<Card.FullBleed>
						<Divider marginTop={ 0 } marginBottom={ 0 } />
					</Card.FullBleed>
					<Stack direction="row" gap="lg" wrap="wrap">
						{ getGateSummarySections( gate, isNewsletter, siteMeter, accessRuleOptions, siteAutoSignup ).map( section => (
							<div key={ section.key } className="newspack-content-gates__gate__summary-section">
								<h4>{ section.label }</h4>
								{ section.content }
							</div>
						) ) }
					</Stack>
				</Card.Content>
			</Card.Root>
		</>
	);
}
