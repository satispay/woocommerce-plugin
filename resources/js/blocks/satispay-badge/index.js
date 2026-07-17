import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import {
	InspectorControls,
	useBlockProps,
} from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	ToggleControl,
	RangeControl,
	SelectControl,
} from '@wordpress/components';
import metadata from '../../../../blocks/satispay-badge/block.json';

const logoUrl = window.wooSatispayBadge?.logoUrl || '';

const SatispayBlockIcon = () => (
	<svg
		width="24"
		height="24"
		viewBox="0 0 25 25"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		aria-hidden="true"
		focusable="false"
	>
		<path
			fill="#FF3D00"
			d="M20.320312 0.574219L17.855469 2.839844L14.824219 0H20.097656C20.398438 0 20.550781 0.367188 20.320312 0.574219ZM14.832031 25H20.136719C20.441406 25 20.589844 24.625 20.359375 24.425781L17.871094 22.113281L14.832031 24.992188ZM1.324219 14.703125C0.695312 14.089844 0.355469 13.261719 0.355469 12.40625C0.355469 11.546875 0.71875 10.71875 1.347656 10.128906L7.136719 4.644531L11.484375 8.683594L7.710938 12.140625C7.636719 12.214844 7.589844 12.316406 7.589844 12.421875C7.589844 12.523438 7.636719 12.628906 7.71875 12.699219L11.535156 16.230469L7.183594 20.269531L1.316406 14.6875ZM4.941406 0H12.871094L23.652344 10.152344C24.28125 10.742188 24.644531 11.570312 24.644531 12.429688C24.652344 13.269531 24.304688 14.105469 23.699219 14.703125L12.902344 24.976562H4.917969C4.617188 24.976562 4.464844 24.609375 4.695312 24.402344L17.289062 12.738281C17.363281 12.667969 17.410156 12.5625 17.410156 12.460938C17.410156 12.355469 17.363281 12.253906 17.28125 12.183594L4.71875 0.574219C4.496094 0.375 4.648438 0 4.941406 0Z"
		/>
	</svg>
);

function Edit( { attributes, setAttributes } ) {
	const { label, showLabel, inlineContent, textAlign, logoWidth } = attributes;

	const blockProps = useBlockProps( {
		style: { textAlign },
	} );

	const previewContentStyle = inlineContent
		? {
			display: 'inline-flex',
			alignItems: 'center',
			gap: '8px',
			textAlign: 'left',
		}
		: {
			display: 'inline-block',
		};

	const imgStyle = {
		display: inlineContent ? 'inline-block' : 'block',
		width: logoWidth + 'px',
		height: 'auto',
		marginLeft: ! inlineContent && ( textAlign === 'center' || textAlign === 'right' ) ? 'auto' : undefined,
		marginRight: ! inlineContent && textAlign === 'center' ? 'auto' : undefined,
	};

	const labelStyle = inlineContent ? { margin: 0 } : { marginTop: '8px' };

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Satispay Badge Settings', 'woo-satispay' ) }>
					<ToggleControl
						label={ __( 'Show label', 'woo-satispay' ) }
						checked={ showLabel }
						onChange={ ( val ) => setAttributes( { showLabel: val } ) }
					/>
					<ToggleControl
						label={ __( 'Show logo and text on the same row', 'woo-satispay' ) }
						checked={ inlineContent }
						onChange={ ( val ) => setAttributes( { inlineContent: val } ) }
					/>
					{ showLabel && (
						<TextControl
							label={ __( 'Label text', 'woo-satispay' ) }
							value={ label }
							onChange={ ( val ) => setAttributes( { label: val } ) }
						/>
					) }
					<RangeControl
						label={ __( 'Logo width (px)', 'woo-satispay' ) }
						value={ logoWidth }
						onChange={ ( val ) => setAttributes( { logoWidth: val } ) }
						min={ 40 }
						max={ 400 }
					/>
					<SelectControl
						label={ __( 'Alignment', 'woo-satispay' ) }
						value={ textAlign }
						options={ [
							{ label: __( 'Left', 'woo-satispay' ), value: 'left' },
							{ label: __( 'Center', 'woo-satispay' ), value: 'center' },
							{ label: __( 'Right', 'woo-satispay' ), value: 'right' },
						] }
						onChange={ ( val ) => setAttributes( { textAlign: val } ) }
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<div style={ previewContentStyle }>
				{ logoUrl ? (
					<img
						src={ logoUrl }
						alt={ __( 'Satispay', 'woo-satispay' ) }
						style={ imgStyle }
					/>
				) : (
					<span style={ { fontStyle: 'italic', color: '#999' } }>
						{ __( 'Satispay logo', 'woo-satispay' ) }
					</span>
				) }
				{ showLabel && label && (
					<p style={ labelStyle }>{ label }</p>
				) }
				</div>
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	icon: <SatispayBlockIcon />,
	edit: Edit,
	save: () => null, // dynamic block — rendered server-side via render.php
} );





