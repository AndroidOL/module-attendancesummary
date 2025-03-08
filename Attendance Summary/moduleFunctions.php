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
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

// Import required classes from the Gibbon framework
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Domain\User\RoleGateway;
use Gibbon\Domain\DataSet;
use Gibbon\Module\HelpDesk\Data\Setting;
use Gibbon\Module\HelpDesk\Data\SettingManager;
use Psr\Container\ContainerInterface;

/**
 * Splits a comma-separated string, trims each element, and filters out empty ones.
 *
 * @param string $commaSeparatedString The comma-separated string
 * @return array An array of trimmed strings
 */
function explodeTrim($commaSeparatedString) {
    // This function splits the string and trims each element.
    return array_filter(array_map('trim', explode(',', $commaSeparatedString)));
}

/**
 * Retrieves roles from the container.
 *
 * @param ContainerInterface $container The dependency injection container.
 * @return array An associative array of role IDs mapped to their translated names and categories.
 */
function getRoles(ContainerInterface $container) {
    $roleGateway = $container->get(RoleGateway::class);
    $criteria = $roleGateway->newQueryCriteria()
        ->sortBy(['gibbonRole.name']);

    return array_reduce($roleGateway->queryRoles($criteria)->toArray(), function ($group, $role) {
        $group[$role['gibbonRoleID']] = __($role['name']) . ' (' . __($role['category']) . ')';
        return $group; 
    }, []);
}

/**
 * Provides an overview of the statistics from a data set of logs.
 *
 * @param DataSet $logs A DataSet object containing log entries.
 * @return array An array of items with each item containing a 'name' and 'value' (count).
 */
function statsOverview(DataSet $logs) {
    // Count occurrences of each title in the logs.
    $items = array_count_values($logs->getColumn('title'));

    // Sort the items by title.
    ksort($items);

    // Map the array so that each value becomes an array with 'name' and 'value' keys.
    array_walk($items, function (&$value, $key) {
        $value = ['name' => $key, 'value' => $value];
    });

    return $items;
}

/**
 * Formats an expandable section with a title and content.
 *
 * @param string $title The title of the section.
 * @param string $content The content of the section.
 * @return string The formatted HTML for the expandable section.
 */
function formatExpandableSection($title, $content) {
    $output = '';
    $output .= '<h6>' . $title . '</h6></br>';
    $output .= nl2brr($content);
    return $output;
}

/**
 * Calculates a permission value based on given flags.
 *
 * @param string $staff   Default "N". "Y" means staff permission (value 8)
 * @param string $student Default "N". "Y" means student permission (value 4)
 * @param string $parent  Default "N". "Y" means parent permission (value 2)
 * @param string $other   Default "N". "Y" means other permission (value 1)
 * @return int The integer representation of the permission value.
 */
function calcPermission($staff = "N", $student = "N", $parent = "N", $other = "N") {
    // Ensure all inputs are strings; if not, set to "N".
    $staff   = is_string($staff) ? $staff : "N";
    $student = is_string($student) ? $student : "N";
    $parent  = is_string($parent) ? $parent : "N";
    $other   = is_string($other) ? $other : "N";

    // Only treat the value as "Y" if it is exactly "Y"; otherwise, default to "N".
    $staff   = ($staff === "Y") ? "Y" : "N";
    $student = ($student === "Y") ? "Y" : "N";
    $parent  = ($parent === "Y") ? "Y" : "N";
    $other   = ($other === "Y") ? "Y" : "N";

    $value = 0;
    // Map permissions to their binary values:
    // staff -> 8 (binary 1000)
    // student -> 4 (binary 0100)
    // parent -> 2 (binary 0010)
    // other -> 1 (binary 0001)
    if ($staff === "Y") {
        $value |= 8;
    }
    if ($student === "Y") {
        $value |= 4;
    }
    if ($parent === "Y") {
        $value |= 2;
    }
    if ($other === "Y") {
        $value |= 1;
    }
    return $value;
}

/**
 * Converts an integer (0-15) to a 4-character binary string, replacing 1 with "Y" and 0 with "N".
 *
 * @param int $num The number to convert.
 * @return string The converted string (e.g. 15 becomes "YYYY", 0 becomes "NNNN").
 */
