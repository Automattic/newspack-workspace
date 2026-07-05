/**
 * External dependencies
 */
import PropTypes from 'prop-types';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';
import { Spinner } from '@wordpress/components';

/**
 * Internal dependencies
 */
import './style.scss';
import { parseBylineForDisplay, formatAuthorsList } from './utils';
import { useCustomByline } from '../../shared/hooks/use-custom-byline';
import { useCoAuthors } from '../../shared/hooks/use-coauthors';
import { useDefaultAuthor } from './hooks/use-default-author';
import { BylineInspectorControls } from './inspector.jsx';

/**
 * Edit component for the byline block.
 *
 * @param {Object}   props               Component props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Object}   props.context       Block context.
 * @param {Function} props.setAttributes Set attributes function.
 * @return {JSX.Element} Edit component.
 */
export default function Edit( { attributes, context, setAttributes } ) {
	const { postId, postType = 'post' } = context;
	const blockProps = useBlockProps();

	// Get custom byline data.
	const { bylineActive, bylineContent } = useCustomByline( postId, postType );

	// Get CoAuthors Plus authors.
	const { authors: coAuthors, isCapAvailable } = useCoAuthors( postId, postType );

	// Get default WordPress author.
	const { authorDetails: defaultAuthor, isLoading: isLoadingAuthor } = useDefaultAuthor( postId, postType );

	// Determine which byline to show.
	const isCustomByline = bylineActive && bylineContent;
	const hasCoAuthors = isCapAvailable && coAuthors?.length > 0;

	// Loading state - show spinner while fetching default author (only when CAP has no authors).
	if ( ! isCustomByline && ! hasCoAuthors && isLoadingAuthor ) {
		return (
			<div { ...blockProps }>
				<Spinner />
			</div>
		);
	}

	// Render custom byline.
	if ( isCustomByline ) {
		const parsedByline = parseBylineForDisplay( bylineContent );
		return (
			<>
				<BylineInspectorControls attributes={ attributes } setAttributes={ setAttributes } isCustomByline={ true } />
				<div { ...blockProps }>
					<span className="byline">{ parsedByline }</span>
				</div>
			</>
		);
	}

	// Render CoAuthors Plus byline.
	if ( hasCoAuthors ) {
		return (
			<>
				<BylineInspectorControls attributes={ attributes } setAttributes={ setAttributes } isCustomByline={ false } />
				<div { ...blockProps }>
					<span className="byline">
						{ attributes.prefix?.trim() && `${ attributes.prefix.trim() } ` }
						{ formatAuthorsList( coAuthors, attributes.linkToAuthorArchive ) }
					</span>
				</div>
			</>
		);
	}

	// Render default WordPress author, or placeholder if no author found.
	const authorName = defaultAuthor?.name || __( 'Author Name', 'newspack-plugin' );
	return (
		<>
			<BylineInspectorControls attributes={ attributes } setAttributes={ setAttributes } isCustomByline={ false } />
			<div { ...blockProps }>
				<span className="byline">
					{ attributes.prefix?.trim() && `${ attributes.prefix.trim() } ` }
					<span className="author vcard">
						{ attributes.linkToAuthorArchive ? (
							<a href="#author-link" onClick={ e => e.preventDefault() } className="url fn n">
								{ authorName }
							</a>
						) : (
							<span className="fn n">{ authorName }</span>
						) }
					</span>
				</span>
			</div>
		</>
	);
}

Edit.propTypes = {
	attributes: PropTypes.shape( {
		prefix: PropTypes.string,
		linkToAuthorArchive: PropTypes.bool,
	} ).isRequired,
	context: PropTypes.shape( {
		postId: PropTypes.number,
		postType: PropTypes.string,
	} ).isRequired,
	setAttributes: PropTypes.func.isRequired,
};
