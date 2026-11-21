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

$retentionURL = $session->get('absoluteURL').'/index.php?q=/modules/Attendance Summary/attendance_followup_retention.php';
$redirect = function (string $return) use ($retentionURL) {
    header('Location: '.$retentionURL.'&return='.$return);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $redirect('error3');
}
if (
    !isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_retentionProcess.php')
    || !isActionAccessible($guid, $connection2, '/modules/System Admin/systemSettings.php')
) {
    $redirect('error0');
}
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $redirect('error9');
}

$input = $container->get(Validator::class)->sanitize($_POST);
if (($input['confirmed'] ?? '') !== 'Y' || ($input['confirm'] ?? '') !== 'Y') {
    $redirect('error1');
}

$gibbonPersonID = (string) $session->get('gibbonPersonID');

try {
    $pdo->beginTransaction();
    $expired = $pdo->select(
        "SELECT followUpID, gibbonSchoolYearID
         FROM attendance_follow_up
         WHERE retentionUntil<CURRENT_DATE
         FOR UPDATE"
    )->fetchAll();
    if (empty($expired)) {
        $pdo->commit();
        $redirect('success0');
    }

    $caseIDs = array_map(function ($row) {
        return (string) $row['followUpID'];
    }, $expired);
    $placeholders = [];
    $params = [];
    foreach ($caseIDs as $index => $caseID) {
        $placeholders[] = ':case'.$index;
        $params['case'.$index] = $caseID;
    }
    $caseSQL = implode(',', $placeholders);
    $byYear = [];
    foreach ($expired as $row) {
        $yearID = (string) $row['gibbonSchoolYearID'];
        $byYear[$yearID] = ($byYear[$yearID] ?? 0) + 1;
    }
    foreach ($byYear as $schoolYearID => $caseCount) {
        $noteCountForYear = (int) $pdo->selectOne(
            "SELECT COUNT(*) AS count
             FROM attendance_follow_up_note AS note
             JOIN attendance_follow_up AS followUp ON followUp.followUpID=note.followUpID
             WHERE followUp.gibbonSchoolYearID=:schoolYearID
               AND followUp.retentionUntil<CURRENT_DATE",
            ['schoolYearID' => $schoolYearID]
        )['count'];
        $eventCountForYear = (int) $pdo->selectOne(
            "SELECT COUNT(*) AS count
             FROM attendance_follow_up_event AS event
             JOIN attendance_follow_up AS followUp ON followUp.followUpID=event.followUpID
             WHERE followUp.gibbonSchoolYearID=:schoolYearID
               AND followUp.retentionUntil<CURRENT_DATE",
            ['schoolYearID' => $schoolYearID]
        )['count'];
        $pdo->insert(
            "INSERT INTO attendance_follow_up_retention_log
             SET gibbonPersonIDActor=:actorID, gibbonSchoolYearID=:schoolYearID,
                 caseCount=:caseCount, noteCount=:noteCount, eventCount=:eventCount",
            [
                'actorID'       => $gibbonPersonID,
                'schoolYearID'  => $schoolYearID,
                'caseCount'     => $caseCount,
                'noteCount'     => $noteCountForYear,
                'eventCount'    => $eventCountForYear,
            ]
        );
    }

    $pdo->delete(
        "DELETE FROM attendance_follow_up WHERE followUpID IN (".$caseSQL.")",
        $params
    );
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $redirect('error1');
}

$redirect('success0');
