<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trial Period Length
    |--------------------------------------------------------------------------
    |
    | How many days a new tenant's Trial subscription runs for, starting
    | from the day they register.
    |
    */

    'trial_days' => env('TRIAL_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Trial Plan Defaults
    |--------------------------------------------------------------------------
    |
    | Attributes for the SubscriptionPlan every new tenant is automatically
    | enrolled on. Looked up by the `is_trial` flag rather than by name, so
    | renaming the plan later doesn't break the lookup.
    |
    */

    'trial_plan' => [
        'name' => 'Trial',
        'is_trial' => true,
        'max_branches' => 1,
        'max_users' => 1,
        'max_transactions_per_month' => 400,
        'price' => 0.00,
    ],

];
