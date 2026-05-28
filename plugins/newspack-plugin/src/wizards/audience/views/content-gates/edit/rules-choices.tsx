/**
 * WordPress dependencies.
 */
import { MenuItem } from '@wordpress/components';
import { check } from '@wordpress/icons';

type RuleChoice = {
	label: string;
	value: string;
	disabled?: boolean;
	info?: string;
	[ 'aria-label' ]?: string;
};

type RulesChoicesProps = {
	choices: readonly RuleChoice[];
	onHover?: ( value: string | null ) => void;
	onSelect: ( value: string ) => void;
	value: string[];
};

const noop = () => {};

export default function RulesChoices( { choices = [], onHover = noop, onSelect, value }: RulesChoicesProps ) {
	return (
		<>
			{ choices.map( item => {
				const isSelected = value.includes( item.value );
				return (
					<MenuItem
						key={ item.value }
						role="menuitemradio"
						disabled={ item.disabled }
						icon={ isSelected ? check : null }
						info={ item.info }
						isSelected={ isSelected }
						className="components-menu-items-choice"
						onClick={ () => {
							onSelect( item.value );
						} }
						onMouseEnter={ () => onHover( item.value ) }
						onMouseLeave={ () => onHover( null ) }
						aria-label={ item[ 'aria-label' ] }
					>
						{ item.label }
					</MenuItem>
				);
			} ) }
		</>
	);
}
