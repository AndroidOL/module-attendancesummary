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
use Gibbon\Module\Attendance\AttendanceLifecycleCourse;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;
use Gibbon\UI\Chart\Chart;

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/src/AttendanceLifecycle.php';
require_once __DIR__ . '/src/AttendanceLifecycleCourse.php';

$page->breadcrumbs->add(attendanceT('View Attendance'));
$page->breadcrumbs->add(attendanceT('Attendance History (Courses)'));

// The report inherits the existing student-attendance action.
if (!isActionAccessible($guid, $connection2, '/modules/Attendance Summary/attendance_summary.php')) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$isPrint = (($_REQUEST['viewMode'] ?? '') === 'print');
$gibbonPersonID = is_scalar($_REQUEST['gibbonPersonID'] ?? null) ? trim((string) $_REQUEST['gibbonPersonID']) : '';
if ($gibbonPersonID !== ''
    && !attendanceCanAccessStudent($pdo, $session->get('gibbonPersonID'), $gibbonPersonID, $session->get('gibbonSchoolYearID'))
) {
    $page->addError(attendanceT('You do not have access to this student.'));
    return;
}

// Report scope: all enrolment / one school year / one term. Term wins over year.
$scope = is_scalar($_REQUEST['scope'] ?? null) ? trim((string) $_REQUEST['scope']) : '';
if ($scope === '') {
    if (!empty($_REQUEST['gibbonSchoolYearTermID'])) {
        $scope = 'term:' . $_REQUEST['gibbonSchoolYearTermID'];
    } elseif (!empty($_REQUEST['gibbonSchoolYearID'])) {
        $scope = 'year:' . $_REQUEST['gibbonSchoolYearID'];
    } else {
        $scope = 'all';
    }
}

$countClassAsSchool = $container->get(SettingGateway::class)->getSettingByScope('Attendance', 'countClassAsSchool');

$lifecycle = new AttendanceLifecycleCourse($pdo, $countClassAsSchool);
$lifecycle->setScoreConfig(getAttendanceScoreConfig());
$lifecycle->loadCalendar();

if ($isPrint) {
    // report.php does not load the core/theme scripts, so add what the charts need.
    $page->scripts->add('jquery', 'lib/jquery/jquery.js', ['context' => 'head']);
    $page->scripts->add('chart', 'lib/Chart.js/3.0/chart.min.js', ['context' => 'head']);
} else {
    $page->scripts->add('chart');
}

// ---- Scope selector (screen only; the print layout is driven by the URL) ----
if (!$isPrint) {
    $form = Form::create('attendanceLifecycleCourse', $session->get('absoluteURL') . '/index.php', 'get');
    $form->setTitle(attendanceT('Attendance History (Courses)'));
    $form->setFactory(DatabaseFormFactory::create($pdo));
    $form->setClass('noIntBorder w-full');

    $form->addHiddenValue('q', '/modules/' . $session->get('module') . '/attendance_lifecycle_course.php');

    $row = $form->addRow();
    $row->addLabel('gibbonPersonID', attendanceT('Student'));
    $row->addSelectStudent('gibbonPersonID', $session->get('gibbonSchoolYearID'), ['allStudents' => true, 'byName' => true, 'byForm' => false])
        ->selected($gibbonPersonID)->placeholder()->required();

    $row = $form->addRow();
    $row->addLabel('scope', attendanceT('Scope'));
    $row->addSelect('scope')->fromArray($lifecycle->getScopeOptions())->selected($scope)->required();

    $row = $form->addRow();
    $row->addFooter();
    $row->addSearchSubmit($session);

    $page->write($form->getOutput());
}

if (empty($gibbonPersonID)) {
    return;
}

$report = $lifecycle->buildCourse($gibbonPersonID, $scope);
$profile = $report['profile'];
if (empty($profile)) {
    $page->addError(attendanceT('The specified record does not exist.'));
    return;
}

$output = '';

