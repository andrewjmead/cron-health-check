( function () {
	'use strict';

	var data = window.chcData || {};
	var i18n = data.i18n || {};
	var runButton = document.getElementById( 'chc-run-test' );
	var result = document.getElementById( 'chc-result' );
	var steps = document.querySelectorAll( '#chc-stepper .chc-step' );
	var clearLock = document.getElementById( 'chc-clear-lock' );
	var diagnosticsButton = document.getElementById( 'chc-show-diagnostics' );
	var diagnosticsCard = document.getElementById( 'chc-diagnostics-card' );
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
		var status = el.querySelector( '.chc-step-status' );
		if ( status ) { status.textContent = text || ''; }
	}

	function resetSteps() {
		steps.forEach( function ( step ) {
			step.classList.remove( 'is-done', 'is-active', 'is-failed' );
			var status = step.querySelector( '.chc-step-status' );
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
		var failed = document.querySelector( '#chc-stepper .chc-step.is-failed' );
		if ( failed ) {
			var label = failed.querySelector( '.chc-step-label' );
			var status = failed.querySelector( '.chc-step-status' );
			var text = ( label ? label.textContent : '' ) + ( status && status.textContent ? ': ' + status.textContent : '' );
			if ( text ) { return text; }
		}
		return ( test && test.message ) || t( 'neverRan' );
	}

	function render( test ) {
		if ( ! result ) { return; }
		if ( ! test || ( test.status !== 'passed' && test.status !== 'failed' ) ) {
			result.innerHTML = '';
			return;
		}
		var passed = test.status === 'passed';
		var icon = passed
			? '<path class="chc-result-mark" d="M20 6 9 17l-5-5"/>'
			: '<path class="chc-result-mark" d="M18 6 6 18"/><path class="chc-result-mark" d="m6 6 12 12"/>';
		result.innerHTML =
			'<div class="chc-result-card chc-status-' + ( passed ? 'passed' : 'failed' ) + '">' +
				'<span class="chc-result-badge"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + icon + '</svg></span>' +
				'<strong class="chc-result-status">' + esc( t( passed ? 'passedTitle' : 'failedTitle' ) ) + '</strong>' +
				( passed ? '' : '<p class="chc-result-message">' + esc( failureDetail( test ) ) + '</p>' ) +
			'</div>';
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
		refreshSections();
	}

	function refreshSections() {
		fetch( window.location.href, { credentials: 'same-origin' } )
			.then( function ( r ) { return r.text(); } )
			.then( function ( html ) {
				var doc = new DOMParser().parseFromString( html, 'text/html' );
				var newDiag = doc.querySelector( '.chc-diagnostics' );
				var oldDiag = document.querySelector( '.chc-diagnostics' );
				if ( newDiag && oldDiag ) {
					oldDiag.innerHTML = newDiag.innerHTML;
				}
			} )
			.catch( function () {} );
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
		post( 'chc_test_status', function ( res ) {
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
				text: fmt( t( 'overdueSome' ), overdue, Math.round( ( Number( test.overdue_oldest ) || 0 ) / 60 ) ),
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

			post( 'chc_start_test', function ( res ) {
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
			post( 'chc_clear_lock', function () {
				window.location.reload();
			} );
		} );
	}

	if ( diagnosticsButton && diagnosticsCard ) {
		diagnosticsButton.addEventListener( 'click', function () {
			diagnosticsCard.removeAttribute( 'hidden' );
			diagnosticsButton.remove();
		} );
	}
} )();
