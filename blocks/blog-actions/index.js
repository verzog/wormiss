/**
 * Blog post actions block: editor script. Plain JavaScript (no build step).
 *
 * The rail is drawn on the server; the editor shows a preview.
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function(wp) {
	'use strict';

	const el = wp.element.createElement;
	const {registerBlockType} = wp.blocks;
	const {useBlockProps} = wp.blockEditor;
	const ServerSideRender = wp.serverSideRender;

	registerBlockType('wormiss/blog-actions', {
		/**
		 * The editor view: a preview.
		 *
		 * @returns {Object} Element.
		 */
		edit: function() {
			return el(
				'div',
				useBlockProps(),
				el(ServerSideRender, {block: 'wormiss/blog-actions'})
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
