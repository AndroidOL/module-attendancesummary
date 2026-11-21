<?php
// USE ;end TO SEPARATE SQL STATEMENTS. DON'T USE ;end IN ANY OTHER PLACES!

$sql = [];
$count = 0;

// v1.1.1
$sql[$count][0] = "1.1.1";
$sql[$count][1] = "INSERT INTO gibbonAction
    (gibbonModuleID, name, precedence, category, description, URLList, entryURL,
     entrySidebar, menuShow, defaultPermissionAdmin, defaultPermissionTeacher,
     defaultPermissionStudent, defaultPermissionParent, defaultPermissionSupport,
     categoryPermissionStaff, categoryPermissionStudent, categoryPermissionParent,
     categoryPermissionOther)
SELECT module.gibbonModuleID, 'View Attendance Follow-up', 0, 'Follow-up',
       'View assigned attendance follow-up cases and append-only coordination notes.',
       'attendance_followup.php, attendance_followup_view.php',
       'attendance_followup.php', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N'
FROM gibbonModule AS module
WHERE module.name='Attendance Summary'
  AND NOT EXISTS (
      SELECT 1 FROM gibbonAction AS action
      WHERE action.gibbonModuleID=module.gibbonModuleID
        AND action.name='View Attendance Follow-up'
  );end
INSERT INTO gibbonAction
    (gibbonModuleID, name, precedence, category, description, URLList, entryURL,
     entrySidebar, menuShow, defaultPermissionAdmin, defaultPermissionTeacher,
     defaultPermissionStudent, defaultPermissionParent, defaultPermissionSupport,
     categoryPermissionStaff, categoryPermissionStudent, categoryPermissionParent,
     categoryPermissionOther)
SELECT module.gibbonModuleID, 'Manage Attendance Follow-up', 1, 'Follow-up',
       'Create, assign and update attendance follow-up cases, notes and retention.',
       'attendance_followup.php, attendance_followup_view.php, attendance_followup_create.php, attendance_followup_createProcess.php, attendance_followup_updateProcess.php, attendance_followup_noteProcess.php, attendance_followup_export.php, attendance_followup_retention.php, attendance_followup_retentionProcess.php',
       'attendance_followup.php', 'Y', 'Y', 'Y', 'N', 'N', 'N', 'N', 'Y', 'N', 'N', 'N'
FROM gibbonModule AS module
WHERE module.name='Attendance Summary'
  AND NOT EXISTS (
      SELECT 1 FROM gibbonAction AS action
      WHERE action.gibbonModuleID=module.gibbonModuleID
        AND action.name='Manage Attendance Follow-up'
  );end
INSERT IGNORE INTO gibbonPermission (gibbonRoleID, gibbonActionID)
SELECT '001', action.gibbonActionID
FROM gibbonAction AS action
JOIN gibbonModule AS module ON module.gibbonModuleID=action.gibbonModuleID
WHERE module.name='Attendance Summary'
  AND action.name IN ('View Attendance Follow-up', 'Manage Attendance Follow-up')
  AND NOT EXISTS (
      SELECT 1 FROM gibbonPermission AS permission
      WHERE permission.gibbonRoleID='001'
        AND permission.gibbonActionID=action.gibbonActionID
  );end
INSERT INTO gibbonPermission (gibbonRoleID, gibbonActionID)
SELECT '002', action.gibbonActionID
FROM gibbonAction AS action
JOIN gibbonModule AS module ON module.gibbonModuleID=action.gibbonModuleID
WHERE module.name='Attendance Summary'
  AND action.name IN ('View Attendance Follow-up', 'Manage Attendance Follow-up')
  AND NOT EXISTS (
      SELECT 1 FROM gibbonPermission AS permission
      WHERE permission.gibbonRoleID='002'
        AND permission.gibbonActionID=action.gibbonActionID
  );end";
$count++;

