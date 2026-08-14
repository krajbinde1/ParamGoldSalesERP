<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Payment Request Approvers
    |--------------------------------------------------------------------------
    |
    | Prefer stable user IDs via env. Names are used only to resolve those users
    | when IDs are not set (matched against users.name / employees.full_name).
    |
    */

    'first_approver_user_id' => env('PAYMENT_REQUEST_FIRST_APPROVER_USER_ID'),
    'second_approver_user_id' => env('PAYMENT_REQUEST_SECOND_APPROVER_USER_ID'),

    'first_approver_name' => env('PAYMENT_REQUEST_FIRST_APPROVER_NAME', 'Bhagwan Kakde'),
    'second_approver_name' => env('PAYMENT_REQUEST_SECOND_APPROVER_NAME', 'Krishna Rajbinde'),

    'first_approver_display_role' => 'Director',
    'second_approver_display_role' => 'Director',
];