function convertIntToYN($num) {
    // If the number is not between 0 and 15, default to 0.
    if ($num < 0 || $num > 15) {
        $num = 0;
    }
    // Convert the number to a binary string and pad it to 4 digits.
    $binary = str_pad(decbin($num), 4, '0', STR_PAD_LEFT);
    // Replace '1' with 'Y' and '0' with 'N'.
    $result = strtr($binary, ['1' => 'Y', '0' => 'N']);
    return $result;
}

/**
 * Gets the value at a specific bit position (1 to 4) from a number (0-15).
 *
 * Bit positions are counted from the right: position 1 is the least significant bit.
 *
 * @param int $num The input number.
 * @param int $position The bit position (1-4).
 * @return int Returns 1 or 0 as a character 'Y' or 'N'.
 */
function getBitAtPosition($num, $position) {
    // Convert the value to integer if it is numeric.
    $value = (is_string($num) && is_numeric($num)) ? intval($num, 10) : (int)$num;
    if ($value < 0 || $value > 15) {
        $value = 0;
    }
    if ($position < 1 || $position > 4) {
        return 0;
    }
    // Shift right (4 - $position) bits and return 'Y' if the bit is 1, otherwise 'N'.
    return (($value >> (4 - $position)) & 1) ? 'Y' : 'N';
}

/**
 * Validates and returns a date range.
 *
 * @param string $start_date_input The start date input from the request.
 * @param string $end_date_input The end date input from the request.
 * @return array An array containing the start and end dates in 'YYYY-MM-DD' format.
 */
function getValidatedDates($start_date_input, $end_date_input) {
    // Define today's date.
    $today = new DateTime();

    // Default values: end date is today; start date is 7 days ago.
    $default_end   = clone $today;
    $default_start = (clone $today)->modify('-7 days');

    // Process start date input.
    if (empty($start_date_input)) {
        $start_dt = clone $default_start;
    } else {
        try {
            $start_dt = new DateTime($start_date_input);
        } catch (Exception $e) {
            $start_dt = clone $default_start;
        }
    }

    // Process end date input.
    if (empty($end_date_input)) {
        $end_dt = clone $default_end;
    } else {
        try {
            $end_dt = new DateTime($end_date_input);
        } catch (Exception $e) {
            $end_dt = clone $default_end;
        }
    }

    // If the start date is later than the end date, swap them.
    if ($start_dt > $end_dt) {
        $temp = $start_dt;
        $start_dt = $end_dt;
        $end_dt = $temp;
    }

    // If the end date is later than today, set it to today.
    if ($end_dt > $today) {
        $end_dt = clone $today;
    }

    // If the start date is later than today, set it to yesterday.
    if ($start_dt > $today) {
        $start_dt = (clone $today)->modify('-1 day');
    }

    // If the end date is earlier than 365 days ago, set it to 365 days ago.
    $past365 = (clone $today)->modify('-365 days');
    if ($end_dt < $past365) {
        $end_dt = clone $past365;
    }

    // If the range is longer than 90 days, adjust the start date to 90 days before the end date.
    $interval = $end_dt->diff($start_dt);
    $daysDiff = (int)$interval->format('%a');
    if ($daysDiff > 90) {
        $start_dt = (clone $end_dt)->modify('-90 days');
    }

    // Return the formatted dates.
    return [
        $start_dt->format('Y-m-d'),
        $end_dt->format('Y-m-d')
    ];
}

/**
 * Retrieves attendance records for a person within a given date range.
 *
 * @param PDO $connection2 The database connection.
 * @param string $start_date The start date in 'YYYY-MM-DD' format.
 * @param string $end_date The end date in 'YYYY-MM-DD' format.
 * @param string $gibbonPersonID The person's ID.
 * @return array An associative array mapping dates to attendance records.
 */
