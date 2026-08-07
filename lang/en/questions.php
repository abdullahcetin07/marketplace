<?php

declare(strict_types=1);

return [
    'singular' => 'Question',
    'plural' => 'Questions',

    'errors' => [
        'no_seller' => 'No shop is selling this product right now, so the question could not be sent.',
        'not_pending' => 'This question has already been answered.',
        'product_not_found' => 'Product not found.',
    ],

    'answer' => [
        'action' => 'Answer',
        'body' => 'Your answer',
        'body_hint' => 'Your answer appears publicly on the product page.',
        'submitted' => 'Your answer is live.',
        'empty' => 'No questions waiting for an answer.',
    ],

    'moderation' => [
        'hide' => 'Hide',
        'hide_reason' => 'Reason for hiding',
        'hide_reason_hint' => 'Kept on the record; neither the asker nor the seller sees it.',
        'hidden_notice' => 'Question hidden',
        'unhide' => 'Show again',
        'unhide_confirm' => 'The question returns to whatever it was before it was hidden.',
        'unhidden_notice' => 'Question visible again',
        'empty' => 'No questions.',
    ],

    'field' => [
        'product' => 'Product',
        'seller' => 'Seller',
        'asker' => 'Asked by',
        'body' => 'Question',
        'answer' => 'Answer',
        'status' => 'Status',
        'asked_at' => 'Asked',
        'answered_at' => 'Answered',
        'hidden' => 'Hidden',
    ],
];
