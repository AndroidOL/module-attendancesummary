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

$page->breadcrumbs->add(attendanceT('Attendance Follow-up'));

$canView = isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup.php');
$canManage = isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_create.php');
if (!$canView && !$canManage) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$schoolYears = $pdo->select(
    "SELECT DISTINCT schoolYear.gibbonSchoolYearID, schoolYear.name, schoolYear.sequenceNumber
     FROM attendance_follow_up AS followUp
     JOIN gibbonSchoolYear AS schoolYear
       ON schoolYear.gibbonSchoolYearID=followUp.gibbonSchoolYearID
     WHERE followUp.retentionUntil>=CURRENT_DATE
     ORDER BY schoolYear.sequenceNumber DESC"
)->fetchAll();
$schoolYearOptions = [];
foreach ($schoolYears as $schoolYear) {
    $schoolYearOptions[(string) $schoolYear['gibbonSchoolYearID']] = $schoolYear['name'];
}
$requestedYearID = is_scalar($_GET['schoolYearID'] ?? null) ? trim((string) $_GET['schoolYearID']) : '';
$selectedYearID = isset($schoolYearOptions[$requestedYearID])
    ? $requestedYearID
    : (isset($schoolYearOptions[$gibbonSchoolYearID]) ? $gibbonSchoolYearID : (string) array_key_first($schoolYearOptions));
$filters = [
    'schoolYearID' => $selectedYearID,
    'status'  => is_scalar($_GET['status'] ?? null) ? trim((string) $_GET['status']) : '',
    'reason'  => is_scalar($_GET['reason'] ?? null) ? trim((string) $_GET['reason']) : '',
    'owner'   => ($_GET['owner'] ?? '') === 'mine' ? 'mine' : 'all',
    'overdue' => !empty($_GET['overdue']),
    'search'  => is_scalar($_GET['search'] ?? null) ? trim((string) $_GET['search']) : '',
];

$form = Form::create('attendanceFollowUpFilter', $session->get('absoluteURL').'/index.php', 'get');
$form->setTitle(attendanceT('Find Follow-up Cases'));
$form->setClass('noIntBorder w-full');
$form->addHiddenValue('q', '/modules/'.$session->get('module').'/attendance_followup.php');

if (!empty($schoolYearOptions)) {
    $row = $form->addRow();
        $row->addLabel('schoolYearID', attendanceT('School Year'));
        $row->addSelect('schoolYearID')->fromArray($schoolYearOptions)->selected($selectedYearID)->required();
}

$row = $form->addRow();
    $row->addLabel('search', attendanceT('Search'))->description(attendanceT('Student name or ID'));
    $row->addTextField('search')->maxLength(100)->setValue($filters['search']);

$row = $form->addRow();
    $row->addLabel('status', attendanceT('Status'));
    $row->addSelect('status')->fromArray(attendanceFollowUpStatuses())->selected($filters['status'])->placeholder();

$row = $form->addRow();
    $row->addLabel('reason', attendanceT('Reason'));
    $row->addSelect('reason')->fromArray(attendanceFollowUpReasons())->selected($filters['reason'])->placeholder();

$row = $form->addRow();
    $row->addLabel('owner', attendanceT('Owner'));
    $row->addSelect('owner')->fromArray([
        'all'  => attendanceT('All Cases'),
        'mine' => attendanceT('Assigned to Me'),
    ])->selected($filters['owner']);

$row = $form->addRow();
    $row->addLabel('overdue', attendanceT('Overdue Only'));
    $row->addCheckbox('overdue')->setValue('Y')->checked($filters['overdue']);

$row = $form->addRow();
    $row->addFooter();
    $row->addSearchSubmit($session, attendanceT('Clear Filters'));

$page->write($form->getOutput());

$cases = attendanceFollowUpWorklist($pdo, $gibbonPersonID, $gibbonSchoolYearID, $filters);
$table = DataTable::create('attendanceFollowUpCases');
$table->setTitle(attendanceT('Attendance Follow-up Worklist'));
$table->setDescription(attendanceT('Cases contain action status and short coordination notes. They are retained until the school year end plus 12 months.'));
$table->addMetaData('allowHTML', ['statusLabel', 'dueDate']);
$table->addMetaData('blankSlate', attendanceT('No follow-up cases match these filters.'));

if ($canManage) {
    $table->addHeaderAction('create', attendanceT('Create Follow-up'))
        ->setURL('/modules/Attendance Summary/attendance_followup_create.php')
        ->setIcon('page_new')
        ->displayLabel();
    $table->addHeaderAction('export', attendanceT('Export CSV'))
        ->setURL('/modules/Attendance Summary/attendance_followup_export.php')
        ->addParams($filters)
        ->setIcon('download')
        ->directLink()
        ->displayLabel();
}
if ($canManage && isActionAccessible($guid, $connection2, '/modules/System Admin/systemSettings.php')) {
    $table->addHeaderAction('retention', attendanceT('Retention Cleanup'))
        ->setURL('/modules/Attendance Summary/attendance_followup_retention.php')
        ->setIcon('settings')
        ->displayLabel();
}

$table->addColumn('student', attendanceT('Student'))->format(function ($case) {
    return Format::name(
        $case['studentTitle'],
        $case['studentPreferredName'],
        $case['studentSurname'],
        'Student',
        true
    );
});
$table->addColumn('formGroupName', attendanceT('Form Group'));
$table->addColumn('reasonCode', attendanceT('Reason'))->format(function ($case) {
    return attendanceFollowUpReasonLabel($case['reasonCode']);
});
$table->addColumn('owner', attendanceT('Owner'))->format(function ($case) {
    return Format::name(
        $case['ownerTitle'],
        $case['ownerPreferredName'],
        $case['ownerSurname'],
        'Staff',
        true
    );
});
$table->addColumn('statusLabel', attendanceT('Status'))->format(function ($case) {
    $status = (string) $case['status'];
    $class = 'attendanceFollowUpStatus-'.preg_replace('/[^a-z_]/', '', $status);

    return '<span class="attendanceFollowUpStatus '.$class.'">'.htmlPrep(attendanceFollowUpStatusLabel($status)).'</span>';
});
$table->addColumn('dueDate', attendanceT('Due'))->format(function ($case) {
    $overdue = $case['status'] !== 'resolved' && $case['dueDate'] < date('Y-m-d');
    $label = Format::date($case['dueDate']);

    return $overdue
        ? '<strong class="attendanceFollowUpOverdue">'.htmlPrep($label).' · '.attendanceT('Overdue').'</strong>'
        : htmlPrep($label);
});
$table->addColumn('noteCount', attendanceT('Notes'));
$table->addColumn('updatedAt', attendanceT('Last Updated'))->format(function ($case) {
    return Format::dateTime($case['updatedAt']);
});
$table->addActionColumn()
    ->addParam('followUpID')
    ->format(function ($case, $actions) {
        $actions->addAction('view', attendanceT('View'))
            ->setIcon('search')
            ->setURL('/modules/Attendance Summary/attendance_followup_view.php');
    });

$page->write($table->render($cases));
