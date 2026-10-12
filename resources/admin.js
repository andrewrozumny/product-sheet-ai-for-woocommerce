/* Product Sheet AI: preview and apply UI. Vanilla JS, no build step. Server data is only ever written with textContent. */
( function () {
	'use strict';

	var cfg = window.productSheetAiConfig;
	if ( ! cfg ) {
		return;
	}
	var S = cfg.strings || {};

	/**
	 * Replace %s, %1$s, %2$s ... with the given values.
	 */
	function fmt( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return String( template ).replace( /%(?:(\d+)\$)?s/g, function ( m, n ) {
			var i = n ? parseInt( n, 10 ) - 1 : next++;
			return i < args.length ? String( args[ i ] ) : '';
		} );
	}

	/**
	 * Create an element. attrs: plain attributes; children: strings (as text) or nodes.
	 */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		var key;
		if ( attrs ) {
			for ( key in attrs ) {
				if ( Object.prototype.hasOwnProperty.call( attrs, key ) && null !== attrs[ key ] && false !== attrs[ key ] ) {
					node.setAttribute( key, attrs[ key ] );
				}
			}
		}
		( children || [] ).forEach( function ( child ) {
			if ( null === child || undefined === child ) {
				return;
			}
			node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	function text( value ) {
		return null === value || undefined === value ? '' : String( value );
	}

	function safeUrl( url ) {
		var value = text( url );
		return /^https?:\/\//i.test( value ) || '/' === value.charAt( 0 ) ? value : '';
	}

	function show( node, visible ) {
		if ( visible ) {
			node.removeAttribute( 'hidden' );
		} else {
			node.setAttribute( 'hidden', 'hidden' );
		}
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function wpNotice( kind, message, notes ) {
		var box = el( 'div', { 'class': 'notice notice-' + kind + ' is-dismissible product-sheet-ai-notice', role: 'status' } );
		box.appendChild( el( 'p', null, [ message ] ) );
		if ( notes && notes.length ) {
			var list = el( 'ul', { 'class': 'psai-notes' } );
			notes.forEach( function ( note ) {
				list.appendChild( el( 'li', null, [ text( note ) ] ) );
			} );
			box.appendChild( list );
		}
		var close = el( 'button', { type: 'button', 'class': 'notice-dismiss' }, [ el( 'span', { 'class': 'screen-reader-text' }, [ S.dismiss || '' ] ) ] );
		close.addEventListener( 'click', function () {
			if ( box.parentNode ) {
				box.parentNode.removeChild( box );
			}
		} );
		box.appendChild( close );
		return box;
	}

	/**
	 * Show a notice flagged before a reload, once the next page has loaded.
	 */
	function showAppliedNotice() {
		var raw = null;
		try {
			raw = window.sessionStorage.getItem( cfg.appliedKey );
			window.sessionStorage.removeItem( cfg.appliedKey );
		} catch ( e ) {
			return;
		}
		if ( ! raw ) {
			return;
		}
		var data = {};
		try {
			data = JSON.parse( raw ) || {};
		} catch ( e ) {
			data = {};
		}
		var wrap = document.querySelector( '#wpbody-content .wrap' ) || document.querySelector( '.wrap' );
		if ( ! wrap ) {
			return;
		}
		var notice = wpNotice( 'success', S.applied, Array.isArray( data.notes ) ? data.notes : [] );
		var heading = wrap.querySelector( 'h1, h2' );
		if ( heading && heading.nextSibling ) {
			wrap.insertBefore( notice, heading.nextSibling );
		} else {
			wrap.insertBefore( notice, wrap.firstChild );
		}
	}

	function Panel( root ) {
		this.root = root;
		this.productId = parseInt( root.getAttribute( 'data-product-id' ) || '0', 10 ) || 0;
		this.token = '';
		this.busy = false;
		this.openButton = root.querySelector( '.psai-open' );
		this.form = root.querySelector( '.psai-form' );
		this.textarea = root.querySelector( '.psai-text' );
		this.file = root.querySelector( '.psai-file' );
		this.generate = root.querySelector( '.psai-generate' );
		this.spinner = root.querySelector( '.psai-spinner' );
		this.status = root.querySelector( '.psai-status' );
		this.error = root.querySelector( '.psai-error' );
		this.preview = root.querySelector( '.psai-preview' );
		this.done = root.querySelector( '.psai-done' );
		this.bind();
	}

	Panel.prototype.bind = function () {
		var self = this;
		if ( this.openButton ) {
			this.openButton.addEventListener( 'click', function () {
				var open = self.form.hasAttribute( 'hidden' );
				show( self.form, open );
				self.openButton.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				if ( open && self.textarea ) {
					self.textarea.focus();
				}
			} );
		}
		this.generate.addEventListener( 'click', function () {
			self.onGenerate();
		} );
	};

	Panel.prototype.setBusy = function ( busy, message ) {
		this.busy = busy;
		var buttons = this.root.querySelectorAll( 'button.psai-generate, button.psai-apply, button.psai-cancel' );
		Array.prototype.forEach.call( buttons, function ( button ) {
			button.disabled = busy;
		} );
		this.spinner.classList.toggle( 'is-active', busy );
		this.status.textContent = busy ? message : '';
		if ( this.preview ) {
			this.preview.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		}
	};

	Panel.prototype.showError = function ( message, settingsUrl ) {
		clear( this.error );
		this.error.appendChild( el( 'p', null, [ message ] ) );
		var url = safeUrl( settingsUrl );
		if ( url ) {
			this.error.lastChild.appendChild( document.createTextNode( ' ' ) );
			this.error.lastChild.appendChild( el( 'a', { href: url }, [ S.openSettings ] ) );
		}
		show( this.error, true );
	};

	Panel.prototype.clearError = function () {
		clear( this.error );
		show( this.error, false );
	};

	/**
	 * POST to admin-ajax. Resolves with the parsed JSON body, or rejects with an Error whose message is user-facing.
	 */
	Panel.prototype.post = function ( body ) {
		return window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.text().then( function ( raw ) {
				var json = null;
				try {
					json = JSON.parse( raw );
				} catch ( e ) {
					json = null;
				}
				if ( ! json || 'object' !== typeof json || ! ( 'success' in json ) ) {
					var bad = new Error( S.badResponse );
					bad.psai = {};
					throw bad;
				}
				if ( ! json.success ) {
					var data = json.data || {};
					var err = new Error( text( data.message ) || S.badResponse );
					err.psai = data;
					throw err;
				}
				return json.data || {};
			} );
		}, function () {
			var net = new Error( S.networkError );
			net.psai = {};
			throw net;
		} );
	};

	Panel.prototype.fail = function ( err ) {
		this.setBusy( false, '' );
		this.showError( err && err.message ? err.message : S.badResponse, err && err.psai ? err.psai.settings_url : '' );
	};

	Panel.prototype.onGenerate = function () {
		var self = this;
		if ( this.busy ) {
			return;
		}
		this.clearError();
		var value = this.textarea.value;
		var hasFile = this.file && this.file.files && this.file.files.length > 0;
		if ( ! hasFile && '' === value.replace( /\s+/g, '' ) ) {
			this.showError( S.emptyInput, '' );
			this.textarea.focus();
			return;
		}
		var body = new window.FormData();
		body.append( 'action', 'product_sheet_ai_generate' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'product_id', String( this.productId ) );
		body.append( 'sheet_text', value );
		if ( hasFile ) {
			body.append( 'sheet_file', this.file.files[ 0 ] );
		}
		this.setBusy( true, S.working );
		show( this.preview, false );
		this.post( body ).then( function ( data ) {
			self.setBusy( false, '' );
			if ( ! data.preview || ! data.token ) {
				throw Object.assign( new Error( S.badResponse ), { psai: {} } );
			}
			self.token = text( data.token );
			self.renderPreview( data.preview, text( data.notice ) );
		} ).catch( function ( err ) {
			self.fail( err );
		} );
	};

	function th( label, attrs ) {
		attrs = attrs || {};
		attrs.scope = 'col';
		return el( 'th', attrs, [ label ] );
	}

	function textCell( value ) {
		var content = text( value );
		return el( 'td', null, [ el( 'div', { 'class': 'psai-text-cell' }, [ '' === content ? el( 'span', { 'class': 'psai-empty' }, [ S.empty ] ) : content ] ) ] );
	}

	function typeLabel( value ) {
		if ( 'variable' === value ) {
			return S.typeVariable;
		}
		if ( 'simple' === value ) {
			return S.typeSimple;
		}
		return '' === text( value ) ? S.typeNone : text( value );
	}

	function badge( label, kind ) {
		return el( 'span', { 'class': 'psai-badge psai-badge-' + kind }, [ label ] );
	}

	Panel.prototype.renderFields = function ( preview ) {
		var tbody = el( 'tbody' );
		var type = preview.product_type || {};
		tbody.appendChild( el( 'tr', null, [
			el( 'th', { scope: 'row' }, [ S.productType ] ),
			textCell( typeLabel( type.current ) ),
			textCell( typeLabel( type.proposed ) ),
			el( 'td', { 'class': 'psai-check-cell' }, [] )
		] ) );
		( preview.fields || [] ).forEach( function ( field ) {
			var id = 'psai-field-' + text( field.key );
			var box = el( 'input', { type: 'checkbox', id: id, 'data-key': text( field.key ), 'aria-label': fmt( S.applyField, text( field.label ) ) } );
			box.checked = !! field.checked && ! field.disabled;
			box.disabled = !! field.disabled;
			tbody.appendChild( el( 'tr', { 'class': field.disabled ? 'psai-row-disabled' : null }, [
				el( 'th', { scope: 'row' }, [ text( field.label ) ] ),
				textCell( field.current ),
				textCell( field.proposed ),
				el( 'td', { 'class': 'psai-check-cell' }, [ box ] )
			] ) );
			if ( field.note ) {
				tbody.appendChild( el( 'tr', { 'class': 'psai-note-row' }, [
					el( 'td', { colspan: '4' }, [ el( 'span', { 'class': 'description' }, [ text( field.note ) ] ) ] )
				] ) );
			}
		} );
		return el( 'table', { 'class': 'widefat striped psai-table' }, [
			el( 'thead', null, [ el( 'tr', null, [ th( S.colField ), th( S.colCurrent ), th( S.colProposed ), th( S.colApply, { 'class': 'psai-check-cell' } ) ] ) ] ),
			tbody
		] );
	};

	Panel.prototype.renderAttributes = function ( preview ) {
		var attributes = preview.attributes || [];
		if ( ! attributes.length ) {
			return null;
		}
		var matches = { global: [ S.matchGlobal, 'existing' ], new_global: [ S.matchNewGlobal, 'new' ], local: [ S.matchLocal, 'local' ] };
		var list = el( 'ul', { 'class': 'psai-attributes' } );
		attributes.forEach( function ( attr ) {
			var info = matches[ attr.match ] || matches.local;
			var values = el( 'span', { 'class': 'psai-values' } );
			( attr.values || [] ).forEach( function ( v ) {
				var chip = el( 'span', { 'class': 'psai-chip' }, [ text( v.value ) ] );
				if ( ! v.exists ) {
					chip.appendChild( document.createTextNode( ' ' ) );
					chip.appendChild( badge( S.newValue, 'new' ) );
				}
				values.appendChild( chip );
			} );
			var head = el( 'div', { 'class': 'psai-attr-head' }, [ el( 'strong', null, [ text( attr.name ) ] ), ' ', badge( info[ 0 ], info[ 1 ] ) ] );
			if ( attr.used_for_variations ) {
				head.appendChild( document.createTextNode( ' ' ) );
				head.appendChild( el( 'span', { 'class': 'description' }, [ S.forVariations ] ) );
			}
			list.appendChild( el( 'li', null, [ head, values ] ) );
		} );
		return el( 'div', { 'class': 'psai-section' }, [ el( 'h4', null, [ S.attributes ] ), list ] );
	};

	Panel.prototype.renderVariations = function ( preview ) {
		var variations = preview.variations || [];
		if ( ! variations.length ) {
			return null;
		}
		var tbody = el( 'tbody' );
		variations.forEach( function ( v ) {
			tbody.appendChild( el( 'tr', null, [
				el( 'th', { scope: 'row' }, [ text( v.label ) ] ),
				el( 'td', null, [ text( v.regular_price ) ] ),
				el( 'td', null, [ text( v.sale_price ) ] ),
				el( 'td', null, [ text( v.sku ) ] )
			] ) );
		} );
		return el( 'div', { 'class': 'psai-section' }, [
			el( 'h4', null, [ S.variations ] ),
			el( 'table', { 'class': 'widefat striped psai-table psai-variations' }, [
				el( 'thead', null, [ el( 'tr', null, [ th( S.colVariation ), th( S.colPrice ), th( S.colSale ), th( S.colSku ) ] ) ] ),
				tbody
			] )
		] );
	};

	Panel.prototype.renderNewItems = function ( preview ) {
		var items = preview.new_items || [];
		if ( ! items.length ) {
			return null;
		}
		var box = el( 'fieldset', { 'class': 'psai-section psai-new-items' }, [
			el( 'legend', null, [ el( 'h4', null, [ S.newItems ] ) ] ),
			el( 'p', { 'class': 'description' }, [ S.newItemsHint ] )
		] );
		items.forEach( function ( item, index ) {
			var id = 'psai-new-' + index;
			var input = el( 'input', { type: 'checkbox', id: id, 'data-confirm': text( item.id ) } );
			box.appendChild( el( 'p', null, [ el( 'label', { 'for': id }, [ input, ' ', text( item.label ) ] ) ] ) );
		} );
		return box;
	};

	Panel.prototype.renderPreview = function ( preview, notice ) {
		var self = this;
		clear( this.preview );
		clear( this.done );
		show( this.done, false );

		this.preview.appendChild( el( 'h3', null, [ S.previewTitle ] ) );
		this.preview.appendChild( el( 'p', { 'class': 'description' }, [ S.previewIntro ] ) );
		if ( notice ) {
			this.preview.appendChild( el( 'div', { 'class': 'notice notice-info inline' }, [ el( 'p', null, [ notice ] ) ] ) );
		}
		this.preview.appendChild( this.renderFields( preview ) );
		[ this.renderAttributes( preview ), this.renderVariations( preview ), this.renderNewItems( preview ) ].forEach( function ( section ) {
			if ( section ) {
				self.preview.appendChild( section );
			}
		} );

		if ( preview.warnings && preview.warnings.length ) {
			var warnings = el( 'ul', { 'class': 'psai-warnings' } );
			preview.warnings.forEach( function ( w ) {
				warnings.appendChild( el( 'li', null, [ text( w ) ] ) );
			} );
			this.preview.appendChild( el( 'div', { 'class': 'psai-section' }, [ el( 'h4', null, [ S.warnings ] ), warnings ] ) );
		}

		var usage = preview.usage || {};
		this.preview.appendChild( el( 'p', { 'class': 'description psai-usage' }, [ fmt( S.usage, text( usage.model ), text( usage.input_tokens ), text( usage.output_tokens ) ) ] ) );

		var apply = el( 'button', { type: 'button', 'class': 'button button-primary psai-apply' }, [ S.applySelected ] );
		var cancel = el( 'button', { type: 'button', 'class': 'button psai-cancel' }, [ S.cancel ] );
		apply.addEventListener( 'click', function () {
			self.onApply();
		} );
		cancel.addEventListener( 'click', function () {
			self.token = '';
			clear( self.preview );
			show( self.preview, false );
			self.clearError();
			self.generate.focus();
		} );
		this.preview.appendChild( el( 'p', { 'class': 'psai-actions' }, [ apply, ' ', cancel ] ) );
		show( this.preview, true );
		this.preview.setAttribute( 'tabindex', '-1' );
		this.preview.focus();
	};

	Panel.prototype.onApply = function () {
		var self = this;
		if ( this.busy || '' === this.token ) {
			return;
		}
		this.clearError();
		var body = new window.FormData();
		body.append( 'action', 'product_sheet_ai_apply' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'product_id', String( this.productId ) );
		body.append( 'token', this.token );
		var fieldCount = 0;
		Array.prototype.forEach.call( this.preview.querySelectorAll( 'input[data-key]' ), function ( box ) {
			if ( box.checked && ! box.disabled ) {
				body.append( 'fields[]', box.getAttribute( 'data-key' ) );
				fieldCount++;
			}
		} );
		Array.prototype.forEach.call( this.preview.querySelectorAll( 'input[data-confirm]' ), function ( box ) {
			if ( box.checked ) {
				body.append( 'confirm[]', box.getAttribute( 'data-confirm' ) );
			}
		} );
		if ( 0 === fieldCount ) {
			this.showError( S.nothingSelected, '' );
			return;
		}
		if ( this.productId > 0 && ! window.confirm( S.confirmApply ) ) {
			return;
		}
		this.setBusy( true, S.applying );
		this.post( body ).then( function ( data ) {
			self.onApplied( data );
		} ).catch( function ( err ) {
			self.fail( err );
		} );
	};

	Panel.prototype.onApplied = function ( data ) {
		var notes = Array.isArray( data.notes ) ? data.notes : [];
		if ( this.productId > 0 ) {
			try {
				window.sessionStorage.setItem( cfg.appliedKey, JSON.stringify( { notes: notes } ) );
			} catch ( e ) {
				// Storage can be blocked. The page still reloads, just without the notice.
			}
			window.onbeforeunload = null;
			if ( window.jQuery ) {
				window.jQuery( window ).off( 'beforeunload.edit-post' );
			}
			window.location.reload();
			return;
		}
		this.setBusy( false, '' );
		clear( this.preview );
		show( this.preview, false );
		show( this.form, false );
		clear( this.done );
		this.done.appendChild( el( 'div', { 'class': 'notice notice-success inline' }, [ el( 'p', null, [ el( 'strong', null, [ S.draftCreated ] ) ] ) ] ) );
		var url = safeUrl( data.edit_url );
		var links = el( 'p', { 'class': 'psai-actions' } );
		if ( url ) {
			links.appendChild( el( 'a', { 'class': 'button button-primary', href: url }, [ S.editDraft ] ) );
			links.appendChild( document.createTextNode( ' ' ) );
		}
		var again = el( 'button', { type: 'button', 'class': 'button' }, [ S.addAnother ] );
		var self = this;
		again.addEventListener( 'click', function () {
			self.token = '';
			self.textarea.value = '';
			if ( self.file ) {
				self.file.value = '';
			}
			show( self.done, false );
			show( self.form, true );
			self.textarea.focus();
		} );
		links.appendChild( again );
		this.done.appendChild( links );
		if ( notes.length ) {
			var list = el( 'ul', { 'class': 'psai-notes' } );
			notes.forEach( function ( note ) {
				list.appendChild( el( 'li', null, [ text( note ) ] ) );
			} );
			this.done.appendChild( el( 'div', { 'class': 'psai-section' }, [ el( 'h4', null, [ S.notes ] ), list ] ) );
		}
		show( this.done, true );
	};

	function init() {
		showAppliedNotice();
		Array.prototype.forEach.call( document.querySelectorAll( '.product-sheet-ai-panel' ), function ( root ) {
			new Panel( root );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
