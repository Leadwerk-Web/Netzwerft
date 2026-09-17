( function () {
	'use strict';

	var config = window.leadwerkMigrationSync || null;
	var timer = null;
	var inFlight = false;
	var syncRequestInFlight = false;
	var failures = 0;
	var signatures = {};
	var environmentIndex = {};

	function element( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		return node;
	}

	function label( key, fallback ) {
		return config && config.labels && config.labels[ key ] ? config.labels[ key ] : fallback;
	}

	function titleCase( value ) {
		return String( value || '' ).replace( /_/g, ' ' ).replace( /\b\w/g, function ( character ) {
			return character.toUpperCase();
		} );
	}

	function jobStatusText( status ) {
		var keys = {
			preparing_target: 'preparingTarget',
			approved: 'approvedJob',
			transferring: 'transferring',
			peer_ready: 'peerReady',
			receiving: 'receiving',
			ready: 'ready',
			verifying: 'verifying',
			preparing_import: 'preparingImport',
			importing: 'importing',
			finalizing: 'finalizing',
			complete: 'complete',
			failed: 'failed',
			canceled: 'canceled'
		};
		return label( keys[ status ] || status, titleCase( status ) );
	}

	function jobFallbackMessage( status ) {
		var messages = {
			preparing_target: label( 'preparingTarget', 'Creating target safety backup' ),
			approved: label( 'approvedJob', 'Preparing source archive' ),
			transferring: label( 'transferring', 'Transferring archive' ),
			peer_ready: label( 'peerReady', 'Source archive ready' ),
			receiving: label( 'receiving', 'Receiving archive' ),
			ready: label( 'ready', 'Transfer complete' ),
			verifying: label( 'verifying', 'Verifying archive' ),
			preparing_import: label( 'preparingImport', 'Preparing import' ),
			importing: label( 'importing', 'Importing WordPress' ),
			finalizing: label( 'finalizing', 'Finalizing import' ),
			complete: label( 'complete', 'Complete' ),
			failed: label( 'failed', 'Failed' ),
			canceled: label( 'canceled', 'Canceled' )
		};
		return messages[ status ] || titleCase( status );
	}

	function safeClass( value ) {
		return String( value || '' ).toLowerCase().replace( /[^a-z0-9_-]/g, '' );
	}

	function formatBytes( bytes ) {
		var value = Math.max( 0, Number( bytes ) || 0 );
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var unit = 0;
		while ( value >= 1024 && unit < units.length - 1 ) {
			value /= 1024;
			unit += 1;
		}
		return new Intl.NumberFormat( document.documentElement.lang || undefined, { maximumFractionDigits: unit ? 1 : 0 } ).format( value ) + ' ' + units[ unit ];
	}

	function formatAgo( seconds ) {
		var value = Number( seconds );
		var amount;
		var template;
		if ( ! Number.isFinite( value ) ) {
			return label( 'never', 'Never' );
		}
		if ( value < 60 ) {
			amount = Math.max( 0, Math.floor( value ) );
			template = label( 'secondsAgo', '%s seconds ago' );
		} else if ( value < 3600 ) {
			amount = Math.floor( value / 60 );
			template = label( 'minutesAgo', '%s minutes ago' );
		} else if ( value < 86400 ) {
			amount = Math.floor( value / 3600 );
			template = label( 'hoursAgo', '%s hours ago' );
		} else {
			amount = Math.floor( value / 86400 );
			template = label( 'daysAgo', '%s days ago' );
		}
		return template.replace( '%s', String( amount ) );
	}

	function badge( status, text ) {
		return element( 'span', 'leadwerk-badge leadwerk-badge--' + safeClass( status ), text || titleCase( status ) );
	}

	function table( headers, rows ) {
		var wrap = element( 'div', 'leadwerk-table-wrap' );
		var node = element( 'table', 'widefat striped leadwerk-table' );
		var head = document.createElement( 'thead' );
		var headerRow = document.createElement( 'tr' );
		headers.forEach( function ( header ) {
			headerRow.appendChild( element( 'th', '', header ) );
		} );
		head.appendChild( headerRow );
		node.appendChild( head );
		var body = document.createElement( 'tbody' );
		rows.forEach( function ( row ) {
			body.appendChild( row );
		} );
		node.appendChild( body );
		wrap.appendChild( node );
		return wrap;
	}

	function cell( row, content, className ) {
		var node = element( 'td', className || '' );
		if ( content instanceof Node ) {
			node.appendChild( content );
		} else {
			node.textContent = content === undefined || content === null ? '' : String( content );
		}
		row.appendChild( node );
		return node;
	}

	function emptyState( icon, title ) {
		var node = element( 'div', 'leadwerk-empty' );
		var symbol = element( 'span', 'dashicons ' + icon );
		symbol.setAttribute( 'aria-hidden', 'true' );
		node.appendChild( symbol );
		node.appendChild( element( 'h3', '', title ) );
		return node;
	}

	function actionForm( action, idName, id, nonce, text ) {
		var form = document.createElement( 'form' );
		form.method = 'post';
		form.action = config.adminPostUrl;
		[ [ 'action', action ], [ idName, id ], [ '_wpnonce', nonce ] ].forEach( function ( item ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = item[ 0 ];
			input.value = item[ 1 ];
			form.appendChild( input );
		} );
		var button = element( 'button', 'button-link', text );
		button.type = 'submit';
		form.appendChild( button );
		return form;
	}

	function describeEnvironment( id ) {
		var environment = environmentIndex[ id ];
		if ( ! environment ) {
			return String( id || '' ).slice( 0, 8 );
		}
		return label( environment.environment_type, titleCase( environment.environment_type ) ) + ' · ' + environment.site_url;
	}

	function replaceDynamic( name, content, signature ) {
		var container = document.querySelector( '[data-leadwerk-dynamic="' + name + '"]' );
		if ( ! container || signatures[ name ] === signature ) {
			return;
		}
		signatures[ name ] = signature;
		container.replaceChildren( content );
	}

	function renderEnvironments( environments ) {
		environmentIndex = {};
		environments.forEach( function ( environment ) {
			environmentIndex[ environment.environment_id ] = environment;
		} );
		var counter = document.querySelector( '[data-leadwerk-count="environments"]' );
		if ( counter ) {
			counter.textContent = label( 'environmentCount', '%s environments' ).replace( '%s', String( environments.length ) );
		}
		var signature = JSON.stringify( environments.map( function ( item ) {
			return [ item.environment_id, item.site_url, item.environment_type, item.wordpress_version, item.php_version, item.plugin_version, item.status, item.is_online, Math.floor( ( Number( item.last_seen_seconds_ago ) || 0 ) / 10 ) ];
		} ) );
		if ( ! environments.length ) {
			replaceDynamic( 'environments', emptyState( 'dashicons-admin-site-alt3', label( 'noEnvironments', 'Waiting for environment heartbeats' ) ), signature );
			updateEnvironmentSelects( environments );
			return;
		}
		var rows = environments.map( function ( item ) {
			var row = document.createElement( 'tr' );
			var identity = element( 'div' );
			identity.appendChild( element( 'strong', '', label( item.environment_type, titleCase( item.environment_type ) ) ) );
			identity.appendChild( document.createElement( 'br' ) );
			identity.appendChild( element( 'small', '', item.site_url ) );
			cell( row, identity );
			var state = badge( item.is_online ? 'approved' : 'failed', item.is_online ? label( 'online', 'Online' ) : label( 'offline', 'Offline' ) );
			cell( row, state );
			cell( row, formatAgo( item.last_seen_seconds_ago ) );
			cell( row, item.wordpress_version );
			cell( row, item.php_version );
			cell( row, item.plugin_version );
			return row;
		} );
		replaceDynamic( 'environments', table( [ label( 'environment', 'Environment' ), label( 'status', 'Status' ), label( 'lastHeartbeat', 'Last heartbeat' ), 'WordPress', 'PHP', 'Plugin' ], rows ), signature );
		updateEnvironmentSelects( environments );
	}

	function updateEnvironmentSelects( environments ) {
		var candidates = environments.filter( function ( item ) {
			return item.status === 'approved' || ( config.hubMode && item.status === 'available' );
		} );
		var signature = JSON.stringify( candidates.map( function ( item ) {
			return [ item.environment_id, item.family_id, item.site_url, item.environment_type, item.wordpress_version, item.php_version, item.plugin_version, item.status ];
		} ) );
		if ( signatures.selects === signature ) {
			return;
		}
		signatures.selects = signature;
		document.querySelectorAll( '[data-leadwerk-environment-select]' ).forEach( function ( select ) {
			var selected = select.value;
			var placeholder = select.options.length ? select.options[ 0 ].textContent : '';
			var mode = select.getAttribute( 'data-leadwerk-environment-select' );
			var available = candidates.filter( function ( item ) {
				return mode === 'target' ? item.status === 'approved' || item.status === 'available' : item.status === 'approved' && Boolean( item.family_id );
			} );
			select.replaceChildren( new Option( placeholder, '' ) );
			available.forEach( function ( item ) {
				var text = ( item.status === 'available' ? label( 'available', 'Available target' ).toUpperCase() : item.environment_type.toUpperCase() ) + ' · ' + item.site_url;
				if ( mode === 'runtime' ) {
					text = item.environment_type.toUpperCase() + ' · WP ' + item.wordpress_version + ' · PHP ' + item.php_version + ' · Plugin ' + item.plugin_version;
				}
				var option = new Option( text, item.environment_id );
				option.dataset.environmentType = item.environment_type;
				option.dataset.familyId = item.family_id || '';
				option.dataset.unassigned = item.status === 'available' ? '1' : '0';
				select.appendChild( option );
			} );
			if ( available.some( function ( item ) { return item.environment_id === selected; } ) ) {
				select.value = selected;
			}
		} );
		updateTransferConstraints();
	}

	function jobProgress( job ) {
		var total = Math.max( 0, Number( job.archive_size ) || 0 );
		var current = Math.max( 0, Number( job.bytes_received ) || 0 );
		var phase = label( 'upload', 'Upload' );
		if ( [ 'verifying', 'preparing_import', 'importing', 'finalizing' ].indexOf( job.status ) !== -1 ) {
			current = total;
			phase = jobStatusText( job.status );
		} else if ( job.transport === 'direct_push' || job.transport === 'direct_pull' ) {
			current = Math.max( 0, Number( job.bytes_delivered ) || 0 );
			phase = label( 'direct', 'Direct' );
		} else if ( [ 'ready', 'receiving', 'importing', 'complete' ].indexOf( job.status ) !== -1 ) {
			current = Math.max( 0, Number( job.bytes_delivered ) || 0 );
			phase = label( 'download', 'Download' );
		}
		if ( job.status === 'complete' ) {
			current = total;
		}
		var percent = total > 0 ? Math.min( 100, Math.round( current / total * 100 ) ) : 0;
		return { total: total, current: current, percent: percent, phase: phase };
	}

	function renderJobs( jobs ) {
		var signature = JSON.stringify( jobs );
		if ( ! jobs.length ) {
			replaceDynamic( 'jobs', emptyState( 'dashicons-randomize', label( 'noJobs', 'No sync jobs' ) ), signature );
			return;
		}
		var rows = jobs.map( function ( job ) {
			var row = document.createElement( 'tr' );
			cell( row, job.created_at ? job.created_at + ' UTC' : '' );
			cell( row, describeEnvironment( job.source_environment_id ) + ' → ' + describeEnvironment( job.target_environment_id ) );
			cell( row, badge( job.status, jobStatusText( job.status ) ) );
			var progress = jobProgress( job );
			var progressCell = element( 'div', 'leadwerk-sync-progress' );
			if ( progress.total > 0 ) {
				var meter = document.createElement( 'progress' );
				meter.max = 100;
				meter.value = progress.percent;
				meter.setAttribute( 'aria-label', progress.phase + ' ' + progress.percent + '%' );
				progressCell.appendChild( meter );
				progressCell.appendChild( element( 'small', '', progress.phase + ' ' + progress.percent + '% · ' + formatBytes( progress.current ) + ' / ' + formatBytes( progress.total ) ) );
			} else {
				progressCell.appendChild( element( 'small', '', label( 'preparing', 'Preparing backup' ) ) );
			}
			cell( row, progressCell );
			cell( row, job.message || jobFallbackMessage( job.status ) );
			var actions = element( 'div', 'leadwerk-table__actions' );
			if ( [ 'preparing_target', 'approved', 'transferring', 'peer_ready', 'ready', 'receiving', 'failed' ].indexOf( job.status ) !== -1 ) {
				actions.appendChild( actionForm( 'leadwerk_migration_sync_cancel_job', 'job_id', job.job_id, config.cancelJobNonce, label( 'cancel', 'Cancel' ) ) );
			}
			if ( [ 'failed', 'canceled' ].indexOf( job.status ) !== -1 ) {
				actions.appendChild( actionForm( 'leadwerk_migration_sync_retry_job', 'job_id', job.job_id, config.retryJobNonce, label( 'retry', 'Retry' ) ) );
			}
			cell( row, actions, 'leadwerk-table__actions' );
			return row;
		} );
		replaceDynamic( 'jobs', table( [ label( 'created', 'Created' ), label( 'direction', 'Direction' ), label( 'status', 'Status' ), label( 'progress', 'Progress' ), label( 'message', 'Message' ), label( 'manage', 'Manage' ) ], rows ), signature );
	}

	function renderCommands( commands ) {
		var signature = JSON.stringify( commands );
		document.querySelectorAll( '[data-leadwerk-command-count]' ).forEach( function ( counter ) {
			counter.textContent = label( 'recordCount', '%s records' ).replace( '%s', String( commands.length ) );
		} );
		var history = document.querySelector( '[data-leadwerk-command-history]' );
		if ( history && commands.some( function ( command ) { return [ 'queued', 'running', 'waiting_runtime' ].indexOf( command.status ) !== -1; } ) ) {
			history.open = true;
		}
		if ( ! commands.length ) {
			replaceDynamic( 'commands', emptyState( 'dashicons-update', label( 'noCommands', 'No automatic family updates yet' ) ), signature );
			return;
		}
		var rows = commands.map( function ( command ) {
			var payload = command.payload || {};
			var row = document.createElement( 'tr' );
			cell( row, command.created_at ? command.created_at + ' UTC' : '' );
			cell( row, describeEnvironment( command.target_environment_id ) );
			cell( row, payload.wordpress_version ? 'WordPress ' + payload.wordpress_version : 'Plugin ' + ( payload.plugin_version || '—' ) );
			cell( row, titleCase( payload.stage || '—' ) );
			cell( row, badge( command.status, titleCase( command.status ) ) );
			cell( row, command.message );
			var actions = element( 'div', 'leadwerk-table__actions' );
			if ( ! config.hubMode && [ 'queued', 'running', 'waiting_runtime' ].indexOf( command.status ) !== -1 ) {
				actions.appendChild( actionForm( 'leadwerk_migration_runtime_cancel_command', 'command_id', command.command_id, config.cancelCommandNonce, label( 'cancel', 'Cancel' ) ) );
			}
			if ( ! config.hubMode && [ 'failed', 'canceled', 'manual_required' ].indexOf( command.status ) !== -1 ) {
				actions.appendChild( actionForm( 'leadwerk_migration_runtime_retry_command', 'command_id', command.command_id, config.retryCommandNonce, label( 'retry', 'Retry' ) ) );
			}
			cell( row, actions, 'leadwerk-table__actions' );
			return row;
		} );
		replaceDynamic( 'commands', table( [ label( 'created', 'Created' ), label( 'target', 'Target' ), label( 'desiredRuntime', 'Target version' ), label( 'stage', 'Stage' ), label( 'status', 'Status' ), label( 'message', 'Message' ), label( 'manage', 'Manage' ) ], rows ), signature );
	}

	function registryAction( kind, id, status ) {
		var approved = status === 'approved';
		return actionForm( approved ? 'leadwerk_migration_hub_revoke' : 'leadwerk_migration_hub_approve', 'registry_id', id, approved ? config.hubRevokeNonce : config.hubApproveNonce, approved ? label( 'revoke', 'Emergency revoke' ) : label( 'reactivate', 'Reactivate' ) );
	}

	function renderHub( hub ) {
		var familiesContainer = document.querySelector( '[data-leadwerk-dynamic="hub-families"]' );
		var environmentsContainer = document.querySelector( '[data-leadwerk-dynamic="hub-environments"]' );
		if ( familiesContainer ) {
			var families = hub && Array.isArray( hub.families ) ? hub.families : [];
			var familyRows = families.map( function ( family ) {
				var row = document.createElement( 'tr' );
				cell( row, family.project_name );
				cell( row, element( 'code', '', family.family_id ) );
				cell( row, family.status === 'approved' ? label( 'active', 'Active' ) : titleCase( family.status ) );
				cell( row, registryAction( 'family', family.family_id, family.status ) );
				row.querySelector( 'form' ).insertBefore( hiddenInput( 'kind', 'family' ), row.querySelector( 'form' ).firstChild );
				return row;
			} );
			replaceDynamic( 'hub-families', table( [ label( 'project', 'Project' ), label( 'familyId', 'Family ID' ), label( 'status', 'Status' ), '' ], familyRows ), JSON.stringify( families ) );
		}
		if ( environmentsContainer ) {
			var environments = hub && Array.isArray( hub.environments ) ? hub.environments : [];
			var environmentRows = environments.map( function ( item ) {
				var row = document.createElement( 'tr' );
				cell( row, item.site_url );
				cell( row, titleCase( item.environment_type ) );
				cell( row, item.family_id ? String( item.family_id ).slice( 0, 8 ) + '…' : label( 'noFamily', 'No Family ID' ) );
				var heartbeat = element( 'span' );
				heartbeat.appendChild( badge( item.is_online ? 'approved' : 'failed', item.is_online ? label( 'online', 'Online' ) : label( 'offline', 'Offline' ) ) );
				heartbeat.appendChild( document.createTextNode( ' ' + formatAgo( item.last_seen_seconds_ago ) ) );
				cell( row, heartbeat );
				cell( row, 'WP ' + item.wordpress_version + ' · PHP ' + item.php_version + ' · ' + item.plugin_version );
				cell( row, item.status === 'approved' ? label( 'active', 'Active' ) : titleCase( item.status ) );
				var action = registryAction( 'environment', item.environment_id, item.status );
				action.insertBefore( hiddenInput( 'kind', 'environment' ), action.firstChild );
				cell( row, action );
				return row;
			} );
			replaceDynamic( 'hub-environments', table( [ 'URL', label( 'type', 'Type' ), label( 'familyId', 'Family ID' ), label( 'heartbeat', 'Heartbeat' ), label( 'versions', 'Versions' ), label( 'status', 'Status' ), '' ], environmentRows ), JSON.stringify( environments ) );
		}
	}

	function hiddenInput( name, value ) {
		var input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = name;
		input.value = value;
		return input;
	}

	function updateTransferConstraints() {
		var form = document.getElementById( 'leadwerk-sync-transfer' );
		if ( ! form ) {
			return;
		}
		var source = form.querySelector( '[name="source_environment_id"]' );
		var target = form.querySelector( '[name="target_environment_id"]' );
		var submit = form.querySelector( 'button[type="submit"]' );
		var availableToggle = document.getElementById( 'leadwerk-show-unassigned' );
		if ( ! source || ! target ) {
			return;
		}
		var sourceOption = source.options[ source.selectedIndex ];
		var sourceFamily = sourceOption && sourceOption.dataset ? sourceOption.dataset.familyId || '' : '';
		Array.prototype.forEach.call( source.options, function ( option ) {
			option.disabled = option.value !== '' && option.value === target.value;
		} );
		Array.prototype.forEach.call( target.options, function ( option ) {
			if ( option.value === '' ) {
				return;
			}
			var unassigned = option.dataset.unassigned === '1';
			var hiddenAvailable = unassigned && ( ! config.hubMode || ! availableToggle || ! availableToggle.checked );
			var differentFamily = ! unassigned && sourceFamily && option.dataset.familyId !== sourceFamily;
			option.hidden = hiddenAvailable;
			option.disabled = option.value === source.value || hiddenAvailable || Boolean( sourceFamily && differentFamily );
		} );
		if ( target.value && target.options[ target.selectedIndex ] && target.options[ target.selectedIndex ].disabled ) {
			target.value = '';
		}
		if ( submit ) {
			submit.disabled = syncRequestInFlight || ! source.value || ! target.value || source.value === target.value;
		}
	}

	function setSyncRequestFeedback( form, type, message ) {
		var feedback = form.querySelector( '[data-leadwerk-sync-request-feedback]' );
		if ( ! feedback ) {
			return;
		}
		feedback.classList.remove( 'notice-success', 'notice-error', 'notice-info' );
		feedback.classList.add( type === 'success' ? 'notice-success' : type === 'error' ? 'notice-error' : 'notice-info' );
		feedback.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
		var paragraph = feedback.querySelector( 'p' );
		if ( paragraph ) {
			paragraph.textContent = message;
		}
		feedback.hidden = false;
	}

	function refreshImmediately() {
		window.clearTimeout( timer );
		if ( inFlight ) {
			timer = window.setTimeout( refreshImmediately, 100 );
			return;
		}
		refresh();
	}

	function submitSyncRequest( event ) {
		var form = event.currentTarget;
		if ( ! config || ! config.ajaxUrl || ! config.requestSyncNonce ) {
			return;
		}
		event.preventDefault();
		if ( syncRequestInFlight ) {
			return;
		}

		var source = form.querySelector( '[name="source_environment_id"]' );
		var target = form.querySelector( '[name="target_environment_id"]' );
		var submit = form.querySelector( 'button[type="submit"]' );
		if ( ! source || ! target || ! source.value || ! target.value || source.value === target.value ) {
			setSyncRequestFeedback( form, 'error', label( 'syncStartFailed', 'Synchronization could not be started.' ) );
			return;
		}

		var originalLabel = submit ? submit.textContent : '';
		syncRequestInFlight = true;
		if ( submit ) {
			submit.disabled = true;
			submit.setAttribute( 'aria-busy', 'true' );
			submit.textContent = label( 'startingSync', 'Starting synchronization…' );
		}
		setSyncRequestFeedback( form, 'info', label( 'startingSync', 'Starting synchronization…' ) );

		var body = new URLSearchParams();
		body.set( 'action', 'leadwerk_migration_sync_request' );
		body.set( 'nonce', config.requestSyncNonce );
		body.set( 'source_environment_id', source.value );
		body.set( 'target_environment_id', target.value );
		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok || ! json.success ) {
					throw new Error( json && json.data && json.data.message ? json.data.message : label( 'syncStartFailed', 'Synchronization could not be started.' ) );
				}
				return json.data || {};
			} );
		} ).then( function ( data ) {
			setSyncRequestFeedback( form, 'success', data.message || label( 'syncStarted', 'Full-site synchronization started.' ) );
			refreshImmediately();
		} ).catch( function ( error ) {
			setSyncRequestFeedback( form, 'error', error && error.message ? error.message : label( 'syncStartFailed', 'Synchronization could not be started.' ) );
		} ).finally( function () {
			syncRequestInFlight = false;
			if ( submit ) {
				submit.removeAttribute( 'aria-busy' );
				submit.textContent = originalLabel;
			}
			updateTransferConstraints();
		} );
	}

	function setPollStatus( state, message ) {
		document.querySelectorAll( '[data-leadwerk-poll-status]' ).forEach( function ( node ) {
			node.className = 'leadwerk-badge ' + ( state === 'error' ? 'leadwerk-badge--failed' : state === 'paused' ? 'leadwerk-badge--warning' : 'leadwerk-badge--verified' );
			node.textContent = message;
		} );
	}

	function hasActiveWork( data ) {
		var activeJobs = [ 'preparing_target', 'approved', 'transferring', 'peer_ready', 'ready', 'receiving', 'verifying', 'preparing_import', 'importing', 'finalizing' ];
		var activeCommands = [ 'queued', 'running', 'waiting_runtime' ];
		return data.jobs.some( function ( job ) { return activeJobs.indexOf( job.status ) !== -1; } ) || data.commands.some( function ( command ) { return activeCommands.indexOf( command.status ) !== -1; } );
	}

	function schedule( delay ) {
		window.clearTimeout( timer );
		if ( ! document.hidden ) {
			timer = window.setTimeout( refresh, delay );
		}
	}

	function refresh() {
		if ( inFlight || document.hidden || ! config ) {
			return;
		}
		inFlight = true;
		var startedAt = Date.now();
		var body = new URLSearchParams();
		body.set( 'action', 'leadwerk_migration_sync_status' );
		body.set( 'nonce', config.statusNonce );
		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok || ! json.success ) {
					throw new Error( json && json.data && json.data.message ? json.data.message : 'AJAX status request failed.' );
				}
				return json.data;
			} );
		} ).then( function ( data ) {
			failures = 0;
			renderEnvironments( Array.isArray( data.environments ) ? data.environments : [] );
			renderJobs( Array.isArray( data.jobs ) ? data.jobs : [] );
			renderCommands( Array.isArray( data.commands ) ? data.commands : [] );
			renderHub( data.hub || {} );
			setPollStatus( 'live', label( 'live', 'Live' ) );
			var interval = hasActiveWork( data ) ? Number( config.activeInterval ) || 1000 : Number( config.idleInterval ) || 5000;
			schedule( Math.max( 100, interval - ( Date.now() - startedAt ) ) );
		} ).catch( function () {
			failures += 1;
			setPollStatus( 'error', label( 'connection', 'Live update temporarily unavailable' ) );
			schedule( Math.min( 30000, ( Number( config.idleInterval ) || 5000 ) * Math.pow( 1.5, failures ) ) );
		} ).finally( function () {
			inFlight = false;
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var transfer = document.getElementById( 'leadwerk-sync-transfer' );
		if ( transfer ) {
			transfer.addEventListener( 'change', updateTransferConstraints );
			transfer.addEventListener( 'submit', submitSyncRequest );
			updateTransferConstraints();
		}
		var availableToggle = document.getElementById( 'leadwerk-show-unassigned' );
		if ( availableToggle ) {
			availableToggle.addEventListener( 'change', updateTransferConstraints );
		}
		document.addEventListener( 'visibilitychange', function () {
			window.clearTimeout( timer );
			if ( document.hidden ) {
				setPollStatus( 'paused', label( 'paused', 'Paused while tab is hidden' ) );
			} else {
				refresh();
			}
		} );
		schedule( 500 );
	} );
}() );
