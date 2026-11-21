<?php

namespace {
    if (!function_exists('__')) {
        function __($text, array $params = [])
        {
            foreach ($params as $key => $value) {
                $text = str_replace('{'.$key.'}', (string) $value, $text);
            }

            return $text;
        }
    }
}

namespace Gibbon\Module\Attendance\Tests {
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__).'/moduleFunctions.php';
    require_once dirname(__DIR__).'/followupFunctions.php';

    final class FollowUpFunctionsTest extends TestCase
    {
        public function testStatusTransitionsAreControlled()
        {
            $this->assertTrue(\attendanceFollowUpStatusAllowsTransition('open', 'in_progress'));
            $this->assertTrue(\attendanceFollowUpStatusAllowsTransition('open', 'resolved'));
            $this->assertTrue(\attendanceFollowUpStatusAllowsTransition('in_progress', 'open'));
            $this->assertTrue(\attendanceFollowUpStatusAllowsTransition('in_progress', 'resolved'));
            $this->assertTrue(\attendanceFollowUpStatusAllowsTransition('resolved', 'open'));
            $this->assertFalse(\attendanceFollowUpStatusAllowsTransition('resolved', 'in_progress'));
            $this->assertFalse(\attendanceFollowUpStatusAllowsTransition('open', 'open'));
        }

        public function testActiveKeyIsStableAndScoped()
        {
            $first = \attendanceFollowUpActiveKey('0000000001', '00026', 'no_record', '2026-09-01', '2026-09-30');
            $same = \attendanceFollowUpActiveKey('0000000001', '00026', 'no_record', '2026-09-01', '2026-09-30');
            $different = \attendanceFollowUpActiveKey('0000000001', '00026', 'low_attendance', '2026-09-01', '2026-09-30');

            $this->assertSame($first, $same);
            $this->assertNotSame($first, $different);
            $this->assertSame(64, strlen($first));
        }

        public function testDefaultDueDateSkipsWeekend()
        {
            $friday = new \DateTimeImmutable('2026-11-20');

            $this->assertSame('2026-11-27', \attendanceFollowUpDefaultDueDate($friday));
        }

        public function testRetentionUsesYearEndPlusTwelveMonths()
        {
            $this->assertSame('2028-08-31', \attendanceFollowUpRetentionUntil('2027-08-31'));
            $this->assertNull(\attendanceFollowUpRetentionUntil('not-a-date'));
        }

        public function testFormTutorAndFullStatusChecks()
        {
            $enrolment = [
                'status' => 'Full',
                'gibbonPersonIDTutor' => '0000000001',
                'gibbonPersonIDTutor2' => '0000000002',
                'gibbonPersonIDTutor3' => '',
            ];

            $this->assertTrue(\attendanceFollowUpStudentIsFull($enrolment));
            $this->assertTrue(\attendanceFollowUpIsFormTutor($enrolment, '0000000002'));
            $this->assertFalse(\attendanceFollowUpIsFormTutor($enrolment, '0000000003'));
            $this->assertFalse(\attendanceFollowUpStudentIsFull(['status' => 'Left']));
        }

        public function testSchemaPermissionsAndSecurityPathsArePresent()
        {
            $base = dirname(__DIR__);
            $manifest = file_get_contents($base.'/manifest.php');
            $changedb = file_get_contents($base.'/CHANGEDB.php');
            $view = file_get_contents($base.'/attendance_followup_view.php');
            $create = file_get_contents($base.'/attendance_followup_createProcess.php');
            $update = file_get_contents($base.'/attendance_followup_updateProcess.php');
            $note = file_get_contents($base.'/attendance_followup_noteProcess.php');
            $export = file_get_contents($base.'/attendance_followup_export.php');
            $retention = file_get_contents($base.'/attendance_followup_retentionProcess.php');
            $summary = file_get_contents($base.'/attendance_summary.php');
            $functions = file_get_contents($base.'/followupFunctions.php');
            $moduleFunctions = file_get_contents($base.'/moduleFunctions.php');
            $version = file_get_contents($base.'/version.php');

            foreach (['attendance_follow_up', 'attendance_follow_up_note', 'attendance_follow_up_event', 'attendance_follow_up_retention_log'] as $table) {
                $this->assertStringContainsString($table, $manifest);
                $this->assertStringContainsString($table, $changedb);
            }
            foreach ([$create, $update, $note, $retention] as $source) {
                $this->assertStringContainsString('validateCsrfToken', $source);
                $this->assertStringContainsString("REQUEST_METHOD", $source);
            }
            $this->assertStringContainsString("\$version     = '1.1.3'", $manifest);
            $this->assertStringContainsString("\$moduleVersion = '1.1.3'", $version);
            $this->assertStringContainsString("'name'                      => 'View Attendance Follow-up'", $manifest);
            $this->assertStringContainsString("'name'                      => 'Manage Attendance Follow-up'", $manifest);
            $this->assertStringContainsString("'defaultPermissionTeacher'  => 'Y'", $manifest);
            $this->assertStringContainsString('gibbonPersonIDTutor2', $functions);
            $this->assertStringContainsString('followUp.gibbonPersonIDCreator', $functions);
            $this->assertStringContainsString('followUp.gibbonPersonIDOwner', $functions);
            $this->assertStringNotContainsString('gibbonCourseClassPerson', $functions);
            $this->assertStringContainsString('!attendanceFollowUpStudentIsFull($enrolment)', $create);
            $this->assertStringContainsString('FOR UPDATE', $note);
            $this->assertStringContainsString('attendanceFollowUpCanAccessCase($pdo, $gibbonPersonID, $lockedCase)', $note);
            $this->assertStringContainsString('revision=revision+1', $update);
            $this->assertStringContainsString('activeKey', $changedb);
            $this->assertStringContainsString('revision', $changedb);
            $this->assertStringContainsString('attendanceFollowUpRecordEvent', $update);
            $this->assertStringContainsString('gibbonPersonIDActor=:actorID', $functions);
            $this->assertStringNotContainsString('actorPersonID=:actorPersonID', $functions);
            $this->assertStringContainsString('INSERT INTO gibbonAction', $changedb);
            $this->assertStringContainsString("'View Attendance Follow-up'", $changedb);
            $this->assertStringContainsString("'Manage Attendance Follow-up'", $changedb);
            $this->assertStringContainsString('INSERT INTO gibbonPermission', $changedb);
            $this->assertStringContainsString("SELECT '002', action.gibbonActionID", $changedb);
            $this->assertSame(8, substr_count($changedb, 'AND NOT EXISTS ('));
            $this->assertSame(
                4,
                substr_count($changedb, "'Y', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'Y', 'N', 'N', 'N'")
            );
            $this->assertStringNotContainsString(
                "'Y', 'Y', 'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N'",
                $changedb
            );
            $this->assertStringContainsString("SET action.defaultPermissionTeacher='Y'", $changedb);
            $this->assertStringContainsString('maxLength(2000)', $view);
            $this->assertStringContainsString('sendAttendanceCsv', $export);
            $this->assertStringContainsString('The CSV was not generated.', $export);
            $this->assertStringContainsString('/modules/System Admin/systemSettings.php', $retention);
            $this->assertStringContainsString('/modules/Attendance Summary/attendance_followup_create.php', $summary);
            $this->assertStringContainsString("(string) \$session->get('gibbonPersonID')", $summary);
            $this->assertStringContainsString("require_once __DIR__ . '/followupFunctions.php'", $summary);
            $this->assertStringContainsString('$followUpStudentIDs = $canCreateFollowUp', $summary);
            $this->assertStringContainsString('isset($followUpStudentIDs[', $summary);
            $this->assertStringContainsString("\$followUpHeaderAction->addParam('gibbonFormGroupID'", $summary);
            $this->assertStringContainsString("\$followUpAction->addParam('gibbonFormGroupID'", $summary);
            $this->assertStringContainsString("addParam('reason', 'low_attendance')", $summary);
            $this->assertStringContainsString("addParam('start', \$followUpStartDate)", $summary);
            $this->assertStringContainsString("addParam('end', \$followUpEndDate)", $summary);
            $this->assertStringContainsString('ON DELETE CASCADE', $manifest);
            $this->assertStringContainsString('new Button(', $moduleFunctions);
            $this->assertStringNotContainsString('<button type="button"', $moduleFunctions);
        }

        public function testTranslationMetadataMatchesInstalledModuleVersion()
        {
            $po = file_get_contents(dirname(__DIR__).'/i18n/zh_CN/LC_MESSAGES/Attendance Summary.po');

            $this->assertStringContainsString('Project-Id-Version: Attendance Summary 1.1.3', $po);
            $this->assertStringNotContainsString('Project-Id-Version: Attendance Summary 1.1.2', $po);
            $this->assertMatchesRegularExpression('/POT-Creation-Date: 2026-11-21 18:23\+0800/', $po);
            $this->assertMatchesRegularExpression('/PO-Revision-Date: 2026-11-21 18:24\+0800/', $po);
        }
    }
}
