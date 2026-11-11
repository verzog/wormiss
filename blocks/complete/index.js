/**
 * Mark as done block: editor script. Plain JavaScript (no build step).
 *
 * Each block gets its own completion ID (a new one when duplicated), so members'
 * completions are counted per block. Points and the activity name are read from the
 * saved block on the server, never from the button.
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function(wp) {
	'use strict';

	const el = wp.element.createElement;
	const {useEffect} = wp.element;
	const {__, sprintf} = wp.i18n;
	const {registerBlockType} = wp.blocks;
	const {useBlockProps, InspectorControls} = wp.blockEditor;
	const {PanelBody, TextControl} = wp.components;
	const {select} = wp.data;

	/**
	 * A new random completion ID.
	 *
	 * @returns {string} ID.
	 */
	const newId = () => (window.crypto && window.crypto.randomUUID)
		? window.crypto.randomUUID()
		: 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);

	registerBlockType('wormiss/complete', {
		edit: function Edit(props) {
			const a = props.attributes;
			const set = props.setAttributes;
			const blockProps = useBlockProps();

			// Give the block an ID, and a fresh one if it is a copy of another block in the post.
			useEffect(() => {
				const editor = select('core/block-editor');
				const others = (editor.getBlocksByName ? editor.getBlocksByName('wormiss/complete') : [])
					.filter((id) => id !== props.clientId);
				const taken = others.some((id) => (editor.getBlockAttributes(id) || {}).completionId === a.completionId);
				if (!a.completionId || taken) {
					set({completionId: newId()});
				}
			}, []); // eslint-disable-line react-hooks/exhaustive-deps

			const points = Number(a.points) || 0;
			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{title: __('Completion', 'wormiss')},
						el(TextControl, {
							label: __('Button text', 'wormiss'),
							placeholder: __('Mark as done', 'wormiss'),
							value: a.label,
							onChange: (v) => set({label: v}),
							__nextHasNoMarginBottom: true,
						}),
						el(TextControl, {
							label: __('Text once done', 'wormiss'),
							placeholder: __('Done', 'wormiss'),
							value: a.doneLabel,
							onChange: (v) => set({doneLabel: v}),
							__nextHasNoMarginBottom: true,
						}),
						el(TextControl, {
							label: __('Activity name in iMIS', 'wormiss'),
							help: __('Defaults to the page title.', 'wormiss'),
							value: a.activity,
							onChange: (v) => set({activity: v}),
							__nextHasNoMarginBottom: true,
						}),
						el(TextControl, {
							label: __('CPD points (optional)', 'wormiss'),
							type: 'number',
							min: 0,
							step: 0.25,
							value: a.points,
							onChange: (v) => set({points: Math.max(0, parseFloat(v) || 0)}),
							__nextHasNoMarginBottom: true,
						})
					)
				),
				el('div', {className: 'wp-block-button'},
					el('span', {className: 'wp-block-button__link wp-element-button'}, a.label || __('Mark as done', 'wormiss'))),
				points > 0 && el('p', {className: 'wormiss-complete__points'},
					sprintf(__('Records %s CPD points in iMIS.', 'wormiss'), points))
			);
		},

		save: function Save() {
			return null;
		},
	});
})(window.wp);
