<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
*/

use Gibbon\Data\Validator;
use Gibbon\Services\Format;
use Gibbon\Session\TokenHandler;

require_once __DIR__.'/../../gibbon.php';
require_once __DIR__.'/moduleFunctions.php';
require_once __DIR__.'/followupFunctions.php';

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$followUpID = is_scalar($_POST['followUpID'] ?? null) ? trim((string) $_POST['followUpID']) : '';
$viewURL = $session->get('absoluteURL').'/index.php?q=/modules/Attendance Summary/attendance_followup_view.php&followUpID='.urlencode($followUpID);
$redirect = function (string $return) use ($viewURL) {
    header('Location: '.$viewURL.'&return='.$return);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $redirect('error3');
}
if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_updateProcess.php')) {
    $redirect('error0');
}
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $redirect('error9');
}

$input = $container->get(Validator::class)->sanitize($_POST);
if (empty($input)) {
    $redirect('error3');
}
$revision = is_scalar($input['revision'] ?? null) ? max(1, (int) $input['revision']) : 0;
if ($followUpID === '' || $revision < 1) {
    $redirect('error1');
}

$case = attendanceFollowUpLoadCase($pdo, $followUpID);
if (empty($case) || !attendanceFollowUpCanAccessCase($pdo, $gibbonPersonID, $case)) {
    $redirect('error0');
}

$ownerID = is_scalar($input['gibbonPersonIDOwner'] ?? null) ? trim((string) $input['gibbonPersonIDOwner']) : '';
$status = is_scalar($input['status'] ?? null) ? trim((string) $input['status']) : '';
$dueDate = is_scalar($input['dueDate'] ?? null) ? Format::dateConvert((string) $input['dueDate']) : '';
$resolutionCode = is_scalar($input['resolutionCode'] ?? null) ? trim((string) $input['resolutionCode']) : '';
$resolutionNote = is_scalar($input['resolutionNote'] ?? null) ? trim((string) $input['resolutionNote']) : '';

$caseSchoolYearID = (string) $case['gibbonSchoolYearID'];
$ownerOptions = attendanceFollowUpOwnerOptions(
    $pdo,
    $case['gibbonPersonIDStudent'],
    $caseSchoolYearID,
    $gibbonPersonID,
    $case['gibbonPersonIDOwner']
);
if (
    !isset($ownerOptions[$ownerID])
    || !attendanceFollowUpOwnerIsEligible(
        $pdo,
        $ownerID,
        $case['gibbonPersonIDStudent'],
        $caseSchoolYearID,
        $gibbonPersonID,
        $case['gibbonPersonIDOwner']
    )
    || !isset(attendanceFollowUpStatuses()[$status])
    || ($status !== $case['status'] && !attendanceFollowUpStatusAllowsTransition($case['status'], $status))
    || !attendanceFollowUpIsValidDate($dueDate)
    || mb_strlen($resolutionNote) > 2000
) {
    $redirect('error1');
}
if ($status === 'resolved') {
    if (
        !isset(attendanceFollowUpResolutionOptions()[$resolutionCode])
        || ($case['status'] !== 'resolved' && $resolutionNote === '')
    ) {
        $redirect('error1');
    }
} else {
    $resolutionCode = '';
    $resolutionNote = '';
}

try {
    $pdo->beginTransaction();
    $locked = $pdo->selectOne(
        "SELECT * FROM attendance_follow_up
         WHERE followUpID=:followUpID
         FOR UPDATE",
        ['followUpID' => $followUpID]
    );
    if (empty($locked) || (int) $locked['revision'] !== $revision) {
        throw new RuntimeException('stale');
    }
    if (!attendanceFollowUpCanAccessCase($pdo, $gibbonPersonID, $locked)) {
        throw new RuntimeException('forbidden');
    }

    $isActive = attendanceFollowUpIsActiveStatus($status);
    $activeKey = $isActive
        ? attendanceFollowUpActiveKey(
            $locked['gibbonPersonIDStudent'],
            $locked['gibbonSchoolYearID'],
            $locked['reasonCode'],
            $locked['sourceDateStart'],
            $locked['sourceDateEnd']
        )
        : null;
    $resolvedAt = $status === 'resolved'
        ? ($locked['status'] === 'resolved' ? $locked['resolvedAt'] : date('Y-m-d H:i:s'))
        : null;

    $pdo->update(
        "UPDATE attendance_follow_up
         SET gibbonPersonIDOwner=:ownerID, status=:status, dueDate=:dueDate,
             resolutionCode=:resolutionCode, activeKey=:activeKey, resolvedAt=:resolvedAt,
             gibbonPersonIDLastEdit=:lastEditorID, revision=revision+1
         WHERE followUpID=:followUpID AND revision=:revision",
        [
            'ownerID'        => $ownerID,
            'status'         => $status,
            'dueDate'        => $dueDate,
            'resolutionCode' => $resolutionCode === '' ? null : $resolutionCode,
            'activeKey'      => $activeKey,
            'resolvedAt'     => $resolvedAt,
            'lastEditorID'   => $gibbonPersonID,
            'followUpID'     => $followUpID,
            'revision'       => $revision,
        ]
    );

    $ownerNames = $ownerOptions;
    if ((string) $locked['gibbonPersonIDOwner'] !== $ownerID) {
        attendanceFollowUpRecordEvent(
            $pdo,
            $followUpID,
            $gibbonPersonID,
            'owner_changed',
            'owner',
            $ownerNames[(string) $locked['gibbonPersonIDOwner']] ?? (string) $locked['gibbonPersonIDOwner'],
            $ownerNames[$ownerID] ?? $ownerID
        );
    }
    if ((string) $locked['status'] !== $status) {
        attendanceFollowUpRecordEvent(
            $pdo,
            $followUpID,
            $gibbonPersonID,
            'status_changed',
            'status',
            attendanceFollowUpStatusLabel($locked['status']),
            attendanceFollowUpStatusLabel($status)
        );
    }
    if ((string) $locked['dueDate'] !== $dueDate) {
        attendanceFollowUpRecordEvent(
            $pdo,
            $followUpID,
            $gibbonPersonID,
            'due_changed',
            'dueDate',
            (string) $locked['dueDate'],
            $dueDate
        );
    }
    if ((string) ($locked['resolutionCode'] ?? '') !== $resolutionCode) {
        attendanceFollowUpRecordEvent(
            $pdo,
            $followUpID,
            $gibbonPersonID,
            'resolution_changed',
            'outcome',
            attendanceFollowUpResolutionLabel($locked['resolutionCode'] ?? ''),
            attendanceFollowUpResolutionLabel($resolutionCode)
        );
    }
    if ($resolutionNote !== '') {
        $noteID = $pdo->insert(
            "INSERT INTO attendance_follow_up_note
             SET followUpID=:followUpID, gibbonPersonIDAuthor=:authorID, noteText=:noteText",
            ['followUpID' => $followUpID, 'authorID' => $gibbonPersonID, 'noteText' => $resolutionNote]
        );
        if (empty($noteID)) {
            throw new RuntimeException('note-failed');
        }
        attendanceFollowUpRecordEvent($pdo, $followUpID, $gibbonPersonID, 'note_added');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
        $redirect('error2');
    }
    $redirect($e->getMessage() === 'stale' ? 'error4' : ($e->getMessage() === 'forbidden' ? 'error0' : 'error1'));
}

$redirect('success1');
