/**
 * Members' blog pictures (Wormiss): a click or tap on a picture in a post opens it large;
 * a click anywhere, Close or Escape closes it.
 */
( function () {
	'use strict';

	const text = window.wormissBlogPictures || { open: 'Open the picture large', close: 'Close' };
	let dialog = null;
	let image = null;
	let opener = null;

	/**
	 * The dialog, made once.
	 *
	 * @returns {HTMLDialogElement} The dialog.
	 */
	function lightbox() {
		if ( dialog ) {
			return dialog;
		}
		dialog = document.createElement( 'dialog' );
		dialog.className = 'wormiss-blog-lightbox';
		const inner = document.createElement( 'div' );
		inner.className = 'wormiss-blog-lightbox__inner';
		image = document.createElement( 'img' );
		image.className = 'wormiss-blog-lightbox__image';
		const close = document.createElement( 'button' );
		close.type = 'button';
		close.className = 'wormiss-blog-lightbox__close';
		close.textContent = text.close;
		inner.appendChild( image );
		dialog.appendChild( close );
		dialog.appendChild( inner );
		dialog.addEventListener( 'click', function () {
			dialog.close();
		} );
		dialog.addEventListener( 'close', function () {
			if ( opener ) {
				opener.focus();
			}
		} );
		document.body.appendChild( dialog );
		return dialog;
	}

	/**
	 * Open a picture large.
	 *
	 * @param {HTMLImageElement} picture The picture.
	 */
	function open( picture ) {
		const box = lightbox();
		// A picture linked to a larger copy of itself opens that copy.
		const link = picture.closest( 'a' );
		image.src = link && link.href ? link.href : ( picture.currentSrc || picture.src );
		image.alt = picture.alt || '';
		opener = picture;
		box.showModal();
	}

	/**
	 * Whether a picture should open large: not one inside a link to a page.
	 *
	 * @param {HTMLImageElement} picture The picture.
	 * @returns {boolean} Whether it opens large.
	 */
	function zoomable( picture ) {
		const link = picture.closest( 'a' );
		if ( ! link ) {
			return true;
		}
		const href = link.getAttribute( 'href' ) || '';
		// A picture extension, or the gated protected-media endpoint (/wormiss-media/<id>), which
		// has no extension but serves the full picture.
		return /\.(?:jpe?g|png|gif|webp|avif)(?:\?|#|$)/i.test( href ) || /\/wormiss-media\/\d+(?:[/?#]|$)/.test( href );
	}

	if ( typeof HTMLDialogElement !== 'function' ) {
		return;
	}
	document.querySelectorAll( '.wormiss-blog-post img' ).forEach( function ( picture ) {
		if ( ! zoomable( picture ) ) {
			return;
		}
		picture.classList.add( 'wormiss-blog-zoomable' );
		picture.setAttribute( 'tabindex', '0' );
		picture.setAttribute( 'role', 'button' );
		picture.setAttribute( 'aria-label', picture.alt ? picture.alt + ': ' + text.open : text.open );
		picture.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			open( picture );
		} );
		picture.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key || ' ' === event.key ) {
				event.preventDefault();
				open( picture );
			}
		} );
	} );
}() );
