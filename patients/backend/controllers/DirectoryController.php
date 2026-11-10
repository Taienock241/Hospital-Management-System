<?php
declare(strict_types=1);

final class DirectoryController
{
    public static function departments(): never
    {
        require_method('GET');
        global $pdo;
        $bookableOnly = ($_GET['bookable'] ?? '') === '1';
        $sql = "SELECT COALESCE(public_slug, department_id::text) AS id, department_name AS name, category, department_code AS code,
                       head, wing, floor, hours, description, services, is_bookable
                FROM departments WHERE status = 'active'";
        if ($bookableOnly) {
            $sql .= ' AND is_bookable IS TRUE';
        }
        $sql .= ' ORDER BY name';
        $rows = $pdo->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            $services = is_array($row['services']) ? $row['services'] : json_decode((string) $row['services'], true);
            $row['services'] = is_array($services) ? $services : [];
            $row['is_bookable'] = filter_var($row['is_bookable'], FILTER_VALIDATE_BOOLEAN);
        }
        unset($row);
        respond(200, true, 'Departments loaded.', $rows);
    }

    public static function doctors(): never
    {
        require_method('GET');
        global $pdo;
        $department = trim((string) ($_GET['department'] ?? ''));
        $search = trim((string) ($_GET['search'] ?? ''));
        if (strlen($department) > 32 || strlen($search) > 100) {
            respond(422, false, 'Search filters are too long.');
        }

        $sql = "SELECT COALESCE(s.public_slug, s.staff_id::text) AS id, s.full_name AS name,
                       COALESCE(dep.public_slug, dep.department_id::text) AS department,
                       dep.department_name AS departmentTitle, s.experience_years AS \"experienceYears\",
                       s.alma_mater AS \"almaMater\", s.focus, s.specialty,
                       COALESCE(schedule.schedule_text, 'Schedule pending') AS schedule,
                       s.room,
                       s.image,
                       s.description,
                       CASE WHEN s.availability = 'on_duty' AND u.is_active THEN 'true' ELSE 'false' END AS available
                FROM staff_profiles s
                JOIN users u ON u.user_id = s.user_id
                JOIN roles r ON r.role_id = u.role_id AND r.role_name = 'doctor'
                JOIN departments dep ON dep.department_id = s.department_id
                LEFT JOIN LATERAL (
                    SELECT string_agg(
                        CASE day_of_week
                            WHEN 0 THEN 'Sun' WHEN 1 THEN 'Mon' WHEN 2 THEN 'Tue'
                            WHEN 3 THEN 'Wed' WHEN 4 THEN 'Thu' WHEN 5 THEN 'Fri' WHEN 6 THEN 'Sat'
                        END || ' (' || to_char(work_start, 'HH12:MI AM') || ' - ' || to_char(work_end, 'HH12:MI AM') || ')',
                        ', ' ORDER BY day_of_week, work_start
                    ) AS schedule_text
                    FROM doctor_schedules WHERE staff_id = s.staff_id AND is_active
                ) schedule ON TRUE
                WHERE s.public_profile AND s.availability = 'on_duty' AND u.is_active AND dep.status = 'active'";
        $parameters = [];
        if ($department !== '') {
            $sql .= ' AND (dep.public_slug = ? OR dep.department_id::text = ?)';
            $parameters[] = $department;
            $parameters[] = $department;
        }
        if ($search !== '') {
            $sql .= ' AND (s.full_name ILIKE ? OR s.specialty ILIKE ? OR s.focus ILIKE ? OR dep.department_name ILIKE ?)';
            $term = '%' . $search . '%';
            array_push($parameters, $term, $term, $term, $term);
        }
        $sql .= ' ORDER BY s.full_name';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $doctors = $statement->fetchAll();
        foreach ($doctors as &$doctor) {
            $doctor['experienceYears'] = (int) $doctor['experienceYears'];
            $doctor['available'] = filter_var($doctor['available'], FILTER_VALIDATE_BOOLEAN);
        }
        unset($doctor);
        respond(200, true, 'Doctors loaded.', $doctors);
    }
}
