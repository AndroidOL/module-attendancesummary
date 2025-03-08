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

// Import necessary classes from Gibbon framework
use Gibbon\Forms\Form;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Tables\DataTable;
use Gibbon\Services\Format;
use Gibbon\Domain\Students\StudentGateway;
use Gibbon\Domain\FormGroups\FormGroupGateway;

// Include module-specific functions
require_once __DIR__ . '/moduleFunctions.php';

// Add breadcrumb for "View Attendance Information"
$page->breadcrumbs->add(__('View Attendance Information'));

// Check if the user has access to this page
if (!isActionAccessible($guid, $connection2, '/modules/' . 'Attendance Summary' . '/attendance_summary.php')) {
    // Access denied: add error message and (TODO) redirect to child page
    $page->addError(__('You do not have permission to access this page.'));
    // TODO: Redirect to child page
} else {
    // Retrieve the selected Form Group ID from GET parameters (if set)
    $gibbonFormGroupID = (isset($_GET['gibbonFormGroupID']) ? $_GET['gibbonFormGroupID'] : null);
    
    // Add a breadcrumb for "Students by Form Group"
    $page->breadcrumbs->add(__('Students by Form Group'));

    // Create a new form for selecting a Form Group
    $form = Form::create('action', $session->get('absoluteURL').'/index.php', 'get');
    $form->setTitle(__('Choose Form Group'))
        ->setFactory(DatabaseFormFactory::create($pdo))
        ->setClass('noIntBorder w-full');

    // Add hidden field for the query parameter so that the form submits to the correct module page
    $form->addHiddenValue('q', "/modules/".$session->get('module')."/attendance_summary.php");

    // Add a row for selecting the Form Group
    $row = $form->addRow();
    $row->addLabel('gibbonFormGroupID', __('Form Group'));
    // Add a select field that lists all form groups for the current school year
    $row->addSelectFormGroup('gibbonFormGroupID', $session->get('gibbonSchoolYearID'), true)
        ->selected($gibbonFormGroupID)
        ->placeholder()  // Display placeholder text when nothing is selected
        ->required();

    // Add a row with the submit button for searching
    $row = $form->addRow();
    $row->addFooter();
    $row->addSearchSubmit($session);

    // Output the search form HTML
    echo $form->getOutput();
    
    // If no form group has been selected, stop execution here.
    if (!isset($gibbonFormGroupID)) return;

    // Get the FormGroupGateway and StudentGateway from the dependency container
    $formGroupGateway = $container->get(FormGroupGateway::class);
    $studentGateway = $container->get(StudentGateway::class);

    // --- QUERY SECTION ---
    // Create new query criteria, sorting by formGroup, surname, and preferredName, with a page size of 50 records.
    // The criteria can also be populated from the $_POST array.
    $criteria = $studentGateway->newQueryCriteria(true)
        ->sortBy(['formGroup', 'surname', 'preferredName'])
        ->pageSize(50)
        ->fromArray($_POST);
    
    // Retrieve students enrolled by the selected form group.
    // If the selected form group is not '*' then pass the form group ID, otherwise pass null.
    $students = $studentGateway->queryStudentEnrolmentByFormGroup($criteria, $gibbonFormGroupID != '*' ? $gibbonFormGroupID : null);

    // --- DATA TABLE SECTION ---
    // Create a paginated data table using the criteria defined earlier.
    $table = DataTable::createPaginated('studentsByFormGroup', $criteria);
    $table->setTitle(__('Report Data'));
    
    // Set a description for the table based on the selected form group.
    $table->setDescription(function () use ($gibbonFormGroupID, $formGroupGateway) {
        $output = '';

        // If the form group is set to '*' (all), no additional description is output.
        if ($gibbonFormGroupID == '*') return $output;
        
        // Retrieve the form group details by ID and add its name to the output.
        if ($formGroup = $formGroupGateway->getFormGroupByID($gibbonFormGroupID)) {
            $output .= '<b>'.__('Form Group').'</b>: '.$formGroup['name'];
        }
        // Retrieve tutors associated with the form group and list their names.
        if ($tutors = $formGroupGateway->selectTutorsByFormGroup($gibbonFormGroupID)->fetchAll()) {
            $output .= '<br/><b>'.__('Tutors').'</b>: '.Format::nameList($tutors, 'Staff');
        }

        return $output;
    });

    // Add a column for the Student name
    $table->addColumn('student', __('Student'))
        ->width('30%')
        ->sortable(['surname', 'preferredName'])
        ->format(function ($person) {
            // Format the student name with surname and preferredName.
            // Also add a small note showing the student's status.
            return Format::name('', $person['preferredName'], $person['surname'], 'Student', true, true)
                . '<br/><small><i>' . Format::userStatusInfo($person) . '</i></small>';
        });
    
    // Add a column for the Year Group
    $table->addColumn('yearGroup', __('Year Group'));
    // Add a column for the Form Group
    $table->addColumn('formGroup', __('Form Group'));
    
    // Add an action column that provides a "View" link for each student.
    $table->addActionColumn()
        ->addParam('gibbonPersonID')
        ->addParam('surname')
        ->addParam('preferredName')
        ->format(function ($row, $actions) {
            $actions->addAction('profile', __('View'))
                ->setURL('/modules/' . 'Attendance Summary' . '/attendance_summary_view.php');
        });

    // Render the data table with the list of students
    echo $table->render($students);
}

// Debug output: print the students array to help with troubleshooting
echo "<pre>";
print_r($students);
echo "</pre>";
