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
use Gibbon\Services\Format;

require_once __DIR__ . '/moduleFunctions.php';

$page->breadcrumbs->add(attendanceT('View Attendance'));

if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_summary_view.php')) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$gibbonPersonID = is_scalar($_REQUEST['gibbonPersonID'] ?? null) ? trim((string) $_REQUEST['gibbonPersonID']) : '';
if ($gibbonPersonID === '') {
    $page->addError(attendanceT('Select a student.'));
    return;
}

$profile = $pdo->selectOne(
    "SELECT title, preferredName, surname FROM gibbonPerson WHERE gibbonPersonID=:gibbonPersonID",
    ['gibbonPersonID' => $gibbonPersonID]
);
if (empty($profile)) {
    $page->addError(attendanceT('The specified record does not exist.'));
    return;
}

list($start_date, $end_date) = getValidatedDates($_REQUEST['start_date'] ?? null, $_REQUEST['end_date'] ?? null);

$form = Form::create('gibbonAttendanceSummary', $session->get('absoluteURL') . '/index.php?q=/modules/' . $session->get('module') . '/attendance_summary_view.php');
$form->setFactory(DatabaseFormFactory::create($pdo));
$form->setTitle(attendanceT('View Attendance'));
$form->addHiddenValue('gibbonPersonID', $gibbonPersonID);

$form->addRow()->addHeading('timeRange', attendanceT('Date Range'));

$row = $form->addRow();
$row->addLabel('fullname', attendanceT('Full Name'));
$row->addTextField('fullname')->setValue(Format::name($profile['title'], $profile['preferredName'], $profile['surname'], 'Student'))->readonly();

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
$termFirstDay = resolveAttendanceStartDate($connection2, $gibbonPersonID, getTermFirstDay($connection2, $session->get('gibbonSchoolYearID')));

$attendanceRecords = getAttendanceRecords($connection2, $termFirstDay, $end_date, $gibbonPersonID);
$allDailyAttendance = calculateDailyAttendance($attendanceRecords);
$dailyAttendance = array_values(array_filter($allDailyAttendance, function ($record) use ($start_date) {
    return strtotime($record['date']) >= strtotime($start_date);
}));
$periodSummaries = calculateAttendancePeriodSummaries($allDailyAttendance, $termFirstDay, $end_date);

if (($_GET['export'] ?? '') === 'csv') {
    $rows = [];
    foreach ($dailyAttendance as $day) {
        $rows[] = [$day['date'], $day['standard'], $day['attendance']];
    }
    sendAttendanceCsv(
        'attendance-summary-'.date('Ymd').'.csv',
        [attendanceT('Date'), attendanceT('Standard'), attendanceT('Day Attendance')],
        $rows
    );
}

$actions = Form::createBlank('attendanceSummaryActions');
$actions->addHeaderAction('export', attendanceT('Export CSV'))
    ->setURL('/index.php')
    ->addParam('q', '/modules/Attendance Summary/attendance_summary_view.php')
    ->addParam('gibbonPersonID', $gibbonPersonID)
    ->addParam('start_date', $start_date)
    ->addParam('end_date', $end_date)
    ->addParam('export', 'csv')
    ->setIcon('download')
    ->directLink()
    ->displayLabel();

$page->write($actions->getOutput());
$page->write('<h2>' . attendanceT('Attendance') . '</h2>');
if (empty($dailyAttendance)) {
    $page->write($page->getBlankSlate(attendanceT('No attendance records to display.')));
} else {
    $page->write(renderAttendanceDailyTable($dailyAttendance, $attendanceRecords));
    $page->write(renderAttendancePeriodTable($periodSummaries));
}
