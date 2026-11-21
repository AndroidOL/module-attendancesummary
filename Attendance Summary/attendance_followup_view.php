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
    ->add(attendanceT('View Follow-up'));

$canView = isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_view.php');
$canManage = isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_updateProcess.php');
if (!$canView && !$canManage) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$page->return->addReturns([
    'success0' => attendanceT('Follow-up case created.'),
    'success1' => attendanceT('Follow-up case updated.'),
    'success2' => attendanceT('Follow-up note added.'),
    'error0'   => attendanceT('You do not have permission to perform this action.'),
    'error1'   => attendanceT('The submitted follow-up data is invalid.'),
    'error2'   => attendanceT('Another active follow-up already uses this student, reason and source range.'),
    'error3'   => attendanceT('No submitted data was received.'),
    'error4'   => attendanceT('This follow-up changed in another tab. Reload before saving.'),
    'error9'   => attendanceT('Your session expired. Refresh the page and try again.'),
]);

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$followUpID = is_scalar($_GET['followUpID'] ?? null) ? trim((string) $_GET['followUpID']) : '';
$case = $followUpID !== '' ? attendanceFollowUpLoadCase($pdo, $followUpID) : null;
if (empty($case) || !attendanceFollowUpCanAccessCase($pdo, $gibbonPersonID, $case)) {
    $page->addError(attendanceT('The specified record does not exist.'));
    return;
}

$studentName = Format::name(
    $case['studentTitle'],
    $case['studentPreferredName'],
    $case['studentSurname'],
    'Student',
    true
);
$ownerName = Format::name(
    $case['ownerTitle'],
    $case['ownerPreferredName'],
    $case['ownerSurname'],
    'Staff',
    true
);
$isOverdue = $case['status'] !== 'resolved' && $case['dueDate'] < date('Y-m-d');

$actions = Form::createBlank('attendanceFollowUpNavigation');
$actions->addHeaderAction('back', attendanceT('Back to Worklist'))
    ->setURL('/modules/Attendance Summary/attendance_followup.php')
    ->setIcon('back')
    ->displayLabel();
if (isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_lifecycle.php')) {
    $actions->addHeaderAction('attendance', attendanceT('View Attendance History'))
        ->setURL('/modules/Attendance Summary/attendance_lifecycle.php')
        ->addParam('gibbonPersonID', $case['gibbonPersonIDStudent'])
        ->addParam('scope', 'year:'.$case['gibbonSchoolYearID'])
        ->setIcon('attendance')
        ->displayLabel();
}
if ($canManage) {
    $actions->addHeaderAction('export', attendanceT('Export This Case'))
        ->setURL('/modules/Attendance Summary/attendance_followup_export.php')
        ->addParam('followUpID', $followUpID)
        ->setIcon('download')
        ->directLink()
        ->displayLabel();
}
$page->write($actions->getOutput());

$statusClass = 'attendanceFollowUpStatus-'.preg_replace('/[^a-z_]/', '', (string) $case['status']);
$output = '<div class="attendanceFollowUpCard">'
    .'<div class="attendanceFollowUpCardHeader">'
    .'<div><h2>'.htmlPrep($studentName).'</h2>'
    .'<p>'.attendanceT('Form Group').': '.htmlPrep((string) $case['formGroupName']).'</p></div>'
    .'<span class="attendanceFollowUpStatus '.$statusClass.'">'.htmlPrep(attendanceFollowUpStatusLabel($case['status'])).'</span>'
    .'</div>'
    .'<div class="attendanceFollowUpMeta">'
    .'<span><strong>'.attendanceT('Reason').':</strong> '.htmlPrep(attendanceFollowUpReasonLabel($case['reasonCode'])).'</span>'
    .'<span><strong>'.attendanceT('Owner').':</strong> '.htmlPrep($ownerName).'</span>'
    .'<span><strong>'.attendanceT('Due').':</strong> '.htmlPrep(Format::date($case['dueDate'])).($isOverdue ? ' · <strong class="attendanceFollowUpOverdue">'.attendanceT('Overdue').'</strong>' : '').'</span>'
    .'<span><strong>'.attendanceT('Source Dates').':</strong> '.htmlPrep(Format::date($case['sourceDateStart'])).' – '.htmlPrep(Format::date($case['sourceDateEnd'])).'</span>'
    .'<span><strong>'.attendanceT('Retain Until').':</strong> '.htmlPrep(Format::date($case['retentionUntil'])).'</span>'
    .'<span><strong>'.attendanceT('Created').':</strong> '.Format::dateTime($case['createdAt']).'</span>';
if (!empty($case['resolutionCode'])) {
    $output .= '<span><strong>'.attendanceT('Outcome').':</strong> '.htmlPrep(attendanceFollowUpResolutionLabel($case['resolutionCode'])).'</span>';
}
$output .= '</div></div>';
$page->write($output);

