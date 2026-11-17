<?php
/**
 * Shared Ghana leave calendar.
 * Public holidays are stored in public_holidays and managed by administrators.
 */
function leave_working_days(PDO $db, string $start, string $end): int
{
    $holidayStmt = $db->prepare(
        "SELECT holiday_date::text
         FROM public_holidays
         WHERE holiday_date BETWEEN :start_date AND :end_date"
    );
    $holidayStmt->execute(['start_date' => $start, 'end_date' => $end]);
    $holidays = array_fill_keys($holidayStmt->fetchAll(PDO::FETCH_COLUMN), true);

    $count = 0;
    $date = new DateTimeImmutable($start);
    $last = new DateTimeImmutable($end);

    while ($date <= $last) {
        $dateString = $date->format('Y-m-d');
        if ((int) $date->format('N') <= 5 && !isset($holidays[$dateString])) {
            $count++;
        }
        $date = $date->modify('+1 day');
    }

    return $count;
}

/** Recalculate saved leave days whenever an administrator changes the holiday calendar. */
function refresh_leave_request_days(PDO $db): void
{
    $requests = $db->query(
        "SELECT leave_id, start_date::text AS start_date, end_date::text AS end_date
         FROM leave_requests"
    )->fetchAll(PDO::FETCH_ASSOC);

    $update = $db->prepare(
        "UPDATE leave_requests SET working_days = :working_days WHERE leave_id = :leave_id"
    );

    foreach ($requests as $request) {
        $days = leave_working_days($db, $request['start_date'], $request['end_date']);
        $update->execute([
            'working_days' => $days,
            'leave_id' => $request['leave_id'],
        ]);
    }
}
