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

$createURL = $session->get('absoluteURL').'/index.php?q=/modules/Attendance Summary/attendance_followup_create.php';
$redirect = function (string $return) use ($createURL) {
    header('Location: '.$createURL.'&return='.$return);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $redirect('error3');
}
if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_createProcess.php')) {
    $redirect('error0');
}
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $redirect('error9');
}

$input = $container->get(Validator::class)->sanitize($_POST);
if (empty($input)) {
    $redirect('error3');
}

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$gibbonSchoolYearID = (string) $session->get('gibbonSchoolYearID');
$studentID = is_scalar($input['gibbonPersonIDStudent'] ?? null) ? trim((string) $input['gibbonPersonIDStudent']) : '';
$reasonCode = is_scalar($input['reasonCode'] ?? null) ? trim((string) $input['reasonCode']) : '';
$dateStart = is_scalar($input['sourceDateStart'] ?? null)
    ? Format::dateConvert((string) $input['sourceDateStart'])
    : '';
$dateEnd = is_scalar($input['sourceDateEnd'] ?? null)
    ? Format::dateConvert((string) $input['sourceDateEnd'])
    : '';
$dueDate = is_scalar($input['dueDate'] ?? null)
    ? Format::dateConvert((string) $input['dueDate'])
    : '';
$initialNote = is_scalar($input['initialNote'] ?? null) ? trim((string) $input['initialNote']) : '';

if (
    !isset(attendanceFollowUpReasons()[$reasonCode])
    || !attendanceFollowUpIsValidDate($dateStart)
    || !attendanceFollowUpIsValidDate($dateEnd)
    || $dateStart > $dateEnd
    || !attendanceFollowUpIsValidDate($dueDate)
    || mb_strlen($initialNote) > 2000
    || !attendanceFollowUpCanAccessStudent($pdo, $gibbonPersonID, $studentID, $gibbonSchoolYearID)
) {
    $redirect('error1');
}

$schoolYear = $pdo->selectOne(
    "SELECT firstDay, lastDay FROM gibbonSchoolYear WHERE gibbonSchoolYearID=:gibbonSchoolYearID",
    ['gibbonSchoolYearID' => $gibbonSchoolYearID]
);
$enrolment = attendanceFollowUpCurrentEnrolment($pdo, $studentID, $gibbonSchoolYearID);
if (
    empty($schoolYear)
    || empty($enrolment)
    || !attendanceFollowUpStudentIsFull($enrolment)
    || $dateStart < $schoolYear['firstDay']
    || $dateEnd > $schoolYear['lastDay']
) {
    $redirect('error1');
}

$retentionUntil = attendanceFollowUpRetentionUntil($schoolYear['lastDay']);
if ($retentionUntil === null) {
    $redirect('error1');
}
$activeKey = attendanceFollowUpActiveKey($studentID, $gibbonSchoolYearID, $reasonCode, $dateStart, $dateEnd);

try {
    $pdo->beginTransaction();
    $followUpID = $pdo->insert(
        "INSERT INTO attendance_follow_up
         SET gibbonPersonIDStudent=:studentID, gibbonSchoolYearID=:schoolYearID,
             gibbonFormGroupID=:formGroupID, reasonCode=:reasonCode,
             sourceDateStart=:dateStart, sourceDateEnd=:dateEnd, status='open',
             gibbonPersonIDOwner=:ownerID, gibbonPersonIDCreator=:creatorID,
             dueDate=:dueDate, activeKey=:activeKey, retentionUntil=:retentionUntil,
             gibbonPersonIDLastEdit=:creatorID",
        [
            'studentID'      => $studentID,
            'schoolYearID'   => $gibbonSchoolYearID,
            'formGroupID'    => $enrolment['gibbonFormGroupID'],
            'reasonCode'     => $reasonCode,
            'dateStart'      => $dateStart,
            'dateEnd'        => $dateEnd,
            'ownerID'        => $gibbonPersonID,
            'creatorID'      => $gibbonPersonID,
            'dueDate'        => $dueDate,
            'activeKey'      => $activeKey,
            'retentionUntil' => $retentionUntil,
        ]
    );
    if (empty($followUpID)) {
        throw new RuntimeException('Unable to create case');
    }

    attendanceFollowUpRecordEvent($pdo, $followUpID, $gibbonPersonID, 'created');
    if ($initialNote !== '') {
        $noteID = $pdo->insert(
            "INSERT INTO attendance_follow_up_note
             SET followUpID=:followUpID, gibbonPersonIDAuthor=:authorID, noteText=:noteText",
            [
                'followUpID' => $followUpID,
                'authorID'   => $gibbonPersonID,
                'noteText'   => $initialNote,
            ]
        );
        if (empty($noteID)) {
            throw new RuntimeException('Unable to create note');
        }
        attendanceFollowUpRecordEvent($pdo, $followUpID, $gibbonPersonID, 'note_added');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $redirect(($e instanceof PDOException && (string) $e->getCode() === '23000') ? 'error2' : 'error1');
}

header(
    'Location: '.$session->get('absoluteURL').'/index.php?q=/modules/Attendance Summary/attendance_followup_view.php'
    .'&followUpID='.urlencode((string) $followUpID).'&return=success0'
);
exit;
