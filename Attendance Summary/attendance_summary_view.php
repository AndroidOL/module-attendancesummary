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

// Import necessary classes from the Gibbon framework
use Gibbon\Domain\Timetable\CourseEnrolmentGateway;
use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;

// Include module-specific functions
require_once __DIR__ . '/moduleFunctions.php';

// Add breadcrumb for "View Attendance Information"
$page->breadcrumbs->add(__('View Attendance Information'));

// Check if the user has access to the attendance summary view page
if (!isActionAccessible($guid, $connection2, '/modules/' . 'Attendance Summary' . '/attendance_summary_view.php')) {
    // Access denied - add an error message and (TODO) redirect to an alternate page
    $page->addError(__('TODO: Redirect to alternate page'));
    // TODO: Redirect to alternate page
} else if (!isset($_REQUEST['gibbonPersonID'])) {
    // If the required gibbonPersonID is not set, add an error message and (TODO) redirect back to selection page
    $page->addError(__('TODO: Return to selection page'));
    // TODO: Return to selection page
} else {
    // Retrieve and validate the start and end dates from the request
    list($start_date, $end_date) = getValidatedDates($_REQUEST['start_date'], $_REQUEST['end_date']);
    // Retrieve the person ID from the request
    $gibbonPersonID = $_REQUEST['gibbonPersonID'];

    // Create a form for attendance summary with a GET method; this form submits to the attendance summary view page
    $form = Form::create('gibbonAttendanceSummary', $session->get('absoluteURL').'/index.php?q=/modules/' . $session->get('module') . '/attendance_summary_view.php');
    $form->setFactory(DatabaseFormFactory::create($pdo));
    $form->setTitle(__('View Attendance Information'));  // Form title in English
    // Pass along hidden values for person ID, surname, and preferred name
    $form->addHiddenValue('gibbonPersonID', $gibbonPersonID);
    $form->addHiddenValue('surname', $_REQUEST['surname'] ?? '');
    $form->addHiddenValue('preferredName', $_REQUEST['preferredName'] ?? '');

    // Add a heading row for the time range section of the form
    $form->addRow()->addHeading('Time Range', __('Time Range'));

    // Add a row for displaying the full name (surname, preferred name)
    $row = $form->addRow();
        $row->addLabel('fullname', __('Full Name'));
        $row->addTextField('fullname')
            ->setValue(($_REQUEST['surname'] ?? '') . ', ' . ($_REQUEST['preferredName'] ?? ''))
            ->readonly();

    // Add a row for the start date
    $row = $form->addRow();
        $row->addLabel('start_date', __('Start Date'));
        $row->addDate('start_date')->setValue($start_date);

    // Add a row for the end date
    $row = $form->addRow();
        $row->addLabel('end_date', __('End Date'));
        $row->addDate('end_date')->setValue($end_date);

    // Add a row with a submit button to update the date range
    $row = $form->addRow();
        $row->addSubmit(__('Update Date'));
    
    // Output the form HTML
    echo $form->getOutput();

    // Create a secondary form (for attendance records display) with no action URL as it is used for output only
    $form = Form::create('gibbonAttendanceSummaryInfo', '');
    $form->addRow()->addHeading('Attendance Records', __('Attendance Records'));
    // Get attendance records based on the selected date range and person ID
    $attendanceRecords = getAttendanceRecords($connection2, $start_date, $end_date, $gibbonPersonID);
    // Calculate daily attendance summary based on the attendance records
    $dailyAttendance = calculateDailyAttendance($attendanceRecords);

    // For debugging purposes, the following lines could output the raw attendance records:
    // echo "<pre>";
    // print_r($attendanceRecords);
    // print_r($dailyAttendance);
    // echo "</pre>";
}

// Prepare a mapping for weekdays (translated to English)
// In this case, we keep the English names for display purposes.
$weekMap = [
    'Monday'    => 'Monday',
    'Tuesday'   => 'Tuesday',
    'Wednesday' => 'Wednesday',
    'Thursday'  => 'Thursday',
    'Friday'    => 'Friday',
    'Saturday'  => 'Saturday',
    'Sunday'    => 'Sunday',
];

