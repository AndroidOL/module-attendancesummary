<?php
// USE ;end TO SEPARATE SQL STATEMENTS. DON'T USE ;end IN ANY OTHER PLACES!

$sql = [];
$count = 0;

// v1.0.3
$sql[$count][0] = "1.0.3";
$sql[$count][1] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsAbsence', 'Absence Keywords', 'Comma-separated attendance type keywords for absence and personal leave.', '旷课,事假,Absent,Unexcused absence' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsAbsence');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsSuspension', 'Suspension Keywords', 'Comma-separated attendance type keywords for suspension.', '被停课,Suspended' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsSuspension');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsLate', 'Late Keywords', 'Comma-separated attendance type keywords for lateness.', '迟到,Late' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsLate');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsLeftEarly', 'Left Early Keywords', 'Comma-separated attendance type keywords for leaving early.', '早退,Left early' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsLeftEarly');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsSickLeave', 'Sick Leave Keywords', 'Comma-separated attendance type keywords for sick leave.', '病假,Sick leave' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsSickLeave');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsAuthorizedAbsence', 'Authorized Absence Keywords', 'Comma-separated attendance type keywords for authorized absence.', '公假,Authorized absence' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsAuthorizedAbsence');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsPresent', 'Present Keywords', 'Comma-separated attendance type keywords for attendance.', '出勤,Present' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsPresent');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsNoRecord', 'No Record Keywords', 'Comma-separated attendance type keywords for no record.', '无记录,No record' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsNoRecord');end";
$count++;

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