// v1.1.0
$sql[$count][0] = "1.1.0";
$sql[$count][1] = "CREATE TABLE IF NOT EXISTS `attendance_follow_up` (
    `followUpID` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonPersonIDStudent` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonSchoolYearID` INT(3) UNSIGNED ZEROFILL NOT NULL,
    `gibbonFormGroupID` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `reasonCode` VARCHAR(30) NOT NULL,
    `sourceDateStart` DATE NOT NULL,
    `sourceDateEnd` DATE NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'open',
    `gibbonPersonIDOwner` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonPersonIDCreator` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `dueDate` DATE NOT NULL,
    `resolutionCode` VARCHAR(30) DEFAULT NULL,
    `activeKey` CHAR(64) DEFAULT NULL,
    `revision` INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `retentionUntil` DATE NOT NULL,
    `resolvedAt` DATETIME DEFAULT NULL,
    `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updatedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `gibbonPersonIDLastEdit` INT(10) UNSIGNED ZEROFILL NOT NULL,
    PRIMARY KEY (`followUpID`),
    UNIQUE KEY `activeKey` (`activeKey`),
    KEY `studentYear` (`gibbonPersonIDStudent`, `gibbonSchoolYearID`),
    KEY `ownerStatus` (`gibbonPersonIDOwner`, `status`),
    KEY `dueDate` (`dueDate`),
    KEY `retentionUntil` (`retentionUntil`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;end
CREATE TABLE IF NOT EXISTS `attendance_follow_up_note` (
    `noteID` INT(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `followUpID` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonPersonIDAuthor` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `noteText` TEXT NOT NULL,
    `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`noteID`),
    KEY `followUpID` (`followUpID`),
    CONSTRAINT `attendanceFollowUpNoteCase`
      FOREIGN KEY (`followUpID`) REFERENCES `attendance_follow_up` (`followUpID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;end
CREATE TABLE IF NOT EXISTS `attendance_follow_up_event` (
    `eventID` INT(12) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `followUpID` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonPersonIDActor` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `eventType` VARCHAR(40) NOT NULL,
    `fieldName` VARCHAR(40) DEFAULT NULL,
    `oldValue` VARCHAR(255) DEFAULT NULL,
    `newValue` VARCHAR(255) DEFAULT NULL,
    `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`eventID`),
    KEY `followUpID` (`followUpID`),
    CONSTRAINT `attendanceFollowUpEventCase`
      FOREIGN KEY (`followUpID`) REFERENCES `attendance_follow_up` (`followUpID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;end
CREATE TABLE IF NOT EXISTS `attendance_follow_up_retention_log` (
    `purgeID` INT(10) UNSIGNED ZEROFILL NOT NULL AUTO_INCREMENT,
    `gibbonPersonIDActor` INT(10) UNSIGNED ZEROFILL NOT NULL,
    `gibbonSchoolYearID` INT(3) UNSIGNED ZEROFILL NOT NULL,
    `caseCount` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `noteCount` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `eventCount` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `purgedAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`purgeID`),
    KEY `schoolYear` (`gibbonSchoolYearID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;end";
$count++;

// v1.0.3
$sql[$count][0] = "1.0.3";
$sql[$count][1] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsAbsence', 'Absence Keywords', 'Comma-separated attendance type keywords for absence and personal leave.', '旷课,事假,Absent,Unexcused absence' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsAbsence');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsSuspension', 'Suspension Keywords', 'Comma-separated attendance type keywords for suspension.', '被停课,Suspended' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsSuspension');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsLate', 'Late Keywords', 'Comma-separated attendance type keywords for lateness.', '迟到,Late' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsLate');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsLeftEarly', 'Left Early Keywords', 'Comma-separated attendance type keywords for leaving early.', '早退,Left early' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsLeftEarly');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsSickLeave', 'Sick Leave Keywords', 'Comma-separated attendance type keywords for sick leave.', '病假,Sick leave' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsSickLeave');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsAuthorizedAbsence', 'Authorized Absence Keywords', 'Comma-separated attendance type keywords for authorized absence.', '公假,Authorized absence' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsAuthorizedAbsence');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsPresent', 'Present Keywords', 'Comma-separated attendance type keywords for attendance.', '出勤,Present' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsPresent');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'keywordsNoRecord', 'No Record Keywords', 'Comma-separated attendance type keywords for no record.', '无记录,No record' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='keywordsNoRecord');end";
$count++;

// v1.0.2
$sql[$count][0] = "1.0.2";
$sql[$count][1] = "INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreAbsence', 'Absence Score', 'Score for unexcused or personal absence.', '0' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreAbsence');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreSuspension', 'Suspension Score', 'Score for a suspension.', '0' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreSuspension');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreLate', 'Late Score', 'Score for lateness.', '0.7' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreLate');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreLeftEarly', 'Left Early Score', 'Score for leaving early.', '0.7' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreLeftEarly');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreSickLeave', 'Sick Leave Score', 'Score for sick leave.', '0.5' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreSickLeave');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreAuthorizedAbsence', 'Authorized Absence Score', 'Score for an authorized absence.', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreAuthorizedAbsence');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scorePresent', 'Present Score', 'Score for attendance.', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scorePresent');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreNoRecord', 'No Record Score', 'Score for an attendance type marked as no record.', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreNoRecord');end
INSERT INTO gibbonSetting (scope, name, nameDisplay, description, value) SELECT 'Attendance Summary', 'scoreUnknown', 'Unknown Type Score', 'Fallback score when an attendance type does not match a configured category.', '1' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM gibbonSetting WHERE scope='Attendance Summary' AND name='scoreUnknown');end";
$count++;

// v0.0.00
// $sql[$count][0] = "0.0.00";
// $sql[$count][1] = "-- First version, nothing to update";
