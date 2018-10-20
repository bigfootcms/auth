<?php

//$auth->admin()->addRoleForUserById($auth->getUserID(), \Delight\Auth\Role::EDITOR);
//		$uuid = ( !empty($_SESSION['uuid']) ) ? $_SESSION['uuid'] : $_SESSION['uuid'] = Delight\Auth\Auth::createUuid();

// Initialize hook system as needed.
if ( !Base::instance()->get("HOOKS") ) {
	Base::instance()->set("HOOKS", new Hooks());
}

/* If content in the database is assigned the "protected"="Y" attribute, we want to route to the /login if not already logged in. */
Base::instance()->get("HOOKS")->add_action('security', function(){
	Base::instance()->set("auth", new Delight\Auth\Auth(Bigfoot::CMS()->DB()->pdo()));
	Base::instance()->set("PLUGIN", dirname(str_replace(Base::instance()->get("ROOT"), "", __FILE__)).'/');
	
	if ( Bigfoot::CMS()->isContentProtected() ) {
		if ( !Base::instance()->get("auth")->isLoggedIn() ) {
			$_SESSION['next_page'] = Base::instance()->get("VPATH");
			Base::instance()->reroute('../login');
			exit;
		}
	}
});
	
Base::instance()->route('GET /login', function($f3) {
	$plugin = (object) []; // Pull from config pages.
	
	/* LOGIN PAGE */
	$f3->set("page_title", "Sign in");
	if ( Base::instance()->get("auth")->isLoggedIn() ) {
		echo "Already logged in.";
		return;
	}
	$template = ( file_exists($f3->get('ROOT') . '/' . $plugin->pages.'/login.html') )
		? $f3->get('ROOT') . '/' . $plugin->pages.'/login.html'
		: __DIR__.'/pages/login.html';
		
	echo Template::instance()->resolve(file_get_contents($template));
}, 0, 64); // Serve the GET requests to /login at a maximum rate of 64 KiB/s (64 * 1024 bytes per second

/* LOGOUT */
Base::instance()->route('GET /logout', function($f3) {
	$f3->set("page_title", "Sign out");
	if ( Base::instance()->get("auth")->isLoggedIn() ) {
		Base::instance()->get("auth")->logout();
	}
	$f3->reroute('../');
}, 0, 64);


/* PAGE: Confirm email */
Base::instance()->route('GET /comfirmEmail', function($f3) {
	$f3->set("page_title", "Account verification");
	echo Template::instance()->render($plugin->pages.'/verify.html');
}, 0, 64);

/* If not already verified, the page will provide a JS mechanism for allowing user to resend the confirmation. Ajax-only to avoid bots in most situations. */
Base::instance()->route('POST /resendConfirmationForEmail [ajax]', function($f3) {
	$response = (object) array();
	try {
		Base::instance()->get("auth")->resendConfirmationForEmail($f3->get('POST.email'), function($selector, $token) {
			// send `$selector` and `$token` to the user (e.g. via email)
			$response->status = "ok";
			$response->resentConfirmationForEmail = true;
			$response->msg = "Verification request sent to ".$f3->get('POST.email').". Be sure to check your spam or junk email folder. You may need to wait a few minutes for the email to arrive.";
		});
		/* the user may now respond to the confirmation request (usually by clicking a link) */
	} catch (\Delight\Auth\ConfirmationRequestNotFound $e) {
		$response->status = "error";
		$response->msg = "No earlier request found that could be re-sent";
		$response->msg .= Base::instance()->get("auth")->getEmail() . " vs "  . $f3->get('POST.email');
	} catch (\Delight\Auth\TooManyRequestsException $e) {
		// there have been too many requests -- try again later
		$response->status = "error";
		$response->msg = "There have been too many requests -- try again later";
	}
	echo json_encode($response, true);
	exit;
}, 0, 64);

Base::instance()->route('POST /authenticate [ajax]', function($f3) {
	if (Base::instance()->get("auth")->isLoggedIn()) {
		Base::instance()->get("auth")->logout();
	}
	$response = (object) array();
	
	// Would be wise to sprinkle in some password formatting hints... 
	if ( $f3->get('POST.password') == "" ) {
		$response->errorForPassword = "Password cannot be empty";
	}
	try {
		$rememberDuration = ( $f3->get('POST.remember') == 1 ) ? (int) (60 * 60 * 24 * 365.25) : null;
		Base::instance()->get("auth")->login($f3->get('POST.email'), $f3->get('POST.password'), $rememberDuration);
		$response->status = "ok";
		$response->authenticated = true;
		
		if ( !empty($_SESSION["next_page"]) ) {
			$response->next_page = $_SESSION["next_page"];
		}
		
		$response->msg = "Thank you. Just a moment.";
	} catch (\Delight\Auth\InvalidEmailException $e) {
		$response->status = "error";
		$response->authenticated = false;
		$response->msg = "Authentication failed.";
		$response->errorForEmail = (filter_var($f3->get('POST.email'), FILTER_VALIDATE_EMAIL))
			? "No account by that email address"
			: "Invalid email address";
	} catch (\Delight\Auth\InvalidPasswordException $e) {
		$response->status = "error";
		$response->authenticated = false;
		$response->msg = "Invalid password";
	} catch (\Delight\Auth\EmailNotVerifiedException $e) {
		$response->status = "error";
		$response->authenticated = false;
		$response->verification = true;
		$response->msg = "Your email has not been verified. Please check your spam directory. <a id=\"resendConfirmationForEmail\" href=\"#\">Resend verification?</a>";
	} catch (\Delight\Auth\TooManyRequestsException $e) {
		$response->status = "error";
		$response->authenticated = false;
		$response->msg = "Too many requests. Now throttling attempts.";
	}
	echo json_encode($response, true);
	exit;
}, 0, 64);

