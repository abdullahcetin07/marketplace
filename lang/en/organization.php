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
    'plural' => 'Organizations',
    'legal_name' => 'Legal name',
    'display_name' => 'Trading name',
    'display_name_hint' => 'Left blank, the legal name is used.',
    'slug' => 'Short name',
    'slug_hint' => 'Letters, digits and dashes only. It cannot be changed later.',
    'country' => 'Country',
    'currency' => 'Currency',
    'status' => 'Status',
    'plan' => 'Plan',
    'action' => [
        'create' => 'Register your company',
        'approve' => 'Approve',
        'reject' => 'Reject',
        'suspend' => 'Suspend',
        'reinstate' => 'Reinstate',
        'reason' => 'Reason',
    ],
    'empty' => [
        'heading' => 'You have no company yet',
        'description' => 'Register your legal company to start selling. Once it is approved you can request a store.',
    ],
    'kyc' => [
        'title' => 'Company verification (KYC)',
        'action' => 'Submit company details',
        'national_id' => 'National id of the authorised person',
        'national_id_hint' => 'Stored encrypted and never shown again.',
        'national_id_on_file' => 'An id is already on file. Leave blank to keep it.',
        'metadata' => 'Country-specific details',
        'metadata_key' => 'Field',
        'metadata_value' => 'Value',
        'submitted_at' => 'Submitted at',
        'not_submitted' => 'Not submitted yet',
        'tax_number' => 'Tax number',
        'registration_number' => 'Registration number',
        'authorized_person_name' => 'Authorised person',
    ],
    'document' => [
        'plural' => 'Documents',
        'type' => 'Document type',
        'status' => 'Status',
        'file' => 'File',
        'file_hint' => 'PDF or image, up to 10 MB. Stored privately and shared only with the reviewing team.',
        'review_notes' => 'Reviewer notes',
        'uploaded_at' => 'Uploaded at',
        'action' => [
            'upload' => 'Upload a document',
            'view' => 'Open',
        ],
        'empty' => [
            'heading' => 'No documents yet',
            'description' => 'Upload your tax certificate, trade registry document and signature circular so the review can start.',
        ],
    ],
    'bank' => [
        'title' => 'Payout account',
        'action' => 'Set the payout account',
        'account_holder' => 'Account holder',
        'bank_name' => 'Bank',
        'iban' => 'IBAN',
        'iban_hint' => 'Stored encrypted. Only the last four digits are shown afterwards.',
        'iban_on_file' => 'Currently on file: :iban. Enter the IBAN again to replace it.',
        'currency' => 'Payout currency',
        'not_set' => 'Not set yet',
    ],
    'bank_account_updated' => 'Your payout account has been saved.',
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
