<?php

declare(strict_types=1);

return [
    'singular' => 'Review',
    'plural' => 'Reviews',

    'errors' => [
        'not_eligible' => 'You can only review a product you have bought and received.',
        'not_pending' => 'This review has already been decided.',
        'already_reviewed' => 'You have already reviewed this purchase.',
        'product_not_found' => 'Product not found.',
    ],

    'moderation' => [
        'publish' => 'Publish',
        'publish_confirm' => 'The review and its photos go live on the product page.',
        'published_notice' => 'Review published',
        'reject' => 'Reject',
        'reject_reason' => 'Reason for rejecting',
        'reject_reason_hint' => 'Kept on the record; not shown to the buyer.',
        'rejected_notice' => 'Review rejected',
        'empty' => 'No reviews waiting for approval.',
    ],

    'field' => [
        'product' => 'Product',
        'seller' => 'Seller',
        'author' => 'Reviewer',
        'rating' => 'Rating',
        'body' => 'Comment',
        'photos' => 'Photos',
        'status' => 'Status',
        'submitted_at' => 'Submitted',
        'has_photos' => 'With photos',
    ],

    'request' => [
        'subject' => 'How was your :product?',
        'intro' => 'Your :product arrived a few days ago. We would like to know how it is — would you take a minute to review it?',
        'points' => 'You will earn :points points once your review is published.',
        'action' => 'Write a review',
        'outro' => 'Your review helps other shoppers looking at the same product decide. If you would rather not receive emails like this, you can update your notification preferences in your account settings.',
    ],
];