// ---- Profile card ----
$name = Format::name($profile['title'], $profile['preferredName'], $profile['surname'], 'Student');
$currentYearID = $session->get('gibbonSchoolYearID');
$currentEnrolment = null;
foreach ($report['enrolments'] as $enrolment) {
    if ($enrolment['gibbonSchoolYearID'] == $currentYearID) {
        $currentEnrolment = $enrolment;
    }
}

$output .= '<div class="attendanceLifecycleCard">';
if (!empty($profile['image_240'])) {
    $output .= '<img class="attendanceLifecyclePhoto" src="' . $session->get('absoluteURL') . '/' . htmlPrep($profile['image_240']) . '" alt="' . htmlPrep($name) . '"/>';
} else {
    $initial = !empty($profile['preferredName']) ? $profile['preferredName'] : $profile['surname'];
    $output .= '<div class="attendanceLifecyclePhoto attendanceLifecyclePhotoEmpty">' . htmlPrep(mb_substr($initial, 0, 1)) . '</div>';
}
$output .= '<div class="flex-1">';
$output .= '<div class="flex items-center gap-2 flex-wrap"><span class="text-lg font-semibold">' . htmlPrep($name) . '</span>'
    . '<span class="attendanceLifecycleBadge">' . htmlPrep($profile['gibbonPersonID']) . '</span>'
    . '<span class="attendanceLifecycleBadge">' . attendanceT('Status') . ': ' . htmlPrep($profile['status'] ? attendanceT($profile['status']) : '') . '</span></div>';
$output .= '<div class="attendanceLifecycleMeta">';
$output .= '<span>' . attendanceT('Gender') . ': ' . htmlPrep($profile['gender'] ? attendanceT($profile['gender']) : '') . '</span>';
$output .= '<span>' . attendanceT('Date of Birth') . ': ' . htmlPrep(!empty($profile['dob']) ? Format::date($profile['dob']) : '-') . '</span>';
$output .= '<span>' . attendanceT('Enrolment Date') . ': ' . htmlPrep(!empty($profile['dateStart']) ? Format::date($profile['dateStart']) : '-') . '</span>';
$output .= '<span>' . attendanceT('Departure Date') . ': ' . htmlPrep(!empty($profile['dateEnd']) ? Format::date($profile['dateEnd']) : '-') . '</span>';
if (!empty($currentEnrolment)) {
    $output .= '<span>' . attendanceT('Current') . ': ' . htmlPrep($currentEnrolment['yearGroupName']) . ' / ' . htmlPrep($currentEnrolment['formGroupName']) . '</span>';
} elseif ($profile['status'] === 'Left') {
    $output .= '<span class="attendanceLifecycleFlagLeft">' . attendanceT('Left School') . '</span>';
}
$output .= '</div>';
$output .= '</div>';
$output .= '</div>';

// ---- Enrolment history ----
if (!empty($report['enrolments'])) {
    $output .= '<div class="attendanceLifecycleSection"><h3>' . attendanceT('Enrolment History') . '</h3><table class="attendanceLifecycleTable"><thead><tr>'
        . '<th>' . attendanceT('School Year') . '</th><th>' . attendanceT('Year Group') . '</th><th>' . attendanceT('Course') . '</th><th>' . attendanceT('Date Range') . '</th>'
        . '</tr></thead><tbody>';
    foreach ($report['enrolments'] as $enrolment) {
        $output .= '<tr>'
            . '<td>' . htmlPrep($enrolment['schoolYearName']) . '</td>'
            . '<td>' . htmlPrep($enrolment['yearGroupName']) . '</td>'
            . '<td>' . htmlPrep($enrolment['formGroupName']) . '</td>'
            . '<td>' . htmlPrep($enrolment['enrolStart']) . ' ~ ' . htmlPrep($enrolment['enrolEnd']) . '</td>'
            . '</tr>';
    }
    $output .= '</tbody></table></div>';
}

