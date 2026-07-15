/* eslint-disable @wordpress/no-unsafe-wp-apis */
import {
	Button,
	ColorIndicator,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
/* eslint-enable @wordpress/no-unsafe-wp-apis */
import { __ } from '@wordpress/i18n';
import { pencil } from '@wordpress/icons';
import type { ColorStyle } from './types';

type FallbackStyleControlProps = {
	fallbackStyle: ColorStyle;
	onEdit: () => void;
};

export const FallbackStyleControl = ( {
	fallbackStyle,
	onEdit,
}: FallbackStyleControlProps ) => (
	<VStack
		spacing={ 2 }
		className="wp-block-newspack-profiles-conditional-style__fallback"
	>
		<HStack
			justify="space-between"
			className="wp-block-newspack-profiles-conditional-style__fallback-header"
		>
			<p className="wp-block-newspack-profiles-conditional-style__fallback-title">
				{ __( 'Fallback style (no match)', 'newspack-profiles' ) }
			</p>
			<Button
				variant="tertiary"
				icon={ pencil }
				label={ __( 'Edit fallback style', 'newspack-profiles' ) }
				size="small"
				onClick={ onEdit }
			/>
		</HStack>

		<HStack
			justify="flex-start"
			spacing={ 2 }
			className="wp-block-newspack-profiles-conditional-style__row-detail"
		>
			<span className="wp-block-newspack-profiles-conditional-style__row-label">
				{ __( 'Text', 'newspack-profiles' ) }
			</span>
			<ColorIndicator colorValue={ fallbackStyle.textColor } />
			<span className="wp-block-newspack-profiles-conditional-style__row-code">
				{ fallbackStyle.textColor }
			</span>
		</HStack>

		<HStack
			justify="flex-start"
			spacing={ 2 }
			className="wp-block-newspack-profiles-conditional-style__row-detail"
		>
			<span className="wp-block-newspack-profiles-conditional-style__row-label">
				{ __( 'Background', 'newspack-profiles' ) }
			</span>
			<ColorIndicator colorValue={ fallbackStyle.backgroundColor } />
			<span className="wp-block-newspack-profiles-conditional-style__row-code">
				{ fallbackStyle.backgroundColor }
			</span>
		</HStack>
	</VStack>
);
