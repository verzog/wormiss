/**
 * "iMIS event" panel in the Event editor sidebar. Plain JavaScript (no build step).
 *
 * Shows whether the event is sent to iMIS, what happened the last time, and places left. The
 * details themselves are edited in the page, in the event blocks (see event-blocks.js).
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function(wp) {
	'use strict';

	const el = wp.element.createElement;
	const {__, sprintf} = wp.i18n;
	const {useSelect} = wp.data;
	const {Notice} = wp.components;
	const Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel)
		|| (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	const data = window.wormissEventSync || {enabled: false, dryRun: true, template: ''};

	if (!Panel) {
		return;
	}

	/**
	 * The last sync result as a notice, or nothing.
	 *
	 * @param {Object} sync The read-only wormiss_sync field.
	 * @returns {Object|null} Element.
	 */
	function syncNotice(sync) {
		const status = sync.status || '';
		if (!status) {
			return null;
		}
		const kinds = {synced: 'success', linked: 'success', cancelled: 'warning', error: 'error'};
		return el(Notice, {status: kinds[status] || 'info', isDismissible: false},
			sync.message || status);
	}

	/**
	 * The panel: whether the event is sent, what happened last time, and places left. Its
	 * details are filled in in the "Event details" box under the content.
	 *
	 * @returns {Object} Element.
	 */
	function EventPanel() {
		const sync = useSelect((select) => select('core/editor').getCurrentPostAttribute('wormiss_sync') || {}, []);
		const imisId = sync.imis_id || '';
		const template = sync.template || data.template || '';
		let state;
		if (sync.linked) {
			state = __('Linked to an iMIS event: its details come from iMIS, and WordPress never changes it.', 'wormiss');
		} else if (!data.enabled) {
			state = __('Sending events to iMIS is off (Wormiss > Settings).', 'wormiss');
		} else if (data.dryRun) {
			state = __('Dry run: on publish or update, iMIS only checks this event.', 'wormiss');
		} else {
			state = __('On publish or update, this event is saved in iMIS.', 'wormiss');
		}

		return el(
			Panel,
			{name: 'wormiss-event', title: __('iMIS event', 'wormiss')},
			el('p', {className: 'description'}, state),
			!imisId && !sync.linked && el('p', {className: 'description'}, template
				? sprintf(__('A new event is copied from iMIS template %s (chosen by its event type, or the default).',
					'wormiss'), template)
				: __('No iMIS template yet: give the event type one, or set the default in Wormiss > Settings.', 'wormiss')),
			syncNotice(sync),
			imisId && el('p', {className: 'description'}, sprintf(__('iMIS event: %s', 'wormiss'), imisId)),
			sync.places && el('p', {className: 'description'},
				sprintf(__('Places in iMIS: %s', 'wormiss'), sync.places)),
			el('p', {className: 'description'},
				__('The dates, place, places, CPD hours and iMIS settings are edited in the page, in its event blocks.', 'wormiss'))
		);
	}

	wp.plugins.registerPlugin('wormiss-event', {render: EventPanel});
})(window.wp);
