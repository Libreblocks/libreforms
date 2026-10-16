/**
 * LibreForms front end: conditional fields, inline validation and AJAX submit.
 * Without this script the form still works (regular POST through admin-post.php).
 */
( function () {
	'use strict';

	var data = window.libreformsData || { endpoint: '', nonce: '', i18n: {} };

	/* ------------------------------------------------------------ values */

	function valueOf( form, name ) {
		var inputs = form.querySelectorAll( '[name="' + name + '"], [name="' + name + '[]"]' );
		var values = [];
		var multiple = false;

		Array.prototype.forEach.call( inputs, function ( input ) {
			if ( input.name.slice( -2 ) === '[]' || input.multiple ) {
				multiple = true;
			}

			if ( input.type === 'checkbox' || input.type === 'radio' ) {
				if ( input.checked ) {
					values.push( input.value );
				}
				return;
			}

			if ( input.tagName === 'SELECT' && input.multiple ) {
				Array.prototype.forEach.call( input.selectedOptions, function ( option ) {
					values.push( option.value );
				} );
				return;
			}

			values.push( input.value );
		} );

		// A single switch: true / false, like on the server.
		if ( inputs.length === 1 && inputs[ 0 ].type === 'checkbox' && ! multiple ) {
			return inputs[ 0 ].checked;
		}

		return multiple ? values : ( values[ 0 ] || '' );
	}

	function blank( value ) {
		return value === null || value === undefined || value === '' || value === false || ( Array.isArray( value ) && ! value.length );
	}

	function equals( actual, expected ) {
		if ( typeof actual === 'boolean' ) {
			return actual === [ '1', 'true', 'yes', 'on' ].indexOf( String( expected ).toLowerCase() ) > -1;
		}

		if ( Array.isArray( actual ) ) {
			return actual.indexOf( String( expected ) ) > -1;
		}

		return String( actual ) === String( expected );
	}

	function list( value ) {
		return Array.isArray( value ) ? value.map( String ) : String( value ).split( ',' ).map( function ( item ) {
			return item.trim();
		} );
	}

	function intersects( actual, expected ) {
		var wanted = list( expected );

		return [].concat( actual ).map( String ).some( function ( item ) {
			return wanted.indexOf( item ) > -1;
		} );
	}

	/* Same rules as LibreBlocks\Core\Fields\Conditions. */
	function rule( form, r ) {
		var actual = valueOf( form, r.field || '' );
		var expected = r.value;
		var number = parseFloat( actual );

		switch ( r.operator || '=' ) {
			case '!=': return ! equals( actual, expected );
			case 'in': return intersects( actual, expected );
			case 'not_in': return ! intersects( actual, expected );
			case 'contains': return Array.isArray( actual ) ? actual.indexOf( String( expected ) ) > -1 : String( actual ).indexOf( String( expected ) ) > -1;
			case 'empty': return blank( actual );
			case 'not_empty': return ! blank( actual );
			case '>': return ! isNaN( number ) && number > parseFloat( expected );
			case '>=': return ! isNaN( number ) && number >= parseFloat( expected );
			case '<': return ! isNaN( number ) && number < parseFloat( expected );
			case '<=': return ! isNaN( number ) && number <= parseFloat( expected );
			default: return equals( actual, expected );
		}
	}

	function matches( form, conditions ) {
		var rules = ( conditions && conditions.rules ) || [];
		var any = String( conditions && conditions.relation ).toUpperCase() === 'OR';

		if ( ! rules.length ) {
			return true;
		}

		return any ? rules.some( function ( r ) {
			return rule( form, r );
		} ) : rules.every( function ( r ) {
			return rule( form, r );
		} );
	}

	function applyConditions( form ) {
		Array.prototype.forEach.call( form.querySelectorAll( '[data-conditions]' ), function ( field ) {
			var conditions;

			try {
				conditions = JSON.parse( field.getAttribute( 'data-conditions' ) );
			} catch ( e ) {
				return;
			}

			var visible = matches( form, conditions );

			field.hidden = ! visible;
			// Hidden fields are not sent, so they can't block the submit.
			Array.prototype.forEach.call( field.querySelectorAll( 'input, select, textarea' ), function ( input ) {
				input.disabled = ! visible;
			} );
		} );
	}

	/* ------------------------------------------------------------ errors */

	function setError( field, message ) {
		var box = field.querySelector( '.lbf-error' );

		field.classList.toggle( 'has-error', !! message );
		Array.prototype.forEach.call( field.querySelectorAll( 'input, select, textarea' ), function ( input ) {
			if ( message ) {
				input.setAttribute( 'aria-invalid', 'true' );
			} else {
				input.removeAttribute( 'aria-invalid' );
			}
		} );

		if ( box ) {
			box.textContent = message || '';
			box.hidden = ! message;
		}
	}

	function clearErrors( form ) {
		Array.prototype.forEach.call( form.querySelectorAll( '.lbf-field' ), function ( field ) {
			setError( field, '' );
		} );
	}

	function validate( form ) {
		var first = null;

		Array.prototype.forEach.call( form.querySelectorAll( '.lbf-field[data-name]' ), function ( field ) {
			if ( field.hidden ) {
				return;
			}

			var problem = '';

			Array.prototype.some.call( field.querySelectorAll( 'input, select, textarea' ), function ( input ) {
				if ( ! input.disabled && input.willValidate && ! input.checkValidity() ) {
					problem = input.validity.valueMissing ? ( data.i18n.required || input.validationMessage ) : input.validationMessage;
					return true;
				}
				return false;
			} );

			setError( field, problem );

			if ( problem && ! first ) {
				first = field;
			}
		} );

		if ( first ) {
			var control = first.querySelector( 'input, select, textarea' );

			if ( control ) {
				control.focus();
			}
		}

		return ! first;
	}

	function showMessage( form, type, text ) {
		var area = form.querySelector( '.lbf-message-area' );

		if ( ! area ) {
			return;
		}

		area.innerHTML = '';

		var box = document.createElement( 'div' );

		box.className = 'lbf-message lbf-message--' + type;
		box.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
		box.textContent = text;
		area.appendChild( box );
	}

	/* ------------------------------------------------------------ submit */

	function submit( form, event ) {
		event.preventDefault();
		clearErrors( form );

		if ( ! validate( form ) ) {
			return;
		}

		var button = form.querySelector( '.lbf-submit' );
		var label = button ? button.textContent : '';
		var headers = {};

		if ( data.nonce ) {
			headers[ 'X-WP-Nonce' ] = data.nonce;
		}

		form.classList.add( 'is-sending' );

		if ( button ) {
			button.disabled = true;
			button.textContent = data.i18n.sending || label;
		}

		fetch( data.endpoint + form.getAttribute( 'data-libreforms' ) + '/submit', {
			method: 'POST',
			body: new FormData( form ),
			headers: headers,
			credentials: 'same-origin',
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return {};
				} );
			} )
			.then( function ( result ) {
				if ( ! result || typeof result.success !== 'boolean' ) {
					throw new Error( ( result && result.message ) || data.i18n.error );
				}

				if ( result.success ) {
					if ( result.redirect ) {
						window.location.href = result.redirect;
						return;
					}

					form.dispatchEvent( new CustomEvent( 'libreforms:success', { bubbles: true, detail: result } ) );

					if ( form.hasAttribute( 'data-hide-on-success' ) ) {
						form.classList.add( 'lbf-form--done' );
						form.innerHTML = '';
						form.appendChild( document.createElement( 'div' ) ).className = 'lbf-message-area';
					} else {
						form.reset();
						applyConditions( form );
					}

					showMessage( form, 'success', result.message );
					return;
				}

				Object.keys( result.errors || {} ).forEach( function ( name ) {
					var field = form.querySelector( '.lbf-field[data-name="' + name + '"]' );

					if ( field ) {
						setError( field, result.errors[ name ] );
					}
				} );

				showMessage( form, 'error', result.message || data.i18n.error );
			} )
			.catch( function ( error ) {
				showMessage( form, 'error', ( error && error.message ) || data.i18n.error );
			} )
			.then( function () {
				form.classList.remove( 'is-sending' );

				if ( button && button.isConnected ) {
					button.disabled = false;
					button.textContent = label;
				}
			} );
	}

	function init( form ) {
		if ( form.__libreforms ) {
			return;
		}

		form.__libreforms = true;
		form.noValidate = true; // We show our own messages next to each field.

		applyConditions( form );
		form.addEventListener( 'change', function () {
			applyConditions( form );
		} );
		form.addEventListener( 'input', function ( event ) {
			applyConditions( form );

			var field = event.target.closest( '.lbf-field' );

			if ( field && field.classList.contains( 'has-error' ) ) {
				setError( field, '' );
			}
		} );
		form.addEventListener( 'submit', function ( event ) {
			if ( window.fetch && window.FormData && data.endpoint ) {
				submit( form, event );
			}
		} );
	}

	function boot() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-libreforms]' ), init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	// Elementor editor and popups add forms later.
	window.addEventListener( 'elementor/frontend/init', function () {
		if ( window.elementorFrontend && window.elementorFrontend.hooks ) {
			window.elementorFrontend.hooks.addAction( 'frontend/element_ready/global', boot );
		}
	} );
	document.addEventListener( 'elementor/popup/show', boot );

	window.libreforms = { init: boot };
}() );
