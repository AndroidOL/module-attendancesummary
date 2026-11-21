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

namespace Gibbon\Module\Attendance;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Services\Format;

/**
 * Attendance Analytics
 *
 * School-wide attendance analytics for one school year or term: rates per form
 * group / year group / weekday / month, an absence-type breakdown and an
 * at-risk student list. Uses the "end of day" attendance model (the last log
 * recorded for a date wins), matching StudentHistoryData and AttendanceLifecycle.
 *
 * @version v1
 * @since   v1
 */
class AttendanceAnalytics
{
    /** @var Connection */
    protected $pdo;

    /** @var string 'N' excludes Class-context logs from the daily status. */
    protected $countClassAsSchool;

    protected $calendarLoaded = false;
    protected $schoolDayNames = [];
    protected $closures = [];
    protected $schoolYears = [];   // [yearID => ['name','firstDay','lastDay']]
    protected $terms = [];         // ordered term rows
    protected $termsByYear = [];   // [yearID => [term rows]]

    public function __construct(Connection $pdo, $countClassAsSchool = 'Y')
    {
        $this->pdo = $pdo;
        $this->countClassAsSchool = $countClassAsSchool;
    }

    /**
     * Load school days of week, school closures, school years and terms (once).
     */
    public function loadCalendar()
    {
        if ($this->calendarLoaded) {
            return;
        }

        $this->schoolDayNames = $this->pdo
            ->select("SELECT nameShort FROM gibbonDaysOfWeek WHERE schoolDay='Y'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($this->pdo->select("SELECT date FROM gibbonSchoolYearSpecialDay WHERE type='School Closure'")->fetchAll(\PDO::FETCH_COLUMN) as $date) {
            $this->closures[$date] = true;
        }

        foreach ($this->pdo->select("SELECT gibbonSchoolYearID, name, firstDay, lastDay FROM gibbonSchoolYear ORDER BY sequenceNumber")->fetchAll(\PDO::FETCH_ASSOC) as $year) {
            $this->schoolYears[$year['gibbonSchoolYearID']] = $year;
        }

        $sql = "SELECT gibbonSchoolYearTerm.gibbonSchoolYearTermID, gibbonSchoolYearTerm.gibbonSchoolYearID, gibbonSchoolYearTerm.name,
                       gibbonSchoolYearTerm.firstDay, gibbonSchoolYearTerm.lastDay, gibbonSchoolYear.name AS schoolYearName
                FROM gibbonSchoolYearTerm
                JOIN gibbonSchoolYear ON gibbonSchoolYear.gibbonSchoolYearID = gibbonSchoolYearTerm.gibbonSchoolYearID
                ORDER BY gibbonSchoolYear.sequenceNumber, gibbonSchoolYearTerm.sequenceNumber";

        $this->terms = $this->pdo->select($sql)->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($this->terms as $term) {
            $this->termsByYear[$term['gibbonSchoolYearID']][] = $term;
        }

        $this->calendarLoaded = true;
    }

    /**
     * Options for the scope selector: one entry per school year and per term.
     *
     * @return array  [value => label]
     */
    public function getScopeOptions()
    {
        $this->loadCalendar();

        $options = [];

        foreach ($this->schoolYears as $yearID => $year) {
            $options['year:' . $yearID] = sprintf(attendanceT('%s School Year'), $year['name']);
        }

        foreach ($this->terms as $term) {
            $options['term:' . $term['gibbonSchoolYearTermID']] = $term['schoolYearName'] . ' · ' . $term['name'];
        }

        return $options;
    }

    /**
     * @param string $scope  'year:<gibbonSchoolYearID>' | 'term:<gibbonSchoolYearTermID>'
     * @return string  A human readable label for the scope.
     */
    public function getScopeLabel($scope)
    {
        $this->loadCalendar();

        list($scopeType, $scopeID) = array_pad(explode(':', $scope, 2), 2, '');

        if ($scopeType == 'term') {
            foreach ($this->terms as $term) {
                if ($term['gibbonSchoolYearTermID'] == $scopeID) {
                    return $term['schoolYearName'] . ' · ' . $term['name'];
                }
            }
        }

        if ($scopeType == 'year' && isset($this->schoolYears[$scopeID])) {
            return sprintf(attendanceT('%s School Year'), $this->schoolYears[$scopeID]['name']);
        }

        return attendanceT('All');
    }

