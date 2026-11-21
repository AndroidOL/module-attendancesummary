<?php
/*
Gibbon: the flexible, open school platform
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Domain\System\SettingGateway;
use Gibbon\Forms\Form;

require_once __DIR__.'/moduleFunctions.php';

$page->breadcrumbs
    ->add(attendanceT('Attendance'), 'attendance_summary.php')
    ->add(attendanceT('Attendance Score Settings'));

if (!isActionAccessible($guid, $connection2, '/modules/School Admin/attendanceSettings.php')) {
    $page->addError(attendanceT('You do not have access to this page.'));
    return;
}

$page->return->addReturns([
    'success0' => attendanceT('Attendance score settings saved.'),
    'error0'   => attendanceT('You do not have permission to perform this action.'),
    'error1'   => attendanceT('The submitted data is invalid.'),
    'error9'   => attendanceT('Your session expired. Refresh the page and try again.'),
]);

$settingGateway = $container->get(SettingGateway::class);
$settings = [
    'scoreAbsence'           => ['Absence', 'Score for unexcused or personal absence.', 'keywordsAbsence'],
    'scoreSuspension'        => ['Suspension', 'Score for a suspension.', 'keywordsSuspension'],
    'scoreLate'              => ['Late', 'Score for lateness.', 'keywordsLate'],
    'scoreLeftEarly'         => ['Left Early', 'Score for leaving early.', 'keywordsLeftEarly'],
    'scoreSickLeave'         => ['Sick Leave', 'Score for sick leave.', 'keywordsSickLeave'],
    'scoreAuthorizedAbsence' => ['Authorized Absence', 'Score for an authorized absence.', 'keywordsAuthorizedAbsence'],
    'scorePresent'           => ['Present', 'Score for attendance.', 'keywordsPresent'],
    'scoreNoRecord'          => ['No Record', 'Score for an attendance type marked as no record.', 'keywordsNoRecord'],
    'scoreUnknown'           => ['Unknown Type', 'Fallback score when an attendance type does not match a configured category.', null],
];

$form = Form::create('attendanceScoreSettings', $session->get('absoluteURL').'/modules/Attendance Summary/attendance_settingsProcess.php', 'post');
$form->addHiddenValue('address', $session->get('address'));
$form->setTitle(attendanceT('Attendance Score Settings'));
$form->setDescription(attendanceT('Weights are used by the full attendance history by course. Values range from 0 to 1, where 1 means full attendance.'));

foreach ($settings as $name => [$label, $description, $keywordSetting]) {
    $setting = $settingGateway->getSettingByScope('Attendance Summary', $name, true);
    $row = $form->addRow();
        $row->addLabel($name, attendanceT($label))->description(attendanceT($description));
        $row->addNumber($name)
            ->minimum(0)
            ->maximum(1)
            ->step(0.01)
            ->required()
            ->setValue($setting['value'] ?? '');

    if ($keywordSetting) {
        $keyword = $settingGateway->getSettingByScope('Attendance Summary', $keywordSetting, true);
        $row = $form->addRow();
            $row->addLabel($keywordSetting, attendanceT('Matching Keywords'))
                ->description(attendanceT('Comma or semicolon separated attendance type fragments. Matching is case-insensitive.'));
            $row->addTextField($keywordSetting)->required()->maxLength(1000)->setValue($keyword['value'] ?? '');
    }
}

$row = $form->addRow();
    $row->addFooter();
    $row->addSubmit();

echo $form->getOutput();
