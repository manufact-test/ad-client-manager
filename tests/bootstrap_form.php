<?php
declare(strict_types=1);

// Offline regression guard: prevent a first-run form layout that activates
// aggressive browser credential-autofill heuristics while typing the name.
$index = file_get_contents(__DIR__ . '/../index.php');
if ($index === false) {
    throw new RuntimeException('Missing index.php');
}
$fields = [
    'name="display_name" autocomplete="off"',
    'name="email" autocomplete="off"',
    'name="password" minlength="12" autocomplete="new-password"',
    'name="setup_secret" autocomplete="off"',
];
$positions = [];
foreach ($fields as $field) {
    $position = strpos($index, $field);
    if ($position === false) {
        throw new RuntimeException('Bootstrap input missing or autocomplete policy changed: ' . $field);
    }
    $positions[] = $position;
}
if ($positions !== array_values(array_unique($positions)) || $positions !== array_values($positions)) {
    throw new RuntimeException('Duplicate bootstrap inputs');
}
for ($i = 1; $i < count($positions); $i++) {
    if ($positions[$i] <= $positions[$i - 1]) {
        throw new RuntimeException('Non-secret form fields must appear before password/secret');
    }
}
if (!str_contains($index, 'class="stack" autocomplete="off"')) {
    throw new RuntimeException('Setup form requires autocomplete off');
}
echo "Bootstrap form regression passed (static check only)\n";
