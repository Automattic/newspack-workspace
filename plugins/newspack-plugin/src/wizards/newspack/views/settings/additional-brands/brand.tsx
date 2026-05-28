/**
 * Additional Brands Brand Card.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { ESCAPE } from '@wordpress/keycodes';
import { useState } from '@wordpress/element';
import { moreVertical } from '@wordpress/icons';
import { MenuItem } from '@wordpress/components';

/**
 * Internal dependencies.
 */
import WizardsActionCard from '../../../../wizards-action-card';
import { Button, Popover, Router } from '../../../../../../packages/components/src';
import { TAB_PATH } from './constants';

const { useHistory } = Router;

export default function Brand( { brand, deleteBrand }: { brand: Brand; deleteBrand: ( brand: Brand ) => void } ) {
	const [ popoverVisibility, setPopoverVisibility ] = useState( false );
	const onFocusOutside = () => setPopoverVisibility( false );
	const history = useHistory();

	return (
		<WizardsActionCard
			isSmall
			title={ brand.name }
			actionText={
				<>
					<Button
						onClick={ () => setPopoverVisibility( ! popoverVisibility ) }
						label={ __( 'More options', 'newspack-plugin' ) }
						icon={ moreVertical }
						className={ popoverVisibility ? 'popover-active' : '' }
					/>
					{ popoverVisibility && (
						<Popover
							position="bottom left"
							onKeyDown={ ( event: React.KeyboardEvent ) => ESCAPE === event.keyCode && onFocusOutside }
							onFocusOutside={ onFocusOutside }
						>
							<MenuItem onClick={ () => onFocusOutside() } className="screen-reader-text">
								{ __( 'Close Popover', 'newspack-plugin' ) }
							</MenuItem>
							<MenuItem onClick={ () => history.push( `${ TAB_PATH }/${ brand.id }` ) } className="newspack-button">
								{ __( 'Edit', 'newspack-plugin' ) }
							</MenuItem>
							<MenuItem onClick={ () => deleteBrand( brand ) } className="newspack-button">
								{ __( 'Delete', 'newspack-plugin' ) }
							</MenuItem>
						</Popover>
					) }
				</>
			}
		/>
	);
}
