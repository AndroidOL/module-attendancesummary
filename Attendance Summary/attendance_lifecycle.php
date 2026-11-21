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
use Gibbon\Module\Attendance\AttendanceLifecycle;
use Gibbon\Services\Format;
use Gibbon\UI\Chart\Chart;

require_once __DIR__ . '/moduleFunctions.php';
require_once __DIR__ . '/src/AttendanceLifecycle.php';

$page->breadcrumbs->add(attendanceT('View Attendance'));
$page->breadcrumbs->add(attendanceT('Attendance History (School Days)'));

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
    $termID = is_scalar($_REQUEST['gibbonSchoolYearTermID'] ?? null) ? trim((string) $_REQUEST['gibbonSchoolYearTermID']) : '';
    $yearID = is_scalar($_REQUEST['gibbonSchoolYearID'] ?? null) ? trim((string) $_REQUEST['gibbonSchoolYearID']) : '';

    if ($termID !== '') {
        $scope = 'term:' . $termID;
    } elseif ($yearID !== '') {
        $scope = 'year:' . $yearID;
    } else {
        $scope = 'all';
    }
}

$countClassAsSchool = $container->get(SettingGateway::class)->getSettingByScope('Attendance', 'countClassAsSchool');

$lifecycle = new AttendanceLifecycle($pdo, $countClassAsSchool);
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
    $form = Form::create('attendanceLifecycle', $session->get('absoluteURL') . '/index.php', 'get');
    $form->setTitle(attendanceT('Attendance History (School Days)'));
    $form->setFactory(DatabaseFormFactory::create($pdo));
    $form->setClass('noIntBorder w-full');

    $form->addHiddenValue('q', '/modules/' . $session->get('module') . '/attendance_lifecycle.php');

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

$report = $lifecycle->build($gibbonPersonID, $scope);

if (($_GET['export'] ?? '') === 'csv') {
    $rows = [];
    foreach ($report['years'] as $year) {
        $counts = $year['counts'];
        $rows[] = [
            $year['schoolYearName'],
            $counts['total'],
            $counts['present'],
            $counts['late'],
            $counts['left'],
            $counts['absent'],
            $counts['none'],
            $year['rate'],
        ];
    }
    $profile = $report['profile'];
    $name = Format::name($profile['title'] ?? '', $profile['preferredName'] ?? '', $profile['surname'] ?? '', 'Student');
    sendAttendanceCsv(
        'attendance-lifecycle-'.date('Ymd').'.csv',
        [attendanceT('School Year'), attendanceT('Expected'), attendanceT('Present'), attendanceT('Late'), attendanceT('Left Early'), attendanceT('Absent'), attendanceT('No record'), attendanceT('Attendance Rate')],
        $rows
    );
}
$profile = $report['profile'];
if (empty($profile)) {
    $page->addError(attendanceT('The specified record does not exist.'));
    return;
}

// ---- Helpers for rendering ----
$statusMeta = function ($status, $scopeValue) {
    switch ($status) {
        case 'present':
            return ['label' => attendanceT('Present'), 'color' => '#9AE6B4'];
        case 'absent':
            return ['label' => attendanceT('Absent'), 'color' => '#FC8181'];
        case 'partial':
            return $scopeValue == 'Offsite - Left'
                ? ['label' => attendanceT('Left Early'), 'color' => '#F6C177']
                : ['label' => attendanceT('Late'), 'color' => '#FFD2A8'];
        default:
            return ['label' => attendanceT('No record'), 'color' => '#E2E8F0'];
    }
};

$countCells = function ($counts, $rate) {
    return '<td>' . $counts['total'] . '</td>'
        . '<td>' . $counts['present'] . '</td>'
        . '<td>' . $counts['late'] . '</td>'
        . '<td>' . $counts['left'] . '</td>'
        . '<td>' . $counts['absent'] . '</td>'
        . '<td>' . $counts['none'] . '</td>'
        . '<td class="attendanceLifecycleRate" style="background-color:' . getBgColor($rate) . ';">' . $rate . '%</td>';
};

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

