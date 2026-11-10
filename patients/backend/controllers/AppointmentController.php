<?php
declare(strict_types=1);

final class AppointmentController
{
    private const SLOT_TIMES = [
        '08:30 AM', '09:15 AM', '10:00 AM', '10:45 AM', '11:30 AM',
        '01:15 PM', '02:00 PM', '02:45 PM', '03:30 PM', '04:15 PM',
    ];

    public static function availability(): never
    {
        require_method('GET');
        $doctorId = trim((string)($_GET['doctor_id'] ?? ''));
        $date = trim((string)($_GET['date'] ?? ''));
        if ($doctorId === '' || strlen($doctorId) > 48 || !valid_date($date)) {
            respond(422, false, 'A valid doctor and appointment date are required.');
        }
        if ($date <= date('Y-m-d')) {
            respond(200, true, 'No appointments are available for a past or same-day date.', ['slots' => []]);
        }

        global $pdo;
        $doctor = self::findDoctor($doctorId);
        $schedules = $doctor ? self::schedulesForDate($doctor['staff_id'], $date) : [];
        if (!$doctor || !$doctor['available'] || !$doctor['bookable'] || !$schedules) {
            respond(200, true, 'No appointments are available for this doctor on that date.', ['slots' => []]);
        }
        $statement = $pdo->prepare(
            "SELECT to_char(scheduled_at, 'HH12:MI AM') AS appointment_time
             FROM appointments
             WHERE doctor_staff_id = ? AND scheduled_at::date = ? AND status IN ('pending', 'confirmed')"
        );
        $statement->execute([(int)$doctor['staff_id'], $date]);
        $booked = array_column($statement->fetchAll(), 'appointment_time');
        $slots = [];
        foreach (self::SLOT_TIMES as $label) {
            $time = DateTimeImmutable::createFromFormat('!h:i A', $label);
            if (!$time || in_array($label, $booked, true)) {
                continue;
            }
            foreach ($schedules as $schedule) {
                if ($time->format('H:i:s') >= $schedule['work_start'] && $time->format('H:i:s') < $schedule['work_end']) {
                    $slots[] = $label;
                    break;
                }
            }
        }
        respond(200, true, 'Available appointment times loaded.', ['slots' => $slots]);
    }

