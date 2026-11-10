<?php
declare(strict_types=1);

function required_string(array $data, string $key, int $maxLength, string $label): string
{
    $value = $data[$key] ?? null;
    if (!is_string($value)) {
        respond(422, false, 'Please check the submitted information.', null, [$key => "{$label} is required."]);
    }
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > $maxLength) {
        respond(422, false, 'Please check the submitted information.', null, [$key => "{$label} is required and must be at most {$maxLength} characters."]);
    }
    return $value;
}

function valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
