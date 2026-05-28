function getActiveTextarea() {
	const activeTextarea = document.querySelector('.republish-content.republish-content--active textarea');
	if (activeTextarea) {
		return activeTextarea;
	}
	// Fallback to the original textarea if no tabs are present
	return document.querySelector('#republication-tracker-tool-shareable-content');
}

function initTabSwitching() {
	const $ = jQuery;
	$( '.republish-format-tabs__button' ).on( 'click', function(e) {
		e.preventDefault();
		const targetTab = $(this).attr('data-tab');

		$( '.republish-format-tabs__button' ).removeClass( 'republish-format-tabs__button--active' );
		$( '.republish-content' ).removeClass( 'republish-content--active' );

		$( this ).addClass( 'republish-format-tabs__button--active' );
		$('[data-tab-content="' + targetTab + '"]').addClass( 'republish-content--active' );

		// Show/hide main copy button based on active tab
		const $mainCopyButton = $( '.republication-tracker-tool__copy-button--main' );
		if ( $mainCopyButton.length ) {
			if ( targetTab === 'html' ) {
				$mainCopyButton.addClass( 'show-for-html' );
			} else {
				$mainCopyButton.removeClass( 'show-for-html' );
			}
		}
	} );

	// Initialize copy buttons for individual fields
	$( '.plain-text-field__button' ).on( 'click', function(e) {
		e.preventDefault();
		const target = $( this ).attr( 'data-target' );
		ClipboardUtils.copyFromElement( target, this );
	} );
}

function modal_actions(){
	// Remove captions from shareable text
	var $ = jQuery;
	var $shareable = $('#republication-tracker-tool-shareable-content');
	var html = $shareable.text();

	var parser = new DOMParser();
	var doc = parser.parseFromString(html, "text/html");
	$(doc).find('.wp-caption').remove();
	var captionless = $(doc).find('body').html();
	$shareable.html(captionless);

	// Responsive modal
	var $modal = $('#republication-tracker-tool-modal');
	var $btn = $('.republication-tracker-tool-button.modal');
	var $close = $('.republication-tracker-tool-close');

	// url hash of #show-republish: open the modal
	if ( '#show-republish' === window.location.hash ) {
		show_modal( $modal, $close );
	}

	// click the republish button: open the modal
	$btn.click(function(){
		show_modal( $modal, $close );
	});

	// click on the modal: close the modal
	$modal.click(function(){
		close_modal( $modal, $btn );
	});

	// close button click: close the modal
	$close.click(function(){
		close_modal( $modal, $btn );
	});

	// escape key press: close the modal
	$(document).keyup(function(e) {
		if (27 === e.keyCode) {
			close_modal( $modal, $btn );
		}
	});
}

function show_modal( $modal, $close ) {
	var $ = jQuery;
	var $modal_content = $('#republication-tracker-tool-modal-content');
	//$modal.html( html );
	$modal.show();
	$modal_content.show();
	$('body').addClass('modal-open-disallow-scrolling');
	$modal_content.unbind().click(function(e) {
		e.stopPropagation();
	});

	initTabSwitching();

	trapFocus( $modal );
	$close.focus();
}

function trapFocus( $modal ) {
	var $ = jQuery;
	var focusableEls = $modal.find('a[href]:not([disabled]), button:not([disabled]), textarea:not([disabled]), input[type="text"]:not([disabled]), input[type="radio"]:not([disabled]), input[type="checkbox"]:not([disabled]), select:not([disabled])');
	var firstFocusableEl = focusableEls[0];
	var lastFocusableEl = focusableEls[focusableEls.length - 1];
	var KEYCODE_TAB = 9;

	$modal.on( 'keydown', function( e ) {
		var isTabPressed = ( e.key === 'Tab' || e.keyCode === KEYCODE_TAB );

		if ( ! isTabPressed ) {
			return;
		}

		if ( e.shiftKey ) /* shift + tab */ {
			if ( document.activeElement === firstFocusableEl ) {
				lastFocusableEl.focus();
				e.preventDefault();
			}
		} else if ( document.activeElement === lastFocusableEl ) {
			firstFocusableEl.focus();
			e.preventDefault();
		}
	} );
}

function close_modal( $modal, $btn ) {
	var $ = jQuery;
	$('body').removeClass('modal-open-disallow-scrolling');
	$modal.hide();
	$btn.focus();
}

jQuery(document).ready(function(){
	var $ = jQuery,
		postId = $( '#republication-tracker-tool-modal' ).attr( 'data-postid' ),
		pluginsdir = $( '#republication-tracker-tool-modal' ).attr( 'data-pluginsdir' );

		$('#republication-tracker-tool-modal').append($('#republication-tracker-tool-modal-content'));
		$('body').append($('#republication-tracker-tool-modal'));

		modal_actions();

});