    public static function create(): never
    {
        require_method('POST');
        $patientId = require_patient();
        require_csrf();
        $data = request_data();
        $departmentId = required_string($data, 'departmentId', 48, 'Department');
        $doctorId = required_string($data, 'doctorId', 48, 'Doctor');
        $date = required_string($data, 'date', 10, 'Appointment date');
        $timeLabel = required_string($data, 'time', 12, 'Appointment time');
        $visitType = $data['visitType'] ?? '';
        $reason = required_string($data, 'reason', 500, 'Reason for visit');
        $notes = $data['notes'] ?? '';

        if ($departmentId === '' || strlen($departmentId) > 48 || $doctorId === '' || strlen($doctorId) > 48) {
            respond(422, false, 'Choose a valid department and doctor.');
        }
        if (!valid_date($date) || $date <= date('Y-m-d')) {
            respond(422, false, 'Please choose a valid future appointment date.', null, ['date' => 'Appointments must be booked for a future date.']);
        }
        if (!in_array($visitType, ['in-person', 'followup', 'diagnostic'], true)) {
            respond(422, false, 'Please select a valid visit type.', null, ['visitType' => 'Invalid visit type.']);
        }
        if (!in_array($timeLabel, self::SLOT_TIMES, true)) {
            respond(422, false, 'Please select an available appointment time.');
        }
        if (!is_string($notes) || mb_strlen($notes) > 5000) {
            respond(422, false, 'Additional notes must be 5000 characters or fewer.', null, ['notes' => 'Additional notes are too long.']);
        }
        if (($data['termsConsent'] ?? false) !== true) {
            respond(422, false, 'Please accept the booking consent to continue.', null, ['termsConsent' => 'Consent is required.']);
        }

        global $pdo;
        $doctor = self::findDoctor($doctorId);
        $schedules = $doctor ? self::schedulesForDate($doctor['staff_id'], $date) : [];
        $time = DateTimeImmutable::createFromFormat('!h:i A', $timeLabel);
        $fitsSchedule = $time && self::timeFitsSchedules($time->format('H:i:s'), $schedules);
        if (!$doctor || $doctor['department_id'] !== $departmentId || !$doctor['available'] || !$doctor['bookable']) {
            respond(422, false, 'The selected doctor is not available in that department.');
        }
        if (!$fitsSchedule) {
            respond(422, false, 'The selected time is outside the doctor schedule.', null, ['time' => 'Choose an available time.']);
        }

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT staff_id FROM staff_profiles WHERE staff_id = ? FOR UPDATE');
            $lock->execute([(int)$doctor['staff_id']]);
            $conflict = $pdo->prepare(
                "SELECT appointment_id FROM appointments
                 WHERE doctor_staff_id = ? AND scheduled_at = ?::timestamptz
                   AND status IN ('pending', 'confirmed') LIMIT 1"
            );
            $scheduledAt = $date . ' ' . $time->format('H:i:s');
            $conflict->execute([(int)$doctor['staff_id'], $scheduledAt]);
            if ($conflict->fetch()) {
                $pdo->rollBack();
                respond(409, false, 'That appointment time was just booked. Please choose another time.');
            }

            $reference = 'APT-' . strtoupper(bin2hex(random_bytes(10)));
            $insert = $pdo->prepare(
                "INSERT INTO appointments
                    (appointment_number, patient_id, department_id, doctor_staff_id, scheduled_at,
                     status, visit_type, reason, notes, consented_at, created_by)
                 VALUES (?, ?, ?, ?, ?::timestamptz, 'confirmed', ?, ?, ?, CURRENT_TIMESTAMP, ?)
                 RETURNING appointment_id"
            );
            $insert->execute([
                $reference, $patientId, (int)$doctor['department_pk'], (int)$doctor['staff_id'], $scheduledAt,
                $visitType, $reason, trim($notes) === '' ? null : trim($notes), (int)($_SESSION['user_id'] ?? 0),
            ]);
            $appointmentId = (int)$insert->fetchColumn();

            $queueNumber = self::nextQueueNumber($pdo, (int)$doctor['department_pk'], $date);
            $ticket = 'Q' . str_pad((string)$queueNumber, 3, '0', STR_PAD_LEFT);
            $queueInsert = $pdo->prepare(
                "INSERT INTO queue_tickets
                    (appointment_id, patient_id, department_id, doctor_staff_id, ticket_number, queue_number,
                     queue_date, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'waiting', ?)
                 RETURNING queue_ticket_id"
            );
            $queueInsert->execute([
                $appointmentId, $patientId, (int)$doctor['department_pk'], (int)$doctor['staff_id'], $ticket,
                $queueNumber, $date, (int)($_SESSION['user_id'] ?? 0),
            ]);
            $queueId = (int)$queueInsert->fetchColumn();
            $event = $pdo->prepare(
                "INSERT INTO queue_ticket_events (queue_ticket_id, new_status, changed_by, note)
                 VALUES (?, 'waiting', ?, 'Patient appointment booked')"
            );
            $event->execute([$queueId, (int)($_SESSION['user_id'] ?? 0)]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23505') {
                respond(409, false, 'That appointment time is no longer available.');
            }
            throw $exception;
        }

