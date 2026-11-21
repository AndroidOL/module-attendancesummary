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

/**
 * Attendance Lifecycle (by course)
 *
 * The whole-enrolment attendance picture grouped BY COURSE rather than by day:
 * attendance is taken per timetabled lesson, so this is the view that matches how
 * the school actually records it. Aggregates lesson counts + weighted score
 * (see moduleFunctions getAttendanceScoreConfig) per course, per school year.
 *
 * @version v1
 * @since   v1
 */
class AttendanceLifecycleCourse extends AttendanceLifecycle
{
    /** @var array  Keyword => score map (from getAttendanceScoreConfig()). */
    protected $scoreConfig = [];

    /**
     * @param array $config  Keyword => score map; mirrors getAttendanceScoreConfig().
     */
    public function setScoreConfig(array $config)
    {
        $this->scoreConfig = $config;

        return $this;
    }

    /**
     * Build the per-course report.
     *
     * @param string $gibbonPersonID
     * @param string $scope  'all' | 'year:<id>' | 'term:<id>'
     * @return array
     */
    public function buildCourse($gibbonPersonID, $scope = 'all')
    {
        $this->loadCalendar();

        $profile = $this->getProfile($gibbonPersonID);
        $enrolments = $this->getEnrolments($gibbonPersonID);

        list($scopeType, $scopeID) = array_pad(explode(':', $scope, 2), 2, '');

        // Which terms belong in the report (same rules as the day-based report).
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

        $result = [
            'profile'    => $profile,
            'enrolments' => $enrolments,
            'scope'      => $scope,
            'dateStart'  => null,
            'dateEnd'    => null,
            'years'      => [],
            'totals'     => $this->emptyCourseTotals(),
            'totalRate'  => 0.0,
            'types'      => [],
            'issues'     => [],
        ];

        if (empty($terms)) {
            return $result;
        }

        $today = date('Y-m-d');
        $start = null;
        $end = null;
        foreach ($terms as $term) {
            if ($start === null || $term['firstDay'] < $start) $start = $term['firstDay'];
            if ($end === null || $term['lastDay'] > $end) $end = $term['lastDay'];
        }
        if ($end > $today) $end = $today;

        if (empty($start) || $start > $end) {
            return $result;
        }

        $result['dateStart'] = $start;
        $result['dateEnd'] = $end;

        $enrolmentByYear = [];
        foreach ($enrolments as $enrolment) {
            $enrolmentByYear[$enrolment['gibbonSchoolYearID']] = $enrolment;
        }

        $lessons = $this->getLessonRecords($gibbonPersonID, $start, $end);
        $years = [];
        $yearByDate = [];

        foreach ($lessons as $lesson) {
            $date = $lesson['course_date'];

            // Group by the school year the lesson date falls in (cached per date;
            // timetables repeat the same dates many times).
            if (!isset($yearByDate[$date])) {
                $yearByDate[$date] = '';
                foreach ($this->schoolYears as $schoolYearID => $schoolYear) {
                    if ($date >= $schoolYear['firstDay'] && $date <= $schoolYear['lastDay']) {
                        $yearByDate[$date] = $schoolYearID;
                        break;
                    }
                }
            }
            $yearID = $yearByDate[$date];

            if (!isset($years[$yearID])) {
                $years[$yearID] = [
                    'gibbonSchoolYearID' => $yearID,
                    'schoolYearName'     => $this->schoolYears[$yearID]['name'] ?? attendanceT('Unknown School Year'),
                    'enrolment'          => $enrolmentByYear[$yearID] ?? null,
                    'courses'            => [],
                    'totals'             => $this->emptyCourseTotals(),
                    'rate'               => 0.0,
                ];
            }

            $courseClassID = $lesson['gibbonCourseClassID'];
            if (!isset($years[$yearID]['courses'][$courseClassID])) {
                $years[$yearID]['courses'][$courseClassID] = [
                    'name'       => $lesson['mergedCourseName'],
                    'courseName' => $lesson['courseName'],
                    'className'  => $lesson['className'],
                    'lessons'    => 0,
                    'recorded'   => 0,
                    'standard'   => 0.0,
                    'attendance' => 0.0,
                    'none'       => 0,
                ];
            }

            $type = $lesson['attendanceType'];
            $course = &$years[$yearID]['courses'][$courseClassID];
            $course['lessons']++;

            $result['types'][$type] = ($result['types'][$type] ?? 0) + 1;

            if (attendanceIsNoRecord($type)) {
                // A lesson with no log is not counted (mirrors the day model, where a
                // day with no records at all is dropped rather than scored as present).
                $course['none']++;
            } else {
                $score = $this->score($type);
                $course['recorded']++;
                $course['standard'] += 1.0;
                $course['attendance'] += $score;

                if ($score < 1.0) {
                    $result['issues'][] = [
                        'date'           => $date,
                        'course'         => $lesson['mergedCourseName'],
                        'period'         => $lesson['periodName'],
                        'type'           => $type,
                        'schoolYearName' => $this->schoolYears[$yearID]['name'] ?? '',
                    ];
                }
            }
            unset($course);
        }

        foreach ($years as $yearID => $year) {
            $courses = [];
            $totals = $this->emptyCourseTotals();

            foreach ($year['courses'] as $course) {
                // A course with no recorded lesson at all carries no information.
                if ($course['recorded'] === 0) {
                    continue;
                }

                $course['rate'] = $this->courseRate($course);
                $courses[] = $course;

                $totals['lessons'] += $course['lessons'];
                $totals['recorded'] += $course['recorded'];
                $totals['standard'] += $course['standard'];
                $totals['attendance'] += $course['attendance'];
                $totals['none'] += $course['none'];
            }

            usort($courses, function ($a, $b) {
                return $a['rate'] <=> $b['rate'];
            });

            $years[$yearID]['courses'] = $courses;
            $years[$yearID]['totals'] = $totals;
            $years[$yearID]['rate'] = $this->courseRate($totals);
        }

        // Keep only years that still have courses, in date order.
        $keptYears = [];
        foreach ($years as $yearID => $year) {
            if (empty($year['courses'])) {
                continue;
            }

            foreach (['lessons', 'recorded', 'standard', 'attendance', 'none'] as $key) {
                $result['totals'][$key] += $year['totals'][$key];
            }

            $keptYears[] = $year;
        }

        $result['totalRate'] = $this->courseRate($result['totals']);
        $result['years'] = $keptYears;

        arsort($result['types']);

        return $result;
    }

