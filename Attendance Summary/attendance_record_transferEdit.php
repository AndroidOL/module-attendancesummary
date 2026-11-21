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

use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Forms\Form;

require_once __DIR__ . '/moduleFunctions.php';

$page->breadcrumbs->add(attendanceT('Confirm Attendance Transfer'));

if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_record_transfer.php')) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$gibbonCourseClassID = is_scalar($_REQUEST['gibbonCourseClassID'] ?? null) ? trim((string) $_REQUEST['gibbonCourseClassID']) : '';
$gibbonPersonID = is_scalar($_REQUEST['gibbonPersonID'] ?? null) ? trim((string) $_REQUEST['gibbonPersonID']) : '';
if ($gibbonCourseClassID === '' || $gibbonPersonID === '') {
    $page->addError(attendanceT('Select a class.'));
    return;
}

$class = getAttendanceTransferClass(
    $pdo,
    $gibbonCourseClassID,
    $session->get('gibbonPersonID'),
    $gibbonPersonID,
    $session->get('gibbonSchoolYearID')
);
if (empty($class)) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$transferData = getTransferData($_REQUEST);
if (empty($transferData)) {
    $page->addError(attendanceT('Select a class.'));
    return;
}

$startDate = is_scalar($_REQUEST['start_date'] ?? null) ? trim((string) $_REQUEST['start_date']) : '';
$endDate = is_scalar($_REQUEST['end_date'] ?? null) ? trim((string) $_REQUEST['end_date']) : '';
$start = DateTime::createFromFormat('Y-m-d', $startDate);
$end = DateTime::createFromFormat('Y-m-d', $endDate);
if (!$start || !$end || $start->format('Y-m-d') !== $startDate || $end->format('Y-m-d') !== $endDate || $start > $end) {
    $page->addError(attendanceT('Select a valid date range.'));
    return;
}

$form = Form::createBlank('attendanceRecordTransferEdit', $session->get('absoluteURL') . '/modules/' . $session->get('module') . '/attendance_record_transferEditProcess.php');
$form->setFactory(DatabaseFormFactory::create($pdo));

// Find the lessons that share the same day/period as the selected ones, across all classes.
$data = ['gibbonCourseClassID' => $gibbonCourseClassID];
$sql = "SELECT DISTINCT
            tdc.gibbonTTDayRowClassID,
            ccs.gibbonCourseClassID,
            CONCAT(c.nameShort, '-', ccs.nameShort) AS courseName,
            tday.name AS dayName,
            tcolrow.gibbonTTColumnRowID,
            tcolrow.name AS periodName,
            tcolrow.timeStart,
            tcolrow.timeEnd
        FROM gibbonttdayrowclass AS tdc
        JOIN gibboncourseclass AS ccs ON ccs.gibbonCourseClassID = tdc.gibbonCourseClassID
        JOIN gibboncourse AS c ON c.gibbonCourseID = ccs.gibbonCourseID
        JOIN gibbonttday AS tday ON tday.gibbonTTDayID = tdc.gibbonTTDayID
        JOIN gibbonttcolumnrow AS tcolrow ON tcolrow.gibbonTTColumnRowID = tdc.gibbonTTColumnRowID
        JOIN gibbontt AS tt ON tt.gibbonTTID = tday.gibbonTTID
        JOIN (
            SELECT DISTINCT tdc2.gibbonTTDayID, tdc2.gibbonTTColumnRowID
            FROM gibbonttdayrowclass AS tdc2
            WHERE tdc2.gibbonCourseClassID = :gibbonCourseClassID
        ) AS base ON tday.gibbonTTDayID = base.gibbonTTDayID AND tcolrow.gibbonTTColumnRowID = base.gibbonTTColumnRowID
        ORDER BY tday.name, tcolrow.timeStart";
$result = $pdo->executeQuery($data, $sql);
$courses = ($result->rowCount() > 0) ? $result->fetchAll() : [];

$newArray = generateTransferData($transferData, $courses);
if (empty($newArray)) {
    $page->addError(attendanceT('No transferable classes were found.'));
    return;
}

$form->addHiddenValue('gibbonStudentID', $gibbonPersonID);
$form->addHiddenValue('gibbonCourseClassID', $gibbonCourseClassID);
$form->addHiddenValue('start_date', $startDate);
$form->addHiddenValue('end_date', $endDate);

foreach ($newArray as $item) {
    $form->addRow()->addHeading('transferPeriod' . $item['gibbonTTDayRowClassID_OLD'], sprintf(attendanceT('Period: %s to %s'), htmlPrep($startDate), htmlPrep($endDate)));
    $form->addRow()->addHeading('transferLesson' . $item['gibbonTTDayRowClassID_OLD'], htmlPrep($item['gibbonCourseClassName_OLD']) . ' (' . htmlPrep(removeAlphaNumeric($item['gibbonTTDayName'])) . ' / ' . htmlPrep($item['gibbonTTDayRowClassName_OLD']) . ')');

    $table = $form->addRow()->addTable()->setClass('smallIntBorder colorOddEven w-full standardForm');

    $header = $table->addHeaderRow();
    $header->addContent(attendanceT('Current Class'));
    $header->addContent(attendanceT('Replacement Class'));

    $row = $table->addRow();

    $recordCount = getAttendanceLogCount($connection2, $item['gibbonPersonID'], $item['gibbonCourseClassID_OLD'], $item['gibbonTTDayRowClassID_OLD'], $startDate, $endDate);
    $oldCourseInfo = htmlPrep($item['gibbonCourseClassName_OLD']) . '.' . htmlPrep($item['gibbonTTDayRowClassName_OLD']);
    $recordCountLabel = $recordCount >= 0 ? (string) $recordCount : attendanceT('Unknown');
    $attRecordInfo = '<em>' . attendanceT('Attendance records') . ': ' . $recordCountLabel . attendanceT(' records') . '</em>';
    $row->addContent($oldCourseInfo . '<br/>' . $attRecordInfo)->setClass('w-1/2');

    if (!empty($item['transferableList'])) {
        $options = [];
        foreach ($item['transferableList'] as $option) {
            $options[$option['gibbonCourseClassID_NEW'] . '.' . $option['gibbonTTDayRowClassID_NEW']] = $option['gibbonCourseClassName_NEW'];
        }

        $row->addSelect('replacement-' . $item['gibbonCourseClassID_OLD'] . '_' . $item['gibbonTTDayRowClassID_OLD'])
            ->fromArray($options)
            ->selected('')
            ->setClass('w-1/2');
    } else {
        $row->addContent('<em>' . attendanceT('No replacement class') . '</em>')->setClass('w-1/2');
    }
}

$table = $form->addRow()->addTable()->setClass('smallIntBorder colorOddEven w-full standardForm');

$row = $table->addRow();
$row->addFooter();
$row->addSubmit();

$page->write($form->getOutput());
