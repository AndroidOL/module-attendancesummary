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

use Gibbon\Domain\System\SettingGateway;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Forms\Form;
use Gibbon\Module\Attendance\AttendanceAnalytics;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;
use Gibbon\UI\Chart\Chart;

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/src/AttendanceAnalytics.php';

$page->breadcrumbs->add(attendanceT('View Attendance'));
$page->breadcrumbs->add(attendanceT('Attendance Analysis and Alerts'));

// Analytics inherits the existing student-attendance action.
if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_summary.php')) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$isPrint = (($_REQUEST['viewMode'] ?? '') === 'print');
$countClassAsSchool = $container->get(SettingGateway::class)->getSettingByScope('Attendance', 'countClassAsSchool');

$analytics = new AttendanceAnalytics($pdo, $countClassAsSchool);
$analytics->loadCalendar();

$scopeOptions = $analytics->getScopeOptions();
$defaultScope = 'year:' . $session->get('gibbonSchoolYearID');
if (!isset($scopeOptions[$defaultScope])) {
    $defaultScope = !empty($scopeOptions) ? array_key_first($scopeOptions) : '';
}

$scope = is_scalar($_REQUEST['scope'] ?? null) ? (string) $_REQUEST['scope'] : $defaultScope;
if (!isset($scopeOptions[$scope])) {
    $scope = $defaultScope;
}

$threshold = is_scalar($_REQUEST['threshold'] ?? null) && is_numeric($_REQUEST['threshold']) ? (float) $_REQUEST['threshold'] : 90;
$threshold = max(0, min(100, $threshold));

if ($isPrint) {
    // report.php does not load the core/theme scripts, so add what the charts need.
    $page->scripts->add('jquery', 'lib/jquery/jquery.js', ['context' => 'head']);
    $page->scripts->add('chart', 'lib/Chart.js/3.0/chart.min.js', ['context' => 'head']);
} else {
    $page->scripts->add('chart');
}

// ---- Filter form (screen only; the print layout is driven by the URL) ----
if (!$isPrint) {
    $form = Form::create('attendanceAnalytics', $session->get('absoluteURL') . '/index.php', 'get');
    $form->setTitle(attendanceT('Attendance Analysis and Alerts'));
    $form->setFactory(DatabaseFormFactory::create($pdo));
    $form->setClass('noIntBorder w-full');

    $form->addHiddenValue('q', '/modules/' . $session->get('module') . '/attendance_analytics.php');

    $row = $form->addRow();
    $row->addLabel('scope', attendanceT('Scope'));
    $row->addSelect('scope')->fromArray($scopeOptions)->selected($scope)->required();

    $row = $form->addRow();
    $row->addLabel('threshold', attendanceT('Alert Threshold'))->description(attendanceT('Students below this percentage appear in the alert list.'));
    $row->addNumber('threshold')->minimum(0)->maximum(100)->setValue($threshold)->required();

    $row = $form->addRow();
    $row->addFooter();
    $row->addSearchSubmit($session);

    $page->write($form->getOutput());
}

$report = $analytics->analyse($scope, $threshold);

if (($_GET['export'] ?? '') === 'csv') {
    $rows = [];
    foreach ($report['students'] as $student) {
        $counts = $student['counts'];
        $rows[] = [
            $student['name'],
            $student['formGroup'],
            $student['yearGroup'],
            $counts['total'],
            $counts['present'],
            $counts['late'],
            $counts['left'],
            $counts['absent'],
            $counts['none'],
            $student['rate'],
        ];
    }
    sendAttendanceCsv(
        'attendance-analytics-'.date('Ymd').'.csv',
        [attendanceT('Student'), attendanceT('Class'), attendanceT('Year Group'), attendanceT('Expected'), attendanceT('Present'), attendanceT('Late'), attendanceT('Left Early'), attendanceT('Absent'), attendanceT('No record'), attendanceT('Attendance Rate')],
        $rows
    );
}

$output = '';

// ---- Page actions ----
if (!$isPrint) {
    $actionForm = Form::createBlank('attendanceAnalyticsActions');
    $actionForm->addHeaderAction('print', attendanceT('Print'))
        ->setURL('/report.php')
        ->addParam('q', '/modules/Attendance Summary/attendance_analytics.php')
        ->addParam('scope', $scope)
        ->addParam('threshold', $threshold)
        ->addParam('viewMode', 'print')
        ->addParam('orientation', 'L')
        ->setIcon('print')
        ->setTarget('_blank')
        ->directLink()
        ->displayLabel();

    $actionForm->addHeaderAction('export', attendanceT('Export CSV'))
        ->setURL('/index.php')
        ->addParam('q', '/modules/Attendance Summary/attendance_analytics.php')
        ->addParam('scope', $scope)
        ->addParam('threshold', $threshold)
        ->addParam('export', 'csv')
        ->setIcon('download')
        ->directLink()
        ->displayLabel();

    $output .= '<div class="attendanceLifecycleNoPrint">' . $actionForm->getOutput() . '</div>';
}

