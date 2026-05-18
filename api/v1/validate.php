<?php


require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError(405, 'Method not allowed. Use POST.');
}

$input = json_decode(file_get_contents('php://input'), true);

if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
}

$number = trim($input['number'] ?? '');

if (empty($number)) {
    sendError(400, 'number is required');
}


if (!str_starts_with($number, '+')) {
    echo json_encode([
        'ok' => true,
        'valid' => false,
        'number' => $number,
        'country' => null,
        'country_code' => null,
        'reason' => 'Number must start with +'
    ]);
    exit;
}


$digitsOnly = preg_replace('/[^0-9]/', '', $number);
$digitCount = strlen($digitsOnly);

if ($digitCount < 8 || $digitCount > 16) {
    echo json_encode([
        'ok' => true,
        'valid' => false,
        'number' => $number,
        'country' => null,
        'country_code' => null,
        'reason' => 'Number must have 8-16 digits after the +' . $digitCount
    ]);
    exit;
}


$countryMap = [
    '+966' => ['country' => 'Saudi Arabia', 'code' => '+966'],
    '+971' => ['country' => 'UAE', 'code' => '+971'],
    '+880' => ['country' => 'Bangladesh', 'code' => '+880'],
    '+234' => ['country' => 'Nigeria', 'code' => '+234'],
    '+27'  => ['country' => 'South Africa', 'code' => '+27'],
    '+34'  => ['country' => 'Spain', 'code' => '+34'],
    '+39'  => ['country' => 'Italy', 'code' => '+39'],
    '+44'  => ['country' => 'UK', 'code' => '+44'],
    '+49'  => ['country' => 'Germany', 'code' => '+49'],
    '+55'  => ['country' => 'Brazil', 'code' => '+55'],
    '+61'  => ['country' => 'Australia', 'code' => '+61'],
    '+62'  => ['country' => 'Indonesia', 'code' => '+62'],
    '+7'   => ['country' => 'Russia', 'code' => '+7'],
    '+81'  => ['country' => 'Japan', 'code' => '+81'],
    '+82'  => ['country' => 'South Korea', 'code' => '+82'],
    '+86'  => ['country' => 'China', 'code' => '+86'],
    '+91'  => ['country' => 'India', 'code' => '+91'],
    '+1'   => ['country' => 'USA/Canada', 'code' => '+1'],
    '+20'  => ['country' => 'Egypt', 'code' => '+20'],
    '+33'  => ['country' => 'France', 'code' => '+33'],
];

$detectedCountry = null;
$detectedCode = null;


$prefixes = array_keys($countryMap);
usort($prefixes, function($a, $b) {
    return strlen($b) - strlen($a);
});

foreach ($prefixes as $prefix) {
    if (str_starts_with($number, $prefix)) {
        $detectedCountry = $countryMap[$prefix]['country'];
        $detectedCode = $countryMap[$prefix]['code'];
        break;
    }
}

$afterPlus = substr($number, 1);
if (!ctype_digit($afterPlus)) {
    echo json_encode([
        'ok' => true,
        'valid' => false,
        'number' => $number,
        'country' => $detectedCountry,
        'country_code' => $detectedCode,
        'reason' => 'Number contains invalid characters after the +' . $afterPlus
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'valid' => true,
    'number' => $number,
    'country' => $detectedCountry,
    'country_code' => $detectedCode
]);
