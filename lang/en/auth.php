<?php

declare(strict_types=1);

/*
| Success messages for the ADR-009 `message` field.
|
| These are user-facing confirmations, not error text — errors live in
| errors.php and are keyed by BaseException::translationKey().
|
| Clients branch on `code`, never on `message`. These strings may change.
*/

return [

    'signed_in' => 'Signed in successfully.',
    'signed_out' => 'Signed out.',
    'profile_updated' => 'Your profile has been updated.',
    'registered' => 'Your account has been created.',

    /*
    | Deliberately non-committal (ADR-025). The same sentence is returned
    | whether or not the account exists — the wording must not imply that a
    | message was actually sent.
    */
    'password_reset_requested' => 'If an account exists for this email address, password reset instructions have been sent.',
    'password_reset' => 'Your password has been reset. Please sign in with your new password.',
    'password_changed' => 'Your password has been changed.',

    // Mail bodies — the only place a reset token is ever exposed.
    'mail' => [
        'greeting' => 'Hello :name,',
        'reset_subject' => 'Reset your password',
        'reset_intro' => 'You are receiving this email because we received a password reset request for your account.',
        'reset_button' => 'Reset password',
        'reset_expiry' => 'This link expires in :minutes minutes and can be used once.',
        'reset_ignore' => 'If you did not request a password reset, no action is required — your password has not changed.',
        'changed_subject' => 'Your password was changed',
        'changed_intro' => 'Your password was just changed.',
        'changed_via_reset' => 'Your password was just reset using a password reset link.',
        'changed_sessions_revoked' => 'For your security, you have been signed out everywhere.',
        'changed_warning' => 'If this was not you, contact support immediately — someone else may have access to your account.',
        'verify_subject' => 'Verify your email address',
        'verify_intro' => 'Please confirm your email address to finish setting up your account.',
        'verify_button' => 'Verify email address',
        'verify_expiry' => 'This link expires in :minutes minutes.',
        'verify_ignore' => 'If you did not create an account, no further action is required.',
        'otp_subject' => 'Your sign-in code',
        'otp_intro' => 'Use this one-time code to finish signing in:',
        'otp_expiry' => 'The code expires in :minutes minutes and can be used once.',
        'otp_ignore' => 'If you did not try to sign in, someone may have your password — change it now.',
        // Suspicious-login alert to the account owner (Q6).
        'suspicious_subject' => 'Unusual sign-in activity on your account',
        'suspicious_intro' => 'We detected a number of failed sign-in attempts on your account.',
        'suspicious_action' => 'If this was not you, change your password now and enable two-factor authentication if you have not already.',
        'suspicious_reassure' => 'These attempts did not succeed. Your password still works and has not been changed.',
        // Security alert to administrators (Q6).
        'admin_alert_subject' => 'Security alert: account under attack',
        'admin_alert_intro' => 'A sustained sign-in attack was detected against an account.',
        'admin_alert_detail' => 'Address: :email — attempts: :count from :ips distinct IP addresses.',
        'admin_alert_action' => 'Review the security audit trail for the full picture.',
    ],

    'email_verified' => 'Your email address has been verified.',
    'verification_sent' => 'A verification link has been sent.',

    // Admin surface (Phase 8).
    'user_updated' => 'The account has been updated.',
    'admin_reset_sent' => 'A password reset link has been sent to the user.',
    'admin_two_factor_disabled' => 'Two-factor authentication has been disabled for the account.',

    'sessions_revoked' => 'Other sessions have been signed out.',
    'device_trusted' => 'This device has been marked as trusted.',

    'two_factor_enabled' => 'Two-factor authentication is now enabled.',
    'two_factor_disabled' => 'Two-factor authentication has been disabled.',
    'two_factor_enrolment_started' => 'Scan the QR code with your authenticator, then confirm with a code.',
    'recovery_codes_generated' => 'New recovery codes generated. Store them now — they are shown once.',
    'email_otp_sent' => 'If a code was needed, it has been sent to your email.',

];