if (empty($report['dateStart'])) {
    $output .= $page->getBlankSlate(attendanceT('No scoped school days or attendance records are available yet.'));
    $page->write($output);
    return;
}

// ---- Heading + KPI cards ----
$output .= '<div class="attendanceLifecycleSection"><h3>' . sprintf(attendanceT('%s Attendance Analysis (%s to %s)'), htmlPrep($report['scopeLabel']), htmlPrep($report['dateStart']), htmlPrep($report['dateEnd'])) . '</h3>';

$kpis = [
    ['label' => attendanceT('Attendance Rate'), 'value' => $report['overallRate'] . '%', 'color' => getBgColor($report['overallRate'])],
    ['label' => attendanceT('Students'), 'value' => $report['studentCount']],
    ['label' => attendanceT('School Days'), 'value' => $report['schoolDays']],
    ['label' => attendanceT('Absent'), 'value' => $report['overall']['absent']],
    ['label' => attendanceT('Late'), 'value' => $report['overall']['late']],
    ['label' => attendanceT('Left Early'), 'value' => $report['overall']['left']],
];

$output .= '<div class="attendanceAnalyticsKpi">';
foreach ($kpis as $kpi) {
    $output .= '<div class="attendanceAnalyticsKpiCard">'
        . '<div class="attendanceAnalyticsKpiLabel">' . htmlPrep($kpi['label']) . '</div>'
        . '<div class="attendanceAnalyticsKpiValue" style="color:' . ($kpi['color'] ?? '#1e293b') . ';">' . htmlPrep((string) $kpi['value']) . '</div>'
        . '</div>';
}
$output .= '</div></div>';

// ---- Charts ----
$output .= '<div class="attendanceLifecycleSection"><h3>' . attendanceT('Charts') . '</h3><div class="attendanceLifecycleCharts">';

// 1. Attendance rate per form group.
if (!empty($report['formGroups'])) {
    $groupLabels = [];
    $groupRates = [];
    foreach ($report['formGroups'] as $group) {
        $groupLabels[] = $group['name'];
        $groupRates[] = $group['rate'];
    }

    $groupChart = Chart::create('attendanceAnalyticsFormGroup', 'bar')
        ->setOptions(['height' => max(240, count($groupLabels) * 30) . 'px', 'indexAxis' => 'y', 'scales' => ['x' => ['min' => 0, 'max' => 100]]])
        ->setColors(['#4C51BF'])
        ->setLabels($groupLabels);
    $groupChart->addDataset('rate', attendanceT('Attendance Rate'))->setData($groupRates);
    $output .= '<div>' . $groupChart->render() . '</div>';
}

// 2. Absence type breakdown.
if (!empty($report['absentTypes'])) {
    $absentChart = Chart::create('attendanceAnalyticsAbsent', 'doughnut')
        ->setOptions(['height' => '240px'])
        ->setLabels(array_keys($report['absentTypes']));
    $absentChart->addDataset('absent', attendanceT('Absent'))->setData(array_values($report['absentTypes']));
    $output .= '<div>' . $absentChart->render() . '</div>';
}

// 3. Attendance rate by weekday.
if (!empty($report['byWeekday'])) {
    $weekdayLabels = ['Mon' => attendanceT('Monday'), 'Tue' => attendanceT('Tuesday'), 'Wed' => attendanceT('Wednesday'), 'Thu' => attendanceT('Thursday'), 'Fri' => attendanceT('Friday'), 'Sat' => attendanceT('Saturday'), 'Sun' => attendanceT('Sunday')];
    $wdLabels = [];
    $wdRates = [];
    foreach ($report['byWeekday'] as $weekday => $weekdayData) {
        $wdLabels[] = $weekdayLabels[$weekday] ?? $weekday;
        $wdRates[] = $weekdayData['rate'];
    }

    $weekdayChart = Chart::create('attendanceAnalyticsWeekday', 'bar')
        ->setOptions(['height' => '240px', 'scales' => ['y' => ['min' => 0, 'max' => 100]]])
        ->setColors(['#3182CE'])
        ->setLabels($wdLabels);
    $weekdayChart->addDataset('rate', attendanceT('Attendance Rate'))->setData($wdRates);
    $output .= '<div>' . $weekdayChart->render() . '</div>';
}

