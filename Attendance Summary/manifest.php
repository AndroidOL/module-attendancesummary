<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http:// www.gnu.org/licenses/>.
*/

// This file describes the module, including database tables

// Basic variables
$name        = 'Attendance Summary';            // The name of the module as it appears to users. Needs to be unique to installation. Also the name of the folder that holds the unit.
$description = 'View, analyse and transfer student attendance.';            // Short text description
$entryURL    = "attendance_summary.php";   // The landing page for the unit, used in the main menu
$type        = "Additional";  // Do not change.
$category    = 'Other';            // The main menu area to place the module in
$version     = '1.0.5';            // Version number
$author      = 'Tianhao Wu';            // Your name
$url         = 'https://gibbonedu.org';            // Your URL

// Module tables & gibbonSettings entries
$moduleTables[] = 'CREATE TABLE `attendance_transfer_record` (
    `transferID` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `submitterID` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `createTime` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `oldCourseID` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `oldScheduleID` INT(12) UNSIGNED ZEROFILL NOT NULL,
    `newCourseID` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `newScheduleID` INT(12) UNSIGNED ZEROFILL NOT NULL,
    `affectedCount` INT(5) NOT NULL,
    `startDate` DATE DEFAULT NULL,
    `endDate` DATE DEFAULT NULL,
    PRIMARY KEY (`transferID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

// Add gibbonSettings entries
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'scoreAbsence', 'Absence Score', 'Score for unexcused or personal absence.', '0');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'scoreSuspension', 'Suspension Score', 'Score for a suspension.', '0');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'scoreLate', 'Late Score', 'Score for lateness.', '0.7');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'scoreLeftEarly', 'Left Early Score', 'Score for leaving early.', '0.7');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'scoreSickLeave', 'Sick Leave Score', 'Score for sick leave.', '0.5');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'scoreAuthorizedAbsence', 'Authorized Absence Score', 'Score for an authorized absence.', '1');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'scorePresent', 'Present Score', 'Score for attendance.', '1');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'scoreNoRecord', 'No Record Score', 'Score for an attendance type marked as no record.', '1');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'scoreUnknown', 'Unknown Type Score', 'Fallback score when an attendance type does not match a configured category.', '1');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'keywordsAbsence', 'Absence Keywords', 'Comma-separated attendance type keywords for absence and personal leave.', '旷课,事假,Absent,Unexcused absence');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'keywordsSuspension', 'Suspension Keywords', 'Comma-separated attendance type keywords for suspension.', '被停课,Suspended');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'keywordsLate', 'Late Keywords', 'Comma-separated attendance type keywords for lateness.', '迟到,Late');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'keywordsLeftEarly', 'Left Early Keywords', 'Comma-separated attendance type keywords for leaving early.', '早退,Left early');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'keywordsSickLeave', 'Sick Leave Keywords', 'Comma-separated attendance type keywords for sick leave.', '病假,Sick leave');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'keywordsAuthorizedAbsence', 'Authorized Absence Keywords', 'Comma-separated attendance type keywords for authorized absence.', '公假,Authorized absence');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'keywordsPresent', 'Present Keywords', 'Comma-separated attendance type keywords for attendance.', '出勤,Present');";
$gibbonSetting[] = "INSERT INTO `gibbonSetting` (`scope`, `name`, `nameDisplay`, `description`, `value`) VALUES ('Attendance Summary', 'keywordsNoRecord', 'No Record Keywords', 'Comma-separated attendance type keywords for no record.', '无记录,No record');";

// Action rows 
$actionRows[] = [
    'name'                      => 'View Student Attendance', // The name of the action (appears to user in the right hand side module menu)
    'precedence'                => '0',// If it is a grouped action, the precedence controls which is highest action in group
    'category'                  => 'Reports', // Optional: subgroups for the right hand side module menu
    'description'               => 'View attendance, analytics and full attendance history for students.', // Text description
    'URLList'                   => 'attendance_summary.php, attendance_summary_view.php, attendance_lifecycle.php, attendance_lifecycle_course.php, attendance_analytics.php', // List of pages included in this action
    'entryURL'                  => 'attendance_summary.php', // The landing action for the page.
    'entrySidebar'              => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow'                  => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin'    => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher'  => 'Y', // Default permission for built in role Teacher
    'defaultPermissionStudent'  => 'N', // Default permission for built in role Student
    'defaultPermissionParent'   => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport'  => 'Y', // Default permission for built in role Support
    'categoryPermissionStaff'   => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent'  => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther'   => 'N', // Should this action be available to user roles in the Other category?
];
$actionRows[] = [
    'name'                      => 'View Attendance Trends', // The name of the action (appears to user in the right hand side module menu)
    'precedence'                => '0',// If it is a grouped action, the precedence controls which is highest action in group
    'category'                  => 'Reports', // Optional: subgroups for the right hand side module menu
    'description'               => 'View attendance trends for a student.', // Text description
    'URLList'                   => 'attendance_report.php', // List of pages included in this action
    'entryURL'                  => 'attendance_report.php', // The landing action for the page.
    'entrySidebar'              => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow'                  => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin'    => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher'  => 'Y', // Default permission for built in role Teacher
    'defaultPermissionStudent'  => 'N', // Default permission for built in role Student
    'defaultPermissionParent'   => 'Y', // Default permission for built in role Parent
    'defaultPermissionSupport'  => 'N', // Default permission for built in role Support
    'categoryPermissionStaff'   => 'N', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent'  => 'Y', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther'   => 'N', // Should this action be available to user roles in the Other category?
];
$actionRows[] = [
    'name'                      => 'View My Child\'s Attendance', // The name of the action (appears to user in the right hand side module menu)
    'precedence'                => '1',// If it is a grouped action, the precedence controls which is highest action in group
    'category'                  => 'Reports', // Optional: subgroups for the right hand side module menu
    'description'               => 'View attendance for a linked child.', // Text description
    'URLList'                   => 'attendance_summary_view_myChild.php', // List of pages included in this action
    'entryURL'                  => 'attendance_summary_view_myChild.php', // The landing action for the page.
    'entrySidebar'              => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow'                  => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin'    => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher'  => 'N', // Default permission for built in role Teacher
    'defaultPermissionStudent'  => 'N', // Default permission for built in role Student
    'defaultPermissionParent'   => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport'  => 'N', // Default permission for built in role Support
    'categoryPermissionStaff'   => 'N', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent'  => 'Y', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther'   => 'N', // Should this action be available to user roles in the Other category?
];
$actionRows[] = [
    'name'                      => 'Transfer Attendance Records', // The name of the action (appears to user in the right hand side module menu)
    'precedence'                => '1',// If it is a grouped action, the precedence controls which is highest action in group
    'category'                  => 'Administration', // Optional: subgroups for the right hand side module menu
    'description'               => 'Transfer attendance records from one class lesson to another.', // Text description
    'URLList'                   => 'attendance_record.php, attendance_record_transfer.php, attendance_record_transferEdit.php, attendance_record_transferEditProcess.php', // List of pages included in this action
    'entryURL'                  => 'attendance_record.php', // The landing action for the page.
    'entrySidebar'              => 'Y', // Whether or not there's a sidebar on entry to the action
    'menuShow'                  => 'Y', // Whether or not this action shows up in menus or if it's hidden
    'defaultPermissionAdmin'    => 'Y', // Default permission for built in role Admin
    'defaultPermissionTeacher'  => 'Y', // Default permission for built in role Teacher
    'defaultPermissionStudent'  => 'N', // Default permission for built in role Student
    'defaultPermissionParent'   => 'N', // Default permission for built in role Parent
    'defaultPermissionSupport'  => 'Y', // Default permission for built in role Support
    'categoryPermissionStaff'   => 'Y', // Should this action be available to user roles in the Staff category?
    'categoryPermissionStudent' => 'N', // Should this action be available to user roles in the Student category?
    'categoryPermissionParent'  => 'N', // Should this action be available to user roles in the Parent category?
    'categoryPermissionOther'   => 'N', // Should this action be available to user roles in the Other category?
];

// Hooks
// $hooks[] = ''; // Serialised array to create hook and set options. See Hooks documentation online.