// ---- Page actions ----
if (!$isPrint) {
    $actionForm = Form::createBlank('attendanceLifecycleActions');
    $actionForm->addHeaderAction('print', attendanceT('Print'))
        ->setURL('/report.php')
        ->addParam('q', '/modules/Attendance Summary/attendance_lifecycle.php')
        ->addParam('gibbonPersonID', $gibbonPersonID)
        ->addParam('scope', $scope)
        ->addParam('viewMode', 'print')
        ->addParam('orientation', 'L')
        ->setIcon('print')
        ->setTarget('_blank')
        ->directLink()
        ->displayLabel();

    $actionForm->addHeaderAction('export', attendanceT('Export CSV'))
        ->setURL('/index.php')
        ->addParam('q', '/modules/Attendance Summary/attendance_lifecycle.php')
        ->addParam('gibbonPersonID', $gibbonPersonID)
        ->addParam('scope', $scope)
        ->addParam('export', 'csv')
        ->setIcon('download')
        ->directLink()
        ->displayLabel();

    $output .= '<div class="attendanceLifecycleNoPrint">' . $actionForm->getOutput() . '</div>';
}

// Every term in scope had no attendance records: nothing to summarise.
if (empty($report['years'])) {
    $output .= $page->getBlankSlate(attendanceT('No attendance records for this student in the selected scope.'));
    $page->write($output);
    return;
}

// ---- Summary table ----
$output .= '<div class="attendanceLifecycleSection"><h3>' . sprintf(attendanceT('%s Attendance Summary (as of %s)'), htmlPrep($name), Format::date(date('Y-m-d'))) . '</h3>';
$output .= '<table class="attendanceLifecycleTable"><thead><tr>'
    . '<th class="left">' . attendanceT('School Year / Term') . '</th>'
    . '<th>' . attendanceT('Date Range') . '</th>'
    . '<th>' . attendanceT('Expected') . '</th>'
    . '<th>' . attendanceT('Present') . '</th>'
    . '<th>' . attendanceT('Late') . '</th>'
    . '<th>' . attendanceT('Left Early') . '</th>'
    . '<th>' . attendanceT('Absent') . '</th>'
    . '<th>' . attendanceT('No record') . '</th>'
    . '<th>' . attendanceT('Attendance Rate') . '</th>'
    . '</tr></thead><tbody>';

foreach ($report['years'] as $year) {
    $yearLabel = htmlPrep($year['schoolYearName']);
    if (!empty($year['enrolment'])) {
        $yearLabel .= ' · ' . htmlPrep($year['enrolment']['yearGroupName']) . ' / ' . htmlPrep($year['enrolment']['formGroupName']);
    }
    $output .= '<tr class="yearRow"><td class="left" colspan="2">' . $yearLabel . '</td>'
        . $countCells($year['counts'], $year['rate']) . '</tr>';

    foreach ($year['terms'] as $term) {
        $output .= '<tr><td class="left">' . htmlPrep($term['name']) . '</td>'
            . '<td>' . htmlPrep($term['firstDay']) . ' ~ ' . htmlPrep($term['lastDay']) . '</td>'
            . $countCells($term['counts'], $term['rate']) . '</tr>';
    }
}

$output .= '<tr class="totalRow"><td class="left" colspan="2">' . attendanceT('Total') . '</td>'
    . $countCells($report['totals'], $report['totalRate']) . '</tr>';
$output .= '</tbody></table></div>';

// ---- Charts ----
$output .= '<div class="attendanceLifecycleSection"><h3>' . attendanceT('Charts') . '</h3><div class="attendanceLifecycleCharts">';

$statusChart = Chart::create('attendanceLifecycleStatus', 'doughnut')
    ->setOptions(['height' => '240px'])
    ->setColors(['#9AE6B4', '#FFD2A8', '#FC8181', '#E2E8F0'])
    ->setLabels([attendanceT('Present'), attendanceT('Late / Left Early'), attendanceT('Absent'), attendanceT('No record')]);
$statusChart->addDataset('status')->setData([
    $report['totals']['present'],
    $report['totals']['partial'],
    $report['totals']['absent'],
    $report['totals']['none'],
]);
$output .= '<div>' . $statusChart->render() . '</div>';

$rateLabels = [];
$rateData = [];
foreach ($report['years'] as $year) {
    foreach ($year['terms'] as $term) {
        $rateLabels[] = $year['schoolYearName'] . ' ' . $term['name'];
        $rateData[] = $term['rate'];
    }
}
if (!empty($rateLabels)) {
    $rateChart = Chart::create('attendanceLifecycleRate', 'bar')
        ->setOptions(['height' => '240px', 'scales' => ['y' => ['min' => 0, 'max' => 100]]])
        ->setColors(['#4C51BF'])
        ->setLabels($rateLabels);
    $rateChart->addDataset('rate', attendanceT('Attendance Rate'))->setData($rateData);
    $output .= '<div>' . $rateChart->render() . '</div>';
}