    /**
     * Every timetabled lesson the student had in the window, with its attendance type.
     *
     * NOTE: `countClassAsSchool` is deliberately NOT applied here — per-lesson
     * attendance IS class-context, so filtering on `context <> 'Class'` would empty
     * this report (see Project Memory → design decision).
     *
     * @return array
     */
    protected function getLessonRecords($gibbonPersonID, $dateStart, $dateEnd)
    {
        $sql = "WITH latest_attendance AS (
                    SELECT gibbonTTDayRowClassID, gibbonCourseClassID, gibbonPersonID, date, type, timestampTaken,
                           ROW_NUMBER() OVER (
                               PARTITION BY gibbonTTDayRowClassID, gibbonCourseClassID, gibbonPersonID, date
                               ORDER BY timestampTaken DESC, gibbonAttendanceLogPersonID DESC
                           ) AS rn
                    FROM gibbonattendancelogperson
                    WHERE gibbonPersonID = :logPersonID
                      AND date BETWEEN :logDateStart AND :logDateEnd
                )
                SELECT cc.gibbonCourseClassID,
                       CONCAT(c.nameShort, '-', cc.nameShort) AS mergedCourseName,
                       cc.name AS className,
                       c.name AS courseName,
                       td.date AS course_date,
                       colrow.name AS periodName,
                       colrow.timeStart,
                       COALESCE(a.type, '__NO_RECORD__') AS attendanceType
                FROM gibboncourseclassperson AS p
                JOIN gibboncourseclass AS cc ON p.gibbonCourseClassID = cc.gibbonCourseClassID
                JOIN gibboncourse AS c ON cc.gibbonCourseID = c.gibbonCourseID
                JOIN gibbonttdayrowclass AS trc ON cc.gibbonCourseClassID = trc.gibbonCourseClassID
                JOIN gibbonttdaydate AS td ON trc.gibbonTTDayID = td.gibbonTTDayID
                JOIN gibbonttcolumnrow AS colrow ON trc.gibbonTTColumnRowID = colrow.gibbonTTColumnRowID
                JOIN gibbonperson AS per ON p.gibbonPersonID = per.gibbonPersonID
                LEFT JOIN latest_attendance AS a
                    ON a.gibbonTTDayRowClassID = trc.gibbonTTDayRowClassID
                    AND a.gibbonCourseClassID = cc.gibbonCourseClassID
                    AND a.gibbonPersonID = p.gibbonPersonID
                    AND a.date = td.date
                    AND a.rn = 1
                WHERE p.gibbonPersonID = :schedPersonID
                  AND per.gibbonRoleIDPrimary = '003'
                  AND td.date BETWEEN :schedDateStart AND :schedDateEnd
                ORDER BY td.date, colrow.timeStart, cc.gibbonCourseClassID";

        return $this->pdo->select($sql, [
            'logPersonID'    => $gibbonPersonID,
            'logDateStart'   => $dateStart,
            'logDateEnd'     => $dateEnd,
            'schedPersonID'  => $gibbonPersonID,
            'schedDateStart' => $dateStart,
            'schedDateEnd'   => $dateEnd,
        ])->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Keyword => score resolution; mirrors moduleFunctions getAttendanceScore().
     *
     * @param string $type
     * @return float
     */
    protected function score($type)
    {
        $matched = [];
        foreach ($this->scoreConfig as $keyword => $score) {
            if (strpos($type, $keyword) !== false) {
                $matched[] = $score;
            }
        }

        return empty($matched) ? 1.0 : min($matched);
    }

    protected function emptyCourseTotals()
    {
        return ['lessons' => 0, 'recorded' => 0, 'standard' => 0.0, 'attendance' => 0.0, 'none' => 0];
    }

    protected function courseRate(array $totals)
    {
        return $totals['standard'] > 0 ? round(($totals['attendance'] / $totals['standard']) * 100, 1) : 0.0;
    }
}