Base::instance()->route('GET /verify', function($f3) {
	try {
		$auth->confirmEmail($f3->get('GET.selector'), $f3->get('GET.token'));
		echo "Email address has been verified. You may now <a href=\"login\">sign-in</a>.";
	} catch (\Delight\Auth\InvalidSelectorTokenPairException $e) {
		echo "Invalid token";
	} catch (\Delight\Auth\TokenExpiredException $e) {
		echo "Token has expired.";
	} catch (\Delight\Auth\UserAlreadyExistsException $e) {
		echo "Email address already exists."; // Huh?
	} catch (\Delight\Auth\TooManyRequestsException $e) {
		echo "Too many requests.";
	}
	exit();
}, 0,  64);

Base::instance()->route('POST /register [ajax]', function($f3) {
	if (Base::instance()->get("auth")->isLoggedIn()) {
		$f3->reroute('../');// WHAT THE HECK? I must have been tired. Lazy right now, fix later.
	}
	$response = (object) array();
	try {
		$userId = Base::instance()->get("auth")->register($f3->get('POST.email'), $f3->get('POST.password'), $f3->get('POST.username'), function ($selector, $token) {
			$url = $f3->get("SCHEME").'://'.$f3->get("HOST").$f3->get("BASE").'/verify?selector='.urlencode($selector).'&token='.urlencode($token);
			$message  = "Hi.\r\n\r\n";
			$message .= "Before we may create your account we need you to verify your email by clicking the following link.\r\n";
			$message .= "$url";

			// In case any of our lines are larger than 70 characters, we should use wordwrap()
			$message = wordwrap($message, 70, "\r\n");

			$f3->set("message", $message);
			
			//$inliner = new Northys\CSSInliner\CSSInliner;
			// $inliner->addCSS(__DIR__ . '/example.css');
			//	$emailTemplate = $inliner->render( // CSS Inliner tool
			$emailTemplate =	Template::instance()->render($plugin->pages.'/confirm_email.html'); // Fat Free Framework template rendering engine.
			//);

			$mail = new PHPMailer\PHPMailer\PHPMailer(); // Why did they change this after ~17+ years?
			$mail->isSendmail();
			$mail->setFrom('no-reply@example.com', addslashes("Generic Website Name"));
			$mail->addAddress($f3->get('POST.email'));
			$mail->Subject = 'Please confirm your registration.';
			$mail->msgHTML($emailTemplate);
			$mail->AltBody = 'Alternate plain-text only messege: '.$f3->get("message"); //TODO: Use DOM to parse out plain-text, then use here.
		});

		$response->status = "ok";
		$response->registered = true;
		$response->msg = "Please check your email for the verification link. Clicking that link will confirm your account which will then let you sign in. Please allow up to 15 minutes for this email to arrive and also check your spam folder.";
	
	} catch (\Delight\Auth\InvalidEmailException $e) {
		$response->status = "error";
		$response->authenticated = false;
		$response->msg = "Invalid email address.";
		if ( !filter_var($f3->get('POST.email'), FILTER_VALIDATE_EMAIL) ) {
			$response->errorForEmail = "Invalid email address";
		}
	} catch (\Delight\Auth\InvalidPasswordException $e) {
		$response->status = "error";
		$response->authenticated = false;
		$response->msg = "Invalid password. **** TODO: Password rules go here ****";
		$response->errorForPassword = "Invalid password";
	} catch (\Delight\Auth\UserAlreadyExistsException $e) {
		$response->status = "error";
		$response->authenticated = false;
		$response->msg = "User already exists.";
	} catch (\Delight\Auth\TooManyRequestsException $e) {
		$response->status = "error";
		$response->authenticated = false;
		$response->msg = "Too many requests. Now throttling attempts.";
	}
	echo json_encode($response, true);
	exit;
}, 0, 64);

//$template = ( file_exists($f3->get('ROOT') . '/' . $plugin->pages.'/login.html') )
//			? $f3->get('ROOT') . '/' . $plugin->pages.'/login.html'
//			: __DIR__.'/pages/login.html';
			
