/**
 * Editor source for the Zoho Recruit Jobs block.
 *
 * Build with `npm run build`, which writes blocks/jobs/build/index.js.
 * The block is server rendered, so the editor preview and the front end always
 * come from the same PHP renderer.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import metadata from '../block.json';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps();

		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody title={ __( 'Listing', 'jobs-sync-for-zoho-recruit' ) }>
						<RangeControl
							label={ __( 'Jobs to show', 'jobs-sync-for-zoho-recruit' ) }
							value={ attributes.perPage }
							onChange={ ( perPage ) => setAttributes( { perPage } ) }
							min={ 1 }
							max={ 50 }
						/>
						<SelectControl
							label={ __( 'Listing style', 'jobs-sync-for-zoho-recruit' ) }
							value={ attributes.listingStyle }
							options={ [
								{ label: __( 'List', 'jobs-sync-for-zoho-recruit' ), value: 'list' },
								{ label: __( 'Grid', 'jobs-sync-for-zoho-recruit' ), value: 'grid' },
							] }
							onChange={ ( listingStyle ) => setAttributes( { listingStyle } ) }
						/>
						{ attributes.listingStyle === 'grid' && (
							<RangeControl
								label={ __( 'Columns', 'jobs-sync-for-zoho-recruit' ) }
								value={ attributes.columns }
								onChange={ ( columns ) => setAttributes( { columns } ) }
								min={ 1 }
								max={ 4 }
							/>
						) }
						<SelectControl
							label={ __( 'Order by', 'jobs-sync-for-zoho-recruit' ) }
							value={ attributes.orderby }
							options={ [
								{ label: __( 'Date published', 'jobs-sync-for-zoho-recruit' ), value: 'date' },
								{ label: __( 'Title', 'jobs-sync-for-zoho-recruit' ), value: 'title' },
								{ label: __( 'Closing date', 'jobs-sync-for-zoho-recruit' ), value: 'closing_date' },
								{ label: __( 'Posted date', 'jobs-sync-for-zoho-recruit' ), value: 'posted_date' },
							] }
							onChange={ ( orderby ) => setAttributes( { orderby } ) }
						/>
						<SelectControl
							label={ __( 'Order', 'jobs-sync-for-zoho-recruit' ) }
							value={ attributes.order }
							options={ [
								{ label: __( 'Descending', 'jobs-sync-for-zoho-recruit' ), value: 'desc' },
								{ label: __( 'Ascending', 'jobs-sync-for-zoho-recruit' ), value: 'asc' },
							] }
							onChange={ ( order ) => setAttributes( { order } ) }
						/>
					</PanelBody>

					<PanelBody
						title={ __( 'Filters', 'jobs-sync-for-zoho-recruit' ) }
						initialOpen={ false }
					>
						<TextControl
							label={ __( 'Keyword', 'jobs-sync-for-zoho-recruit' ) }
							value={ attributes.search }
							onChange={ ( search ) => setAttributes( { search } ) }
						/>
						<TextControl
							label={ __( 'Department', 'jobs-sync-for-zoho-recruit' ) }
							help={ __( 'Term slug or name. Separate several with commas.', 'jobs-sync-for-zoho-recruit' ) }
							value={ attributes.department }
							onChange={ ( department ) => setAttributes( { department } ) }
						/>
						<TextControl
							label={ __( 'Location', 'jobs-sync-for-zoho-recruit' ) }
							value={ attributes.location }
							onChange={ ( location ) => setAttributes( { location } ) }
						/>
						<TextControl
							label={ __( 'Employment type', 'jobs-sync-for-zoho-recruit' ) }
							value={ attributes.employmentType }
							onChange={ ( employmentType ) => setAttributes( { employmentType } ) }
						/>
						<TextControl
							label={ __( 'Category', 'jobs-sync-for-zoho-recruit' ) }
							value={ attributes.category }
							onChange={ ( category ) => setAttributes( { category } ) }
						/>
						<TextControl
							label={ __( 'Experience', 'jobs-sync-for-zoho-recruit' ) }
							value={ attributes.experience }
							onChange={ ( experience ) => setAttributes( { experience } ) }
						/>
					</PanelBody>

					<PanelBody
						title={ __( 'Visitor controls', 'jobs-sync-for-zoho-recruit' ) }
						initialOpen={ false }
					>
						<ToggleControl
							label={ __( 'Show filter dropdowns', 'jobs-sync-for-zoho-recruit' ) }
							checked={ attributes.showFilters }
							onChange={ ( showFilters ) => setAttributes( { showFilters } ) }
						/>
						<ToggleControl
							label={ __( 'Show search box', 'jobs-sync-for-zoho-recruit' ) }
							checked={ attributes.showSearch }
							onChange={ ( showSearch ) => setAttributes( { showSearch } ) }
						/>
						<ToggleControl
							label={ __( 'Show pagination', 'jobs-sync-for-zoho-recruit' ) }
							checked={ attributes.showPagination }
							onChange={ ( showPagination ) => setAttributes( { showPagination } ) }
						/>
						<ToggleControl
							label={ __( 'Show excerpts', 'jobs-sync-for-zoho-recruit' ) }
							checked={ attributes.showExcerpt }
							onChange={ ( showExcerpt ) => setAttributes( { showExcerpt } ) }
						/>
					</PanelBody>
				</InspectorControls>

				<ServerSideRender
					block={ metadata.name }
					attributes={ attributes }
					httpMethod="POST"
				/>
			</div>
		);
	},

	save() {
		// Server rendered.
		return null;
	},
} );
