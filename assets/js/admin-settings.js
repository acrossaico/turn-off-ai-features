( function () {
	var main = document.getElementById( 'toaif_disable_ai' );
	if ( ! main ) {
		return;
	}

	var subOptions = [
		'toaif_disable_abilities',
		'toaif_disable_mcp',
		'toaif_hide_connectors'
	];

	var rows = [];

	subOptions.forEach( function ( id ) {
		var field = document.getElementById( id );
		if ( ! field ) {
			return;
		}
		var row = field.closest( 'tr' );
		if ( row ) {
			rows.push( row );
		}
	} );

	if ( ! rows.length ) {
		return;
	}

	var update = function () {
		rows.forEach( function ( row ) {
			row.style.display = main.checked ? '' : 'none';
		} );
	};

	main.addEventListener( 'change', update );
	update();
} )();
