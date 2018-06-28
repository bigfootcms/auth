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
		$('form').attr('action', '/register');
		$('#password').attr('required', 'require');
		$(this).html("Please wait..").parent().addClass("loader");
		
		var formData = $('form').serialize();
		$.ajax({
			method: "POST"
			, url: "register"
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
					if ( data.registered === true ) {
						$('form > input').each(function() { $(this).attr('disabled', 'disabled'); });
						$('#msg').html('<h3>Success</h3>'+data.msg);
						$('#submit').html(" (sent) ").attr("disabled", "disabled");
						// No redirection here... we wait for user to confirm email. 
					}
				}
				if ( typeof data.resentConfirmationForEmail != 'undefined' && data.resentConfirmationForEmail === true ) {

				}
			}, error: function (jqXHR, textStatus, errorThrown) {
				alert( "Data Error: " + data.msg );
			}
		});
		
		var anyFormInput = $('input');
		$("input").focusin(function() { removeInputError(anyFormInput); $('#submit').html("Register"); });
		
		//e.stopPropagation();
		//return false;
	});
	$('input[name=password]').keypress(function(e){ if (e.which == 13){ $('#submit').click(); } });
});