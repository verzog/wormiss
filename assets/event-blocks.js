/**
 * The event blocks in the editor: Event facts, CPD information and iMIS settings. Plain JavaScript
 * (no build step).
 *
 * On an Event made in WordPress, each block is edited in place, laid out as on the page, and saves
 * the event's own fields. On a linked or imported event it shows what iMIS has. Events open inside
 * their page template, and these blocks stay editable there.
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function(wp) {
	'use strict';

	const el = wp.element.createElement;
	const {__, sprintf} = wp.i18n;
	const {registerBlockType} = wp.blocks;
	const {useBlockProps} = wp.blockEditor;
	const {useSelect} = wp.data;
	const {useEntityProp} = wp.coreData;
	const {Notice, Button, TextControl, SelectControl} = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const data = window.wormissEventBlocks || {requirements: [], maxRows: 50, countries: []};
	const POST_TYPE = 'wormiss_event';
	const CPD = '_wormiss_event_cpd';
	const BLOCKS = ['wormiss/event-facts', 'wormiss/event-cpd', 'wormiss/event-imis'];

	// Editable inside the page template (WordPress shows the event inside it).
	wp.hooks.addFilter('editor.postContentBlockTypes', 'wormiss/event-blocks', (types) => types.concat(BLOCKS));

	/**
	 * The event being edited: its fields (post meta) and what iMIS said (wormiss_sync).
	 *
	 * @param {Object} context Block context (postId, postType).
	 * @returns {Object} Event.
	 */
	function useEvent(context) {
		const postType = context.postType || POST_TYPE;
		const postId = context.postId;
		const [meta, setMeta] = useEntityProp('postType', postType, 'meta', postId);
		const sync = useSelect((select) => select('core/editor').getCurrentPostAttribute('wormiss_sync') || {}, []);
		const fields = meta || {};
		return {
			meta: fields,
			sync: sync,
			postId: postId,
			isEvent: POST_TYPE === postType && !!postId,
			set: (key, value) => setMeta(Object.assign({}, fields, {[key]: value})),
		};
	}

	/**
	 * An input for one field, styled to sit in the page.
	 *
	 * @param {Object} ev Event.
	 * @param {string} key Meta key.
	 * @param {string} label Accessible label and placeholder.
	 * @param {Object} extra Extra props.
	 * @returns {Object} Element.
	 */
	function input(ev, key, label, extra) {
		const value = ev.meta[key];
		return el('input', Object.assign({
			className: 'wormiss-inline',
			value: value === undefined || value === null ? '' : String(value),
			placeholder: label,
			'aria-label': label,
			onChange: (event) => ev.set(key, event.target.value),
		}, extra || {}));
	}

	/**
	 * A note for editors.
	 *
	 * @param {string} text Text.
	 * @returns {Object} Element.
	 */
	function note(text) {
		return el('p', {className: 'wormiss-editor-note'}, text);
	}

	/**
	 * What a block shows outside an event (e.g. in the Site Editor's event template).
	 *
	 * @param {Object} blockProps Block props.
	 * @param {string} text What it shows on each event.
	 * @returns {Object} Element.
	 */
	function placeholder(blockProps, text) {
		return el('div', blockProps, note(text));
	}

	/**
	 * The block as the website shows it (for linked events, and for people who cannot edit it).
	 *
	 * @param {Object} blockProps Block props.
	 * @param {string} name Block name.
	 * @param {number} postId Post ID.
	 * @param {string} text Note under it.
	 * @returns {Object} Element.
	 */
	function asOnSite(blockProps, name, postId, text) {
		return el('div', blockProps,
			el(ServerSideRender, {block: name, urlQueryArgs: {post_id: postId}}),
			note(text));
	}

	registerBlockType('wormiss/event-facts', {
		edit: function EventFacts(props) {
			const blockProps = useBlockProps();
			const ev = useEvent(props.context);
			if (!ev.isEvent) {
				return placeholder(blockProps, __('Event facts: each event shows when and where it is, places left and Register here.', 'wormiss'));
			}
			if (ev.sync.linked) {
				return asOnSite(blockProps, 'wormiss/event-facts', ev.postId,
					__('Linked to an iMIS event: these details come from iMIS.', 'wormiss'));
			}
			const countries = [{label: __('Country: the template\'s', 'wormiss'), value: ''}]
				.concat(data.countries.map((country) => ({label: country, value: country})));
			return el('div', blockProps,
				el('dl', {className: 'wormiss-event-details'},
					el('dt', null, __('When', 'wormiss')),
					el('dd', null,
						input(ev, '_wormiss_event_start', __('Starts', 'wormiss'), {type: 'datetime-local'}),
						' – ',
						input(ev, '_wormiss_event_end', __('Ends', 'wormiss'), {type: 'datetime-local'})),
					el('dt', null, __('Where', 'wormiss')),
					el('dd', null,
						input(ev, '_wormiss_event_location', __('Venue', 'wormiss'), {className: 'wormiss-inline wormiss-inline--wide'}),
						input(ev, '_wormiss_event_address1', __('Street address', 'wormiss'), {className: 'wormiss-inline wormiss-inline--wide'}),
						input(ev, '_wormiss_event_address2', __('Street address, line 2', 'wormiss'), {className: 'wormiss-inline wormiss-inline--wide'}),
						el('span', {className: 'wormiss-inline-row'},
							input(ev, '_wormiss_event_city', __('Suburb or city', 'wormiss')),
							input(ev, '_wormiss_event_state', __('State', 'wormiss'), {size: 4, maxLength: 15}),
							input(ev, '_wormiss_event_postcode', __('Postcode', 'wormiss'), {size: 6, maxLength: 10})),
						el('select', {
							className: 'wormiss-inline',
							value: ev.meta._wormiss_event_country || '',
							'aria-label': __('Country', 'wormiss'),
							onChange: (event) => ev.set('_wormiss_event_country', event.target.value),
						}, countries.map((o) => el('option', {key: o.value, value: o.value}, o.label))),
						input(ev, '_wormiss_event_online', __('Online link', 'wormiss'), {type: 'url', className: 'wormiss-inline wormiss-inline--wide'})),
					el('dt', null, __('Places', 'wormiss')),
					el('dd', null,
						input(ev, '_wormiss_event_capacity', __('Capacity', 'wormiss'), {
							type: 'number',
							min: 0,
							size: 5,
							onChange: (event) => ev.set('_wormiss_event_capacity', parseInt(event.target.value, 10) || 0),
						}),
						' ', __('places', 'wormiss'),
						ev.sync.places && el('span', {className: 'wormiss-editor-note'}, ' (' + ev.sync.places + ')'))),
				el('p', {className: 'wormiss-event-register'},
					el('span', {className: 'wp-element-button', 'aria-disabled': 'true'}, __('Register', 'wormiss'))),
				note(__('A blank state or country keeps the iMIS template\'s. Capacity 0 uses the event type\'s.', 'wormiss')));
		},
		save: () => null,
	});

	registerBlockType('wormiss/event-imis', {
		edit: function EventImis(props) {
			const blockProps = useBlockProps({className: 'wormiss-imis-settings'});
			const ev = useEvent(props.context);
			if (!ev.isEvent) {
				return placeholder(blockProps, __('iMIS settings: editors set each event\'s iMIS details here. Not shown on the website.', 'wormiss'));
			}
			const imisId = ev.sync.imis_id || '';
			const linked = ev.sync.linked || !!ev.meta._wormiss_event_existing;
			const typeDefault = __('Event type\'s default, else iMIS\'s', 'wormiss');
			const status = ev.sync.status || '';
			const kinds = {synced: 'success', linked: 'success', cancelled: 'warning', error: 'error'};
			const text = (key, label, help, extra) => el(TextControl, Object.assign({
				label: label,
				help: help,
				value: ev.meta[key] === undefined || ev.meta[key] === null ? '' : String(ev.meta[key]),
				onChange: (value) => ev.set(key, value),
				__nextHasNoMarginBottom: true,
			}, extra || {}));
			const number = (key, label, help) => text(key, label, help, {
				type: 'number',
				min: 0,
				onChange: (value) => ev.set(key, parseInt(value, 10) || 0),
			});
			const choose = (key, label, options) => el(SelectControl, {
				label: label,
				value: ev.meta[key] || '',
				options: options,
				onChange: (value) => ev.set(key, value),
				__nextHasNoMarginBottom: true,
			});
			const existing = (!imisId || ev.sync.linked) && text('_wormiss_event_existing', __('Existing iMIS event ID', 'wormiss'),
				__('To use an event already in iMIS (e.g. CONGRESS26) instead of creating one. Clear it to unlink.', 'wormiss'),
				{maxLength: 10});
			return el('div', blockProps,
				el('p', {className: 'wormiss-imis-settings__title'}, __('iMIS settings', 'wormiss'),
					el('span', null, ' — ' + __('editors only, not shown on the website', 'wormiss'))),
				status && el(Notice, {status: kinds[status] || 'info', isDismissible: false}, ev.sync.message || status),
				existing,
				!linked && el('div', {className: 'wormiss-imis-settings__grid'},
					text('_wormiss_event_code', __('Event code', 'wormiss'), imisId
						? sprintf(__('In iMIS as %s. The code cannot change now.', 'wormiss'), imisId)
						: __('Up to 10 letters, digits, _ and -, e.g. CONF27.', 'wormiss'), {disabled: !!imisId, maxLength: 10}),
					number('_wormiss_event_minimum', __('Minimum attendance', 'wormiss'), __('0 uses the event type\'s.', 'wormiss')),
					number('_wormiss_event_expected', __('Expected attendance', 'wormiss'), __('0 uses the event type\'s.', 'wormiss')),
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
					])));
		},
		save: () => null,
	});

	/**
	 * A key for a new CPD line.
	 *
	 * @returns {string} Key.
	 */
	function newUid() {
		return Math.random().toString(36).slice(2, 14);
	}

	/**
	 * Hours for people: up to two decimals, without trailing zeros.
	 *
	 * @param {number} hours Hours.
	 * @returns {string} Text.
	 */
	function hours(hours) {
		return String(Math.round((Number(hours) || 0) * 100) / 100);
	}

	registerBlockType('wormiss/event-cpd', {
		edit: function EventCpd(props) {
			const blockProps = useBlockProps();
			const ev = useEvent(props.context);
			const edited = useSelect((select) => {
				const edits = select('core/editor').getPostEdits() || {};
				return !!(edits.meta && edits.meta[CPD] !== undefined);
			}, []);
			if (!ev.isEvent) {
				return placeholder(blockProps, __('CPD information: each event shows its CPD hours per category here.', 'wormiss'));
			}
			const cpd = ev.sync.cpd || {};
			if (!cpd.editable) {
				return asOnSite(blockProps, 'wormiss/event-cpd', ev.postId, ev.sync.linked
					? __('Linked to an iMIS event: its CPD hours come from iMIS.', 'wormiss')
					: __('Only editors and administrators can change CPD hours.', 'wormiss'));
			}
			const owned = !!cpd.owned || edited;
			if (!owned && cpd.imis === null) {
				// Not read: no editing, so a save cannot take iMIS's lines for none (and remove them).
				return el('div', blockProps, el(Notice, {status: 'warning', isDismissible: false},
					__('The CPD hours could not be read from iMIS, so they cannot be changed now. Try again later.', 'wormiss')));
			}
			const rows = owned ? (ev.meta[CPD] || []) : (cpd.imis || []);
			const save = (next) => ev.set(CPD, next.map((row) => ({
				education: Number(row.education) || 0,
				reviewing: Number(row.reviewing) || 0,
				outcomes: Number(row.outcomes) || 0,
				requirement: row.requirement || '',
				ordinal: parseInt(row.ordinal, 10) || 0,
				uid: row.uid || newUid(),
			})));
			const change = (index, key, value) => save(rows.map((row, i) => (i === index ? Object.assign({}, row, {[key]: value}) : row)));
			const categories = [
				['education', __('Educational Activities', 'wormiss'), __('Medical Education', 'wormiss')],
				['reviewing', __('Reviewing Performance', 'wormiss'), __('Reviewing Practices', 'wormiss')],
				['outcomes', __('Measuring Outcomes', 'wormiss'), __('Patient Outcomes', 'wormiss')],
			];
			const totals = categories.map(([key]) => rows.reduce((sum, row) => sum + (Number(row[key]) || 0), 0));
			const requirements = [{label: __('(None)', 'wormiss'), value: ''}]
				.concat(data.requirements.map((r) => ({label: r, value: r})));
			return el('div', blockProps,
				el('section', {className: 'wormiss-cpd'},
					el('h2', {className: 'wormiss-cpd__title'}, __('CPD information', 'wormiss')),
					el('table', {className: 'wormiss-cpd__table wormiss-cpd__table--edit'},
						el('thead', null, el('tr', null,
							categories.map(([key, au, nz]) => el('th', {key: key, scope: 'col'}, au, el('br'), el('small', null, nz))),
							el('th', {scope: 'col'}, __('Program level requirement', 'wormiss')),
							el('th', {scope: 'col'}, el('span', {className: 'screen-reader-text'}, __('Remove', 'wormiss'))))),
						el('tbody', null, rows.map((row, index) => el('tr', {key: row.uid || ('o' + row.ordinal) || index},
							categories.map(([key, au, nz]) => el('td', {key: key}, el('input', {
								className: 'wormiss-inline',
								type: 'number',
								min: 0,
								max: 1000,
								step: 0.01,
								value: row[key] === undefined ? '' : row[key],
								'aria-label': au + ' – ' + nz,
								onChange: (event) => change(index, key, event.target.value),
							}))),
							el('td', null, el('select', {
								className: 'wormiss-inline',
								value: row.requirement || '',
								'aria-label': __('Program level requirement', 'wormiss'),
								onChange: (event) => change(index, 'requirement', event.target.value),
							}, requirements.map((o) => el('option', {key: o.value, value: o.value}, o.label)))),
							el('td', null, el(Button, {
								variant: 'tertiary',
								isDestructive: true,
								onClick: () => save(rows.filter((r, i) => i !== index)),
							}, __('Remove', 'wormiss')))))),
						el('tfoot', null, el('tr', null,
							totals.map((total, i) => el('td', {key: i}, el('strong', null, hours(total)))),
							el('td', {colSpan: 2}, __('Total hours', 'wormiss')))))),
				el(Button, {
					variant: 'secondary',
					disabled: rows.length >= data.maxRows,
					onClick: () => save(rows.concat([{education: 0, reviewing: 0, outcomes: 0, requirement: '', ordinal: 0, uid: newUid()}])),
				}, __('Add CPD line', 'wormiss')),
				note(__('On update the event\'s CPD Hours panel in iMIS is made to match: lines added, changed and removed. Decimals allowed, e.g. 1.5.', 'wormiss')));
		},
		save: () => null,
	});

	// Events are always edited inside their page template, on any WordPress version, so editors
	// see where each detail goes and how the page will look. Once the editor is ready (switching
	// earlier is undone as it finishes loading) the template view is turned on, and turned back on
	// whenever "Show template" is switched off. The remembered choice is set to the template view
	// too, so the next event opens in it straight away.
	let ready = false;
	let lastMode = '';
	wp.data.subscribe(() => {
		const editor = wp.data.select('core/editor');
		if (!editor || !editor.getRenderingMode || POST_TYPE !== editor.getCurrentPostType()) {
			return;
		}
		if (!ready) {
			if (editor.__unstableIsEditorReady && !editor.__unstableIsEditorReady()) {
				return;
			}
			ready = true;
			rememberTemplateView();
		}
		const mode = editor.getRenderingMode();
		if (mode === lastMode) {
			return;
		}
		lastMode = mode;
		const settings = editor.getEditorSettings ? editor.getEditorSettings() : {};
		if ('post-only' === mode && settings.supportsTemplateMode) {
			wp.data.dispatch('core/editor').setRenderingMode('template-locked');
		}
	});

	/**
	 * Save "template view" as this user's choice for events, keeping their choices for other
	 * themes and post types.
	 *
	 * @return {void}
	 */
	function rememberTemplateView() {
		const preferences = wp.data.select('core/preferences');
		const theme = (wp.data.select('core').getCurrentTheme() || {}).stylesheet;
		if (!preferences || !theme) {
			return;
		}
		const modes = preferences.get('core', 'renderingModes') || {};
		const forTheme = modes[theme] || {};
		if ('template-locked' === forTheme[POST_TYPE]) {
			return;
		}
		const updated = Object.assign({}, modes);
		updated[theme] = Object.assign({}, forTheme);
		updated[theme][POST_TYPE] = 'template-locked';
		wp.data.dispatch('core/preferences').set('core', 'renderingModes', updated);
	}
})(window.wp);
