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
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\Domain\System\SettingGateway;
use Gibbon\Forms\Form;
use Gibbon\UI\Chart\Chart;
use Gibbon\Services\Format;
use Gibbon\Module\Attendance\AttendanceView;
use Gibbon\Domain\Students\StudentGateway;

// Module includes
require_once __DIR__ . '/moduleFunctions.php';

if (isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_report.php') == false) {
    // Access denied
    $page->addError(attendanceT('You do not have access to this action.'));
} else {
    // Proceed!
    $page->breadcrumbs->add(attendanceT('Attendance Trends'));
    $page->scripts->add('chart');
    
    // Load the student DataSet.
    $studentGateway    = $container->get(StudentGateway::class);
    $studentsDataset   = $studentGateway->selectActiveStudentsByFamilyAdult(
        $session->get('gibbonSchoolYearID'),
        $session->get('gibbonPersonID')
    )->toDataSet();
    
    // Build StudentID/name options and resolve the selected student.
    $gibbonPersonIDs  = [];
    $selectedStdID    = is_scalar($_REQUEST['selectedStd'] ?? null) ? (string) $_REQUEST['selectedStd'] : '';
    $selectedStdIndex = 0;
    $currentIndex     = 0;
    
    foreach ($studentsDataset as $student) {
        if (isset($student['gibbonPersonID'])) {
            $gibbonPersonIDs[] = [
                'StudentID'     => $student['gibbonPersonID'],
                'Surname'       => $student['surname'],
                'PreferredName' => $student['preferredName'],
            ];
            if ($student['gibbonPersonID'] === $selectedStdID) {
                $selectedStdIndex = $currentIndex;
            }
            $currentIndex++;
        }
    }
    
    // Fall back to the first student when the index is invalid.
    if (!is_numeric($selectedStdIndex) || $selectedStdIndex < 0 || $selectedStdIndex >= count($gibbonPersonIDs)) {
        $selectedStdIndex = 0;
    }
    
    // No linked students.
    if (count($gibbonPersonIDs) < 1) {
        $page->addError(attendanceT('No children are linked to this account.'));
        return;
    }

    // Selected student data.
    $selectedStd  = $gibbonPersonIDs[$selectedStdIndex];

    $settingGateway = $container->get(SettingGateway::class);

    $dateEndInput = is_scalar($_POST['dateEnd'] ?? null) ? (string) $_POST['dateEnd'] : '';
    $dateStartInput = is_scalar($_POST['dateStart'] ?? null) ? (string) $_POST['dateStart'] : '';
    $dateEnd = $dateEndInput !== '' ? Format::dateConvert($dateEndInput) : date('Y-m-d');
    $dateStart = $dateStartInput !== '' ? Format::dateConvert($dateStartInput) : date('Y-m-d', strtotime($dateEnd . ' -1 month'));
    if (empty($dateEnd)) {
        $dateEnd = date('Y-m-d');
    }
    if (empty($dateStart)) {
        $dateStart = date('Y-m-d', strtotime($dateEnd . ' -1 month'));
    }
    $countClassAsSchool = $settingGateway->getSettingByScope('Attendance', 'countClassAsSchool');

    // Correct inverse date ranges rather than generating an error
    if ($dateStart > $dateEnd) {
        $swapDates = $dateStart;
        $dateStart = $dateEnd;
        $dateEnd = $swapDates;
    }

    // Limit date range to the current school year
    if ($dateStart < $session->get('gibbonSchoolYearFirstDay')) {
        $dateStart = $session->get('gibbonSchoolYearFirstDay');
    }

    if ($dateEnd > $session->get('gibbonSchoolYearLastDay')) {
        $dateEnd = $session->get('gibbonSchoolYearLastDay');
    }

    require_once __DIR__ . '/../../Attendance/src/AttendanceView.php';
    $attendance = new AttendanceView($gibbon, $pdo, $settingGateway);

    if (isset($_POST['types']) && isset($_POST['dateStart'])) {
        $types = is_array($_POST['types']) ? array_map('strval', array_filter($_POST['types'], 'is_scalar')) : [];
    } else {
        if (!isset($_POST['dateStart'])) {
            $types = $attendance->getAttendanceTypes(true);
            unset($types[0]);
        } else {
            $types = array();
        }
    }

    // Options & Filters
    $form = Form::create('attendanceTrends', $session->get('absoluteURL').'/index.php?q=/modules/'.$session->get('module').'/attendance_report.php');
    $form->setTitle(attendanceT('Choose Date'));
    $form->setClass('noIntBorder w-full');

    $form->addHiddenValue('address', $session->get('address'));

    // Show a selector only when more than one student is linked.
    if (count($gibbonPersonIDs) === 1) {
        $row = $form->addRow();
        $row->addLabel('fullname', attendanceT('Full Name'));
        $row->addTextField('fullname')
            ->setValue(($selectedStd['Surname'] ?? '') . ', ' . ($selectedStd['PreferredName'] ?? ''))
            ->readonly();
        $form->addHiddenValue('selectedStd', $selectedStd['StudentID']);
    } else {
        // Build options keyed by StudentID.
        $selectOptions = [];
        foreach ($gibbonPersonIDs as $student) {
            $selectOptions[$student['StudentID']] = ($student['Surname'] ?? '') . ', ' . ($student['PreferredName'] ?? '');
        }
        $row = $form->addRow();
        $row->addLabel('fullname', attendanceT('Full Name'));
        $row->addSelect('selectedStd')->fromArray($selectOptions)->required()->selected($selectedStd['StudentID']);
    }

    $row = $form->addRow();
        $row->addLabel('dateStart', attendanceT('Start Date'));
        $row->addDate('dateStart')->setValue(Format::date($dateStart))->required();

    $row = $form->addRow();
        $row->addLabel('dateEnd', attendanceT('End Date'));
        $row->addDate('dateEnd')->setValue(Format::date($dateEnd))->required();

    $row = $form->addRow();
        $row->addLabel('types', attendanceT('Types'));
        $row->addSelect('types')->fromArray($attendance->getAttendanceTypes(true))->selectMultiple()->selected($types);

    $row = $form->addRow();
        $row->addFooter();
        $row->addSearchSubmit($session);

    echo $form->getOutput();


    if ($dateStart != '') {
        echo '<h2>';
        echo attendanceT('Report Data');
        echo '</h2>';

        echo '<p><span class="text-xs italic">'.attendanceT('Click a legend item to toggle visibility.').'</span></p>';

        // Produce array of attendance data
        $rows = getAttendanceSummary($connection2, $dateStart, $dateEnd, $selectedStd['StudentID'], $countClassAsSchool);


        if (empty($rows)) {
            echo $page->getBlankSlate();
        } else {
            $data = [];
            $days = [];

            // Get the date range and filter school days
            $dateRange = new DatePeriod(
                new DateTime($dateStart),
                new DateInterval('P1D'),
                (new DateTime($dateEnd))->modify('+1 day')
            );
            foreach ($dateRange as $dateObject) {
                $date = $dateObject->format('Y-m-d');
                if (isSchoolOpen($guid, $date, $connection2)) {
                    $days[] = $date;
                }
            }

            // Fill each date with zeroes for each type & reason
            foreach ($days as $date) {
                foreach ($types as $type) {
                    $data[$type][$date] = 0;
                }
            }

            // Sum the counts for each type and reason
            foreach ($rows as $row) {
                if (isset($data[$row['name']][$row['date']])) {
                    $data[$row['name']][$row['date']] += $row['count'];
                }
                if (isset($data[$row['reason']][$row['date']])) {
                    $data[$row['reason']][$row['date']] += $row['count'];
                }
            }

            $chart = Chart::create('attendance', 'line')
                ->setOptions([
                    'fill' => false,
                    'showTooltips' => true,
                    'tooltip' => [
                        'mode' => 'single',
                    ],
                    'hover' => [
                        'mode' => 'dataset',
                    ],
                    'scales' => [
                        'x' => [
                            'min' => 0,
                            'ticks' => [
                                'autoSkip'    => true,
                                'maxRotation' => 0,
                                'padding'     => 30,
                            ]
                        ],
                        'y' => [
                            'min' => 0,
                        ],
                    ],
                ])
                ->setLabels(array_map(function ($date) {
                    return Format::dateReadable($date, Format::MEDIUM_NO_YEAR);
                }, $days));

            foreach ($data as $typeName => $dates) {
                $chart->addDataset($typeName)
                    ->setLabel(attendanceT($typeName))
                    ->setProperties(['fill' => false, 'borderWidth' => 1])
                    ->setData($dates);
            }

            echo $chart->render();
        }
    }
}
