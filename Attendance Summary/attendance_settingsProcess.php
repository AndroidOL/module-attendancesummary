<?php
/*
Gibbon: the flexible, open school platform
Copyright © 2010, Gibbon Foundation
*/

use Gibbon\Data\Validator;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Session\TokenHandler;

require_once '../../gibbon.php';
require_once __DIR__.'/moduleFunctions.php';

$URL = $session->get('absoluteURL').'/index.php?q=/modules/Attendance Summary/attendance_settings.php';
$redirect = function (string $return) use ($URL) {
    header('Location: '.$URL.'&return='.$return);
    exit;
};

if (!isActionAccessible($guid, $connection2, '/modules/School Admin/attendanceSettings.php')) {
    $redirect('error0');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $redirect('error1');
}
if (!$container->get(TokenHandler::class)->validateCsrfToken()) {
    $redirect('error9');
}

$_POST = $container->get(Validator::class)->sanitize($_POST);
$names = [
    'scoreAbsence',
    'scoreSuspension',
    'scoreLate',
    'scoreLeftEarly',
    'scoreSickLeave',
    'scoreAuthorizedAbsence',
    'scorePresent',
    'scoreNoRecord',
    'scoreUnknown',
];

$values = [];
foreach ($names as $name) {
    $value = is_scalar($_POST[$name] ?? null) ? trim((string) $_POST[$name]) : '';
    if ($value === '' || !is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
        $redirect('error1');
    }
    $values[$name] = number_format((float) $value, 4, '.', '');
}

$settingGateway = $container->get(SettingGateway::class);
foreach ($values as $name => $value) {
    if (!$settingGateway->updateSettingByScope('Attendance Summary', $name, $value)) {
        $redirect('error1');
    }
}

$redirect('success0');
