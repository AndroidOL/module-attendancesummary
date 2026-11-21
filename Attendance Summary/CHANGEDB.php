<?php
// USE ;end TO SEPARATE SQL STATEMENTS. DON'T USE ;end IN ANY OTHER PLACES!

$sql = [];
$count = 0;

// v1.0.2
$sql[$count][0] = "1.0.2";
$sql[$count][1] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreAbsence', 'Absence Score', 'Score for unexcused or personal absence.', '0' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreAbsence');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreSuspension', 'Suspension Score', 'Score for a suspension.', '0' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreSuspension');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreLate', 'Late Score', 'Score for lateness.', '0.7' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreLate');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreLeftEarly', 'Left Early Score', 'Score for leaving early.', '0.7' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreLeftEarly');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreSickLeave', 'Sick Leave Score', 'Score for sick leave.', '0.5' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreSickLeave');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreAuthorizedAbsence', 'Authorized Absence Score', 'Score for an authorized absence.', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreAuthorizedAbsence');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scorePresent', 'Present Score', 'Score for attendance.', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scorePresent');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreNoRecord', 'No Record Score', 'Score for an attendance type marked as no record.', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreNoRecord');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreUnknown', 'Unknown Type Score', 'Fallback score when an attendance type does not match a configured category.', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreUnknown');end";
$count++;

// v0.0.00
// $sql[$count][0] = "0.0.00";
// $sql[$count][1] = "-- First version, nothing to update";
