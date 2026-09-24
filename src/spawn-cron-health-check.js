( function () {
	'use strict';

	var data = window.spcrData || {};
	var i18n = data.i18n || {};
	var runButton = document.getElementById( 'spcr-run-test' );
	var result = document.getElementById( 'spcr-result' );
	var steps = document.querySelectorAll( '#spcr-stepper .spcr-step' );
	var clearLock = document.getElementById( 'spcr-clear-lock' );
	var pollTimer = null;

	var CHECK_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m5 13 4 4L19 7"/></svg>';
	var X_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
	var CHEV_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>';

	function t( key ) {
		return i18n[ key ] || key;
	}

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return str.replace( /%(\d\$)?s/g, function () {
			return String( args.shift() );
		} );
	}

	function post( action, onDone ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( '_ajax_nonce', data.nonce );
		fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( r ) { return r.json(); } )
			.then( onDone )
			.catch( function () {
				finish( { status: 'failed', reason: 'start', startFailed: true, message: t( 'requestFailed' ) }, t( 'requestFailed' ) );
			} );
	}

	function stepEl( key ) {
		for ( var i = 0; i < steps.length; i++ ) {
			if ( steps[ i ].getAttribute( 'data-step' ) === key ) {
				return steps[ i ];
			}
		}
		return null;
	}

	function setStep( key, state, text ) {
		var el = stepEl( key );
		if ( ! el ) { return; }
		el.classList.remove( 'is-active', 'is-done', 'is-failed' );
		if ( 'active' === state ) { el.classList.add( 'is-active' ); }
		if ( 'done' === state ) { el.classList.add( 'is-done' ); }
		if ( 'failed' === state ) { el.classList.add( 'is-failed' ); }
		var status = el.querySelector( '.spcr-step-status' );
		if ( status ) { status.textContent = text || ''; }
	}

	function stepState( key ) {
		var el = stepEl( key );
		if ( el && el.classList.contains( 'is-done' ) ) { return 'passed'; }
		if ( el && el.classList.contains( 'is-failed' ) ) { return 'failed'; }
		return 'skipped';
	}

	function resetSteps() {
		steps.forEach( function ( step ) {
			step.classList.remove( 'is-done', 'is-active', 'is-failed' );
			var status = step.querySelector( '.spcr-step-status' );
			if ( status ) { status.textContent = ''; }
		} );
	}

	function setRunning( running ) {
		if ( runButton ) {
			runButton.disabled = running;
		}
	}

	function esc( s ) {
		var div = document.createElement( 'div' );
		div.textContent = s == null ? '' : String( s );
		return div.innerHTML;
	}

	// Wrap technical terms in <code> on already-escaped text.
	function codeTags( html ) {
		return html.replace( /(DISABLE_WP_CRON|ALTERNATE_WP_CRON|wp-cron\.php|wp-config\.php|admin-ajax\.php|wp_options|spawn_cron\(\))/g, '<code>$1</code>' );
	}

	// fmt() that escapes the template and inserts each %s argument inside <em>.
	function fmtEm( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return esc( str ).replace( /%(\d\$)?s/g, function () {
			return '<em>' + esc( args.shift() ) + '</em>';
		} );
	}

	function para( text ) {
		return '<p>' + codeTags( esc( text ) ) + '</p>';
	}

	function formatDate( unixSeconds ) {
		var opts = { dateStyle: 'medium', timeStyle: 'short' };
		try {
			return new Intl.DateTimeFormat( undefined, {
				dateStyle: 'medium',
				timeStyle: 'short',
				timeZone: data.timezone || undefined
			} ).format( unixSeconds * 1000 );
		} catch ( e ) {
			return new Intl.DateTimeFormat( undefined, opts ).format( unixSeconds * 1000 );
		}
	}

	// Seconds -> "1 day, 23 hours" using the two largest units.
	function formatDuration( seconds ) {
		var mins = Math.max( 0, Math.round( Number( seconds ) / 60 ) );
		var days = Math.floor( mins / 1440 );
		var hours = Math.floor( ( mins - days * 1440 ) / 60 );
		mins = mins - days * 1440 - hours * 60;
		var units = [];
		if ( days ) { units.push( fmt( t( 1 === days ? 'dayOne' : 'dayMany' ), days ) ); }
		if ( hours ) { units.push( fmt( t( 1 === hours ? 'hourOne' : 'hourMany' ), hours ) ); }
		if ( mins && units.length < 2 ) { units.push( fmt( t( 1 === mins ? 'minuteOne' : 'minuteMany' ), mins ) ); }
		if ( ! units.length ) { units.push( fmt( t( 'minuteMany' ), 0 ) ); }
		return units.slice( 0, 2 ).join( t( 'durJoin' ) );
	}

	function firedText( test ) {
		var dur = test.duration != null ? Number( test.duration ).toFixed( 1 ) : '0.0';
		if ( test.source ) {
			return fmt( t( 'firedIn' ), dur, test.source );
		}
		return fmt( t( 'firedInNoSource' ), dur );
	}

	// Gray "What this usually means" / "Things to check" box.
	function why( meansHtml, checks ) {
		var html = '<div class="spcr-why"><h4>' + esc( t( 'whyMeans' ) ) + '</h4><p>' + meansHtml + '</p>';
		if ( checks && checks.length ) {
			html += '<h4>' + esc( t( 'whyChecks' ) ) + '</h4><ul>';
			checks.forEach( function ( c ) {
				html += '<li>' + codeTags( esc( t( c ) ) ) + '</li>';
			} );
			html += '</ul>';
		}
		return html + '</div>';
	}

	function overdueTable( rows ) {
		var html = '<table class="spcr-report-table"><thead><tr>' +
			'<th>' + esc( t( 'colEvent' ) ) + '</th>' +
			'<th>' + esc( t( 'colScheduled' ) ) + '</th>' +
			'<th>' + esc( t( 'colOverdue' ) ) + '</th>' +
			'</tr></thead><tbody>';
		rows.forEach( function ( r ) {
			html += '<tr><td><code>' + esc( r.hook ) + '</code></td>' +
				'<td>' + esc( formatDate( Number( r.timestamp ) ) ) + '</td>' +
				'<td class="spcr-late">' + esc( formatDuration( Number( r.overdue_by ) ) ) + '</td></tr>';
		} );
		return html + '</tbody></table>';
	}

	// One report row per step: { title, state: 'passed'|'failed'|'skipped', detail }.
	function reportRows( test, errorMessage ) {
		var rows = [];
		var spawn = test ? test.spawn : null;
		var startFailed = ! test || test.startFailed;
		var grace = data.grace || 30;
		var state, detail;

		// Step 1: enabled.
		state = stepState( 'enabled' );
		if ( 'failed' === state ) {
			detail = para( t( 'r1FailDetail' ) ) +
				why( codeTags( esc( t( 'r1Means' ) ) ), [ 'r1Check1', 'r1Check2', 'r1Check3' ] );
			rows.push( { title: t( 'r1Fail' ), state: state, detail: detail } );
		} else {
			detail = para( ( test && 'false' === test.disable_const ) ? t( 'r1False' ) : t( 'r1Undefined' ) );
			rows.push( { title: t( 'r1Pass' ), state: state, detail: detail } );
		}

		// Step 2: scheduled.
		state = stepState( 'scheduled' );
		if ( 'failed' === state ) {
			if ( startFailed ) {
				detail = '<p>' + codeTags( fmtEm( t( 'r2StartDetail' ), errorMessage || '' ) ) + '</p>' +
					why( codeTags( esc( t( 'r2StartMeans' ) ) ), [ 'r2StartCheck1', 'r2StartCheck2', 'r2StartCheck3' ] );
			} else {
				detail = '<p>' + codeTags( fmtEm( t( 'r2FailDetail' ), test.message || '' ) ) + '</p>' +
					why( codeTags( esc( t( 'r2Means' ) ) ), [ 'r2Check1', 'r2Check2', 'r2Check3' ] );
			}
			rows.push( { title: t( 'r2Fail' ), state: state, detail: detail } );
		} else if ( 'passed' === state ) {
			rows.push( { title: t( 'r2Pass' ), state: state, detail: para( t( 'r2PassDetail' ) ) } );
		} else {
			rows.push( { title: t( 'r2Skip' ), state: 'skipped', detail: '' } );
		}

		// Step 3: spawning.
		state = stepState( 'spawning' );
		if ( 'failed' === state ) {
			if ( spawn && spawn.error ) {
				detail = '<p>' + codeTags( fmtEm( t( 'r3ErrDetail' ), spawn.error ) ) + '</p>' +
					why( codeTags( esc( t( 'r3ErrMeans' ) ) ), [ 'r3ErrCheck1', 'r3ErrCheck2', 'r3ErrCheck3', 'r3ErrCheck4' ] );
			} else if ( spawn && spawn.code >= 400 ) {
				if ( 500 === Number( spawn.code ) ) {
					detail = '<p>' + fmtEm( t( 'r3HttpDetail' ), spawn.code ) + '</p>' +
						why( codeTags( esc( t( 'r3Http500Means' ) ) ), [ 'r3Http500Check1', 'r3Http500Check2', 'r3Http500Check3' ] );
				} else {
					detail = '<p>' + fmtEm( t( 'r3HttpDetail' ), spawn.code ) + '</p>' +
						why( codeTags( esc( t( 'r3HttpMeans' ) ) ), [ 'r3HttpCheck1', 'r3HttpCheck2', 'r3HttpCheck3' ] );
				}
			} else {
				detail = '<p>' + esc( ( test && test.message ) || t( 'failed' ) ) + '</p>';
			}
			rows.push( { title: t( 'r3Fail' ), state: state, detail: detail } );
		} else if ( 'passed' === state ) {
			if ( spawn && spawn.alternate ) {
				detail = para( t( 'r3Alternate' ) );
			} else if ( spawn && spawn.code ) {
				detail = '<p>' + codeTags( esc( fmt( t( 'r3PassDetail' ), spawn.code ) ) ) + '</p>';
			} else {
				detail = para( t( 'r3PassDetailNoCode' ) );
			}
			rows.push( { title: t( 'r3Pass' ), state: state, detail: detail } );
		} else {
			rows.push( { title: t( 'r3Skip' ), state: 'skipped', detail: '' } );
		}

		// Step 4: waiting.
		state = stepState( 'waiting' );
		if ( 'passed' === state ) {
			var dur = test && test.duration != null ? Number( test.duration ).toFixed( 1 ) : '0.0';
			detail = para(
				test && test.source
					? fmt( t( 'r4PassDetail' ), dur, test.source )
					: fmt( t( 'r4PassDetailNoSource' ), dur )
			);
			rows.push( { title: t( 'r4Pass' ), state: state, detail: detail } );
		} else if ( 'failed' === state ) {
			if ( spawn && spawn.alternate ) {
				detail = para( fmt( t( 'r4FailDetail' ), data.timeout || 30 ) ) +
					why( codeTags( esc( t( 'r4AltMeans' ) ) ), [ 'r4AltCheck1' ] );
			} else {
				detail = '<p>' + codeTags( fmtEm( t( 'r4FailDetail' ), data.timeout || 30 ) ) + '</p>' +
					why( codeTags( esc( t( 'r4Means' ) ) ), [ 'r4Check1', 'r4Check2', 'r4Check3' ] );
			}
			rows.push( { title: t( 'r4Fail' ), state: state, detail: detail } );
		} else {
			rows.push( { title: t( 'r4Skip' ), state: 'skipped', detail: '' } );
		}

		var otherFailed = rows.some( function ( r ) { return 'failed' === r.state; } );

		// Step 5: overdue — always reported unless the run never started.
		state = stepState( 'overdue' );
		if ( ! test || startFailed || 'skipped' === state ) {
			rows.push( { title: t( 'r5Skip' ), state: 'skipped', detail: '' } );
			return rows;
		}
		var od = test.overdue != null ? Number( test.overdue ) : 0;
		if ( od > 0 ) {
			var events = Array.isArray( test.overdue_events ) ? test.overdue_events : [];
			detail = '<p>' + fmtEm( t( 'r5FailDetail' ), grace ) + '</p>';
			if ( events.length ) {
				detail += overdueTable( events );
				if ( od > events.length ) {
					detail += '<p>' + fmtEm( t( 'r5More' ), od - events.length ) + '</p>';
				}
			}
			detail += why(
				codeTags( esc( t( 'r5Means' ) + ( otherFailed ? t( 'r5MeansFailed' ) : t( 'r5MeansPassed' ) ) ) ),
				otherFailed ? [] : [ 'r5Check1', 'r5Check2', 'r5Check3' ]
			);
			rows.push( {
				title: 1 === od ? t( 'r5FailOne' ) : fmt( t( 'r5FailMany' ), od ),
				state: 'failed',
				detail: detail
			} );
		} else {
			var total = test.total_events != null ? Number( test.total_events ) : 0;
			detail = para(
				1 === total
					? fmt( t( 'r5PassDetailOne' ), grace )
					: fmt( t( 'r5PassDetail' ), total, grace )
			);
			rows.push( { title: t( 'r5Pass' ), state: 'passed', detail: detail } );
		}
		return rows;
	}

	function render( test, errorMessage ) {
		if ( ! result ) { return; }
		if ( ! test || ( 'passed' !== test.status && 'failed' !== test.status ) ) {
			result.innerHTML = '';
			return;
		}
		var failed = 'failed' === test.status;
		var rows = reportRows( test, errorMessage );
		var html = '<div class="spcr-report spcr-report-' + ( failed ? 'failed' : 'passed' ) + '">' +
			'<div class="spcr-report-banner">' +
				'<span class="spcr-mark">' + ( failed ? X_SVG : CHECK_SVG ) + '</span>' +
				'<span class="spcr-report-title">' + esc( t( failed ? 'bannerFailed' : 'bannerPassed' ) ) + '</span>' +
				'<span class="spcr-report-date">' + esc( formatDate( Date.now() / 1000 ) ) + '</span>' +
			'</div>' +
			'<div class="spcr-report-list">';
		var uid = 0;
		rows.forEach( function ( row ) {
			var skipped = 'skipped' === row.state;
			var open = 'failed' === row.state;
			var id = 'spcr-report-panel-' + ( ++uid );
			html += '<div class="spcr-report-item is-' + row.state + ( open ? ' is-open' : '' ) + '">' +
				'<button type="button" class="spcr-report-row" aria-expanded="' + ( open ? 'true' : 'false' ) + '"' +
					( skipped ? ' disabled' : ' aria-controls="' + id + '"' ) + '>' +
				'<span class="spcr-mark">' + ( 'passed' === row.state ? CHECK_SVG : ( 'failed' === row.state ? X_SVG : '' ) ) + '</span>' +
				'<span class="spcr-report-name">' + esc( row.title ) +
					( skipped ? '<span class="spcr-not-run">' + esc( t( 'notRun' ) ) + '</span>' : '' ) + '</span>' +
				( skipped ? '' : '<span class="spcr-chevron">' + CHEV_SVG + '</span>' ) +
				'</button>' +
				( skipped ? '' : '<div class="spcr-report-panel" id="' + id + '"><div class="spcr-report-inner"><div class="spcr-report-body">' + row.detail + '</div></div></div>' ) +
				'</div>';
		} );
		result.innerHTML = html + '</div></div>';

		result.querySelectorAll( '.spcr-report-item' ).forEach( function ( item ) {
			var btn = item.querySelector( '.spcr-report-row' );
			if ( ! btn || btn.disabled ) { return; }
			btn.addEventListener( 'click', function () {
				var open = item.classList.toggle( 'is-open' );
				btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		} );
	}

	function finish( test, errorMessage ) {
		stopPolling();
		var waiting = stepEl( 'waiting' );
		var wasActive = waiting && waiting.classList.contains( 'is-active' );
		if ( test && ( 'passed' === test.status || null != test.fired ) ) {
			setStep( 'waiting', 'done', firedText( test ) );
		} else if ( wasActive && test && 'failed' === test.status ) {
			setStep( 'waiting', 'failed', test.message || t( 'failed' ) );
		}
		if ( test && test.overdue != null ) {
			var od = Number( test.overdue );
			setStep( 'overdue', od > 0 ? 'failed' : 'done', od > 0 ? fmt( t( 'overdueSome' ), od ) : t( 'overdueNone' ) );
		}
		render( test, errorMessage );
		setRunning( false );
	}

	function stopPolling() {
		if ( pollTimer ) {
			clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	function poll( elapsed ) {
		var limit = ( data.timeout || 30 ) + 5;
		if ( elapsed > limit ) {
			finish( { status: 'failed', reason: 'timeout', message: t( 'timeout' ) } );
			return;
		}
		post( 'spcr_test_status', function ( res ) {
			var test = res && res.success ? res.data : null;
			if ( test && test.status && test.status !== 'running' ) {
				finish( test );
				return;
			}
			pollTimer = setTimeout( function () { poll( elapsed + 1 ); }, 1000 );
		} );
	}

	// Ordered per-step outcomes in stepper order; steps 1-4 are `core` and stop
	// on the first failure, while the overdue item always runs and is reported
	// once the run is finished (it is appended only when the test has a verdict).
	function stepResults( test, errorMessage ) {
		var list = [];
		var reason = test ? ( test.reason || '' ) : '';
		var stopped = false;

		if ( 'disabled' === reason ) {
			list.push( { step: 'enabled', state: 'failed', text: t( 'disabledTrue' ), stop: true, core: true } );
			stopped = true;
		} else {
			list.push( {
				step: 'enabled',
				state: 'done',
				text: ( test && 'false' === test.disable_const ) ? t( 'disabledFalse' ) : t( 'disabledUndefined' ),
				core: true
			} );
		}

		if ( ! test ) {
			list.push( { step: 'scheduled', state: 'failed', text: errorMessage || t( 'couldNotStart' ), stop: true, core: true } );
			return list;
		}

		if ( ! stopped ) {
			if ( 'schedule' === reason ) {
				list.push( { step: 'scheduled', state: 'failed', text: test.message || t( 'scheduleFailed' ), stop: true, core: true } );
				stopped = true;
			} else {
				list.push( { step: 'scheduled', state: 'done', text: t( 'scheduledOk' ), core: true } );
			}
		}

		if ( ! stopped ) {
			var spawn = test.spawn;
			if ( spawn && spawn.alternate ) {
				list.push( { step: 'spawning', state: 'done', text: t( 'spawnAlternate' ), core: true } );
			} else if ( spawn && spawn.error ) {
				var errorText = ( 'spawn' === reason && test.message ) ? test.message : String( spawn.error );
				list.push( { step: 'spawning', state: 'failed', text: errorText, stop: true, core: true } );
				stopped = true;
			} else if ( spawn && spawn.code >= 400 ) {
				var httpText = ( 'spawn' === reason && test.message ) ? test.message : fmt( t( 'spawnHttpError' ), spawn.code );
				list.push( { step: 'spawning', state: 'failed', text: httpText, stop: true, core: true } );
				stopped = true;
			} else {
				list.push( { step: 'spawning', state: 'done', text: spawn && spawn.code ? fmt( t( 'spawnOk' ), spawn.code ) : t( 'spawnSent' ), core: true } );
			}
		}

		if ( ! stopped ) {
			if ( 'passed' === test.status || null != test.fired ) {
				list.push( { step: 'waiting', state: 'done', text: firedText( test ), core: true } );
			} else {
				list.push( { step: 'waiting', state: 'active', text: fmt( t( 'waitingUpTo' ), data.timeout || 30 ), core: true } );
			}
		}

		// Overdue always runs for a finished test, even after a core stop.
		if ( 'running' !== test.status ) {
			var od = test.overdue != null ? Number( test.overdue ) : 0;
			list.push( {
				step: 'overdue',
				state: od > 0 ? 'failed' : 'done',
				text: od > 0 ? fmt( t( 'overdueSome' ), od ) : t( 'overdueNone' )
			} );
		}
		return list;
	}

	function playSteps( list, i, after ) {
		if ( i >= list.length ) {
			after();
			return;
		}
		var item = list[ i ];
		setStep( item.step, item.state, item.text );
		if ( item.stop ) {
			// A core failure stops the remaining core steps, but the overdue
			// step (non-core) still plays.
			var j = i + 1;
			while ( j < list.length && list[ j ].core ) { j++; }
			if ( j < list.length ) {
				setTimeout( function () { playSteps( list, j, after ); }, 400 );
				return;
			}
			after();
			return;
		}
		setTimeout( function () { playSteps( list, i + 1, after ); }, 400 );
	}

	if ( runButton ) {
		runButton.addEventListener( 'click', function () {
			setRunning( true );
			resetSteps();
			render( null );
			setStep( 'enabled', 'active', '' );

			post( 'spcr_start_test', function ( res ) {
				var test = res && res.success ? res.data : null;
				var errorMessage = ( res && res.data && res.data.message ) || t( 'couldNotStart' );
				var list = stepResults( test, errorMessage );
				playSteps( list, 0, function () {
					if ( ! test || 'passed' === test.status || 'failed' === test.status ) {
						finish( test || { status: 'failed', reason: 'start', startFailed: true, message: errorMessage }, errorMessage );
						return;
					}
					// Still running: keep waiting active, overdue stays idle, and poll.
					setStep( 'waiting', 'active', fmt( t( 'waitingUpTo' ), data.timeout || 30 ) );
					if ( data.altCron && data.homeUrl ) {
						fetch( data.homeUrl, { mode: 'no-cors', cache: 'no-store' } ).catch( function () {} );
					}
					poll( 0 );
				} );
			} );
		} );
	}

	if ( clearLock ) {
		clearLock.addEventListener( 'click', function () {
			clearLock.disabled = true;
			post( 'spcr_clear_lock', function () {
				window.location.reload();
			} );
		} );
	}

} )();
