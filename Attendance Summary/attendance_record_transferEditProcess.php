<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Session\TokenHandler;

require_once '../../gibbon.php';
require_once __DIR__ . '/moduleFunctions.php';

$URL = $session->get('absoluteURL') . '/index.php?q=/modules/Attendance Summary/attendance_record_transfer.php';

if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_record_transfer.php')) {
    header("Location: {$URL}&return=error0");
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header("Location: {$URL}&return=error0");
    exit;
}

if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    header("Location: {$URL}&return=error9");
    exit;
}

$startDate = is_scalar($_POST['start_date'] ?? null) ? trim((string) $_POST['start_date']) : '';
$endDate = is_scalar($_POST['end_date'] ?? null) ? trim((string) $_POST['end_date']) : '';
$start = DateTime::createFromFormat('Y-m-d', $startDate);
$end = DateTime::createFromFormat('Y-m-d', $endDate);
if (
    !$start
    || !$end
    || $start->format('Y-m-d') !== $startDate
    || $end->format('Y-m-d') !== $endDate
    || $start > $end
    || (int) $start->diff($end)->days > 366
) {
    header("Location: {$URL}&return=error1");
    exit;
}

$parsedData = parseAttendance($connection2, $_POST);
$studentID = is_scalar($parsedData['gibbonStudentID'] ?? null) ? trim((string) $parsedData['gibbonStudentID']) : '';
$replacements = $parsedData['replacement'] ?? [];
if ($studentID === '' || empty($replacements)) {
    header("Location: {$URL}&return=error1");
    exit;
}

$parsedData['gibbonStudentID'] = $studentID;
$parsedData['start_date'] = $startDate;
$parsedData['end_date'] = $endDate;

$oldClassIDs = array_values(array_unique(array_map(function ($row) {
    return (string) $row['gibbonCourseClassID_OLD'];
}, $replacements)));

if (!attendanceCanViewAllClasses($session->get('gibbonPersonID'))) {
    $placeholders = implode(',', array_fill(0, count($oldClassIDs), '?'));
    $check = $connection2->prepare(
        "SELECT DISTINCT gibbonCourseClassID
         FROM gibbonCourseClassPerson
         WHERE gibbonPersonID=?
         AND role IN ('Teacher', 'Assistant', 'Technician')
         AND gibbonCourseClassID IN ({$placeholders})"
    );
    $check->execute(array_merge([$session->get('gibbonPersonID')], $oldClassIDs));
    $allowedClasses = array_map('strval', $check->fetchAll(PDO::FETCH_COLUMN));
    if (count(array_diff($oldClassIDs, $allowedClasses)) > 0) {
        header("Location: {$URL}&return=error0");
        exit;
    }
}

$placeholders = implode(',', array_fill(0, count($oldClassIDs), '?'));
$checkStudent = $connection2->prepare(
    "SELECT DISTINCT gibbonCourseClassID
     FROM gibbonCourseClassPerson
     WHERE gibbonPersonID=?
     AND role='Student'
     AND gibbonCourseClassID IN ({$placeholders})"
);
$checkStudent->execute(array_merge([$studentID], $oldClassIDs));
if (count(array_diff($oldClassIDs, array_map('strval', $checkStudent->fetchAll(PDO::FETCH_COLUMN)))) > 0) {
    header("Location: {$URL}&return=error1");
    exit;
}

$URL = processAttendanceTransfer($connection2, $parsedData, $session->get('gibbonPersonID'), $URL);

header("Location: {$URL}");
exit;
