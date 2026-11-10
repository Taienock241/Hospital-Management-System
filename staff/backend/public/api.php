<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('hopewell_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Strict',
        'path' => '/',
    ]);
    session_start();
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function body(): array
{
    $decoded = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($decoded)) {
        respond(400, ['error' => 'Request body must be a JSON object.']);
    }
    return $decoded;
}

function requiredString(array $data, string $key, int $max = 200): string
{
    $value = trim((string)($data[$key] ?? ''));
    if ($value === '' || mb_strlen($value) > $max) {
        respond(422, ['error' => "{$key} is required and must be at most {$max} characters."]);
    }
    return $value;
}

function currentUser(): array
{
    if (empty($_SESSION['user'])) {
        respond(401, ['error' => 'Please sign in.']);
    }
    $stmt = database()->prepare("SELECT CASE WHEN is_active THEN 'true' ELSE 'false' END FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    if ($stmt->fetchColumn() !== 'true') {
        $_SESSION = [];
        session_destroy();
        respond(401, ['error' => 'This account is inactive.']);
    }
    return $_SESSION['user'];
}

function requireRole(array $roles): array
{
    $user = currentUser();
    if (!in_array($user['role'], $roles, true)) {
        respond(403, ['error' => 'You do not have permission to perform this action.']);
    }
    return $user;
}

function requireCsrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$provided || !hash_equals($_SESSION['csrf'] ?? '', $provided)) {
        respond(419, ['error' => 'Your session has expired. Refresh the page and try again.']);
    }
}

function audit(PDO $db, int $actorId, string $action, string $entity, ?int $entityId, ?array $old = null, ?array $new = null): void
{
    $stmt = $db->prepare('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, old_values, new_values) VALUES (?, ?, ?, ?, ?::jsonb, ?::jsonb)');
    $stmt->execute([
        $actorId,
        $action,
        $entity,
        $entityId,
        $old === null ? null : json_encode($old),
        $new === null ? null : json_encode($new),
    ]);
}

