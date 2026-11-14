/**
 * Sign in block: editor script. Plain JavaScript (no build step).
 *
 * The forms are drawn on the server; the editor shows a preview.
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

	registerBlockType('wormiss/sign-in', {
		/**
		 * The editor view: the forms as visitors see them.
		 *
		 * @returns {Object} Element.
		 */
		edit: function() {
			return el('div', useBlockProps(), el(ServerSideRender, {block: 'wormiss/sign-in'}));
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
