<?php
declare(strict_types=1);

final class PatientController
{
    public static function profile(): never
    {
        require_method('GET');
        $patientId = require_patient();
        global $pdo;
        $statement = $pdo->prepare(
            'SELECT p.patient_id, p.first_name, p.last_name, p.phone,
                    p.date_of_birth::text AS date_of_birth, p.gender, u.email
             FROM patients p JOIN users u ON u.user_id = p.user_id
             WHERE p.patient_id = ? AND p.is_active AND u.is_active'
        );
        $statement->execute([$patientId]);
        $patient = $statement->fetch();
        if (!$patient) {
            respond(404, false, 'Patient profile not found.');
        }
        respond(200, true, 'Patient profile loaded.', $patient);
    }

    public static function updateProfile(): never
    {
        require_method('PUT');
        require_patient();
        require_csrf();
        $data = request_data();
        $firstName = required_string($data, 'firstName', 80, 'First name');
        $lastName = required_string($data, 'lastName', 80, 'Last name');
        $phone = required_string($data, 'phone', 32, 'Phone number');
        if (!preg_match('/^[0-9+().\-\s]{7,32}$/', $phone)) {
            respond(422, false, 'Please check the submitted information.', null, ['phone' => 'Enter a valid phone number.']);
        }
        global $pdo;
        $statement = $pdo->prepare(
            "UPDATE patients SET first_name = ?, last_name = ?, full_name = ?, phone = ?, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = ? AND is_active"
        );
        $statement->execute([$firstName, $lastName, trim($firstName . ' ' . $lastName), $phone, (int)$_SESSION['user_id']]);
        respond(200, true, 'Profile updated.');
    }
}
