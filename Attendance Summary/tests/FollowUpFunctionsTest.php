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
            $functions = file_get_contents($base.'/followupFunctions.php');
            $version = file_get_contents($base.'/version.php');

            foreach (['attendance_follow_up', 'attendance_follow_up_note', 'attendance_follow_up_event', 'attendance_follow_up_retention_log'] as $table) {
                $this->assertStringContainsString($table, $manifest);
                $this->assertStringContainsString($table, $changedb);
            }
            foreach ([$create, $update, $note, $retention] as $source) {
                $this->assertStringContainsString('validateCsrfToken', $source);
                $this->assertStringContainsString("REQUEST_METHOD", $source);
            }
            $this->assertStringContainsString("\$version     = '1.1.0'", $manifest);
            $this->assertStringContainsString("\$moduleVersion = '1.1.0'", $version);
            $this->assertStringContainsString("'name'                      => 'View Attendance Follow-up'", $manifest);
            $this->assertStringContainsString("'name'                      => 'Manage Attendance Follow-up'", $manifest);
            $this->assertStringContainsString("'defaultPermissionTeacher'  => 'N'", $manifest);
            $this->assertStringContainsString('gibbonPersonIDTutor2', $functions);
            $this->assertStringContainsString('followUp.gibbonPersonIDCreator', $functions);
            $this->assertStringContainsString('followUp.gibbonPersonIDOwner', $functions);
            $this->assertStringNotContainsString('gibbonCourseClassPerson', $functions);
            $this->assertStringContainsString('revision=revision+1', $update);
            $this->assertStringContainsString('activeKey', $changedb);
            $this->assertStringContainsString('revision', $changedb);
            $this->assertStringContainsString('attendanceFollowUpRecordEvent', $update);
            $this->assertStringContainsString('maxLength(2000)', $view);
            $this->assertStringContainsString('sendAttendanceCsv', $export);
            $this->assertStringContainsString('/modules/System Admin/systemSettings.php', $retention);
            $this->assertStringContainsString('ON DELETE CASCADE', $manifest);
        }
    }
}
