/**
 * Editor source for the Job Details block.
 *
 * Server rendered, so the editor preview and the front end come from the same
 * PHP renderer as the [zoho_job_meta] shortcode.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import metadata from '../block.json';

/**
 * Every field the renderer knows about, in the order it displays them.
 *
 * @return {Array<{key: string, label: string}>} Field definitions.
 */
function availableFields() {
	return [
		{
			key: 'department',
			label: __( 'Department', 'jobs-sync-for-zoho-recruit' ),
		},
		{
			key: 'location',
			label: __( 'Location', 'jobs-sync-for-zoho-recruit' ),
		},
		{
			key: 'employment_type',
			label: __( 'Employment type', 'jobs-sync-for-zoho-recruit' ),
		},
		{
			key: 'category',
			label: __( 'Category', 'jobs-sync-for-zoho-recruit' ),
		},
		{
			key: 'experience',
			label: __( 'Experience', 'jobs-sync-for-zoho-recruit' ),
		},
		{
			key: 'job_code',
			label: __( 'Job code', 'jobs-sync-for-zoho-recruit' ),
		},
		{ key: 'salary', label: __( 'Salary', 'jobs-sync-for-zoho-recruit' ) },
		{
			key: 'industry',
			label: __( 'Industry', 'jobs-sync-for-zoho-recruit' ),
		},
		{ key: 'client', label: __( 'Client', 'jobs-sync-for-zoho-recruit' ) },
		{
			key: 'remote',
			label: __( 'Work mode', 'jobs-sync-for-zoho-recruit' ),
		},
		{
			key: 'positions',
			label: __( 'Openings', 'jobs-sync-for-zoho-recruit' ),
		},
		{
			key: 'posted_date',
			label: __( 'Posted date', 'jobs-sync-for-zoho-recruit' ),
		},
		{
			key: 'closing_date',
			label: __( 'Closing date', 'jobs-sync-for-zoho-recruit' ),
		},
	];
}

/**
 * Split the stored comma list into an array of field keys.
 *
 * @param {string} value Stored attribute value.
 * @return {string[]} Field keys.
 */
function toList( value ) {
	return String( value || '' )
		.split( ',' )
		.map( ( item ) => item.trim() )
		.filter( ( item ) => item.length > 0 );
}

/**
 * Editor view. Server rendered, so the preview is the real output.
 *
 * @param {Object}   props               Block props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @param {Object}   props.context       Block context, carrying the post ID.
 * @return {Element} The editor markup.
 */
function Edit( { attributes, setAttributes, context } ) {
	const blockProps = useBlockProps();
	const fields = availableFields();
	const selected = toList( attributes.fields );

	/**
	 * Add or remove one field, preserving the documented display order.
	 *
	 * @param {string}  key       Field key.
	 * @param {boolean} isEnabled Whether the field should be shown.
	 */
	const toggleField = ( key, isEnabled ) => {
		const next = fields
			.filter( ( field ) =>
				field.key === key ? isEnabled : selected.includes( field.key )
			)
			.map( ( field ) => field.key );

		setAttributes( { fields: next.join( ',' ) } );
	};

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Fields', 'jobs-sync-for-zoho-recruit' ) }
				>
					{ fields.map( ( field ) => (
						<ToggleControl
							key={ field.key }
							label={ field.label }
							checked={ selected.includes( field.key ) }
							onChange={ ( isEnabled ) =>
								toggleField( field.key, isEnabled )
							}
							__nextHasNoMarginBottom
						/>
					) ) }
				</PanelBody>
			</InspectorControls>

			<ServerSideRender
				block={ metadata.name }
				attributes={ attributes }
				urlQueryArgs={ { post_id: context.postId } }
				httpMethod="POST"
			/>
		</div>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,

	save() {
		// Server rendered: the front end and the editor preview both come from
		// the same PHP renderer as the shortcode.
		return null;
	},
} );
