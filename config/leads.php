<?php

declare(strict_types=1);

return [

    /*
    | Minutes a lead may stay in "new" status before it is escalated to the
    | responsible manager (requirement R5.1).
    */
    'escalation_delay_minutes' => (int) env('LEADS_ESCALATION_DELAY_MINUTES', 30),

    /*
    | Daily capacity given to new agents unless set explicitly (R2.2).
    */
    'default_daily_capacity' => (int) env('LEADS_DEFAULT_DAILY_CAPACITY', 20),

    /*
    | Timestamps are stored in UTC. The "day" used for daily capacity is the
    | calendar day in this business timezone (assumption A-3).
    */
    'business_timezone' => env('LEADS_BUSINESS_TIMEZONE', 'UTC'),

    /*
    | Assignment strategy key, resolved in AppServiceProvider (design §6.6).
    | Supported: "round_robin".
    */
    'assignment_strategy' => env('LEADS_ASSIGNMENT_STRATEGY', 'round_robin'),

    /*
    | Upper bound for one bulk assignment, so a request can never load an
    | unbounded number of rows into memory (R8.3).
    */
    'bulk_assign_max' => (int) env('LEADS_BULK_ASSIGN_MAX', 500),

];
