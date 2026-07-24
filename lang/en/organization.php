<?php

declare(strict_types=1);

/*
| Organization module strings.
|
| @see App\Modules\Organization
*/

return [

    'registered' => 'Your organization has been registered and is pending review.',
    'kyc_submitted' => 'Your company details have been submitted for review.',
    'ownership_transferred' => 'Ownership has been transferred.',
    'invitation_sent' => 'The invitation has been sent.',
    'invitation_accepted' => 'You have joined the organization.',
    'invitation_rejected' => 'The invitation has been declined.',
    'document_uploaded' => 'The document has been uploaded and is pending review.',
    'approved' => 'The organization has been approved.',
    'rejected' => 'The organization has been rejected.',
    'suspended' => 'The organization has been suspended.',
    'reinstated' => 'The organization has been reinstated.',
    'store_request_approved' => 'The store opening request has been approved.',
    'store_request_rejected' => 'The store opening request has been rejected.',

    // Filament labels.
    'singular' => 'Organization',
    'legal_name' => 'Legal name',
    'plan' => 'Plan',
    'action' => [
        'approve' => 'Approve',
        'reject' => 'Reject',
        'suspend' => 'Suspend',
        'reinstate' => 'Reinstate',
        'reason' => 'Reason',
    ],
    'store_request' => [
        'singular' => 'Store opening request',
        'name' => 'Store name',
    ],

    'invitation' => [
        'subject' => 'You have been invited to join :organization',
        'intro' => ':organization has invited you to join as :role.',
        'action' => 'Accept invitation',
        'expiry' => 'This invitation will expire. Accept it while it is still valid.',
        'no_account' => "If you don't have an account yet, you'll be asked to create one first, then the invitation will complete.",
    ],

];
