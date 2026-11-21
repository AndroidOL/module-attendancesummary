<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
*/

use Gibbon\Services\Format;

function attendanceFollowUpStatuses(): array
{
    return [
        'open'        => attendanceT('Open'),
        'in_progress' => attendanceT('In Progress'),
        'resolved'    => attendanceT('Resolved'),
    ];
}

function attendanceFollowUpReasons(): array
{
    return [
        'no_record'      => attendanceT('No Record'),
        'low_attendance' => attendanceT('Low Attendance'),
    ];
}

function attendanceFollowUpResolutionOptions(): array
{
    return [
        'record_corrected' => attendanceT('Attendance Record Corrected'),
        'contact_completed'=> attendanceT('Contact Completed'),
        'monitoring'       => attendanceT('Monitoring'),
        'escalated'        => attendanceT('Escalated'),
        'no_further_action'=> attendanceT('No Further Action'),
    ];
}

function attendanceFollowUpStatusAllowsTransition(string $from, string $to): bool
{
    $transitions = [
        'open'        => ['in_progress', 'resolved'],
        'in_progress' => ['open', 'resolved'],
        'resolved'    => ['open'],
    ];

    return isset($transitions[$from]) && in_array($to, $transitions[$from], true);
}

function attendanceFollowUpIsActiveStatus(string $status): bool
{
    return in_array($status, ['open', 'in_progress'], true);
}

function attendanceFollowUpIsValidDate($value): bool
{
    $value = is_scalar($value) ? trim((string) $value) : '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value;
}

function attendanceFollowUpDefaultDueDate(?DateTimeImmutable $today = null): string
{
    $date = $today ? $today : new DateTimeImmutable('today');
    $added = 0;
    while ($added < 5) {
        $date = $date->modify('+1 day');
        if (!in_array($date->format('N'), ['6', '7'], true)) {
            $added++;
        }
    }

    return $date->format('Y-m-d');
}

function attendanceFollowUpRetentionUntil($schoolYearLastDay): ?string
{
    if (!attendanceFollowUpIsValidDate($schoolYearLastDay)) {
        return null;
    }

    return (new DateTimeImmutable((string) $schoolYearLastDay))->modify('+12 months')->format('Y-m-d');
}

function attendanceFollowUpActiveKey($studentID, $schoolYearID, string $reasonCode, string $dateStart, string $dateEnd): string
{
    return hash('sha256', implode('|', [
        (string) $studentID,
        (string) $schoolYearID,
        $reasonCode,
        $dateStart,
        $dateEnd,
    ]));
}

function attendanceFollowUpCurrentEnrolment($pdo, $studentID, $schoolYearID): ?array
{
    $enrolment = $pdo->selectOne(
        "SELECT enrolment.gibbonFormGroupID, formGroup.name AS formGroupName,
                formGroup.gibbonPersonIDTutor, formGroup.gibbonPersonIDTutor2, formGroup.gibbonPersonIDTutor3,
                person.title, person.preferredName, person.surname, person.studentID, person.username, person.status
         FROM gibbonStudentEnrolment AS enrolment
         JOIN gibbonFormGroup AS formGroup
           ON formGroup.gibbonFormGroupID=enrolment.gibbonFormGroupID
         JOIN gibbonPerson AS person
           ON person.gibbonPersonID=enrolment.gibbonPersonID
         WHERE enrolment.gibbonPersonID=:studentID
           AND enrolment.gibbonSchoolYearID=:schoolYearID
         LIMIT 1",
        ['studentID' => $studentID, 'schoolYearID' => $schoolYearID]
    );

    return empty($enrolment) ? null : $enrolment;
}

function attendanceFollowUpStudentIsFull(array $enrolment): bool
{
    return ($enrolment['status'] ?? '') === 'Full';
}

function attendanceFollowUpIsFormTutor(array $enrolment, $personID): bool
{
    return in_array((string) $personID, [
        (string) ($enrolment['gibbonPersonIDTutor'] ?? ''),
        (string) ($enrolment['gibbonPersonIDTutor2'] ?? ''),
        (string) ($enrolment['gibbonPersonIDTutor3'] ?? ''),
    ], true);
}

