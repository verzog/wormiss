/**
 * Members only block: editor script. Plain JavaScript (no build step).
 *
 * Wraps any blocks; on the site they are shown only to members who meet the rules.
 * In the editor they are always visible, with a note of who can see them.
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function(wp) {
	'use strict';

	const el = wp.element.createElement;
	const {__, sprintf} = wp.i18n;
	const {registerBlockType} = wp.blocks;
	const {useBlockProps, useInnerBlocksProps, InspectorControls, InnerBlocks} = wp.blockEditor;
	const {PanelBody, TextControl, ToggleControl} = wp.components;

	registerBlockType('wormiss/members-only', {
		edit: function Edit(props) {
			const a = props.attributes;
			const set = props.setAttributes;
			const who = a.types
				? sprintf(__('Members only: %s', 'wormiss'), a.types)
				: __('Members only', 'wormiss');
			const blockProps = useBlockProps({className: 'wormiss-members-only-editor'});
			const innerProps = useInnerBlocksProps({}, {
				template: [['core/paragraph', {placeholder: __('Content only members can see', 'wormiss')}]],
			});

			return el(
				'div',
				blockProps,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{title: __('Who can see this', 'wormiss')},
						el(TextControl, {
							label: __('Membership types (optional)', 'wormiss'),
							help: __('Comma-separated, as in iMIS, e.g. "Fellow, Member". Blank for any type.', 'wormiss'),
							value: a.types,
							onChange: (v) => set({types: v}),
							__nextHasNoMarginBottom: true,
						}),
						el(TextControl, {
							label: __('Membership statuses (optional)', 'wormiss'),
							help: __('Comma-separated, e.g. "Active". Blank for the statuses set in Wormiss > Settings.', 'wormiss'),
							value: a.statuses,
							onChange: (v) => set({statuses: v}),
							__nextHasNoMarginBottom: true,
						}),
						el(ToggleControl, {
							label: __('Show a message to others', 'wormiss'),
							help: __('Off: others see nothing at all here.', 'wormiss'),
							checked: a.showNotice,
							onChange: (v) => set({showNotice: v}),
							__nextHasNoMarginBottom: true,
						}),
						a.showNotice && el(TextControl, {
							label: __('Message for signed-in members without access (optional)', 'wormiss'),
							value: a.notice,
							onChange: (v) => set({notice: v}),
							__nextHasNoMarginBottom: true,
						})
					)
				),
				el('p', {className: 'wormiss-members-only-editor__label'}, '🔒 ' + who),
				el('div', innerProps)
			);
		},

		save: function Save() {
			return el(InnerBlocks.Content);
		},
	});
})(window.wp);
