<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

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

use Gibbon\Domain\DataSet;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Services\Format;

function attendanceT($text, array $params = []): string
{
    global $session;

    if (function_exists('__m') && is_object($session) && method_exists($session, 'has') && $session->has('module')) {
        $translated = __m($text, $params);

        return $translated !== $text ? $translated : __($text, $params);
    }

    return __($text, $params);
}

/**
 * Default score awarded to each attendance type when calculating attendance rates.
 *
 * A record's type is matched by substring against these keywords; when several
 * keywords match, the lowest (strictest) score is used. See getAttendanceScore().
 *
 * @return array
 */
function getAttendanceScoreConfig(?array $settings = null)
{
    static $cached = null;

    $defaults = [
        'scoreAbsence'           => 0.0,
        'scoreSuspension'        => 0.0,
        'scoreLate'              => 0.7,
        'scoreLeftEarly'         => 0.7,
        'scoreSickLeave'         => 0.5,
        'scoreAuthorizedAbsence' => 1.0,
        'scorePresent'           => 1.0,
        'scoreNoRecord'          => 1.0,
        'scoreUnknown'           => 1.0,
    ];

    $keywordDefaults = [
        'scoreAbsence'           => ['setting' => 'keywordsAbsence', 'default' => '旷课,事假,Absent,Unexcused absence'],
        'scoreSuspension'        => ['setting' => 'keywordsSuspension', 'default' => '被停课,Suspended'],
        'scoreLate'              => ['setting' => 'keywordsLate', 'default' => '迟到,Late'],
        'scoreLeftEarly'         => ['setting' => 'keywordsLeftEarly', 'default' => '早退,Left early'],
        'scoreSickLeave'         => ['setting' => 'keywordsSickLeave', 'default' => '病假,Sick leave'],
        'scoreAuthorizedAbsence' => ['setting' => 'keywordsAuthorizedAbsence', 'default' => '公假,Authorized absence'],
        'scorePresent'           => ['setting' => 'keywordsPresent', 'default' => '出勤,Present'],
        'scoreNoRecord'          => ['setting' => 'keywordsNoRecord', 'default' => '无记录,No record'],
    ];

    if ($settings === null) {
        if ($cached !== null) {
            return $cached;
        }
        $settings = attendanceScoreSettings();
    }

    $resolved = [];
    foreach ($defaults as $key => $default) {
        $value = $settings[$key] ?? $default;
        $resolved[$key] = is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : $default;
    }

    $config = [];
    foreach ($keywordDefaults as $key => $keywordConfig) {
        $keywordValue = $settings[$keywordConfig['setting']] ?? $keywordConfig['default'];
        $keywords = attendanceParseKeywords($keywordValue);
        foreach ($keywords as $keyword) {
            $config[$keyword] = isset($config[$keyword])
                ? min($config[$keyword], $resolved[$key])
                : $resolved[$key];
        }
    }
    $config['__UNKNOWN__'] = $resolved['scoreUnknown'];

    if ($settings === null) {
        $cached = $config;
    }

    return $config;
}

function attendanceParseKeywords($value): array
{
    if (is_array($value)) {
        $parts = $value;
    } else {
        $parts = preg_split('/[,;\r\n]+/', (string) $value) ?: [];
    }

    $keywords = [];
    foreach ($parts as $part) {
        if (!is_scalar($part)) {
            continue;
        }
        $keyword = trim((string) $part);
        if ($keyword !== '') {
            $keywords[$keyword] = $keyword;
        }
    }

    return array_values($keywords);
}

function attendanceScoreSettings(): array
{
    global $container;

    if (!is_object($container) || !method_exists($container, 'get')) {
        return [];
    }

    try {
        $gateway = $container->get(SettingGateway::class);
        $values = [];
        foreach ([
            'scoreAbsence',
            'scoreSuspension',
            'scoreLate',
            'scoreLeftEarly',
            'scoreSickLeave',
            'scoreAuthorizedAbsence',
            'scorePresent',
            'scoreNoRecord',
            'scoreUnknown',
            'keywordsAbsence',
            'keywordsSuspension',
            'keywordsLate',
            'keywordsLeftEarly',
            'keywordsSickLeave',
            'keywordsAuthorizedAbsence',
            'keywordsPresent',
            'keywordsNoRecord',
        ] as $name) {
            $values[$name] = $gateway->getSettingByScope('Attendance Summary', $name);
        }

        return $values;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Resolve a keyword in an attendance string to its configured score.
 *
 * @param string $attendanceStr   Attendance type, e.g. "缺课 - 病假".
 * @param array  $config          Keyword => score map (see getAttendanceScoreConfig()).
 * @return float                  Lowest matched score, or 1.0 when nothing matches.
 */
function getAttendanceScore($attendanceStr, array $config)
{
    $matchedScores = [];

    foreach ($config as $keyword => $score) {
        if (stripos($attendanceStr, $keyword) !== false) {
            $matchedScores[] = $score;
        }
    }

    return empty($matchedScores) ? (float) ($config['__UNKNOWN__'] ?? 1.0) : min($matchedScores);
}

function attendanceIsNoRecord($attendanceType): bool
{
    return trim((string) $attendanceType) === '__NO_RECORD__';
}

function attendanceDisplayType($attendanceType): string
{
    return attendanceIsNoRecord($attendanceType) ? attendanceT('No record') : attendanceT((string) $attendanceType);
}

/**
 * Whether the current user may operate across all classes.
 *
 * Local deployments may provide canViewAllAssessmentClasses() as an additional
 * policy. Clean Gibbon installations fall back to the Formal Assessment "all"
 * grouped action.
 */
function attendanceCanViewAllClasses($gibbonPersonID): bool
{
    global $guid, $connection2;

    if (function_exists('canViewAllAssessmentClasses') && canViewAllAssessmentClasses($gibbonPersonID)) {
        return true;
    }

    if (empty($guid) || empty($connection2)) {
        return false;
    }

    $highest = getHighestGroupedAction(
        $guid,
        '/modules/Formal Assessment/internalAssessment_write_data.php',
        $connection2
    );

    return is_string($highest) && substr($highest, -4) === '_all';
}

/**
 * Validate and normalise a requested date range.
 *
 * @param string|null $start_date_input
 * @param string|null $end_date_input
 * @return array  [start, end] both formatted 'Y-m-d'.
 */
function getValidatedDates($start_date_input, $end_date_input)
{
    $start_date_input = is_scalar($start_date_input) ? trim((string) $start_date_input) : '';
    $end_date_input = is_scalar($end_date_input) ? trim((string) $end_date_input) : '';

    $today = new DateTime();

    $defaultEnd   = clone $today;
    $defaultStart = (clone $today)->modify('-7 days');

    $parseDate = function ($value) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }

        $parsed = DateTime::createFromFormat('!Y-m-d', $value);

        return $parsed && $parsed->format('Y-m-d') === $value ? $parsed : null;
    };

    $startDate = empty($start_date_input) ? clone $defaultStart : $parseDate($start_date_input);
    $endDate = empty($end_date_input) ? clone $defaultEnd : $parseDate($end_date_input);
    $startDate = $startDate ?: clone $defaultStart;
    $endDate = $endDate ?: clone $defaultEnd;

    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }

    if ($endDate > $today) {
        $endDate = clone $today;
    }

    if ($startDate > $today) {
        $startDate = (clone $today)->modify('-1 day');
    }

    // Clamp the range to the last 365 days and to a maximum span of 90 days.
    $past365 = (clone $today)->modify('-365 days');
    if ($endDate < $past365) {
        $endDate = clone $past365;
    }

    if ($endDate->diff($startDate)->days > 90) {
        $startDate = (clone $endDate)->modify('-90 days');
    }

    return [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')];
}