function attendanceFollowUpCanAccessStudent($pdo, $personID, $studentID, $schoolYearID): bool
{
    if (attendanceCanViewAllClasses($personID)) {
        return true;
    }

    $enrolment = attendanceFollowUpCurrentEnrolment($pdo, $studentID, $schoolYearID);
    if (empty($enrolment) || !attendanceFollowUpStudentIsFull($enrolment)) {
        return false;
    }

    return attendanceFollowUpIsFormTutor($enrolment, $personID);
}

function attendanceFollowUpStudentOptions($pdo, $personID, $schoolYearID): array
{
    $allStudents = attendanceCanViewAllClasses($personID);
    $params = ['schoolYearID' => $schoolYearID];
    $tutorClause = $allStudents ? '' : "
        AND (
            formGroup.gibbonPersonIDTutor=:tutor1
            OR formGroup.gibbonPersonIDTutor2=:tutor2
            OR formGroup.gibbonPersonIDTutor3=:tutor3
        )";
    if (!$allStudents) {
        $params['tutor1'] = $personID;
        $params['tutor2'] = $personID;
        $params['tutor3'] = $personID;
    }

    $students = $pdo->select(
        "SELECT person.gibbonPersonID, person.title, person.preferredName, person.surname,
                formGroup.name AS formGroupName
         FROM gibbonStudentEnrolment AS enrolment
         JOIN gibbonPerson AS person
           ON person.gibbonPersonID=enrolment.gibbonPersonID
         JOIN gibbonFormGroup AS formGroup
           ON formGroup.gibbonFormGroupID=enrolment.gibbonFormGroupID
         WHERE enrolment.gibbonSchoolYearID=:schoolYearID
           AND person.status='Full'
           {$tutorClause}
         ORDER BY formGroup.nameShort, person.surname, person.preferredName",
        $params
    )->fetchAll();

    $options = [];
    foreach ($students as $student) {
        $name = Format::name(
            $student['title'],
            $student['preferredName'],
            $student['surname'],
            'Student',
            true
        );
        $options[(string) $student['gibbonPersonID']] = $name.' ('.$student['formGroupName'].')';
    }

    return $options;
}

function attendanceFollowUpOwnerOptions($pdo, $studentID, $schoolYearID, $currentPersonID, $currentOwnerID = ''): array
{
    $enrolment = attendanceFollowUpCurrentEnrolment($pdo, $studentID, $schoolYearID);
    $ownerIDs = array_values(array_unique(array_filter([
        (string) $currentPersonID,
        (string) $currentOwnerID,
        (string) ($enrolment['gibbonPersonIDTutor'] ?? ''),
        (string) ($enrolment['gibbonPersonIDTutor2'] ?? ''),
        (string) ($enrolment['gibbonPersonIDTutor3'] ?? ''),
    ], 'strlen')));
    if (empty($ownerIDs)) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($ownerIDs as $index => $ownerID) {
        $placeholders[] = ':owner'.$index;
        $params['owner'.$index] = $ownerID;
    }
    $owners = $pdo->select(
        "SELECT gibbonPersonID, title, preferredName, surname
         FROM gibbonPerson
         WHERE gibbonPersonID IN (".implode(',', $placeholders).")
           AND status='Full'
         ORDER BY surname, preferredName",
        $params
    )->fetchAll();

    $options = [];
    foreach ($owners as $owner) {
        $options[(string) $owner['gibbonPersonID']] = Format::name(
            $owner['title'],
            $owner['preferredName'],
            $owner['surname'],
            'Staff',
            true
        );
    }

    return $options;
}

