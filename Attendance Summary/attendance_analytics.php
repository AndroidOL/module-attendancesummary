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
use Gibbon\Domain\DataSet;
use Gibbon\Domain\User\UserGateway;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Forms\Form;
use Gibbon\Module\Attendance\AttendanceAnalytics;
use Gibbon\Services\Format;
use Gibbon\Session\TokenHandler;
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

$page->return->addReturns([
    'success0' => attendanceT('Your default alert threshold has been saved.'),
    'error1'   => attendanceT('The submitted threshold is invalid. Enter a value from 0 to 100.'),
    'error2'   => attendanceT('Unable to save your default threshold.'),
    'error9'   => attendanceT('Your session expired. Refresh the page and try again.'),
]);

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

$gibbonPersonID = (string) $session->get('gibbonPersonID');
$userGateway = $container->get(UserGateway::class);
$savedThreshold = $userGateway->getUserPreferenceByScope(
    $gibbonPersonID,
    'Attendance Summary',
    'analyticsThreshold',
    null
);
$threshold = attendanceResolveThreshold($_REQUEST['threshold'] ?? null, $savedThreshold);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (array_key_exists('saveThreshold', $_POST) || array_key_exists('resetThreshold', $_POST))) {
    $currentRedirectURL = $session->get('absoluteURL').'/index.php?'.http_build_query([
        'q'         => '/modules/'.$session->get('module').'/attendance_analytics.php',
        'scope'     => $scope,
        'threshold' => $threshold,
    ]);

    if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
        header('Location: '.$currentRedirectURL.'&return=error9');
        exit;
    }

    $newThreshold = array_key_exists('resetThreshold', $_POST)
        ? 90.0
        : attendanceValidThreshold($_POST['threshold'] ?? null);
    if ($newThreshold === null) {
        header('Location: '.$currentRedirectURL.'&return=error1');
        exit;
    }

    $redirectURL = $session->get('absoluteURL').'/index.php?'.http_build_query([
        'q'         => '/modules/'.$session->get('module').'/attendance_analytics.php',
        'scope'     => $scope,
        'threshold' => $newThreshold,
    ]);

    try {
        $saved = $userGateway->setUserPreferenceByScope(
            $gibbonPersonID,
            'Attendance Summary',
            'analyticsThreshold',
            (string) $newThreshold
        );
        header('Location: '.$redirectURL.'&return='.($saved !== false ? 'success0' : 'error2'));
        exit;
    } catch (\Throwable $e) {
        header('Location: '.$redirectURL.'&return=error2');
        exit;
    }
}

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

    $defaultForm = Form::create(
        'attendanceThresholdDefault',
        $session->get('absoluteURL').'/index.php?q=/modules/'.$session->get('module').'/attendance_analytics.php',
        'post'
    );
    $defaultForm->setClass('noIntBorder w-full');
    $defaultForm->addHiddenValue('scope', $scope);
    $defaultForm->addHiddenValue('threshold', $threshold);

    $row = $defaultForm->addRow();
    $row->addContent(attendanceT('Save the current alert threshold for your account without changing the report view.'))
        ->wrap('<div class="text-sm text-gray-600">', '</div>');
    $row->addSubmit(attendanceT('Save as my default'))->setName('saveThreshold');
    $row->addSubmit(attendanceT('Reset to 90% and save'))->setName('resetThreshold')->setColor('gray');

    $page->write($defaultForm->getOutput());
}

$report = $analytics->analyse($scope, $threshold);
$scopeSchoolYearID = $analytics->getScopeSchoolYearID($scope);
$canOpenAttendance = $scopeSchoolYearID !== null
    && $scopeSchoolYearID === (string) $session->get('gibbonSchoolYearID')
    && isActionAccessible($guid, $connection2, '/modules/Attendance/attendance_take_byFormGroup.php');
$canCreateFollowUp = $scopeSchoolYearID === (string) $session->get('gibbonSchoolYearID')
    && isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup_create.php');

$definitionLines = [
    sprintf(
        attendanceT('Attendance rate = (Present + Partial) / (Present + Partial + Absent), for %s.'),
        $report['scopeLabel']
    ),
    attendanceT('Late and left early count as partial attendance.'),
    attendanceT('No-record days are excluded from the attendance-rate denominator. Expected counts school days only while each student is enrolled.'),
    $countClassAsSchool === 'N'
        ? attendanceT('Class-context attendance records are excluded by the school Attendance setting.')
        : attendanceT('Class-context attendance records are included by the school Attendance setting.'),
    sprintf(attendanceT('The current alert threshold is %s.'), attendanceFormatRate($threshold)),
];

