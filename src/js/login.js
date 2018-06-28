$(document).ready(function() {
	$('form:first *:input[type!=hidden]:first').focus();
	var throwInputError = function(selector, msg) {
		$(selector).parent().addClass("error").hide().append('<small class="error">'+msg+'</small>').slideDown("fast");
	}
	var removeInputError = function(selector) {
		$(selector).next().remove();
		$(selector).parent().removeClass("error");
	}
    $('form > input').keyup(function() {
        var empty = false;
		$('form > input').each(function() { if ($(this).val() == '') { empty = true; } });
        if (empty) { $('#submit').attr('disabled', 'disabled'); } else { $('#submit').removeAttr('disabled'); }
		removeInputError(this);
    });

	$('#submit').on('click', function(e) {
		removeInputError($('input'));
		$('form').attr('action', '/authenticate');
		$('#password').attr('required', 'require');
		$(this).html("Please wait..").parent().addClass("loader");
		
		var formData = $('form').serialize();
		$.ajax({
			method: "POST"
			, url: "authenticate"
			, data: formData
			, dataType: 'json'
			, success: function(data, textStatus, jqXHR) {
				if ( typeof data.msg != 'undefined' ) {
					$('#msg').html('<h3>Error</h3>'+data.msg);
				}
				if ( data.status == "error" ) {
					if ( typeof data.errorForEmail != 'undefined' ) {
						var selected = $("input[name=email]");
						throwInputError(selected, data.errorForEmail);
					}
					if ( typeof data.errorForPassword != 'undefined') {
						var selected = $("input[name=password]");
						throwInputError(selected, data.errorForPassword);
					}
				}
				if ( data.status == "ok" ) {
					if ( data.authenticated === true ) {
						$('form > input').each(function() { $(this).attr('disabled', 'disabled'); });
						if ( data.unverified === true ) {
							$('#submit').html("Whoops");
						} else {
							$('#msg').html('<h3>Success</h3>'+data.msg);
							$('#submit').html("Redirecting...");
							window.location.href = './';
						}
					}
				}
				if ( typeof data.resentConfirmationForEmail != 'undefined' && data.resentConfirmationForEmail === true ) {

				}
			}, error: function (jqXHR, textStatus, errorThrown) {
				alert( "Data Error: " + data.msg );
			}
		});
		
		var anyFormInput = $('input');
		$("input").focusin(function() { removeInputError(anyFormInput); $('#submit').html("Login"); });
		
	});
	$('input[name=password]').keypress(function(e){ if (e.which == 13){ $('#submit').click(); } });

	$("#resendConfirmationForEmail").on('click', function(e) {
		var email = $('input[name=email]').val();
		$.ajax({
			method: "POST"
			, url: "resendConfirmationForEmail"
			, data: { email: email }
			, dataType: 'json'
			, success: function(data, textStatus, jqXHR) {
				if ( data.status == 'error' ) {
					$('#msg').html('<h3>Error</h3>'+data.msg);
				}
				if ( data.status == 'ok' ) {
					$('#msg').html(data.msg);
				}
			}, error: function (jqXHR, textStatus, errorThrown) {
				alert( "Data Error: " + data.msg );
			}
			
		});
		e.stopPropagation();
		return false;
	});	
});