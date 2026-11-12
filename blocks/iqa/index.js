/**
 * iMIS data block: editor script. Plain JavaScript (no build step).
 *
 * The block is rendered on the server; the editor shows a live preview and the
 * settings. Only queries approved in Wormiss > Settings can be chosen.
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
	const {PanelBody, SelectControl, TextControl, RangeControl, Placeholder, Notice} = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const data = window.wormissIqa || {queries: [], configured: false};

	registerBlockType('wormiss/iqa', {
		edit: function Edit(props) {
			const a = props.attributes;
			const set = props.setAttributes;
			const blockProps = useBlockProps();
			const options = [{label: __('Choose a query', 'wormiss'), value: ''}].concat(
				data.queries.map((q) => ({label: q, value: q}))
			);

			const controls = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{title: __('iMIS query', 'wormiss')},
					!data.configured && el(Notice, {status: 'warning', isDismissible: false},
						__('The iMIS connection is not set up yet (Wormiss > Settings).', 'wormiss')),
					el(SelectControl, {
						label: __('Query', 'wormiss'),
						help: __('Only queries approved by an administrator are listed.', 'wormiss'),
						value: a.query,
						options: options,
						onChange: (v) => set({query: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('Query filter values', 'wormiss'),
						help: __('Values for the query\'s prompts, in order, separated by | (optional).', 'wormiss'),
						value: a.parameters,
						onChange: (v) => set({parameters: v}),
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
							{label: __('Table', 'wormiss'), value: 'table'},
							{label: __('Cards', 'wormiss'), value: 'cards'},
							{label: __('List', 'wormiss'), value: 'list'},
						],
						onChange: (v) => set({layout: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('Columns', 'wormiss'),
						help: __('Comma-separated, optionally with a heading: "Event=EventName, Date=StartDate". '
							+ 'Leave blank for all columns. The first column is the card or list heading.', 'wormiss'),
						value: a.columns,
						onChange: (v) => set({columns: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(RangeControl, {
						label: __('Rows to show', 'wormiss'),
						value: a.limit,
						min: 1,
						max: 100,
						onChange: (v) => set({limit: v || 20}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('Link for each row', 'wormiss'),
						help: __('Optional web address with column names in braces, e.g. '
							+ 'https://members.example.org/Events/Detail?id={EventKey}. Links the first column.', 'wormiss'),
						value: a.link,
						onChange: (v) => set({link: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('Text when there are no results', 'wormiss'),
						value: a.emptyText,
						onChange: (v) => set({emptyText: v}),
						__nextHasNoMarginBottom: true,
					})
				)
			);

			const preview = a.query
				? el(ServerSideRender, {block: 'wormiss/iqa', attributes: a})
				: el(Placeholder, {
					icon: 'database',
					label: __('iMIS data', 'wormiss'),
					instructions: data.queries.length
						? __('Choose a query in the block settings.', 'wormiss')
						: __('No queries are approved yet. An administrator can add them in Wormiss > Settings.', 'wormiss'),
				});

			return el('div', blockProps, controls, preview);
		},

		save: function Save() {
			return null;
		},
	});
})(window.wp);
