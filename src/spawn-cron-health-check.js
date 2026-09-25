( function () {
	'use strict';

	var data = window.spcrData || {};
	var i18n = data.i18n || {};
	var runButton = document.getElementById( 'spcr-run-test' );
	var report = document.getElementById( 'spcr-report' );
	var clearLock = document.getElementById( 'spcr-clear-lock' );
	var pollTimer = null;
	var lastTest = null;

	var ORDER = [ 'enabled', 'overdue', 'scheduled', 'spawning', 'waiting' ];
	var rows = {};
	var bannerTitle = report ? report.querySelector( '.spcr-report-title' ) : null;
	var bannerDate = report ? report.querySelector( '.spcr-report-date' ) : null;
	if ( report ) {
		report.querySelectorAll( '.spcr-report-item' ).forEach( function ( item ) {
			rows[ item.getAttribute( 'data-step' ) ] = item;
		} );
	}

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

	// Derived flags shared by the row builders.
	function buildCtx( test, errorMessage ) {
		var spawn = ( test && test.spawn ) || null;
		var ctx = { test: test, errorMessage: errorMessage || '' };
		ctx.startFailed = ! test || !! test.startFailed;
		ctx.enabledFailed = !! ( test && 'disabled' === test.reason );
		ctx.scheduledFailed = ! ctx.enabledFailed && ( ctx.startFailed || !! ( test && 'schedule' === test.reason ) );
		ctx.spawnFailed = ! ctx.enabledFailed && ! ctx.scheduledFailed &&
			!! ( spawn && ( spawn.error || ( spawn.code && Number( spawn.code ) >= 400 ) ) );
		ctx.waitingFailed = ! ctx.enabledFailed && ! ctx.scheduledFailed && ! ctx.spawnFailed &&
			!! ( test && 'failed' === test.status && null == test.fired );
		ctx.coreFailed = ctx.scheduledFailed || ctx.spawnFailed || ctx.waitingFailed;
		return ctx;
	}

	// { title, state: 'passed'|'failed'|'skipped'|'running', detail } for one row.
	function rowFor( key, ctx ) {
		var test = ctx.test;
		var spawn = ( test && test.spawn ) || null;
		var grace = data.grace || 30;

		if ( 'enabled' === key ) {
			if ( ctx.enabledFailed ) {
				return {
					title: t( 'r1Fail' ),
					state: 'failed',
					detail: para( t( 'r1FailDetail' ) ) +
						why( codeTags( esc( t( 'r1Means' ) ) ), [ 'r1Check1', 'r1Check2', 'r1Check3' ] )
				};
			}
			return {
				title: t( 'r1Pass' ),
				state: 'passed',
				detail: para( ( test && 'false' === test.disable_const ) ? t( 'r1False' ) : t( 'r1Undefined' ) )
			};
		}

		if ( 'overdue' === key ) {
			if ( ! test || ctx.startFailed ) {
				return { title: null, state: 'skipped', detail: '' };
			}
			var od = test.overdue != null ? Number( test.overdue ) : 0;
			if ( od > 0 ) {
				var events = Array.isArray( test.overdue_events ) ? test.overdue_events : [];
				var detail = '<p>' + fmtEm( t( 'r5FailDetail' ), grace ) + '</p>';
				if ( events.length ) {
					detail += overdueTable( events );
					if ( od > events.length ) {
						detail += '<p>' + fmtEm( t( 'r5More' ), od - events.length ) + '</p>';
					}
				}
				detail += why(
					codeTags( esc( t( 'r5Means' ) + ( ctx.coreFailed ? t( 'r5MeansFailed' ) : t( 'r5MeansPassed' ) ) ) ),
					ctx.coreFailed ? [] : [ 'r5Check1', 'r5Check2', 'r5Check3' ]
				);
				return {
					title: 1 === od ? t( 'r5FailOne' ) : fmt( t( 'r5FailMany' ), od ),
					state: 'failed',
					detail: detail
				};
			}
			var total = test.total_events != null ? Number( test.total_events ) : 0;
			return {
				title: t( 'r5Pass' ),
				state: 'passed',
				detail: para( 1 === total ? fmt( t( 'r5PassDetailOne' ), grace ) : fmt( t( 'r5PassDetail' ), total, grace ) )
			};
		}

		if ( 'scheduled' === key ) {
			if ( ctx.enabledFailed ) {
				return { title: null, state: 'skipped', detail: '' };
			}
			if ( ctx.startFailed ) {
				return {
					title: t( 'r2Fail' ),
					state: 'failed',
					detail: '<p>' + codeTags( fmtEm( t( 'r2StartDetail' ), ctx.errorMessage || '' ) ) + '</p>' +
						why( codeTags( esc( t( 'r2StartMeans' ) ) ), [ 'r2StartCheck1', 'r2StartCheck2', 'r2StartCheck3' ] )
				};
			}
			if ( 'schedule' === test.reason ) {
				return {
					title: t( 'r2Fail' ),
					state: 'failed',
					detail: '<p>' + codeTags( fmtEm( t( 'r2FailDetail' ), test.message || '' ) ) + '</p>' +
						why( codeTags( esc( t( 'r2Means' ) ) ), [ 'r2Check1', 'r2Check2', 'r2Check3' ] )
				};
			}
			return { title: t( 'r2Pass' ), state: 'passed', detail: para( t( 'r2PassDetail' ) ) };
		}

		if ( 'spawning' === key ) {
			if ( ctx.enabledFailed || ctx.scheduledFailed ) {
				return { title: null, state: 'skipped', detail: '' };
			}
			if ( spawn && spawn.error ) {
				return {
					title: t( 'r3Fail' ),
					state: 'failed',
					detail: '<p>' + codeTags( fmtEm( t( 'r3ErrDetail' ), spawn.error ) ) + '</p>' +
						why( codeTags( esc( t( 'r3ErrMeans' ) ) ), [ 'r3ErrCheck1', 'r3ErrCheck2', 'r3ErrCheck3', 'r3ErrCheck4' ] )
				};
			}
			if ( spawn && spawn.code >= 400 ) {
				return {
					title: t( 'r3Fail' ),
					state: 'failed',
					detail: '<p>' + fmtEm( t( 'r3HttpDetail' ), spawn.code ) + '</p>' +
						( 500 === Number( spawn.code )
							? why( codeTags( esc( t( 'r3Http500Means' ) ) ), [ 'r3Http500Check1', 'r3Http500Check2', 'r3Http500Check3' ] )
							: why( codeTags( esc( t( 'r3HttpMeans' ) ) ), [ 'r3HttpCheck1', 'r3HttpCheck2', 'r3HttpCheck3' ] ) )
				};
			}
			return {
				title: t( 'r3Pass' ),
				state: 'passed',
				detail: spawn && spawn.alternate
					? para( t( 'r3Alternate' ) )
					: ( spawn && spawn.code
						? '<p>' + codeTags( esc( fmt( t( 'r3PassDetail' ), spawn.code ) ) ) + '</p>'
						: para( t( 'r3PassDetailNoCode' ) ) )
			};
		}

		// waiting
		if ( ctx.enabledFailed || ctx.scheduledFailed || ctx.spawnFailed ) {
			return { title: null, state: 'skipped', detail: '' };
		}
		if ( test && ( 'passed' === test.status || null != test.fired ) ) {
			var dur = test.duration != null ? Number( test.duration ).toFixed( 1 ) : '0.0';
			return {
				title: t( 'r4Pass' ),
				state: 'passed',
				detail: para(
					test.source
						? fmt( t( 'r4PassDetail' ), dur, test.source )
						: fmt( t( 'r4PassDetailNoSource' ), dur )
				)
			};
		}
		if ( test && 'failed' === test.status ) {
			return {
				title: t( 'r4Fail' ),
				state: 'failed',
				detail: '<p>' + codeTags( fmtEm( t( 'r4FailDetail' ), data.timeout || 30 ) ) + '</p>' +
					( spawn && spawn.alternate
						? why( codeTags( esc( t( 'r4AltMeans' ) ) ), [ 'r4AltCheck1' ] )
						: why( codeTags( esc( t( 'r4Means' ) ) ), [ 'r4Check1', 'r4Check2', 'r4Check3' ] ) )
			};
		}
		return { title: null, state: 'running', detail: '' };
	}

	// Apply a row state to the DOM. Idle = data-idle title, empty body, disabled.
	function applyRow( key, row ) {
		var item = rows[ key ];
		if ( ! item ) { return; }
		item.classList.remove( 'is-open', 'is-passed', 'is-failed', 'is-skipped', 'is-running' );
		var btn = item.querySelector( '.spcr-report-row' );
		var name = item.querySelector( '.spcr-report-name' );
		var body = item.querySelector( '.spcr-report-body' );
		if ( 'passed' === row.state || 'failed' === row.state ) {
			item.classList.add( 'is-' + row.state );
			name.textContent = row.title;
			body.innerHTML = row.detail;
			btn.disabled = false;
		} else {
			if ( 'skipped' === row.state || 'running' === row.state ) {
				item.classList.add( 'is-' + row.state );
			}
			name.innerHTML = esc( item.getAttribute( 'data-idle' ) ) +
				( 'skipped' === row.state ? '<span class="spcr-not-run">' + esc( t( 'notRun' ) ) + '</span>' : '' );
			body.innerHTML = '';
			btn.disabled = true;
		}
		btn.setAttribute( 'aria-expanded', 'false' );
	}

	function resetReport() {
		if ( ! report ) { return; }
		report.className = 'spcr-report';
		if ( bannerTitle ) { bannerTitle.textContent = ''; }
		if ( bannerDate ) { bannerDate.textContent = ''; }
		ORDER.forEach( function ( key ) {
			applyRow( key, { title: null, state: 'idle', detail: '' } );
		} );
	}

	// Play the rows in visual order: skipped rows appear fast (150ms), real
	// steps spin for 400ms then resolve and pause 250ms before the next.
	function runRows( ctx, i, done ) {
		if ( i >= ORDER.length ) { done(); return; }
		var key = ORDER[ i ];
		var row = rowFor( key, ctx );
		var item = rows[ key ];
		if ( 'skipped' === row.state ) {
			applyRow( key, row );
			setTimeout( function () { runRows( ctx, i + 1, done ); }, 150 );
			return;
		}
		if ( item ) { item.classList.add( 'is-running' ); }
		setTimeout( function () {
			applyRow( key, row );
			if ( 'running' === row.state ) { done(); return; }
			setTimeout( function () { runRows( ctx, i + 1, done ); }, 250 );
		}, 400 );
	}

	function finish( test, errorMessage ) {
		stopPolling();
		var ctx = buildCtx( test, errorMessage );
		// Settle every row that is not already passed: waiting resolves now,
		// overdue's "usually means" text depends on whether a core step
		// failed, and a failed start request never played the rows at all.
		ORDER.forEach( function ( key ) {
			if ( rows[ key ] && ! rows[ key ].classList.contains( 'is-passed' ) ) {
				applyRow( key, rowFor( key, ctx ) );
			}
		} );
		var failed = ! test || 'failed' === test.status;
		if ( bannerTitle ) { bannerTitle.textContent = t( failed ? 'bannerFailed' : 'bannerPassed' ); }
		if ( bannerDate ) { bannerDate.textContent = formatDate( Date.now() / 1000 ); }
		setTimeout( function () {
			if ( report ) { report.classList.add( failed ? 'is-failed' : 'is-passed' ); }
			setRunning( false );
		}, 200 );
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
			var timedOut = lastTest || {};
			timedOut.status = 'failed';
			timedOut.reason = 'timeout';
			timedOut.message = t( 'timeout' );
			finish( timedOut );
			return;
		}
		post( 'spcr_test_status', function ( res ) {
			var test = res && res.success ? res.data : null;
			if ( test && test.status && test.status !== 'running' ) {
				finish( test );
				return;
			}
			if ( test ) { lastTest = test; }
			pollTimer = setTimeout( function () { poll( elapsed + 1 ); }, 1000 );
		} );
	}

	if ( report ) {
		report.querySelectorAll( '.spcr-report-item' ).forEach( function ( item ) {
			var btn = item.querySelector( '.spcr-report-row' );
			btn.addEventListener( 'click', function () {
				if ( btn.disabled ) { return; }
				var open = item.classList.toggle( 'is-open' );
				btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		} );
	}

	if ( runButton ) {
		runButton.addEventListener( 'click', function () {
			setRunning( true );
			resetReport();
			lastTest = null;
			var enabledItem = rows.enabled;
			if ( enabledItem ) { enabledItem.classList.add( 'is-running' ); }

			post( 'spcr_start_test', function ( res ) {
				var test = res && res.success ? res.data : null;
				var errorMessage = ( res && res.data && res.data.message ) || t( 'couldNotStart' );
				lastTest = test;
				var ctx = buildCtx( test, errorMessage );
				runRows( ctx, 0, function () {
					var waiting = rowFor( 'waiting', ctx );
					if ( 'running' === waiting.state && test ) {
						if ( data.altCron && data.homeUrl ) {
							fetch( data.homeUrl, { mode: 'no-cors', cache: 'no-store' } ).catch( function () {} );
						}
						poll( 0 );
						return;
					}
					finish( test || { status: 'failed', reason: 'start', startFailed: true, message: errorMessage }, errorMessage );
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
