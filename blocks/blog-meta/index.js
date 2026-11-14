/**
 * Blog post details block: editor script. Plain JavaScript (no build step).
 *
 * The details are drawn on the server; the editor shows a preview and a choice of what to show.
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
	const {PanelBody, SelectControl} = wp.components;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType('wormiss/blog-meta', {
		/**
		 * The editor view: a preview, and what to show in the sidebar.
		 *
		 * @param {Object} props Block props.
		 * @returns {Object} Element.
		 */
		edit: function(props) {
			const show = props.attributes.show;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{title: __('Show', 'wormiss')},
						el(SelectControl, {
							label: __('Details', 'wormiss'),
							value: show,
							options: [
								{label: __('Comment count (cards)', 'wormiss'), value: 'comments'},
								{label: __('Author, date and comments (under the title)', 'wormiss'), value: 'line'},
								{label: __('Back to posts, author, reading time and date', 'wormiss'), value: 'sidebar'}
							],
							/**
							 * Keep the choice.
							 *
							 * @param {string} value Chosen value.
							 */
							onChange: function(value) {
								props.setAttributes({show: value});
							}
						})
					)
				),
				el(ServerSideRender, {
					block: 'wormiss/blog-meta',
					attributes: {show: show}
				})
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