// ---- Print button ----
if (!$isPrint) {
    $printForm = Form::createBlank('attendanceLifecycleCourseActions');
    $printForm->addHeaderAction('print', attendanceT('Print'))
        ->setURL('/report.php')
        ->addParam('q', '/modules/Attendance Summary/attendance_lifecycle_course.php')
        ->addParam('gibbonPersonID', $gibbonPersonID)
        ->addParam('scope', $scope)
        ->addParam('viewMode', 'print')
        ->addParam('orientation', 'L')
        ->setIcon('print')
        ->setTarget('_blank')
        ->directLink()
        ->displayLabel();

    $printForm->addHeaderAction('export', attendanceT('Export CSV'))
        ->setURL('/index.php')
        ->addParam('q', '/modules/Attendance Summary/attendance_lifecycle_course.php')
        ->addParam('gibbonPersonID', $gibbonPersonID)
        ->addParam('scope', $scope)
        ->addParam('export', 'csv')
        ->setIcon('download')
        ->directLink()
        ->displayLabel();

    $output .= '<div class="attendanceLifecycleNoPrint">' . $printForm->getOutput() . '</div>';
}

if (empty($report['years'])) {
    $output .= $page->getBlankSlate(attendanceT('No course attendance records for this student in the selected scope.'));
    $page->write($output);
    return;
}

// ---- Per-course summary table ----
$num = function ($value) {
    return number_format((float) $value, 1);
};

$courseRows = [];
foreach ($report['years'] as $year) {
    $yearLabel = $year['schoolYearName'];
    if (!empty($year['enrolment'])) {
        $yearLabel .= ' · ' . $year['enrolment']['yearGroupName'] . ' / ' . $year['enrolment']['formGroupName'];
    }

    $courseRows[] = [
        'name'       => $yearLabel,
        'lessons'    => $year['totals']['lessons'],
        'standard'   => $year['totals']['standard'],
        'attendance' => $year['totals']['attendance'],
        'none'       => $year['totals']['none'],
        'rate'       => $year['rate'],
        'kind'       => 'year',
    ];

    foreach ($year['courses'] as $course) {
        $courseRows[] = [
            'name'       => $course['name'],
            'lessons'    => $course['lessons'],
            'standard'   => $course['standard'],
            'attendance' => $course['attendance'],
            'none'       => $course['none'],
            'rate'       => $course['rate'],
            'kind'       => 'course',
        ];
    }
}

$courseRows[] = [
    'name'       => attendanceT('Total'),
    'lessons'    => $report['totals']['lessons'],
    'standard'   => $report['totals']['standard'],
    'attendance' => $report['totals']['attendance'],
    'none'       => $report['totals']['none'],
    'rate'       => $report['totalRate'],
    'kind'       => 'total',
];

if (($_GET['export'] ?? '') === 'csv') {
    $csvRows = [];
    foreach ($courseRows as $row) {
        $csvRows[] = [
            $row['name'],
            $row['lessons'],
            $num($row['standard']),
            $num($row['attendance']),
            $row['none'],
            $row['rate'],
        ];
    }
    sendAttendanceCsv(
        'attendance-lifecycle-course-'.date('Ymd').'.csv',
        [
            attendanceT('School Year / Course'),
            attendanceT('Period'),
            attendanceT('Standard'),
            attendanceT('Present'),
            attendanceT('No record'),
            attendanceT('Attendance Rate'),
        ],
        $csvRows
    );
}

$table = DataTable::create('attendanceLifecycleCourseSummary');
$table->setTitle(sprintf(attendanceT('%s Course Attendance Summary (%s to %s)'), htmlPrep($name), htmlPrep($report['dateStart']), htmlPrep($report['dateEnd'])));
$table->addMetaData('allowHTML', ['rate']);
$table->modifyRows(function ($row, $tableRow) {
    if (($row['kind'] ?? '') === 'year') {
        $tableRow->addClass('attendanceLifecycleCourseYearRow');
    } elseif (($row['kind'] ?? '') === 'total') {
        $tableRow->addClass('attendanceAnalyticsTotalRow');
    }
    return $tableRow;
});

