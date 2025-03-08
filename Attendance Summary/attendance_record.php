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
use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Tables\DataTable;
use Gibbon\Services\Format;
use Gibbon\Domain\Students\StudentGateway;
use Gibbon\Domain\FormGroups\FormGroupGateway;

// Include module-specific helper functions
require_once __DIR__ . '/moduleFunctions.php';

// Add breadcrumb for "Transfer Attendance Records"
$page->breadcrumbs->add(__('Transfer Attendance Records'));

// Check if the user has permission to access the attendance record page
if (!isActionAccessible($guid, $connection2, '/modules/' . 'Attendance Summary' . '/attendance_record.php')) {
    // Access denied: display error message and TODO: redirect to a child page
    $page->addError(__('You do not have permission to access this page.'));
    ## TODO: Redirect to child page
} else {
    // Retrieve the selected Form Group ID from GET parameters (if provided)
    $gibbonFormGroupID = (isset($_GET['gibbonFormGroupID']) ? $_GET['gibbonFormGroupID'] : null);
    
    // Add breadcrumb for "Students by Form Group"
    $page->breadcrumbs->add(__('Students by Form Group'));

    // Create a new form for selecting a Form Group
    $form = Form::create('action', $session->get('absoluteURL').'/index.php', 'get');
    $form->setTitle(__('Choose Form Group'))
         ->setFactory(DatabaseFormFactory::create($pdo))
         ->setClass('noIntBorder w-full');

    // Add a hidden field for the query so the form submits to the correct module page
    $form->addHiddenValue('q', "/modules/".$session->get('module')."/attendance_record.php");

    // Add a row for selecting the Form Group
    $row = $form->addRow();
        $row->addLabel('gibbonFormGroupID', __('Form Group'));
        // Add a select field to choose a Form Group for the current school year. The field is required.
        $row->addSelectFormGroup('gibbonFormGroupID', $session->get('gibbonSchoolYearID'), true)
            ->selected($gibbonFormGroupID)
            ->placeholder()
            ->required();

    // Add a row for the search/submit button
    $row = $form->addRow();
        $row->addFooter();
        $row->addSearchSubmit($session);

    // Output the selection form
    echo $form->getOutput();
    
    // Cancel execution if no form group is selected
    if (!isset($gibbonFormGroupID)) return;

    // Retrieve the FormGroupGateway and StudentGateway from the dependency container
    $formGroupGateway = $container->get(FormGroupGateway::class);
    $studentGateway = $container->get(StudentGateway::class);

    // --- QUERY SECTION ---
    // Create query criteria to retrieve student enrolment information, sorted by form group, surname, and preferred name.
    // Set the page size to 50 records and populate the criteria from the $_POST array.
    $criteria = $studentGateway->newQueryCriteria(true)
        ->sortBy(['formGroup', 'surname', 'preferredName'])
        ->pageSize(50)
        ->fromArray($_POST);
    
    // Query the student enrolment data for the selected form group.
    // If the selected form group is not '*', pass the form group ID; otherwise, pass null.
    $students = $studentGateway->queryStudentEnrolmentByFormGroup($criteria, $gibbonFormGroupID != '*' ? $gibbonFormGroupID : null);

    // --- DATA TABLE SETUP ---
    // Create a paginated data table with the specified criteria
    $table = DataTable::createPaginated('studentsByFormGroup', $criteria);
    $table->setTitle(__('Report Data'));
    
    // Set the table description based on the selected form group and its associated tutors.
    $table->setDescription(function () use ($gibbonFormGroupID, $formGroupGateway) {
        $output = '';

        // If the selection is set to '*' (all), then no description is generated.
        if ($gibbonFormGroupID == '*') return $output;
        
        // Retrieve the form group details by ID and add the form group name to the output.
        if ($formGroup = $formGroupGateway->getFormGroupByID($gibbonFormGroupID)) {
            $output .= '<b>' . __('Form Group') . '</b>: ' . $formGroup['name'];
        }
        // Retrieve and list the tutors associated with this form group.
        if ($tutors = $formGroupGateway->selectTutorsByFormGroup($gibbonFormGroupID)->fetchAll()) {
            $output .= '<br/><b>' . __('Tutors') . '</b>: ' . Format::nameList($tutors, 'Staff');
        }

        return $output;
    });

    // Add a column for the Student's name, including sortable fields for surname and preferred name.
    $table->addColumn('student', __('Student'))
        ->width('30%')
        ->sortable(['surname', 'preferredName'])
        ->format(function ($person) {
            // Format the student's full name and include a small text with their status information.
            return Format::name('', $person['preferredName'], $person['surname'], 'Student', true, true)
                . '<br/><small><i>' . Format::userStatusInfo($person) . '</i></small>';
        });
    
    // Add columns for Year Group and Form Group
    $table->addColumn('yearGroup', __('Year Group'));
    $table->addColumn('formGroup', __('Form Group'));
    
    // Add an action column with a "Transfer" action. The action passes student parameters and links to the attendance record transfer page.
    $table->addActionColumn()
        ->addParam('gibbonPersonID')
        ->addParam('surname')
        ->addParam('preferredName')
        ->format(function ($row, $actions) {
            $actions->addAction('import', __('Transfer'))
                ->setURL('/modules/' . 'Attendance Summary' . '/attendance_record_transfer.php');
        });

    // Render the table with the student data
    echo $table->render($students);
}

// Debug output: print the $students array for troubleshooting purposes
echo "<pre>";
print_r($students);
echo "</pre>";
