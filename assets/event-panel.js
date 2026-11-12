/**
 * "iMIS event" panel in the Event editor sidebar. Plain JavaScript (no build step).
 *
 * Shows whether the event is sent to iMIS, what happened the last time, and places left. The
 * details themselves are edited in the "Event details" box under the content, which a button
 * here opens and scrolls to.
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function(wp) {
	'use strict';

	const el = wp.element.createElement;
	const {__, sprintf} = wp.i18n;
	const {useSelect} = wp.data;
	const {Notice, Button} = wp.components;
	const Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel)
		|| (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	const data = window.wormissEventSync || {enabled: false, dryRun: true, template: ''};

	if (!Panel) {
		return;
	}

	/**
	 * Show the "Event details" box: open the editor's meta box panel at the bottom if it is
	 * closed (newer WordPress folds it away under "Meta Boxes"), open the box if it is collapsed,
	 * then scroll to it and move focus to its first tab.
	 */
	function openDetails() {
		const box = document.getElementById('wormiss-event-details');
		const panel = document.querySelector('.edit-post-meta-boxes-main');
		// The panel's open state is a saved preference (WordPress 6.8 and later).
		const preferences = wp.data.dispatch('core/preferences');
		if (preferences && preferences.set) {
			preferences.set('core/edit-post', 'metaBoxesMainIsOpen', true);
		}
		// The panel's own "Meta Boxes" toggle (not a box's collapse button).
		const toggle = panel && panel.querySelector('.edit-post-meta-boxes-main__presenter[aria-expanded="false"],'
			+ ' .edit-post-meta-boxes-main__presenter button[aria-expanded="false"]');
		if (toggle) {
			toggle.click();
		}
		if (box && box.classList.contains('closed')) {
			const handle = box.querySelector('.handlediv');
			if (handle) {
				handle.click();
			} else {
				box.classList.remove('closed');
			}
		}
		// After the panel has opened.
		window.setTimeout(() => {
			const target = document.getElementById('wormiss-event-details');
			if (!target) {
				return;
			}
			target.scrollIntoView({behavior: 'smooth', block: 'start'});
			const tab = target.querySelector('[role="tab"][aria-selected="true"], [role="tab"], input, select');
			if (tab) {
				tab.focus({preventScroll: true});
			}
		}, 300);
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
				__('Fill in the dates, place, places, waitlist and CPD hours in "Event details" below the content.', 'wormiss')),
			el(Button, {variant: 'secondary', onClick: openDetails}, __('Open Event details', 'wormiss'))
		);
	}

	wp.plugins.registerPlugin('wormiss-event', {render: EventPanel});
})(window.wp);
