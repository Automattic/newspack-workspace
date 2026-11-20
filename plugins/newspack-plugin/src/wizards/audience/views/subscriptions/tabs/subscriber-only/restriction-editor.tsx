/**
 * Editor for a subscriber-only product restriction: which subscriptions unlock
 * purchasing of which products, and whether everyone else may still see them.
 * A right-edge drawer, mirroring the rest of the Subscriptions wizard's editors.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack, __experimentalVStack as VStack, RadioControl } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Modal } from '../../../../../../../packages/components/src';
import SubscriberFields from '../../components/subscriber-fields';
import TargetingFields from '../../components/targeting-fields';
import type { RuleTargeting, SubscriberAudience } from '../../types';
import type { Restriction, RestrictionType } from './types';

interface RestrictionEditorProps {
	/** The restriction to edit, or a partial one for a new restriction. */
	restriction: Partial< Restriction >;
	saving: boolean;
	onSave: ( rule: Partial< Restriction > ) => void;
	onClose: () => void;
}

const restrictionTypeHelp = ( type: RestrictionType ) =>
	'view' === type
		? __(
				"Readers without these subscriptions can't buy the products or see their pages. Restriction settings decide what the product page shows and whether product lists hide them.",
				'newspack-plugin'
		  )
		: __( 'Everyone can see the products and their prices. Only readers with these subscriptions can buy them.', 'newspack-plugin' );

const emptyTargeting: RuleTargeting = {
	targeting: 'products',
	product_ids: [],
	category_ids: [],
	excluded_product_ids: [],
};

export default function RestrictionEditor( { restriction, saving, onSave, onClose }: RestrictionEditorProps ) {
	const isEdit = Boolean( restriction.id );
	const [ restrictionType, setRestrictionType ] = useState< RestrictionType >( restriction.restriction_type || 'purchase' );
	const [ audience, setAudience ] = useState< SubscriberAudience >( {
		subscription_targeting: restriction.subscription_targeting || 'subscriptions',
		subscription_product_ids: restriction.subscription_product_ids || [],
	} );
	const [ targeting, setTargeting ] = useState< RuleTargeting >( {
		targeting: restriction.targeting || emptyTargeting.targeting,
		product_ids: restriction.product_ids || [],
		category_ids: restriction.category_ids || [],
		excluded_product_ids: restriction.excluded_product_ids || [],
	} );

	// A restriction that names its subscriptions and then names none would be
	// unbuyable by everyone, and a "specific products" one naming no product would
	// restrict nothing. Neither is savable, so Save stays disabled until the rule
	// says something. "All subscriptions" names its audience through the mode, so
	// an empty list there is the rule working as written.
	const isComplete =
		( 'all' === audience.subscription_targeting || audience.subscription_product_ids.length > 0 ) &&
		( 'products' !== targeting.targeting || targeting.product_ids.length > 0 ) &&
		( 'category' !== targeting.targeting || targeting.category_ids.length > 0 );

	const handleSave = () => {
		onSave( {
			...restriction,
			restriction_type: restrictionType,
			subscription_targeting: audience.subscription_targeting,
			subscription_product_ids: 'all' === audience.subscription_targeting ? [] : audience.subscription_product_ids,
			targeting: targeting.targeting,
			// Only the fields the chosen targeting uses, so switching mode before
			// saving can't persist ids the rule no longer means.
			product_ids: 'products' === targeting.targeting ? targeting.product_ids : [],
			category_ids: 'category' === targeting.targeting ? targeting.category_ids : [],
			excluded_product_ids: 'products' === targeting.targeting ? [] : targeting.excluded_product_ids,
			active: restriction.active ?? true,
		} );
	};

	return (
		<Modal
			title={ isEdit ? __( 'Edit restriction', 'newspack-plugin' ) : __( 'Add restriction', 'newspack-plugin' ) }
			onRequestClose={ onClose }
			className="newspack-subscriptions-drawer"
			overlayClassName="newspack-subscriptions-drawer__overlay"
			shouldCloseOnClickOutside={ false }
		>
			<VStack spacing={ 4 } className="newspack-subscriptions-drawer__content">
				<RadioControl
					label={ __( 'Restrict', 'newspack-plugin' ) }
					help={ restrictionTypeHelp( restrictionType ) }
					selected={ restrictionType }
					onChange={ ( next: string ) => {
						// The help is the meaning of the choice, and a screen reader
						// announces it only on entry; see SubscriberFields.
						speak( restrictionTypeHelp( next as RestrictionType ), 'polite' );
						setRestrictionType( next as RestrictionType );
					} }
					options={ [
						{ value: 'purchase', label: __( 'Purchase', 'newspack-plugin' ) },
						{ value: 'view', label: __( 'Purchase and visibility', 'newspack-plugin' ) },
					] }
					disabled={ saving }
				/>
				<SubscriberFields
					value={ audience }
					onChange={ partial => setAudience( current => ( { ...current, ...partial } ) ) }
					label={ __( 'Available to', 'newspack-plugin' ) }
					specificHelp={
						'view' === restrictionType
							? __( 'Only subscribers of the subscriptions below can see and purchase the products.', 'newspack-plugin' )
							: __( 'Only subscribers of the subscriptions below can purchase the products.', 'newspack-plugin' )
					}
					allHelp={
						'view' === restrictionType
							? __( 'Anyone with an active subscription can see and purchase the products.', 'newspack-plugin' )
							: __( 'Anyone with an active subscription can purchase the products.', 'newspack-plugin' )
					}
					disabled={ saving }
				/>
				<TargetingFields
					value={ targeting }
					onChange={ partial => setTargeting( current => ( { ...current, ...partial } ) ) }
					appliesHelp={ __( 'Choose which products are subscriber-only.', 'newspack-plugin' ) }
					categoryHelp={ __( 'Subcategories are included.', 'newspack-plugin' ) }
					disabled={ saving }
				/>
			</VStack>
			<HStack className="newspack-subscriptions-drawer__footer" justify="flex-end" spacing={ 2 }>
				<Button variant="secondary" onClick={ onClose } disabled={ saving }>
					{ __( 'Cancel', 'newspack-plugin' ) }
				</Button>
				<Button variant="primary" onClick={ handleSave } disabled={ saving || ! isComplete }>
					{ __( 'Save', 'newspack-plugin' ) }
				</Button>
			</HStack>
		</Modal>
	);
}