if ($canManage) {
    $ownerOptions = attendanceFollowUpOwnerOptions(
        $pdo,
        $case['gibbonPersonIDStudent'],
        $gibbonSchoolYearID,
        $gibbonPersonID,
        $case['gibbonPersonIDOwner']
    );
    $statusOptions = [];
    foreach (attendanceFollowUpStatuses() as $statusCode => $statusLabel) {
        if ($statusCode === $case['status'] || attendanceFollowUpStatusAllowsTransition($case['status'], $statusCode)) {
            $statusOptions[$statusCode] = $statusLabel;
        }
    }
    $updateForm = Form::create(
        'attendanceFollowUpUpdate',
        $session->get('absoluteURL').'/modules/Attendance Summary/attendance_followup_updateProcess.php',
        'post'
    );
    $updateForm->setTitle(attendanceT('Update Follow-up'));
    $updateForm->addHiddenValue('address', $session->get('address'));
    $updateForm->addHiddenValue('followUpID', $followUpID);
    $updateForm->addHiddenValue('revision', (string) $case['revision']);

    $row = $updateForm->addRow();
        $row->addLabel('gibbonPersonIDOwner', attendanceT('Owner'));
        $row->addSelect('gibbonPersonIDOwner')->fromArray($ownerOptions)->selected($case['gibbonPersonIDOwner'])->required();

    $row = $updateForm->addRow();
        $row->addLabel('status', attendanceT('Status'));
        $row->addSelect('status')->fromArray($statusOptions)->selected($case['status'])->required();

    $row = $updateForm->addRow();
        $row->addLabel('dueDate', attendanceT('Due Date'));
        $row->addDate('dueDate')->required()->setValue(Format::date($case['dueDate']));

    $row = $updateForm->addRow();
        $row->addLabel('resolutionCode', attendanceT('Outcome'))->description(attendanceT('Required when resolving a case.'));
        $row->addSelect('resolutionCode')->fromArray(attendanceFollowUpResolutionOptions())->selected($case['resolutionCode'])->placeholder();

    $row = $updateForm->addRow();
        $row->addLabel('resolutionNote', attendanceT('Resolution Note'))->description(attendanceT('Required when resolving a case. Maximum 2000 characters.'));
        $row->addTextArea('resolutionNote')->maxLength(2000)->setRows(4);

    $row = $updateForm->addRow();
        $row->addFooter();
        $row->addSubmit(attendanceT('Save Follow-up'));

    $page->write($updateForm->getOutput());

    $noteForm = Form::create(
        'attendanceFollowUpNote',
        $session->get('absoluteURL').'/modules/Attendance Summary/attendance_followup_noteProcess.php',
        'post'
    );
    $noteForm->setTitle(attendanceT('Add Coordination Note'));
    $noteForm->setDescription(attendanceT('Notes are append-only. Do not enter diagnoses, medical details, safeguarding narratives or attachments.'));
    $noteForm->addHiddenValue('address', $session->get('address'));
    $noteForm->addHiddenValue('followUpID', $followUpID);
    $row = $noteForm->addRow();
        $row->addLabel('noteText', attendanceT('Note'))->description(attendanceT('Maximum 2000 characters.'));
        $row->addTextArea('noteText')->required()->maxLength(2000)->setRows(4);
    $row = $noteForm->addRow();
        $row->addFooter();
        $row->addSubmit(attendanceT('Add Note'));
    $page->write($noteForm->getOutput());
}

$notes = $pdo->select(
    "SELECT note.*, person.title, person.preferredName, person.surname
     FROM attendance_follow_up_note AS note
     LEFT JOIN gibbonPerson AS person ON person.gibbonPersonID=note.gibbonPersonIDAuthor
     WHERE note.followUpID=:followUpID
     ORDER BY note.createdAt, note.noteID",
    ['followUpID' => $followUpID]
)->fetchAll();
$events = $pdo->select(
    "SELECT event.*, person.title, person.preferredName, person.surname
     FROM attendance_follow_up_event AS event
     LEFT JOIN gibbonPerson AS person ON person.gibbonPersonID=event.gibbonPersonIDActor
     WHERE event.followUpID=:followUpID
     ORDER BY event.createdAt, event.eventID",
    ['followUpID' => $followUpID]
)->fetchAll();

$output = '<div class="attendanceFollowUpSection"><h3>'.attendanceT('Coordination Notes').'</h3>';
if (empty($notes)) {
    $output .= $page->getBlankSlate(attendanceT('No coordination notes have been added.'));
} else {
    foreach ($notes as $note) {
        $author = Format::name($note['title'], $note['preferredName'], $note['surname'], 'Staff', true);
        $output .= '<div class="attendanceFollowUpNote">'
            .'<div><strong>'.htmlPrep($author).'</strong> · '.Format::dateTime($note['createdAt']).'</div>'
            .'<p>'.nl2br(htmlPrep((string) $note['noteText'])).'</p>'
            .'</div>';
    }
}
$output .= '</div>';

$eventLabels = [
    'created'        => attendanceT('Case Created'),
    'owner_changed'  => attendanceT('Owner Changed'),
    'status_changed' => attendanceT('Status Changed'),
    'due_changed'    => attendanceT('Due Date Changed'),
    'resolution_changed' => attendanceT('Outcome Changed'),
    'note_added'     => attendanceT('Note Added'),
    'exported'       => attendanceT('Case Exported'),
];
$output .= '<div class="attendanceFollowUpSection"><h3>'.attendanceT('Audit Timeline').'</h3>';
if (empty($events)) {
    $output .= $page->getBlankSlate(attendanceT('No audit events are available.'));
} else {
    $output .= '<ul class="attendanceFollowUpEvents">';
    foreach ($events as $event) {
        $actor = Format::name($event['title'], $event['preferredName'], $event['surname'], 'Staff', true);
        $label = $eventLabels[$event['eventType']] ?? $event['eventType'];
        $detail = '';
        if (!empty($event['fieldName'])) {
            $detail = ' · '.htmlPrep((string) $event['fieldName']).': '
                .htmlPrep((string) ($event['oldValue'] ?? '')).' → '.htmlPrep((string) ($event['newValue'] ?? ''));
        }
        $output .= '<li><strong>'.htmlPrep($label).'</strong> · '.htmlPrep($actor).' · '
            .Format::dateTime($event['createdAt']).$detail.'</li>';
    }
    $output .= '</ul>';
}
$output .= '</div>';

$page->write($output);
