/**
 * "iMIS event" panel in the Event editor sidebar. Plain JavaScript (no build step).
 *
 * Edits the event's code, start, end, place, online link and capacity, and shows what
 * happened the last time it was sent to iMIS.
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function(wp) {
	'use strict';

	const el = wp.element.createElement;
	const {__, sprintf} = wp.i18n;
	const {useSelect, useDispatch} = wp.data;
	const {TextControl, Notice} = wp.components;
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
		const kinds = {synced: 'success', cancelled: 'warning', error: 'error'};
		return el(Notice, {status: kinds[status] || 'info', isDismissible: false},
			sync.message || status);
	}

	/**
	 * The panel.
	 *
	 * @returns {Object} Element.
	 */
	function EventPanel() {
		const meta = useSelect((select) => select('core/editor').getEditedPostAttribute('meta') || {}, []);
		const sync = useSelect((select) => select('core/editor').getCurrentPostAttribute('wormiss_sync') || {}, []);
		const {editPost} = useDispatch('core/editor');
		const set = (key, value) => editPost({meta: {[key]: value}});
		const imisId = sync.imis_id || '';

		/**
		 * A text field for one meta key.
		 *
		 * @param {string} key Meta key.
		 * @param {string} label Label.
		 * @param {Object} extra Extra props.
		 * @returns {Object} Element.
		 */
		const field = (key, label, extra) => el(TextControl, Object.assign({
			label: label,
			value: meta[key] === undefined || meta[key] === null ? '' : String(meta[key]),
			onChange: (v) => set(key, v),
			__nextHasNoMarginBottom: true,
		}, extra || {}));

		const template = sync.template || data.template || '';
		let state;
		if (!data.enabled) {
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
			!imisId && el('p', {className: 'description'}, template
				? sprintf(__('A new event is copied from iMIS template %s (chosen by its event type, or the default).',
					'wormiss'), template)
				: __('No iMIS template yet: give the event type one, or set the default in Wormiss > Settings.', 'wormiss')),
			syncNotice(sync),
			field('_wormiss_event_code', __('Event code', 'wormiss'), {
				help: imisId
					? sprintf(__('In iMIS as %s. The code cannot change now.', 'wormiss'), imisId)
					: __('Letters, digits, _ and - only, e.g. CONF27. Becomes the iMIS event code.', 'wormiss'),
				disabled: !!imisId,
			}),
			field('_wormiss_event_start', __('Starts', 'wormiss'), {type: 'datetime-local'}),
			field('_wormiss_event_end', __('Ends', 'wormiss'), {type: 'datetime-local'}),
			field('_wormiss_event_location', __('Place', 'wormiss'), {help: __('Venue name. Blank keeps the template\'s.', 'wormiss')}),
			field('_wormiss_event_online', __('Online link', 'wormiss'), {type: 'url'}),
			field('_wormiss_event_capacity', __('Capacity', 'wormiss'), {
				type: 'number',
				min: 0,
				help: __('0 keeps the template\'s capacity.', 'wormiss'),
				onChange: (v) => set('_wormiss_event_capacity', parseInt(v, 10) || 0),
			})
		);
	}

	wp.plugins.registerPlugin('wormiss-event', {render: EventPanel});
})(window.wp);