function getAttendanceRecords($connection2, $start_date, $end_date, $gibbonPersonID) {
    // SQL query to retrieve attendance records within the specified date range.
    $sql = "
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
      JOIN gibboncourseclass AS cc 
        ON p.gibbonCourseClassID = cc.gibbonCourseClassID
      JOIN gibboncourse AS c 
        ON cc.gibbonCourseID = c.gibbonCourseID
      JOIN gibbonttdayrowclass AS trc 
        ON cc.gibbonCourseClassID = trc.gibbonCourseClassID
      JOIN gibbonttdaydate AS td 
        ON trc.gibbonTTDayID = td.gibbonTTDayID
      JOIN gibbonttcolumnrow AS colrow 
        ON trc.gibbonTTColumnRowID = colrow.gibbonTTColumnRowID
      JOIN gibbonperson AS per 
        ON p.gibbonPersonID = per.gibbonPersonID
      WHERE p.gibbonPersonID = :personID
        AND per.gibbonRoleIDPrimary = '003'
        AND td.date BETWEEN :startDate AND :endDate
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
             ORDER BY timestampTaken DESC
          ) AS rn
      FROM gibbonattendancelogperson
      WHERE gibbonPersonID = :personID
        AND date BETWEEN :startDate AND :endDate
    )
    SELECT 
        s.mergedCourseName,
        s.gibbonCourseClassID,
        s.gibbonPersonID,
        s.periodName,
        s.timeStart,
        s.course_date,
        COALESCE(a.type, 'No Record') AS attendanceType,
        a.timestampTaken AS latestTimestamp
    FROM schedule AS s
    LEFT JOIN latest_attendance AS a
      ON a.gibbonTTDayRowClassID = s.gibbonTTDayRowClassID
      AND a.gibbonCourseClassID = s.gibbonCourseClassID
      AND a.gibbonPersonID = s.gibbonPersonID
      AND a.date = s.course_date
      AND a.rn = 1
    ORDER BY s.course_date, s.timeStart, s.gibbonCourseClassID, s.gibbonPersonID;
    ";

    // Prepare and execute the SQL statement.
    $stmt = $connection2->prepare($sql);
    $stmt->execute([
        'startDate' => $start_date,
        'endDate'   => $end_date,
        'personID'  => $gibbonPersonID
    ]);

    // Fetch all records as an associative array.
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organize the data in the format: {date => [attendance records]}
    $attendanceData = [];
    foreach ($records as $record) {
        $date = $record['course_date'];
        if (!isset($attendanceData[$date])) {
            $attendanceData[$date] = [];
        }
        $attendanceData[$date][] = [
            'course'     => $record['mergedCourseName'],
            'period'     => $record['periodName'],
            'courseID'   => $record['gibbonCourseClassID'],
            'time_start' => $record['timeStart'],
            'attendance' => $record['attendanceType'],
            'timestamp'  => $record['latestTimestamp'],
        ];
    }

    return $attendanceData;
}

/**
 * Returns a score for an attendance record based on keyword matching.
 *
 * @param string $attendanceStr The attendance record string, e.g., "Absent - Sick Leave"
 * @param array $config An associative array mapping keywords to scores.
 * @return float Returns the smallest matched score or 1.0 if no match is found.
 */
function getAttendanceScore($attendanceStr, $config) {
    $matchedScores = [];
    foreach ($config as $keyword => $score) {
        // If the attendance string contains the keyword...
        if (strpos($attendanceStr, $keyword) !== false) {
            $matchedScores[] = $score;
        }
    }
    // Return a default score of 1.0 if no keywords are matched.
    if (empty($matchedScores)) {
        return 1.0;
    }
    // If multiple matches occur, return the lowest (most severe) score.
    return min($matchedScores);
}

/**
 * Calculates daily attendance scores.
 *
 * @param array $attendanceData The attendance data, structured as:
 *   [
 *     '2025-03-03' => [
 *         [ 'course'=>..., 'period'=>..., 'time_start'=>..., 'attendance'=>..., 'timestamp'=>... ],
 *         ...
 *     ],
 *     '2025-03-04' => [ ... ],
 *     ...
 *   ]
 * @param array|null $config An optional configuration mapping attendance types to scores. Default:
 *   [
 *     'Absent'     => 0.0,
 *     'Personal Leave' => 0.0,
 *     'Suspended'  => 0.0,
 *     'Late'       => 0.7,
 *     'Early Leave'=> 0.7,
 *     'Sick Leave' => 0.5,
 *     'Official Leave' => 1.0,
 *     'Present'    => 1.0,
 *     'No Record'  => 1.0,
 *   ]
 * @return array An array in the format:
 *   [
 *      [ 'date' => '2025-03-03', 'Expected' => '9.0', 'Actual' => '4.7' ],
 *      [ 'date' => '2025-03-04', 'Expected' => '9.0', 'Actual' => '8.5' ],
 *      ...
 *   ]
 */
