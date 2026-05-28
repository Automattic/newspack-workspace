/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { ToggleControl } from '@wordpress/components';
import { compose } from '@wordpress/compose';
import { withDispatch, withSelect } from '@wordpress/data';
import { Fragment } from '@wordpress/element';

const PublicSettingsComponent = props => {
	const { meta, updateIsPublic } = props;
	const { is_public } = meta;

	return (
		<Fragment>
			<hr />
			<ToggleControl
				className="newspack-newsletters__public-toggle-control"
				label={ __( 'Public newsletter', 'newspack-newsletters' ) }
				help={ __( 'Choose whether this newsletter will be publicly viewable as an article after being sent.', 'newspack-newsletters' ) }
				checked={ is_public }
				onChange={ value => updateIsPublic( value ) }
			/>
		</Fragment>
	);
};

const mapStateToProps = select => {
	const { getEditedPostAttribute } = select( 'core/editor' );

	return {
		meta: getEditedPostAttribute( 'meta' ),
	};
};

const mapDispatchToProps = dispatch => {
	const { editPost } = dispatch( 'core/editor' );

	return {
		updateIsPublic: value => editPost( { meta: { is_public: value } } ),
	};
};

export const PublicSettings = compose( [ withSelect( mapStateToProps ), withDispatch( mapDispatchToProps ) ] )( PublicSettingsComponent );