    /**
     * Analyse attendance for every student enrolled in the scope.
     *
     * @param string $scope
     * @param float  $threshold  At-risk cut-off (attendance rate %).
     * @return array
     */
    public function analyse($scope, $threshold = 90)
    {
        $this->loadCalendar();

        list($scopeType, $scopeID) = array_pad(explode(':', $scope, 2), 2, '');

        // Terms in scope (a single school year).
        if ($scopeType == 'term' && !empty($scopeID)) {
            $terms = array_values(array_filter($this->terms, function ($term) use ($scopeID) {
                return $term['gibbonSchoolYearTermID'] == $scopeID;
            }));
        } elseif ($scopeType == 'year' && !empty($scopeID)) {
            $terms = $this->termsByYear[$scopeID] ?? [];
        } else {
            $terms = [];
        }

        $result = [
            'scope'         => $scope,
            'scopeLabel'    => $this->getScopeLabel($scope),
            'threshold'     => $threshold,
            'dateStart'     => null,
            'dateEnd'       => null,
            'schoolDays'    => 0,
            'studentCount'  => 0,
            'overall'       => $this->emptyCounts(),
            'overallRate'   => 0.0,
            'formGroups'    => [],
            'yearGroups'    => [],
            'byWeekday'     => [],
            'byMonth'       => [],
            'absentTypes'   => [],
            'students'      => [],
            'atRisk'        => [],
        ];

        if (empty($terms)) {
            return $result;
        }

        $gibbonSchoolYearID = $terms[0]['gibbonSchoolYearID'];
        $today = date('Y-m-d');

        // ---- School days in scope, up to today ----
        $dates = [];
        $dateSet = [];
        foreach ($terms as $term) {
            $cursor = new \DateTime($term['firstDay']);
            $termEnd = $term['lastDay'] < $today ? $term['lastDay'] : $today;
            $end = new \DateTime($termEnd);

            for (; $cursor <= $end; $cursor->modify('+1 day')) {
                $date = $cursor->format('Y-m-d');
                if (isset($dateSet[$date])) continue;
                if (!$this->isSchoolDay($date)) continue;
                $dateSet[$date] = true;
                $dates[] = $date;
            }
        }

        sort($dates);

        if (empty($dates)) {
            return $result;
        }

        $rangeStart = reset($dates);
        $rangeEnd = end($dates);

        $result['dateStart'] = $rangeStart;
        $result['dateEnd'] = $rangeEnd;
        $result['schoolDays'] = count($dates);

        // ---- Students enrolled in the scope ----
        $sql = "SELECT gibbonStudentEnrolment.gibbonPersonID, gibbonPerson.title, gibbonPerson.preferredName, gibbonPerson.surname,
                       gibbonFormGroup.name AS formGroupName, gibbonYearGroup.name AS yearGroupName
                FROM gibbonStudentEnrolment
                JOIN gibbonPerson ON gibbonPerson.gibbonPersonID = gibbonStudentEnrolment.gibbonPersonID
                JOIN gibbonFormGroup ON gibbonFormGroup.gibbonFormGroupID = gibbonStudentEnrolment.gibbonFormGroupID
                JOIN gibbonYearGroup ON gibbonYearGroup.gibbonYearGroupID = gibbonStudentEnrolment.gibbonYearGroupID
                WHERE gibbonStudentEnrolment.gibbonSchoolYearID = :gibbonSchoolYearID
                ORDER BY gibbonYearGroup.sequenceNumber, gibbonFormGroup.nameShort, gibbonPerson.surname, gibbonPerson.preferredName";

        $students = $this->pdo->select($sql, ['gibbonSchoolYearID' => $gibbonSchoolYearID])->fetchAll(\PDO::FETCH_ASSOC);
        $result['studentCount'] = count($students);

        if (empty($students)) {
            return $result;
        }

        $dailyStatus = $this->getDailyStatus(array_column($students, 'gibbonPersonID'), $rangeStart, $rangeEnd);

        // ---- Aggregate ----
        $byFormGroup = [];
        $byYearGroup = [];
        $weekdayCounts = [];
        $weekdayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        // Per-date buckets let weekday/month totals be folded once per date instead
        // of five times per student-day.
        $dateWeekday = [];
        $dateMonth = [];
        $dateCounts = [];
        foreach ($dates as $date) {
            $weekday = date('D', strtotime($date));
            $monthKey = substr($date, 0, 7);
            $dateWeekday[$date] = $weekday;
            $dateMonth[$date] = $monthKey;

            if (!isset($weekdayCounts[$weekday])) $weekdayCounts[$weekday] = $this->emptyCounts();
            if (!isset($result['byMonth'][$monthKey])) {
                $result['byMonth'][$monthKey] = ['counts' => $this->emptyCounts()];
            }
            $dateCounts[$date] = $this->emptyCounts();
        }

        foreach ($students as $student) {
            $personID = $student['gibbonPersonID'];
            $formGroup = $student['formGroupName'] !== '' ? $student['formGroupName'] : attendanceT('Unassigned Form Group');
            $yearGroup = $student['yearGroupName'] !== '' ? $student['yearGroupName'] : attendanceT('Unassigned Year Group');

            if (!isset($byFormGroup[$formGroup])) {
                $byFormGroup[$formGroup] = ['counts' => $this->emptyCounts(), 'students' => 0];
            }
            if (!isset($byYearGroup[$yearGroup])) {
                $byYearGroup[$yearGroup] = ['counts' => $this->emptyCounts(), 'students' => 0];
            }
            $byFormGroup[$formGroup]['students']++;
            $byYearGroup[$yearGroup]['students']++;

            $studentCounts = $this->emptyCounts();

            foreach ($dates as $date) {
                $record = $dailyStatus[$personID][$date] ?? null;
                $status = $record['status'] ?? 'none';

                $this->addStatus($studentCounts, $status, $record);
                $this->addStatus($dateCounts[$date], $status, $record);

                if ($status == 'absent') {
                    $typeKey = ($record['type'] ?? '') !== '' ? $record['type'] : attendanceT('Unclassified');
                    $result['absentTypes'][$typeKey] = ($result['absentTypes'][$typeKey] ?? 0) + 1;
                }
            }

            $this->addCounts($byFormGroup[$formGroup]['counts'], $studentCounts);
            $this->addCounts($byYearGroup[$yearGroup]['counts'], $studentCounts);
            $this->addCounts($result['overall'], $studentCounts);

            $result['students'][] = [
                'gibbonPersonID' => $personID,
                'name'           => Format::name($student['title'], $student['preferredName'], $student['surname'], 'Student'),
                'formGroup'      => $formGroup,
                'yearGroup'      => $yearGroup,
                'counts'         => $studentCounts,
                'rate'           => $this->rate($studentCounts),
            ];
        }

        foreach ($dates as $date) {
            $this->addCounts($weekdayCounts[$dateWeekday[$date]], $dateCounts[$date]);
            $this->addCounts($result['byMonth'][$dateMonth[$date]]['counts'], $dateCounts[$date]);
        }

        // ---- Shape the output ----
        $result['overallRate'] = $this->rate($result['overall']);

        foreach ($byFormGroup as $name => $group) {
            $result['formGroups'][] = [
                'name'     => $name,
                'students' => $group['students'],
                'counts'   => $group['counts'],
                'rate'     => $this->rate($group['counts']),
            ];
        }

        foreach ($byYearGroup as $name => $group) {
            $result['yearGroups'][] = [
                'name'     => $name,
                'students' => $group['students'],
                'counts'   => $group['counts'],
                'rate'     => $this->rate($group['counts']),
            ];
        }

        // Lower rates first: the weakest groups/students surface at the top.
        usort($result['formGroups'], function ($a, $b) { return $a['rate'] <=> $b['rate']; });
        usort($result['yearGroups'], function ($a, $b) { return $a['rate'] <=> $b['rate']; });
        usort($result['students'], function ($a, $b) { return $a['rate'] <=> $b['rate']; });

        foreach ($weekdayNames as $weekday) {
            if (!isset($weekdayCounts[$weekday])) continue;
            $result['byWeekday'][$weekday] = [
                'counts' => $weekdayCounts[$weekday],
                'rate'   => $this->rate($weekdayCounts[$weekday]),
            ];
        }

        ksort($result['byMonth']);
        foreach ($result['byMonth'] as $monthKey => $month) {
            $result['byMonth'][$monthKey]['rate'] = $this->rate($month['counts']);
        }

        arsort($result['absentTypes']);

        foreach ($result['students'] as $student) {
            if ($student['rate'] < $threshold) {
                $result['atRisk'][] = $student;
            }
        }

        return $result;
    }

