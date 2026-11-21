<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
*/

use Gibbon\Services\Format;

require_once __DIR__.'/moduleFunctions.php';
require_once __DIR__.'/followupFunctions.php';

if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_export.php')) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$followUpID = is_scalar($_GET['followUpID'] ?? null) ? trim((string) $_GET['followUpID']) : '';

if ($followUpID !== '') {
    $case = attendanceFollowUpLoadCase($pdo, $followUpID);
    if (empty($case) || !attendanceFollowUpCanAccessCase($pdo, $gibbonPersonID, $case)) {
        $page->addError(attendanceT('The specified record does not exist.'));
        return;
    }
    $cases = [$case];
} else {
    $cases = attendanceFollowUpWorklist($pdo, $gibbonPersonID, $gibbonSchoolYearID, [
        'schoolYearID' => is_scalar($_GET['schoolYearID'] ?? null) ? trim((string) $_GET['schoolYearID']) : $gibbonSchoolYearID,
        'status' => is_scalar($_GET['status'] ?? null) ? trim((string) $_GET['status']) : '',
        'reason' => is_scalar($_GET['reason'] ?? null) ? trim((string) $_GET['reason']) : '',
        'owner'  => ($_GET['owner'] ?? '') === 'mine' ? 'mine' : 'all',
        'overdue'=> !empty($_GET['overdue']),
        'search' => is_scalar($_GET['search'] ?? null) ? trim((string) $_GET['search']) : '',
    ]);
}

$caseIDs = array_values(array_filter(array_map(function ($case) {
    return (string) ($case['followUpID'] ?? '');
}, $cases), 'strlen'));
$notesByCase = [];
if (!empty($caseIDs)) {
    $placeholders = [];
    $params = [];
    foreach ($caseIDs as $index => $caseID) {
        $placeholders[] = ':case'.$index;
        $params['case'.$index] = $caseID;
    }
    $notes = $pdo->select(
        "SELECT followUpID, noteText, createdAt
         FROM attendance_follow_up_note
         WHERE followUpID IN (".implode(',', $placeholders).")
         ORDER BY followUpID, createdAt, noteID",
        $params
    )->fetchAll();
    foreach ($notes as $note) {
        $notesByCase[(string) $note['followUpID']][] = [
            'date' => $note['createdAt'],
            'text' => $note['noteText'],
        ];
    }
}

$rows = [];
$exportYearIDs = [];
foreach ($cases as $case) {
    $caseID = (string) $case['followUpID'];
    $exportYearIDs[(string) $case['gibbonSchoolYearID']] = true;
    $studentName = Format::name(
        $case['studentTitle'] ?? '',
        $case['studentPreferredName'] ?? '',
        $case['studentSurname'] ?? '',
        'Student',
        true
    );
    $ownerName = Format::name(
        $case['ownerTitle'] ?? '',
        $case['ownerPreferredName'] ?? '',
        $case['ownerSurname'] ?? '',
        'Staff',
        true
    );
    $noteLines = [];
    foreach ($notesByCase[$caseID] ?? [] as $note) {
        $noteLines[] = '['.Format::dateTime($note['date']).'] '.$note['text'];
    }
    $rows[] = [
        $caseID,
        $case['studentID'] ?? '',
        $studentName,
        $case['formGroupName'] ?? '',
        attendanceFollowUpReasonLabel($case['reasonCode']),
        $case['sourceDateStart'],
        $case['sourceDateEnd'],
        attendanceFollowUpStatusLabel($case['status']),
        $ownerName,
        $case['dueDate'],
        attendanceFollowUpResolutionLabel($case['resolutionCode'] ?? ''),
        $case['retentionUntil'],
        implode("\n", $noteLines),
        $case['createdAt'],
        $case['updatedAt'],
    ];
}

$exportYearNames = [];
if (!empty($exportYearIDs)) {
    $yearIDs = array_keys($exportYearIDs);
    $placeholders = [];
    $params = [];
    foreach ($yearIDs as $index => $yearID) {
        $placeholders[] = ':year'.$index;
        $params['year'.$index] = $yearID;
    }
    $exportYearNames = $pdo->select(
        "SELECT name FROM gibbonSchoolYear
         WHERE gibbonSchoolYearID IN (".implode(',', $placeholders).")
         ORDER BY sequenceNumber",
        $params
    )->fetchAll(PDO::FETCH_COLUMN);
}

if (!empty($caseIDs)) {
    try {
        $pdo->beginTransaction();
        foreach ($caseIDs as $caseID) {
            attendanceFollowUpRecordEvent($pdo, $caseID, $gibbonPersonID, 'exported');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

sendAttendanceCsv(
    'attendance-follow-up-'.date('Ymd').'.csv',
    [
        attendanceT('Case ID'),
        attendanceT('Student ID'),
        attendanceT('Student'),
        attendanceT('Form Group'),
        attendanceT('Reason'),
        attendanceT('Source Date Start'),
        attendanceT('Source Date End'),
        attendanceT('Status'),
        attendanceT('Owner'),
        attendanceT('Due Date'),
        attendanceT('Outcome'),
        attendanceT('Retain Until'),
        attendanceT('Notes'),
        attendanceT('Created'),
        attendanceT('Last Updated'),
    ],
    $rows,
    [
        [attendanceT('School Year'), implode(', ', $exportYearNames)],
        [attendanceT('Exported'), Format::dateTime(date('Y-m-d H:i:s'))],
    ]
);
