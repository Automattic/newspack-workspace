/**
 * WordPress dependencies.
 */
import { __, sprintf } from '@wordpress/i18n';
import { CardBody } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { createInterpolateElement, useRef } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { Badge, Card, Grid, Router, useConfirmDialog } from '../../../../../packages/components/src';
import { useWizardData } from '../../../../../packages/components/src/wizard/store/utils';
import { useWizardApiFetch } from '../../../hooks/use-wizard-api-fetch';
import { WIZARD_STORE_NAMESPACE } from '../../../../../packages/components/src/wizard/store';
import { getEditGateLayoutUrl, getGateStatus, getGateStatusBadgeLevel } from './utils';
import { getGateSummarySections } from './gate-summary';
import { AUDIENCE_CONTENT_GATES_WIZARD_SLUG } from './consts';

const { useHistory } = Router;

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
	const { gates = null as unknown as Gate[] } = useWizardData( slug ) as WizardData;
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

	const updateStatus = useRef< ( status: GateStatus ) => void >();
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
			}
		);
	};

	const actions = [
		[
			{
				label: __( 'Edit', 'newspack-plugin' ),
				action: () => history.push( `/edit/${ gate.id }` ),
				disabled: isFetching,
			},
			{
				label: gate.status !== 'publish' ? __( 'Set to active', 'newspack-plugin' ) : __( 'Set to inactive', 'newspack-plugin' ),
				action: () => updateStatus.current?.( gate.status === 'publish' ? 'draft' : 'publish' ),
				disabled: isFetching,
			},
			{
				label: __( 'Delete', 'newspack-plugin' ),
				action: () => requestDelete( handleDelete ),
				disabled: isFetching,
				destructive: true,
			},
		],
	];
	const hasRegistrationLayout = ! isNewsletter && gate.registration?.active && gate.registration.gate_layout_id;
	const hasCustomAccessLayout =
		! isNewsletter && gate.custom_access?.active && gate.custom_access.access_rules?.length > 0 && gate.custom_access.gate_layout_id;
	const layoutOptions: { label: string; action?: () => void; href?: string }[] = [];
	if ( hasRegistrationLayout ) {
		layoutOptions.push( {
			label: __( 'Edit registered access layout', 'newspack-plugin' ),
			href: getEditGateLayoutUrl( gate.id, 'registration' ),
		} );
	}
	if ( hasCustomAccessLayout ) {
		layoutOptions.push( {
			label: __( 'Edit paid access layout', 'newspack-plugin' ),
			href: getEditGateLayoutUrl( gate.id, 'custom_access' ),
		} );
	}
	if ( layoutOptions.length > 0 ) {
		actions.push( layoutOptions );
	}

	return (
		<>
			{ deleteDialog }
			<Card
				className="newspack-content-gates__gate"
				id={ gate.id }
				key={ gate.id }
				isSmall
				__experimentalCoreCard
				__experimentalCoreProps={ {
					noMargin: true,
					header: (
						<>
							<h3>
								<a href={ `#/edit/${ gate.id }` }>{ gate.title }</a>
								<Badge level={ getGateStatusBadgeLevel( gate.status ) } text={ getGateStatus( gate.status ) } />
							</h3>
						</>
					),
					actions,
				} }
			>
				<CardBody>
					<Grid className="newspack-content-gates__gate__settings" columns={ isNewsletter ? 2 : 3 } gutter={ 16 } borders noMargin>
						{ getGateSummarySections( gate, isNewsletter ).map( section => (
							<div key={ section.key }>
								<h4>{ section.label }</h4>
								{ section.content }
							</div>
						) ) }
					</Grid>
				</CardBody>
			</Card>
		</>
	);
}
