<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Punch out correction approval cutoff
    |--------------------------------------------------------------------------
    |
    | Requests created before this instant stay on the attendance record for
    | audit, but they are not pending approvals. The timestamp is the
    | correction's created_at in Asia/Kolkata, not the attendance date.
    |
    */

    'punch_out_correction_cutoff' => env('PUNCH_OUT_CORRECTION_CUTOFF', '2026-09-28 00:00:00'),

    /*
    |--------------------------------------------------------------------------
    | Punch out enforcement cutoff
    |--------------------------------------------------------------------------
    |
    | Open attendance punched in before this instant does not block Punch In
    | and does not enter the >24-hour correction workflow. The timestamp is
    | the attendance punch-in in Asia/Kolkata, not the correction created_at.
    |
    */

    'punch_out_enforcement_cutoff' => env('PUNCH_OUT_ENFORCEMENT_CUTOFF', '2026-09-29 00:00:00'),

];
