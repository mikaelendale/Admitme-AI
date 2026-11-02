<?php

namespace App\Domain\Applications;

/**
 * Who has to do a checklist item. Third-party work (a teacher's letter, a counselor's school report, a
 * testing agency's score report) is outside the student's control and is tracked separately.
 */
enum ChecklistOwner: string
{
    case Student = 'student';
    case ThirdParty = 'third_party';
}
