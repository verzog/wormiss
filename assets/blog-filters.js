/**
 * Members' blog filters (Wormiss): each choice applies straight away, as on the iMIS blog.
 * Without JavaScript the Filter button does the same.
 */
( function () {
	'use strict';

	/**
	 * Make one filter form apply its choices as they change.
	 *
	 * @param {HTMLFormElement} form The form.
	 */
	function auto( form ) {
		form.classList.add( 'wormiss-blog-filters--auto' );
		form.addEventListener( 'change', function ( event ) {
			const field = event.target;
			if ( field.matches( 'select, input[type="date"], input[type="search"], input[type="checkbox"]' ) ) {
				form.requestSubmit ? form.requestSubmit() : form.submit();
			}
		} );
	}

	document.querySelectorAll( 'form.wormiss-blog-filters' ).forEach( auto );
}() );
