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

$page->breadcrumbs->add(attendanceT('Transfer Attendance'));

$page->return->addReturns([
    'success0' => attendanceT('Attendance records transferred.'),
    'error0' => attendanceT('You do not have permission to perform this action.'),
    'error1' => attendanceT('The submitted data is invalid. Select the records again.'),
    'error2' => attendanceT('Transfer failed. Try again shortly.'),
    'error9' => attendanceT('Your session expired. Refresh the page and try again.'),
]);

if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_record_transfer.php')) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$gibbonPersonID = is_scalar($_REQUEST['gibbonPersonID'] ?? null) ? trim((string) $_REQUEST['gibbonPersonID']) : '';
if ($gibbonPersonID === '') {
    $page->addError(attendanceT('Select a student.'));
    return;
}
if (!attendanceCanAccessStudent($pdo, $session->get('gibbonPersonID'), $gibbonPersonID, $session->get('gibbonSchoolYearID'))) {
    $page->addError(attendanceT('You do not have access to this student.'));
    return;
}

$student = $pdo->selectOne(
    "SELECT gibbonPersonID FROM gibbonPerson WHERE gibbonPersonID=:gibbonPersonID",
    ['gibbonPersonID' => $gibbonPersonID]
);
if (empty($student)) {
    $page->addError(attendanceT('The specified record does not exist.'));
    return;
}

$gibbonCourseClassID = is_scalar($_REQUEST['gibbonCourseClassID'] ?? null) ? trim((string) $_REQUEST['gibbonCourseClassID']) : '';