// Absence types (doughnut).
if (!empty($report['detail']['absentTypes'])) {
    $absentTypeChart = Chart::create('attendanceLifecycleAbsentType', 'doughnut')
        ->setOptions(['height' => '240px'])
        ->setLabels(array_map(function ($type) { return attendanceT($type); }, array_keys($report['detail']['absentTypes'])));
    $absentTypeChart->addDataset('absent', attendanceT('Absent'))->setData(array_values($report['detail']['absentTypes']));
    $output .= '<div>' . $absentTypeChart->render() . '</div>';
}

// Attendance rate by weekday (bar).
$weekdayLabels = ['Mon' => attendanceT('Monday'), 'Tue' => attendanceT('Tuesday'), 'Wed' => attendanceT('Wednesday'), 'Thu' => attendanceT('Thursday'), 'Fri' => attendanceT('Friday'), 'Sat' => attendanceT('Saturday'), 'Sun' => attendanceT('Sunday')];
$weekdayTally = [];
foreach ($report['years'] as $year) {
    foreach ($year['terms'] as $term) {
        foreach ($term['days'] as $day) {
            $weekday = $day['weekday'];
            if (!isset($weekdayTally[$weekday])) {
                $weekdayTally[$weekday] = ['attended' => 0, 'absent' => 0];
            }
            if ($day['status'] == 'present' || $day['status'] == 'partial') {
                $weekdayTally[$weekday]['attended']++;
            } elseif ($day['status'] == 'absent') {
                $weekdayTally[$weekday]['absent']++;
            }
        }
    }
}

$wdLabels = [];
$wdRates = [];
foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday) {
    if (!isset($weekdayTally[$weekday])) continue;
    $tally = $weekdayTally[$weekday];
    $denominator = $tally['attended'] + $tally['absent'];
    $wdLabels[] = $weekdayLabels[$weekday];
    $wdRates[] = $denominator > 0 ? round(($tally['attended'] / $denominator) * 100, 1) : 0.0;
}

if (!empty($wdLabels)) {
    $weekdayChart = Chart::create('attendanceLifecycleWeekday', 'bar')
        ->setOptions(['height' => '240px', 'scales' => ['y' => ['min' => 0, 'max' => 100]]])
        ->setColors(['#3182CE'])
        ->setLabels($wdLabels);
    $weekdayChart->addDataset('rate', attendanceT('Attendance Rate'))->setData($wdRates);
    $output .= '<div>' . $weekdayChart->render() . '</div>';
}
$output .= '</div></div>';

// ---- Absence type summary ----
if (!empty($report['detail']['absentTypes'])) {
    $output .= '<div class="attendanceLifecycleSection"><h3>' . attendanceT('Absence Type Summary') . '</h3>';
    $output .= '<table class="attendanceLifecycleTable attendanceLifecycleTableNarrow"><thead><tr><th>' . attendanceT('Type') . '</th><th>' . attendanceT('Days') . '</th></tr></thead><tbody>';
    foreach ($report['detail']['absentTypes'] as $type => $count) {
        $output .= '<tr><td class="left">' . htmlPrep(attendanceT($type)) . '</td><td>' . $count . '</td></tr>';
    }
    $output .= '</tbody></table></div>';
}

// ---- Absence / late / left detail ----
$detailBlocks = [
    ['key' => 'absent', 'title' => attendanceT('Absence Details'), 'color' => '#FC8181'],
    ['key' => 'late',   'title' => attendanceT('Late Details'), 'color' => '#FFD2A8'],
    ['key' => 'left',   'title' => attendanceT('Left Early Details'), 'color' => '#F6C177'],
];

foreach ($detailBlocks as $block) {
    $rows = $report['detail'][$block['key']];
    if (empty($rows)) {
        continue;
    }

    $output .= '<div class="attendanceLifecycleSection"><h3><span class="attendanceLifecycleDot" style="background-color:' . $block['color'] . ';"></span>' . $block['title'] . ' (' . count($rows) . ')</h3>';
    $output .= '<table class="attendanceLifecycleTable"><thead><tr>'
        . '<th>' . attendanceT('Date') . '</th><th>' . attendanceT('Weekday') . '</th><th>' . attendanceT('School Year') . '</th><th>' . attendanceT('Term') . '</th><th>' . attendanceT('Type') . '</th><th>' . attendanceT('Reason') . '</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $output .= '<tr>'
            . '<td>' . htmlPrep($row['date']) . '</td>'
            . '<td>' . htmlPrep(getWeekDayName($row['date'])) . '</td>'
            . '<td>' . htmlPrep($row['schoolYearName']) . '</td>'
            . '<td>' . htmlPrep($row['termName']) . '</td>'
            . '<td>' . htmlPrep($row['type'] !== '' ? attendanceT($row['type']) : '') . '</td>'
            . '<td>' . htmlPrep($row['reason']) . '</td>'
            . '</tr>';
    }
    $output .= '</tbody></table></div>';
}