// Debug output for the request variables (commented out)
// echo "<pre>";
// print_r($_REQUEST);
// echo "</pre>";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Information</title>
    <style>
        /* Styles for the inner embedded table */
        .inner-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .inner-table th, .inner-table td {
            border: 1px solid #ccc;
            padding: 6px;
            text-align: center;
        }
    </style>
    <script type="text/javascript">
        // Function to lighten a given hex color by a specified percentage
        function lightenColor(color, percent) {
            color = color.replace(/^#/, '');
            var num = parseInt(color, 16);
            var amt = Math.round(2.55 * percent);
            var R = (num >> 16) + amt;
            var G = ((num >> 8) & 0x00FF) + amt;
            var B = (num & 0x0000FF) + amt;
            R = (R < 255) ? R : 255;
            G = (G < 255) ? G : 255;
            B = (B < 255) ? B : 255;
            return "#" + ((1 << 24) + (R << 16) + (G << 8) + B).toString(16).slice(1).toUpperCase();
        }
        
        // Function to compute the hover background color based on the original color
        function hoverColor(original) {
            if (original.toUpperCase() === "#FFFFFF") {
                return "#F0F8FF"; // AliceBlue: use light blue when original is white
            } else {
                return lightenColor(original, 10); // Otherwise, lighten the color by 10%
            }
        }
        
        // Function to toggle the display of detailed attendance records for a specific date
        function toggleDetails(dateStr) {
            var row = document.getElementById('details-' + dateStr);
            if (row.style.display === 'none' || row.style.display === '') {
                row.style.display = 'table-row';
            } else {
                row.style.display = 'none';
            }
        }
    </script>
</head>
<body style="font-family: sans-serif; font-size: 18px;">
    <!-- Page title -->
    <h2 style="text-align: center;">Attendance Information</h2>
    <!-- Main table for displaying daily attendance summary -->
    <table style="border-collapse: collapse; width: 100%; margin: auto; margin-top: 20px;">
        <thead>
            <tr style="background-color: #f7f7f7; border: 1px solid #ccc;">
                <!-- Table headers translated to English -->
                <th style="border: 1px solid #ccc; padding: 12px;">Date</th>
                <th style="border: 1px solid #ccc; padding: 12px;">Day</th>
                <th style="border: 1px solid #ccc; padding: 12px;">Standard Attendance</th>
                <th style="border: 1px solid #ccc; padding: 12px;">Daily Attendance</th>
                <th style="border: 1px solid #ccc; padding: 12px;">Attendance Rate</th>
                <th style="border: 1px solid #ccc; padding: 12px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($dailyAttendance as $record): 
                // Retrieve the date string from the record
                $dateStr = $record['date'];
                // Get the English weekday name for the given date
                $weekEn = date('l', strtotime($dateStr));
                // Map the weekday to our desired display value (in this case, same as English)
                $weekDay = isset($weekMap[$weekEn]) ? $weekMap[$weekEn] : $weekEn;
                // Convert the standard attendance and daily attendance values to float
                $standard = floatval($record['标准']);
                $daily = floatval($record['当日考勤']);
                // Calculate the attendance rate (percentage)
                $rate = ($standard > 0) ? round(($daily / $standard) * 100) : 0;
                // Set background color; if no record exists, use a light grey color
                $bgColor = ($standard == 0 && $daily == 0) ? "#E0E0E0" : getBgColor($rate);
            ?>
            <!-- Summary row for a specific date -->
            <tr data-bgcolor="<?php echo $bgColor; ?>" 
                style="background-color: <?php echo $bgColor; ?>; border: 1px solid #ccc;"
                onmouseover="this.style.backgroundColor = hoverColor(this.getAttribute('data-bgcolor'));"
                onmouseout="this.style.backgroundColor = this.getAttribute('data-bgcolor');">
                <td style="border: 1px solid #ccc; padding: 12px;"><?php echo htmlspecialchars($dateStr); ?></td>
                <td style="border: 1px solid #ccc; padding: 12px;"><?php echo htmlspecialchars($weekDay); ?></td>
                <?php if ($standard == 0 && $daily == 0): ?>
                    <!-- If there is no record, span across three columns -->
                    <td colspan="3" style="border: 1px solid #ccc; padding: 12px;">No Record</td>
                <?php else: ?>
                    <!-- Display standard and daily attendance, and the calculated attendance rate -->
                    <td style="border: 1px solid #ccc; padding: 12px;"><?php echo htmlspecialchars($record['标准']); ?></td>
                    <td style="border: 1px solid #ccc; padding: 12px;"><?php echo htmlspecialchars($record['当日考勤']); ?></td>
                    <td style="border: 1px solid #ccc; padding: 12px;"><?php echo $rate . '%'; ?></td>
                <?php endif; ?>
                <!-- Action column with a button to view detailed records -->
                <td style="border: 1px solid #ccc; padding: 12px;">
                    <button type="button" onclick="toggleDetails('<?php echo $dateStr; ?>')">View Details</button>
                </td>
            </tr>
            <!-- Hidden row containing detailed attendance records for the specific date -->
            <tr id="details-<?php echo $dateStr; ?>" style="display: none; background-color: #fafafa;">
                <td colspan="6" style="border: 1px solid #ccc; padding: 12px;">
                    <?php if (isset($attendanceRecords[$dateStr])): ?>
                        <!-- Embedded inner table for detailed attendance records -->
                        <table class="inner-table">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Period</th>
                                    <th>Start Time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($attendanceRecords[$dateStr] as $detail): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($detail['course']); ?></td>
                                    <td><?php echo htmlspecialchars($detail['period']); ?></td>
                                    <td><?php echo htmlspecialchars($detail['time_start']); ?></td>
                                    <td><?php echo htmlspecialchars($detail['attendance']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        No detailed records.
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <!-- Debug output for the $_REQUEST array -->
    <pre>
<?php print_r($_REQUEST); ?>
    </pre>
</body>
</html>
