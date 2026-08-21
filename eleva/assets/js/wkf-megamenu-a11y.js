/* wkf-megamenu-a11y.js — Working Finiture main navigation.
 *
 * Bricks owns: opening/closing the mobile panel, the focus trap, body
 * scroll lock, Escape, arrow-key roving, and aria-expanded on the three
 * top-level triggers. None of that is reimplemented here.
 *
 * This file adds the two things Bricks cannot express:
 *
 *   1. The mobile LEVEL MACHINE. Bricks renders an opened level inline
 *      (an accordion). The design requires a stack of screens: level 2
 *      replaces level 1 in the panel's center section, level 3 replaces
 *      level 2, and the panel's header/footer never move. The level is
 *      published as `data-wkf-level` on the nav root; section 3b of
 *      wkf-megamenu.css does the showing and hiding.
 *
 *   2. ARIA semantics for the controls the level machine introduces —
 *      the level header row's back button, and each macro row acting as
 *      a drill-in control while it is on level 2.
 *
 * Plain ES5-compatible vanilla JS, no dependencies, no build step.
 */
( function () {
	'use strict';

	var NAV_ID = 'brxe-wkfnav';
	var MOBILE = '(max-width: 991px)';

	function isMobile() {
		return window.matchMedia( MOBILE ).matches;
	}

	function nav() {
		return document.getElementById( NAV_ID );
	}

	function level( el ) {
		return el.getAttribute( 'data-wkf-level' ) || '1';
	}

	function setLevel( el, value ) {
		el.setAttribute( 'data-wkf-level', String( value ) );
	}

	/* ------------------------------------------------------------------ *
	 * Borrowed header widgets
	 *
	 * The panel needs the logo, search, account and cart — but those already
	 * exist in the site header, and duplicating them would put two of each in
	 * the DOM, two tab stops per control, and two copies of the search input
	 * for Relevanssi to bind. So the real elements are MOVED into the panel
	 * while it is open and returned to their exact original position when it
	 * closes. Each one remembers the parent and next sibling it came from.
	 * ------------------------------------------------------------------ */

	var BORROWED = [
		{ id: 'brxe-aqqruf', slot: '.wkf-mm-mobile-head', order: 1 },  /* logo    */
		{ id: 'brxe-donbzq', slot: '.wkf-mm-mobile-head', order: 2 },  /* account */
		{ id: 'brxe-gfhkoa', slot: '.wkf-mm-mobile-head', order: 3 },  /* cart    */
		{ id: 'brxe-gjvbmv', slot: '.wkf-mm-mobile-search', order: 1 } /* search  */
	];

	var borrowedHome = {};

	/* Bricks wraps an icon element that has a link in an <a>, so the node to
	   move is that wrapper, not the icon itself. */
	function widgetNode( id ) {
		var el = document.getElementById( id );

		if ( ! el ) {
			return null;
		}

		var wrapper = el.parentNode;

		if ( wrapper && wrapper.tagName === 'A' && wrapper.classList.contains( 'bricks-link-wrapper' ) ) {
			return wrapper;
		}

		return el;
	}

	function borrowWidgets( root ) {
		BORROWED.forEach( function ( item ) {
			var node = widgetNode( item.id );
			var slot = root.querySelector( item.slot );

			if ( ! node || ! slot || borrowedHome[ item.id ] ) {
				return;
			}

			borrowedHome[ item.id ] = { parent: node.parentNode, next: node.nextSibling };
			node.style.order = item.order;
			slot.appendChild( node );
		} );
	}

	function returnWidgets() {
		BORROWED.forEach( function ( item ) {
			var node = widgetNode( item.id );
			var home = borrowedHome[ item.id ];

			if ( ! node || ! home ) {
				return;
			}

			node.style.order = '';
			home.parent.insertBefore( node, home.next );
			delete borrowedHome[ item.id ];
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Level 2 header row: [ label ................ back ]
	 * Built once per dropdown panel, replacing the "Indietro" anchor that
	 * Bricks injects into every nested <ul>.
	 * ------------------------------------------------------------------ */

	function labelOf( dropdown ) {
		var node = dropdown.querySelector( ':scope > .brx-submenu-toggle > a, :scope > .brx-submenu-toggle > span' );
		return node ? node.textContent.trim() : '';
	}

	function buildLevelHead( dropdown ) {
		var panel = dropdown.querySelector( ':scope > .brx-dropdown-content' );

		if ( ! panel || panel.querySelector( ':scope > .wkf-mm-levelhead' ) ) {
			return;
		}

		var label = labelOf( dropdown );

		/* The catalogue's own page is already linked by the "Vedi tutto il
		   catalogo" row; reuse that href so the title links to the same
		   place. Brand and Settori have no page yet, so they stay on "#". */
		var cta = panel.querySelector( '.wkf-mm-cta a' );
		var href = cta ? cta.getAttribute( 'href' ) : '#';

		var row = document.createElement( 'li' );
		row.className = 'menu-item wkf-mm-levelhead';

		var title = document.createElement( 'a' );
		title.className = 'wkf-mm-levelhead__label';
		title.setAttribute( 'href', href );
		title.textContent = label;

		var back = document.createElement( 'button' );
		back.type = 'button';
		back.className = 'wkf-mm-backbtn brx-multilevel-back';
		back.setAttribute( 'aria-label', 'Torna al menu principale' );

		back.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			closeLevel2( dropdown );
		} );

		row.appendChild( title );
		row.appendChild( back );
		panel.insertBefore( row, panel.firstChild );
	}

	/* Bricks' own injected back rows are redundant once the header row
	   above exists, and its click handler fights the level machine. */
	function suppressBricksBackRows( root ) {
		var rows = root.querySelectorAll( 'a.brx-multilevel-back' );

		Array.prototype.forEach.call( rows, function ( anchor ) {
			anchor.setAttribute( 'hidden', 'hidden' );
			anchor.setAttribute( 'tabindex', '-1' );
			anchor.setAttribute( 'aria-hidden', 'true' );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Macro rows (Catalogo only)
	 * ------------------------------------------------------------------ */

	function columns( root ) {
		return root.querySelectorAll( '.wkf-mm-col' );
	}

	/* On level 2 the whole row drills in, so the row — not the heading
	   link inside it — is the control. On level 3 the same row becomes the
	   level's header, and its heading goes back to being a plain link to
	   the macro's own archive page. */
	function asDrillControl( column ) {
		var row = column.querySelector( '.wkf-mm-coltop' );
		var link = row ? row.querySelector( 'a' ) : null;

		if ( ! row ) {
			return;
		}

		/* At desktop the column is a permanently expanded list, not a level
		   to drill into: the row must stay an ordinary heading whose link
		   opens the macro's archive. */
		if ( ! isMobile() ) {
			asPlainRow( column );
			return;
		}

		row.setAttribute( 'role', 'button' );
		row.setAttribute( 'tabindex', '0' );
		row.setAttribute( 'aria-expanded', 'false' );

		if ( link ) {
			link.setAttribute( 'tabindex', '-1' );
		}
	}

	function asPlainRow( column ) {
		var row = column.querySelector( '.wkf-mm-coltop' );
		var link = row ? row.querySelector( 'a' ) : null;

		if ( ! row ) {
			return;
		}

		row.removeAttribute( 'role' );
		row.removeAttribute( 'tabindex' );
		row.removeAttribute( 'aria-expanded' );

		if ( link ) {
			link.removeAttribute( 'tabindex' );
		}
	}

	function asHeaderRow( column ) {
		var row = column.querySelector( '.wkf-mm-coltop' );
		var link = row ? row.querySelector( 'a' ) : null;

		if ( ! row ) {
			return;
		}

		row.removeAttribute( 'role' );
		row.removeAttribute( 'tabindex' );
		row.setAttribute( 'aria-expanded', 'true' );

		if ( link ) {
			link.removeAttribute( 'tabindex' );
		}
	}

	/* The two arrows are Bricks icon elements: <i> is not focusable and
	   carries no accessible name. The back arrow becomes a real button;
	   the drill-in arrow stays decorative because its whole row is the
	   control. */
	function upgradeArrows( column ) {
		var back = column.querySelector( '.wkf-back-to-macro' );
		var go = column.querySelector( '.wkf-go-into-macro' );
		var name = ( column.querySelector( '.wkf-mm-coltop a, .wkf-mm-coltop .brxe-heading' ) || {} ).textContent || '';

		if ( go ) {
			go.setAttribute( 'aria-hidden', 'true' );
		}

		if ( ! back || back.parentNode.classList.contains( 'wkf-mm-backbtn' ) ) {
			return;
		}

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'wkf-mm-backbtn';
		button.setAttribute( 'aria-label', 'Torna all’elenco delle categorie' );

		back.parentNode.insertBefore( button, back );
		button.appendChild( back );
		back.setAttribute( 'aria-hidden', 'true' );

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			closeLevel3( column );
		} );

		/* Name is read from the row, so nothing else is needed for the
		   accessible name of the level-3 header. */
		void name;
	}

	/* ------------------------------------------------------------------ *
	 * Level transitions
	 * ------------------------------------------------------------------ */

	function openLevel3( column ) {
		var root = nav();
		var list = column.querySelector( '.wkf-mm-sublist' );

		Array.prototype.forEach.call( columns( root ), function ( other ) {
			other.classList.remove( 'wkf-is-open' );
			asDrillControl( other );
		} );

		column.classList.add( 'wkf-is-open' );
		asHeaderRow( column );
		setLevel( root, 3 );

		var focusable = list ? list.querySelector( 'a' ) : null;
		if ( focusable ) {
			focusable.focus();
		}
	}

	function closeLevel3( column ) {
		var root = nav();

		column.classList.remove( 'wkf-is-open' );
		asDrillControl( column );
		setLevel( root, 2 );

		var row = column.querySelector( '.wkf-mm-coltop' );
		if ( row ) {
			row.focus();
		}
	}

	function closeLevel2( dropdown ) {
		var root = nav();

		if ( ! dropdown ) {
			return;
		}

		resetColumns( root );
		setLevel( root, 1 );

		var toggle = dropdown.querySelector( ':scope > .brx-submenu-toggle > button' );

		if ( ! toggle ) {
			return;
		}

		/* Let Bricks do the actual closing so aria-expanded and its own state
		   classes stay consistent — but only when it really is open. The same
		   click on a closed dropdown would OPEN it. */
		if ( dropdown.classList.contains( 'open' ) ) {
			toggle.click();
		}

		toggle.focus();
	}

	function resetColumns( root ) {
		Array.prototype.forEach.call( columns( root ), function ( column ) {
			column.classList.remove( 'wkf-is-open' );
			asDrillControl( column );
		} );
	}

	function resetToLevel1( root ) {
		resetColumns( root );
		setLevel( root, 1 );
	}

	/* Bricks keeps a dropdown's `open` class across a breakpoint change, so a
	   panel opened at desktop would still be open the next time the mobile
	   menu is opened — which would drop the user straight into level 2.
	   Classes are cleared directly rather than by clicking the toggle: a
	   synthetic click inside the observer would re-enter this same code. */
	function closeAllDropdowns( root ) {
		var open = root.querySelectorAll( '.brxe-dropdown.open, .brxe-dropdown.active' );

		Array.prototype.forEach.call( open, function ( dropdown ) {
			dropdown.classList.remove( 'open' );
			dropdown.classList.remove( 'active' );

			var toggle = dropdown.querySelector( ':scope > .brx-submenu-toggle > button' );

			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Wiring
	 * ------------------------------------------------------------------ */

	function wireAriaControls( root ) {
		var triggers = root.querySelectorAll( '.wkf-mm-trigger' );

		Array.prototype.forEach.call( triggers, function ( item, index ) {
			var button = item.querySelector( ':scope > .brx-submenu-toggle > button' );
			var panel = item.querySelector( ':scope > .brx-dropdown-content' );

			if ( ! button || ! panel ) {
				return;
			}

			if ( ! panel.id ) {
				panel.id = 'wkf-mm-panel-' + ( index + 1 );
			}

			button.setAttribute( 'aria-controls', panel.id );
		} );
	}

	function wireSectorDescriptions( root ) {
		var sectors = root.querySelectorAll( '.wkf-mm-sector' );

		Array.prototype.forEach.call( sectors, function ( row, index ) {
			var link = row.querySelector( 'a' );
			var desc = row.querySelector( '.brxe-text-basic' );

			if ( ! link || ! desc ) {
				return;
			}

			if ( ! desc.id ) {
				desc.id = 'wkf-mm-sector-desc-' + ( index + 1 );
			}

			link.setAttribute( 'aria-describedby', desc.id );
		} );
	}

	function enhance() {
		var root = nav();

		if ( ! root ) {
			return;
		}

		wireAriaControls( root );
		wireSectorDescriptions( root );
		suppressBricksBackRows( root );

		Array.prototype.forEach.call( root.querySelectorAll( '.wkf-mm-trigger' ), buildLevelHead );

		Array.prototype.forEach.call( columns( root ), function ( column ) {
			upgradeArrows( column );
			asDrillControl( column );
		} );

		if ( ! root.hasAttribute( 'data-wkf-level' ) ) {
			setLevel( root, 1 );
		}

		/* A macro row drills in — but only while it is on level 2, and only
		   at mobile. At desktop every column is permanently expanded and the
		   row must keep behaving as an ordinary link. */
		root.addEventListener( 'click', function ( event ) {
			if ( ! isMobile() ) {
				return;
			}

			var row = event.target.closest( '.wkf-mm-coltop' );

			if ( ! row || level( root ) !== '2' ) {
				return;
			}

			var column = row.closest( '.wkf-mm-col' );

			if ( ! column ) {
				return;
			}

			event.preventDefault();
			openLevel3( column );
		} );

		root.addEventListener( 'keydown', function ( event ) {
			if ( ! isMobile() ) {
				return;
			}

			var row = event.target.closest ? event.target.closest( '.wkf-mm-coltop' ) : null;

			if ( row && row.getAttribute( 'role' ) === 'button' && ( event.key === 'Enter' || event.key === ' ' ) ) {
				event.preventDefault();
				openLevel3( row.closest( '.wkf-mm-col' ) );
				return;
			}

			/* Escape steps back one level before Bricks closes the panel. */
			if ( event.key === 'Escape' ) {
				var current = level( root );

				if ( current === '3' ) {
					event.stopPropagation();
					closeLevel3( root.querySelector( '.wkf-mm-col.wkf-is-open' ) );
				} else if ( current === '2' ) {
					event.stopPropagation();
					closeLevel2( root.querySelector( '.wkf-mm-trigger.open' ) );
				}
			}
		}, true );

		/* Level 1 <-> 2 follows Bricks' own open/close state. */
		var panelWasOpen = root.classList.contains( 'brx-open' );

		var observer = new MutationObserver( function () {
			if ( ! isMobile() ) {
				return;
			}

			/* Opening or closing the panel always resets the stack: the menu
			   must never reopen halfway down a branch the user left. */
			var panelIsOpen = root.classList.contains( 'brx-open' );

			if ( panelIsOpen !== panelWasOpen ) {
				panelWasOpen = panelIsOpen;
				closeAllDropdowns( root );
				resetToLevel1( root );

				if ( panelIsOpen ) {
					borrowWidgets( root );
				} else {
					returnWidgets();
				}

				return;
			}

			var open = root.querySelector( '.brx-nav-nested-items > .brxe-dropdown.open' );

			if ( ! open ) {
				if ( level( root ) !== '1' ) {
					resetToLevel1( root );
				}
				return;
			}

			if ( level( root ) === '1' ) {
				setLevel( root, 2 );
			}
		} );

		observer.observe( root, { attributes: true, subtree: true, attributeFilter: [ 'class' ] } );

		/* Crossing to desktop discards all level state: at >=992 the panels
		   are permanently expanded grids, not a stack of screens. */
		window.addEventListener( 'resize', function () {
			if ( isMobile() ) {
				resetColumns( root );
				return;
			}

			closeAllDropdowns( root );
			resetToLevel1( root );
			root.classList.remove( 'brx-open' );
			returnWidgets();

			Array.prototype.forEach.call( columns( root ), asPlainRow );
		} );
	}

	function boot() {
		enhance();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			window.setTimeout( boot, 0 );
		} );
	} else {
		window.setTimeout( boot, 0 );
	}
} )();
