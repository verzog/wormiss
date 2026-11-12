/**
 * "Members only" panel in the post editor sidebar. Plain JavaScript (no build step).
 *
 * Saves three post meta fields: whether the post is members-only, and optionally which
 * iMIS membership types and statuses may read it.
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function(wp) {
	'use strict';

	const el = wp.element.createElement;
	const {__} = wp.i18n;
	const {useSelect, useDispatch} = wp.data;
	const {ToggleControl, TextControl, Notice} = wp.components;
	const Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel)
		|| (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	const data = window.wormissAccess || {accessQuery: false};

	if (!Panel) {
		return;
	}

	/**
	 * The panel: a members-only switch and the optional type and status lists.
	 *
	 * @returns {Object} Element.
	 */
	function MembersOnlyPanel() {
		const meta = useSelect((select) => select('core/editor').getEditedPostAttribute('meta') || {}, []);
		const {editPost} = useDispatch('core/editor');
		const set = (key, value) => editPost({meta: {[key]: value}});
		const enabled = !!meta._wormiss_members_only;

		return el(
			Panel,
			{name: 'wormiss-members-only', title: __('Members only', 'wormiss')},
			el(ToggleControl, {
				label: __('Only members can read this', 'wormiss'),
				help: __('Others see the title and excerpt, then a sign-in message.', 'wormiss'),
				checked: enabled,
				onChange: (v) => set('_wormiss_members_only', v),
				__nextHasNoMarginBottom: true,
			}),
			enabled && el(TextControl, {
				label: __('Membership types (optional)', 'wormiss'),
				help: __('Comma-separated, as in iMIS, e.g. "Fellow, Member". Blank for any type.', 'wormiss'),
				value: meta._wormiss_member_types || '',
				onChange: (v) => set('_wormiss_member_types', v),
				__nextHasNoMarginBottom: true,
			}),
			enabled && el(TextControl, {
				label: __('Membership statuses (optional)', 'wormiss'),
				help: __('Comma-separated, e.g. "Active". Blank for the statuses set in Wormiss > Settings.', 'wormiss'),
				value: meta._wormiss_member_statuses || '',
				onChange: (v) => set('_wormiss_member_statuses', v),
				__nextHasNoMarginBottom: true,
			}),
			enabled && !data.accessQuery && el(Notice, {status: 'info', isDismissible: false},
				__('No membership query is set, so any signed-in member with an iMIS ID can read this; '
					+ 'type and status rules need one (Wormiss > Settings).', 'wormiss'))
		);
	}

	wp.plugins.registerPlugin('wormiss-members-only', {render: MembersOnlyPanel});
})(window.wp);
