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

use Gibbon\Domain\FormGroups\FormGroupGateway;
use Gibbon\Domain\Students\StudentGateway;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;

require_once __DIR__ . '/moduleFunctions.php';

$page->breadcrumbs->add(attendanceT('View Attendance'));

if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_summary.php')) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$gibbonFormGroupID = is_scalar($_GET['gibbonFormGroupID'] ?? null) ? trim((string) $_GET['gibbonFormGroupID']) : null;

$page->breadcrumbs->add(attendanceT('Students by Form Group'));

$form = Form::create('action', $session->get('absoluteURL') . '/index.php', 'get');
$form->setTitle(attendanceT('Choose Form Group'))
    ->setFactory(DatabaseFormFactory::create($pdo))
    ->setClass('noIntBorder w-full');

$form->addHiddenValue('q', '/modules/' . $session->get('module') . '/attendance_summary.php');

$row = $form->addRow();
$row->addLabel('gibbonFormGroupID', attendanceT('Form Group'));
$row->addSelectFormGroup('gibbonFormGroupID', $session->get('gibbonSchoolYearID'), true)->selected($gibbonFormGroupID)->placeholder()->required();

$row = $form->addRow();
$row->addFooter();
$row->addSearchSubmit($session);

$page->write($form->getOutput());

// Cancel out early if there's no form group selected
if (!isset($gibbonFormGroupID)) {
    return;
}

$formGroupGateway = $container->get(FormGroupGateway::class);
$studentGateway = $container->get(StudentGateway::class);

$criteria = $studentGateway->newQueryCriteria(true)
    ->sortBy(['formGroup', 'surname', 'preferredName'])
    ->pageSize(50)
    ->fromArray($_GET);

$students = $studentGateway->queryStudentEnrolmentByFormGroup($criteria, $gibbonFormGroupID != '*' ? $gibbonFormGroupID : null);
$studentsAttendance = getAllStudentsAttendanceRate($connection2, $students, $session->get('gibbonSchoolYearID'));
$canCreateFollowUp = isActionAccessible(
    $guid,
    $connection2,
    '/modules/Attendance Summary/attendance_followup_create.php'
);
$followUpStartDate = getTermFirstDay($connection2, $session->get('gibbonSchoolYearID'));
$followUpEndDate = (new DateTime('last Friday'))->format('Y-m-d');

$table = DataTable::createPaginated('studentsByFormGroup', $criteria);
$table->setTitle(attendanceT('Report Data'));
if (isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_create.php')) {
    $table->addHeaderAction('followUp', attendanceT('Create Follow-up'))
        ->setURL('/modules/Attendance Summary/attendance_followup_create.php')
        ->addParam('gibbonFormGroupID', $gibbonFormGroupID)
        ->setIcon('markbook')
        ->displayLabel();
}
if (isActionAccessible($guid, $connection2, '/modules/School Admin/attendanceSettings.php')) {
    $table->addHeaderAction('settings', attendanceT('Attendance Score Settings'))
        ->setURL('/modules/Attendance Summary/attendance_settings.php')
        ->setIcon('settings')
        ->displayLabel();
}
$table->setDescription(function () use ($gibbonFormGroupID, $formGroupGateway) {
    $output = '';

    if ($gibbonFormGroupID == '*') {
        return $output;
    }

    if ($formGroup = $formGroupGateway->getFormGroupByID($gibbonFormGroupID)) {
        $output .= '<b>' . attendanceT('Form Group') . '</b>: ' . htmlPrep($formGroup['name']);
    }
    if ($tutors = $formGroupGateway->selectTutorsByFormGroup($gibbonFormGroupID)->fetchAll()) {
        $output .= '<br/><b>' . attendanceT('Tutors') . '</b>: ' . Format::nameList($tutors, 'Staff');
    }

    return $output;
});

$table->addColumn('student', attendanceT('Student'))
    ->width('30%')
    ->sortable(['surname', 'preferredName'])
    ->format(function ($person) {
        return Format::name('', $person['preferredName'], $person['surname'], 'Student', true, true) . '<br/><small><i>' . Format::userStatusInfo($person) . '</i></small>';
    });

$table->addColumn('yearGroup', attendanceT('Year Group'));
$table->addColumn('formGroup', attendanceT('Form Group'));
$table->addColumn('attendanceRate', sprintf(attendanceT('Attendance Rate to %s'), date('Y-m-d', strtotime('last Friday'))));
$table->addColumn('attendanceDetail', attendanceT('Details'));
$table->addActionColumn()
    ->addParam('gibbonPersonID')
    ->addParam('surname')
    ->addParam('preferredName')
    ->format(function ($row, $actions) use ($canCreateFollowUp, $followUpStartDate, $followUpEndDate) {
        $actions->addAction('profile', attendanceT('View'))
            ->setURL('/modules/Attendance Summary/attendance_summary_view.php');
        $actions->addAction('lifecycle', attendanceT('Full History (School Days)'))
            ->setIcon('attendance')
            ->setURL('/modules/Attendance Summary/attendance_lifecycle.php');
        $actions->addAction('lifecycleCourse', attendanceT('Full History (Courses)'))
            ->setIcon('tasks')
            ->setURL('/modules/Attendance Summary/attendance_lifecycle_course.php');
        if ($canCreateFollowUp) {
            $actions->addAction('followUp', attendanceT('Create Follow-up'))
                ->setIcon('markbook')
                ->setURL('/modules/Attendance Summary/attendance_followup_create.php')
                ->addParam('reason', 'low_attendance')
                ->addParam('start', $followUpStartDate)
                ->addParam('end', $followUpEndDate);
        }
    });

$page->write($table->render($studentsAttendance));
