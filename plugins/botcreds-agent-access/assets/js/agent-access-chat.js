/* global AgentAccessChatData */
(function () {
	'use strict';

	var cfg          = window.AgentAccessChatData || {};
	var restUrl      = cfg.restUrl  || '';
	var nonce        = cfg.nonce    || '';
	var sender       = cfg.sender   || '';
	var initChannel  = cfg.channel  || 'general';

	var channels  = document.querySelectorAll( '#aa-channels li' );
	var msgBox    = document.getElementById( 'aa-messages' );
	var input     = document.getElementById( 'aa-input' );
	var sendBtn   = document.getElementById( 'aa-send' );
	var chanLabel = document.getElementById( 'aa-channel-name' );

	var activeChannel = initChannel;
	var lastId        = 0;

	function headers() {
		return { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };
	}

	function escHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s;
		return d.innerHTML;
	}

	function renderMsg( m ) {
		var isBotClass = ( m.sender_type === 'bot' || m.sender_type === 'agent' ) ? ' bot' : '';
		var time       = m.timestamp ? new Date( m.timestamp ).toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } ) : '';
		return '<div class="aa-msg">' +
			'<span class="aa-msg-sender' + isBotClass + '">' + escHtml( m.sender ) + '</span>' +
			'<span class="aa-msg-time">' + escHtml( time ) + '</span>' +
			'<div class="aa-msg-body">' + escHtml( m.message ) + '</div>' +
			'</div>';
	}

	function loadMessages( channel ) {
		fetch( restUrl + '/messages?channel=' + encodeURIComponent( channel ) + '&limit=50', { headers: headers() } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( msgs ) {
				var list = Array.isArray( msgs ) ? msgs : ( msgs.messages || [] );
				msgBox.innerHTML = list.map( renderMsg ).join( '' );
				msgBox.scrollTop = msgBox.scrollHeight;
				if ( list.length ) {
					lastId = Math.max.apply( null, list.map( function ( m ) { return parseInt( m.id, 10 ) || 0; } ) );
				}
			} )
			.catch( function ( e ) { console.error( 'Load error:', e ); } );
	}

	function pollNew() {
		fetch( restUrl + '/messages?channel=' + encodeURIComponent( activeChannel ) + '&since_id=' + lastId + '&limit=20', { headers: headers() } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( msgs ) {
				var list = Array.isArray( msgs ) ? msgs : ( msgs.messages || [] );
				list.forEach( function ( m ) {
					var mid = parseInt( m.id, 10 ) || 0;
					if ( mid > lastId ) {
						lastId = mid;
						msgBox.insertAdjacentHTML( 'beforeend', renderMsg( m ) );
					}
				} );
				if ( list.length ) { msgBox.scrollTop = msgBox.scrollHeight; }
			} )
			.catch( function () { /* silent */ } );
	}

	function sendMessage() {
		var text = input.value.trim();
		if ( ! text ) { return; }
		input.value      = '';
		sendBtn.disabled = true;

		fetch( restUrl + '/send', {
			method:  'POST',
			headers: headers(),
			body:    JSON.stringify( { channel: activeChannel, sender: sender, sender_type: 'human', message: text } ),
		} )
			.then( function () { return pollNew(); } )
			.catch( function ( e ) { console.error( 'Send error:', e ); } )
			.finally( function () {
				sendBtn.disabled = false;
				input.focus();
			} );
	}

	function switchChannel( ch ) {
		activeChannel = ch;
		chanLabel.textContent = ch;
		channels.forEach( function ( el ) { el.classList.toggle( 'active', el.dataset.channel === ch ); } );
		lastId           = 0;
		msgBox.innerHTML = '';
		loadMessages( ch );
	}

	channels.forEach( function ( el ) { el.addEventListener( 'click', function () { switchChannel( el.dataset.channel ); } ); } );
	sendBtn.addEventListener( 'click', sendMessage );
	input.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { sendMessage(); } } );

	loadMessages( activeChannel );
	setInterval( pollNew, 5000 );
} )();
