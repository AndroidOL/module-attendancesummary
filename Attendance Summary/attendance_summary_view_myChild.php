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

use Gibbon\Domain\Students\StudentGateway;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Forms\Form;

require_once __DIR__ . '/moduleFunctions.php';

$page->breadcrumbs->add(attendanceT('View Attendance'));

if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_summary_view_myChild.php')) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

// Build the list of the current user's children.
$studentsDataSet = $container->get(StudentGateway::class)
    ->selectActiveStudentsByFamilyAdult($session->get('gibbonSchoolYearID'), $session->get('gibbonPersonID'))
    ->toDataSet();

$children = [];
foreach ($studentsDataSet as $student) {
    if (empty($student['gibbonPersonID'])) {
        continue;
    }
    $children[$student['gibbonPersonID']] = ($student['surname'] ?? '') . ', ' . ($student['preferredName'] ?? '');
}

if (empty($children)) {
    $page->addError(attendanceT('No children are linked to this account.'));
    return;
}

$selectedStd = is_scalar($_REQUEST['selectedStd'] ?? null) ? trim((string) $_REQUEST['selectedStd']) : '';
if (!array_key_exists($selectedStd, $children)) {
    $selectedStd = array_key_first($children);
}

list($start_date, $end_date) = getValidatedDates($_REQUEST['start_date'] ?? null, $_REQUEST['end_date'] ?? null);

$form = Form::create('gibbonAttendanceSummary', $session->get('absoluteURL') . '/index.php?q=/modules/' . $session->get('module') . '/attendance_summary_view_myChild.php');
$form->setFactory(DatabaseFormFactory::create($pdo));
$form->setTitle(attendanceT('View Attendance'));

if (count($children) === 1) {
    $row = $form->addRow();
    $row->addLabel('fullname', attendanceT('Full Name'));
    $row->addTextField('fullname')->setValue(reset($children))->readonly();
    $form->addHiddenValue('selectedStd', $selectedStd);
} else {
    $row = $form->addRow();
    $row->addLabel('selectedStd', attendanceT('Full Name'));
    $row->addSelect('selectedStd')->fromArray($children)->required()->selected($selectedStd);
}

$row = $form->addRow();
$row->addLabel('start_date', attendanceT('Start Date'));
$row->addDate('start_date')->setValue($start_date);

$row = $form->addRow();
$row->addLabel('end_date', attendanceT('End Date'));
$row->addDate('end_date')->setValue($end_date);

$row = $form->addRow();
$row->addSubmit(attendanceT('Update Dates'));

$page->write($form->getOutput());

// Attendance is always queried from the start of term (or enrolment) so the term
// summary is complete; only the daily table is limited to the chosen date range.
$termFirstDay = resolveAttendanceStartDate($connection2, $selectedStd, getTermFirstDay($connection2, $session->get('gibbonSchoolYearID')));

$attendanceRecords = getAttendanceRecords($connection2, $termFirstDay, $end_date, $selectedStd);
$allDailyAttendance = calculateDailyAttendance($attendanceRecords);
$dailyAttendance = array_values(array_filter($allDailyAttendance, function ($record) use ($start_date) {
    return strtotime($record['date']) >= strtotime($start_date);
}));

$page->write('<h2>' . attendanceT('Attendance') . '</h2>');
if (empty($dailyAttendance)) {
    $page->write($page->getBlankSlate(attendanceT('No attendance records to display.')));
} else {
    $page->write(renderAttendanceDailyTable($dailyAttendance, $attendanceRecords));
    $page->write(renderAttendancePeriodTable(calculateAttendancePeriodSummaries($allDailyAttendance, $termFirstDay, $end_date)));
}
