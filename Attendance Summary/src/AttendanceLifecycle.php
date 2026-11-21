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
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

namespace Gibbon\Module\Attendance;

use Gibbon\Contracts\Database\Connection;

/**
 * Attendance Lifecycle
 *
 * Builds a student's whole-enrolment attendance picture (enrolment history,
 * per-term/weekly attendance, absence / late / left detail), using the
 * "end of day" attendance model: the last log recorded for a date wins.
 *
 * @version v1
 * @since   v1
 */
class AttendanceLifecycle
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
     * Options for the report scope selector: all / a school year / a term.
     *
     * @return array  [value => label]
     */
    public function getScopeOptions()
    {
        $this->loadCalendar();

        $options = ['all' => attendanceT('All (Since Enrolment)')];

        foreach ($this->schoolYears as $yearID => $year) {
            $options['year:' . $yearID] = sprintf(attendanceT('%s School Year'), $year['name']);
        }

        foreach ($this->terms as $term) {
            $options['term:' . $term['gibbonSchoolYearTermID']] = $term['schoolYearName'] . ' · ' . $term['name'];
        }

        return $options;
    }

    /**
     * @param string $gibbonPersonID
     * @return array|false
     */
    public function getProfile($gibbonPersonID)
    {
        return $this->pdo->select("SELECT * FROM gibbonPerson WHERE gibbonPersonID=:gibbonPersonID", [
            'gibbonPersonID' => $gibbonPersonID,
        ])->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * A student's enrolment (year group + form group), one row per school year.
     *
     * @param string $gibbonPersonID
     * @return array
     */
    public function getEnrolments($gibbonPersonID)
    {
        $this->loadCalendar();

        $sql = "SELECT gibbonStudentEnrolment.gibbonSchoolYearID,
                       gibbonSchoolYear.name AS schoolYearName,
                       gibbonSchoolYear.firstDay AS schoolYearFirstDay, gibbonSchoolYear.lastDay AS schoolYearLastDay,
                       gibbonPerson.dateStart AS personStart, gibbonPerson.dateEnd AS personEnd,
                       gibbonYearGroup.name AS yearGroupName, gibbonYearGroup.nameShort AS yearGroup,
                       gibbonFormGroup.name AS formGroupName, gibbonFormGroup.nameShort AS formGroup
                FROM gibbonStudentEnrolment
                JOIN gibbonSchoolYear ON gibbonSchoolYear.gibbonSchoolYearID = gibbonStudentEnrolment.gibbonSchoolYearID
                JOIN gibbonYearGroup ON gibbonYearGroup.gibbonYearGroupID = gibbonStudentEnrolment.gibbonYearGroupID
                JOIN gibbonFormGroup ON gibbonFormGroup.gibbonFormGroupID = gibbonStudentEnrolment.gibbonFormGroupID
                JOIN gibbonPerson ON gibbonPerson.gibbonPersonID = gibbonStudentEnrolment.gibbonPersonID
                WHERE gibbonStudentEnrolment.gibbonPersonID=:gibbonPersonID
                ORDER BY gibbonSchoolYear.sequenceNumber";

        $enrolments = $this->pdo->select($sql, ['gibbonPersonID' => $gibbonPersonID])->fetchAll(\PDO::FETCH_ASSOC);

        // The study period is the span of that year's terms (person dateStart is often
        // left blank by staff, so it is only used to narrow the range when it is set).
        foreach ($enrolments as &$enrolment) {
            $start = null;
            $end = null;
            foreach ($this->termsByYear[$enrolment['gibbonSchoolYearID']] ?? [] as $term) {
                if ($start === null || $term['firstDay'] < $start) $start = $term['firstDay'];
                if ($end === null || $term['lastDay'] > $end) $end = $term['lastDay'];
            }

            // A year with no terms yet falls back to the school year dates.
            if ($start === null) $start = $enrolment['schoolYearFirstDay'];
            if ($end === null) $end = $enrolment['schoolYearLastDay'];

            if (!empty($enrolment['personStart']) && $enrolment['personStart'] > $start) $start = $enrolment['personStart'];
            if (!empty($enrolment['personEnd']) && $enrolment['personEnd'] < $end) $end = $enrolment['personEnd'];

            $enrolment['enrolStart'] = $start;
            $enrolment['enrolEnd'] = $end;
        }
        unset($enrolment);

        return $enrolments;
    }

    /**
     * End-of-day attendance status for every date the student has a log.
     *
     * @param string $gibbonPersonID
     * @return array  [date => ['status','type','reason','scope','context']]
     */
    public function getDailyStatus($gibbonPersonID)
    {
        $classFilter = $this->countClassAsSchool == 'N'
            ? " AND gibbonAttendanceLogPerson.context <> 'Class'"
            : '';

        $sql = "SELECT ranked.date, ranked.type, ranked.reason, ranked.direction, ranked.context,
                       gibbonAttendanceCode.scope
                FROM (
                    SELECT gibbonAttendanceLogPerson.date, gibbonAttendanceLogPerson.type,
                           gibbonAttendanceLogPerson.reason, gibbonAttendanceLogPerson.direction,
                           gibbonAttendanceLogPerson.context, gibbonAttendanceLogPerson.gibbonAttendanceCodeID,
                           ROW_NUMBER() OVER (
                               PARTITION BY gibbonAttendanceLogPerson.date
                               ORDER BY gibbonAttendanceLogPerson.timestampTaken DESC, gibbonAttendanceLogPerson.gibbonAttendanceLogPersonID DESC
                           ) AS rowNumber
                    FROM gibbonAttendanceLogPerson
                    WHERE gibbonAttendanceLogPerson.gibbonPersonID=:gibbonPersonID" . $classFilter . "
                ) ranked
                LEFT JOIN gibbonAttendanceCode ON gibbonAttendanceCode.gibbonAttendanceCodeID=ranked.gibbonAttendanceCodeID
                WHERE ranked.rowNumber = 1
                ORDER BY ranked.date";

        $rows = $this->pdo->select($sql, ['gibbonPersonID' => $gibbonPersonID])->fetchAll(\PDO::FETCH_ASSOC);

        $daily = [];
        foreach ($rows as $row) {
            $daily[$row['date']] = $this->classify($row);
        }

        return $daily;
    }

    /**
     * Build the full report.
     *
     * @param string $gibbonPersonID
     * @param string $scope  'all' | 'year:<gibbonSchoolYearID>' | 'term:<gibbonSchoolYearTermID>'
     * @return array
     */
    public function build($gibbonPersonID, $scope = 'all')
    {
        $this->loadCalendar();

        $profile = $this->getProfile($gibbonPersonID);
        $enrolments = $this->getEnrolments($gibbonPersonID);

        $enrolmentByYear = [];
        foreach ($enrolments as $enrolment) {
            $enrolmentByYear[$enrolment['gibbonSchoolYearID']] = $enrolment;
        }

        $parts = array_pad(explode(':', $scope, 2), 2, '');
        $scopeType = $parts[0];
        $scopeID = $parts[1];

        // Which terms belong in the report.
        if ($scopeType == 'term' && !empty($scopeID)) {
            $terms = array_values(array_filter($this->terms, function ($term) use ($scopeID) {
                return $term['gibbonSchoolYearTermID'] == $scopeID;
            }));
        } elseif ($scopeType == 'year' && !empty($scopeID)) {
            $terms = $this->termsByYear[$scopeID] ?? [];
        } else {
            $terms = [];
            foreach ($this->resolveReportYears($enrolments, $profile) as $yearID) {
                foreach ($this->termsByYear[$yearID] ?? [] as $term) {
                    $terms[] = $term;
                }
            }
        }

        $daily = $this->getDailyStatus($gibbonPersonID);
        $personStart = $profile['dateStart'] ?? null;
        $personEnd = $profile['dateEnd'] ?? null;
        $today = date('Y-m-d');

        $years = [];
        $totals = $this->emptyCounts();
        $claimed = [];
        $detail = ['absent' => [], 'late' => [], 'left' => [], 'absentTypes' => []];

        foreach ($terms as $term) {
            $days = [];
            $cursor = new \DateTime($term['firstDay']);
            $termEnd = new \DateTime($term['lastDay']);

            for (; $cursor <= $termEnd; $cursor->modify('+1 day')) {
                $date = $cursor->format('Y-m-d');

                // Days that have not happened yet must never count towards attendance.
                if ($date > $today) break;
                if (isset($claimed[$date])) continue;
                if (!$this->isSchoolDay($date)) continue;
                if (!empty($personStart) && $date < $personStart) continue;
                if (!empty($personEnd) && $date > $personEnd) continue;

                $record = $daily[$date] ?? null;
                $days[] = [
                    'date'    => $date,
                    'weekday' => $cursor->format('D'),
                    'status'  => $record['status'] ?? 'none',
                    'type'    => $record['type'] ?? '',
                    'reason'  => $record['reason'] ?? '',
                    'scope'   => $record['scope'] ?? '',
                    'context' => $record['context'] ?? '',
                ];
            }

            $counts = $this->countDays($days);

            // Skip terms that have no attendance records at all (nothing but "no record" days).
            // Dates are only claimed by accepted terms, so a skipped term never blocks an
            // overlapping term that does have records.
            if ($counts['present'] + $counts['partial'] + $counts['absent'] === 0) {
                continue;
            }

            $yearID = $term['gibbonSchoolYearID'];
            if (!isset($years[$yearID])) {
                $years[$yearID] = [
                    'gibbonSchoolYearID' => $yearID,
                    'schoolYearName'     => $term['schoolYearName'],
                    'enrolment'          => $enrolmentByYear[$yearID] ?? null,
                    'terms'              => [],
                    'counts'             => $this->emptyCounts(),
                ];
            }

            foreach ($days as $day) {
                $claimed[$day['date']] = true;
            }

            // Collect detail lists.
            foreach ($days as $day) {
                if ($day['status'] == 'absent') {
                    $detail['absent'][] = $day + ['termName' => $term['name'], 'schoolYearName' => $term['schoolYearName']];
                    $typeKey = $day['type'] !== '' ? $day['type'] : attendanceT('No record');
                    $detail['absentTypes'][$typeKey] = ($detail['absentTypes'][$typeKey] ?? 0) + 1;
                }
                if ($day['scope'] == 'Onsite - Late' || $day['scope'] == 'Offsite - Late') {
                    $detail['late'][] = $day + ['termName' => $term['name'], 'schoolYearName' => $term['schoolYearName']];
                }
                if ($day['scope'] == 'Offsite - Left') {
                    $detail['left'][] = $day + ['termName' => $term['name'], 'schoolYearName' => $term['schoolYearName']];
                }
            }

            $years[$yearID]['terms'][] = [
                'gibbonSchoolYearTermID' => $term['gibbonSchoolYearTermID'],
                'name'                   => $term['name'],
                'firstDay'               => $term['firstDay'],
                'lastDay'                => $term['lastDay'],
                'counts'                 => $counts,
                'rate'                   => $this->rate($counts),
                'days'                   => $days,
            ];
            $this->addCounts($years[$yearID]['counts'], $counts);
            $this->addCounts($totals, $counts);
        }

        foreach ($years as $yearID => $year) {
            $years[$yearID]['rate'] = $this->rate($year['counts']);
        }

        arsort($detail['absentTypes']);

        return [
            'profile'     => $profile,
            'enrolments'  => $enrolments,
            'scope'       => $scope,
            'years'       => array_values($years),
            'totals'      => $totals,
            'totalRate'   => $this->rate($totals),
            'detail'      => $detail,
        ];
    }

    /**
     * School years to include for the "all" scope.
     *
     * @return array  Ordered school year IDs.
     */
    protected function resolveReportYears(array $enrolments, $profile)
    {
        $yearIDs = [];
        foreach ($enrolments as $enrolment) {
            $yearIDs[] = $enrolment['gibbonSchoolYearID'];
        }
        if (!empty($yearIDs)) {
            return $yearIDs;
        }

        // No enrolment on record: fall back to years overlapping the person's window.
        $start = $profile['dateStart'] ?? null;
        $end = $profile['dateEnd'] ?? null;
        foreach ($this->schoolYears as $yearID => $year) {
            if (!empty($start) && $year['lastDay'] < $start) continue;
            if (!empty($end) && $year['firstDay'] > $end) continue;
            $yearIDs[] = $yearID;
        }

        return $yearIDs;
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

    protected function countDays(array $days)
    {
        $counts = $this->emptyCounts();

        foreach ($days as $day) {
            $counts['total']++;

            switch ($day['status']) {
                case 'present':
                    $counts['present']++;
                    break;
                case 'absent':
                    $counts['absent']++;
                    break;
                case 'partial':
                    $counts['partial']++;
                    if ($day['scope'] == 'Onsite - Late' || $day['scope'] == 'Offsite - Late') $counts['late']++;
                    if ($day['scope'] == 'Offsite - Left') $counts['left']++;
                    break;
                default:
                    $counts['none']++;
            }
        }

        return $counts;
    }

    protected function addCounts(array &$target, array $counts)
    {
        foreach ($counts as $key => $value) {
            $target[$key] += $value;
        }
    }

    protected function rate(array $counts)
    {
        $attended = $counts['present'] + $counts['partial'];
        $denominator = $attended + $counts['absent'];

        return $denominator > 0 ? round(($attended / $denominator) * 100, 1) : 0.0;
    }
}
