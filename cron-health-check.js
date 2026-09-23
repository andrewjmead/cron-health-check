( function () {
	'use strict';

	var data = window.chcData || {};
	var runButton = document.getElementById( 'chc-run-test' );
	var result = document.getElementById( 'chc-result' );
	var steps = document.querySelectorAll( '#chc-stepper .chc-step' );
	var toggle = document.getElementById( 'chc-toggle-events' );
	var allEvents = document.getElementById( 'chc-all-events' );
	var clearLock = document.getElementById( 'chc-clear-lock' );
	var pollTimer = null;

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
				render( { status: 'failed', message: 'Request failed. Check your connection and try again.' } );
				setRunning( false );
			} );
	}

	function setStep( activeKey ) {
		var keys = [ 'scheduled', 'spawning', 'waiting' ];
		var activeIndex = keys.indexOf( activeKey );
		steps.forEach( function ( step ) {
			var idx = keys.indexOf( step.getAttribute( 'data-step' ) );
			step.classList.toggle( 'is-done', idx < activeIndex );
			step.classList.toggle( 'is-active', idx === activeIndex );
		} );
	}

	function resetSteps() {
		steps.forEach( function ( step ) {
			step.classList.remove( 'is-done', 'is-active' );
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

	function statusLabel( status ) {
		if ( status === 'passed' ) { return 'Passed'; }
		if ( status === 'failed' ) { return 'Failed'; }
		if ( status === 'running' ) { return 'Running'; }
		return 'Unknown';
	}

	function messageFor( test ) {
		if ( test.message ) { return test.message; }
		if ( test.status === 'passed' ) {
			var src = test.source ? ' via ' + test.source : '';
			var dur = test.duration != null ? test.duration + 's' : '';
			return 'Cron fired in ' + dur + src + '.';
		}
		if ( test.status === 'failed' ) {
			return 'The event was scheduled but never ran.';
		}
		return 'The test is running.';
	}

	function render( test ) {
		if ( ! result ) { return; }
		if ( ! test || test.status === 'none' ) {
			result.innerHTML = '<p class="chc-result-empty">No test has been run yet.</p>';
			return;
		}
		var meta = '';
		if ( test.duration != null ) {
			meta += '<div><dt>Duration</dt><dd>' + esc( test.duration ) + 's</dd></div>';
		}
		if ( test.source ) {
			meta += '<div><dt>Source</dt><dd>' + esc( test.source ) + '</dd></div>';
		}
		result.innerHTML =
			'<div class="chc-result-card chc-status-' + esc( test.status ) + '">' +
				'<strong class="chc-result-status">' + esc( statusLabel( test.status ) ) + '</strong>' +
				'<p class="chc-result-message">' + esc( messageFor( test ) ) + '</p>' +
				( meta ? '<dl class="chc-result-meta">' + meta + '</dl>' : '' ) +
			'</div>';
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
			stopPolling();
			setRunning( false );
			return;
		}
		post( 'chc_test_status', function ( res ) {
			var test = res && res.success ? res.data : null;
			if ( test && test.status && test.status !== 'running' ) {
				render( test );
				setRunning( false );
				return;
			}
			pollTimer = setTimeout( function () { poll( elapsed + 1 ); }, 1000 );
		} );
	}

	if ( runButton ) {
		runButton.addEventListener( 'click', function () {
			setRunning( true );
			resetSteps();
			setStep( 'scheduled' );
			post( 'chc_start_test', function ( res ) {
				var test = res && res.success ? res.data : null;
				if ( ! test ) {
					render( { status: 'failed', message: ( res && res.data && res.data.message ) || 'Could not start the test.' } );
					setRunning( false );
					return;
				}
				setStep( 'waiting' );
				if ( test.status === 'failed' ) {
					render( test );
					setRunning( false );
					return;
				}
				if ( data.altCron && data.homeUrl ) {
					fetch( data.homeUrl, { mode: 'no-cors', cache: 'no-store' } ).catch( function () {} );
				}
				poll( 0 );
			} );
		} );
	}

	if ( toggle && allEvents ) {
		toggle.addEventListener( 'click', function () {
			var open = allEvents.hasAttribute( 'hidden' );
			if ( open ) {
				allEvents.removeAttribute( 'hidden' );
			} else {
				allEvents.setAttribute( 'hidden', '' );
			}
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
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
} )();
