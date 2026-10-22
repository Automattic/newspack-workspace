/* global jQuery, ClipboardUtils */

( function () {
	const republicationFocusableSelector =
		'a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input[type="text"]:not([disabled]), input[type="radio"]:not([disabled]), input[type="checkbox"]:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function getActiveTextarea() {
		const activeTextarea = document.querySelector( '.republish-content.republish-content--active textarea' );
		if ( activeTextarea ) {
			return activeTextarea;
		}
		return document.querySelector( '#republication-tracker-tool-shareable-content' );
	}

	function usesNewspackUI( modal ) {
		return modal.classList.contains( 'newspack-ui__modal-container' );
	}

	function isModalOpen( modal ) {
		if ( usesNewspackUI( modal ) ) {
			return modal.getAttribute( 'data-state' ) === 'open';
		}
		return modal.style.display !== 'none';
	}

	function selectTab( modal, tab ) {
		const targetTab = tab.getAttribute( 'data-tab' );

		modal.querySelectorAll( '.republish-format-tabs__button' ).forEach( function ( btn ) {
			const selected = btn === tab;
			btn.classList.toggle( 'republish-format-tabs__button--active', selected );
			btn.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			btn.setAttribute( 'tabindex', selected ? '0' : '-1' );
		} );

		modal.querySelectorAll( '.republish-content' ).forEach( function ( content ) {
			content.classList.toggle( 'republish-content--active', content.getAttribute( 'data-tab-content' ) === targetTab );
		} );

		const mainCopyButton = modal.querySelector( '.republication-tracker-tool__copy-button--main' );
		if ( mainCopyButton ) {
			mainCopyButton.classList.toggle( 'show-for-html', targetTab === 'html' );
		}
	}

	function initTabs( modal ) {
		const tabs = Array.from( modal.querySelectorAll( '.republish-format-tabs__button' ) );

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				selectTab( modal, tab );
			} );

			tab.addEventListener( 'keydown', function ( e ) {
				if ( e.defaultPrevented ) {
					return;
				}
				let next = null;
				if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
					next = tabs[ ( index + 1 ) % tabs.length ];
				} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
					next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
				} else if ( e.key === 'Home' ) {
					next = tabs[ 0 ];
				} else if ( e.key === 'End' ) {
					next = tabs[ tabs.length - 1 ];
				}
				if ( next ) {
					e.preventDefault();
					selectTab( modal, next );
					next.focus();
				}
			} );
		} );

		modal.querySelectorAll( '.plain-text-field__button' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				const target = btn.getAttribute( 'data-target' );
				if ( window.ClipboardUtils && target ) {
					ClipboardUtils.copyFromElement( target, btn );
				}
			} );
		} );

		const copyActiveBtn = modal.querySelector( '[data-copy-active]' );
		if ( copyActiveBtn ) {
			copyActiveBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( window.ClipboardUtils ) {
					ClipboardUtils.copyFromElement( getActiveTextarea(), copyActiveBtn );
				}
			} );
		}
	}

	function stripCaptions( modal ) {
		const shareable = modal.querySelector( '#republication-tracker-tool-shareable-content' );
		if ( ! shareable ) {
			return;
		}
		const doc = new DOMParser().parseFromString( shareable.textContent, 'text/html' );
		doc.querySelectorAll( '.wp-caption' ).forEach( function ( el ) {
			el.remove();
		} );
		shareable.innerHTML = doc.body.innerHTML;
	}

	function containFocus( modal, e ) {
		const focusable = Array.from( modal.querySelectorAll( republicationFocusableSelector ) ).filter( function ( el ) {
			return el.offsetParent !== null;
		} );
		if ( ! focusable.length ) {
			return;
		}
		const first = focusable[ 0 ];
		const last = focusable[ focusable.length - 1 ];
		const active = modal.ownerDocument.activeElement;
		const inside = focusable.indexOf( active ) !== -1;

		if ( e.shiftKey && ( active === first || ! inside ) ) {
			last.focus();
			e.preventDefault();
		} else if ( ! e.shiftKey && ( active === last || ! modal.contains( active ) ) ) {
			first.focus();
			e.preventDefault();
		}
	}

	function closeModal( modal ) {
		if ( ! isModalOpen( modal ) ) {
			return;
		}
		if ( usesNewspackUI( modal ) ) {
			modal.setAttribute( 'data-state', 'closed' );
			return;
		}
		document.body.classList.remove( 'modal-open-disallow-scrolling' );
		modal.style.display = 'none';
		if ( modal.rttTrigger ) {
			modal.rttTrigger.focus();
		}
		modal.rttTrigger = null;
	}

	function showModal( modal, trigger ) {
		if ( usesNewspackUI( modal ) ) {
			if ( trigger ) {
				trigger.focus();
			}
			modal.setAttribute( 'data-state', 'open' );
			return;
		}
		const content = modal.querySelector( '#republication-tracker-tool-modal-content' );
		modal.rttTrigger = trigger;

		modal.style.display = '';
		if ( content ) {
			content.style.display = '';
		}
		document.body.classList.add( 'modal-open-disallow-scrolling' );

		const title = modal.querySelector( '#republish-modal-label' );
		if ( title ) {
			title.focus();
		}
	}

	function initModal( modal ) {
		if ( modal.rttReady ) {
			return;
		}
		modal.rttReady = true;

		const content = document.getElementById( 'republication-tracker-tool-modal-content' );
		if ( content && content.parentNode !== modal ) {
			modal.appendChild( content );
		}
		document.body.appendChild( modal );

		stripCaptions( modal );
		initTabs( modal );

		if ( content ) {
			content.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
			} );
		}
		modal.addEventListener( 'click', function ( e ) {
			if ( usesNewspackUI( modal ) && ! e.target.classList.contains( 'newspack-ui__modal-container__overlay' ) ) {
				return;
			}
			closeModal( modal );
		} );

		const closeBtn = modal.querySelector( '.republication-tracker-tool-close' );
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				closeModal( modal );
			} );
		}

		document.addEventListener( 'keydown', function ( e ) {
			if ( usesNewspackUI( modal ) || ! isModalOpen( modal ) ) {
				return;
			}
			if ( e.key === 'Escape' ) {
				closeModal( modal );
			} else if ( e.key === 'Tab' && ! e.defaultPrevented ) {
				containFocus( modal, e );
			}
		} );
	}

	jQuery( document ).ready( function () {
		const modal = document.getElementById( 'republication-tracker-tool-modal' );
		if ( ! modal ) {
			return;
		}
		initModal( modal );

		const triggers = document.querySelectorAll( '.republication-tracker-tool-button.modal' );
		triggers.forEach( function ( trigger ) {
			trigger.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				showModal( modal, trigger );
			} );
		} );

		if ( '#show-republish' === window.location.hash ) {
			showModal( modal, triggers[ 0 ] || null );
		}
	} );
} )();