function attendanceFollowUpOwnerIsEligible($pdo, $ownerID, $studentID, $schoolYearID, $currentPersonID, $currentOwnerID = ''): bool
{
    $ownerID = (string) $ownerID;
    if ($ownerID === '') {
        return false;
    }
    if ($ownerID === (string) $currentPersonID) {
        return attendanceFollowUpCanAccessStudent($pdo, $currentPersonID, $studentID, $schoolYearID);
    }
    if ($currentOwnerID !== '' && $ownerID === (string) $currentOwnerID) {
        return true;
    }

    $enrolment = attendanceFollowUpCurrentEnrolment($pdo, $studentID, $schoolYearID);

    return !empty($enrolment) && attendanceFollowUpIsFormTutor($enrolment, $ownerID);
}

function attendanceFollowUpLoadCase($pdo, $followUpID, $schoolYearID = null): ?array
{
    $yearClause = $schoolYearID ? ' AND followUp.gibbonSchoolYearID=:schoolYearID' : '';
    $params = ['followUpID' => $followUpID];
    if ($schoolYearID) {
        $params['schoolYearID'] = $schoolYearID;
    }
    $case = $pdo->selectOne(
        "SELECT followUp.*, student.title AS studentTitle, student.preferredName AS studentPreferredName,
                student.surname AS studentSurname, student.studentID,
                owner.title AS ownerTitle, owner.preferredName AS ownerPreferredName, owner.surname AS ownerSurname,
                creator.title AS creatorTitle, creator.preferredName AS creatorPreferredName, creator.surname AS creatorSurname,
                formGroup.name AS formGroupName
         FROM attendance_follow_up AS followUp
         JOIN gibbonPerson AS student ON student.gibbonPersonID=followUp.gibbonPersonIDStudent
         LEFT JOIN gibbonPerson AS owner ON owner.gibbonPersonID=followUp.gibbonPersonIDOwner
         LEFT JOIN gibbonPerson AS creator ON creator.gibbonPersonID=followUp.gibbonPersonIDCreator
         LEFT JOIN gibbonFormGroup AS formGroup ON formGroup.gibbonFormGroupID=followUp.gibbonFormGroupID
         WHERE followUp.followUpID=:followUpID
           AND followUp.retentionUntil>=CURRENT_DATE".$yearClause,
        $params
    );

    return empty($case) ? null : $case;
}

function attendanceFollowUpCanAccessCase($pdo, $personID, array $case): bool
{
    if (
        (string) ($case['gibbonPersonIDCreator'] ?? '') === (string) $personID
        || (string) ($case['gibbonPersonIDOwner'] ?? '') === (string) $personID
    ) {
        return true;
    }

    return attendanceFollowUpCanAccessStudent(
        $pdo,
        $personID,
        $case['gibbonPersonIDStudent'] ?? '',
        $case['gibbonSchoolYearID'] ?? ''
    );
}

function attendanceFollowUpRecordEvent(
    $pdo,
    $followUpID,
    $actorPersonID,
    string $eventType,
    ?string $fieldName = null,
    ?string $oldValue = null,
    ?string $newValue = null
): void {
    $pdo->insert(
        "INSERT INTO attendance_follow_up_event
         SET followUpID=:followUpID, actorPersonID=:actorPersonID, eventType=:eventType,
             fieldName=:fieldName, oldValue=:oldValue, newValue=:newValue",
        [
            'followUpID'   => $followUpID,
            'actorPersonID'=> $actorPersonID,
            'eventType'    => $eventType,
            'fieldName'    => $fieldName,
            'oldValue'     => $oldValue === null ? null : mb_substr($oldValue, 0, 255),
            'newValue'     => $newValue === null ? null : mb_substr($newValue, 0, 255),
        ]
    );
}

function attendanceFollowUpStatusLabel($status): string
{
    return attendanceFollowUpStatuses()[(string) $status] ?? (string) $status;
}

function attendanceFollowUpReasonLabel($reason): string
{
    return attendanceFollowUpReasons()[(string) $reason] ?? (string) $reason;
}

function attendanceFollowUpResolutionLabel($resolution): string
{
    if (empty($resolution)) {
        return '';
    }

    return attendanceFollowUpResolutionOptions()[(string) $resolution] ?? (string) $resolution;
}

