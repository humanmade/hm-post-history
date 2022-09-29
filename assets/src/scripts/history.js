import HtmlDiff from 'htmldiff-js';

import RevisionMap from './class-revision-map';

let settings = {
	api: {
		base: undefined,
		nonce: undefined,
	},
	default: {
		per_page: undefined,
	},
	postID: undefined,
	marker: {
		selector: undefined,
	},
};
let originalHTML = '';
const revisions = new RevisionMap();

/**
 * Set up our environment.
 */
function preflight() {
	const {
		api_base,
		api_nonce,
		per_page_default,
	} = window.HMPostHistorySettings;
	const {
		post_id,
		marker_selector,
	} = window.HMPostHistoryCurrentItem;

	settings.api.base = new URL( api_base );
	settings.api.nonce = api_nonce;
	settings.default.per_page = per_page_default;
	settings.postID = parseInt( post_id, 10 );
	settings.marker.selector = marker_selector;
}

/**
 * Whether we have what we need to run this feature.
 *
 * @returns {boolean} Boolean indicating whether we can run.
 */
function canRun() {
	// Make certain every variable we'll need is defined.
	return ( typeof settings.api.base != 'undefined' )
		&& ( typeof settings.api.nonce != 'undefined' )
		&& ( typeof settings.default.per_page != 'undefined' )
		&& ( typeof settings.postID != 'undefined' )
		&& ( typeof settings.marker.selector != 'undefined' );
}

/**
 * Get the marker element.
 *
 * @param {HTMLElement} wrapper The DOM node to search for the marker.
 * @returns {boolean|HTMLElement} The DOM node of the marker, or false.
 */
function getMarker( wrapper ) {
	let searchIn = document;
	if ( wrapper ) {
		searchIn = wrapper;
	}
	return searchIn.querySelector( settings.marker.selector ) || false;
}

/**
 * Gets the presumbed content wrapper element, i.e. the parent of the marker.
 *
 * @returns {boolean|HTMLElement} The parent of the marker, or boolean false.
 */
function getContentWrapper() {
	const marker = getMarker();
	if ( marker ) {
		return marker.parentElement;
	}
	return false;
}

/**
 * Returns the HTML of the original content for diff comparison.
 *
 * This removes the marker element, as that shouldn't be part of the diff.
 *
 * @param {HTMLElement} content DOM Node containing content.
 * @returns {string} Original HTML content.
 */
function getContentForDiff( content ) {
	if ( content ) {
		// We clone this because we're going to manipulate its contents, and we don't want to modify the actual DOM yet.
		const cloneContent = content.cloneNode( true );
		const cloneMarker = getMarker( cloneContent );
		if ( cloneMarker ) {
			// The marker should not be part of the diff.
			cloneContent.removeChild( cloneMarker );
		}
		// Attempt to normalize the HTML to reduce inconsequential diffs.
		cloneContent.normalize();
		return cloneContent.innerHTML;
	}

	return '';
}

/**
 * Get the DOM node for the load more button.
 *
 * @returns { HTMLElement } Button element.
 */
function getLoadMoreButton() {
	return document.querySelector( 'button[data-post-history-load-more]' );
}

/**
 * Attempt to normalize an HTML string to reduce the number of inconsequential diffs.
 *
 * @param {string} html HTML code to be normalized.
 * @returns {string} Normalized HTML.
 */
function normalizeHTMLString( html ) {
	const temporaryWrapper = document.createElement( 'div' );
	temporaryWrapper.innerHTML = html;
	temporaryWrapper.normalize();
	return temporaryWrapper.innerHTML;
}

/**
 * Set up the contain that will hold rendered diffs.
 */
function setupDiffContainer() {
	// Store the original content for reference.
	const container = getContentWrapper();
	if ( ! container ) {
		// If for some reason there's no content we have to bail.
		return;
	}

	// Add a data-attribute for future querySelection.
	container.dataset.postHistoryDiffContainer = '';

	// Add a class for styling diffs.
	container.classList.add( 'hm-post-history__diff-container' );

	// Store the original HTML for future comparisons and restoration.
	originalHTML = getContentForDiff( container );
}

/**
 * Set up the diff list to be contain diff options and swap between them.
 */
