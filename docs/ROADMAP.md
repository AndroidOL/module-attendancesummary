# Attendance Summary Roadmap

## Released scope

- Class and form-group attendance reports.
- Full attendance history by school day and by course.
- Analytics, low-attendance alerts, CSV export and print views.
- Auditable attendance-record transfer with transactional updates.
- Restricted low-attendance and no-record follow-up cases with append-only notes
  and administrator-only retention cleanup.

## Next candidates

- Add browser coverage for the no-record queue, follow-up access boundaries,
  retention cleanup and analytics print/CSV metadata.
- Add indexes after profiling the production attendance-log query plans.
- Add term/year comparison and scheduled report delivery.
- Add an explicit teacher-scope policy setting for student-detail access.
- Add browser tests for print, CSV and transfer workflows.
