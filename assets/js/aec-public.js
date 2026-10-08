/*
 * Energy Bill Comparison - front end.
 *
 * Plain DOM, no framework and no jQuery dependency, so it runs on any theme.
 * The three REST calls mirror the three screens: upload, compare, enquiry.
 */
( function () {
	'use strict';

	var cfg = window.AEC_CONFIG || {};
	var S = cfg.strings || {};

	var app = document.getElementById( 'aec-app' );
	if ( ! app ) {
		return;
	}

	var state = {
		token: '',
		results: [],
		selected: null,
		currentAnnual: null
	};

	var els = {
		alert: document.getElementById( 'aec-alert' ),
		steps: app.querySelectorAll( '.aec-step' ),
		panels: {
			upload: document.getElementById( 'aec-panel-upload' ),
			review: document.getElementById( 'aec-panel-review' ),
			results: document.getElementById( 'aec-panel-results' ),
			lead: document.getElementById( 'aec-panel-lead' ),
			done: document.getElementById( 'aec-panel-done' )
		},
		uploadForm: document.getElementById( 'aec-upload-form' ),
		file: document.getElementById( 'aec-file' ),
		drop: document.getElementById( 'aec-drop' ),
		chosen: document.getElementById( 'aec-chosen' ),
		uploadSubmit: document.getElementById( 'aec-upload-submit' ),
		manual: document.getElementById( 'aec-manual' ),
		progress: document.getElementById( 'aec-progress' ),
		progressText: document.getElementById( 'aec-progress-text' ),
		reviewForm: document.getElementById( 'aec-review-form' ),
		reviewWarnings: document.getElementById( 'aec-review-warnings' ),
		usageElec: document.getElementById( 'aec-usage-electricity' ),
		usageGas: document.getElementById( 'aec-usage-gas' ),
		fuel: document.getElementById( 'aec-fuel' ),
		postcode: document.getElementById( 'aec-postcode' ),
		stateSel: document.getElementById( 'aec-state' ),
		summary: document.getElementById( 'aec-summary' ),
		resultNotes: document.getElementById( 'aec-result-notes' ),
		results: document.getElementById( 'aec-results' ),
		leadForm: document.getElementById( 'aec-lead-form' ),
		leadIntro: document.getElementById( 'aec-lead-intro' ),
		doneMessage: document.getElementById( 'aec-done-message' )
	};

	/* --- helpers --------------------------------------------------------- */

	/**
	 * Fill %s / %d / %1$s style placeholders in a translated string.
	 *
	 * @param {string} template
	 * @param {...*} values
	 * @return {string}
	 */
	function fmt( template ) {
		var values = Array.prototype.slice.call( arguments, 1 );
		var auto = 0;
		return String( template === undefined ? '' : template ).replace(
			/%(\d+\$)?[sd]/g,
			function ( match, position ) {
				var index = position ? parseInt( position, 10 ) - 1 : auto++;
				return values[ index ] === undefined ? '' : String( values[ index ] );
			}
		).replace( /%%/g, '%' );
	}

	function text( value ) {
		return value === null || value === undefined ? '' : String( value );
	}

	function el( tag, className, content ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( content !== undefined && content !== null ) {
			node.textContent = String( content );
		}
		return node;
	}

	function showPanel( name ) {
		Object.keys( els.panels ).forEach( function ( key ) {
			if ( els.panels[ key ] ) {
				els.panels[ key ].hidden = key !== name;
			}
		} );

		var order = [ 'upload', 'review', 'results' ];
		var active = name === 'lead' || name === 'done' ? 'results' : name;
		var index = order.indexOf( active );

		Array.prototype.forEach.call( els.steps, function ( step, i ) {
			step.classList.toggle( 'is-current', i === index );
			step.classList.toggle( 'is-done', index > -1 && i < index );
		} );

		if ( els.panels[ name ] ) {
			var top = els.panels[ name ].getBoundingClientRect().top + window.pageYOffset - 24;
			window.scrollTo( { top: top, behavior: 'smooth' } );
		}
	}

	function showAlert( message ) {
		if ( ! els.alert ) {
			return;
		}
		els.alert.textContent = message;
		els.alert.hidden = false;
		els.alert.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
	}

	function clearAlert() {
		if ( els.alert ) {
			els.alert.hidden = true;
			els.alert.textContent = '';
		}
	}

	function busy( on, message ) {
		if ( ! els.progress ) {
			return;
		}
		els.progress.hidden = ! on;
		if ( els.progressText ) {
			els.progressText.textContent = on ? text( message ) : '';
		}
	}

	function request( path, body, isForm ) {
		var options = {
			method: 'POST',
			headers: { 'X-WP-Nonce': cfg.nonce },
			body: isForm ? body : JSON.stringify( body )
		};
		if ( ! isForm ) {
			options.headers['Content-Type'] = 'application/json';
		}

		return fetch( cfg.root + path, options ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					var message = data && data.message ? data.message : S.genericFail;
					throw new Error( message );
				}
				return data;
			} ).catch( function ( err ) {
				// A non-JSON body means the server died before WordPress could
				// format an error; say something useful rather than
				// "unexpected token <".
				if ( err instanceof SyntaxError ) {
					throw new Error( S.genericFail );
				}
				throw err;
			} );
		} );
	}

	/* --- step 1: upload -------------------------------------------------- */

	function fileChosen( file ) {
		clearAlert();

		if ( ! file ) {
			els.chosen.hidden = true;
			els.uploadSubmit.disabled = true;
			return;
		}

		var isPdf = /\.pdf$/i.test( file.name ) || file.type === 'application/pdf';
		if ( ! isPdf ) {
			showAlert( S.notPdf );
			els.file.value = '';
			els.chosen.hidden = true;
			els.uploadSubmit.disabled = true;
			return;
		}

		if ( cfg.maxMb && file.size > cfg.maxMb * 1024 * 1024 ) {
			showAlert( S.tooBig );
			els.file.value = '';
			els.chosen.hidden = true;
			els.uploadSubmit.disabled = true;
			return;
		}

		els.chosen.textContent = file.name;
		els.chosen.hidden = false;
		els.uploadSubmit.disabled = false;
	}

	if ( els.file ) {
		els.file.addEventListener( 'change', function () {
			fileChosen( els.file.files && els.file.files[0] );
		} );
	}

	if ( els.drop ) {
		[ 'dragenter', 'dragover' ].forEach( function ( evt ) {
			els.drop.addEventListener( evt, function ( e ) {
				e.preventDefault();
				els.drop.classList.add( 'is-over' );
			} );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( evt ) {
			els.drop.addEventListener( evt, function ( e ) {
				e.preventDefault();
				els.drop.classList.remove( 'is-over' );
			} );
		} );
		els.drop.addEventListener( 'drop', function ( e ) {
			if ( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length ) {
				els.file.files = e.dataTransfer.files;
				fileChosen( e.dataTransfer.files[0] );
			}
		} );
	}

	if ( els.uploadForm ) {
		els.uploadForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearAlert();

			var file = els.file.files && els.file.files[0];
			if ( ! file ) {
				showAlert( S.notPdf );
				return;
			}

			var data = new FormData();
			data.append( 'bill', file );

			els.uploadSubmit.disabled = true;
			busy( true, S.uploading );

			// The read is the slow part, so the message changes once the bytes
			// are likely to be across.
			var swap = window.setTimeout( function () {
				busy( true, S.reading );
			}, 1500 );

			request( '/upload', data, true ).then( function ( data ) {
				window.clearTimeout( swap );
				busy( false );
				els.uploadSubmit.disabled = false;
				state.token = data.token || '';
				fillReview( data );
				showPanel( 'review' );
			} ).catch( function ( err ) {
				window.clearTimeout( swap );
				busy( false );
				els.uploadSubmit.disabled = false;
				showAlert( err.message );
			} );
		} );
	}

	if ( els.manual ) {
		els.manual.addEventListener( 'click', function () {
			clearAlert();
			state.token = '';
			fillReview( {
				fields: { fuel: app.dataset.fuel || 'electricity', state: app.dataset.state || '' },
				confidence: {},
				check: [],
				warnings: []
			} );
			showPanel( 'review' );
		} );
	}

	/* --- step 2: review -------------------------------------------------- */

	function setValue( name, value ) {
		var field = els.reviewForm.querySelector( '[name="' + name + '"]' );
		if ( ! field ) {
			return;
		}
		field.value = value === null || value === undefined ? '' : value;
	}

	function fillReview( data ) {
		var fields = data.fields || {};
		var usage = fields.usage || {};

		setValue( 'fuel', fields.fuel || app.dataset.fuel || 'electricity' );
		setValue( 'postcode', fields.postcode );
		setValue( 'state', fields.state || app.dataset.state || '' );
		setValue( 'billing_days', fields.billing_days ? fields.billing_days : '' );
		setValue( 'total_amount', fields.total_amount );
		setValue( 'retailer_name', fields.retailer_name );

		[ 'total_kwh', 'peak_kwh', 'shoulder_kwh', 'offpeak_kwh', 'controlled_load_kwh', 'solar_export_kwh', 'demand_kw', 'gas_mj' ]
			.forEach( function ( key ) {
				setValue( 'usage[' + key + ']', usage[ key ] );
			} );

		// Mark the fields the reader was unsure about.
		app.querySelectorAll( '.aec-field[data-field]' ).forEach( function ( node ) {
			node.classList.remove( 'is-check' );
			var label = node.querySelector( 'label' );
			if ( label ) {
				label.removeAttribute( 'data-check-label' );
			}
		} );

		( data.check || [] ).forEach( function ( path ) {
			var node = app.querySelector( '.aec-field[data-field="' + path + '"]' );
			if ( ! node ) {
				return;
			}
			node.classList.add( 'is-check' );
			var label = node.querySelector( 'label' );
			if ( label ) {
				label.setAttribute( 'data-check-label', S.fieldCheck || 'check' );
			}
		} );

		var warnings = ( data.warnings || [] ).slice();
		if ( data.ai_notes ) {
			warnings.push( data.ai_notes );
		}

		if ( warnings.length && els.reviewWarnings ) {
			els.reviewWarnings.innerHTML = '';
			var list = el( 'ul' );
			warnings.forEach( function ( message ) {
				list.appendChild( el( 'li', null, message ) );
			} );
			els.reviewWarnings.appendChild( list );
			els.reviewWarnings.hidden = false;
		} else if ( els.reviewWarnings ) {
			els.reviewWarnings.hidden = true;
		}

		toggleFuelFields();
	}

	function toggleFuelFields() {
		var isGas = els.fuel && els.fuel.value === 'gas';
		if ( els.usageElec ) {
			els.usageElec.hidden = isGas;
		}
		if ( els.usageGas ) {
			els.usageGas.hidden = ! isGas;
		}
	}

	if ( els.fuel ) {
		els.fuel.addEventListener( 'change', toggleFuelFields );
	}

	// Filling in a postcode is enough to pick the state, so do it for them.
	if ( els.postcode && els.stateSel ) {
		els.postcode.addEventListener( 'input', function () {
			var pc = parseInt( els.postcode.value.replace( /\D/g, '' ), 10 );
			if ( isNaN( pc ) ) {
				return;
			}
			var guess = stateFromPostcode( pc );
			if ( guess && ! els.stateSel.value ) {
				els.stateSel.value = guess;
			}
		} );
	}

	function stateFromPostcode( pc ) {
		var ranges = [
			[ 1000, 2599, 'NSW' ], [ 2600, 2618, 'ACT' ], [ 2619, 2899, 'NSW' ],
			[ 2900, 2920, 'ACT' ], [ 2921, 2999, 'NSW' ], [ 3000, 3999, 'VIC' ],
			[ 4000, 4999, 'QLD' ], [ 5000, 5799, 'SA' ], [ 6000, 6797, 'WA' ],
			[ 7000, 7799, 'TAS' ], [ 800, 899, 'NT' ]
		];
		for ( var i = 0; i < ranges.length; i++ ) {
			if ( pc >= ranges[i][0] && pc <= ranges[i][1] ) {
				return ranges[i][2];
			}
		}
		return '';
	}

	function collectReview() {
		var fields = { usage: {} };
		var data = new FormData( els.reviewForm );

		data.forEach( function ( value, key ) {
			var match = key.match( /^usage\[(.+)\]$/ );
			if ( match ) {
				fields.usage[ match[1] ] = value;
				return;
			}
			fields[ key ] = value;
		} );

		// Do not send the other fuel's usage; an old value left in a hidden
		// field would otherwise be costed.
		if ( fields.fuel === 'gas' ) {
			[ 'total_kwh', 'peak_kwh', 'shoulder_kwh', 'offpeak_kwh', 'controlled_load_kwh', 'solar_export_kwh', 'demand_kw' ]
				.forEach( function ( key ) {
					fields.usage[ key ] = '';
				} );
		} else {
			fields.usage.gas_mj = '';
		}

		return fields;
	}

	if ( els.reviewForm ) {
		els.reviewForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearAlert();

			var button = els.reviewForm.querySelector( '[type="submit"]' );
			if ( button ) {
				button.disabled = true;
			}

			request( '/compare', { token: state.token, fields: collectReview() }, false )
				.then( function ( data ) {
					if ( button ) {
						button.disabled = false;
					}
					renderResults( data );
					showPanel( 'results' );
				} ).catch( function ( err ) {
					if ( button ) {
						button.disabled = false;
					}
					showAlert( err.message );
				} );
		} );
	}

	/* --- step 3: results ------------------------------------------------- */

	function renderResults( data ) {
		state.results = data.results || [];
		state.currentAnnual = data.current_annual;
		state.token = data.token || state.token;

		els.summary.innerHTML = '';

		function stat( label, value ) {
			if ( ! value ) {
				return;
			}
			var box = el( 'div', 'aec-stat' );
			box.appendChild( el( 'span', 'aec-stat__label', label ) );
			box.appendChild( el( 'span', 'aec-stat__value', value ) );
			els.summary.appendChild( box );
		}

		stat( S.yourBill, data.current_bill );
		stat( S.perYearNow, data.current_annual );
		if ( state.results.length ) {
			stat( S.cheapest, state.results[0].annual_cost );
			if ( state.results[0].annual_saving_raw !== null && state.results[0].annual_saving_raw > 0 ) {
				stat( S.saving, state.results[0].annual_saving );
			}
		}

		if ( data.notes && data.notes.length ) {
			els.resultNotes.innerHTML = '';
			var list = el( 'ul' );
			data.notes.forEach( function ( note ) {
				list.appendChild( el( 'li', null, note ) );
			} );
			els.resultNotes.appendChild( list );
			els.resultNotes.hidden = false;
		} else {
			els.resultNotes.hidden = true;
		}

		els.results.innerHTML = '';

		if ( ! state.results.length ) {
			els.results.appendChild( el( 'p', 'aec-notice aec-notice--warn', S.noResults ) );
			return;
		}

		state.results.forEach( function ( row, index ) {
			els.results.appendChild( buildCard( row, index === 0 ) );
		} );
	}

	function buildCard( row, isBest ) {
		var card = el( 'div', 'aec-card' + ( isBest ? ' is-best' : '' ) );

		if ( isBest ) {
			card.appendChild( el( 'span', 'aec-card__ribbon', S.bestValue ) );
		}

		var head = el( 'div', 'aec-card__head' );

		var id = el( 'div', 'aec-card__id' );
		if ( row.retailer_logo ) {
			var logo = document.createElement( 'img' );
			logo.className = 'aec-card__logo';
			logo.src = row.retailer_logo;
			logo.alt = row.retailer_name || '';
			logo.loading = 'lazy';
			id.appendChild( logo );
		}
		var idText = el( 'div' );
		idText.appendChild( el( 'p', 'aec-card__retailer', row.retailer_name ) );
		idText.appendChild( el( 'p', 'aec-card__plan', row.plan_name ) );
		id.appendChild( idText );
		head.appendChild( id );

		var price = el( 'div', 'aec-card__price' );
		price.appendChild( el( 'span', 'aec-card__cost', row.annual_cost ) );
		price.appendChild( el( 'span', 'aec-card__per', S.perYear ) );

		if ( row.annual_saving_raw !== null && row.annual_saving_raw !== undefined ) {
			var positive = row.annual_saving_raw > 0;
			var badge = el(
				'span',
				'aec-card__saving' + ( positive ? '' : ' is-negative' ),
				( positive ? S.saving + ' ' : S.costsMore + ' ' ) + stripSign( row.annual_saving )
			);
			price.appendChild( badge );
		}
		head.appendChild( price );
		card.appendChild( head );

		var tags = el( 'ul', 'aec-tags' );
		function tag( label, extra ) {
			if ( ! label ) {
				return;
			}
			tags.appendChild( el( 'li', 'aec-tag' + ( extra ? ' ' + extra : '' ), label ) );
		}

		tag( tariffLabel( row.tariff_type ) );
		if ( row.green_percent > 0 ) {
			tag( fmt( S.greenPower, row.green_percent ) );
		}
		tag( row.contract_months > 0 ? fmt( S.monthTerm, row.contract_months ) : S.noFixedTerm );
		if ( row.exit_fee ) {
			tag( fmt( S.exitFee, row.exit_fee ) );
		}
		if ( row.signup_credit ) {
			tag( fmt( S.credit, row.signup_credit ) );
		}
		if ( row.conditional_discount > 0 ) {
			tag( row.conditional_discount + '% ' + ( row.conditional_label || '' ) );
		}
		if ( row.estimated ) {
			tag( S.estimate, 'aec-tag--estimate' );
		}
		if ( tags.children.length ) {
			card.appendChild( tags );
		}

		if ( row.notes ) {
			card.appendChild( el( 'p', 'aec-card__notes', row.notes ) );
		}

		if ( row.flags && row.flags.length ) {
			var flagList = el( 'ul', 'aec-card__flags' );
			row.flags.forEach( function ( flag ) {
				flagList.appendChild( el( 'li', null, flag.label ) );
			} );
			card.appendChild( flagList );
		}

		if ( row.lines && row.lines.length ) {
			var details = el( 'details', 'aec-details' );
			details.appendChild( el( 'summary', null, S.details ) );

			var table = el( 'table', 'aec-breakdown' );
			var tbody = el( 'tbody' );
			row.lines.forEach( function ( line ) {
				var tr = el( 'tr' );
				var th = el( 'th' );
				if ( line.label ) {
					th.appendChild( el( 'span', null, line.label ) );
				}
				if ( line.detail ) {
					th.appendChild( el( 'span', 'aec-breakdown__detail', line.detail ) );
				}
				tr.appendChild( th );
				tr.appendChild( el( 'td', null, line.amount ) );
				tbody.appendChild( tr );
			} );

			var total = el( 'tr' );
			total.appendChild( el( 'th', null, S.annualCost ) );
			total.appendChild( el( 'td', null, row.annual_cost ) );
			tbody.appendChild( total );

			if ( row.conditional_discount > 0 ) {
				var cond = el( 'tr' );
				cond.appendChild(
					el( 'th', null, fmt( S.withCond, row.conditional_discount ) )
				);
				cond.appendChild( el( 'td', null, row.annual_cost_conditional ) );
				tbody.appendChild( cond );
			}

			table.appendChild( tbody );
			details.appendChild( table );
			card.appendChild( details );
		}

		var foot = el( 'div', 'aec-card__foot' );
		var choose = el( 'button', 'aec-btn aec-btn--primary', S.choose );
		choose.type = 'button';
		choose.addEventListener( 'click', function () {
			selectPlan( row );
		} );
		foot.appendChild( choose );

		if ( row.plan_url ) {
			var link = document.createElement( 'a' );
			link.className = 'aec-btn aec-btn--ghost';
			link.href = row.plan_url;
			link.target = '_blank';
			link.rel = 'noopener nofollow';
			link.textContent = S.planDetails;
			foot.appendChild( link );
		}

		card.appendChild( foot );

		return card;
	}

	function stripSign( value ) {
		return text( value ).replace( /^-/, '' );
	}

	function tariffLabel( type ) {
		var map = S.tariffs || {};
		return map[ type ] || '';
	}

	function selectPlan( row ) {
		state.selected = row;
		if ( els.leadIntro ) {
			var line = fmt( S.youPicked, row.retailer_name, row.plan_name );
			if ( row.annual_saving_raw !== null && row.annual_saving_raw > 0 ) {
				line += ' ' + fmt( S.savingLine, stripSign( row.annual_saving ) );
			}
			line += ' ' + S.leadPrompt;
			els.leadIntro.textContent = line;
		}
		showPanel( 'lead' );
	}

	/* --- step 4: lead ---------------------------------------------------- */

	if ( els.leadForm ) {
		els.leadForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearAlert();

			if ( ! els.leadForm.checkValidity() ) {
				els.leadForm.reportValidity();
				return;
			}

			var data = new FormData( els.leadForm );
			var payload = {
				token: state.token,
				name: data.get( 'name' ),
				email: data.get( 'email' ),
				phone: data.get( 'phone' ),
				preferred_contact: data.get( 'preferred_contact' ),
				message: data.get( 'message' ),
				consent: data.get( 'consent' ) ? 1 : 0,
				plan_id: state.selected ? state.selected.plan_id : 0,
				estimated_saving: state.selected ? state.selected.annual_saving_raw : null
			};

			var button = els.leadForm.querySelector( '[type="submit"]' );
			if ( button ) {
				button.disabled = true;
				button.textContent = S.sending;
			}

			request( '/lead', payload, false ).then( function ( response ) {
				if ( els.doneMessage ) {
					els.doneMessage.textContent = response.message || '';
				}
				showPanel( 'done' );
			} ).catch( function ( err ) {
				if ( button ) {
					button.disabled = false;
					button.textContent = S.sendDetails;
				}
				showAlert( err.message );
			} );
		} );
	}

	/* --- navigation ------------------------------------------------------ */

	app.addEventListener( 'click', function ( e ) {
		var target = e.target.closest( '[data-goto]' );
		if ( ! target ) {
			return;
		}
		clearAlert();
		showPanel( target.dataset.goto );
	} );
} )();