    /**
     * End-of-day attendance status for a set of students across a date range.
     *
     * @return array  [personID => [date => ['status','type','reason','scope','context']]]
     */
    protected function getDailyStatus(array $personIDs, $dateStart, $dateEnd)
    {
        if (empty($personIDs)) {
            return [];
        }

        $placeholders = [];
        $params = ['dateStart' => $dateStart, 'dateEnd' => $dateEnd];
        foreach (array_values($personIDs) as $index => $personID) {
            $placeholders[] = ':pid' . $index;
            $params['pid' . $index] = $personID;
        }

        $classFilter = $this->countClassAsSchool == 'N'
            ? " AND gibbonAttendanceLogPerson.context <> 'Class'"
            : '';

        // The last log of each day wins; ranking in SQL avoids loading every log
        // row into PHP when a long date range is analysed for the whole school.
        $sql = "SELECT ranked.gibbonPersonID, ranked.date, ranked.type, ranked.reason, ranked.direction,
                       ranked.context, gibbonAttendanceCode.scope
                FROM (
                    SELECT gibbonAttendanceLogPerson.gibbonPersonID, gibbonAttendanceLogPerson.date,
                           gibbonAttendanceLogPerson.type, gibbonAttendanceLogPerson.reason,
                           gibbonAttendanceLogPerson.direction, gibbonAttendanceLogPerson.context,
                           gibbonAttendanceLogPerson.gibbonAttendanceCodeID,
                           ROW_NUMBER() OVER (
                               PARTITION BY gibbonAttendanceLogPerson.gibbonPersonID, gibbonAttendanceLogPerson.date
                               ORDER BY gibbonAttendanceLogPerson.timestampTaken DESC, gibbonAttendanceLogPerson.gibbonAttendanceLogPersonID DESC
                           ) AS rowNumber
                    FROM gibbonAttendanceLogPerson
                    WHERE gibbonAttendanceLogPerson.gibbonPersonID IN (" . implode(',', $placeholders) . ")
                      AND gibbonAttendanceLogPerson.date >= :dateStart AND gibbonAttendanceLogPerson.date <= :dateEnd" . $classFilter . "
                ) ranked
                LEFT JOIN gibbonAttendanceCode ON gibbonAttendanceCode.gibbonAttendanceCodeID = ranked.gibbonAttendanceCodeID
                WHERE ranked.rowNumber = 1";

        $rows = $this->pdo->select($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);

        $daily = [];
        foreach ($rows as $row) {
            $daily[$row['gibbonPersonID']][$row['date']] = $this->classify($row);
        }

        return $daily;
    }

