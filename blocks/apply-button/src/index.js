/**
 * Editor source for the Apply Button block.
 *
 * Server rendered, so the editor preview and the front end come from the same
 * PHP renderer as the [zoho_job_apply] shortcode — including its decision to
 * render nothing when a job has no application URL or is closed.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import metadata from '../block.json';

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

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Button', 'jobs-sync-for-zoho-recruit' ) }
				>
					<TextControl
						label={ __( 'Label', 'jobs-sync-for-zoho-recruit' ) }
						help={ __(
							'Leave blank to use the label from the plugin settings.',
							'jobs-sync-for-zoho-recruit'
						) }
						value={ attributes.label }
						onChange={ ( label ) => setAttributes( { label } ) }
						__nextHasNoMarginBottom
					/>
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