// Step 1: choose the course class whose attendance will be transferred.
if ($gibbonCourseClassID === '') {
    $form = Form::create('gibbonCourseClassChoose', $session->get('absoluteURL') . '/index.php', 'get');
    $form->addHiddenValue('q', '/modules/' . $session->get('module') . '/attendance_record_transfer.php');
    $form->setFactory(DatabaseFormFactory::create($pdo));
    $form->setTitle(attendanceT('Choose Class'));

    $classes = [];

    // Classes taught by the current user that also contain the selected student.
    $sql = "SELECT gcc.gibbonCourseClassID AS value, CONCAT(gc.nameShort, '.', gcc.nameShort) AS name
            FROM gibbonCourseClass AS gcc
            JOIN gibbonCourse AS gc ON gcc.gibbonCourseID = gc.gibbonCourseID
            JOIN gibbonCourseClassPerson AS cp_teacher ON cp_teacher.gibbonCourseClassID = gcc.gibbonCourseClassID
            WHERE gc.gibbonSchoolYearID = :gibbonSchoolYearID
              AND cp_teacher.gibbonPersonID = :gibbonPersonID
              AND cp_teacher.role IN ('Teacher', 'Assistant', 'Technician')
              AND EXISTS (
                  SELECT 1 FROM gibbonCourseClassPerson
                  WHERE gibbonCourseClassID = gcc.gibbonCourseClassID
                    AND gibbonPersonID = :gibbonStudentID
                    AND role = 'Student'
              )
            ORDER BY name";
    $result = $pdo->executeQuery(
        ['gibbonSchoolYearID' => $session->get('gibbonSchoolYearID'), 'gibbonPersonID' => $session->get('gibbonPersonID'), 'gibbonStudentID' => $gibbonPersonID],
        $sql
    );
    if ($result->rowCount() > 0) {
        $classes['--' . attendanceT('My Classes') . '--'] = $result->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    // All classes for the student, available to a small set of administrators.
    if (attendanceCanViewAllClasses($session->get('gibbonPersonID'))) {
        $sql = "SELECT DISTINCT gcc.gibbonCourseClassID AS value, CONCAT(gc.nameShort, '.', gcc.nameShort) AS name
                FROM gibbonCourseClass AS gcc
                JOIN gibbonCourse AS gc ON gcc.gibbonCourseID = gc.gibbonCourseID
                JOIN gibbonCourseClassPerson AS cp ON cp.gibbonCourseClassID = gcc.gibbonCourseClassID
                WHERE cp.gibbonPersonID = :gibbonStudentID
                  AND cp.role = 'Student'
                  AND gc.gibbonSchoolYearID = :gibbonSchoolYearID
                ORDER BY name";
        $result = $pdo->executeQuery(
            ['gibbonStudentID' => $gibbonPersonID, 'gibbonSchoolYearID' => $session->get('gibbonSchoolYearID')],
            $sql
        );
        if ($result->rowCount() > 0) {
            $classes['--' . attendanceT('All Classes') . '--'] = $result->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
    }

    if (empty($classes)) {
        $page->addError(attendanceT('No transferable classes were found.'));
        return;
    }

    $row = $form->addRow();
    $row->addLabel('gibbonCourseClassID', attendanceT('Class Name'));
    $row->addSelect('gibbonCourseClassID')->fromArray($classes)->required()->placeholder();

    $form->addHiddenValue('gibbonPersonID', $gibbonPersonID);

    $row = $form->addRow();
    $row->addSubmit(attendanceT('View Timetable'));

    $page->write($form->getOutput());

    return;
}

// Step 2: confirm the timetable lessons to transfer.
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

$form = Form::create('gibbonAttendanceTransfer', $session->get('absoluteURL') . '/index.php?q=/modules/' . $session->get('module') . '/attendance_record_transferEdit.php');
$form->setFactory(DatabaseFormFactory::create($pdo));
$form->setTitle(attendanceT('Confirm Timetable Lessons'));
$form->setDescription('<b>' . attendanceT('Class Name') . '</b>: ' . htmlPrep($class['name']));

$form->addRow()->addHeading('transferRecord', attendanceT('Transfer Attendance Records'));

$row = $form->addRow();
$row->addLabel('start_date', attendanceT('Start Date'));
$row->addDate('start_date')->setValue(getTermFirstDay($connection2, $session->get('gibbonSchoolYearID')));

$row = $form->addRow();
$row->addLabel('end_date', attendanceT('End Date'));
$row->addDate('end_date')->setValue(date('Y-m-d'));

$data = ['gibbonCourseClassID' => $gibbonCourseClassID];
$sql = "SELECT
            tdc.gibbonTTDayRowClassID,
            tdc.gibbonCourseClassID,
            CONCAT(c.nameShort, '-', cc.nameShort) AS courseName,
            tday.name AS dayName,
            tcolrow.gibbonTTColumnRowID,
            tcolrow.name AS periodName,
            tcolrow.timeStart,
            tcolrow.timeEnd
        FROM gibbonttdayrowclass AS tdc
        JOIN gibboncourseclass AS cc ON cc.gibbonCourseClassID = tdc.gibbonCourseClassID
        JOIN gibboncourse AS c ON c.gibbonCourseID = cc.gibbonCourseID
        JOIN gibbonttday AS tday ON tday.gibbonTTDayID = tdc.gibbonTTDayID
        JOIN gibbonttcolumnrow AS tcolrow ON tcolrow.gibbonTTColumnRowID = tdc.gibbonTTColumnRowID
        WHERE tdc.gibbonCourseClassID = :gibbonCourseClassID
        ORDER BY tday.name, tcolrow.timeStart";
$result = $pdo->executeQuery($data, $sql);

$courses = ($result->rowCount() > 0) ? $result->fetchAll() : [];

if (empty($courses)) {
    $form->addRow()->addHeading('course', attendanceT('Timetable Lessons'));
    $form->addRow()->addAlert(attendanceT('There are no records to display.'), 'error');
} else {
    $table = $form->addRow()->addTable()->setClass('smallIntBorder w-full colorOddEven noMargin noPadding noBorder');

    $header = $table->addHeaderRow();
    $header->addTableCell(attendanceT('Class Name'));
    $header->addTableCell(attendanceT('Timetable Day'));
    $header->addTableCell(attendanceT('Lesson'));
    $header->addTableCell(attendanceT('Start Time'));
    $header->addTableCell(attendanceT('End Time'));
    $header->addTableCell(attendanceT('Transfer'));

    foreach ($courses as $course) {
        $row = $table->addRow();
        $row->addColumn()->addContent(htmlPrep($course['courseName']));
        $row->addColumn()->addContent(htmlPrep($course['dayName']));
        $row->addColumn()->addContent(htmlPrep($course['periodName']));
        $row->addColumn()->addContent(htmlPrep($course['timeStart']));
        $row->addColumn()->addContent(htmlPrep($course['timeEnd']));
        $row->addCheckbox('transfer-' . $course['gibbonTTDayRowClassID'])->checked(false);
    }
}

$form->addHiddenValue('gibbonPersonID', $gibbonPersonID);
$form->addHiddenValue('gibbonCourseClassID', $gibbonCourseClassID);

$row = $form->addRow();
$row->addSubmit(attendanceT('Choose Replacement Classes'));

$page->write($form->getOutput());
