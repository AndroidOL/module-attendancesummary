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
if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_noteProcess.php')) {
    $redirect('error0');
}
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $redirect('error9');
}

$input = $container->get(Validator::class)->sanitize($_POST);
$noteText = is_scalar($input['noteText'] ?? null) ? trim((string) $input['noteText']) : '';
if ($followUpID === '' || $noteText === '' || mb_strlen($noteText) > 2000) {
    $redirect('error1');
}

$case = attendanceFollowUpLoadCase($pdo, $followUpID);
if (empty($case) || !attendanceFollowUpCanAccessCase($pdo, $gibbonPersonID, $case)) {
    $redirect('error0');
}

try {
    $pdo->beginTransaction();
    $lockedCase = $pdo->selectOne(
        "SELECT * FROM attendance_follow_up
         WHERE followUpID=:followUpID
           AND retentionUntil>=CURRENT_DATE
         FOR UPDATE",
        ['followUpID' => $followUpID]
    );
    if (empty($lockedCase) || !attendanceFollowUpCanAccessCase($pdo, $gibbonPersonID, $lockedCase)) {
        throw new RuntimeException('forbidden');
    }
    $noteID = $pdo->insert(
        "INSERT INTO attendance_follow_up_note
         SET followUpID=:followUpID, gibbonPersonIDAuthor=:authorID, noteText=:noteText",
        ['followUpID' => $followUpID, 'authorID' => $gibbonPersonID, 'noteText' => $noteText]
    );
    if (empty($noteID)) {
        throw new RuntimeException('note-failed');
    }
    attendanceFollowUpRecordEvent($pdo, $followUpID, $gibbonPersonID, 'note_added');
    $pdo->update(
        "UPDATE attendance_follow_up
         SET gibbonPersonIDLastEdit=:lastEditorID, revision=revision+1
         WHERE followUpID=:followUpID",
        ['lastEditorID' => $gibbonPersonID, 'followUpID' => $followUpID]
    );
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $redirect($e->getMessage() === 'forbidden' ? 'error0' : 'error1');
}

$redirect('success2');
