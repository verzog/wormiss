/**
 * My membership block: editor script. Plain JavaScript (no build step).
 *
 * The details are drawn on the server for the signed-in member; the editor shows a preview.
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

	registerBlockType('wormiss/my-membership', {
		/**
		 * The editor view: a preview (the editor's own membership, if they have one).
		 *
		 * @returns {Object} Element.
		 */
		edit: function() {
			return el('div', useBlockProps(), el(ServerSideRender, {block: 'wormiss/my-membership'}));
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
