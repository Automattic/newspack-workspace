/* eslint-disable @wordpress/no-unsafe-wp-apis */
import type { FormEvent } from 'react';
import { __ } from '@wordpress/i18n';
import { useSelect, useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import {
	__experimentalVStack as VStack,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	Button,
	TextControl,
} from '@wordpress/components';

/** An ad-placement taxonomy term as read from the `core` store. */
interface AdPlacement {
	id: number;
	name: string;
}

export default function AdPlacements() {
	// `false` when not editing; a placement ID (number) or `true` (new) when editing.
	const [ editingPlacement, setEditingPlacement ] = useState< boolean | number >( false );
	const [ newPlacementName, setNewPlacementName ] = useState( '' );
	const [ isSavingPlacement, setIsSavingPlacement ] = useState( false );

	const { placements } = useSelect( select => {
		const coreSelectors = select( 'core' ) as {
			getEntityRecords: ( kind: string, name: string, query?: Record< string, unknown > ) => AdPlacement[] | null;
		};
		return {
			placements: coreSelectors.getEntityRecords( 'taxonomy', 'newspack_nl_ad_placement', { per_page: -1, hide_empty: false } ),
		};
	} );

	useEffect( () => {
		if ( editingPlacement ) {
			setNewPlacementName( placements?.find( p => p.id === editingPlacement )?.name || '' );
		} else {
			setNewPlacementName( '' );
		}
	}, [ editingPlacement, placements ] );

	const handleSubmit = async ( ev: FormEvent< HTMLFormElement > ) => {
		ev.preventDefault();
		setIsSavingPlacement( true );
		const record: { name: string; id?: boolean | number } = {
			name: newPlacementName,
		};
		if ( true !== editingPlacement ) {
			record.id = editingPlacement;
		}
		await saveEntityRecord( 'taxonomy', 'newspack_nl_ad_placement', record );
		setIsSavingPlacement( false );
		setEditingPlacement( false );
	};

	const { saveEntityRecord, deleteEntityRecord } = useDispatch( 'core' );

	if ( editingPlacement ) {
		return (
			<form onSubmit={ handleSubmit }>
				<VStack spacing={ 4 }>
					<TextControl
						value={ newPlacementName }
						onChange={ setNewPlacementName }
						label={ __( 'Placement Name', 'newspack-newsletters' ) }
					/>
					<HStack justify="end">
						<Button variant="secondary" onClick={ () => setEditingPlacement( false ) } disabled={ isSavingPlacement }>
							{ __( 'Cancel', 'newspack-newsletters' ) }
						</Button>
						<Button type="submit" variant="primary" disabled={ isSavingPlacement || ! newPlacementName } isBusy={ isSavingPlacement }>
							{ __( 'Save', 'newspack-newsletters' ) }
						</Button>
					</HStack>
				</VStack>
			</form>
		);
	}

	return (
		<VStack spacing={ 4 }>
			{ ( placements?.length ?? 0 ) > 0 && (
				<VStack>
					{ placements?.map( placement => (
						<HStack key={ placement.id }>
							<Text style={ { flex: 1 } }>{ placement.name }</Text>
							<Button variant="link" onClick={ () => setEditingPlacement( placement.id ) }>
								{ __( 'Edit', 'newspack-newsletters' ) }
							</Button>
							<Button
								variant="link"
								isDestructive
								onClick={ () => {
									if (
										// eslint-disable-next-line no-alert
										confirm( __( 'Are you sure you want to delete this placement?', 'newspack-newsletters' ) )
									) {
										deleteEntityRecord( 'taxonomy', 'newspack_nl_ad_placement', placement.id, { force: true } );
									}
								} }
							>
								{ __( 'Delete', 'newspack-newsletters' ) }
							</Button>
						</HStack>
					) ) }
				</VStack>
			) }
			<Text>
				{ __( 'Tip: Use the "Newsletter Ad" block to insert the ad placements into your newsletter content.', 'newspack-newsletters' ) }
			</Text>
			<HStack justify="end">
				<Button variant="secondary" onClick={ () => setEditingPlacement( true ) } isBusy={ isSavingPlacement } disabled={ isSavingPlacement }>
					{ __( 'New Placement', 'newspack-newsletters' ) }
				</Button>
			</HStack>
		</VStack>
	);
}