function attendanceFollowUpWorklist($pdo, $personID, $schoolYearID, array $filters = []): array
{
    $selectedYearID = (string) ($filters['schoolYearID'] ?? $schoolYearID);
    $where = ['followUp.gibbonSchoolYearID=:schoolYearID', 'followUp.retentionUntil>=CURRENT_DATE'];
    $params = [
        'schoolYearID'       => $selectedYearID,
        'sessionSchoolYearID'=> $schoolYearID,
    ];
    $accessClause = '';

    if (!attendanceCanViewAllClasses($personID)) {
        $accessClause = "
          AND (
              followUp.gibbonPersonIDCreator=:creatorID
              OR followUp.gibbonPersonIDOwner=:ownerID
              OR formGroup.gibbonPersonIDTutor=:tutor1
              OR formGroup.gibbonPersonIDTutor2=:tutor2
              OR formGroup.gibbonPersonIDTutor3=:tutor3
          )";
        $params['creatorID'] = $personID;
        $params['ownerID'] = $personID;
        $params['tutor1'] = $personID;
        $params['tutor2'] = $personID;
        $params['tutor3'] = $personID;
    }

    $status = is_scalar($filters['status'] ?? null) ? (string) $filters['status'] : '';
    if ($status !== '' && isset(attendanceFollowUpStatuses()[$status])) {
        $where[] = 'followUp.status=:status';
        $params['status'] = $status;
    }

    $reason = is_scalar($filters['reason'] ?? null) ? (string) $filters['reason'] : '';
    if ($reason !== '' && isset(attendanceFollowUpReasons()[$reason])) {
        $where[] = 'followUp.reasonCode=:reason';
        $params['reason'] = $reason;
    }

    if (($filters['owner'] ?? '') === 'mine') {
        $where[] = 'followUp.gibbonPersonIDOwner=:ownerID';
        $params['ownerID'] = $personID;
    }

    if (!empty($filters['overdue'])) {
        $where[] = "followUp.dueDate<CURRENT_DATE AND followUp.status<>'resolved'";
    }

    $search = is_scalar($filters['search'] ?? null) ? trim((string) $filters['search']) : '';
    if ($search !== '') {
        $where[] = '(student.preferredName LIKE :searchName OR student.surname LIKE :searchSurname OR student.studentID LIKE :searchID)';
        $params['searchName'] = '%'.$search.'%';
        $params['searchSurname'] = '%'.$search.'%';
        $params['searchID'] = '%'.$search.'%';
    }

    return $pdo->select(
        "SELECT followUp.*, student.title AS studentTitle,
                student.preferredName AS studentPreferredName, student.surname AS studentSurname, student.studentID,
                owner.title AS ownerTitle, owner.preferredName AS ownerPreferredName, owner.surname AS ownerSurname,
                COALESCE(formGroup.name, caseFormGroup.name) AS formGroupName,
                (SELECT COUNT(*) FROM attendance_follow_up_note AS note
                 WHERE note.followUpID=followUp.followUpID) AS noteCount
         FROM attendance_follow_up AS followUp
         JOIN gibbonPerson AS student ON student.gibbonPersonID=followUp.gibbonPersonIDStudent
         LEFT JOIN gibbonPerson AS owner ON owner.gibbonPersonID=followUp.gibbonPersonIDOwner
         LEFT JOIN gibbonStudentEnrolment AS enrolment
           ON enrolment.gibbonPersonID=followUp.gibbonPersonIDStudent
          AND enrolment.gibbonSchoolYearID=:sessionSchoolYearID
         LEFT JOIN gibbonFormGroup AS formGroup
           ON formGroup.gibbonFormGroupID=enrolment.gibbonFormGroupID
         JOIN gibbonFormGroup AS caseFormGroup
           ON caseFormGroup.gibbonFormGroupID=followUp.gibbonFormGroupID
         WHERE ".implode(' AND ', $where).$accessClause."
         ORDER BY CASE followUp.status WHEN 'resolved' THEN 2 ELSE 0 END,
                  followUp.dueDate, followUp.updatedAt DESC",
        $params
    )->fetchAll();
}
