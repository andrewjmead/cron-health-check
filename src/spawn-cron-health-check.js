( function () {
	'use strict';

	var data = window.spcrData || {};
	var i18n = data.i18n || {};
	var runButton = document.getElementById( 'spcr-run-test' );
	var result = document.getElementById( 'spcr-result' );
	var steps = document.querySelectorAll( '#spcr-stepper .spcr-step' );
	var clearLock = document.getElementById( 'spcr-clear-lock' );
	var pollTimer = null;

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
				finish( { status: 'failed', message: t( 'requestFailed' ) } );
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

	function firedText( test ) {
		var dur = test.duration != null ? Number( test.duration ).toFixed( 1 ) : '0.0';
		if ( test.source ) {
			return fmt( t( 'firedIn' ), dur, test.source );
		}
		return fmt( t( 'firedInNoSource' ), dur );
	}

	// The failed step's label + status line, or the test message as a fallback.
	function failureDetail( test ) {
		var failed = document.querySelector( '#spcr-stepper .spcr-step.is-failed' );
		if ( failed ) {
			var label = failed.querySelector( '.spcr-step-label' );
			var status = failed.querySelector( '.spcr-step-status' );
			var text = ( label ? label.textContent : '' ) + ( status && status.textContent ? ': ' + status.textContent : '' );
			if ( text ) { return text; }
		}
		return ( test && test.message ) || t( 'neverRan' );
	}

	// One entry per step: { num, label, state: 'passed'|'failed'|'skipped', text }.
	function stepReport() {
		var rows = [];
		steps.forEach( function ( step, i ) {
			var label = step.querySelector( '.spcr-step-label' );
			var status = step.querySelector( '.spcr-step-status' );
			var state = 'skipped';
			if ( step.classList.contains( 'is-done' ) ) { state = 'passed'; }
			if ( step.classList.contains( 'is-failed' ) ) { state = 'failed'; }
			rows.push( {
				num: i + 1,
				label: label ? label.textContent : '',
				state: state,
				text: status ? status.textContent : ''
			} );
		} );
		return rows;
	}

	function stateLabel( state ) {
		if ( 'passed' === state ) { return t( 'statusPassed' ); }
		if ( 'failed' === state ) { return t( 'statusFailed' ); }
		return t( 'statusSkipped' );
	}

	function reportText( test ) {
		var lines = [
			fmt( t( 'reportHeader' ), data.homeUrl || '', data.wpVersion || '', data.version || '' ),
			t( test.status === 'passed' ? 'passedTitle' : 'failedTitle' ),
			''
		];
		stepReport().forEach( function ( row ) {
			lines.push( row.num + '. ' + row.label + ' - ' + stateLabel( row.state ) );
			if ( row.text ) { lines.push( '   ' + row.text ); }
		} );
		return lines.join( '\n' );
	}

	function reportHtml() {
		var html = '<ol class="spcr-report">';
		stepReport().forEach( function ( row ) {
			html += '<li class="spcr-report-row is-' + row.state + '">' +
				'<span class="spcr-report-state">' + esc( stateLabel( row.state ) ) + '</span>' +
				'<span class="spcr-report-body"><span class="spcr-report-label">' + esc( row.label ) + '</span>' +
				( row.text ? '<span class="spcr-report-text">' + esc( row.text ) + '</span>' : '' ) +
				'</span></li>';
		} );
		return html + '</ol>';
	}

	function copyText( text, onDone ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( function () { onDone( true ); }, function () { onDone( false ); } );
			return;
		}
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		var ok = false;
		try { ok = document.execCommand( 'copy' ); } catch ( e ) { ok = false; }
		document.body.removeChild( ta );
		onDone( ok );
	}

	function render( test ) {
		if ( ! result ) { return; }
		if ( ! test || ( test.status !== 'passed' && test.status !== 'failed' ) ) {
			result.innerHTML = '';
			return;
		}
		var passed = test.status === 'passed';
		var icon = passed
			? '<path class="spcr-result-mark" d="m4 12 5 5L20 6"/>'
			: '<path class="spcr-result-mark" d="M18 6 6 18"/><path class="spcr-result-mark" d="m6 6 12 12"/>';
		var badge = '<span class="spcr-result-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + icon + '</svg></span>';
		var title = '<strong class="spcr-result-status">' + esc( t( passed ? 'passedTitle' : 'failedTitle' ) ) + '</strong>';

		if ( passed ) {
			result.innerHTML = '<div class="spcr-result-card spcr-status-passed">' + badge + title + '</div>';
			return;
		}

		result.innerHTML =
			'<div class="spcr-result-card spcr-status-failed">' +
				'<div class="spcr-result-head">' + badge + title + '</div>' +
				'<p class="spcr-result-message">' + esc( failureDetail( test ) ) + '</p>' +
				'<div class="spcr-result-actions">' +
					'<button type="button" class="button spcr-report-toggle" aria-expanded="false">' + esc( t( 'viewReport' ) ) + '</button>' +
					'<button type="button" class="button spcr-report-copy">' + esc( t( 'copyReport' ) ) + '</button>' +
				'</div>' +
				'<div class="spcr-report-wrap" hidden>' + reportHtml() + '</div>' +
			'</div>';

		var toggle = result.querySelector( '.spcr-report-toggle' );
		var copy = result.querySelector( '.spcr-report-copy' );
		var wrap = result.querySelector( '.spcr-report-wrap' );
		toggle.addEventListener( 'click', function () {
			var open = wrap.hasAttribute( 'hidden' );
			if ( open ) { wrap.removeAttribute( 'hidden' ); } else { wrap.setAttribute( 'hidden', '' ); }
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			toggle.textContent = t( open ? 'hideReport' : 'viewReport' );
		} );
		copy.addEventListener( 'click', function () {
			copyText( reportText( test ), function ( ok ) {
				copy.textContent = t( ok ? 'copied' : 'copyFailed' );
				setTimeout( function () { copy.textContent = t( 'copyReport' ); }, 2000 );
			} );
		} );
	}

	function finish( test ) {
		stopPolling();
		var waiting = stepEl( 'waiting' );
		var wasActive = waiting && waiting.classList.contains( 'is-active' );
		if ( test && test.status === 'passed' ) {
			setStep( 'waiting', 'done', firedText( test ) );
		} else if ( wasActive && test && test.status === 'failed' ) {
			setStep( 'waiting', 'failed', test.message || t( 'failed' ) );
		}
		render( test );
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

	// Build the ordered per-step outcomes from the start response and play them
	// 400ms apart; each item is { step, state, text, stop }.
	function stepResults( test, errorMessage ) {
		var list = [];
		var reason = test ? ( test.reason || '' ) : '';

		if ( 'disabled' === reason ) {
			list.push( { step: 'enabled', state: 'failed', text: t( 'disabledTrue' ), stop: true } );
			return list;
		}
		list.push( {
			step: 'enabled',
			state: 'done',
			text: ( test && 'false' === test.disable_const ) ? t( 'disabledFalse' ) : t( 'disabledUndefined' )
		} );

		if ( ! test ) {
			list.push( { step: 'scheduled', state: 'failed', text: errorMessage || t( 'couldNotStart' ), stop: true } );
			return list;
		}

		var overdue = test.overdue != null ? Number( test.overdue ) : 0;
		if ( overdue > 0 ) {
			list.push( {
				step: 'overdue',
				state: 'failed',
				text: fmt( t( 'overdueSome' ), overdue ),
				stop: true
			} );
			return list;
		}
		list.push( { step: 'overdue', state: 'done', text: t( 'overdueNone' ) } );

		if ( 'schedule' === reason ) {
			list.push( { step: 'scheduled', state: 'failed', text: test.message || t( 'scheduleFailed' ), stop: true } );
			return list;
		}
		list.push( { step: 'scheduled', state: 'done', text: t( 'scheduledOk' ) } );

		var spawn = test.spawn;
		if ( spawn && spawn.alternate ) {
			list.push( { step: 'spawning', state: 'done', text: t( 'spawnAlternate' ) } );
		} else if ( spawn && spawn.error ) {
			var errorText = ( 'spawn' === reason && test.message ) ? test.message : String( spawn.error );
			list.push( { step: 'spawning', state: 'failed', text: errorText, stop: true } );
		} else if ( spawn && spawn.code >= 400 ) {
			var httpText = ( 'spawn' === reason && test.message ) ? test.message : fmt( t( 'spawnHttpError' ), spawn.code );
			list.push( { step: 'spawning', state: 'failed', text: httpText, stop: true } );
		} else {
			list.push( { step: 'spawning', state: 'done', text: spawn && spawn.code ? fmt( t( 'spawnOk' ), spawn.code ) : t( 'spawnSent' ) } );
		}

		if ( 'passed' === test.status ) {
			list.push( { step: 'waiting', state: 'done', text: firedText( test ) } );
		} else {
			list.push( { step: 'waiting', state: 'active', text: fmt( t( 'waitingUpTo' ), data.timeout || 30 ) } );
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
						finish( test || { status: 'failed', reason: 'start', message: errorMessage } );
						return;
					}
					// Still running: keep waiting active and poll.
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