// 4. Attendance rate trend by month.
if (!empty($report['byMonth'])) {
    $monthLabels = [];
    $monthRates = [];
    foreach ($report['byMonth'] as $monthKey => $month) {
        $monthLabels[] = Format::monthName(new \DateTime($monthKey . '-01'), true) . ' ' . substr($monthKey, 0, 4);
        $monthRates[] = $month['rate'];
    }

    $monthChart = Chart::create('attendanceAnalyticsMonth', 'line')
        ->setOptions(['height' => '240px', 'scales' => ['y' => ['min' => 0, 'max' => 100]]])
        ->setColors(['#38A169'])
        ->setLabels($monthLabels);
    $monthChart->addDataset('rate', attendanceT('Attendance Rate'))->setData($monthRates);
    $output .= '<div>' . $monthChart->render() . '</div>';
}

$output .= '</div></div>';

// ---- Form group ranking ----
if (!empty($report['formGroups'])) {
    $groupRows = $report['formGroups'];
    $groupRows[] = [
        'name'     => attendanceT('Total'),
        'students' => $report['studentCount'],
        'counts'   => $report['overall'],
        'rate'     => $report['overallRate'],
    ];

    $table = DataTable::create('attendanceAnalyticsFormGroups');
    $table->setTitle(attendanceT('Class Attendance Summary'));
    $table->addMetaData('allowHTML', ['rate']);
    $table->modifyRows(function ($group, $row) {
        if (($group['name'] ?? '') === attendanceT('Total')) {
            $row->addClass('attendanceAnalyticsTotalRow');
        }
        return $row;
    });

    $table->addColumn('name', attendanceT('Class'));
    $table->addColumn('students', attendanceT('Students'));
    $table->addColumn('total', attendanceT('Expected'))->format(function ($group) {
        return $group['counts']['total'];
    });
    $table->addColumn('present', attendanceT('Present'))->format(function ($group) {
        return $group['counts']['present'];
    });
    $table->addColumn('late', attendanceT('Late'))->format(function ($group) {
        return $group['counts']['late'];
    });
    $table->addColumn('left', attendanceT('Left Early'))->format(function ($group) {
        return $group['counts']['left'];
    });
    $table->addColumn('absent', attendanceT('Absent'))->format(function ($group) {
        return $group['counts']['absent'];
    });
    $table->addColumn('none', attendanceT('No record'))->format(function ($group) {
        return $group['counts']['none'];
    });
    $table->addColumn('rate', attendanceT('Attendance Rate'))->format(function ($group) {
        return '<span class="attendanceAnalyticsRate" style="background-color:' . getBgColor($group['rate']) . ';">' . $group['rate'] . '%</span>';
    });

    $output .= $table->render($groupRows);
}

// ---- At-risk students ----
$table = DataTable::create('attendanceAnalyticsAtRisk');
$table->setTitle(sprintf(attendanceT('Low Attendance Warning (below %s%%)'), $threshold) . ' (' . count($report['atRisk']) . ')');
$table->addMetaData('allowHTML', ['rate']);
$table->addMetaData('blankSlate', attendanceT('No students are below this attendance rate.'));

$table->addColumn('name', attendanceT('Student'));
$table->addColumn('formGroup', attendanceT('Class'));
$table->addColumn('total', attendanceT('Expected'))->format(function ($student) {
    return $student['counts']['total'];
});
$table->addColumn('present', attendanceT('Present'))->format(function ($student) {
    return $student['counts']['present'];
});
$table->addColumn('late', attendanceT('Late'))->format(function ($student) {
    return $student['counts']['late'];
});
$table->addColumn('left', attendanceT('Left Early'))->format(function ($student) {
    return $student['counts']['left'];
});
$table->addColumn('absent', attendanceT('Absent'))->format(function ($student) {
    return $student['counts']['absent'];
});
$table->addColumn('rate', attendanceT('Attendance Rate'))->format(function ($student) {
    return '<span class="attendanceAnalyticsRate" style="background-color:' . getBgColor($student['rate']) . ';">' . $student['rate'] . '%</span>';
});
$table->addActionColumn()
    ->addParam('gibbonPersonID')
    ->format(function ($student, $actions) {
        $actions->addAction('lifecycle', attendanceT('Full History'))
            ->setIcon('attendance')
            ->setURL('/modules/Attendance Summary/attendance_lifecycle.php');
    });

$output .= $table->render($report['atRisk']);

$page->write($output);