function idParam(string $value): int
{
    if (!ctype_digit($value) || (int)$value < 1) {
        respond(404, ['error' => 'Record not found.']);
    }
    return (int)$value;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = preg_replace('#^/api/?#', '', $path);
$segments = array_values(array_filter(explode('/', trim($route, '/')), 'strlen'));

try {
    $db = database();

    // Authentication and session endpoints.
    if ($segments === ['auth', 'login'] && $method === 'POST') {
        $input = body();
        $email = strtolower(requiredString($input, 'email', 254));
        $password = (string)($input['password'] ?? '');
        $stmt = $db->prepare('SELECT u.user_id, u.email, u.password_hash, r.role_name AS role, COALESCE(sp.full_name, p.full_name, u.email) AS name, d.department_name AS department FROM users u JOIN roles r ON r.role_id = u.role_id LEFT JOIN staff_profiles sp ON sp.user_id = u.user_id LEFT JOIN departments d ON d.department_id = sp.department_id LEFT JOIN patients p ON p.user_id = u.user_id WHERE LOWER(u.email) = LOWER(?) AND u.is_active = TRUE');
        $stmt->execute([$email]);
        $account = $stmt->fetch();
        if (!$account || !password_verify($password, $account['password_hash'])) {
            respond(401, ['error' => 'Invalid email or password.']);
        }
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$account['user_id'],
            'email' => $account['email'],
            'role' => $account['role'],
            'name' => $account['name'],
            'department' => $account['department'] ?? 'Hopewell Hospital',
        ];
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $db->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?')->execute([$account['user_id']]);
        respond(200, ['user' => $_SESSION['user'], 'csrfToken' => $_SESSION['csrf']]);
    }

    if ($segments === ['auth', 'me'] && $method === 'GET') {
        $user = currentUser();
        respond(200, ['user' => $user, 'csrfToken' => $_SESSION['csrf'] ?? null]);
    }

    if ($segments === ['auth', 'logout'] && $method === 'POST') {
        currentUser();
        requireCsrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        respond(200, ['message' => 'Signed out.']);
    }

    // Patient self-service reads are scoped to the profile linked to this login.
    if ($segments === ['patient', 'me'] && $method === 'GET') {
        $user = requireRole(['patient']);
        $stmt = $db->prepare('SELECT patient_id, patient_number, full_name, phone, gender, date_of_birth::text AS date_of_birth, blood_group FROM patients WHERE user_id = ? AND is_active');
        $stmt->execute([$user['id']]);
        $patient = $stmt->fetch();
        if (!$patient) respond(404, ['error' => 'No active patient profile is linked to this account.']);
        respond(200, ['item' => $patient]);
    }

    if ($segments === ['patient', 'appointments'] && $method === 'GET') {
        $user = requireRole(['patient']);
        $stmt = $db->prepare("SELECT a.appointment_number, a.scheduled_at, a.status, a.reason, d.department_name, COALESCE(s.full_name, 'Unassigned') AS doctor_name FROM appointments a JOIN patients p USING (patient_id) JOIN departments d USING (department_id) LEFT JOIN staff_profiles s ON s.staff_id = a.doctor_staff_id WHERE p.user_id = ? AND p.is_active ORDER BY a.scheduled_at DESC LIMIT 100");
        $stmt->execute([$user['id']]);
        respond(200, ['items' => $stmt->fetchAll()]);
    }

    if ($segments === ['patient', 'queue'] && $method === 'GET') {
        $user = requireRole(['patient']);
        $stmt = $db->prepare('SELECT q.ticket_number, q.queue_date, q.status, q.arrived_at, q.called_at, q.served_at, d.department_name FROM queue_tickets q JOIN patients p USING (patient_id) JOIN departments d USING (department_id) WHERE p.user_id = ? AND p.is_active ORDER BY q.arrived_at DESC LIMIT 50');
        $stmt->execute([$user['id']]);
        respond(200, ['items' => $stmt->fetchAll()]);
    }

    if ($segments === ['patient', 'appointments'] && $method === 'POST') {
        $user = requireRole(['patient']);
        requireCsrf();
        $input = body();
        $patientStmt = $db->prepare('SELECT patient_id FROM patients WHERE user_id = ? AND is_active');
        $patientStmt->execute([$user['id']]);
        $patientId = (int)$patientStmt->fetchColumn();
        if (!$patientId) respond(404, ['error' => 'No active patient profile is linked to this account.']);
        $departmentId = (int)($input['department_id'] ?? 0);
        $scheduled = requiredString($input, 'scheduled_at', 40);
        $futureCheck = $db->prepare("SELECT CASE WHEN ?::timestamptz >= CURRENT_TIMESTAMP THEN 'true' ELSE 'false' END");
        $futureCheck->execute([$scheduled]);
        if ($futureCheck->fetchColumn() !== 'true') respond(422, ['error' => 'Appointment must be scheduled in the future.']);
        $deptCheck = $db->prepare("SELECT 1 FROM departments WHERE department_id = ? AND status = 'active'");
        $deptCheck->execute([$departmentId]);
        if (!$deptCheck->fetchColumn()) respond(422, ['error' => 'Choose an active department.']);
        $doctorId = !empty($input['doctor_staff_id']) ? (int)$input['doctor_staff_id'] : null;
        if ($doctorId !== null) {
            $doctorCheck = $db->prepare("SELECT 1 FROM staff_profiles s JOIN users u USING (user_id) JOIN roles r USING (role_id) WHERE s.staff_id = ? AND s.department_id = ? AND s.availability = 'on_duty' AND u.is_active AND r.role_name = 'doctor'");
            $doctorCheck->execute([$doctorId, $departmentId]);
            if (!$doctorCheck->fetchColumn()) respond(422, ['error' => 'Choose an on-duty doctor from that department.']);
        }
        $number = 'APT-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $db->prepare("INSERT INTO appointments (appointment_number, patient_id, department_id, doctor_staff_id, scheduled_at, status, created_by) VALUES (?, ?, ?, ?, ?::timestamptz, 'pending', ?) RETURNING appointment_id, appointment_number, status, scheduled_at");
        $stmt->execute([$number, $patientId, $departmentId, $doctorId, $scheduled, $user['id']]);
        $item = $stmt->fetch();
        audit($db, (int)$user['id'], 'request', 'appointment', (int)$item['appointment_id'], null, $item);
        respond(201, ['item' => $item]);
    }

    if ($segments === ['patient', 'queue'] && $method === 'POST') {
        $user = requireRole(['patient']);
        requireCsrf();
        $input = body();
        $patientStmt = $db->prepare('SELECT patient_id FROM patients WHERE user_id = ? AND is_active');
        $patientStmt->execute([$user['id']]);
        $patientId = (int)$patientStmt->fetchColumn();
        if (!$patientId) respond(404, ['error' => 'No active patient profile is linked to this account.']);
        $departmentId = (int)($input['department_id'] ?? 0);
        $departmentCheck = $db->prepare("SELECT 1 FROM departments WHERE department_id = ? AND status = 'active'");
        $departmentCheck->execute([$departmentId]);
        if (!$departmentCheck->fetchColumn()) respond(422, ['error' => 'Choose an active department.']);
        $day = (string)$db->query('SELECT CURRENT_DATE::text')->fetchColumn();
        $db->beginTransaction();
        try {
            $db->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute([$departmentId . ':' . $day]);
            $sequence = $db->prepare('SELECT COUNT(*) + 1 FROM queue_tickets WHERE department_id = ? AND queue_date = ?');
            $sequence->execute([$departmentId, $day]);
            $ticket = 'Q' . str_pad((string)$sequence->fetchColumn(), 3, '0', STR_PAD_LEFT);
            $stmt = $db->prepare("INSERT INTO queue_tickets (patient_id, department_id, ticket_number, queue_date, created_by) VALUES (?, ?, ?, ?, ?) RETURNING queue_ticket_id, ticket_number, status, arrived_at");
            $stmt->execute([$patientId, $departmentId, $ticket, $day, $user['id']]);
            $item = $stmt->fetch();
            $db->prepare("INSERT INTO queue_ticket_events (queue_ticket_id, new_status, changed_by, note) VALUES (?, 'waiting', ?, 'Patient joined queue')")->execute([$item['queue_ticket_id'], $user['id']]);
            audit($db, (int)$user['id'], 'join', 'queue_ticket', (int)$item['queue_ticket_id'], null, $item);
            $db->commit();
            respond(201, ['item' => $item]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    // Dashboard/report reads.
    if ($segments === ['dashboard'] && $method === 'GET') {
        requireRole(['admin', 'doctor', 'nurse', 'receptionist']);
        $today = $db->query("SELECT CURRENT_DATE::text AS today")->fetchColumn();
        $stats = $db->prepare("SELECT
            (SELECT COUNT(*) FROM patients WHERE is_active) AS patients,
            (SELECT COUNT(*) FROM appointments WHERE scheduled_at::date = CURRENT_DATE) AS appointments,
            (SELECT COUNT(*) FROM queue_tickets WHERE queue_date = CURRENT_DATE AND status = 'waiting') AS waiting,
            (SELECT COUNT(*) FROM queue_tickets WHERE queue_date = CURRENT_DATE AND status = 'served') AS served,
            (SELECT COUNT(*) FROM queue_tickets WHERE queue_date = CURRENT_DATE AND status IN ('called','in_consultation')) AS in_progress");
        $stats->execute();
        $queue = $db->query("SELECT q.queue_ticket_id, q.ticket_number, p.full_name AS patient, d.department_name AS department, q.arrived_at, q.status FROM queue_tickets q JOIN patients p USING (patient_id) JOIN departments d USING (department_id) WHERE q.queue_date = CURRENT_DATE ORDER BY q.arrived_at LIMIT 6")->fetchAll();
        $appointments = $db->query("SELECT scheduled_at::date::text AS day, COUNT(*) AS count FROM appointments WHERE scheduled_at >= CURRENT_DATE - INTERVAL '6 days' AND scheduled_at < CURRENT_DATE + INTERVAL '1 day' GROUP BY day ORDER BY day")->fetchAll();
        respond(200, ['today' => $today, 'stats' => $stats->fetch(), 'queue' => $queue, 'appointmentsByDay' => $appointments]);
    }

    if ($segments === ['reports'] && $method === 'GET') {
        requireRole(['admin']);
        $data = $db->query("SELECT
            (SELECT COUNT(*) FROM appointments) AS appointments,
            (SELECT COUNT(*) FROM queue_tickets WHERE status = 'served' AND served_at >= date_trunc('week', CURRENT_TIMESTAMP)) AS served_this_week,
            (SELECT COUNT(*) FROM appointments WHERE status = 'cancelled') AS cancelled,
            (SELECT ROUND(AVG(EXTRACT(EPOCH FROM (called_at - arrived_at)) / 60)::numeric, 1) FROM queue_tickets WHERE called_at >= date_trunc('week', CURRENT_TIMESTAMP)) AS avg_wait_minutes")->fetch();
        respond(200, $data);
    }

    // Patient directory: registration, search, and reversible archive.
    if ($segments === ['patients'] && $method === 'GET') {
        requireRole(['admin', 'doctor', 'nurse', 'receptionist']);
        $q = trim((string)($_GET['q'] ?? ''));
        $stmt = $db->prepare("SELECT patient_id, patient_number, full_name, phone, gender, date_of_birth::text AS date_of_birth, blood_group, CASE WHEN user_id IS NULL THEN 'false' ELSE 'true' END AS has_portal FROM patients WHERE is_active AND (? = '' OR full_name ILIKE ? OR patient_number ILIKE ? OR phone ILIKE ?) ORDER BY full_name LIMIT 500");
        $like = '%' . $q . '%';
        $stmt->execute([$q, $like, $like, $like]);
        respond(200, ['items' => $stmt->fetchAll()]);
    }

    if ($segments === ['patients'] && $method === 'POST') {
        $user = requireRole(['admin', 'receptionist']);
        requireCsrf();
        $input = body();
        $name = requiredString($input, 'full_name', 160);
        $phone = requiredString($input, 'phone', 30);
        $gender = strtolower(trim((string)($input['gender'] ?? 'unspecified')));
        if (!in_array($gender, ['female', 'male', 'nonbinary', 'other', 'unspecified'], true)) respond(422, ['error' => 'Choose a valid gender value.']);
        $dob = trim((string)($input['date_of_birth'] ?? '')) ?: null;
        $blood = strtoupper(trim((string)($input['blood_group'] ?? ''))) ?: null;
        $number = 'P-' . strtoupper(bin2hex(random_bytes(4)));
        [$firstName, $lastName] = array_pad(preg_split('/\s+/', $name, 2) ?: [$name], 2, '');
        $stmt = $db->prepare('INSERT INTO patients (patient_number, full_name, first_name, last_name, phone, gender, date_of_birth, blood_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING patient_id, patient_number, full_name, phone, gender, date_of_birth::text AS date_of_birth, blood_group');
        $stmt->execute([$number, $name, $firstName, $lastName, $phone, $gender, $dob, $blood]);
        $patient = $stmt->fetch();
        audit($db, (int)$user['id'], 'create', 'patient', (int)$patient['patient_id'], null, $patient);
        respond(201, ['item' => $patient]);
    }

    if (count($segments) === 3 && $segments[0] === 'patients' && $segments[2] === 'portal-account' && $method === 'POST') {
        $actor = requireRole(['admin', 'receptionist']);
        requireCsrf();
        $patientId = idParam($segments[1]);
        $input = body();
        $email = strtolower(requiredString($input, 'email', 254));
        $password = (string)($input['temporary_password'] ?? '');
        if (strlen($password) < 12) respond(422, ['error' => 'Temporary password must be at least 12 characters.']);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $db->beginTransaction();
        try {
            $find = $db->prepare('SELECT patient_id, user_id FROM patients WHERE patient_id = ? AND is_active FOR UPDATE');
            $find->execute([$patientId]);
            $patient = $find->fetch();
            if (!$patient) { $db->rollBack(); respond(404, ['error' => 'Active patient not found.']); }
            if ($patient['user_id'] !== null) { $db->rollBack(); respond(409, ['error' => 'This patient already has a portal account.']); }
            $insert = $db->prepare("INSERT INTO users (role_id, email, password_hash) SELECT role_id, ?, ? FROM roles WHERE role_name = 'patient' RETURNING user_id");
            $insert->execute([$email, $passwordHash]);
            $patientUserId = (int)$insert->fetchColumn();
            $db->prepare('UPDATE patients SET user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE patient_id = ?')->execute([$patientUserId, $patientId]);
            audit($db, (int)$actor['id'], 'create_portal_account', 'patient', $patientId, null, ['user_id' => $patientUserId, 'email' => $email]);
            $db->commit();
            respond(201, ['message' => 'Patient portal account created. Share the temporary password securely.']);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    if (count($segments) === 2 && $segments[0] === 'patients' && $method === 'DELETE') {
        $user = requireRole(['admin']);
        requireCsrf();
        $id = idParam($segments[1]);
        $stmt = $db->prepare('UPDATE patients SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP WHERE patient_id = ? AND is_active RETURNING patient_id, patient_number, full_name');
        $stmt->execute([$id]);
        $patient = $stmt->fetch();
        if (!$patient) respond(404, ['error' => 'Active patient record not found.']);
        audit($db, (int)$user['id'], 'archive', 'patient', $id, $patient, null);
        respond(200, ['message' => 'Patient record archived.']);
    }

    // Departments and doctor/staff directory.
    if ($segments === ['departments'] && $method === 'GET') {
        requireRole(['admin', 'doctor', 'nurse', 'receptionist', 'patient']);
        $rows = $db->query("SELECT d.department_id, d.department_code, d.department_name, d.consultation_rooms, d.status, COALESCE(s.full_name, '') AS lead_name FROM departments d LEFT JOIN staff_profiles s ON s.staff_id = d.lead_staff_id ORDER BY d.department_name")->fetchAll();
        respond(200, ['items' => $rows]);
    }

    if ($segments === ['departments'] && $method === 'POST') {
        $user = requireRole(['admin']);
        requireCsrf();
        $input = body();
        $stmt = $db->prepare('INSERT INTO departments (department_code, department_name, consultation_rooms) VALUES (?, ?, ?) RETURNING department_id, department_code, department_name, consultation_rooms, status');
        $stmt->execute([strtoupper(requiredString($input, 'department_code', 20)), requiredString($input, 'department_name', 100), max(1, (int)($input['consultation_rooms'] ?? 1))]);
        $department = $stmt->fetch();
        audit($db, (int)$user['id'], 'create', 'department', (int)$department['department_id'], null, $department);
        respond(201, ['item' => $department]);
    }

    if (count($segments) === 2 && $segments[0] === 'departments' && $method === 'PATCH') {
        $user = requireRole(['admin']);
        requireCsrf();
        $id = idParam($segments[1]);
        $input = body();
        $status = strtolower((string)($input['status'] ?? ''));
        if (!in_array($status, ['active', 'inactive'], true)) respond(422, ['error' => 'Invalid department status.']);
        $stmt = $db->prepare('UPDATE departments SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE department_id = ? RETURNING department_id, department_code, department_name, status');
        $stmt->execute([$status, $id]);
        $item = $stmt->fetch();
        if (!$item) respond(404, ['error' => 'Department not found.']);
        audit($db, (int)$user['id'], 'update_status', 'department', $id, null, $item);
        respond(200, ['item' => $item]);
    }

    if ($segments === ['doctors'] && $method === 'GET') {
        requireRole(['admin', 'doctor', 'nurse', 'receptionist', 'patient']);
        $rows = $db->query("SELECT s.staff_id, s.staff_number, s.full_name, s.specialty, s.availability, d.department_name, s.department_id FROM staff_profiles s JOIN users u ON u.user_id = s.user_id JOIN roles r ON r.role_id = u.role_id LEFT JOIN departments d ON d.department_id = s.department_id WHERE r.role_name = 'doctor' AND u.is_active ORDER BY s.full_name")->fetchAll();
        respond(200, ['items' => $rows]);
    }

    if ($segments === ['staff'] && $method === 'GET') {
        requireRole(['admin']);
        $rows = $db->query("SELECT sp.staff_id, sp.staff_number, sp.full_name, sp.job_title, sp.specialty, sp.availability, sp.department_id, d.department_name, u.user_id, u.email, CASE WHEN u.is_active THEN 'true' ELSE 'false' END AS is_active, r.role_name AS role FROM staff_profiles sp JOIN users u USING (user_id) JOIN roles r USING (role_id) LEFT JOIN departments d USING (department_id) ORDER BY sp.full_name")->fetchAll();
        respond(200, ['items' => $rows]);
    }

    if ($segments === ['staff'] && $method === 'POST') {
        $admin = requireRole(['admin']);
        requireCsrf();
        $input = body();
        $email = strtolower(requiredString($input, 'email', 254));
        $password = (string)($input['temporary_password'] ?? '');
        if (strlen($password) < 12) respond(422, ['error' => 'Temporary password must be at least 12 characters.']);
        $role = strtolower(requiredString($input, 'role', 40));
        if (!in_array($role, ['doctor', 'nurse', 'receptionist'], true)) respond(422, ['error' => 'Choose doctor, nurse, or receptionist.']);
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO users (role_id, email, password_hash) SELECT role_id, ?, ? FROM roles WHERE role_name = ? RETURNING user_id');
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $role]);
            $userId = (int)$stmt->fetchColumn();
            if (!$userId) throw new RuntimeException('Role was not found.');
            $staffNumber = 'STF-' . strtoupper(bin2hex(random_bytes(4)));
            $staffStmt = $db->prepare('INSERT INTO staff_profiles (user_id, staff_number, full_name, department_id, job_title, specialty) VALUES (?, ?, ?, ?, ?, ?) RETURNING staff_id, staff_number, full_name, job_title, specialty');
            $staffStmt->execute([$userId, $staffNumber, requiredString($input, 'full_name', 160), !empty($input['department_id']) ? (int)$input['department_id'] : null, ucfirst($role), trim((string)($input['specialty'] ?? '')) ?: null]);
            $staff = $staffStmt->fetch();
            audit($db, (int)$admin['id'], 'create', 'staff', (int)$staff['staff_id'], null, ['staff' => $staff, 'email' => $email, 'role' => $role]);
            $db->commit();
            respond(201, ['item' => $staff, 'message' => 'Staff account created. Share its temporary password securely.']);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    if (count($segments) === 2 && $segments[0] === 'staff' && $method === 'PATCH') {
        $admin = requireRole(['admin']);
        requireCsrf();
        $staffId = idParam($segments[1]);
        $input = body();
        $active = filter_var($input['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) respond(422, ['error' => 'is_active must be true or false.']);
        $targetStmt = $db->prepare('SELECT u.user_id, r.role_name FROM staff_profiles sp JOIN users u USING (user_id) JOIN roles r USING (role_id) WHERE sp.staff_id = ?');
        $targetStmt->execute([$staffId]);
        $target = $targetStmt->fetch();
        if (!$target) respond(404, ['error' => 'Staff account not found.']);
        if ((int)$target['user_id'] === (int)$admin['id'] || $target['role_name'] === 'admin') {
            respond(403, ['error' => 'System administrator accounts are protected from staff-directory changes.']);
        }
        $stmt = $db->prepare('UPDATE users u SET is_active = ?, updated_at = CURRENT_TIMESTAMP FROM staff_profiles sp WHERE sp.user_id = u.user_id AND sp.staff_id = ? RETURNING u.user_id, u.is_active');
        $stmt->execute([$active, $staffId]);
        $changed = $stmt->fetch();
        if (!$changed) respond(404, ['error' => 'Staff account not found.']);
        audit($db, (int)$admin['id'], $active ? 'activate' : 'deactivate', 'staff', $staffId, null, $changed);
        respond(200, ['item' => $changed]);
    }

    if (count($segments) === 2 && $segments[0] === 'doctors' && $method === 'PATCH') {
        $user = requireRole(['admin']);
        requireCsrf();
        $id = idParam($segments[1]);
        $input = body();
        $availability = strtolower((string)($input['availability'] ?? ''));
        if (!in_array($availability, ['on_duty', 'off_duty'], true)) respond(422, ['error' => 'Invalid availability.']);
        $stmt = $db->prepare("UPDATE staff_profiles s SET availability = ?, updated_at = CURRENT_TIMESTAMP FROM users u JOIN roles r ON r.role_id = u.role_id WHERE s.user_id = u.user_id AND r.role_name = 'doctor' AND s.staff_id = ? RETURNING s.staff_id, s.availability");
        $stmt->execute([$availability, $id]);
        $item = $stmt->fetch();
        if (!$item) respond(404, ['error' => 'Doctor not found.']);
        audit($db, (int)$user['id'], 'update_availability', 'staff', $id, null, $item);
        respond(200, ['item' => $item]);
    }

    // Appointments.
    if ($segments === ['appointments'] && $method === 'GET') {
        requireRole(['admin', 'doctor', 'receptionist']);
        $user = currentUser();
        $stmt = $db->prepare("SELECT a.appointment_id, a.appointment_number, a.patient_id, p.patient_number, p.full_name AS patient_name, a.department_id, d.department_name, a.doctor_staff_id, COALESCE(s.full_name, 'Unassigned') AS doctor_name, a.scheduled_at, a.status, a.reason FROM appointments a JOIN patients p USING (patient_id) JOIN departments d USING (department_id) LEFT JOIN staff_profiles s ON s.staff_id = a.doctor_staff_id WHERE p.is_active AND (? IN ('admin', 'receptionist') OR EXISTS (SELECT 1 FROM staff_profiles mine WHERE mine.user_id = ? AND mine.staff_id = a.doctor_staff_id)) ORDER BY a.scheduled_at DESC LIMIT 1000");
        $stmt->execute([$user['role'], $user['id']]);
        $rows = $stmt->fetchAll();
        respond(200, ['items' => $rows]);
    }

    if ($segments === ['appointments'] && $method === 'POST') {
        $user = requireRole(['admin', 'receptionist']);
        requireCsrf();
        $input = body();
        $scheduled = requiredString($input, 'scheduled_at', 40);
        $futureCheck = $db->prepare("SELECT CASE WHEN ?::timestamptz >= CURRENT_TIMESTAMP THEN 'true' ELSE 'false' END");
        $futureCheck->execute([$scheduled]);
        if ($futureCheck->fetchColumn() !== 'true') respond(422, ['error' => 'Appointment must be scheduled in the future.']);
        $check = $db->prepare("SELECT 1 FROM patients p JOIN departments d ON d.department_id = ? WHERE p.patient_id = ? AND p.is_active AND d.status = 'active'");
        $check->execute([(int)($input['department_id'] ?? 0), (int)($input['patient_id'] ?? 0)]);
        if (!$check->fetchColumn()) respond(422, ['error' => 'Choose an active patient and department.']);
        if (!empty($input['doctor_staff_id'])) {
            $db->beginTransaction();
            $doctorLock = $db->prepare('SELECT staff_id FROM staff_profiles WHERE staff_id = ? FOR UPDATE');
            $doctorLock->execute([(int)$input['doctor_staff_id']]);
            $doctorCheck = $db->prepare("SELECT 1 FROM staff_profiles s JOIN users u USING (user_id) JOIN roles r USING (role_id) WHERE s.staff_id = ? AND s.department_id = ? AND s.availability = 'on_duty' AND u.is_active AND r.role_name = 'doctor'");
            $doctorCheck->execute([(int)$input['doctor_staff_id'], (int)$input['department_id']]);
            if (!$doctorCheck->fetchColumn()) {
                $db->rollBack();
                respond(422, ['error' => 'Choose an on-duty doctor from the selected department.']);
            }
            $conflict = $db->prepare("SELECT appointment_id FROM appointments WHERE doctor_staff_id = ? AND scheduled_at = ?::timestamptz AND status IN ('pending', 'confirmed') LIMIT 1");
            $conflict->execute([(int)$input['doctor_staff_id'], $scheduled]);
            if ($conflict->fetchColumn()) {
                $db->rollBack();
                respond(409, ['error' => 'That appointment time is already booked.']);
            }
        }
        $number = 'APT-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $db->prepare("INSERT INTO appointments (appointment_number, patient_id, department_id, doctor_staff_id, scheduled_at, status, created_by) VALUES (?, ?, ?, ?, ?::timestamptz, 'pending', ?) RETURNING appointment_id, appointment_number, status");
        $stmt->execute([$number, (int)($input['patient_id'] ?? 0), (int)($input['department_id'] ?? 0), !empty($input['doctor_staff_id']) ? (int)$input['doctor_staff_id'] : null, $scheduled, $user['id']]);
        $item = $stmt->fetch();
        audit($db, (int)$user['id'], 'create', 'appointment', (int)$item['appointment_id'], null, $item);
        if (!empty($input['doctor_staff_id'])) $db->commit();
        respond(201, ['item' => $item]);
    }

    if (count($segments) === 2 && $segments[0] === 'appointments' && $method === 'PATCH') {
        $user = requireRole(['admin', 'doctor', 'receptionist']);
        requireCsrf();
        $id = idParam($segments[1]);
        $status = strtolower((string)(body()['status'] ?? ''));
        if (!in_array($status, ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'], true)) respond(422, ['error' => 'Invalid appointment status.']);
        $db->beginTransaction();
        $previous = $db->prepare('SELECT status FROM appointments WHERE appointment_id = ? FOR UPDATE');
        $previous->execute([$id]);
        $oldStatus = $previous->fetchColumn();
        if ($oldStatus === false) { $db->rollBack(); respond(404, ['error' => 'Appointment not found.']); }
        $stmt = $db->prepare('UPDATE appointments SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE appointment_id = ? RETURNING appointment_id, status');
        $stmt->execute([$status, $id]);
        $item = $stmt->fetch();
        if (!$item) { $db->rollBack(); respond(404, ['error' => 'Appointment not found.']); }
        $queueStatus = match ($status) { 'completed' => 'served', 'cancelled' => 'cancelled', 'no_show' => 'no_show', default => null };
        if ($queueStatus !== null) {
            $queueFind = $db->prepare('SELECT queue_ticket_id, status FROM queue_tickets WHERE appointment_id = ? FOR UPDATE');
            $queueFind->execute([$id]);
            $ticket = $queueFind->fetch();
            if ($ticket) {
                $queueUpdate = $db->prepare('UPDATE queue_tickets SET status = ?, updated_at = CURRENT_TIMESTAMP, served_at = CASE WHEN ? = \'served\' THEN CURRENT_TIMESTAMP ELSE served_at END WHERE queue_ticket_id = ?');
                $queueUpdate->execute([$queueStatus, $queueStatus, $ticket['queue_ticket_id']]);
                $event = $db->prepare('INSERT INTO queue_ticket_events (queue_ticket_id, old_status, new_status, changed_by, note) VALUES (?, ?, ?, ?, ?)');
                $event->execute([$ticket['queue_ticket_id'], $ticket['status'], $queueStatus, $user['id'], 'Appointment status changed by staff']);
            }
        }
        audit($db, (int)$user['id'], 'update_status', 'appointment', $id, null, $item);
        $db->commit();
        respond(200, ['item' => $item]);
    }

    // Queue: walk-ins can be registered and ticketed atomically.
    if ($segments === ['queue'] && $method === 'GET') {
        requireRole(['admin', 'doctor', 'nurse', 'receptionist']);
        $date = (string)($_GET['date'] ?? $db->query('SELECT CURRENT_DATE::text')->fetchColumn());
        $stmt = $db->prepare('SELECT q.queue_ticket_id, q.ticket_number, q.patient_id, p.patient_number, p.full_name AS patient_name, q.department_id, d.department_name, q.queue_date, q.status, q.arrived_at, q.called_at, q.service_started_at, q.served_at FROM queue_tickets q JOIN patients p USING (patient_id) JOIN departments d USING (department_id) WHERE q.queue_date = ? ORDER BY q.arrived_at');
        $stmt->execute([$date]);
        respond(200, ['items' => $stmt->fetchAll()]);
    }

    if ($segments === ['queue'] && $method === 'POST') {
        $user = requireRole(['admin', 'nurse', 'receptionist']);
        requireCsrf();
        $input = body();
        $db->beginTransaction();
        try {
            if (!empty($input['patient_id'])) {
                $patientId = (int)$input['patient_id'];
                $patientCheck = $db->prepare('SELECT patient_id FROM patients WHERE patient_id = ? AND is_active');
                $patientCheck->execute([$patientId]);
                if (!$patientCheck->fetchColumn()) respond(422, ['error' => 'Choose an active patient record.']);
            } else {
                $name = requiredString($input, 'patient_name', 160);
                $phone = requiredString($input, 'phone', 30);
                $patientNumber = 'P-' . strtoupper(bin2hex(random_bytes(4)));
                $p = $db->prepare("INSERT INTO patients (patient_number, full_name, phone, gender) VALUES (?, ?, ?, 'unspecified') RETURNING patient_id");
                $p->execute([$patientNumber, $name, $phone]);
                $patientId = (int)$p->fetchColumn();
            }
            $departmentId = (int)($input['department_id'] ?? 0);
            $departmentCheck = $db->prepare("SELECT 1 FROM departments WHERE department_id = ? AND status = 'active'");
            $departmentCheck->execute([$departmentId]);
            if (!$departmentCheck->fetchColumn()) respond(422, ['error' => 'Choose an active department.']);
            $day = (string)$db->query('SELECT CURRENT_DATE::text')->fetchColumn();
            $db->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute([$departmentId . ':' . $day]);
            $seq = $db->prepare('SELECT COUNT(*) + 1 FROM queue_tickets WHERE department_id = ? AND queue_date = ?');
            $seq->execute([$departmentId, $day]);
            $ticket = 'Q' . str_pad((string)$seq->fetchColumn(), 3, '0', STR_PAD_LEFT);
            $q = $db->prepare("INSERT INTO queue_tickets (patient_id, department_id, ticket_number, queue_date, created_by) VALUES (?, ?, ?, ?, ?) RETURNING queue_ticket_id, ticket_number, status, arrived_at");
            $q->execute([$patientId, $departmentId, $ticket, $day, $user['id']]);
            $created = $q->fetch();
            $db->prepare("INSERT INTO queue_ticket_events (queue_ticket_id, new_status, changed_by, note) VALUES (?, 'waiting', ?, 'Ticket issued')")->execute([$created['queue_ticket_id'], $user['id']]);
            audit($db, (int)$user['id'], 'create', 'queue_ticket', (int)$created['queue_ticket_id'], null, $created);
            $db->commit();
            respond(201, ['item' => $created]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    if (count($segments) === 3 && $segments[0] === 'queue' && $segments[2] === 'status' && $method === 'PATCH') {
        $user = requireRole(['admin', 'doctor', 'nurse', 'receptionist']);
        requireCsrf();
        $id = idParam($segments[1]);
        $status = strtolower((string)(body()['status'] ?? ''));
        $valid = ['waiting', 'called', 'in_consultation', 'served', 'cancelled', 'no_show'];
        if (!in_array($status, $valid, true)) respond(422, ['error' => 'Invalid queue status.']);
        $db->beginTransaction();
        try {
            $find = $db->prepare('SELECT status, appointment_id FROM queue_tickets WHERE queue_ticket_id = ? FOR UPDATE');
            $find->execute([$id]);
            $ticketBefore = $find->fetch();
            if (!$ticketBefore) { $db->rollBack(); respond(404, ['error' => 'Queue ticket not found.']); }
            $oldStatus = $ticketBefore['status'];
            $timeColumn = match ($status) {
                'called' => 'called_at',
                'in_consultation' => 'service_started_at',
                'served' => 'served_at',
                default => null,
            };
            $sql = 'UPDATE queue_tickets SET status = ?, updated_at = CURRENT_TIMESTAMP';
            if ($timeColumn !== null) $sql .= ", {$timeColumn} = CURRENT_TIMESTAMP";
            $sql .= ' WHERE queue_ticket_id = ? RETURNING queue_ticket_id, ticket_number, status';
            $update = $db->prepare($sql);
            $update->execute([$status, $id]);
            $item = $update->fetch();
            $event = $db->prepare('INSERT INTO queue_ticket_events (queue_ticket_id, old_status, new_status, changed_by) VALUES (?, ?, ?, ?)');
            $event->execute([$id, $oldStatus, $status, $user['id']]);
            if ($ticketBefore['appointment_id'] !== null) {
                $appointmentStatus = match ($status) { 'served' => 'completed', 'cancelled' => 'cancelled', 'no_show' => 'no_show', default => null };
                if ($appointmentStatus !== null) {
                    $db->prepare('UPDATE appointments SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE appointment_id = ?')->execute([$appointmentStatus, $ticketBefore['appointment_id']]);
                }
            }
            audit($db, (int)$user['id'], 'update_status', 'queue_ticket', $id, ['status' => $oldStatus], ['status' => $status]);
            $db->commit();
            respond(200, ['item' => $item]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    respond(404, ['error' => 'API endpoint not found.']);
} catch (PDOException $error) {
    error_log($error->__toString());
    if ($error->getCode() === '23505') respond(409, ['error' => 'A record with those details already exists.']);
    if ($error->getCode() === '23503') respond(422, ['error' => 'A selected related record does not exist.']);
    if ($error->getCode() === '23514' || $error->getCode() === '22007' || $error->getCode() === '22008') respond(422, ['error' => 'One or more values are invalid.']);
    respond(500, ['error' => 'Database request failed. Check backend database configuration.']);
} catch (Throwable $error) {
    error_log($error->__toString());
    respond(500, ['error' => 'Unexpected server error.']);
}