$csvMetadata = [
    [attendanceT('Scope'), $report['scopeLabel']],
    [attendanceT('Date range'), ($report['dateStart'] ?: '-').' - '.($report['dateEnd'] ?: '-')],
    [attendanceT('Alert Threshold'), attendanceFormatRate($threshold)],
    [attendanceT('Attendance rate definition'), $definitionLines[0]],
    [attendanceT('Partial attendance'), $definitionLines[1]],
    [attendanceT('No-record handling'), $definitionLines[2]],
    [attendanceT('Class-context records'), $definitionLines[3]],
    [attendanceT('No record'), $report['dataQuality']['noRecordStudentDays']],
];

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
            attendanceFormatRate($student['rate']),
        ];
    }
    sendAttendanceCsv(
        'attendance-analytics-'.date('Ymd').'.csv',
        [attendanceT('Student'), attendanceT('Class'), attendanceT('Year Group'), attendanceT('Expected'), attendanceT('Present'), attendanceT('Late'), attendanceT('Left Early'), attendanceT('Absent'), attendanceT('No record'), attendanceT('Attendance Rate')],
        $rows,
        $csvMetadata
    );
}

if (($_GET['export'] ?? '') === 'data-quality-csv') {
    $rows = [];
    foreach ($report['dataQuality']['queue'] as $row) {
        $rows[] = [
            $row['date'],
            $row['formGroup'],
            $row['expected'],
            $row['noRecord'],
        ];
    }
    sendAttendanceCsv(
        'attendance-data-quality-'.date('Ymd').'.csv',
        [attendanceT('Date'), attendanceT('Class'), attendanceT('Expected'), attendanceT('No record')],
        $rows,
        $csvMetadata
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

    $actionForm->addHeaderAction('exportQuality', attendanceT('Export Data Quality CSV'))
        ->setURL('/index.php')
        ->addParam('q', '/modules/Attendance Summary/attendance_analytics.php')
        ->addParam('scope', $scope)
        ->addParam('threshold', $threshold)
        ->addParam('export', 'data-quality-csv')
        ->setIcon('attendance')
        ->directLink()
        ->displayLabel();

    if (isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_followup.php')) {
        $actionForm->addHeaderAction('followUp', attendanceT('Attendance Follow-up'))
            ->setURL('/modules/Attendance Summary/attendance_followup.php')
            ->setIcon('markbook')
            ->displayLabel();
    }

    $output .= '<div class="attendanceLifecycleNoPrint">' . $actionForm->getOutput() . '</div>';
}

// ---- Definitions ----
$definitionsOutput = '<div class="attendanceLifecycleSection attendanceDataDefinitions"><h3>' . attendanceT('Attendance Definitions') . '</h3><ul>';
foreach ($definitionLines as $line) {
    $definitionsOutput .= '<li>' . htmlPrep($line) . '</li>';
}
$definitionsOutput .= '</ul></div>';

if (empty($report['dateStart'])) {
    $output .= $definitionsOutput;
    $output .= $page->getBlankSlate(attendanceT('No scoped school days or attendance records are available yet.'));
    $page->write($output);
    return;
}

// ---- Heading + KPI cards ----
$output .= '<div class="attendanceLifecycleSection"><h3>' . sprintf(attendanceT('%s Attendance Analysis (%s to %s)'), htmlPrep($report['scopeLabel']), htmlPrep($report['dateStart']), htmlPrep($report['dateEnd'])) . '</h3>';
$output .= $definitionsOutput;

$kpis = [
    ['label' => attendanceT('Attendance Rate'), 'value' => attendanceFormatRate($report['overallRate']), 'color' => getBgColor($report['overallRate'])],
    ['label' => attendanceT('Students'), 'value' => $report['studentCount']],
    ['label' => attendanceT('School Days'), 'value' => $report['schoolDays']],
    ['label' => attendanceT('Absent'), 'value' => $report['overall']['absent']],
    ['label' => attendanceT('Late'), 'value' => $report['overall']['late']],
    ['label' => attendanceT('Left Early'), 'value' => $report['overall']['left']],
    ['label' => attendanceT('No record'), 'value' => $report['dataQuality']['noRecordStudentDays'], 'color' => '#475569'],
];

$output .= '<div class="attendanceAnalyticsKpi">';
foreach ($kpis as $kpi) {
    $output .= '<div class="attendanceAnalyticsKpiCard">'
        . '<div class="attendanceAnalyticsKpiLabel">' . htmlPrep($kpi['label']) . '</div>'
        . '<div class="attendanceAnalyticsKpiValue" style="color:' . ($kpi['color'] ?? '#1e293b') . ';">' . htmlPrep((string) $kpi['value']) . '</div>'
        . '</div>';
}
$output .= '</div></div>';

// ---- Attendance data quality ----
$dataQuality = $report['dataQuality'];
$output .= '<div class="attendanceLifecycleSection attendanceDataQuality"><h3>' . attendanceT('Attendance Data Quality') . '</h3>';

if ($dataQuality['noRecordStudentDays'] === 0) {
    $output .= '<div class="success">'.attendanceT('No attendance data gaps were found in this scope.').'</div>';
} else {
    $output .= '<div class="warning">'
        . '<p><strong>'.sprintf(
            attendanceT('%s no-record student-days affect %s students in this scope.'),
            number_format($dataQuality['noRecordStudentDays']),
            number_format($dataQuality['studentsWithNoRecord'])
        ).'</strong></p>'
        . '<p>'.attendanceT('Review these records separately. A no-record day is a data gap, not an absence, and it is excluded from the attendance-rate denominator.').'</p>'
        . '</div>';

    if ($isPrint) {
        $qualityRows = [];
        foreach ($report['formGroups'] as $group) {
            if ($group['counts']['none'] < 1) {
                continue;
            }
            $qualityRows[] = [
                'formGroup' => $group['name'],
                'expected'  => $group['counts']['total'],
                'noRecord'  => $group['counts']['none'],
            ];
        }

        $table = DataTable::create('attendanceDataQualityPrint');
        $table->setTitle(attendanceT('No-record days by class'));
        $table->addColumn('formGroup', attendanceT('Class'));
        $table->addColumn('expected', attendanceT('Expected'));
        $table->addColumn('noRecord', attendanceT('No record'));
        $output .= $table->render(new DataSet($qualityRows));
    } else {
        $table = DataTable::create('attendanceDataQuality');
        $table->setTitle(attendanceT('No-record Attendance Queue'));
        $table->setDescription(
            $canOpenAttendance
                ? attendanceT('Rows are newest first. Use the action to open the original form-group attendance page.')
                : ($scopeSchoolYearID !== (string) $session->get('gibbonSchoolYearID')
                    ? attendanceT('Historical attendance data is read-only. Change the scope to the current school year to open the attendance-entry page.')
                    : attendanceT('You do not have permission to enter attendance. This queue is read-only.'))
        );
        $table->addMetaData('allowHTML', ['noRecord']);
        $table->addMetaData('blankSlate', attendanceT('No attendance data gaps were found in this scope.'));
        $table->addColumn('date', attendanceT('Date'))->format(function ($row) {
            return Format::date($row['date']);
        });
        $table->addColumn('formGroup', attendanceT('Class'));
        $table->addColumn('noRecord', attendanceT('No record').' / '.attendanceT('Expected'))->format(function ($row) {
            return '<span class="attendanceAnalyticsNeutral">'.htmlPrep((string) $row['noRecord']).' / '.htmlPrep((string) $row['expected']).'</span>';
        });

        if ($canOpenAttendance) {
            $table->addActionColumn()
                ->format(function ($row, $actions) {
                    $actions->addAction('openAttendance', attendanceT('Open Attendance'))
                        ->setIcon('attendance')
                        ->setURL('/modules/Attendance/attendance_take_byFormGroup.php')
                        ->addParam('gibbonFormGroupID', $row['gibbonFormGroupID'])
                        ->addParam('currentDate', Format::date($row['date']));
                });
        }

        $output .= $table->render(new DataSet($dataQuality['queue']));
    }
}

$output .= '</div>';

// ---- Charts ----
$output .= '<div class="attendanceLifecycleSection"><h3>' . attendanceT('Charts') . '</h3><div class="attendanceLifecycleCharts">';

// 1. Attendance rate per form group.
if (!empty($report['formGroups'])) {
    $groupLabels = [];
    $groupRates = [];
    foreach ($report['formGroups'] as $group) {
        if ($group['rate'] === null) {
            continue;
        }
        $groupLabels[] = $group['name'];
        $groupRates[] = $group['rate'];
    }

    if (!empty($groupLabels)) {
        $groupChart = Chart::create('attendanceAnalyticsFormGroup', 'bar')
            ->setOptions(['height' => max(240, count($groupLabels) * 30) . 'px', 'indexAxis' => 'y', 'scales' => ['x' => ['min' => 0, 'max' => 100]]])
            ->setColors(['#4C51BF'])
            ->setLabels($groupLabels);
        $groupChart->addDataset('rate', attendanceT('Attendance Rate'))->setData($groupRates);
        $output .= '<div>' . $groupChart->render() . '</div>';
    }
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
        if ($weekdayData['rate'] === null) {
            continue;
        }
        $wdLabels[] = $weekdayLabels[$weekday] ?? $weekday;
        $wdRates[] = $weekdayData['rate'];
    }

    if (!empty($wdLabels)) {
        $weekdayChart = Chart::create('attendanceAnalyticsWeekday', 'bar')
            ->setOptions(['height' => '240px', 'scales' => ['y' => ['min' => 0, 'max' => 100]]])
            ->setColors(['#3182CE'])
            ->setLabels($wdLabels);
        $weekdayChart->addDataset('rate', attendanceT('Attendance Rate'))->setData($wdRates);
        $output .= '<div>' . $weekdayChart->render() . '</div>';
    }
}