$table->addColumn('name', attendanceT('School Year / Course'));
$table->addColumn('lessons', attendanceT('Period'));
$table->addColumn('standard', attendanceT('Standard'))->format(function ($row) use ($num) {
    return $num($row['standard']);
});
$table->addColumn('attendance', attendanceT('Present'))->format(function ($row) use ($num) {
    return $num($row['attendance']);
});
$table->addColumn('none', attendanceT('No record'));
$table->addColumn('rate', attendanceT('Attendance Rate'))->format(function ($row) {
    return '<span class="attendanceAnalyticsRate" style="background-color:' . getBgColor($row['rate']) . ';">' . $row['rate'] . '%</span>';
});

$output .= $table->render($courseRows);

// ---- Charts ----
$output .= '<div class="attendanceLifecycleSection"><h3>' . attendanceT('Charts') . '</h3><div class="attendanceLifecycleCharts">';

$courseLabels = [];
$courseRates = [];
foreach ($report['years'] as $year) {
    foreach ($year['courses'] as $course) {
        $courseLabels[] = $year['schoolYearName'] . ' ' . $course['name'];
        $courseRates[] = $course['rate'];
    }
}

if (!empty($courseLabels)) {
    $courseChart = Chart::create('attendanceLifecycleCourseRate', 'bar')
        ->setOptions(['height' => max(240, count($courseLabels) * 26) . 'px', 'indexAxis' => 'y', 'scales' => ['x' => ['min' => 0, 'max' => 100]]])
        ->setColors(['#4C51BF'])
        ->setLabels($courseLabels);
    $courseChart->addDataset('rate', attendanceT('Attendance Rate'))->setData($courseRates);
    $output .= '<div>' . $courseChart->render() . '</div>';
}

// Type distribution, excluding no-record entries (a data gap, not an outcome).
$typeChartData = [];
foreach ($report['types'] as $type => $count) {
    if (attendanceIsNoRecord($type)) continue;
    $typeChartData[$type] = $count;
}

if (!empty($typeChartData)) {
    $typeChart = Chart::create('attendanceLifecycleCourseType', 'doughnut')
        ->setOptions(['height' => '240px'])
        ->setLabels(array_map('attendanceDisplayType', array_keys($typeChartData)));
    $typeChart->addDataset('type', attendanceT('Period'))->setData(array_values($typeChartData));
    $output .= '<div>' . $typeChart->render() . '</div>';
}

$output .= '</div></div>';

// ---- Abnormal lessons (anything scored below full attendance) ----
if (!empty($report['issues'])) {
    $output .= '<div class="attendanceLifecycleSection"><h3><span class="attendanceLifecycleDot" style="background-color:#FC8181;"></span>' . attendanceT('Exceptions') . ' (' . count($report['issues']) . ')</h3>';
    $output .= '<table class="attendanceLifecycleTable"><thead><tr>'
        . '<th>' . attendanceT('Date') . '</th><th>' . attendanceT('Weekday') . '</th><th>' . attendanceT('School Year') . '</th><th>' . attendanceT('Course') . '</th><th>' . attendanceT('Period') . '</th><th>' . attendanceT('Status') . '</th>'
        . '</tr></thead><tbody>';

    foreach ($report['issues'] as $issue) {
        $output .= '<tr>'
            . '<td>' . htmlPrep($issue['date']) . '</td>'
            . '<td>' . htmlPrep(getWeekDayName($issue['date'])) . '</td>'
            . '<td>' . htmlPrep($issue['schoolYearName']) . '</td>'
            . '<td class="left">' . htmlPrep($issue['course']) . '</td>'
            . '<td>' . htmlPrep($issue['period']) . '</td>'
            . '<td>' . htmlPrep(attendanceDisplayType($issue['type'])) . '</td>'
            . '</tr>';
    }

    $output .= '</tbody></table></div>';
}

$page->write($output);