function calculateDailyAttendance($attendanceData, $config = null) {
    // Default configuration
    $defaultConfig = [
        'Absent'           => 0.0,
        'Personal Leave'   => 0.0,
        'Suspended'        => 0.0,
        'Late'             => 0.7,
        'Early Leave'      => 0.7,
        'Sick Leave'       => 0.5,
        'Official Leave'   => 1.0,
        'Present'          => 1.0,
        'No Record'        => 1.0,
    ];
    
    // Use the provided configuration or fall back to default
    if ($config === null) {
        $config = $defaultConfig;
    }
    
    $result = [];
    
    // Loop through each date's attendance records
    foreach ($attendanceData as $date => $records) {
        // Check if all records for the day are "No Record"
        $allNoRecord = true;
        foreach ($records as $record) {
            if (trim($record['attendance']) !== 'No Record') {
                $allNoRecord = false;
                break;
            }
        }
        
        if ($allNoRecord) {
            // If all records are "No Record", set expected and actual scores to 0.
            $result[] = [
                'date'     => $date,
                'Expected' => '0.0',
                'Actual'   => '0.0'
            ];
            continue;
        }
        
        // Expected score: if all classes are attended, equals the number of classes.
        $standard = count($records) * 1.0;
        $attendanceTotal = 0.0;
        
        foreach ($records as $record) {
            $attendanceStr = $record['attendance'];
            // Get the score for this record based on keyword matching.
            $score = getAttendanceScore($attendanceStr, $config);
            $attendanceTotal += $score;
        }
        
        // Format the result to one decimal place.
        $result[] = [
            'date'     => $date,
            'Expected' => number_format($standard, 1),
            'Actual'   => number_format($attendanceTotal, 1)
        ];
    }
    
    return $result;
}

/**
 * Filters the form data to retrieve entries starting with "transfer-" that are set to "on",
 * and returns a new array with each element containing gibbonPersonID, gibbonCourseClassID, and gibbonTTDayRowClassID.
 *
 * @param array $data The submitted form data.
 * @return array The formatted array of transfer data.
 */
function getTransferData(array $data) {
    $result = [];
    // Get the global gibbonPersonID and gibbonCourseClassID from the form data.
    $personID = isset($data['gibbonPersonID']) ? $data['gibbonPersonID'] : null;
    $courseClassID = isset($data['gibbonCourseClassID']) ? $data['gibbonCourseClassID'] : null;
    
    // Loop through all data items.
    foreach ($data as $key => $value) {
        // Check if the key starts with "transfer-" and the value is "on"
        if (strpos($key, 'transfer-') === 0 && $value === 'on') {
            // Extract the part after "transfer-" as the gibbonTTDayRowClassID
            $transferId = substr($key, strlen('transfer-'));
            $result[] = [
                'gibbonPersonID'        => $personID,
                'gibbonCourseClassID'   => $courseClassID,
                'gibbonTTDayRowClassID' => $transferId
            ];
        }
    }
    
    return $result;
}

/**
 * Generates a new transfer data array based on the submitted data and SQL query results.
 *
 * @param array $submittedData The submitted data array, e.g.:
 * [
 *     [
 *         'gibbonPersonID' => '0000000068',
 *         'gibbonCourseClassID' => '00000185',
 *         'gibbonTTDayRowClassID' => '000000000341'
 *     ],
 *     ...
 * ]
 *
 * @param array $sqlData The SQL query results array, e.g.:
 * [
 *     [
 *         'gibbonTTDayRowClassID' => '000000000184',
 *         'gibbonCourseClassID' => '00000175',
 *         'courseName' => 'Japanese Intermediate II - G9',
 *         'dayName' => 'Monday Scheduling',
 *         'gibbonTTColumnRowID' => '00000001',
 *         'periodName' => 'First Period',
 *         'timeStart' => '08:15:00',
 *         'timeEnd' => '09:00:00'
 *     ],
 *     ...
 * ]
 *
 * @return array Returns a new array structured like:
 * [
 *     [
 *         'gibbonPersonID' => '0000000068',
 *         'gibbonCourseClassID_OLD' => '00000185',
 *         'gibbonCourseClassName_OLD' => 'Japanese Beginner I - G9',
 *         'gibbonTTDayRowClassID_OLD' => '000000000341',
 *         'gibbonTTDayRowClassName_OLD' => 'First Period',
 *         'gibbonTTDayName' => 'Monday Scheduling',
 *         'gibbonTTColumnRowID' => '00000001',
 *         'transferableList' => [
 *             [
 *                 'gibbonCourseClassID_NEW' => '00000175',
 *                 'gibbonCourseClassName_NEW' => 'Japanese Intermediate II - G9',
 *                 'gibbonTTDayRowClassID_NEW' => '000000000184',
 *                 'gibbonTTDayRowClassName_NEW' => 'First Period'
 *             ],
 *             ...
 *         ]
 *     ],
 *     ...
 * ]
 */
