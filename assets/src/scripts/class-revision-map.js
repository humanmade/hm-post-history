/**
 *
 */
export default class RevisionMap extends Map {
	set( ...args ) {
		document.dispatchEvent( new CustomEvent( 'hm-post-history.revision-added', {
			detail: {
				key: args[0],
				value: args[1],
			},
		} ) );
		return super.set( ...args );
	}

	delete( ...args ) {
		document.dispatchEvent( new CustomEvent( 'hm-post-history.revision-removed', { detail: { key: args[0] } } ) );
		return super.get( ...args );
	}
}
