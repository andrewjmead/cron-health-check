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

	var STEPS = [ 'enabled', 'overdue', 'scheduled', 'spawning', 'waiting' ];

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

	function markSteps( doneUpTo, active, failed ) {
		var doneIdx = doneUpTo ? STEPS.indexOf( doneUpTo ) : -1;
		var failIdx = failed ? STEPS.indexOf( failed ) : -1;
		steps.forEach( function ( step ) {
			var idx = STEPS.indexOf( step.getAttribute( 'data-step' ) );
			step.classList.remove( 'is-done', 'is-active', 'is-failed' );
			if ( idx === failIdx ) {
				step.classList.add( 'is-failed' );
			} else if ( idx <= doneIdx || ( failIdx >= 0 && idx < failIdx ) ) {
				step.classList.add( 'is-done' );
			} else if ( step.getAttribute( 'data-step' ) === active ) {
				step.classList.add( 'is-active' );
			}
		} );
	}

	function setActive( key ) {
		var idx = STEPS.indexOf( key );
		markSteps( idx > 0 ? STEPS[ idx - 1 ] : null, key, null );
	}

	function stepForFailure( test ) {
		var r = ( test && test.reason ) || '';
		if ( r === 'disabled' ) { return 'enabled'; }
		if ( r === 'overdue' ) { return 'overdue'; }
		if ( r === 'timeout_loopback_error' || r === 'timeout_http_error' ) { return 'spawning'; }
		if ( r === 'start' ) { return 'scheduled'; }
		return 'waiting'; // timeout_no_fire, timeout_no_request, requestFailed
	}

	// The overdue snapshot is taken up front, but its verdict lands after the
	// event fires; when that is the only failure the later steps still passed.
	function failStepFor( test ) {
		var failed = stepForFailure( test );
		markSteps( failed === 'overdue' && test && test.fired ? 'waiting' : null, null, failed );
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
		if ( test && test.status === 'passed' ) {
			markSteps( 'waiting', null, null );
		} else {
			failStepFor( test );
		}
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
			setActive( 'enabled' );

			// The start request covers enabled→overdue→scheduled→spawning in one
			// round trip; tick the steps on a timer so the user sees the sequence.
			var stagedDone = false;
			setTimeout( function () { setActive( 'overdue' ); }, 400 );
			setTimeout( function () { setActive( 'scheduled' ); }, 800 );
			setTimeout( function () {
				setActive( 'spawning' );
				stagedDone = true;
			}, 1200 );

			post( 'chc_start_test', function ( res ) {
				var proceed = function () {
					var test = res && res.success ? res.data : null;
					if ( ! test ) {
						finish( {
							status: 'failed',
							reason: 'start',
							message: ( res && res.data && res.data.message ) || t( 'couldNotStart' )
						} );
						return;
					}
					if ( test.status === 'failed' ) {
						finish( test );
						return;
					}
					if ( test.status === 'passed' ) {
						finish( test );
						return;
					}
					markSteps( 'spawning', 'waiting', null );
					if ( data.altCron && data.homeUrl ) {
						fetch( data.homeUrl, { mode: 'no-cors', cache: 'no-store' } ).catch( function () {} );
					}
					poll( 0 );
				};
				var waitForStaged = function () {
					if ( stagedDone ) {
						proceed();
					} else {
						setTimeout( waitForStaged, 100 );
					}
				};
				waitForStaged();
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
