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

// Import necessary classes from the Gibbon framework
use Gibbon\Domain\Timetable\CourseEnrolmentGateway;
use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;

// Include module-specific helper functions
require_once __DIR__ . '/moduleFunctions.php';

// Add breadcrumb for "Confirm Transfer of Attendance Records"
$page->breadcrumbs->add(__('Confirm Transfer of Attendance Records'));

// Retrieve transfer data from the request parameters using a helper function
$transferData = getTransferData($_REQUEST);

// Check if the user has permission to access the attendance record transfer page
if (!isActionAccessible($guid, $connection2, '/modules/' . 'Attendance Summary' . '/attendance_record_transfer.php')) {
    // Access denied: display an error message
    $page->addError(__('You do not have permission to access this page.'));
} else if (count($transferData) < 1) {
    // If no transfer data was selected, display an error message
    $page->addError(__('Please select the corresponding course(s)!'));
} else {
    // Create a blank form for course enrolment synchronization editing
    $form = Form::createBlank('courseEnrolmentSyncEdit', $session->get('absoluteURL').'/modules/'.$session->get('module').'/attendance_record_transferEditProcess.php');
    $form->setFactory(DatabaseFormFactory::create($pdo));

    // Prepare the data array for binding in the SQL query
    $data = array('gibbonCourseClassID' => $_REQUEST['gibbonCourseClassID']);
    
    // SQL query to retrieve distinct scheduling information
    $sql = "SELECT DISTINCT
                tdc.gibbonTTDayRowClassID,                     -- Timetable scheduling ID
                ccs.gibbonCourseClassID,                       -- Course class ID
                CONCAT(c.nameShort, '-', ccs.nameShort) AS courseName, -- Concatenated course name
                tday.name AS dayName,                          -- Day name (e.g. Monday)
                tcolrow.gibbonTTColumnRowID,                   -- Scheduling column/row ID
                tcolrow.name AS periodName,                    -- Period name (e.g. First Period)
                tcolrow.timeStart,                             -- Class start time
                tcolrow.timeEnd                                -- Class end time
            FROM gibbonttdayrowclass AS tdc
            JOIN gibboncourseclass AS ccs 
                ON ccs.gibbonCourseClassID = tdc.gibbonCourseClassID
            JOIN gibboncourse AS c 
                ON c.gibbonCourseID = ccs.gibbonCourseID
            JOIN gibbonttday AS tday 
                ON tday.gibbonTTDayID = tdc.gibbonTTDayID
            JOIN gibbonttcolumnrow AS tcolrow 
                ON tcolrow.gibbonTTColumnRowID = tdc.gibbonTTColumnRowID
            JOIN gibbontt AS tt 
                ON tt.gibbonTTID = tday.gibbonTTID
            JOIN (
                -- Subquery: Get all scheduling information (all dates and periods) for course 00000220
                SELECT DISTINCT 
                    tdc2.gibbonTTDayID,
                    tdc2.gibbonTTColumnRowID
                FROM gibbonttdayrowclass AS tdc2
                WHERE tdc2.gibbonCourseClassID = :gibbonCourseClassID
            ) AS base 
                ON tday.gibbonTTDayID = base.gibbonTTDayID 
                AND tcolrow.gibbonTTColumnRowID = base.gibbonTTColumnRowID
            ORDER BY tday.name, tcolrow.timeStart";
    
    // Execute the query using the provided data
    $result = $pdo->executeQuery($data, $sql);

    // Fetch all course scheduling records, if any
    $courses = ($result->rowCount() > 0) ? $result->fetchAll() : array();

    // Generate new transfer data by combining transferData and courses data using a helper function
    $newArray = generateTransferData($transferData, $courses);

    // If there are items in the new transfer array, loop through them to build the form
    if (count($newArray) > 0) {
        foreach ($newArray as $item) {
            // Heading row: display the current course name and period information.
            $headingText = $item['gibbonCourseClassName_OLD'] . ' (' . removeAlphaNumeric($item['gibbonTTDayName']) . ' / ' . $item['gibbonTTDayRowClassName_OLD'] . ')';
            $form->addRow()->addHeading($headingText);
    
            // Create a table row with two columns (left and right)
            $table = $form->addRow()->addTable()->setClass('smallIntBorder colorOddEven w-full standardForm');
    
            // Table header: you can customize as needed
            $header = $table->addHeaderRow();
            $header->addContent(__('Current Course'));
            $header->addContent(__('Replacement Course'));
    
            // Add a data row in the table
            $row = $table->addRow();
    
            // Left column: display current (old) course information, including course name and period
            $oldCourseInfo = $item['gibbonCourseClassName_OLD'] . ' - ' . $item['gibbonTTDayRowClassName_OLD'];
            $row->addContent($oldCourseInfo)->setClass('w-1/2');
    
            // Right column: if there is a list of transferable courses, display a dropdown selection;
            // otherwise, display a message indicating no replacement courses are available.
            if (!empty($item['transferableList'])) {
                // Prepare an array of dropdown options: key is the new course ID concatenated with new timetable row ID, value is the concatenated course name and period
                $options = [];
                foreach ($item['transferableList'] as $option) {
                    $value = $option['gibbonCourseClassID_NEW'] . '.' . $option['gibbonTTDayRowClassID_NEW'];
                    $name  = $option['gibbonCourseClassName_NEW'];
                    $options[$value] = $name;
                }
                // Create a select field using the prepared options
                $row->addSelect('replacement[' . $item['gibbonTTDayRowClassID_OLD'] . ']')
                    ->fromArray($options)
                    ->selected('')
                    ->setClass('w-1/2');
            } else {
                // If no transferable courses exist, show an italicized message
                $row->addContent('<em>' . __('No replacement courses available') . '</em>')->setClass('w-1/2');
            }
        }
    }

    // Add a final table row with a submit button at the footer
    $table = $form->addRow()->addTable()->setClass('smallIntBorder colorOddEven w-full standardForm');
    $row = $table->addRow();
        $row->addFooter();
        $row->addSubmit();

    // Output the complete form
    echo $form->getOutput();
    
    // Debug output: print the transfer data and new generated transfer data arrays
    echo "<pre>";
    print_r($transferData);
    print_r($newArray);
    echo "</pre>";
}
