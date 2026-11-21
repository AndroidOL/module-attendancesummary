# Attendance Summary for Gibbon

Attendance Summary extends Gibbon with class and school-level attendance reporting,
full attendance history, analytics and a controlled lesson-transfer workflow.

## Requirements

- Gibbon 31.0.00 or later
- PHP 8.2 or later
- MySQL 8 compatible server

## Installation

1. Copy the `Attendance Summary` directory into `modules/`.
2. Sign in as an administrator and open **System Admin → Manage Modules**.
3. Install or update the module.
4. Grant the required actions to the appropriate staff and parent roles.

The transfer audit table is created during module installation.

## Features

- View attendance by form group and open a student's detailed record.
- Calculate daily, weekly, monthly and whole-term attendance summaries.
- View attendance across the whole enrolment history by school day or course.
- Analyse attendance by class, year group, weekday and month.
- Identify students below a configurable attendance threshold.
- Review no-record student-days in a separate data-quality queue.
- Export CSV and print reports, including the course-history summary.
- Transfer class-context attendance records with authorization, CSRF,
  transaction rollback and an audit record.

## Permissions

The module installs four actions:

- View Student Attendance
- View Attendance Trends
- View My Child's Attendance
- Transfer Attendance Records

Schools should review the default role assignments before enabling the module.

## Attendance definitions

The day-based analysis excludes days with no record from the attendance-rate
denominator. The course view uses a weighted score. Both definitions are
implemented in `moduleFunctions.php` and are intentionally documented separately.

An all-no-record student has no calculable attendance rate and is shown as
`N/A`, not `0%`. No-record student-days are listed separately as data gaps and
never enter the low-attendance list by themselves.

The analytics page displays its active scope, date range, denominator and
no-record handling to all viewers, and the same metadata is included in CSV
exports. Each user can explicitly save a personal alert threshold; ordinary
report GET requests, print and CSV exports never change that preference.

The `Attendance` setting `countClassAsSchool` controls whether class-context
attendance is included in day-based reports.

Existing course-history defaults are preserved, but administrators can change
the weights and matching keywords in **Attendance Score Settings**. The values
are stored in `gibbonSetting` under the `Attendance Summary` scope.

## Development

Run the helper tests from the Gibbon repository root:

```sh
vendor/bin/phpunit --bootstrap vendor/autoload.php \
  "modules/Attendance Summary/tests/ModuleFunctionsTest.php"
```

## License

GPL-3.0
