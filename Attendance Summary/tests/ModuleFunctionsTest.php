<?php

namespace {
    if (!function_exists('__')) {
        function __($text)
        {
            return $text;
        }
    }
}

namespace Gibbon\Module\Attendance\Tests {
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__).'/moduleFunctions.php';

    final class ModuleFunctionsTest extends TestCase
    {
    public function testAttendanceScoreUsesTheLowestMatchingType()
    {
        $config = \getAttendanceScoreConfig();

        $this->assertSame(0.0, \getAttendanceScore('Absent', $config));
        $this->assertSame(0.7, \getAttendanceScore('Present but late', $config));
        $this->assertSame(0.5, \getAttendanceScore('SICK LEAVE', $config));
        $this->assertSame(1.0, \getAttendanceScore('Unknown type', $config));
    }

    public function testCsvCellsWithLeadingWhitespaceCannotStartAFormula()
    {
        $this->assertSame("'=1+1", \attendanceCsvSafeCell('=1+1'));
        $this->assertSame("' \t@SUM(A1:A2)", \attendanceCsvSafeCell(" \t@SUM(A1:A2)"));
        $this->assertSame('normal text', \attendanceCsvSafeCell('normal text'));
    }

    public function testNoRecordSentinelIsStableAndDisplayed()
    {
        $this->assertTrue(\attendanceIsNoRecord('__NO_RECORD__'));
        $this->assertFalse(\attendanceIsNoRecord('Absent'));
        $this->assertSame('No record', \attendanceDisplayType('__NO_RECORD__'));
    }
    }
}
