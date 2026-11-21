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

    public function testAttendanceScoreCategoriesUseConfiguredOverrides()
    {
        $config = \getAttendanceScoreConfig([
            'scoreAbsence' => 0.2,
            'scoreLate' => 0.8,
            'scoreUnknown' => 0.9,
        ]);

        $this->assertSame(0.2, \getAttendanceScore('Absent', $config));
        $this->assertSame(0.8, \getAttendanceScore('Late', $config));
        $this->assertSame(0.9, \getAttendanceScore('Unknown type', $config));
    }

    public function testAttendanceKeywordCategoriesAreConfigurable()
    {
        $config = \getAttendanceScoreConfig([
            'scoreAbsence' => 0.1,
            'scoreLate' => 0.6,
            'keywordsAbsence' => 'Truant; AWOL',
            'keywordsLate' => 'Tardy',
        ]);

        $this->assertSame(0.1, \getAttendanceScore('AWOL', $config));
        $this->assertSame(0.6, \getAttendanceScore('Tardy', $config));
        $this->assertSame(1.0, \getAttendanceScore('Absent', $config));
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

    public function testTransferParserRequiresScheduleRowsToMatchSubmittedClasses()
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE gibbonttdayrowclass (
            gibbonTTDayRowClassID TEXT PRIMARY KEY,
            gibbonCourseClassID TEXT NOT NULL,
            gibbonTTColumnRowID TEXT NOT NULL,
            gibbonTTDayID TEXT NOT NULL
        )');
        $statement = $pdo->prepare('INSERT INTO gibbonttdayrowclass VALUES (?, ?, ?, ?)');
        $statement->execute(['101', 'A', 'P1', 'D1']);
        $statement->execute(['102', 'B', 'P1', 'D1']);

        $valid = \parseAttendance($pdo, [
            'gibbonStudentID' => '1',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-07',
            'replacement-A_101' => 'B.102',
        ]);
        $this->assertCount(1, $valid['replacement']);

        $invalid = \parseAttendance($pdo, [
            'gibbonStudentID' => '1',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-07',
            'replacement-A_101' => 'D.102',
        ]);
        $this->assertCount(0, $invalid['replacement']);
    }

    public function testInvalidOrRelativeDatesFallBackToTheSafeDefaultRange()
    {
        [$start, $end] = \getValidatedDates('next week', '2026-02-30');

        $this->assertSame((new \DateTime())->format('Y-m-d'), $end);
        $this->assertSame((new \DateTime('-7 days'))->format('Y-m-d'), $start);
    }
    }
}
