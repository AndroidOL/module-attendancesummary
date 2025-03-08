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
$description = 'A module for quickly creating and inserting grades.';            // Short text description
$entryURL    = "attendance_summary.php";   // The landing page for the unit, used in the main menu
$type        = "Additional";  // Do not change.
$category    = 'Other';            // The main menu area to place the module in
$version     = '0.0.00';            // Version number
$author      = 'Tianhao Wu';            // Your name
$url         = 'https://gibbonedu.org';            // Your URL

// Module tables & gibbonSettings entries
$moduleTables[] = 'CREATE TABLE `attendance_transfer_record` (
    `transferID` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,  -- 转移记录主键自增
    `submitterID` INT(10) UNSIGNED ZEROFILL NOT NULL,               -- 提交人ID（对应原senderID，但字段名称已修改）
    `createTime` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,               -- 创建时间（原提交时间）
    `oldCourseID` INT(10) UNSIGNED ZEROFILL NOT NULL,               -- 转移课程序号（旧）
    `oldScheduleID` INT(12) UNSIGNED ZEROFILL NOT NULL,             -- 转移课表序号（旧）
    `newCourseID` INT(10) UNSIGNED ZEROFILL NOT NULL,               -- 转移课程序号（新）
    `newScheduleID` INT(12) UNSIGNED ZEROFILL NOT NULL,             -- 转移课表序号（新）
    `affectedCount` INT(5) NOT NULL,                               -- 变更影响数量
    PRIMARY KEY (`transferID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

// Add gibbonSettings entries
// $gibbonSetting[] = "";

// Action rows 
$actionRows[] = [
    'name'                      => 'View Student Attendance', // The name of the action (appears to user in the right hand side module menu)
    'precedence'                => '0',// If it is a grouped action, the precedence controls which is highest action in group
    'category'                  => 'Query', // Optional: subgroups for the right hand side module menu
    'description'               => 'Query the attendance information for the specified student', // Text description
    'URLList'                   => 'attendance_summary.php, attendance_summary_view.php', // List of pages included in this action
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
    'name'                      => 'View Child Attendance', // The name of the action (appears to user in the right hand side module menu)
    'precedence'                => '0',// If it is a grouped action, the precedence controls which is highest action in group
    'category'                  => 'Query', // Optional: subgroups for the right hand side module menu
    'description'               => 'Query the attendance information for the specified student', // Text description
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
    'precedence'                => '0',// If it is a grouped action, the precedence controls which is highest action in group
    'category'                  => 'Operations', // Optional: subgroups for the right hand side module menu
    'description'               => 'Transfer the attendance records of the specified course to another period', // Text description
    'URLList'                   => 'attendance_record.php, attendance_record_transfer.php', // List of pages included in this action
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
