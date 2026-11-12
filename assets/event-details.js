/**
 * Tabs for the "Event details" box under an Event's content. Plain JavaScript (no build step).
 * Without it every tab's fields show, one section under another.
 *
 * @copyright  2026 Vernon Spain
 * @license    GNU GPL v3 or later
 */

(function() {
	'use strict';

	/**
	 * Show one tab's panel and hide the others.
	 *
	 * @param {Element} box The tabs box.
	 * @param {Element} tab The tab to show.
	 * @param {boolean} focus Move focus to the tab.
	 */
	function show(box, tab, focus) {
		box.querySelectorAll('[role="tab"]').forEach((other) => {
			const on = other === tab;
			other.setAttribute('aria-selected', on ? 'true' : 'false');
			other.tabIndex = on ? 0 : -1;
			const panel = document.getElementById(other.getAttribute('aria-controls'));
			if (panel) {
				panel.hidden = !on;
			}
		});
		if (focus) {
			tab.focus();
		}
	}

	/**
	 * Set up one tabs box: tab clicks, and arrow, Home and End keys.
	 *
	 * @param {Element} box The tabs box.
	 */
	function setUp(box) {
		const list = box.querySelector('[role="tablist"]');
		const tabs = Array.from(box.querySelectorAll('[role="tab"]'));
		if (!list || tabs.length < 2) {
			return;
		}
		list.hidden = false;
		box.classList.add('wormiss-tabs--ready');
		show(box, tabs[0], false);
		list.addEventListener('click', (event) => {
			const tab = event.target.closest('[role="tab"]');
			if (tab) {
				show(box, tab, false);
			}
		});
		list.addEventListener('keydown', (event) => {
			const now = tabs.indexOf(document.activeElement);
			if (now < 0) {
				return;
			}
			const moves = {
				ArrowRight: (now + 1) % tabs.length,
				ArrowLeft: (now - 1 + tabs.length) % tabs.length,
				Home: 0,
				End: tabs.length - 1,
			};
			if (event.key in moves) {
				event.preventDefault();
				show(box, tabs[moves[event.key]], true);
			}
		});
	}

	/**
	 * Set up every tabs box on the page (the box can arrive after the page loads).
	 */
	function start() {
		document.querySelectorAll('.wormiss-tabs:not(.wormiss-tabs--ready)').forEach(setUp);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
	// The block editor adds its meta box area after load.
	new MutationObserver(start).observe(document.body, {childList: true, subtree: true});
})();
