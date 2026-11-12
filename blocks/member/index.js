/**
 * My iMIS data block: editor script. Plain JavaScript (no build step).
 *
 * Shows the signed-in member their own iMIS data. The member's iMIS ID is added
 * on the server; the editor only chooses an approved member query and how to show it.
 * The preview shows the editor's own data, if their account has an iMIS ID.
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
	const data = window.wormissMember || {queries: [], configured: false};

	registerBlockType('wormiss/member', {
		edit: function Edit(props) {
			const a = props.attributes;
			const set = props.setAttributes;
			const blockProps = useBlockProps();
			const options = [{label: __('Choose a member query', 'wormiss'), value: ''}].concat(
				data.queries.map((q) => ({label: q, value: q}))
			);

			const controls = el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{title: __('Member query', 'wormiss')},
					!data.configured && el(Notice, {status: 'warning', isDismissible: false},
						__('The iMIS connection is not set up yet (Wormiss > Settings).', 'wormiss')),
					el(SelectControl, {
						label: __('Query', 'wormiss'),
						help: __('Only member queries approved by an administrator are listed. '
							+ 'The signed-in member\'s iMIS ID is always its first filter value.', 'wormiss'),
						value: a.query,
						options: options,
						onChange: (v) => set({query: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('More filter values', 'wormiss'),
						help: __('Values for any further prompts, after the iMIS ID, separated by | (optional).', 'wormiss'),
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
							{label: __('Details (one record)', 'wormiss'), value: 'details'},
							{label: __('Table', 'wormiss'), value: 'table'},
							{label: __('Cards', 'wormiss'), value: 'cards'},
							{label: __('List', 'wormiss'), value: 'list'},
						],
						onChange: (v) => set({layout: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('Columns', 'wormiss'),
						help: __('Comma-separated, optionally with a heading: "Status=MemberStatus, Paid to=PaidThru". '
							+ 'Leave blank for all columns.', 'wormiss'),
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
						help: __('Optional web address with column names in braces. Not used by the Details layout.', 'wormiss'),
						value: a.link,
						onChange: (v) => set({link: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('Text when there are no results', 'wormiss'),
						value: a.emptyText,
						onChange: (v) => set({emptyText: v}),
						__nextHasNoMarginBottom: true,
					}),
					el(TextControl, {
						label: __('Text for visitors who are not signed in', 'wormiss'),
						help: __('Shown as a sign-in link. Default: "Sign in to see your details."', 'wormiss'),
						value: a.signedOutText,
						onChange: (v) => set({signedOutText: v}),
						__nextHasNoMarginBottom: true,
					})
				)
			);

			const preview = a.query
				? el(ServerSideRender, {block: 'wormiss/member', attributes: a})
				: el(Placeholder, {
					icon: 'id',
					label: __('My iMIS data', 'wormiss'),
					instructions: data.queries.length
						? __('Choose a member query in the block settings.', 'wormiss')
						: __('No member queries are approved yet. An administrator can add them in Wormiss > Settings.',
							'wormiss'),
				});

			return el('div', blockProps, controls, preview);
		},

		save: function Save() {
			return null;
		},
	});
})(window.wp);
