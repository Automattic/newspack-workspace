/**
 * Editor for a subscriber-only product restriction: which subscriptions unlock
 * purchasing of which products. A right-edge drawer, mirroring the rest of the
 * Subscriptions wizard's editors.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
import { __experimentalHStack as HStack, __experimentalVStack as VStack } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import { Button, Modal } from '../../../../../../../packages/components/src';
import SearchTokenField from '../../components/search-token-field';
import TargetingFields from '../../components/targeting-fields';
import { SEARCH_ENDPOINTS } from '../../constants';
import type { RuleTargeting } from '../../types';
import type { Restriction } from './types';

interface RestrictionEditorProps {
	/** The restriction to edit, or a partial one for a new restriction. */
	restriction: Partial< Restriction >;
	saving: boolean;
	onSave: ( rule: Partial< Restriction > ) => void;
	onClose: () => void;
}

const emptyTargeting: RuleTargeting = {
	targeting: 'products',
	product_ids: [],
	category_ids: [],
	excluded_product_ids: [],
};

export default function RestrictionEditor( { restriction, saving, onSave, onClose }: RestrictionEditorProps ) {
	const isEdit = Boolean( restriction.id );
	const [ subscriptionIds, setSubscriptionIds ] = useState< number[] >( restriction.subscription_product_ids || [] );
	const [ targeting, setTargeting ] = useState< RuleTargeting >( {
		targeting: restriction.targeting || emptyTargeting.targeting,
		product_ids: restriction.product_ids || [],
		category_ids: restriction.category_ids || [],
		excluded_product_ids: restriction.excluded_product_ids || [],
	} );

	// A restriction naming no subscription would be unbuyable by everyone, and a
	// "specific products" one naming no product would restrict nothing. Neither
	// is savable, so Save stays disabled until the rule says something.
	const isComplete =
		subscriptionIds.length > 0 &&
		( 'products' !== targeting.targeting || targeting.product_ids.length > 0 ) &&
		( 'category' !== targeting.targeting || targeting.category_ids.length > 0 );

	const handleSave = () => {
		onSave( {
			...restriction,
			subscription_product_ids: subscriptionIds,
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
				<SearchTokenField
					endpoint={ SEARCH_ENDPOINTS.subscriptions }
					label={ __( 'Available to', 'newspack-plugin' ) }
					help={ __( 'Subscribers of any of these subscriptions can purchase the products.', 'newspack-plugin' ) }
					value={ subscriptionIds }
					onChange={ setSubscriptionIds }
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
