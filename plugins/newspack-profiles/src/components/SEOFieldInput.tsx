import { useId, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import classNames from 'classnames';
import { sanitizeSEOFieldToken } from '../utils';

interface SEOFieldInputProps {
	label: string;
	value: string[];
	fields: string[];
	onChange: ( tokens: string[] ) => void;
}

/**
 * A custom input component for SEO fields that allows users to compose values using tokens and delimiters.
 *
 * The component renders a token list with an inline input. Users can type to filter available tokens and add them to the list.
 * They can also use backspace to remove the last token when the input is empty.
 *
 * @param root0
 * @param root0.label    The label for the input field.
 * @param root0.value    An array of strings representing the current value of the field, which can include both tokens and delimiters.
 * @param root0.fields   An array of available tokens that users can select from.
 * @param root0.onChange A callback function that is called when the value changes, receiving the new array of tokens as an argument.
 *
 * @return JSX.Element The SEOFieldInput component.
 */
export const SEOFieldInput = ( {
	label,
	value,
	fields,
	onChange,
}: SEOFieldInputProps ) => {
	const [ inputValue, setInputValue ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const inputRef = useRef< HTMLInputElement >( null );
	const inputId = useId();

	const filtered = inputValue
		? fields.filter( ( s ) =>
				s.toLowerCase().includes( inputValue.toLowerCase() )
		  )
		: fields;

	const add = ( token: string ) => {
		const sanitizedToken = sanitizeSEOFieldToken( token );

		if ( sanitizedToken ) {
			onChange( [ ...value, sanitizedToken ] );
			setInputValue( '' );
		}

		inputRef.current?.focus();
	};

	const handleKeyDown = ( e: React.KeyboardEvent< HTMLInputElement > ) => {
		if ( e.key === 'Enter' && inputValue ) {
			e.preventDefault();
			add( inputValue );
		} else if ( e.key === 'Backspace' && ! inputValue && value.length ) {
			onChange( value.slice( 0, -1 ) );
		}
	};

	return (
		<div>
			<label
				htmlFor={ inputId }
				className="text-[11px] font-medium uppercase mb-1 block"
			>
				{ label }
			</label>
			{ /* Token list + inline input */ }
			<div className="border border-gray-400 rounded-xs group focus-within:border-[var(--wp-admin-theme-color)] overflow-hidden">
				<div className="flex flex-wrap items-center gap-1 p-1.5">
					{ value.map( ( token, i ) => {
						const isDynamic = fields.includes( token );
						return (
							<span
								key={ i }
								className={ classNames(
									'text-sm px-2 py-1 rounded-xs',
									isDynamic
										? 'bg-[#406ebc] text-white'
										: 'bg-gray-300 text-gray-700'
								) }
							>
								{ token.trim() }
							</span>
						);
					} ) }

					{ /* Inline input with dropdown */ }
					<input
						ref={ inputRef }
						id={ inputId }
						className="!shadow-none outline-none border-none p-0 text-sm w-full basis-20 grow-1 shrink-0"
						type="text"
						placeholder={ __( 'Type to add', 'newspack-profiles' ) }
						value={ inputValue }
						onChange={ ( e ) => {
							setInputValue( e.target.value );
							setOpen( true );
						} }
						onFocus={ () => setOpen( true ) }
						onBlur={ () =>
							setTimeout( () => {
								if ( ! inputRef.current?.matches( ':focus' ) ) {
									setOpen( false );
								}
							}, 100 )
						}
						onKeyDown={ handleKeyDown }
					/>
				</div>
				{ open && filtered.length > 0 && (
					<ul
						role="listbox"
						className="m-0 w-fit w-full bg-white max-h-48 overflow-auto border-t border-gray-400"
					>
						{ filtered.map( ( option, i ) => (
							<li
								key={ i }
								role="option"
								aria-selected={ false }
								className="m-0"
							>
								<button
									type="button"
									className="w-full text-start px-3 py-2 text-sm border-none bg-transparent text-gray-700 hover:bg-[var(--wp-admin-theme-color)] hover:text-white cursor-pointer"
									onClick={ ( e ) => {
										e.preventDefault();
										add( option );
									} }
								>
									{ option }
								</button>
							</li>
						) ) }
					</ul>
				) }
			</div>
			<p className="m-0 mt-1 text-gray-500">
				{ __(
					'Add fields from the list or type a delimiter (, / | - _) to separate them. Any other text will be included as-is.',
					'newspack-profiles'
				) }
			</p>
		</div>
	);
};