/**
 * Get the first day of the school term that contains today.
 *
 * @param PDO    $connection2
 * @param string $gibbonSchoolYearID
 * @return string  'Y-m-d', falling back to today.
 */
function getTermFirstDay($connection2, $gibbonSchoolYearID)
{
    $sql = "SELECT firstDay FROM gibbonschoolyearterm
            WHERE gibbonSchoolYearID = :gibbonSchoolYearID
              AND CURDATE() BETWEEN firstDay AND lastDay
            ORDER BY firstDay ASC LIMIT 1";

    try {
        $stmt = $connection2->prepare($sql);
        $stmt->execute(['gibbonSchoolYearID' => $gibbonSchoolYearID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!empty($row['firstDay'])) {
            return (new DateTime($row['firstDay']))->format('Y-m-d');
        }
    } catch (Exception $e) {
        // Fall through to today.
    }

    return date('Y-m-d');
}

/**
 * Get the date a person joined the school (used to avoid counting days before enrolment).
 *
 * @param PDO    $connection2
 * @param string $gibbonPersonID
 * @return string|null  'Y-m-d' or null when unavailable.
 */
function getPersonStartDate($connection2, $gibbonPersonID)
{
    $sql = "SELECT dateStart FROM gibbonperson
            WHERE gibbonPersonID = :gibbonPersonID
            LIMIT 1";

    try {
        $stmt = $connection2->prepare($sql);
        $stmt->execute(['gibbonPersonID' => $gibbonPersonID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['dateStart'] ?? null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Determine the start date to use for a person's attendance, never earlier than enrolment.
 *
 * @param PDO    $connection2
 * @param string $gibbonPersonID
 * @param string $termFirstDay
 * @return string  'Y-m-d'.
 */
function resolveAttendanceStartDate($connection2, $gibbonPersonID, $termFirstDay)
{
    $personStart = getPersonStartDate($connection2, $gibbonPersonID);

    return (!empty($personStart) && $personStart > $termFirstDay) ? $personStart : $termFirstDay;
}

/**
 * Build the shared CTE used to fetch a person's timetable attendance.
 *
 * The query pairs each scheduled lesson of a person's timetable with the latest
 * attendance record for that lesson/date, then keeps a single row per
 * date + period (preferring a real attendance record over the no-record sentinel).
 *
 * @param array  $personIDs          One or more gibbonPersonID values.
 * @param string $countClassAsSchool 'N' excludes Class-context logs.
 * @return string  SQL with positional placeholders (persons, dates, persons, dates).
 */
function buildAttendanceQuery(array $personIDs, $countClassAsSchool = 'Y')
{
    $placeholders = implode(',', array_fill(0, count($personIDs), '?'));
    $classFilter  = ($countClassAsSchool == 'N') ? " AND context <> 'Class'" : '';

    return "
    WITH schedule AS (
        SELECT
            cc.gibbonCourseClassID,
            p.gibbonPersonID,
            td.date AS course_date,
            trc.gibbonTTDayRowClassID,
            colrow.name AS periodName,
            colrow.timeStart,
            CONCAT(c.nameShort, '-', cc.nameShort) AS mergedCourseName
        FROM gibboncourseclassperson AS p
        JOIN gibboncourseclass AS cc ON p.gibbonCourseClassID = cc.gibbonCourseClassID
        JOIN gibboncourse AS c ON cc.gibbonCourseID = c.gibbonCourseID
        JOIN gibbonttdayrowclass AS trc ON cc.gibbonCourseClassID = trc.gibbonCourseClassID
        JOIN gibbonttdaydate AS td ON trc.gibbonTTDayID = td.gibbonTTDayID
        JOIN gibbonttcolumnrow AS colrow ON trc.gibbonTTColumnRowID = colrow.gibbonTTColumnRowID
        JOIN gibbonperson AS per ON p.gibbonPersonID = per.gibbonPersonID
        WHERE p.gibbonPersonID IN ({$placeholders})
            AND per.gibbonRoleIDPrimary = '003'
            AND td.date BETWEEN ? AND ?
    ),
    latest_attendance AS (
        SELECT
            gibbonTTDayRowClassID,
            gibbonCourseClassID,
            gibbonPersonID,
            date,
            type,
            timestampTaken,
            ROW_NUMBER() OVER (
                PARTITION BY gibbonTTDayRowClassID, gibbonCourseClassID, gibbonPersonID, date
                ORDER BY timestampTaken DESC, gibbonAttendanceLogPersonID DESC
            ) AS rn
        FROM gibbonattendancelogperson
        WHERE gibbonPersonID IN ({$placeholders})
            AND date BETWEEN ? AND ?{$classFilter}
    ),
    raw_result AS (
        SELECT
            s.mergedCourseName,
            s.gibbonCourseClassID,
            s.gibbonPersonID,
            s.periodName,
            s.timeStart,
            s.course_date,
            COALESCE(a.type, '__NO_RECORD__') AS attendanceType,
            a.timestampTaken AS latestTimestamp
        FROM schedule AS s
        LEFT JOIN latest_attendance AS a
            ON a.gibbonTTDayRowClassID = s.gibbonTTDayRowClassID
            AND a.gibbonCourseClassID = s.gibbonCourseClassID
            AND a.gibbonPersonID = s.gibbonPersonID
            AND a.date = s.course_date
            AND a.rn = 1
    )
    SELECT
        mergedCourseName,
        gibbonCourseClassID,
        gibbonPersonID,
        periodName,
        timeStart,
        course_date,
        attendanceType,
        latestTimestamp
    FROM (
        SELECT
            *,
            ROW_NUMBER() OVER (
                PARTITION BY gibbonPersonID, course_date, periodName
                ORDER BY CASE WHEN attendanceType = '__NO_RECORD__' THEN 2 ELSE 1 END, timeStart, gibbonCourseClassID
            ) AS rn
        FROM raw_result
    ) AS t
    WHERE rn = 1
    ORDER BY gibbonPersonID, course_date, timeStart, gibbonCourseClassID";
}

/**
 * Fetch timetable attendance for one or more people in a single query.
 *
 * @param PDO    $connection2
 * @param string $startDate
 * @param string $endDate
 * @param array  $personIDs
 * @param string $countClassAsSchool
 * @return array  [gibbonPersonID => [course_date => [lesson records]]].
 */
function getAttendanceRecordsForPersons($connection2, $startDate, $endDate, array $personIDs, $countClassAsSchool = 'Y')
{
    $personIDs = array_values(array_unique(array_filter($personIDs)));
    if (empty($personIDs)) {
        return [];
    }

    $bindings = array_merge($personIDs, [$startDate, $endDate], $personIDs, [$startDate, $endDate]);

    $stmt = $connection2->prepare(buildAttendanceQuery($personIDs, $countClassAsSchool));
    $stmt->execute($bindings);

    $recordsByPerson = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recordsByPerson[$row['gibbonPersonID']][$row['course_date']][] = [
            'course'     => $row['mergedCourseName'],
            'period'     => $row['periodName'],
            'courseID'   => $row['gibbonCourseClassID'],
            'time_start' => $row['timeStart'],
            'attendance' => $row['attendanceType'],
            'timestamp'  => $row['latestTimestamp'],
        ];
    }

    return $recordsByPerson;
}

/**
 * Fetch timetable attendance for a single person, grouped by date.
 *
 * @param PDO    $connection2
 * @param string $start_date
 * @param string $end_date
 * @param string $gibbonPersonID
 * @param string $countClassAsSchool
 * @return array  [course_date => [lesson records]].
 */
function getAttendanceRecords($connection2, $start_date, $end_date, $gibbonPersonID, $countClassAsSchool = 'Y')
{
    $records = getAttendanceRecordsForPersons($connection2, $start_date, $end_date, [$gibbonPersonID], $countClassAsSchool);

    return $records[$gibbonPersonID] ?? [];
}

/**
 * Count a person's attendance types per date (for attendance-trend charts).
 *
 * @param PDO    $connection2
 * @param string $start_date
 * @param string $end_date
 * @param string $gibbonPersonID
 * @param string $countClassAsSchool
 * @return array  List of ['name', 'reason', 'count', 'date'] rows.
 */
function getAttendanceSummary($connection2, $start_date, $end_date, $gibbonPersonID, $countClassAsSchool = 'Y')
{
    $records = getAttendanceRecords($connection2, $start_date, $end_date, $gibbonPersonID, $countClassAsSchool);

    $result = [];
    foreach ($records as $date => $lessons) {
        $typeCounts = [];
        foreach ($lessons as $lesson) {
            $type = $lesson['attendance'];
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }

        foreach ($typeCounts as $type => $count) {
            $result[] = ['name' => $type, 'reason' => '', 'count' => $count, 'date' => $date];
        }
    }

    return $result;
}

/**
 * Convert daily attendance into a per-day score breakdown.
 *
 * @param array      $attendanceData  [course_date => [lesson records]].
 * @param array|null $config          Keyword => score map; defaults to getAttendanceScoreConfig().
 * @return array  List of ['date', 'standard', 'attendance'] rows.
 */
function calculateDailyAttendance(array $attendanceData, $config = null)
{
    $config = $config ?? getAttendanceScoreConfig();

    $result = [];
    foreach ($attendanceData as $date => $lessons) {
        // A day where every lesson has no record is treated as no data at all.
        $hasRecord = false;
        foreach ($lessons as $lesson) {
            if (!attendanceIsNoRecord($lesson['attendance'])) {
                $hasRecord = true;
                break;
            }
        }

        if (!$hasRecord) {
            $result[] = ['date' => $date, 'standard' => '0.0', 'attendance' => '0.0'];
            continue;
        }

        $standard = count($lessons) * 1.0;
        $score = 0.0;
        foreach ($lessons as $lesson) {
            $score += getAttendanceScore($lesson['attendance'], $config);
        }

        $result[] = [
            'date'     => $date,
            'standard'   => number_format($standard, 1),
            'attendance' => number_format($score, 1),
        ];
    }

    return $result;
}

/**
 * Calculate the attendance rate string shown in the student list.
 *
 * @param PDO     $connection2
 * @param DataSet $studentsDataSet   Student rows (each containing gibbonPersonID).
 * @param string  $gibbonSchoolYearID
 * @param string  $countClassAsSchool
 * @return DataSet  The same data set, with attendanceRate / attendanceDetail added.
 */
function getAllStudentsAttendanceRate($connection2, DataSet $studentsDataSet, $gibbonSchoolYearID, $countClassAsSchool = 'Y')
{
    $termFirstDay = getTermFirstDay($connection2, $gibbonSchoolYearID);
    $endDate      = (new DateTime('last Friday'))->format('Y-m-d');

    $personIDs = [];
    foreach ($studentsDataSet->toArray() as $student) {
        if (!empty($student['gibbonPersonID'])) {
            $personIDs[] = $student['gibbonPersonID'];
        }
    }

    if (empty($personIDs)) {
        return new DataSet([]);
    }

    // One query for the whole set of students, rather than one per student.
    $recordsByPerson = getAttendanceRecordsForPersons($connection2, $termFirstDay, $endDate, $personIDs, $countClassAsSchool);

    $attendanceRates = [];
    foreach ($personIDs as $personID) {
        $totalStandard = 0.0;
        $totalScore = 0.0;

        foreach (calculateDailyAttendance($recordsByPerson[$personID] ?? []) as $day) {
            $totalStandard += floatval($day['standard']);
            $totalScore += floatval($day['attendance']);
        }

        if (abs($totalScore - $totalStandard) < 0.0001) {
            $attendanceRates[$personID] = '100%, ' . attendanceT('Present') . ' ' . $totalScore . ' - ' . attendanceT('Standard') . ' ' . $totalStandard;
        } else {
            $rate = ($totalStandard > 0) ? ($totalScore / $totalStandard) : 0.0;
            $attendanceRates[$personID] = number_format($rate * 100, 1) . '%, ' . attendanceT('Present') . ' ' . $totalScore . ' - ' . attendanceT('Standard') . ' ' . $totalStandard;
        }
    }

    $studentsDataSet->transform(function (&$row) use ($attendanceRates) {
        $personID = $row['gibbonPersonID'];
        $row['attendanceRate'] = $attendanceRates[$personID] ?? '0.0';
        $row['attendanceDetail'] = '<a href="./index.php?q=/modules/Students/student_view_details.php&gibbonPersonID=' . urlencode($personID) . '&subpage=Attendance">' . attendanceT('View Details') . '</a>';
    });

    return $studentsDataSet;
}

/**
 * Attach the shared hover/background attributes used by the attendance tables.
 *
 * @param string $bgColor
 * @return string
 */
function attendanceRowAttributes($bgColor)
{
    return ' data-bgcolor="' . $bgColor . '" style="background-color: ' . $bgColor . ';"'
        . ' onmouseover="this.style.backgroundColor = hoverColor(this.getAttribute(\'data-bgcolor\'));"'
        . ' onmouseout="this.style.backgroundColor = this.getAttribute(\'data-bgcolor\');"';
}

/**
 * Return the background colour for an attendance rate (percentage).
 *
 * @param float $rate
 * @return string  Hex colour.
 */
function getBgColor($rate)
{
    if ($rate < 40) {
        return '#FFCCCC';
    } elseif ($rate < 60) {
        return '#FFE6CC';
    } elseif ($rate < 80) {
        return '#FFFFCC';
    } elseif ($rate < 99) {
        return '#FFFFF0';
    }

    return '#FFFFFF';
}

/**
 * Get the localised short weekday name for a date.
 *
 * @param string $date  'Y-m-d'.
 * @return string
 */
function getWeekDayName($date)
{
    $dayNames = [
        'Monday'    => attendanceT('Monday'),
        'Tuesday'   => attendanceT('Tuesday'),
        'Wednesday' => attendanceT('Wednesday'),
        'Thursday'  => attendanceT('Thursday'),
        'Friday'    => attendanceT('Friday'),
        'Saturday'  => attendanceT('Saturday'),
        'Sunday'    => attendanceT('Sunday'),
    ];

    $day = date('l', strtotime($date));

    return $dayNames[$day] ?? $day;
}

/**
 * Sum the standard/attendance figures for the days between two dates.
 *
 * @param array    $dailySummary  [date => ['standard' => ..., 'attendance' => ...]].
 * @param DateTime $start
 * @param DateTime $end           Inclusive.
 * @return array  [standard, attendance].
 */
function sumDailyRange(array $dailySummary, DateTime $start, DateTime $end)
{
    $standard = 0.0;
    $attendance = 0.0;

    $day = clone $start;
    while ($day <= $end) {
        $key = $day->format('Y-m-d');
        if (isset($dailySummary[$key])) {
            $standard += floatval($dailySummary[$key]['standard']);
            $attendance += floatval($dailySummary[$key]['attendance']);
        }
        $day->modify('+1 day');
    }

    return [$standard, $attendance];
}

/**
 * Aggregate daily attendance into weekly, monthly and whole-term summaries.
 *
 * @param array  $allDailyAttendance  Output of calculateDailyAttendance().
 * @param string $termFirstDay        'Y-m-d'.
 * @param string $endDate             'Y-m-d'.
 * @return array
 */
function calculateAttendancePeriodSummaries(array $allDailyAttendance, $termFirstDay, $endDate)
{
    $dailySummary = [];
    foreach ($allDailyAttendance as $record) {
        $dailySummary[$record['date']] = $record;
    }

    $termStart = new DateTime($termFirstDay);
    $queryEnd  = new DateTime($endDate);

    // Weekly buckets of 7 days, starting from the first day of term.
    $weeklySummary = [];
    $weekNumber = 1;
    $weekStart = clone $termStart;
    while ($weekStart <= $queryEnd) {
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');
        if ($weekEnd > $queryEnd) {
            $weekEnd = clone $queryEnd;
        }

        [$standard, $attendance] = sumDailyRange($dailySummary, $weekStart, $weekEnd);
        $weeklySummary[] = [
            'weekNumber' => $weekNumber,
            'weekStart'  => $weekStart->format('Y-m-d'),
            'weekEnd'    => $weekEnd->format('Y-m-d'),
            'standard'   => $standard,
            'attendance' => $attendance,
            'rate'       => ($standard > 0) ? round(($attendance / $standard) * 100) : 0,
        ];

        $weekNumber++;
        $weekStart->modify('+7 days');
    }

    // Calendar-month buckets.
    $monthlySummary = [];
    $monthStart = clone $termStart;
    while ($monthStart <= $queryEnd) {
        $monthKey  = $monthStart->format('Y-m');
        $start     = ($monthKey === $termStart->format('Y-m')) ? new DateTime($termFirstDay) : new DateTime($monthStart->format('Y-m-01'));
        $end       = clone $monthStart;
        $end->modify('last day of this month');
        if ($end > $queryEnd) {
            $end = clone $queryEnd;
        }

        [$standard, $attendance] = sumDailyRange($dailySummary, $start, $end);
        $monthlySummary[] = [
            'month'      => $monthKey,
            'monthStart' => $start->format('Y-m-d'),
            'monthEnd'   => $end->format('Y-m-d'),
            'standard'   => $standard,
            'attendance' => $attendance,
            'rate'       => ($standard > 0) ? round(($attendance / $standard) * 100) : 0,
        ];

        $monthStart->modify('first day of next month');
    }

    // Group each week under the month in which it starts.
    $groupedWeekly = [];
    foreach ($weeklySummary as $week) {
        $groupedWeekly[date('Y-m', strtotime($week['weekStart']))][] = $week;
    }

    // Whole-term totals.
    $termStandard = 0.0;
    $termAttendance = 0.0;
    foreach ($allDailyAttendance as $record) {
        $recordDate = new DateTime($record['date']);
        if ($recordDate >= $termStart && $recordDate <= $queryEnd) {
            $termStandard += floatval($record['standard']);
            $termAttendance += floatval($record['attendance']);
        }
    }

    return [
        'weekly'         => $weeklySummary,
        'monthly'        => $monthlySummary,
        'groupedWeekly'  => $groupedWeekly,
        'termStandard'   => $termStandard,
        'termAttendance' => $termAttendance,
        'termRate'       => ($termStandard > 0) ? round(($termAttendance / $termStandard) * 100, 1) : 0,
    ];
}

/**
 * Render the daily attendance table, with an expandable per-lesson detail row.
 *
 * @param array $dailyAttendance   Rows from calculateDailyAttendance() (already date-filtered).
 * @param array $attendanceRecords [course_date => [lesson records]].
 * @return string  HTML.
 */
function renderAttendanceDailyTable(array $dailyAttendance, array $attendanceRecords)
{
    $output = '<table class="attendanceSummaryTable"><thead><tr>';
    $output .= '<th>' . attendanceT('Date') . '</th>';
    $output .= '<th>' . attendanceT('Weekday') . '</th>';
    $output .= '<th>' . attendanceT('Standard') . '</th>';
    $output .= '<th>' . attendanceT('Day') . '</th>';
    $output .= '<th>' . attendanceT('Attendance Rate') . '</th>';
    $output .= '<th>' . attendanceT('Actions') . '</th>';
    $output .= '</tr></thead><tbody>';

    foreach ($dailyAttendance as $record) {
        $dateStr    = $record['date'];
        $standard   = floatval($record['standard']);
        $attendance = floatval($record['attendance']);
        $rate       = ($standard > 0) ? round(($attendance / $standard) * 100) : 0;
        $bgColor    = ($standard == 0 && $attendance == 0) ? '#E0E0E0' : getBgColor($rate);

        $output .= '<tr' . attendanceRowAttributes($bgColor) . '>';
        $output .= '<td>' . htmlPrep($dateStr) . '</td>';
        $output .= '<td>' . htmlPrep(getWeekDayName($dateStr)) . '</td>';

        if ($standard == 0 && $attendance == 0) {
            $output .= '<td colspan="3">' . attendanceT('No record') . '</td>';
        } else {
            $output .= '<td>' . htmlPrep($record['standard']) . '</td>';
            $output .= '<td>' . htmlPrep($record['attendance']) . '</td>';
            $output .= '<td>' . $rate . '%</td>';
        }

        $output .= '<td><button type="button" class="attendanceSummaryToggle" onclick="toggleDetails(\'' . htmlPrep($dateStr) . '\')">' . attendanceT('View Details') . '</button></td>';
        $output .= '</tr>';

        $output .= '<tr id="details-' . htmlPrep($dateStr) . '" class="attendanceSummaryDetails"><td colspan="6">';

        if (!empty($attendanceRecords[$dateStr])) {
            $output .= '<table class="attendanceSummaryTable"><thead><tr>';
            $output .= '<th>' . attendanceT('Course') . '</th>';
            $output .= '<th>' . attendanceT('Period') . '</th>';
            $output .= '<th>' . attendanceT('Lesson Time') . '</th>';
            $output .= '<th>' . attendanceT('Status') . '</th>';
            $output .= '</tr></thead><tbody>';

            foreach ($attendanceRecords[$dateStr] as $lesson) {
                $output .= '<tr>';
                $output .= '<td>' . htmlPrep($lesson['course']) . '</td>';
                $output .= '<td>' . htmlPrep($lesson['period']) . '</td>';
                $output .= '<td>' . htmlPrep($lesson['time_start']) . '</td>';
                $output .= '<td>' . htmlPrep($lesson['attendance']) . '</td>';
                $output .= '</tr>';
            }

            $output .= '</tbody></table>';
        } else {
            $output .= attendanceT('No detailed records.');
        }

        $output .= '</td></tr>';
    }

    return $output . '</tbody></table>';
}

/**
 * Render the weekly/monthly/term summary table.
 *
 * @param array $summary  Output of calculateAttendancePeriodSummaries().
 * @return string  HTML.
 */
function renderAttendancePeriodTable(array $summary)
{
    $output = '<table class="attendanceSummaryTable"><thead><tr>';
    $output .= '<th>' . attendanceT('Summary') . '</th>';
    $output .= '<th>' . attendanceT('Date Range') . '</th>';
    $output .= '<th>' . attendanceT('Standard') . '</th>';
    $output .= '<th>' . attendanceT('Present') . '</th>';
    $output .= '<th>' . attendanceT('Attendance Rate') . '</th>';
    $output .= '</tr></thead><tbody>';

    foreach ($summary['monthly'] as $month) {
        $output .= '<tr class="month-row"' . attendanceRowAttributes('#B8DAFF') . '>';
        $output .= '<td>' . htmlPrep($month['month']) . attendanceT(' (Monthly)') . '</td>';
        $output .= '<td>' . htmlPrep($month['monthStart']) . ' ~ ' . htmlPrep($month['monthEnd']) . '</td>';
        $output .= '<td>' . htmlPrep($month['standard']) . '</td>';
        $output .= '<td>' . htmlPrep($month['attendance']) . '</td>';
        $output .= '<td>' . htmlPrep($month['rate']) . '%</td>';
        $output .= '</tr>';

        foreach ($summary['groupedWeekly'][$month['month']] ?? [] as $week) {
            $bgColor = ($week['standard'] == 0 && $week['attendance'] == 0) ? '#E0E0E0' : getBgColor($week['rate']);

            $output .= '<tr' . attendanceRowAttributes($bgColor) . '>';
            $output .= '<td>' . sprintf(attendanceT('Week %d'), $week['weekNumber']) . '</td>';
            $output .= '<td>' . htmlPrep($week['weekStart']) . ' ~ ' . htmlPrep($week['weekEnd']) . '</td>';
            $output .= '<td>' . htmlPrep($week['standard']) . '</td>';
            $output .= '<td>' . htmlPrep($week['attendance']) . '</td>';
            $output .= '<td>' . htmlPrep($week['rate']) . '%</td>';
            $output .= '</tr>';
        }
    }

    $output .= '<tr' . attendanceRowAttributes('#E0E0E0') . '>';
    $output .= '<td colspan="5">' . sprintf(
        attendanceT('Overall term attendance: standard total %s, attendance total %s, rate %s%%'),
        $summary['termStandard'],
        $summary['termAttendance'],
        $summary['termRate']
    ) . '</td>';
    $output .= '</tr>';

    return $output . '</tbody></table>';
}

/**
 * Extract the transfer selections from submitted form data.
 *
 * @param array $data  Form data; picks up "transfer-*" keys set to "on".
 * @return array  List of ['gibbonPersonID', 'gibbonCourseClassID', 'gibbonTTDayRowClassID'].
 */
function getTransferData(array $data)
{
    $personID      = is_scalar($data['gibbonPersonID'] ?? null) ? (string) $data['gibbonPersonID'] : '';
    $courseClassID = is_scalar($data['gibbonCourseClassID'] ?? null) ? (string) $data['gibbonCourseClassID'] : '';

    $result = [];
    foreach ($data as $key => $value) {
        if (strpos($key, 'transfer-') === 0 && $value === 'on') {
            $result[] = [
                'gibbonPersonID'        => $personID,
                'gibbonCourseClassID'   => $courseClassID,
                'gibbonTTDayRowClassID' => substr($key, strlen('transfer-')),
            ];
        }
    }

    return $result;
}

/**
 * Validate a class/student pair for the attendance transfer workflow.
 *
 * The class must belong to the current school year, the current user must be
 * able to manage it, and the selected student must be enrolled in it.
 *
 * @return array|false  Class row, or false when access is not allowed.
 */
function getAttendanceTransferClass($pdo, $gibbonCourseClassID, $gibbonPersonID, $gibbonStudentID, $gibbonSchoolYearID)
{
    foreach ([$gibbonCourseClassID, $gibbonPersonID, $gibbonStudentID, $gibbonSchoolYearID] as $value) {
        if (!is_scalar($value) || (string) $value === '') {
            return false;
        }
    }

    $class = $pdo->selectOne(
        "SELECT cc.gibbonCourseClassID, CONCAT(c.nameShort, '.', cc.nameShort) AS name
         FROM gibbonCourseClass AS cc
         JOIN gibbonCourse AS c ON c.gibbonCourseID = cc.gibbonCourseID
         WHERE cc.gibbonCourseClassID = :gibbonCourseClassID
           AND c.gibbonSchoolYearID = :gibbonSchoolYearID",
        ['gibbonCourseClassID' => $gibbonCourseClassID, 'gibbonSchoolYearID' => $gibbonSchoolYearID]
    );

    if (empty($class)) {
        return false;
    }

    if (!attendanceCanViewAllClasses($gibbonPersonID)) {
        $isTeacher = $pdo->selectOne(
            "SELECT 1 FROM gibbonCourseClassPerson
             WHERE gibbonCourseClassID = :gibbonCourseClassID
               AND gibbonPersonID = :gibbonPersonID
               AND role IN ('Teacher', 'Assistant', 'Technician')
             LIMIT 1",
            ['gibbonCourseClassID' => $gibbonCourseClassID, 'gibbonPersonID' => $gibbonPersonID]
        );

        if (empty($isTeacher)) {
            return false;
        }
    }

    $isStudent = $pdo->selectOne(
        "SELECT 1 FROM gibbonCourseClassPerson
         WHERE gibbonCourseClassID = :gibbonCourseClassID
           AND gibbonPersonID = :gibbonStudentID
           AND role = 'Student'
         LIMIT 1",
        ['gibbonCourseClassID' => $gibbonCourseClassID, 'gibbonStudentID' => $gibbonStudentID]
    );

    return empty($isStudent) ? false : $class;
}

/**
 * Expand transfer selections into old/new record pairs, with transferable alternatives.
 *
 * @param array $submittedData  Output of getTransferData().
 * @param array $sqlData        Rows describing the timetable lessons of the source class.
 * @return array
 */
function generateTransferData($submittedData, $sqlData)
{
    // Index the source lessons once, rather than scanning the whole list per selection.
    $lessonsByRowID = [];
    foreach ($sqlData as $row) {
        $lessonsByRowID[$row['gibbonTTDayRowClassID']] = $row;
    }

    $result = [];
    foreach ($submittedData as $submitted) {
        $oldRowID = $submitted['gibbonTTDayRowClassID'];
        $oldCourseClassID = $submitted['gibbonCourseClassID'];

        if (!isset($lessonsByRowID[$oldRowID])) {
            continue;
        }

        $oldRecord = $lessonsByRowID[$oldRowID];
        $ttColumnRowID = $oldRecord['gibbonTTColumnRowID'];

        $transferableList = [];
        foreach ($sqlData as $row) {
            if ($row['gibbonTTColumnRowID'] == $ttColumnRowID && $row['gibbonCourseClassID'] != $oldCourseClassID) {
                $transferableList[] = [
                    'gibbonCourseClassID_NEW'     => $row['gibbonCourseClassID'],
                    'gibbonCourseClassName_NEW'   => $row['courseName'],
                    'gibbonTTDayRowClassID_NEW'   => $row['gibbonTTDayRowClassID'],
                    'gibbonTTDayRowClassName_NEW' => $row['periodName'],
                ];
            }
        }

        usort($transferableList, function ($a, $b) {
            return strcmp($a['gibbonCourseClassName_NEW'], $b['gibbonCourseClassName_NEW']);
        });

        $result[] = [
            'gibbonPersonID'              => $submitted['gibbonPersonID'],
            'gibbonCourseClassID_OLD'     => $oldCourseClassID,
            'gibbonCourseClassName_OLD'   => $oldRecord['courseName'],
            'gibbonTTDayRowClassID_OLD'   => $oldRowID,
            'gibbonTTDayRowClassName_OLD' => $oldRecord['periodName'],
            'gibbonTTDayName'             => $oldRecord['dayName'],
            'gibbonTTColumnRowID'         => $ttColumnRowID,
            'transferableList'            => $transferableList,
        ];
    }

    return $result;
}

/**
 * Count a person's attendance logs for a class lesson within a date range.
 *
 * @param PDO    $pdo
 * @param string $gibbonPersonID
 * @param string $gibbonCourseClassID
 * @param string $gibbonTTDayRowClassID
 * @param string $start_date
 * @param string $end_date
 * @return int  -1 when no rows, -2 on error.
 */
function getAttendanceLogCount($pdo, $gibbonPersonID, $gibbonCourseClassID, $gibbonTTDayRowClassID, $start_date, $end_date)
{
    $sql = "SELECT count(gibbonAttendanceLogPersonID) AS count
            FROM gibbonattendancelogperson
            WHERE gibbonCourseClassID = :gibbonCourseClassID
              AND gibbonTTDayRowClassID = :gibbonTTDayRowClassID
              AND context = 'Class'
              AND gibbonPersonID = :gibbonPersonID
              AND date BETWEEN :start_date AND :end_date";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'gibbonCourseClassID'   => $gibbonCourseClassID,
            'gibbonTTDayRowClassID' => $gibbonTTDayRowClassID,
            'gibbonPersonID'        => $gibbonPersonID,
            'start_date'            => $start_date,
            'end_date'              => $end_date,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['count'] : -1;
    } catch (PDOException $e) {
        return -2;
    }
}

/**
 * Strip digits and latin letters from a string (e.g. "1周一排课" => "周一排课").
 *
 * @param string $input
 * @return string
 */
function removeAlphaNumeric($input)
{
    return preg_replace('/[A-Za-z0-9]/', '', $input);
}

/**
 * Parse and validate the submitted attendance replacement data.
 *
 * Replacement fields are named "replacement-{OLD_COURSE}_{OLD_ROW}" with the
 * value "{NEW_COURSE}.{NEW_ROW}". Each pair is only kept when the old and new
 * timetable rows share the same column row and TT day.
 *
 * @param PDO   $pdo
 * @param array $data
 * @return array  ['gibbonStudentID', 'start_date', 'end_date', 'replacement' => [...]]
 */
function parseAttendance($pdo, $data)
{
    $result = [
        'gibbonStudentID' => is_scalar($data['gibbonStudentID'] ?? null) ? (string) $data['gibbonStudentID'] : '',
        'start_date'      => is_scalar($data['start_date'] ?? null) ? (string) $data['start_date'] : '',
        'end_date'        => is_scalar($data['end_date'] ?? null) ? (string) $data['end_date'] : '',
        'replacement'     => [],
    ];

    $replacements = [];
    $ttDayRowIDs = [];

    foreach ($data as $key => $value) {
        if (strpos($key, 'replacement-') !== 0 || !is_scalar($value)) {
            continue;
        }

        $parts = explode('_', substr($key, strlen('replacement-')));
        $newParts = explode('.', $value);
        if (count($parts) != 2 || count($newParts) != 2) {
            continue;
        }

        $ttDayRowIDs[] = $parts[1];
        $ttDayRowIDs[] = $newParts[1];

        $replacements[] = [
            'gibbonCourseClassID_OLD'   => $parts[0],
            'gibbonTTDayRowClassID_OLD' => $parts[1],
            'gibbonCourseClassID_NEW'   => $newParts[0],
            'gibbonTTDayRowClassID_NEW' => $newParts[1],
        ];
    }

    $ttDayRowIDs = array_values(array_unique($ttDayRowIDs));
    if (empty($ttDayRowIDs)) {
        return $result;
    }

    $placeholders = implode(',', array_fill(0, count($ttDayRowIDs), '?'));
    $sql = "SELECT gibbonTTDayRowClassID, gibbonCourseClassID, gibbonTTColumnRowID, gibbonTTDayID
            FROM gibbonttdayrowclass
            WHERE gibbonTTDayRowClassID IN ({$placeholders})";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($ttDayRowIDs);

    $rows = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[$row['gibbonTTDayRowClassID']] = $row;
    }

    foreach ($replacements as $replacement) {
        $oldID = $replacement['gibbonTTDayRowClassID_OLD'];
        $newID = $replacement['gibbonTTDayRowClassID_NEW'];

        if (!isset($rows[$oldID], $rows[$newID])) {
            continue;
        }

        if ((string) $rows[$oldID]['gibbonCourseClassID'] === (string) $replacement['gibbonCourseClassID_OLD']
            && (string) $rows[$newID]['gibbonCourseClassID'] === (string) $replacement['gibbonCourseClassID_NEW']
            && $rows[$oldID]['gibbonTTColumnRowID'] === $rows[$newID]['gibbonTTColumnRowID']
            && $rows[$oldID]['gibbonTTDayID'] === $rows[$newID]['gibbonTTDayID']) {
            $result['replacement'][] = $replacement;
        }
    }

    return $result;
}

/**
 * Apply attendance replacements inside a transaction and log each transfer.
 *
 * @param PDO    $pdo             Transactional connection.
 * @param array  $data            Output of parseAttendance().
 * @param string $gibbonPersonID  Submitter's gibbonPersonID.
 * @param string $url             Redirect URL to append the result code to.
 * @return string  The original URL with &return=success0 or &return=error2 appended.
 */
function processAttendanceTransfer($pdo, $data, $gibbonPersonID, $url)
{
    $url .= '&gibbonPersonID=' . urlencode((string) $data['gibbonStudentID']);

    $updateSQL = "UPDATE gibbonattendancelogperson
                  SET gibbonCourseClassID = :newCourseID,
                      gibbonTTDayRowClassID = :newScheduleID
                  WHERE gibbonPersonID = :gibbonPersonID
                    AND gibbonCourseClassID = :oldCourseID
                    AND gibbonTTDayRowClassID = :oldScheduleID
                    AND context = 'Class'
                    AND date BETWEEN :start_date AND :end_date";

    $insertSQL = "INSERT INTO attendance_transfer_record
                  (submitterID, oldCourseID, oldScheduleID, newCourseID, newScheduleID, affectedCount, startDate, endDate)
                  VALUES (:submitterID, :oldCourseID, :oldScheduleID, :newCourseID, :newScheduleID, :affectedCount, :start_date, :end_date)";

    try {
        $pdo->beginTransaction();

        $submitterID = !empty($gibbonPersonID) ? $gibbonPersonID : $data['gibbonStudentID'];

        foreach ($data['replacement'] as $replacement) {
            $stmtUpdate = $pdo->prepare($updateSQL);
            $stmtUpdate->execute([
                'newCourseID'    => $replacement['gibbonCourseClassID_NEW'],
                'newScheduleID'  => $replacement['gibbonTTDayRowClassID_NEW'],
                'gibbonPersonID' => $data['gibbonStudentID'],
                'oldCourseID'    => $replacement['gibbonCourseClassID_OLD'],
                'oldScheduleID'  => $replacement['gibbonTTDayRowClassID_OLD'],
                'start_date'     => $data['start_date'],
                'end_date'       => $data['end_date'],
            ]);

            $stmtInsert = $pdo->prepare($insertSQL);
            $stmtInsert->execute([
                'submitterID'   => $submitterID,
                'oldCourseID'   => $replacement['gibbonCourseClassID_OLD'],
                'oldScheduleID' => $replacement['gibbonTTDayRowClassID_OLD'],
                'newCourseID'   => $replacement['gibbonCourseClassID_NEW'],
                'newScheduleID' => $replacement['gibbonTTDayRowClassID_NEW'],
                'affectedCount' => $stmtUpdate->rowCount(),
                'start_date'    => $data['start_date'],
                'end_date'      => $data['end_date'],
            ]);
        }

        $pdo->commit();

        return $url . '&return=success0';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return $url . '&return=error2';
    }
}

// Get the last N school days from currentDate within the last 100.
function getLastNSchoolDays($guid, $connection2, $date, $n = 5, $inclusive = false)
{
    $timestamp = Format::timestamp($date);
    if ($inclusive == true) {
        $timestamp += 86400;
    }

    $count = 0;
    $spin = 1;
    $max = max($n, 100);
    $lastNSchoolDays = [];
    while ($count < $n and $spin <= $max) {
        $date = date('Y-m-d', ($timestamp - ($spin * 86400)));
        if (isSchoolOpen($guid, $date, $connection2)) {
            $lastNSchoolDays[$count] = $date;
            ++$count;
        }
        ++$spin;
    }

    return $lastNSchoolDays;
}

/**
 * Prevent spreadsheet applications from evaluating user-controlled cell values.
 */
function attendanceCsvSafeCell($value): string
{
    $value = (string) $value;

    return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'".$value : $value;
}

/**
 * Send a UTF-8 CSV download and stop the request.
 *
 * @param string $filename
 * @param array  $header
 * @param array  $rows
 */
function sendAttendanceCsv($filename, array $header, array $rows)
{
    if (headers_sent()) {
        return;
    }

    $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'attendance.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $header, ',', '"', '');
    foreach ($rows as $row) {
        $row = array_map('attendanceCsvSafeCell', $row);
        fputcsv($output, $row, ',', '"', '');
    }
    fclose($output);
    exit;
}
