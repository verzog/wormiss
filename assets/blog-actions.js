/**
 * Members' blog actions (Wormiss): like and save without leaving the page, and share the link.
 * Without JavaScript the like and save buttons post the form and the page reloads.
 */
( function () {
	'use strict';

	/**
	 * Say something to screen readers (and show it briefly).
	 *
	 * @param {HTMLElement} rail    The rail.
	 * @param {string}      message What to say.
	 */
	function say( rail, message ) {
		const status = rail.querySelector( '.wormiss-blog-actions__status' );
		if ( ! status ) {
			return;
		}
		status.textContent = message || status.dataset.error;
		window.setTimeout( function () {
			status.textContent = '';
		}, 4000 );
	}

	/**
	 * Show a button as on or off.
	 *
	 * @param {HTMLButtonElement|null} button The button.
	 * @param {boolean}                on     Whether it is on.
	 */
	function press( button, on ) {
		if ( ! button ) {
			return;
		}
		button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		const label = button.querySelector( '.wormiss-blog-actions__label' );
		if ( label ) {
			label.textContent = on ? button.dataset.on : button.dataset.off;
		}
	}

	/**
	 * Send a like or save in the background and show the result.
	 *
	 * @param {HTMLElement}       rail   The rail.
	 * @param {HTMLFormElement}   form   The form.
	 * @param {HTMLButtonElement} button The button pressed.
	 */
	function send( rail, form, button ) {
		const data = new FormData( form );
		data.append( button.name, button.value );
		data.append( 'wormiss_ajax', '1' );
		button.disabled = true;
		window.fetch( form.action, { method: 'POST', body: data, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( ! result || ! result.success ) {
					throw new Error( result && result.data ? result.data.message : '' );
				}
				const like = form.querySelector( '.wormiss-blog-actions__like' );
				press( like, result.data.liked );
				const count = like ? like.querySelector( '.wormiss-blog-actions__count' ) : null;
				if ( count ) {
					count.textContent = String( result.data.likes );
				}
				press( form.querySelector( '.wormiss-blog-actions__save' ), result.data.saved );
			} )
			.catch( function ( error ) {
				say( rail, error && error.message ? error.message : '' );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

	/**
	 * Share the post: the device's share sheet where there is one, else copy the link.
	 *
	 * @param {HTMLElement}       rail   The rail.
	 * @param {HTMLButtonElement} button The share button.
	 */
	function share( rail, button ) {
		const url = button.dataset.url;
		if ( navigator.share ) {
			navigator.share( { title: button.dataset.title, url: url } ).catch( function () {
				// Closing the share sheet is not an error worth showing.
			} );
			return;
		}
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( url ).then( function () {
				say( rail, button.dataset.copied );
			} );
		}
	}

	/**
	 * Wire up one rail.
	 *
	 * @param {HTMLElement} rail The rail.
	 */
	function wire( rail ) {
		const form = rail.querySelector( 'form.wormiss-blog-actions__form' );
		if ( form && window.fetch && window.FormData ) {
			form.addEventListener( 'submit', function ( event ) {
				const button = event.submitter;
				if ( button && button.name ) {
					event.preventDefault();
					send( rail, form, button );
				}
			} );
		}
		const shareButton = rail.querySelector( '.wormiss-blog-actions__share' );
		if ( shareButton && ( navigator.share || navigator.clipboard ) ) {
			shareButton.hidden = false;
			shareButton.addEventListener( 'click', function () {
				share( rail, shareButton );
			} );
		}
	}

	document.querySelectorAll( '.wormiss-blog-actions' ).forEach( wire );
}() );
