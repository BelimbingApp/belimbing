<?php

/**
 * Relative paths under the repository root that may read a subject-shaped
 * request or route parameter inside Domain Livewire without the workforce
 * subject seam. Each entry needs a concrete reason; empty is the default.
 *
 * @return array<string, string>
 */
return [
    // Composed People tree hit until the Attendance fix lands (BelimbingApp/blb-people#249).
    'app/Domains/People/Attendance/Livewire/RosterEmployeeHistory.php' => 'Temporary: request()->query(employee_id) into Employee::find; tracked as BelimbingApp/blb-people#249',
];
