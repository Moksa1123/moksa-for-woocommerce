( function ( $ ) {
	'use strict';

	$( function () {
		const $list = $( '#mo-tw-field-list' );
		const $hidden = $( '#mo-tw-field-layout-input' );

		if ( ! $list.length || ! $hidden.length ) {
			return;
		}

		function serialize() {
			const data = [];
			$list.find( 'li' ).each( function () {
				const $li = $( this );
				const key = String( $li.data( 'key' ) );
				const width = parseInt( $li.find( 'input[type=radio]:checked' ).val(), 10 ) || 100;
				const enabled = $li.find( '.mo-tw-enable-checkbox' ).is( ':checked' );
				const required = $li.find( '.mo-tw-required-checkbox' ).is( ':checked' );
				data.push( { key, width, enabled, required } );
			} );
			$hidden.val( JSON.stringify( data ) );
		}

		function syncEnableState( $li ) {
			const checked = $li.find( '.mo-tw-enable-checkbox' ).is( ':checked' );
			$li.toggleClass( 'is-disabled', ! checked );
		}

		serialize();

		$list.sortable( {
			items: '> li',
			handle: '.mo-tw-drag',
			axis: 'y',
			placeholder: 'mo-tw-placeholder',
			tolerance: 'pointer',
			update: serialize,
		} );

		$list.on( 'change', 'input[type=radio]', serialize );
		$list.on( 'change', '.mo-tw-enable-checkbox', function () {
			syncEnableState( $( this ).closest( 'li' ) );
			serialize();
		} );
		$list.on( 'change', '.mo-tw-required-checkbox', serialize );
	} );
} )( jQuery );
