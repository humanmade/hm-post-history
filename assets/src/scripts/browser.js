import '../styles/browser.scss';
import HtmlDiff from 'htmldiff-js';

const { __, sprintf } = window.wp.i18n;
const { apiFetch } = window.wp;
const changeSelector = 'ins.diffins, ins.diffmod, del.diffdel, del.diffmod';

// Preserve the live DOM while historical content is shown in the actual article.
document.querySelectorAll( '[data-hm-history]' ).forEach( ( root ) => {
	/**
	 * @param {string} selector Attribute suffix.
	 * @returns {HTMLElement} Matching browser control.
	 */
	const find = ( selector ) => root.querySelector( `[data-history-${selector}]` );
	const status = find( 'status' );
	const versionList = find( 'versions' );
	const fixedList = find( 'fixed' );
	const article = root.closest( 'main' )?.querySelector( '.hm-history-current' );
	if ( ! article ) {
		return;
	}
	const originalNodes = Array.from( article.childNodes );
	const title = root.closest( 'main' )?.querySelector( 'h1' );
	const originalTitleNodes = title ? Array.from( title.childNodes ) : [];
	let showingHistory = false;

	/**
	 * Restore the same DOM nodes, preserving live block state and event listeners.
	 */
	function restoreArticle() {
		if ( ! showingHistory ) {
			return;
		}
		article.replaceChildren( ...originalNodes );
		article.classList.remove( 'hm-history-reading' );
		article.removeAttribute( 'aria-label' );
		if ( title ) {
			title.replaceChildren( ...originalTitleNodes );
			title.classList.remove( 'hm-history-reading-title' );
		}
		showingHistory = false;
	}

	/** @returns {Element|null} First visible title or article change, excluding supplemental UI. */
	function firstChange() {
		const changes = [ ...( title?.querySelectorAll( changeSelector ) || [] ), ...article.querySelectorAll( '[data-history-content-change]' ) ];
		return changes.find( ( change ) => {
			const bounds = change.getBoundingClientRect();
			return bounds.width > 0 && bounds.height > 0;
		} ) || null;
	}

	/** @param {Element} change Visible change to position below fixed page headers. */
	function revealFirstChange( change ) {
		const bounds = change.getBoundingClientRect();
		let inset = 24;
		document.querySelectorAll( 'header, #wpadminbar' ).forEach( ( header ) => {
			const position = window.getComputedStyle( header ).position;
			const area = header.getBoundingClientRect();
			if ( [ 'fixed', 'sticky' ].includes( position ) && area.left < bounds.right && area.right > bounds.left && area.bottom > 0 ) {
				inset = Math.max( inset, area.bottom + 24 );
			}
		} );
		window.scrollTo( {
			top: Math.max( 0, window.scrollY + bounds.top - inset ),
			behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'instant' : 'smooth',
		} );
	}

	/** @param {number|null} id Revision being loaded, or null when loading finishes. */
	function setLoading( id ) {
		article.setAttribute( 'aria-busy', String( id !== null ) );
		root.querySelectorAll( '[data-version]' ).forEach( ( button ) => {
			button.setAttribute( 'aria-busy', String( id !== null && Number( button.dataset.version ) === id ) );
		} );
	}

	/**
	 * Keep substantive replacements together instead of alternating isolated words.
	 *
	 * @param {string} before Earlier sanitized markup.
	 * @param {string} after Later sanitized markup.
	 * @returns {string} Markup with insertion and deletion elements.
	 */
	function renderedDiff( before, after ) {
		const diff = new HtmlDiff( before, after );
		diff.orphanMatchThreshold = 0.25;
		return diff.build();
	}

	/**
	 * Show safe saved markup in the article's normal reading layout.
	 *
	 * @param {object} data Authorized version response.
	 */
	function renderArticle( data ) {
		const comparing = data.inline;
		const html = comparing ? renderedDiff( data.inline.before.html, data.inline.after.html ) : data.html;
		const template = document.createElement( 'template' );
		template.innerHTML = html;
		// Preserve section navigation while dropping duplicate IDs created by the diff.
		const usedIds = new Set( Array.from( document.querySelectorAll( '[id]' ) )
			.filter( ( element ) => ! article.contains( element ) )
			.map( ( element ) => element.id ) );
		template.content.querySelectorAll( '[id]' ).forEach( ( element ) => {
			if ( usedIds.has( element.id ) ) {
				element.removeAttribute( 'id' );
			} else {
				usedIds.add( element.id );
			}
		} );
		template.content.querySelectorAll( changeSelector ).forEach( ( change ) => {
			change.dataset.historyContentChange = '';
		} );
		article.replaceChildren( template.content );
		article.classList.add( 'hm-history-reading' );
		article.setAttribute( 'aria-label', comparing ? __( 'Article with changes highlighted', 'hm-post-history' ) : __( 'Historical article', 'hm-post-history' ) );
		if ( title ) {
			title.classList.add( 'hm-history-reading-title' );
			if ( comparing ) {
				// Titles are plain text, so escape them before passing through the HTML diff.
				/**
				 * @param {string} value Plain title text.
				 * @returns {string} Escaped markup.
				 */
				const escape = ( value ) => {
					const span = document.createElement( 'span' );
					span.textContent = value;
					return span.innerHTML;
				};
				title.innerHTML = renderedDiff( escape( data.inline.before.title ), escape( data.inline.after.title ) );
			} else {
				title.textContent = data.title;
			}
		}
		showingHistory = true;
	}

	const versionIds = new Set();
	let selected = 0;
	let baseline = 0;
	const currentMatches = new Set();
	let next = null;
	let initialized = false;
	let loadingList = false;
	let listRequest = null;
	let requestNumber = 0;
	let retry;
	const priorHash = window.location.hash.startsWith( '#history=' ) ? '' : window.location.hash;

	/**
	 * @param {string} message Screen-reader and visible status.
	 * @param {boolean} visible Whether a loading/error message needs visual attention.
	 */
	function announce( message, visible = false ) {
		status.textContent = message;
		status.classList.toggle( 'hm-history__sr-only', ! visible );
	}

	/**
	 * @param {string} suffix Relative API path.
	 * @returns {Promise<object>} Authorized server response.
	 */
	async function request( suffix ) {
		const response = await apiFetch( {
			path: `${root.dataset.endpoint}${suffix}`,
			parse: false,
		} ).catch( ( error ) => {
			throw new Error( error.status
				? __( 'History could not be loaded. Your access may have changed. Try again or reload the page.', 'hm-post-history' )
				: __( 'History could not be loaded. Check your connection and try again.', 'hm-post-history' ) );
		} );
		return {
			data: await response.json(),
			totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) ),
		};
	}

	/**
	 * Read a post or revision using Core's controller, with the timeline presentation field.
	 *
	 * @param {number} id Revision ID, or zero for the current post.
	 * @returns {Promise<object>} Core post or revision response.
	 */
	async function readVersion( id ) {
		const { data } = await request( `${id ? `/revisions/${id}` : ''}?context=view&_fields=id,title,content,modified_gmt,hm_history` );
		data.hm_history = versionMetadata( data, id === 0 );
		return data;
	}

	/**
	 * @param {object} post Core response containing timeline presentation metadata.
	 * @param {boolean} current Whether this is the live post.
	 * @returns {object} Timeline entry.
	 */
	function versionMetadata( post, current = false ) {
		if ( ! post.hm_history ) {
			throw new Error( __( 'This version is unavailable.', 'hm-post-history' ) );
		}
		return {
			...post.hm_history,
			id: current ? 0 : post.id,
			current,
		};
	}

	/**
	 * Adapt two Core responses to the existing inline comparison renderer.
	 *
	 * @param {object} version Selected Core response.
	 * @param {object} base Pinned Core response.
	 * @returns {object} Chronological comparison, or a large-document preview.
	 */
	function comparison( version, base ) {
		/**
		 * @param {object} post Core post or revision response.
		 * @returns {object} Plain title and rendered article.
		 */
		const snapshot = ( post ) => {
			const heading = document.createElement( 'template' );
			heading.innerHTML = post.title.rendered;
			return {
				title: heading.content.textContent,
				html: post.content.rendered,
			};
		};
		const order = ( version.modified_gmt || '' ).localeCompare( base.modified_gmt || '' )
			|| Number( version.hm_history.current ) - Number( base.hm_history.current )
			|| version.id - base.id;
		const [ before, after ] = ( order <= 0 ? [ version, base ] : [ base, version ] ).map( snapshot );
		const html = before.html + after.html;
		return {
			version: version.hm_history,
			comparison: base.hm_history,
			title: snapshot( version ).title,
			html: version.content.rendered,
			inline: new TextEncoder().encode( html ).length > 200000 || html.split( '\n' ).length > 2001 ? null : {
				before,
				after,
			},
		};
	}

	/**
	 * @param {object} version Server version metadata.
	 */
	function addVersion( version ) {
		if ( ! version.current && version.matches_current ) {
			currentMatches.add( version.id );
			return;
		}
		if ( versionIds.has( version.id ) ) {
			return;
		}
		versionIds.add( version.id );
		const item = document.createElement( 'li' );
		if ( version.current ) {
			item.className = 'hm-history__current';
		}
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'hm-history__revision';
		button.dataset.version = version.id;
		const states = document.createElement( 'span' );
		states.className = 'hm-history__states';
		states.dataset.historyStates = '';
		states.hidden = true;
		const baseLabel = document.createElement( 'span' );
		baseLabel.className = 'hm-history__state hm-history__state--base';
		baseLabel.dataset.historyBase = '';
		baseLabel.textContent = __( 'Compare from', 'hm-post-history' );
		states.append( baseLabel );
		button.append( states );
		const date = document.createElement( 'span' );
		date.className = 'hm-history__date';
		date.textContent = version.current ? __( 'Current version', 'hm-post-history' ) : version.date;
		const author = document.createElement( 'span' );
		author.className = 'hm-history__author';
		const avatar = document.createElement( 'span' );
		avatar.className = 'hm-history__avatar';
		avatar.setAttribute( 'role', 'img' );
		avatar.setAttribute( 'aria-label', sprintf( __( 'Author: %s', 'hm-post-history' ), version.author ) );
		avatar.title = version.author;
		avatar.textContent = version.author.split( /\s+/ ).map( ( part ) => part[0] ).slice( 0, 2 ).join( '' );
		if ( version.avatar ) {
			const image = document.createElement( 'img' );
			image.src = version.avatar;
			image.alt = '';
			image.width = 18;
			image.height = 18;
			image.loading = 'lazy';
			image.addEventListener( 'error', () => image.remove() );
			avatar.append( image );
		}
		author.append( avatar );
		if ( version.current ) {
			const timestamp = document.createElement( 'span' );
			timestamp.className = 'hm-history__timestamp';
			timestamp.textContent = version.date;
			button.append( date, timestamp, author );
		} else {
			button.append( date, author );
		}
		button.addEventListener( 'click', () => select( version.id ) );
		const pin = document.createElement( 'button' );
		pin.type = 'button';
		pin.className = 'hm-history__pin';
		pin.dataset.pinVersion = version.id;
		pin.dataset.pinLabel = version.current ? __( 'current version', 'hm-post-history' ) : sprintf( __( '%1$s by %2$s', 'hm-post-history' ), version.date, version.author );
		pin.setAttribute( 'aria-label', sprintf( __( 'Pin %s as comparison base', 'hm-post-history' ), pin.dataset.pinLabel ) );
		pin.title = pin.getAttribute( 'aria-label' );
		pin.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 17v5M5 17h14v-2l-3-3V5l1-1V2H7v2l1 1v7l-3 3z"/></svg>';
		pin.addEventListener( 'click', () => {
			baseline = baseline === version.id ? 0 : version.id;
			select( selected, true, false );
		} );
		item.append( button, pin );
		positionVersion( item, version.id );
	}

	/**
	 * Keep only the comparison base fixed, with current first in the scrolling list otherwise.
	 *
	 * @param {HTMLElement} item Version row to place.
	 * @param {number} id Native revision ID, or zero for current.
	 */
	function positionVersion( item, id ) {
		const target = id === baseline ? fixedList : versionList;
		if ( item.parentElement === target ) {
			return;
		}
		const focused = item.contains( document.activeElement ) ? document.activeElement : null;
		if ( target === fixedList ) {
			fixedList.append( item );
		} else if ( id === 0 ) {
			versionList.prepend( item );
		} else {
			// Unpinning restores chronological position without cloning the row or its listeners.
			const nextRow = Array.from( versionList.querySelectorAll( '[data-version]' ) )
				.find( ( button ) => Number( button.dataset.version ) > 0 && Number( button.dataset.version ) < id )?.closest( 'li' );
			versionList.insertBefore( item, nextRow || find( 'more' ) );
		}
		focused?.focus( { preventScroll: true } );
	}

	/** Update selection labels, pin state and the placement of each loaded version. */
	function controls() {
		const empty = initialized && versionIds.size === 1 && ! next;
		root.classList.toggle( 'hm-history--empty', empty );
		root.querySelector( '.hm-history__workspace' ).hidden = ! initialized || empty;
		find( 'more' ).hidden = ! next;
		root.querySelectorAll( '[data-version]' ).forEach( ( button ) => {
			const id = Number( button.dataset.version );
			positionVersion( button.closest( 'li' ), id );
			button.querySelector( '[data-history-states]' ).hidden = id !== baseline;
			if ( id === selected ) {
				button.setAttribute( 'aria-current', 'true' );
			} else {
				button.removeAttribute( 'aria-current' );
			}
		} );
		root.querySelectorAll( '[data-pin-version]' ).forEach( ( pin ) => {
			const pinned = Number( pin.dataset.pinVersion ) === baseline;
			pin.setAttribute( 'aria-pressed', String( pinned ) );
			pin.setAttribute( 'aria-label', pinned && baseline !== 0 ? sprintf( __( 'Unpin %s; compare with current version', 'hm-post-history' ), pin.dataset.pinLabel ) : sprintf( __( 'Pin %s as comparison base', 'hm-post-history' ), pin.dataset.pinLabel ) );
			pin.title = pin.getAttribute( 'aria-label' );
			pin.closest( 'li' ).classList.toggle( 'hm-history__pinned', pinned );
		} );
	}

	/**
	 * Share an in-flight page request when the disclosure is closed and reopened.
	 *
	 * @returns {Promise<boolean>} Whether another batch loaded.
	 */
	function loadList() {
		if ( ! listRequest ) {
			listRequest = loadNextPage().finally( () => {
				listRequest = null;
			} );
		}
		return listRequest;
	}

	/** @returns {Promise<boolean>} Whether another batch loaded. */
	async function loadNextPage() {
		const firstLoad = ! initialized;
		loadingList = true;
		controls();
		announce( __( 'Loading revisions', 'hm-post-history' ), ! initialized );
		try {
			// A batch may contain only snapshots identical to current, or inaccessible
			// evidence. Advance to the next page until an entry can actually be displayed.
			const previousCount = versionIds.size;
			if ( firstLoad ) {
				const { data } = await request( '?context=view&_fields=id,hm_history' );
				addVersion( versionMetadata( data, true ) );
			}
			do {
				const page = next || 1;
				const { data, totalPages } = await request( `/revisions?context=view&per_page=20&page=${page}&orderby=id&order=desc&_fields=id,hm_history` );
				data.forEach( ( revision ) => {
					if ( revision.hm_history ) {
						addVersion( versionMetadata( revision ) );
					}
				} );
				next = page < totalPages ? page + 1 : null;
			} while ( next && root.open && versionIds.size <= Math.max( 1, previousCount ) );
			initialized = true;
			find( 'retry' ).hidden = true;
			const empty = versionIds.size === 1 && ! next;
			announce( empty ? __( 'No earlier versions yet.', 'hm-post-history' ) : __( 'Page versions loaded.', 'hm-post-history' ), empty );
			return true;
		} catch ( error ) {
			announce( error.message, true );
			// Initial retries must re-read the deep link, including its pinned base.
			retry = firstLoad ? open : loadList;
			find( 'retry' ).hidden = false;
			return false;
		} finally {
			loadingList = false;
			controls();
		}
	}

	/**
	 * @param {number} id Native revision ID, or zero for current.
	 * @param {boolean} updateURL Whether to update the deep link.
	 * @param {boolean} revealChange Whether to scroll to the first change after selection.
	 * @returns {Promise<void>} Resolves after display or a recoverable error.
	 */
	async function select( id, updateURL = true, revealChange = true ) {
		const sequence = ++requestNumber;
		restoreArticle();
		selected = id;
		controls();
		const panel = root.querySelector( '.hm-history__version' );
		panel.setAttribute( 'aria-busy', 'true' );
		// Remove stale content immediately: a revoked version must not appear under a new label.
		find( 'notice' ).hidden = true;
		if ( id === 0 && baseline === 0 ) {
			setLoading( null );
			find( 'retry' ).hidden = true;
			panel.setAttribute( 'aria-busy', 'false' );
			const empty = initialized && versionIds.size === 1 && ! next;
			announce( empty ? __( 'No earlier versions yet.', 'hm-post-history' ) : __( 'Current version displayed.', 'hm-post-history' ), empty );
			if ( updateURL ) {
				window.history.replaceState( null, '', '#history=0' );
			}
			return;
		}
		setLoading( id );
		announce( __( 'Loading revisions', 'hm-post-history' ) );
		try {
			const [ versionResponse, baseResponse ] = await Promise.all( [ readVersion( id ), id === baseline ? null : readVersion( baseline ) ] );
			const data = comparison( versionResponse, baseResponse || versionResponse );
			if ( sequence !== requestNumber || ! root.open ) {
				return;
			}
			const version = data.version;
			addVersion( version );
			addVersion( data.comparison );
			controls();
			const note = ! data.inline ? __( 'This comparison is too large to highlight inline.', 'hm-post-history' ) : '';
			find( 'notice' ).hidden = ! note;
			find( 'notice' ).textContent = note;
			find( 'retry' ).hidden = true;
			renderArticle( data );
			const change = firstChange();
			if ( data.inline && ! change ) {
				find( 'notice' ).hidden = false;
				find( 'notice' ).textContent = baseline === id ? __( 'Select another revision to compare with this pinned version.', 'hm-post-history' ) : __( 'No visible changes between these versions.', 'hm-post-history' );
			}
			announce( sprintf( __( 'Version loaded. %1$s · %2$s.', 'hm-post-history' ), version.date, version.author ) );
			if ( updateURL ) {
				window.history.replaceState( null, '', `#history=${id}${baseline ? `&base=${baseline}` : ''}` );
			}
			if ( revealChange && change ) {
				revealFirstChange( change );
			}
		} catch ( error ) {
			if ( sequence !== requestNumber || ! root.open ) {
				return;
			}
			restoreArticle();
			announce( error.message, true );
			find( 'notice' ).hidden = true;
			/** @returns {Promise<void>} Reload the selected version. */
			retry = () => select( selected );
			find( 'retry' ).hidden = false;
		} finally {
			if ( sequence === requestNumber ) {
				setLoading( null );
				panel.setAttribute( 'aria-busy', 'false' );
			}
		}
	}

	/** @returns {Promise<void>} Resolves after the first batch and selection. */
	async function open() {
		const sequence = ++requestNumber;
		if ( initialized ) {
			await select( selected, true, false );
			return;
		}
		if ( await loadList() && root.open && sequence === requestNumber ) {
			const params = new URLSearchParams( window.location.hash.slice( 1 ) );
			const base = Number( params.get( 'base' ) );
			baseline = Number.isSafeInteger( base ) && base > 0 && ! currentMatches.has( base ) ? base : 0;
			const requested = Number( params.get( 'history' ) );
			const id = Number.isSafeInteger( requested ) && requested > 0 && ! currentMatches.has( requested ) ? requested : 0;
			await select( id, true, false );
		}
	}

	root.addEventListener( 'toggle', () => {
		if ( root.open ) {
			open();
		} else {
			++requestNumber;
			setLoading( null );
			root.querySelector( '.hm-history__version' ).setAttribute( 'aria-busy', 'false' );
			restoreArticle();
			if ( window.location.hash.startsWith( '#history=' ) ) {
				window.history.replaceState( null, '', window.location.pathname + window.location.search + priorHash );
			}
		}
	} );
	find( 'retry' ).addEventListener( 'click', async () => {
		await retry();
	} );
	const olderObserver = new IntersectionObserver( ( entries ) => {
		if ( entries.some( ( entry ) => entry.isIntersecting ) && root.open && next && ! loadingList ) {
			loadList();
		}
	}, {
		root: versionList,
		rootMargin: '0px 0px 100px 0px',
	} );
	olderObserver.observe( find( 'more' ) );
	if ( window.location.hash.startsWith( '#history=' ) ) {
		root.open = true;
	}
} );