    protected function classify($log)
    {
        $direction = $log['direction'] ?? '';
        $scope = $log['scope'] ?? '';

        if ($direction == 'Out' && $scope == 'Offsite') {
            $status = 'absent';
        } elseif (in_array($scope, ['Onsite - Late', 'Offsite - Late', 'Offsite - Left'], true)) {
            $status = 'partial';
        } else {
            $status = 'present';
        }

        return [
            'status'  => $status,
            'type'    => $log['type'] ?? '',
            'reason'  => $log['reason'] ?? '',
            'scope'   => $scope,
            'context' => $log['context'] ?? '',
        ];
    }

    protected function isSchoolDay($date)
    {
        if (isset($this->closures[$date])) {
            return false;
        }

        return in_array(date('D', strtotime($date)), $this->schoolDayNames, true);
    }

    protected function emptyCounts()
    {
        return ['total' => 0, 'present' => 0, 'partial' => 0, 'late' => 0, 'left' => 0, 'absent' => 0, 'none' => 0];
    }

    protected function addStatus(array &$counts, $status, $record)
    {
        $counts['total']++;

        switch ($status) {
            case 'present':
                $counts['present']++;
                break;
            case 'absent':
                $counts['absent']++;
                break;
            case 'partial':
                $counts['partial']++;
                $scope = $record['scope'] ?? '';
                if ($scope == 'Onsite - Late' || $scope == 'Offsite - Late') $counts['late']++;
                if ($scope == 'Offsite - Left') $counts['left']++;
                break;
            default:
                $counts['none']++;
        }
    }

    protected function addCounts(array &$target, array $counts)
    {
        foreach ($counts as $key => $value) {
            $target[$key] = ($target[$key] ?? 0) + $value;
        }
    }

    protected function rate(array $counts)
    {
        $attended = $counts['present'] + $counts['partial'];
        $denominator = $attended + $counts['absent'];

        return $denominator > 0 ? round(($attended / $denominator) * 100, 1) : 0.0;
    }
}