Base::instance()->route('GET /register', function($f3) {
	$f3->set("page_title", "My Account");
	echo (Base::instance()->get("auth")->isLoggedIn())
		? Template::instance()->render(__DIR__.'/pages/my_account.html')
		: Template::instance()->render(__DIR__.'/pages/register.html');
}, 0, 64);

Base::instance()->route('GET /reset_password', function($f3) {
	$f3->set("page_title", "Account Recovery");
	echo ($auth->isLoggedIn())
		? Template::instance()->render($pluginBase.'/already_signed_in.html')
		: Template::instance()->render($pluginBase.'/reset.html');
}, 0, 64);

Base::instance()->route('GET /new_password', function($f3) {
	$f3->set("page_title", "Change Password");
	if (Base::instance()->get("auth")->isLoggedIn()) {
		echo Template::instance()->render($plugin->pages.'/already_signed_in.html');
	} else {
		if ( $f3->get('GET.selector') != "" &&  $f3->get('GET.token') != "" ) {			
			$f3->set('selector', $f3->get('GET.selector'));
			$f3->set('token', $f3->get('GET.token'));
			echo Template::instance()->render($plugin->pages.'/change_password.html');
		} else {
			echo Template::instance()->render($plugin->pages.'/new_password.html');
		}
	}
}, 0 , 64);

Base::instance()->route('POST /set_password [ajax]', function($f3) {
	$response = (object) array();
	try {
		Base::instance()->get("auth")->resetPassword($_POST['selector'], $_POST['token'], $_POST['password']);
		// password has been reset
		$response->status = "ok";
		$response->passwordUpdated = true;
		$response->msg = "Password has been reset. You may now login.";
		
	} catch (\Delight\Auth\InvalidSelectorTokenPairException $e) {
		// invalid token
		$response->status = "error";
		$response->passwordUpdated = false;
		$response->msg = "Invalid Token";
		
	} catch (\Delight\Auth\TokenExpiredException $e) {
		// token expired
		$response->status = "error";
		$response->passwordUpdated = false;
		$response->msg = "The link used is no longer valid. You will need to start the password reset over to continue.";
		
	} catch (\Delight\Auth\ResetDisabledException $e) {
		// password reset is disabled
		
	} catch (\Delight\Auth\InvalidPasswordException $e) {
		// invalid password
		$response->status = "error";
		$response->passwordUpdated = false;
		$response->msg = "Invalid password. Try something better.";
		
	} catch (\Delight\Auth\TooManyRequestsException $e) {
		// too many requests
		$response->status = "error";
		$response->passwordUpdated = false;
		$response->msg = "Too many requests. Try again later.";
	}
	echo json_encode($response, true);
	exit;
}, 0, 64);

Base::instance()->route('GET /reset_password', function($f3) {
	$f3->set("page_title", "Password Reset");
	echo "There is no password reset form yet. (coming soon)";
}, 0, 64);

Base::instance()->route('POST /reset_password', function($f3) {
	$response = (object) array();
	try {
		Base::instance()->get("auth")->forgotPassword($f3->get('POST.email'), function($selector, $token) {
			// send `$selector` and `$token` to the user (e.g. via email)
		
			$url = $f3->get('SCHEME').'://'.$f3->get('HOST').$f3->get('BASE').'/new_password?selector='.urlencode($selector).'&token='.urlencode($token);
			$message  = "Hi.\r\n\r\n";
			$message .= "A request was made to reset the password on your account. Please click the following link to authorize a password change. Ignoring this email will effectively prevent this from happening.\r\n";
			$message .= "$url";

			// In case any of our lines are larger than 70 characters, we should use wordwrap()
			$message = wordwrap($message, 70, "\r\n");

			// Send
			mail($f3->get('POST.email'), 'Password Reset Confirmation', $message);
		});
		// request has been generated
		$response->status = "ok";
		$response->resetAuthorized = true; // Trigger ajax to provide a new form prompting for new password.
		$response->msg = "Your request to change your password has been authorized. You may now create a new password.";
	} catch (\Delight\Auth\InvalidEmailException $e) {
		// invalid email address
		$response->status = "error";
		$response->resetAuthorized = false;
		$response->msg = "This step needs a valid email address already in our records so we can send a confirmation link.";
		$response->errorForEmail = "Invalid email address";		
	} catch (\Delight\Auth\EmailNotVerifiedException $e) {
		// email not verified
		$response->status = "error";
		$response->resetAuthorized = false;
		$response->msg = "Your email address has not been verified so no reset option is available. Please use the sign-in to trigger the confirm prompt.";	
	} catch (\Delight\Auth\ResetDisabledException $e) {
		// password reset is disabled
		$response->status = "error";
		$response->resetAuthorized = false;
		$response->msg = "The password reset capability has been disabled by the System Administrator for this website. Please use the contact us through our Contact Us page.";
	} catch (\Delight\Auth\TooManyRequestsException $e) {
		// too many requests
		$response->status = "error";
		$response->resetAuthorized = false;
		$response->msg = "Too many requests. Please try again later.";
	}
	echo json_encode($response, true);
	exit();
}, 0, 64);