function setupDiffList() {
	const list = document.querySelector( '[data-post-history-diff-list]' );
	/**
	 * Handle diffs being added to revivions by adding them to list.
	 *
	 * @param {CustomEvent} addRevisionEvent Event dispatched when a diff is added to the list of options.
	 */
	const addRevision = ( addRevisionEvent ) => {
		const {
			author,
			date,
			id,
		} = addRevisionEvent.detail.value;
		const item = document.createElement( 'li' );
		item.classList.add( 'hm-post-history__diff' );
		item.dataset.postHistoryDiffId = id;
		item.innerHTML = `<button class="hm-post-history__select-diff" type="button" value="${id}">${date} - ${author}</button>`;
		item.addEventListener( 'click', () => {
			switchToDiff( id );
		} );
		list.appendChild( item );
	};
	/**
	 * Handle removing a diff from the revision map and hence the list of options.
	 *
	 * @param {CustomEvent} removeRevisionEvent Event dispatched when diff option is removed from list.
	 */
	const removeRevision = ( removeRevisionEvent ) => {
		const item = list.querySelector( `[data-post-history-diff-id="${removeRevisionEvent.detail.key}"]` );
		if ( item ) {
			list.removeChild( item );
		}
	};
	/**
	 * Handle changing the active diff.
	 *
	 * @param {CustomEvent} diffSwitchedEvent Event dispatched when diff switches.
	 */
	const updateActiveRevision = ( diffSwitchedEvent ) => {
		const id = diffSwitchedEvent.detail.currentDiffID;
		list.querySelectorAll( 'li' ).forEach( ( item ) => {
			item.classList.remove( 'hm-post-history__diff--current' );
			if ( parseInt( item.dataset.postHistoryDiffId, 10 ) === id ) {
				item.classList.add( 'hm-post-history__diff--current' );
			}
		} );
	};
	if ( list ) {
		document.addEventListener( 'hm-post-history.revision-added', addRevision );
		document.addEventListener( 'hm-post-history.revision-removed', removeRevision );
		document.addEventListener( 'hm-post-history.diff-switched', updateActiveRevision );
	}
}

/**
 * Prepare the Load More button to be used.
 */
function setupLoadMoreButton() {
	const button = getLoadMoreButton();
	if ( button ) {
		button.addEventListener( 'click', () => {
			let count = parseInt( button.dataset.postHistoryLoadMore, 10 ) || false;
			loadRevisions( count );
			button.dataset.postHistoryLoadMore = ++count;
		} );
		document.addEventListener( 'hm-post-history.no-more-revisions', () => {
			button.hidden = true;
			button.disabled = true;
		} );
	}
}

/**
 * Calculate and render the diff between revision HTML and original HTML.
 *
 * @param {string} revisionHTML HTML for a revision to be compared against the original page source.
 */
function renderDiff( revisionHTML ) {
	const container = document.querySelector( '[data-post-history-diff-container]' );
	if ( container ) {
		container.innerHTML = HtmlDiff.execute( revisionHTML, originalHTML );
	}
}

/**
 * Change the diff currently applied to the container.
 *
 * @param {number} id WordPress post ID for the revision to use.
 */
function switchToDiff( id ) {
	const revision = revisions.get( id );
	if ( revision ) {
		renderDiff( normalizeHTMLString( revision.content ) );
		document.dispatchEvent( new CustomEvent( 'hm-post-history.diff-switched', {
			detail: {
				currentDiffID: id,
			},
		} ) );
	}
}

/**
 * Get revisions from REST API and load them into revisions map.
 *
 * @param {number} page "Page" of results to get. Default 1.
 */
function loadRevisions( page ) {
	if ( typeof page !== 'number' ) {
		// If called with no/bad arguments, default to first page.
		page = 1;
	}
	const endpoint = new URL( `${settings.api.base.pathname}/revisions/${settings.postID}/`, settings.api.base.href );
	endpoint.searchParams.set( 'paged', page );
	endpoint.searchParams.set( 'per_page', settings.default.per_page );
	const req = new Request( endpoint.href, {
		credentials: 'include',
		headers: new Headers( {
			'X-WP-Nonce': settings.api.nonce,
		} ),
	} );

	const loadMoreButton = getLoadMoreButton();
	// Turn on loading state for button.
	loadMoreButton.classList.add( 'hm-post-history__load-more--loading' );

	fetch( req ).then( ( response ) => {
		// One way or another, loading is finished.
		loadMoreButton.classList.remove( 'hm-post-history__load-more--loading' );

		if ( response.ok ) {
			return response.json();
		}
		loadMoreButton.classList.add( 'hm-post-history__load-more--failed' );
	} ).then( ( json ) => {
		json.revisions.map( ( revision ) => {
			if ( ! revisions.has( revision.id ) ) {
				revisions.set( revision.id, revision );
			}
			return revisions;
		} );
		if ( json.hasMore === false ) {
			document.dispatchEvent( new Event( 'hm-post-history.no-more-revisions' ) );
		}
	} );
}

/**
 * Start up all history functionality.
 */
function init() {
	preflight();

	if ( ! canRun() ) {
		return;
	}

	setupDiffContainer();

	setupDiffList();

	setupLoadMoreButton();
}

export default init;
