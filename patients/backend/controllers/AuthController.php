<?php
declare(strict_types=1);

final class AuthController
{
    public static function csrf(): never
    {
        require_method('GET');
        respond(200, true, 'Session token ready.', ['csrfToken' => csrf_token()]);
    }

    public static function me(): never
    {
        require_method('GET');
        if (($_SESSION['role'] ?? '') !== 'patient' || !isset($_SESSION['patient_id'])) {
            respond(200, true, 'No patient is signed in.', ['authenticated' => false]);
        }

        global $pdo;
        $statement = $pdo->prepare(
            'SELECT p.patient_id, p.first_name, p.last_name, p.phone,
                    p.date_of_birth::text AS date_of_birth, p.gender, u.email
             FROM patients p JOIN users u ON u.user_id = p.user_id
             WHERE p.patient_id = ? AND p.is_active AND u.is_active'
        );
        $statement->execute([(int) $_SESSION['patient_id']]);
        $patient = $statement->fetch();
        if (!$patient) {
            $_SESSION = [];
            session_regenerate_id(true);
            respond(200, true, 'No patient is signed in.', ['authenticated' => false]);
        }
        respond(200, true, 'Signed in.', ['authenticated' => true, 'patient' => $patient, 'csrfToken' => csrf_token()]);
    }

    public static function register(): never
    {
        require_method('POST');
        require_csrf();
        $data = request_data();
        $firstName = required_string($data, 'firstName', 80, 'First name');
        $lastName = required_string($data, 'lastName', 80, 'Last name');
        $email = strtolower(required_string($data, 'email', 254, 'Email'));
        $phone = required_string($data, 'phone', 32, 'Phone number');
        $dateOfBirth = required_string($data, 'dateOfBirth', 10, 'Date of birth');
        $password = $data['password'] ?? null;
        $gender = $data['gender'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(422, false, 'Please check the submitted information.', null, ['email' => 'Enter a valid email address.']);
        }
        if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72) {
            respond(422, false, 'Please check the submitted information.', null, ['password' => 'Password must be between 8 and 72 bytes.']);
        }
        if (!preg_match('/^[0-9+().\-\s]{7,32}$/', $phone)) {
            respond(422, false, 'Please check the submitted information.', null, ['phone' => 'Enter a valid phone number.']);
        }
        if (!valid_date($dateOfBirth) || $dateOfBirth >= date('Y-m-d')) {
            respond(422, false, 'Please check the submitted information.', null, ['dateOfBirth' => 'Enter a valid date of birth in the past.']);
        }
        if (!in_array($gender, ['', 'female', 'male', 'nonbinary', 'other'], true)) {
            respond(422, false, 'Please check the submitted information.', null, ['gender' => 'Select a valid gender option.']);
        }
        if (($data['termsConsent'] ?? false) !== true) {
            respond(422, false, 'Please check the submitted information.', null, ['termsConsent' => 'You must accept the terms to register.']);
        }

        global $pdo;
        $pdo->beginTransaction();
        try {
            $userStatement = $pdo->prepare(
                "INSERT INTO users (role_id, email, password_hash)
                 SELECT role_id, ?, ? FROM roles WHERE role_name = 'patient'
                 RETURNING user_id"
            );
            $userStatement->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $userStatement->fetchColumn();
            if ($userId < 1) {
                throw new RuntimeException('Patient role is missing from the database.');
            }

            $patientNumber = 'PT-' . strtoupper(bin2hex(random_bytes(5)));
            $fullName = trim($firstName . ' ' . $lastName);

            $patientStatement = $pdo->prepare(
                'INSERT INTO patients
                    (patient_number, user_id, full_name, first_name, last_name, phone, date_of_birth, gender)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $patientStatement->execute([
                $patientNumber, $userId, $fullName, $firstName, $lastName, $phone, $dateOfBirth, $gender === '' ? null : $gender,
            ]);
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception->getCode() === '23505') {
                respond(409, false, 'An account with this email address already exists.', null, ['email' => 'Use another email address or sign in.']);
            }
            throw $exception;
        }

        respond(201, true, 'Registration completed. You can now sign in.', ['email' => $email]);
    }

    public static function login(): never
    {
        require_method('POST');
        require_csrf();
        $data = request_data();
        $email = strtolower(required_string($data, 'email', 254, 'Email'));
        $password = $data['password'] ?? null;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) ||
            $password === '' || strlen($password) > 72) {
            respond(422, false, 'Enter a valid email address and password.');
        }

        global $pdo;
        $statement = $pdo->prepare(
            "SELECT u.user_id, u.password_hash, r.role_name AS role, p.patient_id
             FROM users u JOIN roles r ON r.role_id = u.role_id
             LEFT JOIN patients p ON p.user_id = u.user_id AND p.is_active
             WHERE LOWER(u.email) = LOWER(?) AND u.is_active LIMIT 1"
        );
        $statement->execute([$email]);
        $account = $statement->fetch();

        if (!$account || $account['role'] !== 'patient' || !$account['patient_id'] ||
            !password_verify($password, $account['password_hash'])) {
            respond(401, false, 'Email or password is incorrect.');
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $account['user_id'];
        $_SESSION['patient_id'] = (int) $account['patient_id'];
        $_SESSION['role'] = 'patient';
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(200, true, 'Signed in successfully.', ['authenticated' => true, 'csrfToken' => $_SESSION['csrf_token']]);
    }

    public static function logout(): never
    {
        require_method('POST');
        require_csrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        respond(200, true, 'Signed out.');
    }
}
