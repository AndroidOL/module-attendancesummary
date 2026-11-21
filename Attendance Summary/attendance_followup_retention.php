<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
*/

use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;

require_once __DIR__.'/moduleFunctions.php';
require_once __DIR__.'/followupFunctions.php';

$page->breadcrumbs
    ->add(attendanceT('Attendance Follow-up'), 'attendance_followup.php')
    ->add(attendanceT('Retention Cleanup'));

$isAdmin = isActionAccessible($guid, $connection2, '/modules/System Admin/systemSettings.php');
if (
    !isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_retentionProcess.php')
    || !$isAdmin
) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$page->return->addReturns([
    'success0' => attendanceT('Expired follow-up cases were purged and the retention audit log was updated.'),
    'error0'   => attendanceT('You do not have permission to perform this action.'),
    'error1'   => attendanceT('The purge confirmation was missing.'),
    'error3'   => attendanceT('No submitted data was received.'),
    'error9'   => attendanceT('Your session expired. Refresh the page and try again.'),
]);

$rows = $pdo->select(
    "SELECT followUp.gibbonSchoolYearID, schoolYear.name AS schoolYearName,
            schoolYear.sequenceNumber,
            COUNT(*) AS caseCount,
            (SELECT COUNT(*) FROM attendance_follow_up_note AS note
             JOIN attendance_follow_up AS expired
               ON expired.followUpID=note.followUpID
             WHERE expired.gibbonSchoolYearID=followUp.gibbonSchoolYearID
               AND expired.retentionUntil<CURRENT_DATE) AS noteCount,
            (SELECT COUNT(*) FROM attendance_follow_up_event AS event
             JOIN attendance_follow_up AS expired
               ON expired.followUpID=event.followUpID
             WHERE expired.gibbonSchoolYearID=followUp.gibbonSchoolYearID
               AND expired.retentionUntil<CURRENT_DATE) AS eventCount,
            MIN(followUp.retentionUntil) AS firstRetentionDate
     FROM attendance_follow_up AS followUp
     JOIN gibbonSchoolYear AS schoolYear
       ON schoolYear.gibbonSchoolYearID=followUp.gibbonSchoolYearID
     WHERE followUp.retentionUntil<CURRENT_DATE
     GROUP BY followUp.gibbonSchoolYearID, schoolYear.name, schoolYear.sequenceNumber
     ORDER BY schoolYear.sequenceNumber",
)->fetchAll();

$table = DataTable::create('attendanceFollowUpRetention');
$table->setTitle(attendanceT('Expired Follow-up Cases'));
$table->setDescription(attendanceT('Retention is calculated as the source school year end plus 12 months. Purging removes cases, notes and audit events together and records an administrator audit row.'));
$table->addMetaData('blankSlate', attendanceT('No follow-up cases are currently due for retention cleanup.'));
$table->addColumn('schoolYearName', attendanceT('School Year'));
$table->addColumn('caseCount', attendanceT('Cases'));
$table->addColumn('noteCount', attendanceT('Notes'));
$table->addColumn('eventCount', attendanceT('Audit Events'));
$table->addColumn('firstRetentionDate', attendanceT('Oldest Retention Date'))->format(function ($row) {
    return Format::date($row['firstRetentionDate']);
});
$page->write($table->render($rows));

if (!empty($rows)) {
    $form = Form::create(
        'attendanceFollowUpRetentionPurge',
        $session->get('absoluteURL').'/modules/Attendance Summary/attendance_followup_retentionProcess.php',
        'post'
    );
    $form->setTitle(attendanceT('Purge Expired Cases'));
    $form->setDescription(attendanceT('This action cannot be undone. Export any cases required by school policy before continuing.'));
    $form->addHiddenValue('address', $session->get('address'));
    $form->addHiddenValue('confirm', 'Y');
    $row = $form->addRow();
        $row->addCheckbox('confirmed')->setValue('Y')->required()
            ->description(attendanceT('I understand that expired cases, notes and events will be permanently removed.'));
    $row = $form->addRow();
        $row->addFooter();
        $row->addSubmit(attendanceT('Purge Expired Cases'))->setColor('red');
    $page->write($form->getOutput());
}