        respond(201, true, 'Appointment booked.', [
            'reference' => $reference,
            'appointmentDate' => $date,
            'appointmentTime' => $timeLabel,
            'status' => 'confirmed',
            'queueNumber' => $queueNumber,
            'doctor' => $doctor['name'],
            'department' => $doctor['department_name'],
            'room' => $doctor['room'],
        ]);
    }

    public static function index(): never
    {
        require_method('GET');
        $patientId = require_patient();
        global $pdo;
        $statement = $pdo->prepare(
            "SELECT a.appointment_id::text AS id, a.appointment_number AS reference_token,
                    a.scheduled_at::date::text AS appointment_date,
                    to_char(a.scheduled_at, 'HH12:MI AM') AS appointment_time, a.visit_type,
                    a.reason, a.notes, a.status, COALESCE(s.public_slug, s.staff_id::text, '') AS doctor_id,
                    COALESCE(s.full_name, 'Unassigned') AS doctor_name, COALESCE(s.room, '') AS room,
                    COALESCE(d.public_slug, d.department_id::text) AS department_id, d.department_name AS department_name,
                    q.queue_number, CASE q.status WHEN 'called' THEN 'in_progress'
                        WHEN 'in_consultation' THEN 'in_progress' WHEN 'served' THEN 'completed'
                        ELSE q.status END AS queue_status
             FROM appointments a
             LEFT JOIN staff_profiles s ON s.staff_id = a.doctor_staff_id
             JOIN departments d ON d.department_id = a.department_id
             LEFT JOIN queue_tickets q ON q.appointment_id = a.appointment_id
             WHERE a.patient_id = ?
             ORDER BY a.scheduled_at DESC LIMIT 100"
        );
        $statement->execute([$patientId]);
        respond(200, true, 'Appointments loaded.', $statement->fetchAll());
    }

    public static function show(): never
    {
        require_method('GET');
        $patientId = require_patient();
        $reference = trim((string)($_GET['reference'] ?? ''));
        if ($reference === '' || strlen($reference) > 30) {
            respond(422, false, 'A valid appointment reference is required.');
        }
        global $pdo;
        $statement = $pdo->prepare(
            "SELECT a.appointment_number AS reference_token,
                    a.scheduled_at::date::text AS appointment_date,
                    to_char(a.scheduled_at, 'HH12:MI AM') AS appointment_time, a.visit_type,
                    a.reason, a.notes, a.status, COALESCE(s.full_name, 'Unassigned') AS doctor_name,
                    COALESCE(s.room, '') AS room, d.department_name AS department_name
             FROM appointments a LEFT JOIN staff_profiles s ON s.staff_id = a.doctor_staff_id
             JOIN departments d ON d.department_id = a.department_id
             WHERE a.patient_id = ? AND a.appointment_number = ?"
        );
        $statement->execute([$patientId, $reference]);
        $appointment = $statement->fetch();
        if (!$appointment) {
            respond(404, false, 'Appointment not found.');
        }
        respond(200, true, 'Appointment loaded.', $appointment);
    }

    public static function cancel(): never
    {
        require_method('POST');
        $patientId = require_patient();
        require_csrf();
        $reference = required_string(request_data(), 'reference', 30, 'Appointment reference');
        global $pdo;
        $pdo->beginTransaction();
        $find = $pdo->prepare(
            "SELECT q.queue_ticket_id, q.status FROM appointments a
             LEFT JOIN queue_tickets q ON q.appointment_id = a.appointment_id
             WHERE a.patient_id = ? AND a.appointment_number = ? AND a.status IN ('pending', 'confirmed')
             FOR UPDATE OF a"
        );
        $find->execute([$patientId, $reference]);
        $queue = $find->fetch();
        $update = $pdo->prepare(
            "UPDATE appointments SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
             WHERE patient_id = ? AND appointment_number = ? AND status IN ('pending', 'confirmed')"
        );
        $update->execute([$patientId, $reference]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            respond(404, false, 'Active appointment not found.');
        }
        if ($queue && $queue['queue_ticket_id'] !== null) {
            $queueUpdate = $pdo->prepare(
                "UPDATE queue_tickets SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP
                 WHERE queue_ticket_id = ?"
            );
            $queueUpdate->execute([$queue['queue_ticket_id']]);
            $event = $pdo->prepare(
                "INSERT INTO queue_ticket_events (queue_ticket_id, old_status, new_status, changed_by, note)
                 VALUES (?, ?, 'cancelled', ?, 'Patient cancelled appointment')"
            );
            $event->execute([$queue['queue_ticket_id'], $queue['status'], (int)($_SESSION['user_id'] ?? 0)]);
        }
        $pdo->commit();
        respond(200, true, 'Appointment cancelled.');
    }

    public static function reschedule(): never
    {
        require_method('POST');
        $patientId = require_patient();
        require_csrf();
        $data = request_data();
        $reference = required_string($data, 'reference', 30, 'Appointment reference');
        $date = required_string($data, 'date', 10, 'Appointment date');
        $timeLabel = required_string($data, 'time', 12, 'Appointment time');
        if (!valid_date($date) || $date <= date('Y-m-d') || !in_array($timeLabel, self::SLOT_TIMES, true)) {
            respond(422, false, 'Choose a valid future date and available time.');
        }

        global $pdo;
        $existing = $pdo->prepare(
            "SELECT a.appointment_id, a.doctor_staff_id, a.department_id,
                    s.availability,
                    CASE WHEN d.is_bookable THEN 'true' ELSE 'false' END AS is_bookable,
                    d.status AS department_status
             FROM appointments a JOIN staff_profiles s ON s.staff_id = a.doctor_staff_id
             JOIN departments d ON d.department_id = a.department_id
             WHERE a.patient_id = ? AND a.appointment_number = ? AND a.status IN ('pending', 'confirmed')"
        );
        $existing->execute([$patientId, $reference]);
        $appointment = $existing->fetch();
        if (!$appointment) {
            respond(404, false, 'Active appointment not found.');
        }
        $doctorId = (string)$appointment['doctor_staff_id'];
            $schedules = self::schedulesForDate($doctorId, $date);
        $time = DateTimeImmutable::createFromFormat('!h:i A', $timeLabel);
        if ($appointment['availability'] !== 'on_duty' || !filter_var($appointment['is_bookable'], FILTER_VALIDATE_BOOLEAN) ||
            $appointment['department_status'] !== 'active' || !$time || !self::timeFitsSchedules($time->format('H:i:s'), $schedules)) {
            respond(422, false, 'The selected time is outside the doctor schedule.');
        }

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT staff_id FROM staff_profiles WHERE staff_id = ? FOR UPDATE');
            $lock->execute([(int)$doctorId]);
            $conflict = $pdo->prepare(
                "SELECT appointment_id FROM appointments
                 WHERE doctor_staff_id = ? AND scheduled_at = ?::timestamptz
                   AND status IN ('pending', 'confirmed') AND appointment_id <> ? LIMIT 1"
            );
            $scheduledAt = $date . ' ' . $time->format('H:i:s');
            $conflict->execute([(int)$doctorId, $scheduledAt, $appointment['appointment_id']]);
            if ($conflict->fetch()) {
                $pdo->rollBack();
                respond(409, false, 'That appointment time is already booked.');
            }
            $update = $pdo->prepare(
                'UPDATE appointments SET scheduled_at = ?::timestamptz, updated_at = CURRENT_TIMESTAMP WHERE appointment_id = ?'
            );
            $update->execute([$scheduledAt, $appointment['appointment_id']]);

            $queueFind = $pdo->prepare('SELECT queue_ticket_id, status FROM queue_tickets WHERE appointment_id = ? FOR UPDATE');
            $queueFind->execute([$appointment['appointment_id']]);
            $queue = $queueFind->fetch();
            $queueNumber = null;
            if ($queue) {
                $queueNumber = self::nextQueueNumber($pdo, (int)$appointment['department_id'], $date);
                $ticket = 'Q' . str_pad((string)$queueNumber, 3, '0', STR_PAD_LEFT);
                $queueUpdate = $pdo->prepare(
                    "UPDATE queue_tickets SET queue_date = ?, queue_number = ?, ticket_number = ?,
                         status = 'waiting', arrived_at = CURRENT_TIMESTAMP, called_at = NULL,
                         service_started_at = NULL, served_at = NULL, updated_at = CURRENT_TIMESTAMP
                     WHERE queue_ticket_id = ?"
                );
                $queueUpdate->execute([$date, $queueNumber, $ticket, $queue['queue_ticket_id']]);
                $event = $pdo->prepare(
                    "INSERT INTO queue_ticket_events (queue_ticket_id, old_status, new_status, changed_by, note)
                     VALUES (?, ?, 'waiting', ?, 'Patient rescheduled appointment')"
                );
                $event->execute([$queue['queue_ticket_id'], $queue['status'], (int)($_SESSION['user_id'] ?? 0)]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23505') {
                respond(409, false, 'That appointment time is already booked.');
            }
            throw $exception;
        }
        respond(200, true, 'Appointment rescheduled.', ['date' => $date, 'time' => $timeLabel, 'queueNumber' => $queueNumber]);
    }

    private static function findDoctor(string $doctorId): array|false
    {
        global $pdo;
        $statement = $pdo->prepare(
            "SELECT COALESCE(s.public_slug, s.staff_id::text) AS id, s.staff_id::text AS staff_id,
                    COALESCE(d.public_slug, d.department_id::text) AS department_id,
                    d.department_id::text AS department_pk,
                    s.room,
                    CASE WHEN s.availability = 'on_duty' AND u.is_active THEN 'true' ELSE 'false' END AS available,
                    d.department_name AS department_name,
                    CASE WHEN d.is_bookable THEN 'true' ELSE 'false' END AS is_bookable,
                    COALESCE((SELECT MIN(work_start) FROM doctor_schedules WHERE staff_id = s.staff_id AND is_active), TIME '00:00') AS work_start,
                    COALESCE((SELECT MAX(work_end) FROM doctor_schedules WHERE staff_id = s.staff_id AND is_active), TIME '00:00') AS work_end
             FROM staff_profiles s JOIN users u ON u.user_id = s.user_id
             JOIN roles r ON r.role_id = u.role_id AND r.role_name = 'doctor'
             JOIN departments d ON d.department_id = s.department_id
             WHERE (s.public_slug = ? OR s.staff_id::text = ?) AND s.public_profile AND d.status = 'active'"
        );
        $statement->execute([$doctorId, $doctorId]);
        $doctor = $statement->fetch();
        if (!$doctor) {
            return false;
        }
        $doctor['available'] = filter_var($doctor['available'], FILTER_VALIDATE_BOOLEAN);
        $doctor['bookable'] = filter_var($doctor['is_bookable'], FILTER_VALIDATE_BOOLEAN);
        return $doctor;
    }

    private static function schedulesForDate(string $doctorId, string $date): array
    {
        if (!ctype_digit($doctorId) || !valid_date($date)) {
            return [];
        }
        global $pdo;
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dateValue) {
            return [];
        }
        $statement = $pdo->prepare(
            'SELECT work_start::text AS work_start, work_end::text AS work_end
             FROM doctor_schedules WHERE staff_id = ? AND day_of_week = ? AND is_active ORDER BY work_start'
        );
        $statement->execute([(int)$doctorId, (int)$dateValue->format('w')]);
        return $statement->fetchAll();
    }

    private static function timeFitsSchedules(string $time, array $schedules): bool
    {
        foreach ($schedules as $schedule) {
            if ($time >= $schedule['work_start'] && $time < $schedule['work_end']) {
                return true;
            }
        }
        return false;
    }

    private static function nextQueueNumber(PDO $pdo, int $departmentId, string $date): int
    {
        // Match the staff walk-in ticket flow's transaction lock and Q### format.
        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute([$departmentId . ':' . $date]);
        $statement = $pdo->prepare(
            'SELECT COUNT(*) + 1 FROM queue_tickets WHERE department_id = ? AND queue_date = ?'
        );
        $statement->execute([$departmentId, $date]);
        return (int)$statement->fetchColumn();
    }
}
