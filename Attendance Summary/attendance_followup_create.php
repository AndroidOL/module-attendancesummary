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

require_once __DIR__.'/moduleFunctions.php';
require_once __DIR__.'/followupFunctions.php';

$page->breadcrumbs
    ->add(attendanceT('Attendance Follow-up'), 'attendance_followup.php')
    ->add(attendanceT('Create Follow-up'));

if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_create.php')) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$page->return->addReturns([
    'error0' => attendanceT('You do not have permission to perform this action.'),
    'error1' => attendanceT('The submitted follow-up data is invalid.'),
    'error2' => attendanceT('An active follow-up already exists for this student, reason and source range.'),
    'error3' => attendanceT('No follow-up data was submitted.'),
    'error9' => attendanceT('Your session expired. Refresh the page and try again.'),
]);

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$students = attendanceFollowUpStudentOptions($pdo, $gibbonPersonID, $gibbonSchoolYearID);
if (empty($students)) {
    $page->addError(attendanceT('No current students are available for follow-up with your access.'));
    return;
}

$schoolYear = $pdo->selectOne(
    "SELECT firstDay, lastDay FROM gibbonSchoolYear WHERE gibbonSchoolYearID=:gibbonSchoolYearID",
    ['gibbonSchoolYearID' => $gibbonSchoolYearID]
);
$defaultStart = !empty($schoolYear['firstDay']) && $schoolYear['firstDay'] > date('Y-m-d', strtotime('-90 days'))
    ? $schoolYear['firstDay']
    : date('Y-m-d', strtotime('-90 days'));
$defaultEnd = date('Y-m-d');

$requestedStudent = is_scalar($_GET['gibbonPersonID'] ?? null) ? trim((string) $_GET['gibbonPersonID']) : '';
$requestedReason = is_scalar($_GET['reason'] ?? null) ? trim((string) $_GET['reason']) : '';
$requestedStart = is_scalar($_GET['start'] ?? null) ? trim((string) $_GET['start']) : '';
$requestedEnd = is_scalar($_GET['end'] ?? null) ? trim((string) $_GET['end']) : '';
if (attendanceFollowUpIsValidDate($requestedStart)) {
    $defaultStart = max($defaultStart, $requestedStart);
}
if (attendanceFollowUpIsValidDate($requestedEnd) && $requestedEnd <= date('Y-m-d')) {
    $defaultEnd = max($defaultStart, $requestedEnd);
}

$currentStaff = $pdo->selectOne(
    "SELECT title, preferredName, surname FROM gibbonPerson WHERE gibbonPersonID=:gibbonPersonID",
    ['gibbonPersonID' => $gibbonPersonID]
);

$form = Form::create(
    'attendanceFollowUpCreate',
    $session->get('absoluteURL').'/modules/Attendance Summary/attendance_followup_createProcess.php',
    'post'
);
$form->setTitle(attendanceT('Create Attendance Follow-up'));
$form->setDescription(attendanceT('Record only the minimum coordination status and notes. Do not enter diagnoses, safeguarding narratives, medical details or attachments.'));
$form->addHiddenValue('address', $session->get('address'));
$form->addHiddenValue('gibbonPersonIDOwner', $gibbonPersonID);

$row = $form->addRow();
    $row->addLabel('gibbonPersonIDStudent', attendanceT('Student'));
    $row->addSearchSelect('gibbonPersonIDStudent')
        ->fromArray($students)
        ->selected(array_key_exists($requestedStudent, $students) ? $requestedStudent : '')
        ->required()
        ->placeholder(attendanceT('Please select...'));

$row = $form->addRow();
    $row->addLabel('reasonCode', attendanceT('Reason'));
    $row->addSelect('reasonCode')
        ->fromArray(attendanceFollowUpReasons())
        ->selected(isset(attendanceFollowUpReasons()[$requestedReason]) ? $requestedReason : '')
        ->required()
        ->placeholder(attendanceT('Please select...'));

$row = $form->addRow();
    $row->addLabel('sourceDateStart', attendanceT('Source Date Start'));
    $row->addDate('sourceDateStart')->required()->setValue(Format::date($defaultStart));

$row = $form->addRow();
    $row->addLabel('sourceDateEnd', attendanceT('Source Date End'));
    $row->addDate('sourceDateEnd')->required()->setValue(Format::date($defaultEnd));

$row = $form->addRow();
    $row->addLabel('dueDate', attendanceT('Due Date'));
    $row->addDate('dueDate')->required()->setValue(Format::date(attendanceFollowUpDefaultDueDate()));

$row = $form->addRow();
    $row->addLabel('initialNote', attendanceT('Opening Note'))->description(attendanceT('Optional, maximum 2000 characters.'));
    $row->addTextArea('initialNote')->maxLength(2000)->setRows(4);

$row = $form->addRow();
    $row->addLabel('owner', attendanceT('Initial Owner'));
    $row->addContent(Format::name(
        $currentStaff['title'] ?? '',
        $currentStaff['preferredName'] ?? '',
        $currentStaff['surname'] ?? '',
        'Staff',
        true
    ));

$row = $form->addRow();
    $row->addFooter();
    $row->addSubmit(attendanceT('Create Follow-up'));

$page->write($form->getOutput());