// 4. Attendance rate trend by month.
if (!empty($report['byMonth'])) {
    $monthLabels = [];
    $monthRates = [];
    foreach ($report['byMonth'] as $monthKey => $month) {
        if ($month['rate'] === null) {
            continue;
        }
        $monthLabels[] = Format::monthName(new \DateTime($monthKey . '-01'), true) . ' ' . substr($monthKey, 0, 4);
        $monthRates[] = $month['rate'];
    }

    if (!empty($monthLabels)) {
        $monthChart = Chart::create('attendanceAnalyticsMonth', 'line')
            ->setOptions(['height' => '240px', 'scales' => ['y' => ['min' => 0, 'max' => 100]]])
            ->setColors(['#38A169'])
            ->setLabels($monthLabels);
        $monthChart->addDataset('rate', attendanceT('Attendance Rate'))->setData($monthRates);
        $output .= '<div>' . $monthChart->render() . '</div>';
    }
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
        return '<span class="attendanceAnalyticsRate" style="background-color:' . getBgColor($group['rate']) . ';">' . attendanceFormatRate($group['rate']) . '</span>';
    });

    $output .= $table->render($groupRows);
}

// ---- At-risk students ----
$table = DataTable::create('attendanceAnalyticsAtRisk');
$table->setTitle(sprintf(attendanceT('Low Attendance (below %s%%)'), $threshold) . ' (' . count($report['atRisk']) . ')');
$table->addMetaData('allowHTML', ['rate']);
$table->addMetaData('blankSlate', attendanceT('No students with calculable attendance are below this rate.'));

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
$table->addColumn('none', attendanceT('No record'))->format(function ($student) {
    return $student['counts']['none'];
});
$table->addColumn('rate', attendanceT('Attendance Rate'))->format(function ($student) {
    return '<span class="attendanceAnalyticsRate" style="background-color:' . getBgColor($student['rate']) . ';">' . attendanceFormatRate($student['rate']) . '</span>';
});
$table->addActionColumn()
    ->addParam('gibbonPersonID')
    ->format(function ($student, $actions) use ($report, $canCreateFollowUp) {
        $actions->addAction('lifecycle', attendanceT('Full History'))
            ->setIcon('attendance')
            ->setURL('/modules/Attendance Summary/attendance_lifecycle.php');
        if ($canCreateFollowUp) {
            $actions->addAction('followUp', attendanceT('Create Follow-up'))
                ->setIcon('markbook')
                ->setURL('/modules/Attendance Summary/attendance_followup_create.php')
                ->addParam('reason', 'low_attendance')
                ->addParam('start', $report['dateStart'])
                ->addParam('end', $report['dateEnd']);
        }
    });

$output .= $table->render($report['atRisk']);

$page->write($output);