function generateTransferData($submittedData, $sqlData) {
    $result = array();
    
    // Loop through each submitted record.
    foreach ($submittedData as $submitted) {
        $oldRowID = $submitted['gibbonTTDayRowClassID'];
        $oldCourseClassID = $submitted['gibbonCourseClassID'];
        $gibbonPersonID = $submitted['gibbonPersonID'];
        
        // Find the matching old record in the SQL data based on gibbonTTDayRowClassID.
        $oldRecord = null;
        foreach ($sqlData as $row) {
            if ($row['gibbonTTDayRowClassID'] == $oldRowID) {
                $oldRecord = $row;
                break;
            }
        }
        
        // If no matching record is found, skip this submission.
        if (!$oldRecord) {
            continue;
        }
        
        // Extract relevant information from the old record.
        $oldCourseClassName = $oldRecord['courseName'];
        $oldPeriodName = $oldRecord['periodName'];
        $ttColumnRowID = $oldRecord['gibbonTTColumnRowID'];
        $ttDayName = $oldRecord['dayName'];
        
        // Look for other transferable records with the same gibbonTTColumnRowID, excluding the current course.
        $transferableList = array();
        foreach ($sqlData as $row) {
            if ($row['gibbonTTColumnRowID'] == $ttColumnRowID && $row['gibbonCourseClassID'] != $oldCourseClassID) {
                $transferableList[] = array(
                    'gibbonCourseClassID_NEW'    => $row['gibbonCourseClassID'],
                    'gibbonCourseClassName_NEW'  => $row['courseName'],
                    'gibbonTTDayRowClassID_NEW'  => $row['gibbonTTDayRowClassID'],
                    'gibbonTTDayRowClassName_NEW'=> $row['periodName']
                    // Additional fields such as dayName, timeStart, timeEnd can be added if needed.
                );
            }
        }
        
        // Create a new record for this submission.
        $newRecord = array(
            'gibbonPersonID'              => $gibbonPersonID,
            'gibbonCourseClassID_OLD'     => $oldCourseClassID,
            'gibbonCourseClassName_OLD'   => $oldCourseClassName,
            'gibbonTTDayRowClassID_OLD'   => $oldRowID,
            'gibbonTTDayRowClassName_OLD' => $oldPeriodName,
            'gibbonTTDayName'             => $ttDayName,
            'gibbonTTColumnRowID'         => $ttColumnRowID,
            'transferableList'            => $transferableList
        );
        
        $result[] = $newRecord;
    }
    
    return $result;
}

/**
 * Removes alphanumeric characters (letters and digits) from a string.
 *
 * @param string $input The original string.
 * @return string The processed string with letters and numbers removed.
 */
function removeAlphaNumeric($input) {
    return preg_replace('/[A-Za-z0-9]/', '', $input);
}

/**
 * Returns a background color based on the attendance rate.
 *
 * - Attendance rate < 40%: light red.
 * - 40% ≤ Attendance rate < 60%: light orange.
 * - 60% ≤ Attendance rate < 80%: light yellow.
 * - Attendance rate ≥ 80%: white.
 *
 * @param float $rate The attendance rate percentage.
 * @return string The corresponding background color in hexadecimal.
 */
function getBgColor($rate) {
    if ($rate < 40) {
        return "#FFCCCC"; // light red
    } elseif ($rate < 60) {
        return "#FFE6CC"; // light orange
    } elseif ($rate < 80) {
        return "#FFFFCC"; // light yellow
    } elseif ($rate < 99) {
        return "#FFFFF0"; // off-white
    } else {
        return "#FFFFFF"; // white
    }
}
?>
