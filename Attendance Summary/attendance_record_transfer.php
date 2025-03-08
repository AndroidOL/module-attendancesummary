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

// Add breadcrumb for "Transfer Attendance Record Confirmation"
$page->breadcrumbs->add(__('Transfer Attendance Record Confirmation'));

// Check if the user has access to the attendance record transfer page
if (!isActionAccessible($guid, $connection2, '/modules/' . 'Attendance Summary' . '/attendance_record_transfer.php')) {
    // Access denied: display error message
    $page->addError(__('You do not have permission to access this page.'));
} else if (!isset($_REQUEST['gibbonPersonID'])) {
    // If no student has been selected, show an error message
    $page->addError(__('Please select the corresponding student!'));
} else if (isset($_REQUEST['gibbonPersonID']) && !isset($_REQUEST['gibbonCourseClassID'])) {
    // If student is selected but no course class is chosen, then display the course selection form

    // Create a form for choosing the course class
    $form = Form::create('gibbonCourseClassChoose', $session->get('absoluteURL').'/index.php?q=/modules/' . $session->get('module') . '/attendance_record_transfer.php');
    $form->setFactory(DatabaseFormFactory::create($pdo));
    $form->setTitle(__('Choose Course'));

    $classes = array();
    // "My Classes": retrieve course classes for the teacher
    $gibbonPersonID = $session->get('gibbonPersonID');
    $data = array(
        'gibbonSchoolYearID' => $session->get('gibbonSchoolYearID'),
        'gibbonPersonID'       => $gibbonPersonID,
        'gibbonStudentID'      => $_REQUEST['gibbonPersonID']
    );
    $sql = "SELECT 
                gcc.gibbonCourseClassID AS value, 
                CONCAT(gc.nameShort, '.', gcc.nameShort) AS name 
            FROM gibbonCourseClass AS gcc
            JOIN gibbonCourse AS gc 
                ON gcc.gibbonCourseID = gc.gibbonCourseID
            JOIN gibbonCourseClassPerson AS cp_teacher 
                ON cp_teacher.gibbonCourseClassID = gcc.gibbonCourseClassID
            WHERE gc.gibbonSchoolYearID = :gibbonSchoolYearID
              AND cp_teacher.gibbonPersonID = :gibbonPersonID
              AND EXISTS (
                  SELECT 1 
                  FROM gibbonCourseClassPerson 
                  WHERE gibbonCourseClassID = gcc.gibbonCourseClassID 
                    AND gibbonPersonID = :gibbonStudentID
              )
            ORDER BY name";
    try {
        $results = $pdo->executeQuery($data, $sql);
        if ($results->rowCount() > 0) {
            // Save teacher's classes under the key "--My Classes--"
            $classes['--'.__('My Classes').'--'] = $results->fetchAll(\PDO::FETCH_KEY_PAIR);
        }
    } catch (\PDOException $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    } catch (\Exception $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    }

    // "All Classes" if the teacher has access (for certain teacher IDs)
    $data = array('gibbonStudentID' => $_REQUEST['gibbonPersonID']);
    $sql = "SELECT DISTINCT
                gcc.gibbonCourseClassID AS value, 
                CONCAT(gc.nameShort, '.', gcc.nameShort) AS name 
            FROM gibbonCourseClass AS gcc
            JOIN gibbonCourse AS gc 
                ON gcc.gibbonCourseID = gc.gibbonCourseID
            JOIN gibbonCourseClassPerson AS cp 
                ON cp.gibbonCourseClassID = gcc.gibbonCourseClassID
            WHERE cp.gibbonPersonID = :gibbonStudentID
            ORDER BY name;";
    // Check if the teacher's ID is in the allowed list
    if ($gibbonPersonID == '0000000001' || $gibbonPersonID == '0000000017' || $gibbonPersonID == '0000000008' || $gibbonPersonID == '0000000010' || $gibbonPersonID == '0000000009' || $gibbonPersonID == '0000000015' || $gibbonPersonID == '0000000014') {
        try {
            $results = $pdo->executeQuery($data, $sql);
            if ($results->rowCount() > 0) {
                // Save all classes under the key "--All Classes--"
                $classes['--'.__('All Classes').'--'] = $results->fetchAll(\PDO::FETCH_KEY_PAIR);
            }
        } catch (\PDOException $e) {
            echo "<pre>";
            print_r($e->getMessage());
            echo "</pre>";
        } catch (\Exception $e) {
            echo "<pre>";
            print_r($e->getMessage());
            echo "</pre>";
        }
    }

    // Add a row to the form for selecting the course class
    $row = $form->addRow();
    $row->addLabel('gibbonCourseClassID', __('Course Name'));
    $row->addSelect('gibbonCourseClassID')->fromArray($classes)->required()->placeholder();

    // Keep the student ID as a hidden field
    $form->addHiddenValue('gibbonPersonID', $_REQUEST['gibbonPersonID']);
    $row = $form->addRow();
    $row->addSubmit(__('View Scheduling'));

    // Output the course selection form
    echo $form->getOutput();
} else if (isset($_REQUEST['gibbonCourseClassID']))  {
    // If the course class is selected, then display the scheduling confirmation form

    // Create a form for internal assessment transfer
    $form = Form::create('gibbonInternalAssessment', $session->get('absoluteURL').'/index.php?q=/modules/' . $session->get('module') . '/attendance_record_transferEdit.php');
    $form->setFactory(DatabaseFormFactory::create($pdo));
    $form->setTitle(__('Confirm Scheduling Information'));

    // Retrieve current course class information
    $classes = "";
    $data = array('gibbonCourseClassID' => $_REQUEST['gibbonCourseClassID']);
    $sql = "SELECT gibbonCourseClass.gibbonCourseClassID as value, CONCAT(gibbonCourse.nameShort, '.', gibbonCourseClass.nameShort) AS name 
            FROM gibbonCourseClass 
            JOIN gibbonCourse ON (gibbonCourseClass.gibbonCourseID=gibbonCourse.gibbonCourseID) 
            WHERE gibbonCourseClass.gibbonCourseClassID=:gibbonCourseClassID";
    
    try {
        $results = $pdo->executeQuery($data, $sql);
        if ($results->rowCount() < 1) {
            throw new InvalidArgumentException("The submitted data does not exist");
        }
    } catch (\PDOException $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    } catch (\Exception $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    }

    // Set default termFirstDay to today's date
    $termFirstDay = date("Y-m-d");
    // Query the current term's first day based on the current school year ID
    $data = array('gibbonSchoolYearID' => $session->get('gibbonSchoolYearID'));
    $sql = "SELECT firstDay FROM gibbonschoolyearterm
            WHERE gibbonSchoolYearID = :gibbonSchoolYearID 
              AND CURDATE() BETWEEN firstDay AND lastDay
            ORDER BY firstDay ASC LIMIT 1";
    try {
        $results = $pdo->executeQuery($data, $sql);
        if ($results->rowCount() == 1) {
            $row = $results->fetch();
            try {
                $date = new DateTime($row['firstDay']);
                $termFirstDay = $date->format('Y-m-d');
            } catch (Exception $e) {
                $termFirstDay = date("Y-m-d");
            }
        }
    } catch (\PDOException $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    } catch (\Exception $e) {
        echo "<pre>";
        print_r($e->getMessage());
        echo "</pre>";
    }

    // Add heading to the form
    $form->addRow()->addHeading('Transfer Record', __('Transfer Attendance Record'));

    // Add a row for the start date (default to the term first day)
    $row = $form->addRow();
    $row->addLabel('start_date', __('Start Date'));
    $row->addDate('start_date')->setValue($termFirstDay);

    // Add a row for the end date (default to today's date)
    $row = $form->addRow();
    $row->addLabel('end_date', __('End Date'));
    $row->addDate('end_date')->setValue(date("Y-m-d"));

    ////////////////////////////////////////////////////////////////////////////
    // Retrieve scheduling information for the selected course class
    $data = array('gibbonCourseClassID' => $_REQUEST['gibbonCourseClassID']);
    $sql = "SELECT
                tdc.gibbonTTDayRowClassID,                              -- Timetable scheduling ID
                tdc.gibbonCourseClassID,                                -- Course class ID
                CONCAT(c.nameShort, '-', cc.nameShort) AS courseName,   -- Concatenated course name
                tday.name AS dayName,                                   -- Day name (e.g. Monday, Tuesday, etc.)
                tcolrow.gibbonTTColumnRowID,                            -- Unique scheduling period ID
                tcolrow.name AS periodName,                             -- Period name (e.g. First Period)
                tcolrow.timeStart,                                      -- Class start time
                tcolrow.timeEnd                                         -- Class end time
            FROM gibbonttdayrowclass AS tdc
            JOIN gibboncourseclass AS cc 
                ON cc.gibbonCourseClassID = tdc.gibbonCourseClassID
            JOIN gibboncourse AS c 
                ON c.gibbonCourseID = cc.gibbonCourseID
            JOIN gibbonttday AS tday 
                ON tday.gibbonTTDayID = tdc.gibbonTTDayID
            JOIN gibbonttcolumnrow AS tcolrow 
                ON tcolrow.gibbonTTColumnRowID = tdc.gibbonTTColumnRowID
            WHERE tdc.gibbonCourseClassID = :gibbonCourseClassID
            ORDER BY tday.name, tcolrow.timeStart";
    $result = $pdo->executeQuery($data, $sql);

    // Fetch all scheduling records, if any exist
    $courses = ($result->rowCount() > 0) ? $result->fetchAll() : array();
    if (count($courses) == 0) {
        // If no scheduling records exist, display an alert message
        $form->addRow()->addHeading('Course', __('Scheduling Information'));
        $form->addRow()->addAlert(__('There are no records to display.'), 'error');
    } else {
        // Otherwise, create a table to display scheduling information
        $table = $form->addRow()->setHeading('table')->addTable()->setClass('smallIntBorder w-full colorOddEven noMargin noPadding noBorder');

        // Create table header row
        $header = $table->addHeaderRow();
        $header->addTableCell(__('Course Name'));
        $header->addTableCell(__('Cycle Name'));
        $header->addTableCell(__('Period Name'));
        $header->addTableCell(__('Start Time'));
        $header->addTableCell(__('End Time'));
        $header->addTableCell(__('Transfer?'));
    }

    // Loop through each scheduling record and display it in the table
    foreach ($courses as $index => $course) {
        $row = $table->addRow();
        $row->addColumn()->addLabel('courseName', $course['courseName']);
        $row->addColumn()->addLabel('dayName', $course['dayName']);
        $row->addColumn()->addLabel('periodName', $course['periodName']);
        $row->addColumn()->addLabel('timeStart', $course['timeStart']);
        $row->addColumn()->addLabel('timeEnd', $course['timeEnd']);
        // Add a checkbox to indicate if this record should be transferred
        $row->addCheckbox('transfer-' . $course['gibbonTTDayRowClassID'])->checked(false);
    }
    // Add hidden fields to retain student and course class IDs
    $form->addHiddenValue('gibbonPersonID', $_REQUEST['gibbonPersonID']);
    $form->addHiddenValue('gibbonCourseClassID', $_REQUEST['gibbonCourseClassID']);

    ////////////////////////////////////////////////////////////////////////////
    // Add final row with a submit button
    $row = $form->addRow();
    $row->addSubmit(__('Select New Course Information'));

    // Output the completed form
    echo $form->getOutput();
}

// Debug output: print the request data
echo "<pre>";
print_r($_REQUEST);
echo "</pre>";
