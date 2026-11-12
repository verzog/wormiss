/**
 * Upcoming iMIS events block: editor script. Plain JavaScript (no build step).
 *
 * The block is rendered on the server from the iMIS Events API; the editor shows a
 * live preview and the display settings.
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
	const {PanelBody, SelectControl, TextControl, RangeControl, ToggleControl, Notice} = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const data = window.wormissEvents || {configured: false};

	registerBlockType('wormiss/events', {
		edit: function Edit(props) {
			const a = props.attributes;
			const set = props.setAttributes;
			const blockProps = useBlockProps();

			const controls = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{title: __('Events', 'wormiss')},
					!data.configured && el(Notice, {status: 'warning', isDismissible: false},
						__('The iMIS connection is not set up yet (Wormiss > Settings).', 'wormiss')),
					el(RangeControl, {
						label: __('Events to show', 'wormiss'),
						value: a.limit,
						min: 1,
						max: 50,
						onChange: (v) => set({limit: v || 6}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('Categories', 'wormiss'),
						help: __('Only events in these iMIS event categories, comma-separated. Blank for all.', 'wormiss'),
						value: a.category,
						onChange: (v) => set({category: v}),
						__nextHasNoMarginBottom: true,
					})
				),
				el(
					PanelBody,
					{title: __('Display', 'wormiss')},
					el(SelectControl, {
						label: __('Layout', 'wormiss'),
						value: a.layout,
						options: [
							{label: __('Cards', 'wormiss'), value: 'cards'},
							{label: __('List', 'wormiss'), value: 'list'},
						],
						onChange: (v) => set({layout: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(ToggleControl, {
						label: __('Show images (cards)', 'wormiss'),
						checked: a.showImage,
						onChange: (v) => set({showImage: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(ToggleControl, {
						label: __('Show a short description', 'wormiss'),
						checked: a.showDescription,
						onChange: (v) => set({showDescription: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('Button text', 'wormiss'),
						help: __('Defaults to "Find out more". The button opens the event in iMIS.', 'wormiss'),
						value: a.buttonText,
						onChange: (v) => set({buttonText: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('Text when there are no events', 'wormiss'),
						value: a.emptyText,
						onChange: (v) => set({emptyText: v}),
						__nextHasNoMarginBottom: true,
					})
				)
			);

			return el('div', blockProps, controls, el(ServerSideRender, {block: 'wormiss/events', attributes: a}));
		},

		save: function Save() {
			return null;
		},
	});
})(window.wp);
