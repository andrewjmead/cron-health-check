( function () {
	'use strict';

	var data = window.chcData || {};
	var i18n = data.i18n || {};
	var runButton = document.getElementById( 'chc-run-test' );
	var result = document.getElementById( 'chc-result' );
	var steps = document.querySelectorAll( '#chc-stepper .chc-step' );
	var clearLock = document.getElementById( 'chc-clear-lock' );
	var pollTimer = null;
	var activeStep = null;

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

	function setStep( activeKey ) {
		var keys = [ 'scheduled', 'spawning', 'waiting' ];
		var activeIndex = keys.indexOf( activeKey );
		activeStep = activeKey;
		steps.forEach( function ( step ) {
			var idx = keys.indexOf( step.getAttribute( 'data-step' ) );
			step.classList.toggle( 'is-done', idx < activeIndex );
			step.classList.toggle( 'is-active', idx === activeIndex );
		} );
	}

	function finishSteps( status ) {
		steps.forEach( function ( step ) {
			step.classList.remove( 'is-active' );
			if ( status === 'passed' ) {
				step.classList.add( 'is-done' );
			} else if ( status === 'failed' && step.getAttribute( 'data-step' ) === activeStep ) {
				step.classList.add( 'is-failed' );
			}
		} );
		if ( status === 'failed' && ! activeStep ) {
			var last = steps[ steps.length - 1 ];
			if ( last ) { last.classList.add( 'is-failed' ); }
		}
	}

	function resetSteps() {
		steps.forEach( function ( step ) {
			step.classList.remove( 'is-done', 'is-active', 'is-failed' );
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
		if ( status === 'passed' ) { return t( 'passed' ); }
		if ( status === 'failed' ) { return t( 'failed' ); }
		if ( status === 'running' ) { return t( 'running' ); }
		return t( 'unknown' );
	}

	function messageFor( test ) {
		if ( test.message ) { return test.message; }
		if ( test.status === 'passed' ) {
			var dur = test.duration != null ? Number( test.duration ).toFixed( 1 ) : '0.0';
			if ( test.source ) {
				return fmt( t( 'firedIn' ), dur, test.source );
			}
			return fmt( t( 'firedInNoSource' ), dur );
		}
		if ( test.status === 'failed' ) {
			return t( 'neverRan' );
		}
		return t( 'isRunning' );
	}

	function render( test ) {
		if ( ! result ) { return; }
		if ( ! test || test.status === 'none' ) {
			result.innerHTML = '<p class="chc-result-empty">' + esc( t( 'noTest' ) ) + '</p>';
			return;
		}
		var meta = '';
		if ( test.status === 'passed' || test.status === 'failed' ) {
			var overdueCount = test.overdue != null ? Number( test.overdue ) : 0;
			meta += '<div><dt>' + esc( t( 'overdue' ) ) + '</dt><dd class="chc-overdue-count' + ( overdueCount > 0 ? ' chc-fail' : '' ) + '">' + esc( overdueCount ) + '</dd></div>';
		}
		if ( test.duration != null ) {
			meta += '<div><dt>' + esc( t( 'duration' ) ) + '</dt><dd>' + esc( Number( test.duration ).toFixed( 1 ) ) + 's</dd></div>';
		}
		if ( test.source ) {
			meta += '<div><dt>' + esc( t( 'source' ) ) + '</dt><dd>' + esc( test.source ) + '</dd></div>';
		}
		var spawnLine = '';
		if ( test.status === 'failed' && test.spawn ) {
			var detail = '';
			if ( test.spawn.error ) {
				detail = test.spawn.error;
			} else if ( test.spawn.code ) {
				detail = 'HTTP ' + test.spawn.code;
			} else if ( test.spawn.alternate ) {
				detail = 'ALTERNATE_WP_CRON';
			}
			if ( detail ) {
				spawnLine = '<p class="chc-spawn-detail">' + esc( t( 'loopback' ) ) + ': <code>' + esc( detail ) + '</code></p>';
			}
		}
		result.innerHTML =
			'<div class="chc-result-card chc-status-' + esc( test.status ) + '">' +
				'<strong class="chc-result-status">' + esc( statusLabel( test.status ) ) + '</strong>' +
				'<p class="chc-result-message">' + esc( messageFor( test ) ) + '</p>' +
				( meta ? '<dl class="chc-result-meta">' + meta + '</dl>' : '' ) +
				spawnLine +
			'</div>';
	}

	function finish( test ) {
		stopPolling();
		render( test );
		finishSteps( test && test.status ? test.status : 'failed' );
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
			finish( { status: 'failed', message: t( 'timeout' ) } );
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

	if ( runButton ) {
		runButton.addEventListener( 'click', function () {
			setRunning( true );
			resetSteps();
			setStep( 'scheduled' );
			post( 'chc_start_test', function ( res ) {
				var test = res && res.success ? res.data : null;
				if ( ! test ) {
					finish( { status: 'failed', message: ( res && res.data && res.data.message ) || t( 'couldNotStart' ) } );
					return;
				}
				setStep( 'waiting' );
				if ( test.status === 'failed' ) {
					finish( test );
					return;
				}
				if ( test.status === 'passed' ) {
					finish( test );
					return;
				}
				if ( data.altCron && data.homeUrl ) {
					fetch( data.homeUrl, { mode: 'no-cors', cache: 'no-store' } ).catch( function () {} );
				}
				poll( 0 );
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

} )();
