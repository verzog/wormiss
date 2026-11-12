/**
 * "iMIS event" panel in the Event editor sidebar. Plain JavaScript (no build step).
 *
 * Edits the event's code, start, end, place, address, online link, numbers and waitlist, and shows
 * places left and what happened the last time it was sent to iMIS.
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function(wp) {
	'use strict';

	const el = wp.element.createElement;
	const {__, sprintf} = wp.i18n;
	const {useSelect, useDispatch} = wp.data;
	const {TextControl, SelectControl, Notice} = wp.components;
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

		/**
		 * A whole-number field for one meta key.
		 *
		 * @param {string} key Meta key.
		 * @param {string} label Label.
		 * @param {string} help Help text.
		 * @returns {Object} Element.
		 */
		const number = (key, label, help) => field(key, label, {
			type: 'number',
			min: 0,
			help: help,
			onChange: (v) => set(key, parseInt(v, 10) || 0),
		});

		/**
		 * A drop-down for one meta key.
		 *
		 * @param {string} key Meta key.
		 * @param {string} label Label.
		 * @param {Array} options Options ({label, value}).
		 * @returns {Object} Element.
		 */
		const choose = (key, label, options) => el(SelectControl, {
			label: label,
			value: meta[key] || '',
			options: options,
			onChange: (v) => set(key, v),
			__nextHasNoMarginBottom: true,
		});

		const typeDefault = __('Event type\'s default, else iMIS\'s', 'wormiss');
		const template = sync.template || data.template || '';
		let state;
		if (!data.enabled) {
			state = __('Sending events to iMIS is off (Wormiss > Settings).', 'wormiss');
		} else if (data.dryRun) {
			state = __('Dry run: on publish or update, iMIS only checks this event.', 'wormiss');
		} else {
			state = __('On publish or update, this event is saved in iMIS.', 'wormiss');
		}

		const existing = meta._wormiss_event_existing || '';
		const existingField = (!imisId || sync.linked) && field('_wormiss_event_existing', __('Existing iMIS event ID', 'wormiss'), {
			help: __('To use an event already in iMIS (e.g. CONGRESS26) instead of creating one. Its details, places', 'wormiss')
				+ ' ' + __('and registration come from iMIS, and WordPress never changes or cancels it. Clear it to unlink.', 'wormiss'),
			maxLength: 10,
		});

		if (sync.linked || existing) {
			// A linked event is only read from iMIS: nothing else here is sent.
			return el(
				Panel,
				{name: 'wormiss-event', title: __('iMIS event', 'wormiss')},
				syncNotice(sync),
				existingField,
				sync.places && el('p', {className: 'description'},
					sprintf(__('Places in iMIS: %s', 'wormiss'), sync.places)),
				!sync.linked && el('p', {className: 'description'},
					__('Save to link this page to the iMIS event.', 'wormiss'))
			);
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
			existingField,
			field('_wormiss_event_code', __('Event code', 'wormiss'), {
				help: imisId
					? sprintf(__('In iMIS as %s. The code cannot change now.', 'wormiss'), imisId)
					: __('Up to 10 letters, digits, _ and -, e.g. CONF27. Becomes the iMIS event code.', 'wormiss'),
				disabled: !!imisId,
				maxLength: 10,
			}),
			field('_wormiss_event_start', __('Starts', 'wormiss'), {type: 'datetime-local'}),
			field('_wormiss_event_end', __('Ends', 'wormiss'), {type: 'datetime-local'}),
			field('_wormiss_event_location', __('Place', 'wormiss'), {help: __('Venue name. Blank keeps the template\'s.', 'wormiss')}),
			field('_wormiss_event_address1', __('Street address', 'wormiss'), {maxLength: 100}),
			field('_wormiss_event_address2', __('Street address, line 2', 'wormiss'), {maxLength: 100}),
			field('_wormiss_event_city', __('Suburb or city', 'wormiss'), {maxLength: 100}),
			field('_wormiss_event_state', __('State', 'wormiss'), {
				help: __('As iMIS has it, e.g. QLD, NSW or VIC.', 'wormiss'),
				maxLength: 15,
			}),
			field('_wormiss_event_postcode', __('Postcode', 'wormiss'), {maxLength: 10}),
			choose('_wormiss_event_country', __('Country', 'wormiss'), [
				{label: __('The template\'s', 'wormiss'), value: ''},
				{label: __('Australia', 'wormiss'), value: 'Australia'},
				{label: __('New Zealand', 'wormiss'), value: 'New Zealand'},
			]),
			el('p', {className: 'description'}, __('With any part of the address filled in, the whole address is saved in iMIS'
				+ ' (a blank state or country keeps the template\'s). With none, the template\'s address is kept.', 'wormiss')),
			field('_wormiss_event_online', __('Online link', 'wormiss'), {type: 'url'}),
			sync.places && el('p', {className: 'description'},
				sprintf(__('Places in iMIS: %s', 'wormiss'), sync.places)),
			number('_wormiss_event_capacity', __('Capacity', 'wormiss'),
				__('0 uses the event type\'s default, else the template\'s. 1000 or more shows as "Places available".', 'wormiss')),
			number('_wormiss_event_minimum', __('Minimum attendance', 'wormiss'),
				__('Fewest people for the event to go ahead. 0 uses the event type\'s default.', 'wormiss')),
			number('_wormiss_event_expected', __('Expected attendance', 'wormiss'),
				__('For planning. 0 uses the event type\'s default.', 'wormiss')),
			choose('_wormiss_event_waitlist', __('Waitlist', 'wormiss'), [
				{label: typeDefault, value: ''},
				{label: __('No waitlist', 'wormiss'), value: 'None'},
				{label: __('Staff can add people', 'wormiss'), value: 'OnlyStaff'},
				{label: __('Anyone can join', 'wormiss'), value: 'StaffAndPublicUsers'},
			]),
			choose('_wormiss_event_auto_release', __('When a place frees up', 'wormiss'), [
				{label: typeDefault, value: ''},
				{label: __('Offer it to the waitlist automatically', 'wormiss'), value: 'yes'},
				{label: __('Staff offer it', 'wormiss'), value: 'no'},
			])
		);
	}

	wp.plugins.registerPlugin('wormiss-event', {render: EventPanel});
})(window.wp);
