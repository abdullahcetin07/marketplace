<?php

declare(strict_types=1);

return [

    /*
    | Domain error messages shown to the user (BaseException::userMessage()).
    | They say what went wrong without naming a table, an id or a class.
    */
    'errors' => [
        'invalid_transition' => 'This store cannot change state that way.',
    ],

    'plural' => 'Stores',
    'singular' => 'Store',

    'name' => 'Name',
    'slug' => 'Slug',
    'number' => 'Store number',
    'status' => 'Status',
    'organization' => 'Organization',
    'activated_at' => 'Activated',

    'action' => [
        'request' => 'Request a store',
        'activate' => 'Activate',
        'pause' => 'Pause',
        'resume' => 'Resume',
        'close' => 'Close',
        'suspend' => 'Suspend',
        'reinstate' => 'Reinstate',
        'archive' => 'Archive',
    ],

    'reason' => 'Reason',

    'notify' => [
        'activated' => 'Store activated.',
        'paused' => 'Store paused.',
        'resumed' => 'Store resumed.',
        'closed' => 'Store closed.',
        'suspended' => 'Store suspended.',
        'reinstated' => 'Store reinstated.',
        'archived' => 'Store archived.',
    ],
];
