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
            'download' => 'Download',
            'approve' => 'Approve',
            'request_revision' => 'Request a revision',
            'reject' => 'Reject',
        ],
        'review' => [
            'notes' => 'Note for the seller',
            'notes_hint' => 'Shown to the seller. Say exactly what has to change.',
            'approved' => 'The document has been approved.',
            'needs_revision' => 'The document has been sent back for revision.',
            'rejected' => 'The document has been rejected.',
        ],
        'empty' => [
            'heading' => 'No documents yet',
            'description' => 'Upload your tax certificate, trade registry document and signature circular so the review can start.',
        ],
        'admin_empty' => [
            'heading' => 'This company has uploaded nothing yet',
            'description' => 'There is no evidence to review. Approving now means approving without documents.',
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

    /*
    | The admin review surface — what a reviewer reads before deciding. Every
    | encrypted field is shown masked: the point is verification, not exposure.
    */
    'review' => [
        'company' => 'Company',
        'kyc' => 'Company verification (KYC)',
        'bank' => 'Payout account',
        'owner' => 'Owner',
        'owner_email' => 'Owner email',
        'registered_at' => 'Registered at',
        'verified_at' => 'Verified at',
        'masked_hint' => 'Only the last four digits are shown; the value is encrypted at rest.',
        'not_provided' => 'Not provided',
        'pending_documents' => 'Documents awaiting review',
        'pending_documents_warning' => 'This company still has :count document(s) awaiting review. Review them first, or approve deliberately.',
    ],
    'store_request' => [
        'singular' => 'Store opening request',
        'plural' => 'Store opening requests',
        'name' => 'Store name',
        'slug' => 'Store address',
        'slug_hint' => 'Letters, digits and dashes only. Your store will be reachable at /store/this-address.',
        'organization_hint' => 'The company the store will trade under.',
        'description' => 'What will you sell?',
        'reason' => 'Note for the reviewer',
        'reason_hint' => 'Optional. Anything that helps the team understand the request.',
        'submitted_at' => 'Submitted at',
        'review_notes' => 'Reviewer notes',
        'created' => 'Draft saved. Submit it when you are ready for review.',
        'submitted' => 'Your request has been sent for review.',
        'submit_failed' => 'This request could not be submitted',
        'cancelled' => 'The request has been withdrawn.',
        'cancel_failed' => 'This request could not be withdrawn',
        'action' => [
            'create' => 'Request a store',
            'submit' => 'Send for review',
            'submit_confirm' => 'Once submitted, the request goes to the review team and can no longer be edited. You can still withdraw it.',
            'cancel' => 'Withdraw',
        ],
        'empty' => [
            'heading' => 'No store requests yet',
            'description' => 'Request a store to start selling. The review team approves it, and your store is created automatically.',
        ],
    ],

    /*
    | Team — the seller's own panel. The roles here are the Organization
    | module's own; platform staff roles are never granted from this surface.
    | @see App\Modules\Organization\Presentation\Filament\Seller\Resources\TeamMemberResource
    */
    'team' => [
        'member_singular' => 'Team member',
        'member_plural' => 'Team members',
        'invitation_singular' => 'Invitation',
        'invitation_plural' => 'Invitations',

        'member_name' => 'Name',
        'member_email' => 'Email',
        'role' => 'Role',
        'role_help' => 'Company roles only. Ownership moves by transfer, never by assigning a role.',
        'status' => 'Status',
        'joined_at' => 'Joined',
        'expires_at' => 'Expires',
        'invited_at' => 'Invited',

        'reason' => 'Reason',
        'reason_help' => 'Recorded in the audit trail. Explain why this change was made.',

        'role_changed' => 'The member’s role has been updated.',
        'removed' => 'The member has been removed from the team.',
        'invitation_cancelled' => 'The invitation has been withdrawn.',

        'action' => [
            'invite' => 'Invite to the team',
            'change_role' => 'Change role',
            'remove' => 'Remove from team',
            'remove_confirm' => 'This person loses access to your company. Nothing is deleted — the audit trail is kept.',
            'resend' => 'Resend invitation',
            'resend_confirm' => 'A new link is sent and the previous one stops working.',
            'cancel' => 'Withdraw invitation',
            'cancel_confirm' => 'The link stops working. You can invite the address again later.',
        ],

        'empty' => [
            'heading' => 'Nobody else on your team yet',
            'description' => 'Invite a colleague by email; they join your team once they accept.',
        ],
        'invitation_empty' => [
            'heading' => 'No invitations in flight',
            'description' => 'Invitations you send appear here until they are accepted.',
        ],
    ],

    'invitation' => [
        'subject' => 'You have been invited to join :organization',
        'intro' => ':organization has invited you to join as :role.',
        'action' => 'Accept invitation',
        'expiry' => 'This invitation will expire. Accept it while it is still valid.',
        'no_account' => "If you don't have an account yet, you'll be asked to create one first, then the invitation will complete.",
    ],

];
