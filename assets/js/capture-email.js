/**
 * Classic checkout: sends a guest's billing email (and first name) to YayMatik when they leave the field,
 * so a cart that never becomes an order can still get a reminder. Each email + name pair is sent once.
 */
( function () {
	var config = window.yayMatikCapture;
	if ( ! config || ! config.url || ! window.fetch ) {
		return;
	}
	var sent = {};
	var looksLikeEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	function send( event ) {
		if ( ! event.target || event.target.id !== 'billing_email' ) {
			return;
		}
		var email = event.target.value.trim();
		var nameField = document.getElementById( 'billing_first_name' );
		var firstName = nameField ? nameField.value.trim() : '';
		var key = email + '\n' + firstName;
		if ( ! looksLikeEmail.test( email ) || sent[ key ] ) {
			return;
		}
		sent[ key ] = true;
		window.fetch( config.url, {
			method: 'POST',
			credentials: 'same-origin',
			body: new URLSearchParams( { email: email, first_name: firstName } ),
		} ).catch( function () {} );
	}

	// Delegated: checkout scripts may re-render the billing fields. `blur` doesn't bubble, `focusout` does.
	document.addEventListener( 'change', send );
	document.addEventListener( 'focusout', send );
} )();
