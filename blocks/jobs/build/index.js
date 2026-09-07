/**
 * Editor script for the Zoho Recruit Jobs block.
 *
 * This is the built asset loaded by WordPress. It is intentionally shipped
 * unminified and without a source map so the code that runs is the code you can
 * read. The JSX source it corresponds to lives in ../src/index.js and can be
 * rebuilt with `npm run build`.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var components = wp.components;
	var ServerSideRender = wp.serverSideRender;

	var BLOCK_NAME = 'jobs-sync-for-zoho-recruit/jobs';
	var DOMAIN = 'jobs-sync-for-zoho-recruit';

	/**
	 * Build a change handler for one attribute.
	 *
	 * @param {Function} setAttributes Block setter.
	 * @param {string}   key           Attribute name.
	 * @return {Function} Handler.
	 */
	function setter( setAttributes, key ) {
		return function ( value ) {
			var update = {};

			update[ key ] = value;

			setAttributes( update );
		};
	}

	/**
	 * Text field bound to an attribute.
	 *
	 * @param {Object}   attributes    Block attributes.
	 * @param {Function} setAttributes Block setter.
	 * @param {string}   key           Attribute name.
	 * @param {string}   label         Field label.
	 * @param {string}   help          Optional help text.
	 * @return {Object} Element.
	 */
	function text( attributes, setAttributes, key, label, help ) {
		return el( components.TextControl, {
			label: label,
			help: help,
			value: attributes[ key ],
			onChange: setter( setAttributes, key ),
			__nextHasNoMarginBottom: true
		} );
	}

	/**
	 * Toggle bound to a boolean attribute.
	 *
	 * @param {Object}   attributes    Block attributes.
	 * @param {Function} setAttributes Block setter.
	 * @param {string}   key           Attribute name.
	 * @param {string}   label         Field label.
	 * @return {Object} Element.
	 */
	function toggle( attributes, setAttributes, key, label ) {
		return el( components.ToggleControl, {
			label: label,
			checked: !! attributes[ key ],
			onChange: setter( setAttributes, key ),
			__nextHasNoMarginBottom: true
		} );
	}

	registerBlockType( BLOCK_NAME, {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			var listingPanel = el(
				components.PanelBody,
				{ title: __( 'Listing', DOMAIN ) },
				el( components.RangeControl, {
					label: __( 'Jobs to show', DOMAIN ),
					value: attributes.perPage,
					onChange: setter( setAttributes, 'perPage' ),
					min: 1,
					max: 50,
					__nextHasNoMarginBottom: true
				} ),
				el( components.SelectControl, {
					label: __( 'Listing style', DOMAIN ),
					value: attributes.listingStyle,
					options: [
						{ label: __( 'List', DOMAIN ), value: 'list' },
						{ label: __( 'Grid', DOMAIN ), value: 'grid' }
					],
					onChange: setter( setAttributes, 'listingStyle' ),
					__nextHasNoMarginBottom: true
				} ),
				'grid' === attributes.listingStyle
					? el( components.RangeControl, {
						label: __( 'Columns', DOMAIN ),
						value: attributes.columns,
						onChange: setter( setAttributes, 'columns' ),
						min: 1,
						max: 4,
						__nextHasNoMarginBottom: true
					} )
					: null,
				el( components.SelectControl, {
					label: __( 'Order by', DOMAIN ),
					value: attributes.orderby,
					options: [
						{ label: __( 'Date published', DOMAIN ), value: 'date' },
						{ label: __( 'Title', DOMAIN ), value: 'title' },
						{ label: __( 'Closing date', DOMAIN ), value: 'closing_date' },
						{ label: __( 'Posted date', DOMAIN ), value: 'posted_date' }
					],
					onChange: setter( setAttributes, 'orderby' ),
					__nextHasNoMarginBottom: true
				} ),
				el( components.SelectControl, {
					label: __( 'Order', DOMAIN ),
					value: attributes.order,
					options: [
						{ label: __( 'Descending', DOMAIN ), value: 'desc' },
						{ label: __( 'Ascending', DOMAIN ), value: 'asc' }
					],
					onChange: setter( setAttributes, 'order' ),
					__nextHasNoMarginBottom: true
				} )
			);

			var filterPanel = el(
				components.PanelBody,
				{
					title: __( 'Filters', DOMAIN ),
					initialOpen: false
				},
				text( attributes, setAttributes, 'search', __( 'Keyword', DOMAIN ) ),
				text(
					attributes,
					setAttributes,
					'department',
					__( 'Department', DOMAIN ),
					__( 'Term slug or name. Separate several with commas.', DOMAIN )
				),
				text( attributes, setAttributes, 'location', __( 'Location', DOMAIN ) ),
				text( attributes, setAttributes, 'employmentType', __( 'Employment type', DOMAIN ) ),
				text( attributes, setAttributes, 'category', __( 'Category', DOMAIN ) ),
				text( attributes, setAttributes, 'experience', __( 'Experience', DOMAIN ) )
			);

			var controlsPanel = el(
				components.PanelBody,
				{
					title: __( 'Visitor controls', DOMAIN ),
					initialOpen: false
				},
				toggle( attributes, setAttributes, 'showFilters', __( 'Show filter dropdowns', DOMAIN ) ),
				toggle( attributes, setAttributes, 'showSearch', __( 'Show search box', DOMAIN ) ),
				toggle( attributes, setAttributes, 'showPagination', __( 'Show pagination', DOMAIN ) ),
				toggle( attributes, setAttributes, 'showExcerpt', __( 'Show excerpts', DOMAIN ) )
			);

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					listingPanel,
					filterPanel,
					controlsPanel
				),
				ServerSideRender
					? el( ServerSideRender, {
						block: BLOCK_NAME,
						attributes: attributes,
						httpMethod: 'POST'
					} )
					: el(
						components.Placeholder,
						{ label: __( 'Zoho Recruit Jobs', DOMAIN ) },
						__( 'The preview component is unavailable in this editor.', DOMAIN )
					)
			);
		},

		save: function () {
			// Rendered on the server so the block and the shortcode stay identical.
			return null;
		}
	} );
}( window.wp ) );
