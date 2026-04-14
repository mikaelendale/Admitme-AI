<?php

return [

    'warning_threshold' => 0.8,

    /*
    |--------------------------------------------------------------------------
    | Plan limits (Trial vs Paid)
    |--------------------------------------------------------------------------
    |
    | Sprint and Pro share the same numeric limits (key: paid).
    | null = unlimited for that feature.
    |
    */

    'plans' => [
        'trial' => [
            'window' => null,
            'features' => [
                'gap_analysis' => 1,
                'fix_roadmap' => 1,
                'college_list' => 1,
                'chat_conversation' => 3,
                'chat_message_per_convo' => 20,
                'essay_roast' => 1,
                'essay_draft' => 1,
                'cliche_detector' => 1,
                'export_college_list' => 0,
            ],
            'binary' => [
                'application_tracker' => false,
                'document_vault' => false,
                'deadline_reminders' => false,
                'school_deep_dives' => false,
                'financial_aid_estimator' => false,
                'milestones_badges' => false,
                'weekly_progress_email' => false,
            ],
        ],
        'paid' => [
            'window' => 30,
            'features' => [
                'gap_analysis' => 30,
                'fix_roadmap' => 30,
                'college_list' => 10,
                'chat_message' => 500,
                'essay_roast' => 30,
                'essay_draft' => null,
                'cliche_detector' => 30,
                'export_college_list' => 5,
            ],
            'binary' => [
                'application_tracker' => true,
                'document_vault' => true,
                'deadline_reminders' => true,
                'school_deep_dives' => true,
                'financial_aid_estimator' => true,
                'milestones_badges' => true,
                'weekly_progress_email' => true,
            ],
        ],
    ],

];
