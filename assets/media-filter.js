/**
 * Media categories (Wormiss): a category dropdown in the media modal and the Media grid, so an
 * image can be found by its category. Choosing a category narrows the list straight away.
 *
 * This only filters what is shown by the viewer's choice; it does not control what they may see.
 */
( function ( wp, data ) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view || ! data || ! data.taxonomy ) {
		return;
	}

	const media = wp.media;
	const key = data.taxonomy;

	/**
	 * A dropdown of the media categories, built on the core attachment filter.
	 */
	const CategoryFilter = media.view.AttachmentFilters.extend( {
		id: 'wormiss-media-category-filter',

		/**
		 * The dropdown's options: every category, and "all" which clears the filter.
		 *
		 * @return {void}
		 */
		createFilters: function () {
			const filters = {};
			( data.terms || [] ).forEach( function ( term ) {
				const props = {};
				props[ key ] = term.id;
				filters[ 'cat-' + term.id ] = {
					text: term.name,
					props: props,
				};
			} );
			const allProps = {};
			allProps[ key ] = '';
			filters.all = {
				text: data.all,
				props: allProps,
				priority: 10,
			};
			this.filters = filters;
		},
	} );

	const AttachmentsBrowser = media.view.AttachmentsBrowser;

	media.view.AttachmentsBrowser = AttachmentsBrowser.extend( {
		/**
		 * Add the category dropdown to the attachments browser toolbar.
		 *
		 * @return {void}
		 */
		createToolbar: function () {
			AttachmentsBrowser.prototype.createToolbar.call( this );
			this.toolbar.set(
				'wormissMediaCategoryFilter',
				new CategoryFilter( {
					controller: this.controller,
					model: this.collection.props,
					priority: -75,
				} ).render()
			);
		},
	} );
}( window.wp, window.wormissMediaFilter ) );
