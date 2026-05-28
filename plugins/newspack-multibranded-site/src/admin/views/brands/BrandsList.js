import { NavLink, useNavigate } from 'react-router-dom';

import { useState, Fragment } from '@wordpress/element';
import { MenuItem } from '@wordpress/components';
import { moreVertical } from '@wordpress/icons';
import { ESCAPE } from '@wordpress/keycodes';
import { __ } from '@wordpress/i18n';

import { Card, ActionCard, Button, Popover, withWizardScreen } from 'newspack-components';

const AddNewBrandLink = () => (
	<NavLink to="brands/new">
		<Button variant="primary">{ __( 'Add New Brand', 'newspack' ) }</Button>
	</NavLink>
);

const BrandActionCard = ( { brand, deleteBrand } ) => {
	const [ popoverVisibility, setPopoverVisibility ] = useState( false );
	const onFocusOutside = () => setPopoverVisibility( false );
	const navigate = useNavigate();

	return (
		<ActionCard
			isSmall
			title={ brand.name }
			actionText={
				<>
					<Button
						onClick={ () => setPopoverVisibility( ! popoverVisibility ) }
						label={ __( 'More options', 'newspack' ) }
						icon={ moreVertical }
						className={ popoverVisibility && 'popover-active' }
					/>
					{ popoverVisibility && (
						<Popover
							position="bottom left"
							onKeyDown={ event => ESCAPE === event.keyCode && onFocusOutside }
							onFocusOutside={ onFocusOutside }
						>
							<MenuItem onClick={ () => onFocusOutside() } className="screen-reader-text">
								{ __( 'Close Popover', 'newspack' ) }
							</MenuItem>
							<MenuItem onClick={ () => navigate( `/brands/${ brand.id }` ) } className="newspack-button">
								{ __( 'Edit', 'newspack' ) }
							</MenuItem>
							<MenuItem onClick={ () => deleteBrand( brand ) } className="newspack-button">
								{ __( 'Delete', 'newspack' ) }
							</MenuItem>
						</Popover>
					) }
				</>
			}
		/>
	);
};

const BrandsList = ( { brands, deleteBrand } ) => {
	return brands.length ? (
		<Fragment>
			<Card headerActions noBorder>
				<h2>{ __( 'Site brands', 'newspack' ) }</h2>
				<AddNewBrandLink />
			</Card>
			{ brands.map( brand => (
				<BrandActionCard key={ brand.id } brand={ brand } deleteBrand={ deleteBrand } />
			) ) }
		</Fragment>
	) : (
		<Fragment>
			<Card headerActions noBorder>
				<h2>{ __( 'You have no saved brands.', 'newspack' ) }</h2>
				<AddNewBrandLink />
			</Card>
			<p>{ __( 'Create brands to enhance your readers experience.', 'newspack' ) }</p>
		</Fragment>
	);
};

export default withWizardScreen( BrandsList );