// ---- Daily detail: compact month calendars (far lighter to print than a full day list) ----
$weekdayHeads = [attendanceT('Mon'), attendanceT('Tue'), attendanceT('Wed'), attendanceT('Thu'), attendanceT('Fri'), attendanceT('Sat'), attendanceT('Sun')];

$renderCalendar = function (array $yearDays, \DateTime $month) use ($statusMeta, $weekdayHeads) {
    $firstDow = (int) $month->format('N'); // 1 = Monday .. 7 = Sunday
    $daysInMonth = (int) $month->format('t');

    $cells = [];
    for ($i = 1; $i < $firstDow; $i++) {
        $cells[] = '<td class="attendanceLifecycleCalOff"></td>';
    }

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = $month->format('Y-m') . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
        $record = $yearDays[$date] ?? null;

        if ($record === null) {
            $cells[] = '<td class="attendanceLifecycleCalOff">' . $day . '</td>';
            continue;
        }

        $meta = $statusMeta($record['status'], $record['scope']);
        $title = $meta['label']
            . ($record['type'] !== '' ? ' · ' . attendanceT($record['type']) : '')
            . ($record['reason'] !== '' ? ': ' . $record['reason'] : '');

        $cells[] = '<td class="attendanceLifecycleCalDay" style="background-color:' . $meta['color'] . ';" title="' . htmlPrep($title) . '">' . $day . '</td>';
    }

    while (count($cells) % 7 !== 0) {
        $cells[] = '<td class="attendanceLifecycleCalOff"></td>';
    }

    $html = '<div class="attendanceLifecycleMonth">';
    $html .= '<div class="attendanceLifecycleMonthName">' . htmlPrep(Format::monthName($month, true) . ' ' . $month->format('Y')) . '</div>';
    $html .= '<table class="attendanceLifecycleCal"><thead><tr>';
    foreach ($weekdayHeads as $head) {
        $html .= '<th>' . $head . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach (array_chunk($cells, 7) as $week) {
        $html .= '<tr>' . implode('', $week) . '</tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
};

$output .= '<div class="attendanceLifecycleSection"><h3>' . attendanceT('Daily Details') . '</h3>';

$output .= '<div class="attendanceLifecycleLegend">';
foreach ([
    [attendanceT('Present'), '#9AE6B4'],
    [attendanceT('Late / Left Early'), '#FFD2A8'],
    [attendanceT('Absent'), '#FC8181'],
    [attendanceT('No record'), '#E2E8F0'],
] as $legendItem) {
    $output .= '<span class="attendanceLifecycleLegendItem"><i style="background-color:' . $legendItem[1] . ';"></i>' . $legendItem[0] . '</span>';
}
$output .= '<span class="attendanceLifecycleLegendItem"><i style="background-color:#ffffff;border-color:#cbd5e1;"></i>' . attendanceT('Non-school Day') . '</span>';
$output .= '</div>';

foreach ($report['years'] as $year) {
    $yearDays = [];
    foreach ($year['terms'] as $term) {
        foreach ($term['days'] as $day) {
            $yearDays[$day['date']] = $day;
        }
    }
    if (empty($yearDays)) {
        continue;
    }

    // Only render months that actually contain school days.
    $months = [];
    foreach (array_keys($yearDays) as $date) {
        $months[substr($date, 0, 7)] = true;
    }
    $months = array_keys($months);
    sort($months);

    $output .= '<div class="attendanceLifecycleYearBlock"><h4>' . htmlPrep($year['schoolYearName'])
        . ' <span class="attendanceLifecycleMuted">(' . $year['counts']['total'] . ')</span></h4>';
    $output .= '<div class="attendanceLifecycleCalendar">';
    foreach ($months as $monthKey) {
        $output .= $renderCalendar($yearDays, new \DateTime($monthKey . '-01'));
    }
    $output .= '</div></div>';
}
$output .= '</div>';

$page->write($output);
