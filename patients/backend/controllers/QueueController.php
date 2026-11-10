<?php
declare(strict_types=1);

final class QueueController
{
    public static function board(): never
    {
        require_method('GET');
        global $pdo;
        $statement = $pdo->query(
            "SELECT d.department_id::text AS department_id, d.department_name AS department_name, d.floor,
                    COUNT(q.queue_ticket_id) FILTER (WHERE q.status IN ('waiting', 'called', 'in_consultation')) AS active_count,
                    MAX(COALESCE(q.queue_number, NULLIF(regexp_replace(q.ticket_number, '[^0-9]', '', 'g'), '')::integer))
                        FILTER (WHERE q.status IN ('called', 'in_consultation')) AS now_serving
             FROM departments d
             LEFT JOIN queue_tickets q ON q.department_id = d.department_id AND q.queue_date = CURRENT_DATE
             WHERE d.status = 'active'
             GROUP BY d.department_id, d.department_name, d.floor
             ORDER BY d.department_name"
        );
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['active_count'] = (int)$row['active_count'];
            $row['now_serving'] = $row['now_serving'] === null ? null : (int)$row['now_serving'];
        }
        unset($row);
        respond(200, true, 'Current queue overview loaded.', $rows);
    }

    public static function lookup(): never
    {
        require_method('POST');
        $patientId = require_patient();
        $data = request_data();
        $reference = $data['reference'] ?? null;
        if (!is_string($reference)) {
            respond(422, false, 'Enter a valid appointment reference.');
        }
        $reference = strtoupper(trim($reference));
        if ($reference === '' || strlen($reference) > 30) {
            respond(422, false, 'Enter a valid appointment reference.');
        }
        global $pdo;
        $statement = $pdo->prepare(
            "SELECT COALESCE(a.appointment_number, q.ticket_number) AS reference_token,
                    COALESCE(q.queue_number, NULLIF(regexp_replace(q.ticket_number, '[^0-9]', '', 'g'), '')::integer) AS queue_number,
                    CASE q.status WHEN 'called' THEN 'in_progress' WHEN 'in_consultation' THEN 'in_progress'
                        WHEN 'served' THEN 'completed' ELSE q.status END AS queue_status,
                    COALESCE(a.scheduled_at::date::text, q.queue_date::text) AS appointment_date,
                    COALESCE(to_char(a.scheduled_at, 'HH12:MI AM'), 'Walk-in') AS appointment_time,
                    COALESCE(a.status, 'walk_in') AS appointment_status,
                    COALESCE(s.full_name, 'Staff queue') AS doctor_name,
                    COALESCE(s.room, '') AS room, d.department_name AS department_name,
                    q.queue_ticket_id, q.department_id::text AS department_id,
                    COALESCE(q.doctor_staff_id, a.doctor_staff_id)::text AS doctor_id
             FROM queue_tickets q
             JOIN patients p ON p.patient_id = q.patient_id
             LEFT JOIN appointments a ON a.appointment_id = q.appointment_id
             LEFT JOIN staff_profiles s ON s.staff_id = COALESCE(q.doctor_staff_id, a.doctor_staff_id)
             JOIN departments d ON d.department_id = q.department_id
             WHERE p.patient_id = ? AND (a.appointment_number = ? OR q.ticket_number = ?)"
        );
        $statement->execute([$patientId, $reference, $reference]);
        $queue = $statement->fetch();
        if (!$queue) {
            respond(404, false, 'No queue information was found for that reference.');
        }
        respond(200, true, 'Queue information loaded.', self::withPosition($pdo, $queue));
    }

    public static function status(): never
    {
        require_method('GET');
        $patientId = require_patient();
        global $pdo;
        $statement = $pdo->prepare(
            "SELECT COALESCE(a.appointment_number, q.ticket_number) AS reference_token,
                    COALESCE(q.queue_number, NULLIF(regexp_replace(q.ticket_number, '[^0-9]', '', 'g'), '')::integer) AS queue_number,
                    CASE q.status WHEN 'called' THEN 'in_progress' WHEN 'in_consultation' THEN 'in_progress'
                        WHEN 'served' THEN 'completed' ELSE q.status END AS queue_status,
                    COALESCE(a.scheduled_at::date::text, q.queue_date::text) AS appointment_date,
                    COALESCE(to_char(a.scheduled_at, 'HH12:MI AM'), 'Walk-in') AS appointment_time,
                    COALESCE(a.status, 'walk_in') AS appointment_status,
                    COALESCE(s.full_name, 'Staff queue') AS doctor_name,
                    COALESCE(s.room, '') AS room, d.department_name AS department_name,
                    q.queue_ticket_id, q.department_id::text AS department_id,
                    COALESCE(q.doctor_staff_id, a.doctor_staff_id)::text AS doctor_id
             FROM queue_tickets q
             JOIN patients p ON p.patient_id = q.patient_id
             LEFT JOIN appointments a ON a.appointment_id = q.appointment_id
             LEFT JOIN staff_profiles s ON s.staff_id = COALESCE(q.doctor_staff_id, a.doctor_staff_id)
             JOIN departments d ON d.department_id = q.department_id
             WHERE q.patient_id = ? AND p.is_active AND q.queue_date = CURRENT_DATE
               AND q.status IN ('waiting', 'called', 'in_consultation')
               AND (a.appointment_id IS NULL OR a.status IN ('pending', 'confirmed'))
             ORDER BY q.arrived_at LIMIT 1"
        );
        $statement->execute([$patientId]);
        $queue = $statement->fetch();
        respond(200, true, 'Queue status loaded.', ['queue' => $queue ? self::withPosition($pdo, $queue) : null]);
    }

    private static function withPosition(PDO $pdo, array $queue): array
    {
        $today = $queue['appointment_date'] === date('Y-m-d');
        $queue['queue_number'] = $queue['queue_number'] === null ? null : (int)$queue['queue_number'];
        $queue['patients_ahead'] = null;
        $queue['now_serving'] = null;
        if ($today && in_array($queue['queue_status'], ['waiting', 'in_progress'], true) && $queue['queue_number'] !== null) {
            $position = $pdo->prepare(
                "SELECT COUNT(*) FROM queue_tickets q
                 LEFT JOIN appointments a ON a.appointment_id = q.appointment_id
                 WHERE q.department_id = ? AND q.queue_date = CURRENT_DATE
                   AND COALESCE(q.queue_number, NULLIF(regexp_replace(q.ticket_number, '[^0-9]', '', 'g'), '')::integer) < ?
                   AND q.status IN ('waiting', 'called', 'in_consultation')
                   AND (a.appointment_id IS NULL OR a.status IN ('pending', 'confirmed'))"
            );
            $position->execute([(int)$queue['department_id'], $queue['queue_number']]);
            $queue['patients_ahead'] = (int)$position->fetchColumn();

            $serving = $pdo->prepare(
                "SELECT MAX(COALESCE(queue_number, NULLIF(regexp_replace(ticket_number, '[^0-9]', '', 'g'), '')::integer))
                 FROM queue_tickets WHERE department_id = ? AND queue_date = CURRENT_DATE
                   AND status IN ('called', 'in_consultation')"
            );
            $serving->execute([(int)$queue['department_id']]);
            $number = $serving->fetchColumn();
            $queue['now_serving'] = $number === null ? null : (int)$number;
        }
        unset($queue['queue_ticket_id'], $queue['department_id'], $queue['doctor_id']);
        $queue['is_today'] = $today;
        return $queue;
    }
}
