/**
 * Open events block: editor script. Plain JavaScript (no build step).
 *
 * The cards are drawn on the server; the editor shows a live preview and the choice of event category.
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function(wp) {
	'use strict';

	const el = wp.element.createElement;
	const {__} = wp.i18n;
	const {registerBlockType} = wp.blocks;
	const {useBlockProps, InspectorControls} = wp.blockEditor;
	const {PanelBody, SelectControl, TextControl, RangeControl, ToggleControl} = wp.components;
	const {useSelect} = wp.data;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType('wormiss/open-events', {
		/**
		 * The editor view: a preview, and the settings in the sidebar.
		 *
		 * @param {Object} props Block props.
		 * @returns {Object} Element.
		 */
		edit: function Edit(props) {
			const a = props.attributes;
			const set = props.setAttributes;
			const types = useSelect(function(select) {
				return select('core').getEntityRecords('taxonomy', 'wormiss_event_type', {per_page: -1, orderby: 'name'});
			}, []) || [];
			const options = [{label: __('Every category', 'wormiss'), value: ''}].concat(types.map(function(type) {
				return {label: (type.parent ? '— ' : '') + type.name, value: type.slug};
			}));

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{title: __('Events', 'wormiss')},
						el(SelectControl, {
							label: __('Event category', 'wormiss'),
							value: a.type,
							options: options,
							onChange: (v) => set({type: v}),
							__nextHasNoMarginBottom: true,
						}),
						el(ToggleControl, {
							label: __('Include its sub-categories', 'wormiss'),
							checked: a.children,
							onChange: (v) => set({children: v}),
							__nextHasNoMarginBottom: true,
						}),
						el(RangeControl, {
							label: __('Most to show', 'wormiss'),
							value: a.limit,
							min: 1,
							max: 50,
							onChange: (v) => set({limit: v || 12}),
							__nextHasNoMarginBottom: true,
						})
					),
					el(
						PanelBody,
						{title: __('Display', 'wormiss')},
						el(ToggleControl, {
							label: __('Show the summary', 'wormiss'),
							checked: a.showDescription,
							onChange: (v) => set({showDescription: v}),
							__nextHasNoMarginBottom: true,
						}),
						el(TextControl, {
							label: __('Button text', 'wormiss'),
							help: __('Blank for "Learn more".', 'wormiss'),
							value: a.buttonText,
							onChange: (v) => set({buttonText: v}),
							__nextHasNoMarginBottom: true,
						}),
						el(TextControl, {
							label: __('When none are open', 'wormiss'),
							help: __('Blank for "Nothing is open for enrolment at the moment."', 'wormiss'),
							value: a.emptyText,
							onChange: (v) => set({emptyText: v}),
							__nextHasNoMarginBottom: true,
						})
					)
				),
				el(ServerSideRender, {block: 'wormiss/open-events', attributes: a})
			);
		},
		/**
		 * Drawn on the server.
		 *
		 * @returns {null} Nothing saved.
		 */
		save: function() {
			return null;
		}
	});
}(window.wp));
