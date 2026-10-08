/*
 * Energy Bill Comparison - admin.
 *
 * Only job: the tariff rate repeater on the plan edit screen.
 */
( function () {
	'use strict';

	var body = document.getElementById( 'aec-rates-body' );
	var template = document.getElementById( 'aec-rate-template' );
	var addButton = document.getElementById( 'aec-add-rate' );
	var fuelSelect = document.getElementById( 'aec-plan-fuel' );

	if ( ! body || ! template ) {
		return;
	}

	/*
	 * Row indexes have to stay unique for the whole life of the page, so the
	 * counter starts past the rows already rendered and never goes down. Reusing
	 * an index after a delete would make two rows collide in the POST array and
	 * silently drop one.
	 */
	var nextIndex = body.querySelectorAll( '.aec-rate-row' ).length;

	function defaultUnit() {
		return fuelSelect && fuelSelect.value === 'gas' ? 'MJ' : 'kWh';
	}

	function defaultGroup() {
		return fuelSelect && fuelSelect.value === 'gas' ? 'gas_usage' : 'single';
	}

	function addRow() {
		var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
		nextIndex++;

		var holder = document.createElement( 'tbody' );
		holder.innerHTML = html.trim();

		var row = holder.querySelector( 'tr' );
		if ( ! row ) {
			return;
		}

		var unit = row.querySelector( 'input[name$="[unit]"]' );
		if ( unit ) {
			unit.value = defaultUnit();
		}

		var group = row.querySelector( 'select[name$="[rate_group]"]' );
		if ( group ) {
			group.value = defaultGroup();
		}

		body.appendChild( row );
		removeEmptyMessage();

		var rate = row.querySelector( 'input[name$="[rate_cents]"]' );
		if ( rate ) {
			rate.focus();
		}
	}

	function removeEmptyMessage() {
		var empty = body.querySelector( '.aec-rates-empty' );
		if ( empty ) {
			empty.remove();
		}
	}

	function showEmptyMessage() {
		if ( body.querySelector( '.aec-rate-row' ) || body.querySelector( '.aec-rates-empty' ) ) {
			return;
		}
		var row = document.createElement( 'tr' );
		row.className = 'aec-rates-empty';
		var cell = document.createElement( 'td' );
		cell.colSpan = 6;
		cell.textContent = 'No rates yet. Add at least one usage rate or this plan cannot be compared.';
		row.appendChild( cell );
		body.appendChild( row );
	}

	if ( addButton ) {
		addButton.addEventListener( 'click', addRow );
	}

	body.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '.aec-remove-rate' );
		if ( ! button ) {
			return;
		}
		var row = button.closest( '.aec-rate-row' );
		if ( row ) {
			row.remove();
			showEmptyMessage();
		}
	} );

	// Switching fuel on a brand new plan should move the default unit with it.
	if ( fuelSelect ) {
		fuelSelect.addEventListener( 'change', function () {
			body.querySelectorAll( '.aec-rate-row' ).forEach( function ( row ) {
				var unit = row.querySelector( 'input[name$="[unit]"]' );
				if ( ! unit ) {
					return;
				}
				// Only retype a unit that is still the other fuel's default, so
				// a hand-edited value is left alone.
				if ( unit.value === 'kWh' || unit.value === 'MJ' ) {
					unit.value = defaultUnit();
				}
			} );
		} );
	}

	showEmptyMessage();
} )();
