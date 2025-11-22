<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Audit\AuditLogger;
use App\Support\Checklist\ChecklistRepository;
use App\Support\Clock;
use App\Support\Codes\PickupCodeGenerator;
use App\Support\Documents\DocumentRepository;
use App\Support\Notifications\NotificationService;
use App\Support\Repositories\AppointmentRepository;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\EmployeeRepository;
use App\Support\Repositories\NoteRepository;
use App\Support\Repositories\PartnerRepository;
use App\Support\Repositories\WarehouseRepository;
use App\Support\Workflow\RepairWorkflowRepository;
use App\Validation\InputValidator;
use App\Support\View;
use Dompdf\Dompdf;
use Dompdf\Options;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';
require_once __DIR__ . '/templates/partials/field-help.php';

$caseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($caseId === null || $caseId === false) {
    Response::error('Ongeldig of ontbrekend case-ID.', 400);
}

$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);
$checklistRepository = new ChecklistRepository($pdo);
$auditLogger = new AuditLogger($pdo);
$warehouseRepository = new WarehouseRepository($pdo);
$employeeRepository = new EmployeeRepository($pdo);
$partnerRepository = new PartnerRepository($pdo);
$appointmentRepository = new AppointmentRepository($pdo);
$notificationService = new NotificationService($pdo);
$documentRepository = new DocumentRepository($pdo);
$pickupCodeGenerator = new PickupCodeGenerator($pdo);
$workflowRepository = new RepairWorkflowRepository($pdo);

$case = $caseRepository->findById((int) $caseId);
if ($case === null) {
    Response::error('Case niet gevonden.', 404);
}

$statement = $pdo->prepare(
    'SELECT c.*, cust.full_name, cust.email, cust.phone, cust.address, cust.postal_code, cust.city,
            dev.brand AS device_brand, dev.model AS device_model, dev.serial_number AS device_serial
     FROM cases c
     INNER JOIN customers cust ON cust.id = c.customer_id
     LEFT JOIN devices dev ON dev.id = c.device_id
     WHERE c.id = :id'
);
$statement->execute(['id' => $caseId]);
$caseRecord = $statement->fetch();
if (!$caseRecord) {
    Response::error('Casegegevens konden niet worden geladen.', 500);
}

$caseCustomerEmail = trim((string) ($caseRecord['email'] ?? ''));
$caseCustomerPhone = trim((string) ($caseRecord['phone'] ?? ''));

$warehouseStatusLabels = $warehouseRepository->statusLabels();
$warehouseItems = $warehouseRepository->findByCaseId((int) $caseId);
$warehouseSummary = [
    'total' => count($warehouseItems),
    'ready' => 0,
    'reserved' => 0,
    'in_service' => 0,
];
$warehouseTotals = [
    'quantity' => 0,
    'reserved' => 0,
];

foreach ($warehouseItems as $warehouseItem) {
    $statusKey = (string) ($warehouseItem['status'] ?? '');
    if ($statusKey === 'ready') {
        $warehouseSummary['ready']++;
    }
    if ($statusKey === 'reserved') {
        $warehouseSummary['reserved']++;
    }
    if ($statusKey === 'in_service') {
        $warehouseSummary['in_service']++;
    }

    $warehouseTotals['quantity'] += (int) ($warehouseItem['quantity'] ?? 0);
    $warehouseTotals['reserved'] += (int) ($warehouseItem['reserved_quantity'] ?? 0);
}

$pickupStatement = $pdo->prepare('SELECT * FROM ophaalbevestigingen WHERE case_id = :case_id ORDER BY created_at DESC');
$pickupStatement->execute(['case_id' => $caseId]);
$pickupConfirmations = $pickupStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$latestPickupConfirmation = $pickupConfirmations[0] ?? null;

$caseDetails = [];
if (!empty($caseRecord['details'])) {
    $decoded = json_decode((string) $caseRecord['details'], true);
    if (is_array($decoded)) {
        $caseDetails = $decoded;
    }
}

$detailItems = [];
$deviceModalItems = [];
$handledDetailKeys = [];

$formatDateTime = static function ($value): string {
    if ($value === null || $value === '') {
        return '';
    }

    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return (string) $value;
    }

    return date('d-m-Y H:i', $timestamp);
};

$normalizeValue = static function ($value): string {
    if (is_string($value)) {
        return trim($value) !== '' ? $value : '—';
    }

    if ($value === null) {
        return '—';
    }

    $stringValue = (string) $value;

    return trim($stringValue) !== '' ? $stringValue : '—';
};

if (array_key_exists('appointment_at', $caseDetails)) {
    $handledDetailKeys[] = 'appointment_at';
    $formatted = $formatDateTime($caseDetails['appointment_at']);
    $detailItems[] = [
        'key' => 'appointment_at',
        'label' => 'Appointment at',
        'value' => $formatted !== '' ? $formatted : '—',
    ];
}

if (array_key_exists('appointment_end', $caseDetails)) {
    $handledDetailKeys[] = 'appointment_end';
    $formatted = $formatDateTime($caseDetails['appointment_end']);
    $detailItems[] = [
        'key' => 'appointment_end',
        'label' => 'Appointment end',
        'value' => $formatted !== '' ? $formatted : '—',
    ];
}

if (array_key_exists('problem_description', $caseDetails)) {
    $handledDetailKeys[] = 'problem_description';
    $detailItems[] = [
        'key' => 'problem_description',
        'label' => 'Problem description',
        'value' => $normalizeValue($caseDetails['problem_description']),
        'multiline' => true,
    ];
}

$deviceDetailMap = [
    'device_brand' => 'Brand',
    'device_model' => 'Model',
    'device_serial' => 'Serial',
    'device_type' => 'Type',
];

$deviceSummaryParts = [];
foreach ($deviceDetailMap as $key => $label) {
    if (array_key_exists($key, $caseDetails)) {
        $handledDetailKeys[] = $key;
        $value = $normalizeValue($caseDetails[$key]);
        if ($value !== '—' && in_array($key, ['device_brand', 'device_model'], true)) {
            $deviceSummaryParts[] = $value;
        }

        $deviceModalItems[] = [
            'label' => $label,
            'value' => $value,
        ];
    }
}

if ($deviceModalItems !== []) {
    $deviceLabel = $deviceSummaryParts !== [] ? implode(' ', $deviceSummaryParts) : 'Onbekend apparaat';
    $detailItems[] = [
        'key' => 'device',
        'label' => 'Device',
        'value' => $deviceLabel,
        'interactive' => true,
        'modal_id' => 'device-details',
    ];
}

if (array_key_exists('barcode', $caseDetails)) {
    $handledDetailKeys[] = 'barcode';
    $detailItems[] = [
        'key' => 'barcode',
        'label' => 'Barcode',
        'value' => $normalizeValue($caseDetails['barcode']),
    ];
}

if (array_key_exists('pickup_code', $caseDetails)) {
    $handledDetailKeys[] = 'pickup_code';
    $detailItems[] = [
        'key' => 'pickup_code',
        'label' => 'Ophaalcode',
        'value' => $normalizeValue($caseDetails['pickup_code']),
    ];
}

if (array_key_exists('pickup_ready_date', $caseDetails)) {
    $handledDetailKeys[] = 'pickup_ready_date';
    $detailItems[] = [
        'key' => 'pickup_ready_date',
        'label' => 'Datum afgifte',
        'value' => $normalizeValue($caseDetails['pickup_ready_date']),
    ];
}

if (array_key_exists('case_closed_at', $caseDetails)) {
    $handledDetailKeys[] = 'case_closed_at';
    $closedAtValue = $formatDateTime($caseDetails['case_closed_at']);
    $detailItems[] = [
        'key' => 'case_closed_at',
        'label' => 'Case gesloten op',
        'value' => $closedAtValue !== '' ? $closedAtValue : '—',
    ];
}

if (array_key_exists('registered_by', $caseDetails)) {
    $handledDetailKeys[] = 'registered_by';
    $detailItems[] = [
        'key' => 'registered_by',
        'label' => 'Registered by',
        'value' => $normalizeValue($caseDetails['registered_by']),
    ];
}

if (array_key_exists('company_branch', $caseDetails)) {
    $handledDetailKeys[] = 'company_branch';
    $detailItems[] = [
        'key' => 'company_branch',
        'label' => 'Company branch',
        'value' => $normalizeValue($caseDetails['company_branch']),
    ];
}

if (array_key_exists('company_address_line', $caseDetails)) {
    $handledDetailKeys[] = 'company_address_line';
    $detailItems[] = [
        'key' => 'company_address_line',
        'label' => 'Company address line',
        'value' => $normalizeValue($caseDetails['company_address_line']),
        'multiline' => true,
    ];
}

foreach ($caseDetails as $key => $value) {
    if (in_array($key, $handledDetailKeys, true)) {
        continue;
    }

    $label = str_replace('_', ' ', (string) $key);
    $label = ucfirst($label);

    if (is_array($value)) {
        $detailItems[] = [
            'key' => (string) $key,
            'label' => $label,
            'value' => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'preformatted' => true,
        ];
        continue;
    }

    $detailItems[] = [
        'key' => (string) $key,
        'label' => $label,
        'value' => $normalizeValue($value),
        'multiline' => is_string($value) && strpos((string) $value, "\n") !== false,
    ];
}

$appointmentAtRaw = null;
if (isset($caseDetails['appointment_at']) && is_string($caseDetails['appointment_at']) && $caseDetails['appointment_at'] !== '') {
    $appointmentAtRaw = (string) $caseDetails['appointment_at'];
}

$appointmentTimestamp = $appointmentAtRaw !== null ? strtotime($appointmentAtRaw) : false;
$appointmentHasPassed = $appointmentTimestamp !== false && $appointmentTimestamp < time();

if (($caseRecord['status'] ?? '') === 'gepland' && $appointmentHasPassed) {
    $caseRepository->updateStatus((int) $caseId, 'vertraagd');
    $caseRecord['status'] = 'vertraagd';
    $case['status'] = 'vertraagd';
}

$attendanceStatus = isset($caseDetails['attendance_status']) ? (string) $caseDetails['attendance_status'] : null;
$attendanceHandledAt = isset($caseDetails['attendance_handled_at']) ? (string) $caseDetails['attendance_handled_at'] : '';

$shouldShowAttendanceModal = in_array($caseRecord['status'], ['gepland', 'vertraagd'], true)
    && (
        $attendanceHandledAt === ''
        || ($attendanceStatus === 'rescheduled' && $appointmentHasPassed)
    );

$rescheduleDefaultValue = '';
if ($appointmentTimestamp !== false) {
    $rescheduleDefaultValue = date('Y-m-d\TH:i', $appointmentTimestamp);
}

$priorityValue = (string) ($caseRecord['priority'] ?? '');
$slaDueInputValue = '';
if (!empty($caseRecord['sla_due_at'])) {
    $slaDueInputValue = date('Y-m-d\TH:i', strtotime((string) $caseRecord['sla_due_at']));
}
$primaryEmployeeValue = $caseRecord['primary_employee_id'] ?? null;

$activeEmployees = $employeeRepository->all('active');
$caseAssignments = $caseRepository->assignments((int) $caseId);
$caseAppointments = $appointmentRepository->forCase((int) $caseId);
$activeCaseAssignments = array_values(array_filter(
    $caseAssignments,
    static fn (array $assignment): bool => empty($assignment['unassigned_at'])
));
$currentEmployeeProfile = $employeeRepository->findByUsername(Auth::username());
$currentEmployeeId = $currentEmployeeProfile !== null ? (int) $currentEmployeeProfile['id'] : null;
$userAlreadyAssigned = false;
foreach ($activeCaseAssignments as $assignment) {
    if ((int) ($assignment['employee_id'] ?? 0) === $currentEmployeeId) {
        $userAlreadyAssigned = true;
        break;
    }
}

$appointmentTypePresets = [
    'intake_visit' => [
        'label' => 'Intake w serwisie',
        'description' => 'Klient odwiedza serwis, aby przekazać urządzenie do dalszych działań.',
        'default_title' => 'Intake wizyta',
        'default_duration' => 30,
        'status' => 'scheduled',
        'color' => '#F05A28',
        'confirmation_method' => 'email',
        'confirmation_status' => 'pending',
    ],
    'pickup_visit' => [
        'label' => 'Odbiór urządzenia',
        'description' => 'Serwisant udaje się do klienta, aby odebrać sprzęt.',
        'default_title' => 'Odbiór urządzenia',
        'default_duration' => 45,
        'status' => 'scheduled',
        'color' => '#2563EB',
        'confirmation_method' => 'phone',
        'confirmation_status' => 'awaiting',
    ],
    'diagnostics_session' => [
        'label' => 'Diagnostyka',
        'description' => 'Spotkanie poświęcone analizie problemu i testom urządzenia.',
        'default_title' => 'Sesja diagnostyczna',
        'default_duration' => 60,
        'status' => 'tentative',
        'color' => '#7C3AED',
        'confirmation_method' => 'email',
        'confirmation_status' => 'pending',
    ],
    'delivery_visit' => [
        'label' => 'Dowóz / wydanie',
        'description' => 'Dostarczenie naprawionego urządzenia do klienta lub przekazanie na miejscu.',
        'default_title' => 'Dowóz urządzenia',
        'default_duration' => 45,
        'status' => 'confirmed',
        'color' => '#0EA5E9',
        'confirmation_method' => 'phone',
        'confirmation_status' => 'confirmed',
    ],
    'service_followup' => [
        'label' => 'Kontrola po serwisie',
        'description' => 'Wizyta sprawdzająca po zakończonej naprawie.',
        'default_title' => 'Kontrola serwisowa',
        'default_duration' => 30,
        'status' => 'scheduled',
        'color' => '#22C55E',
        'confirmation_method' => 'email',
        'confirmation_status' => 'pending',
    ],
];

$defaultAppointmentType = 'intake_visit';
if (!isset($appointmentTypePresets[$defaultAppointmentType])) {
    $presetKeys = array_keys($appointmentTypePresets);
    $defaultAppointmentType = $presetKeys[0] ?? 'intake_visit';
}
$defaultPreset = $appointmentTypePresets[$defaultAppointmentType] ?? [];
$appointmentDurationOptions = [30, 45, 60, 90, 120, 180];
$appointmentStatusOptions = [
    'scheduled' => 'Zaplanowana',
    'tentative' => 'Wstępna',
    'confirmed' => 'Potwierdzona',
    'completed' => 'Zakończona',
    'cancelled' => 'Anulowana',
    'no_show' => 'Nieobecność',
];
$appointmentConfirmationMethods = [
    '' => '—',
    'phone' => 'Telefon',
    'email' => 'E-mail',
    'sms' => 'SMS',
];
$appointmentConfirmationStatuses = [
    '' => '—',
    'pending' => 'W trakcie potwierdzania',
    'awaiting' => 'Oczekuje',
    'confirmed' => 'Potwierdzone',
    'declined' => 'Odrzucone',
];

$appointmentFormValues = [
    'title' => (string) ($defaultPreset['default_title'] ?? 'Wizyta serwisowa'),
    'appointment_type' => $defaultAppointmentType,
    'start_at' => '',
    'duration' => (string) ($defaultPreset['default_duration'] ?? 60),
    'location' => '',
    'notes' => '',
    'status' => (string) ($defaultPreset['status'] ?? 'scheduled'),
    'color' => (string) ($defaultPreset['color'] ?? '#2563EB'),
    'confirmation_method' => (string) ($defaultPreset['confirmation_method'] ?? ''),
    'confirmation_status' => (string) ($defaultPreset['confirmation_status'] ?? ''),
    'employees' => $currentEmployeeId ? [(string) $currentEmployeeId] : [],
    'resources' => '',
];
$appointmentFormErrors = [];
$shouldOpenAppointmentModal = false;

$closeCaseFormValues = [
    'pickup_code' => htmlspecialchars((string) ($caseDetails['pickup_code'] ?? ($caseRecord['reference_code'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'ready_date' => isset($caseDetails['pickup_ready_date']) ? substr((string) $caseDetails['pickup_ready_date'], 0, 10) : date('Y-m-d'),
    'remarks' => '',
    'closing_note' => '',
    'notify_email' => $caseCustomerEmail !== '' ? '1' : '',
    'notify_sms' => '',
];
$closeCaseErrors = [];
$shouldOpenCaseCloseModal = false;

$errors = [];
$checklistErrors = [];
$noteEditErrors = [];
$noteEditValues = [];
$partnerAssignErrors = [];
$partnerRequestErrors = [];
$partnerResponseErrors = [];
$partnerEstimateErrors = [];
$partnerCorrectionErrors = [];
$currentAction = null;
$attendanceErrors = [];
$attendanceFormValues = [
    'attendance_action' => '',
    'reschedule_at' => $rescheduleDefaultValue,
    'cancellation_reason' => '',
];

$workflowStages = [
    'intake' => 'Intake & Diagnose',
    'repair' => 'Reparatie & Onderdelen',
    'quality_control' => 'Test & Validatie',
    'handover' => 'Afhandeling & Pickup',
];
$workflowStatuses = [
    'todo' => 'Te plannen',
    'in_progress' => 'In uitvoering',
    'blocked' => 'Geblokkeerd',
    'done' => 'Afgerond',
];
$workflowPriorities = [
    'low' => 'Laag',
    'normal' => 'Normaal',
    'high' => 'Hoog',
    'critical' => 'Kritiek',
];
$workflowTemplates = [
    'standard_repair' => [
        'label' => 'Standaard reparatieproces',
        'description' => 'Compleet traject van intake, reparatie, testen en overdracht.',
        'tasks' => [
            ['stage' => 'intake', 'priority' => 'normal', 'title' => 'Technische intake', 'description' => 'Analyseer klacht, verzamel symptoomdata en noteer bijzonderheden.'],
            ['stage' => 'repair', 'priority' => 'high', 'title' => 'Onderdelen bestellen/monteren', 'description' => 'Controleer magazijn, reserveer onderdelen en plan de fysieke reparatie.'],
            ['stage' => 'quality_control', 'priority' => 'normal', 'title' => 'Volledige testcyclus', 'description' => 'Voer minimaal 3 tests uit (stress, performance, diagnostyka).'],
            ['stage' => 'handover', 'priority' => 'normal', 'title' => 'Afronding & pickup', 'description' => 'Werk logboek bij, stel pickup-code op en informeer klant.'],
        ],
    ],
    'data_recovery' => [
        'label' => 'Proces odzysku danych',
        'description' => 'Plan diagnostykę, odzysk i raport końcowy.',
        'tasks' => [
            ['stage' => 'intake', 'priority' => 'normal', 'title' => 'Ocena nośnika', 'description' => 'Zweryfikuj typ nośnika, poziom uszkodzeń i potrzebne narzędzia.'],
            ['stage' => 'repair', 'priority' => 'critical', 'title' => 'Sesja odzyskiwania danych', 'description' => 'Uruchom oprogramowanie DR, monitoruj postęp i loguj błędy.'],
            ['stage' => 'quality_control', 'priority' => 'high', 'title' => 'Weryfikacja i kopia kontrolna', 'description' => 'Zweryfikuj losowe próbki plików oraz przygotuj kopię na nośnik klienta.'],
            ['stage' => 'handover', 'priority' => 'normal', 'title' => 'Raport + przekazanie', 'description' => 'Przygotuj raport dla klienta, potwierdź integralność danych i ustal wydanie.'],
        ],
    ],
];
$workflowFormDefaults = [
    'title' => '',
    'stage' => 'intake',
    'priority' => 'normal',
    'assigned_to' => '',
    'due_at' => '',
    'description' => '',
];
$workflowFormValues = $workflowFormDefaults;
$workflowErrors = [];
$workflowTaskErrors = [];

$isPartnerUser = Auth::role() === 'partner';
$assignedPartnerId = isset($caseDetails['partner_id']) ? (int) $caseDetails['partner_id'] : null;
$partnerViewingOwnCase = $isPartnerUser && $assignedPartnerId !== null && $assignedPartnerId === Auth::id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new ValidationException(['general' => 'Ongeldige sessie, probeer opnieuw.']);
        }

        $action = $_POST['action'] ?? 'add-note';
        $currentAction = $action;

        if ($action === 'intake-attendance') {
            $attendanceFormValues['attendance_action'] = isset($_POST['attendance_action']) ? (string) $_POST['attendance_action'] : '';
            if (isset($_POST['reschedule_at']) && $_POST['reschedule_at'] !== '') {
                $attendanceFormValues['reschedule_at'] = (string) $_POST['reschedule_at'];
            }
            $attendanceFormValues['cancellation_reason'] = isset($_POST['cancellation_reason']) ? (string) $_POST['cancellation_reason'] : '';
            } elseif ($action === 'close-case') {
            $closeCaseFormValues['pickup_code'] = isset($_POST['pickup_code']) ? htmlspecialchars((string) $_POST['pickup_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $closeCaseFormValues['pickup_code'];
            $closeCaseFormValues['ready_date'] = isset($_POST['ready_date']) ? (string) $_POST['ready_date'] : $closeCaseFormValues['ready_date'];
            $closeCaseFormValues['remarks'] = isset($_POST['remarks']) ? htmlspecialchars((string) $_POST['remarks'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $closeCaseFormValues['remarks'];
            $closeCaseFormValues['closing_note'] = isset($_POST['closing_note']) ? htmlspecialchars((string) $_POST['closing_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $closeCaseFormValues['closing_note'];
            $closeCaseFormValues['notify_email'] = isset($_POST['notify_email']) ? '1' : '';
            $closeCaseFormValues['notify_sms'] = isset($_POST['notify_sms']) ? '1' : '';
        } elseif ($action === 'workflow-create-task') {
            $workflowFormValues['title'] = isset($_POST['title']) ? (string) $_POST['title'] : $workflowFormValues['title'];
            $workflowFormValues['stage'] = isset($_POST['stage']) ? (string) $_POST['stage'] : $workflowFormValues['stage'];
            $workflowFormValues['priority'] = isset($_POST['priority']) ? (string) $_POST['priority'] : $workflowFormValues['priority'];
            $workflowFormValues['assigned_to'] = isset($_POST['assigned_to']) ? (string) $_POST['assigned_to'] : $workflowFormValues['assigned_to'];
            $workflowFormValues['due_at'] = isset($_POST['due_at']) ? (string) $_POST['due_at'] : $workflowFormValues['due_at'];
            $workflowFormValues['description'] = isset($_POST['description']) ? (string) $_POST['description'] : $workflowFormValues['description'];
        }

        switch ($action) {
          case 'create-appointment':
                $appointmentFormValues['title'] = isset($_POST['title']) ? (string) $_POST['title'] : $appointmentFormValues['title'];
                $appointmentFormValues['appointment_type'] = isset($_POST['appointment_type']) ? (string) $_POST['appointment_type'] : $appointmentFormValues['appointment_type'];
                $appointmentFormValues['start_at'] = isset($_POST['start_at']) ? (string) $_POST['start_at'] : $appointmentFormValues['start_at'];
                $appointmentFormValues['duration'] = isset($_POST['duration']) ? (string) $_POST['duration'] : $appointmentFormValues['duration'];
                $appointmentFormValues['location'] = isset($_POST['location']) ? (string) $_POST['location'] : $appointmentFormValues['location'];
                $appointmentFormValues['notes'] = isset($_POST['notes']) ? (string) $_POST['notes'] : $appointmentFormValues['notes'];
                $appointmentFormValues['status'] = isset($_POST['status']) ? (string) $_POST['status'] : $appointmentFormValues['status'];
                $appointmentFormValues['color'] = isset($_POST['color']) ? (string) $_POST['color'] : $appointmentFormValues['color'];
                $appointmentFormValues['confirmation_method'] = isset($_POST['confirmation_method']) ? (string) $_POST['confirmation_method'] : $appointmentFormValues['confirmation_method'];
                $appointmentFormValues['confirmation_status'] = isset($_POST['confirmation_status']) ? (string) $_POST['confirmation_status'] : $appointmentFormValues['confirmation_status'];
                $appointmentFormValues['resources'] = isset($_POST['resources']) ? (string) $_POST['resources'] : $appointmentFormValues['resources'];
                $appointmentFormValues['employees'] = array_map('strval', (array) ($_POST['employees'] ?? $appointmentFormValues['employees']));

                $selectedType = InputValidator::requireString($_POST, 'appointment_type', 64);
                if (!isset($appointmentTypePresets[$selectedType])) {
                    throw new ValidationException(['appointment_type' => 'Wybierz prawidłowy rodzaj wizyty.']);
                }

                $title = InputValidator::requireString($_POST, 'title', 191);
                $startRaw = InputValidator::requireString($_POST, 'start_at', 32);
                $durationMinutes = filter_var($_POST['duration'] ?? null, FILTER_VALIDATE_INT);
                if ($durationMinutes === false || $durationMinutes < 15 || $durationMinutes > 480) {
                    throw new ValidationException(['duration' => 'Wybierz czas trwania wizyty (15–480 minut).']);
                }

                $startAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startRaw);
                if (!$startAt instanceof DateTimeImmutable) {
                    throw new ValidationException(['start_at' => 'Podaj prawidłową datę i godzinę wizyty.']);
                }

                $endAt = $startAt->add(new DateInterval('PT' . $durationMinutes . 'M'));

                $employeeIds = array_map('intval', (array) ($_POST['employees'] ?? []));
                $employeeIds = array_values(array_filter($employeeIds, static fn (int $value): bool => $value > 0));
                if ($employeeIds === []) {
                    throw new ValidationException(['employees' => 'Wybierz przynajmniej jednego pracownika.']);
                }

                $location = InputValidator::optionalString($_POST, 'location', 191);
                $notes = InputValidator::optionalString($_POST, 'notes', 500);
                $status = InputValidator::optionalString($_POST, 'status', 32);
                $status = $status !== '' ? $status : (string) ($appointmentTypePresets[$selectedType]['status'] ?? 'scheduled');
                $color = InputValidator::optionalString($_POST, 'color', 16);
                if ($color === '' && isset($appointmentTypePresets[$selectedType]['color'])) {
                    $color = (string) $appointmentTypePresets[$selectedType]['color'];
                }
                $confirmationMethod = InputValidator::optionalString($_POST, 'confirmation_method', 64);
                $confirmationStatus = InputValidator::optionalString($_POST, 'confirmation_status', 32);
                $resourcesRaw = InputValidator::optionalString($_POST, 'resources', 2000);

                $resources = [];
                if ($resourcesRaw !== '') {
                    $resourceLines = preg_split('/\r?\n/', $resourcesRaw, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($resourceLines as $line) {
                        $trimmedLine = trim($line);
                        if ($trimmedLine === '') {
                            continue;
                        }
                        $parts = array_map('trim', explode('|', $trimmedLine));
                        $labelSource = $parts[1] !== '' ? $parts[1] : ($parts[0] !== '' ? $parts[0] : $trimmedLine);
                        $resources[] = [
                            'type' => $parts[0] !== '' ? $parts[0] : 'resource',
                            'label' => $labelSource,
                            'details' => $parts[2] !== '' ? $parts[2] : null,
                        ];
                    }
                }

                $conflicts = $appointmentRepository->conflicts(
                    $startAt->format('Y-m-d H:i:s'),
                    $endAt->format('Y-m-d H:i:s'),
                    $employeeIds
                );

                if ($conflicts !== []) {
                    throw new ValidationException(['general' => 'Wybrani pracownicy mają już wizytę w tym czasie.']);
                }

                $appointmentCustomerId = isset($caseRecord['customer_id']) ? (int) $caseRecord['customer_id'] : 0;

                $appointment = $appointmentRepository->create(
                    $title,
                    $selectedType,
                    $status,
                    $startAt->format('Y-m-d H:i:s'),
                    $endAt->format('Y-m-d H:i:s'),
                    (int) $caseId,
                    $appointmentCustomerId > 0 ? $appointmentCustomerId : null,
                    $location !== '' ? $location : null,
                    $notes !== '' ? $notes : null,
                    $color !== '' ? $color : null,
                    $confirmationMethod !== '' ? $confirmationMethod : null,
                    $confirmationStatus !== '' ? $confirmationStatus : null,
                    null,
                    Auth::username(),
                    Auth::username(),
                    $employeeIds,
                    $resources
                );

                $customerId = $appointmentCustomerId;
                if ($customerId > 0) {
                    $noteLabel = $appointmentTypePresets[$selectedType]['label'] ?? $selectedType;
                    $noteRepository->add(
                        (int) $caseId,
                        $customerId,
                        Auth::username(),
                        sprintf(
                            'Zaplanowano wizytę: %s (%s).',
                            $noteLabel,
                            $startAt->format('d-m-Y H:i')
                        )
                    );
                }

                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'appointment_created', [
                    'appointment_id' => (int) ($appointment['id'] ?? 0),
                    'type' => $selectedType,
                    'start_at' => $startAt->format('Y-m-d H:i:s'),
                    'end_at' => $endAt->format('Y-m-d H:i:s'),
                    'employees' => $employeeIds,
                ]);

                Response::redirect('case.php?id=' . (int) $caseId . '&appointment_created=1');
            case 'intake-attendance':
                $attendanceActionRaw = InputValidator::requireString($_POST, 'attendance_action', 40);
                $attendanceAction = strtolower($attendanceActionRaw);
                $allowedAttendanceActions = ['arrived', 'no_show', 'rescheduled', 'cancelled'];
                if (!in_array($attendanceAction, $allowedAttendanceActions, true)) {
                    throw new ValidationException(['attendance_action' => 'Onbekende keuze.']);
                }

                $updatedDetails = $caseDetails;
                $now = Clock::now();
                $handledAt = $now->format('Y-m-d H:i:s');
                $updatedDetails['attendance_handled_at'] = $handledAt;
                $updatedDetails['attendance_handled_by'] = Auth::username();

                $appointmentLabel = null;
                if ($appointmentTimestamp !== false) {
                    $appointmentLabel = date('d-m-Y H:i', $appointmentTimestamp);
                }

                $intakeAppointment = null;
                foreach ($caseAppointments as $appointment) {
                    if (($appointment['appointment_type'] ?? '') === 'intake_visit') {
                        $intakeAppointment = $appointment;
                        break;
                    }
                }

                $customerId = (int) ($caseRecord['customer_id'] ?? 0);
                $customerEmail = isset($caseRecord['email']) ? trim((string) $caseRecord['email']) : '';
                $customerName = (string) ($caseRecord['full_name'] ?? 'klant');
                $referenceCode = (string) ($caseRecord['reference_code'] ?? '');
                $emailResult = null;
                $noteMessage = '';

                if ($attendanceAction === 'arrived') {
                    $updatedDetails['attendance_status'] = 'arrived';
                    $updatedDetails['arrival_confirmed_at'] = $handledAt;

                    $caseRepository->updateDetails((int) $caseId, $updatedDetails);
                    $caseRepository->updateStatus((int) $caseId, 'intake');
                    $caseRecord['status'] = 'intake';
                    $case['status'] = 'intake';

                    if ($intakeAppointment && isset($intakeAppointment['id'])) {
                        $appointmentRepository->updateStatus((int) $intakeAppointment['id'], 'completed', Auth::username());
                    }

                    if ($customerEmail !== '') {
                        $emailResult = $notificationService->sendIntakeArrivalAcknowledgement(
                            (int) $caseId,
                            $customerId ?: null,
                            $customerEmail,
                            [
                                'customer_name' => $customerName,
                                'reference_code' => $referenceCode,
                                'appointment_at' => $appointmentLabel ?? $now->format('d-m-Y H:i'),
                            ]
                        );
                    }

                    $noteMessage = 'Klant verschenen voor intake. Apparatuur ontvangen en werkzaamheden gestart.';
                    if ($emailResult !== null && !$emailResult['success']) {
                        $noteMessage .= ' (E-mail verzenden mislukt: ' . (string) ($emailResult['error'] ?? 'onbekende fout') . ')';
                    }

                    $noteRepository->add((int) $caseId, $customerId, Auth::username(), $noteMessage);
                    $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'intake_attendance_arrived', [
                        'appointment_at' => $appointmentAtRaw,
                        'email_sent' => $emailResult === null ? 'not_requested' : ($emailResult['success'] ? 'sent' : 'failed'),
                    ]);
                } elseif ($attendanceAction === 'no_show') {
                    $updatedDetails['attendance_status'] = 'no_show';

                    $caseRepository->updateDetails((int) $caseId, $updatedDetails);
                    $caseRepository->updateStatus((int) $caseId, 'vertraagd');
                    $caseRecord['status'] = 'vertraagd';
                    $case['status'] = 'vertraagd';

                    if ($intakeAppointment && isset($intakeAppointment['id'])) {
                        $appointmentRepository->updateStatus((int) $intakeAppointment['id'], 'no_show', Auth::username());
                    }

                    if ($customerEmail !== '') {
                        $emailResult = $notificationService->sendIntakeNoShow(
                            (int) $caseId,
                            $customerId ?: null,
                            $customerEmail,
                            [
                                'customer_name' => $customerName,
                                'appointment_at' => $appointmentLabel ?? ($appointmentAtRaw ?? ''),
                            ]
                        );
                    }

                    $noteMessage = 'Klant is niet verschenen op de intakeafspraak.';
                    if ($emailResult !== null && !$emailResult['success']) {
                        $noteMessage .= ' (E-mail verzenden mislukt: ' . (string) ($emailResult['error'] ?? 'onbekende fout') . ')';
                    }

                    $noteRepository->add((int) $caseId, $customerId, Auth::username(), $noteMessage);
                    $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'intake_attendance_no_show', [
                        'appointment_at' => $appointmentAtRaw,
                        'email_sent' => $emailResult === null ? 'not_requested' : ($emailResult['success'] ? 'sent' : 'failed'),
                    ]);
                } elseif ($attendanceAction === 'rescheduled') {
                    $rescheduleRaw = InputValidator::requireString($_POST, 'reschedule_at', 25);
                    $rescheduleAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $rescheduleRaw);
                    if (!$rescheduleAt instanceof DateTimeImmutable) {
                        throw new ValidationException(['reschedule_at' => 'Ongeldige datum/tijd opgegeven.']);
                    }

                    $rescheduleEnd = $rescheduleAt->add(new DateInterval('PT30M'));
                    $updatedDetails['attendance_status'] = 'rescheduled';
                    $updatedDetails['rescheduled_from'] = $appointmentAtRaw;
                    $updatedDetails['rescheduled_at'] = $handledAt;
                    $updatedDetails['appointment_at'] = $rescheduleAt->format('Y-m-d H:i:s');
                    $updatedDetails['appointment_end'] = $rescheduleEnd->format('Y-m-d H:i:s');

                    $caseRepository->updateDetails((int) $caseId, $updatedDetails);
                    $caseRepository->updateStatus((int) $caseId, 'gepland');
                    $caseRecord['status'] = 'gepland';
                    $case['status'] = 'gepland';

                    if ($intakeAppointment && isset($intakeAppointment['id'])) {
                        $appointmentRepository->updateSchedule(
                            (int) $intakeAppointment['id'],
                            $rescheduleAt->format('Y-m-d H:i:s'),
                            $rescheduleEnd->format('Y-m-d H:i:s'),
                            Auth::username()
                        );
                        $appointmentRepository->updateStatus((int) $intakeAppointment['id'], 'scheduled', Auth::username());
                    }

                    if ($customerEmail !== '') {
                        $emailResult = $notificationService->sendIntakeRescheduled(
                            (int) $caseId,
                            $customerId ?: null,
                            $customerEmail,
                            [
                                'customer_name' => $customerName,
                                'appointment_at' => $rescheduleAt->format('d-m-Y H:i'),
                                'reference_code' => $referenceCode,
                            ]
                        );
                    }

                    $noteMessage = sprintf('Intakeafspraak verplaatst naar %s.', $rescheduleAt->format('d-m-Y H:i'));
                    if ($emailResult !== null && !$emailResult['success']) {
                        $noteMessage .= ' (E-mail verzenden mislukt: ' . (string) ($emailResult['error'] ?? 'onbekende fout') . ')';
                    }

                    $noteRepository->add((int) $caseId, $customerId, Auth::username(), $noteMessage);
                    $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'intake_rescheduled', [
                        'previous_appointment' => $appointmentAtRaw,
                        'new_appointment' => $updatedDetails['appointment_at'],
                        'email_sent' => $emailResult === null ? 'not_requested' : ($emailResult['success'] ? 'sent' : 'failed'),
                    ]);
                } else { // cancelled
                    $cancellationReason = InputValidator::requireString($_POST, 'cancellation_reason', 500);
                    $updatedDetails['attendance_status'] = 'cancelled';
                    $updatedDetails['cancellation_reason'] = $cancellationReason;
                    $updatedDetails['appointment_cancelled_at'] = $handledAt;

                    $caseRepository->updateStatusAndDetails((int) $caseId, 'geannuleerd', $updatedDetails);
                    $caseRecord['status'] = 'geannuleerd';
                    $case['status'] = 'geannuleerd';

                    if ($intakeAppointment && isset($intakeAppointment['id'])) {
                        $appointmentRepository->updateStatus((int) $intakeAppointment['id'], 'cancelled', Auth::username());
                    }

                    if ($customerEmail !== '') {
                        $emailResult = $notificationService->sendIntakeCancellation(
                            (int) $caseId,
                            $customerId ?: null,
                            $customerEmail,
                            [
                                'customer_name' => $customerName,
                                'appointment_at' => $appointmentLabel ?? ($appointmentAtRaw ?? ''),
                                'reference_code' => $referenceCode,
                                'cancellation_reason' => $cancellationReason,
                            ]
                        );
                    }

                    $noteMessage = 'Intake geannuleerd: ' . $cancellationReason;
                    if ($emailResult !== null && !$emailResult['success']) {
                        $noteMessage .= ' (E-mail verzenden mislukt: ' . (string) ($emailResult['error'] ?? 'onbekende fout') . ')';
                    }

                    $noteRepository->add((int) $caseId, $customerId, Auth::username(), $noteMessage);
                    $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'intake_cancelled', [
                        'appointment_at' => $appointmentAtRaw,
                        'reason' => $cancellationReason,
                        'email_sent' => $emailResult === null ? 'not_requested' : ($emailResult['success'] ? 'sent' : 'failed'),
                    ]);
                }

                $caseDetails = $updatedDetails;
                break;
                case 'close-case':
                $preferredCodeRaw = trim((string) ($_POST['pickup_code'] ?? ''));
                if (mb_strlen($preferredCodeRaw) > 32) {
                    throw new ValidationException(['pickup_code' => 'Ophaalcode mag maximaal 32 tekens bevatten.']);
                }

                $readyDate = InputValidator::requireDate($_POST, 'ready_date');

                $remarksRaw = trim((string) ($_POST['remarks'] ?? ''));
                if (mb_strlen($remarksRaw) > 500) {
                    throw new ValidationException(['remarks' => 'Opmerkingen mogen maximaal 500 tekens bevatten.']);
                }

                $closingNoteRaw = trim((string) ($_POST['closing_note'] ?? ''));
                if (mb_strlen($closingNoteRaw) > 500) {
                    throw new ValidationException(['closing_note' => 'Notitie mag maximaal 500 tekens bevatten.']);
                }

                try {
                    $pickupCode = $pickupCodeGenerator->generate($preferredCodeRaw !== '' ? $preferredCodeRaw : null);
                } catch (\RuntimeException $exception) {
                    throw new ValidationException(['pickup_code' => $exception->getMessage()]);
                }

                $closeCaseFormValues['pickup_code'] = htmlspecialchars($pickupCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $closeCaseFormValues['ready_date'] = $readyDate;
                $closeCaseFormValues['remarks'] = htmlspecialchars($remarksRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $closeCaseFormValues['closing_note'] = htmlspecialchars($closingNoteRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                $shouldNotifyEmail = isset($_POST['notify_email']);
                $shouldNotifySms = isset($_POST['notify_sms']);

                $customerId = (int) $caseRecord['customer_id'];
                $customerName = trim((string) ($caseRecord['full_name'] ?? ''));
                if ($customerName === '') {
                    $customerName = 'Onbekende klant';
                }

                $customerEmail = trim((string) ($caseRecord['email'] ?? ''));
                $customerPhone = trim((string) ($caseRecord['phone'] ?? ''));
                $deviceBrand = trim((string) ($caseRecord['device_brand'] ?? ''));
                $deviceModel = trim((string) ($caseRecord['device_model'] ?? ''));
                $merkmodel = trim($deviceBrand . ' ' . $deviceModel);
                if ($merkmodel === '') {
                    $merkmodel = 'Onbekend apparaat';
                }

                $caseReference = trim((string) ($caseRecord['reference_code'] ?? ''));
                $now = Clock::nowFormatted();

                $insertStatement = $pdo->prepare(
                    'INSERT INTO ophaalbevestigingen '
                    . '(klantnaam, klantemail, klanttelefoon, merkmodel, apparaatmerk, apparaatmodel, ophaalcode, case_reference, case_id, datumgereed, status, opmerkingen, pickup_signed_at) '
                    . 'VALUES (:klantnaam, :klantemail, :klanttelefoon, :merkmodel, :apparaatmerk, :apparaatmodel, :ophaalcode, :case_reference, :case_id, :datumgereed, :status, :opmerkingen, :pickup_signed_at)'
                );
                $insertStatement->execute([
                    'klantnaam' => $customerName,
                    'klantemail' => $customerEmail !== '' ? $customerEmail : null,
                    'klanttelefoon' => $customerPhone !== '' ? $customerPhone : null,
                    'merkmodel' => $merkmodel,
                    'apparaatmerk' => $deviceBrand !== '' ? $deviceBrand : null,
                    'apparaatmodel' => $deviceModel !== '' ? $deviceModel : null,
                    'ophaalcode' => $pickupCode,
                    'case_reference' => $caseReference !== '' ? $caseReference : null,
                    'case_id' => (int) $caseId,
                    'datumgereed' => $readyDate,
                    'status' => 'opgehaald',
                    'opmerkingen' => $remarksRaw !== '' ? $remarksRaw : null,
                    'pickup_signed_at' => $now,
                ]);

                $pickupId = (int) $pdo->lastInsertId();

                $caseRepository->updateStatus((int) $caseId, 'opgehaald');
                $caseRecord['status'] = 'opgehaald';
                $case['status'] = 'opgehaald';

                $updatedDetails = $caseDetails;
                $updatedDetails['pickup_code'] = $pickupCode;
                $updatedDetails['pickup_ready_date'] = $readyDate;
                $updatedDetails['pickup_confirmation_id'] = $pickupId;
                $updatedDetails['case_closed_at'] = $now;
                $updatedDetails['case_closed_by'] = Auth::username();
                if ($closingNoteRaw !== '') {
                    $updatedDetails['case_closing_note'] = $closingNoteRaw;
                } else {
                    unset($updatedDetails['case_closing_note']);
                }
                if ($remarksRaw !== '') {
                    $updatedDetails['pickup_closing_notes'] = $remarksRaw;
                } else {
                    unset($updatedDetails['pickup_closing_notes']);
                }
                $caseRepository->updateDetails((int) $caseId, $updatedDetails);
                $caseDetails = $updatedDetails;

                $receiptHtml = View::render('pdf/apparaat-opgehaald.php', [
                    'documentTitel' => 'Apparaat Opgehaald',
                    'bedrijfsNaam' => 'Digivriend',
                    'huidigeDatum' => date('d-m-Y'),
                    'klantnaam' => htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'ophaalcode' => htmlspecialchars($pickupCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'merkmodel' => htmlspecialchars($merkmodel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'datumgereed' => htmlspecialchars($readyDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'pickupSignature' => '',
                ]);

                $pdfOptions = new Options();
                $pdfOptions->set('isRemoteEnabled', true);
                $dompdf = new Dompdf($pdfOptions);
                $dompdf->loadHtml($receiptHtml);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();

                $documentsDirectory = __DIR__ . '/storage/documents';
                if (!is_dir($documentsDirectory)) {
                    mkdir($documentsDirectory, 0775, true);
                }

                $documentFilename = sprintf('PickupReceipt[%s][%d].pdf', date('Ymd'), $pickupId);
                $documentPath = $documentsDirectory . '/' . $documentFilename;
                file_put_contents($documentPath, $dompdf->output());

                $documentRepository->store(
                    (int) $caseId,
                    'pickup_receipt',
                    'storage/documents/' . $documentFilename,
                    [
                        'pickup_confirmation_id' => $pickupId,
                        'pickup_code' => $pickupCode,
                        'pickup_date' => $readyDate,
                        'closed_by' => Auth::username(),
                    ]
                );

                $notifiedAt = null;
                if ($shouldNotifyEmail && $customerEmail !== '') {
                    $notificationService->sendPickupConfirmation(
                        (int) $caseId,
                        $customerId ?: null,
                        $customerEmail,
                        [
                            'customer_name' => $customerName,
                            'pickup_code' => $pickupCode,
                            'pickup_date' => date('d-m-Y', strtotime($readyDate)),
                        ]
                    );
                    $notifiedAt = $now;
                }

                if ($shouldNotifySms && $customerPhone !== '') {
                    $notificationService->sendSms(
                        (int) $caseId,
                        $customerId ?: null,
                        $customerPhone,
                        [
                            'body' => sprintf('Bedankt voor uw bezoek! Code %s is opgehaald.', $pickupCode),
                            'subject' => 'Pickup bevestigd',
                        ]
                    );
                    $notifiedAt = $notifiedAt ?? $now;
                }

                if ($notifiedAt !== null) {
                    $notifiedStatement = $pdo->prepare('UPDATE ophaalbevestigingen SET notified_collected_at = :notified WHERE id = :id');
                    $notifiedStatement->execute([
                        'notified' => $notifiedAt,
                        'id' => $pickupId,
                    ]);
                }

                $noteParts = [
                    'Case afgesloten als opgehaald.',
                    sprintf('Ophaalcode: %s.', $pickupCode),
                ];
                if ($closingNoteRaw !== '') {
                    $noteParts[] = 'Notitie: ' . $closingNoteRaw;
                }
                if ($remarksRaw !== '') {
                    $noteParts[] = 'Opmerkingen: ' . $remarksRaw;
                }
                $noteRepository->add(
                    (int) $caseId,
                    $customerId,
                    Auth::username(),
                    implode(' ', $noteParts)
                );

                $auditLogger->log(
                    (int) $caseId,
                    Auth::id(),
                    Auth::username(),
                    'case_closed_pickup',
                    [
                        'pickup_confirmation_id' => $pickupId,
                        'pickup_code' => $pickupCode,
                        'pickup_date' => $readyDate,
                        'notifications' => [
                            'email' => $shouldNotifyEmail && $customerEmail !== '',
                            'sms' => $shouldNotifySms && $customerPhone !== '',
                        ],
                    ]
                );

                Response::redirect('case.php?id=' . (int) $caseId . '&case_closed=1&pickup_id=' . $pickupId);
            case 'add-note':
                $body = InputValidator::requireString($_POST, 'body', 2000);
                $noteRepository->add((int) $caseId, (int) $caseRecord['customer_id'], Auth::username(), $body);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'note_added', ['body' => $body]);
                break;
              case 'self-assign':
                if ($currentEmployeeId === null) {
                    throw new ValidationException(['general' => 'Er is geen medewerkerprofiel gekoppeld aan dit account.']);
                }

                if ($userAlreadyAssigned) {
                    Response::redirect('case.php?id=' . (int) $caseId . '&assigned=1');
                }

                $assignmentRows = [];
                foreach ($activeCaseAssignments as $assignment) {
                    $assignmentRows[] = [
                        'employee_id' => (int) ($assignment['employee_id'] ?? 0),
                        'type' => (string) ($assignment['assignment_type'] ?? 'primary'),
                        'notes' => isset($assignment['notes']) ? (string) $assignment['notes'] : null,
                    ];
                }

                $assignmentRows[] = [
                    'employee_id' => $currentEmployeeId,
                    'type' => 'primary',
                    'notes' => null,
                ];

                $caseRepository->syncAssignments((int) $caseId, $assignmentRows, Auth::username());
                $assignedName = $currentEmployeeProfile['full_name'] ?? Auth::username();
                $noteRepository->add(
                    (int) $caseId,
                    (int) $caseRecord['customer_id'],
                    Auth::username(),
                    sprintf('Case toegewezen aan %s.', $assignedName)
                );
                $auditLogger->log(
                    (int) $caseId,
                    Auth::id(),
                    Auth::username(),
                    'case_self_assigned',
                    ['employee_id' => $currentEmployeeId]
                );

                Response::redirect('case.php?id=' . (int) $caseId . '&assigned=1');
            case 'update-note':
                $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$noteId) {
                    throw new ValidationException(['general' => 'Ongeldige notitie geselecteerd.']);
                }
                $body = InputValidator::requireString($_POST, 'body', 2000);
                if (!$noteRepository->update((int) $caseId, (int) $noteId, $body)) {
                    throw new ValidationException(['general' => 'Notitie niet gevonden.']);
                }
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'note_updated', ['note_id' => (int) $noteId, 'body' => $body]);
                break;
            case 'delete-note':
                $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$noteId) {
                    throw new ValidationException(['general' => 'Ongeldige notitie geselecteerd.']);
                }
                if (!$noteRepository->delete((int) $caseId, (int) $noteId)) {
                    throw new ValidationException(['general' => 'Notitie niet gevonden.']);
                }
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'note_deleted', ['note_id' => (int) $noteId]);
                break;
            case 'add-checklist':
                $title = InputValidator::requireString($_POST, 'title', 160);
                $assignedTo = InputValidator::optionalString($_POST, 'assigned_to', 120);
                $dueDateRaw = trim((string) ($_POST['due_date'] ?? ''));
                $dueDate = null;
                if ($dueDateRaw !== '') {
                    $dueDateInstance = date_create_immutable($dueDateRaw);
                    if (!$dueDateInstance instanceof \DateTimeImmutable) {
                        throw new ValidationException(['due_date' => 'Ongeldige datum opgegeven.']);
                    }
                    $dueDate = $dueDateInstance->format('Y-m-d');
                }
                $newChecklistId = $checklistRepository->createChecklist((int) $caseId, $title, $assignedTo !== '' ? $assignedTo : null, $dueDate);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'checklist_created', ['checklist_id' => $newChecklistId, 'title' => $title]);
                break;
            case 'add-checklist-item':
                $checklistId = filter_var($_POST['checklist_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$checklistId) {
                    throw new ValidationException(['checklist_id' => 'Ongeldige checklist.']);
                }
                $description = InputValidator::requireString($_POST, 'description', 255);
                $checklistRepository->addItem((int) $checklistId, $description);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'checklist_item_added', ['checklist_id' => (int) $checklistId]);
                break;
            case 'toggle-checklist-item':
                $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$itemId) {
                    throw new ValidationException(['item_id' => 'Ongeldig item.']);
                }
                $completed = isset($_POST['completed']) && $_POST['completed'] === '1';
                $checklistRepository->toggleItem((int) $itemId, $completed, Auth::username());
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'checklist_item_toggled', ['item_id' => (int) $itemId, 'completed' => $completed]);
                break;
            case 'remove-checklist':
                $checklistId = filter_var($_POST['checklist_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$checklistId) {
                    throw new ValidationException(['checklist_id' => 'Ongeldige checklist.']);
                }
                $checklistRepository->removeChecklist((int) $checklistId);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'checklist_removed', ['checklist_id' => (int) $checklistId]);
                break;
                case 'workflow-create-task':
                $title = InputValidator::requireString($_POST, 'title', 191);
                $stage = strtolower(InputValidator::requireString($_POST, 'stage', 64));
                if (!isset($workflowStages[$stage])) {
                    throw new ValidationException(['stage' => 'Wybierz poprawną fazę procesu.']);
                }
                $priorityInput = strtolower(InputValidator::requireString($_POST, 'priority', 32));
                if (!isset($workflowPriorities[$priorityInput])) {
                    throw new ValidationException(['priority' => 'Nieobsługiwany priorytet.']);
                }
                $assignedTo = InputValidator::optionalString($_POST, 'assigned_to', 120);
                $dueAtRaw = InputValidator::optionalString($_POST, 'due_at', 32);
                $dueAt = null;
                if ($dueAtRaw !== '') {
                    $dueDate = date_create_immutable($dueAtRaw);
                    if (!$dueDate instanceof \DateTimeImmutable) {
                        throw new ValidationException(['due_at' => 'Podaj poprawną datę.']);
                    }
                    $dueAt = $dueDate->format('Y-m-d H:i:s');
                }
                $description = InputValidator::optionalString($_POST, 'description', 2000);
                $newTaskId = $workflowRepository->create(
                    (int) $caseId,
                    $title,
                    $stage,
                    'todo',
                    $priorityInput,
                    $assignedTo !== '' ? $assignedTo : null,
                    $dueAt,
                    $description !== '' ? $description : null
                );
                $workflowFormValues = $workflowFormDefaults;
                $auditLogger->log(
                    (int) $caseId,
                    Auth::id(),
                    Auth::username(),
                    'workflow_task_created',
                    ['task_id' => $newTaskId, 'title' => $title, 'stage' => $stage]
                );
                break;
            case 'workflow-update-task':
                $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$taskId) {
                    throw new ValidationException(['task_id' => 'Nie znaleziono zadania.']);
                }
                $status = strtolower(InputValidator::requireString($_POST, 'status', 32));
                if (!isset($workflowStatuses[$status])) {
                    throw new ValidationException(['status' => 'Nieobsługiwany status.']);
                }
                $stageUpdate = strtolower(InputValidator::requireString($_POST, 'stage', 64));
                if (!isset($workflowStages[$stageUpdate])) {
                    throw new ValidationException(['stage' => 'Nieobsługiwana faza procesu.']);
                }
                $blockedReason = InputValidator::optionalString($_POST, 'blocked_reason', 500);
                $workflowRepository->moveToStage((int) $taskId, $stageUpdate);
                $workflowRepository->updateStatus((int) $taskId, $status, $blockedReason !== '' ? $blockedReason : null);
                $auditLogger->log(
                    (int) $caseId,
                    Auth::id(),
                    Auth::username(),
                    'workflow_task_updated',
                    ['task_id' => (int) $taskId, 'status' => $status, 'stage' => $stageUpdate]
                );
                break;
            case 'workflow-delete-task':
                $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$taskId) {
                    throw new ValidationException(['task_id' => 'Nie znaleziono zadania.']);
                }
                $workflowRepository->delete((int) $taskId);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'workflow_task_deleted', ['task_id' => (int) $taskId]);
                break;
            case 'workflow-apply-template':
                $templateKey = strtolower(InputValidator::requireString($_POST, 'template', 64));
                if (!isset($workflowTemplates[$templateKey])) {
                    throw new ValidationException(['template' => 'Nieznany szablon.']);
                }
                $createdTasks = 0;
                foreach ($workflowTemplates[$templateKey]['tasks'] as $templateTask) {
                    $stageKey = isset($workflowStages[$templateTask['stage']]) ? $templateTask['stage'] : 'intake';
                    $priorityKey = isset($workflowPriorities[$templateTask['priority']]) ? $templateTask['priority'] : 'normal';
                    $workflowRepository->create(
                        (int) $caseId,
                        $templateTask['title'],
                        $stageKey,
                        'todo',
                        $priorityKey,
                        null,
                        null,
                        $templateTask['description']
                    );
                    $createdTasks++;
                }
                $auditLogger->log(
                    (int) $caseId,
                    Auth::id(),
                    Auth::username(),
                    'workflow_template_applied',
                    ['template' => $templateKey, 'tasks_created' => $createdTasks]
                );
                break;

                case 'update-case-meta':
                $priorityRaw = InputValidator::optionalString($_POST, 'priority', 32);
                $priority = $priorityRaw !== '' ? strtolower($priorityRaw) : null;
                if ($priority !== null && !in_array($priority, ['low', 'normal', 'high', 'critical'], true)) {
                    throw new ValidationException(['priority' => 'Nieobsługiwany priorytet.']);
                }

                $slaDueRaw = InputValidator::optionalString($_POST, 'sla_due_at', 32);
                $slaDueAt = null;
                if ($slaDueRaw !== '') {
                    $slaDate = date_create_immutable($slaDueRaw);
                    if (!$slaDate instanceof \DateTimeImmutable) {
                        throw new ValidationException(['sla_due_at' => 'Nieprawidłowy format daty.']);
                    }
                    $slaDueAt = $slaDate->format('Y-m-d H:i:s');
                }

                $primaryEmployee = filter_var($_POST['primary_employee_id'] ?? null, FILTER_VALIDATE_INT);
                if ($primaryEmployee && !$employeeRepository->find((int) $primaryEmployee)) {
                    throw new ValidationException(['primary_employee_id' => 'Wybrany pracownik nie istnieje.']);
                }

                $assignmentPayload = $_POST['assignments'] ?? [];
                $assignmentRows = [];
                if (is_array($assignmentPayload)) {
                    foreach ($assignmentPayload as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $employeeId = filter_var($row['employee_id'] ?? null, FILTER_VALIDATE_INT);
                        if (!$employeeId) {
                            continue;
                        }
                        $type = isset($row['type']) ? trim((string) $row['type']) : 'primary';
                        $notes = isset($row['notes']) ? trim((string) $row['notes']) : null;
                        $assignmentRows[] = [
                            'employee_id' => (int) $employeeId,
                            'type' => $type !== '' ? $type : 'primary',
                            'notes' => $notes,
                        ];
                    }
                }

                $caseRepository->updateMeta((int) $caseId, $priority, $slaDueAt, $primaryEmployee ?: null);
                $caseRepository->syncAssignments((int) $caseId, $assignmentRows, Auth::username());
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'case_meta_updated', [
                    'priority' => $priority,
                    'sla_due_at' => $slaDueAt,
                    'primary_employee_id' => $primaryEmployee,
                    'assignments' => array_map(static fn (array $row): array => ['employee_id' => $row['employee_id'], 'type' => $row['type']], $assignmentRows),
                ]);
                break;
                case 'assign-partner':
                if ($isPartnerUser) {
                    throw new ValidationException(['general' => 'Partners kunnen deze actie niet uitvoeren.']);
                }

                $selectedPartnerId = filter_var($_POST['partner_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                if ($selectedPartnerId !== null && !$partnerRepository->find((int) $selectedPartnerId)) {
                    throw new ValidationException(['partner_id' => 'Geen geldige partner geselecteerd.']);
                }

                $consentForPartner = isset($_POST['partner_contact_consent']);
                $updatedDetails = $caseDetails;

                if ($selectedPartnerId !== null) {
                    $updatedDetails['partner_id'] = (int) $selectedPartnerId;
                    $updatedDetails['partner_assigned_at'] = Clock::nowFormatted();
                } else {
                    unset($updatedDetails['partner_id'], $updatedDetails['partner_assigned_at']);
                }

                $updatedDetails['partner_contact_consent'] = $consentForPartner;
                unset($updatedDetails['partner_data_request']);

                $caseRepository->updateDetails((int) $caseId, $updatedDetails);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'partner_assigned', [
                    'partner_id' => $selectedPartnerId,
                    'consent' => $consentForPartner,
                ]);
                $caseDetails = $updatedDetails;
                break;
            case 'request-customer-data':
                if (!$partnerViewingOwnCase) {
                    throw new ValidationException(['general' => 'Tylko przypisany partner może poprosić o dane klienta.']);
                }

                $requestReason = InputValidator::requireString($_POST, 'reason', 500);

                $updatedDetails = $caseDetails;
                $updatedDetails['partner_data_request'] = [
                    'status' => 'pending',
                    'reason' => $requestReason,
                    'requested_by' => Auth::username(),
                    'requested_at' => Clock::nowFormatted(),
                ];
                $caseRepository->updateDetails((int) $caseId, $updatedDetails);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'partner_requested_data', [
                    'reason' => $requestReason,
                ]);
                $caseDetails = $updatedDetails;
                break;
            case 'respond-customer-data-request':
                if ($isPartnerUser) {
                    throw new ValidationException(['general' => 'Partner kan deze aanvraag niet beoordelen.']);
                }

                if (($partnerDataRequest['status'] ?? '') !== 'pending') {
                    throw new ValidationException(['general' => 'Er is geen lopend verzoek om te beoordelen.']);
                }

                $decision = strtolower(InputValidator::requireString($_POST, 'decision', 16));
                if (!in_array($decision, ['approve', 'decline'], true)) {
                    throw new ValidationException(['decision' => 'Maak een keuze voor toestaan of weigeren.']);
                }
                $responseNote = InputValidator::optionalString($_POST, 'response_note', 500);

                $updatedDetails = $caseDetails;
                $updatedDetails['partner_data_request'] = array_merge($partnerDataRequest, [
                    'status' => $decision === 'approve' ? 'approved' : 'declined',
                    'responded_at' => Clock::nowFormatted(),
                    'responded_by' => Auth::username(),
                    'response_note' => $responseNote !== '' ? $responseNote : null,
                ]);

                $caseRepository->updateDetails((int) $caseId, $updatedDetails);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'partner_request_' . ($decision === 'approve' ? 'approved' : 'declined'), [
                    'response_note' => $responseNote,
                ]);
                $caseDetails = $updatedDetails;
                break;
                case 'review-partner-estimate':
                if ($isPartnerUser) {
                    throw new ValidationException(['general' => 'Partner kan deze wyceny nie zatwierdzać.']);
                }

                $partnerWorkflow = isset($caseDetails['partner_workflow']) && is_array($caseDetails['partner_workflow'])
                    ? $caseDetails['partner_workflow']
                    : [];
                $partnerEstimate = isset($partnerWorkflow['estimate']) && is_array($partnerWorkflow['estimate'])
                    ? $partnerWorkflow['estimate']
                    : null;

                if ($partnerEstimate === null) {
                    throw new ValidationException(['general' => 'Brak wyceny partnera do oceny.']);
                }

                $decision = strtolower(InputValidator::requireString($_POST, 'decision', 16));
                if (!in_array($decision, ['approve', 'decline', 'counter'], true)) {
                    throw new ValidationException(['decision' => 'Wybierz decyzję: zatwierdź, odrzuć lub zaproponuj zmianę.']);
                }

                $counterAmount = null;
                if ($decision === 'counter') {
                    $counterRaw = InputValidator::requireString($_POST, 'counter_amount', 32);
                    $counterNormalized = str_replace(',', '.', $counterRaw);
                    $counterAmount = filter_var($counterNormalized, FILTER_VALIDATE_FLOAT);
                    if ($counterAmount === false || $counterAmount <= 0) {
                        throw new ValidationException(['counter_amount' => 'Podaj nową kwotę większą od zera.']);
                    }
                }

                $reviewNote = InputValidator::optionalString($_POST, 'review_note', 500);

                $partnerWorkflow['decision'] = [
                    'status' => $decision,
                    'proposed_amount' => $counterAmount,
                    'note' => $reviewNote !== '' ? $reviewNote : null,
                    'decided_at' => Clock::nowFormatted(),
                    'decided_by' => Auth::username(),
                ];

                if ($decision === 'approve') {
                    $partnerWorkflow['status'] = 'repair_ready';
                } elseif ($decision === 'decline') {
                    $partnerWorkflow['status'] = 'estimate_declined';
                } else {
                    $partnerWorkflow['status'] = 'counter_review';
                    $partnerWorkflow['estimate']['counter_amount'] = $counterAmount;
                }

                $caseDetails['partner_workflow'] = $partnerWorkflow;
                $caseRepository->updateDetails((int) $caseId, $caseDetails);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'partner_estimate_reviewed', [
                    'decision' => $decision,
                    'counter_amount' => $counterAmount,
                    'note' => $reviewNote,
                ]);
                break;
              case 'review-correction-request':
                if ($isPartnerUser) {
                    throw new ValidationException(['general' => 'Partner nie może zatwierdzać korekt.']);
                }

                $partnerWorkflow = isset($caseDetails['partner_workflow']) && is_array($caseDetails['partner_workflow'])
                    ? $caseDetails['partner_workflow']
                    : [];
                $partnerEstimate = isset($partnerWorkflow['estimate']) && is_array($partnerWorkflow['estimate'])
                    ? $partnerWorkflow['estimate']
                    : null;
                $correctionRequest = isset($partnerWorkflow['correction_request']) && is_array($partnerWorkflow['correction_request'])
                    ? $partnerWorkflow['correction_request']
                    : null;

                if (!is_array($correctionRequest) || ($correctionRequest['status'] ?? '') !== 'pending') {
                    throw new ValidationException(['general' => 'Brak oczekującej korekty do zatwierdzenia.']);
                }

                $decision = strtolower(InputValidator::requireString($_POST, 'decision', 16));
                if (!in_array($decision, ['approve', 'decline'], true)) {
                    throw new ValidationException(['decision' => 'Wybierz akceptację lub odrzucenie korekty.']);
                }

                $responseNote = InputValidator::optionalString($_POST, 'response_note', 500);
                $history = isset($partnerWorkflow['estimate_history']) && is_array($partnerWorkflow['estimate_history'])
                    ? $partnerWorkflow['estimate_history']
                    : [];

                if ($partnerEstimate !== null) {
                    $history[] = array_merge($partnerEstimate, [
                        'replaced_at' => Clock::nowFormatted(),
                        'replaced_by' => Auth::username(),
                        'reason' => 'correction',
                    ]);
                }

                if ($decision === 'approve') {
                    $partnerWorkflow['estimate_history'] = $history;
                    $partnerWorkflow['estimate'] = [
                        'amount' => (float) ($correctionRequest['amount'] ?? 0),
                        'currency' => (string) ($correctionRequest['currency'] ?? 'EUR'),
                        'description' => (string) ($correctionRequest['description'] ?? ''),
                        'submitted_at' => (string) ($correctionRequest['submitted_at'] ?? Clock::nowFormatted()),
                        'submitted_by' => (string) ($correctionRequest['submitted_by'] ?? 'partner'),
                    ];
                    $partnerWorkflow['correction_request'] = array_merge($correctionRequest, [
                        'status' => 'approved',
                        'responded_at' => Clock::nowFormatted(),
                        'responded_by' => Auth::username(),
                        'response_note' => $responseNote !== '' ? $responseNote : null,
                    ]);
                    $partnerWorkflow['status'] = 'repair_ready';
                } else {
                    $partnerWorkflow['correction_request'] = array_merge($correctionRequest, [
                        'status' => 'declined',
                        'responded_at' => Clock::nowFormatted(),
                        'responded_by' => Auth::username(),
                        'response_note' => $responseNote !== '' ? $responseNote : null,
                    ]);
                    $partnerWorkflow['status'] = 'repair_ready';
                }

                $caseDetails['partner_workflow'] = $partnerWorkflow;
                $caseRepository->updateDetails((int) $caseId, $caseDetails);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'partner_correction_reviewed', [
                    'decision' => $decision,
                    'response_note' => $responseNote,
                    'correction_amount' => $correctionRequest['amount'] ?? null,
                ]);
                break;
            default:
                throw new ValidationException(['general' => 'Onbekende actie.']);
        }
        Response::redirect('case.php?id=' . (int) $caseId);
    } catch (ValidationException $exception) {
        $validationErrors = $exception->errors();
        if ($currentAction === 'add-checklist' || $currentAction === 'add-checklist-item' || $currentAction === 'toggle-checklist-item' || $currentAction === 'remove-checklist') {
            $checklistErrors = $validationErrors;
        } elseif ($currentAction === 'update-note') {
            $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT);
            if ($noteId) {
                $noteEditErrors[(int) $noteId] = $validationErrors;
                $noteEditValues[(int) $noteId] = (string) ($_POST['body'] ?? '');
            } else {
                $errors = $validationErrors;
            }
        } elseif ($currentAction === 'delete-note') {
            $errors = array_merge($errors, $validationErrors);
        } elseif ($currentAction === 'add-note') {
            $errors = $validationErrors;
        } elseif ($currentAction === 'intake-attendance') {
            $attendanceErrors = $validationErrors;
        } elseif ($currentAction === 'close-case') {
            $closeCaseErrors = $validationErrors;
            $shouldOpenCaseCloseModal = true;
        } elseif ($currentAction === 'create-appointment') {
            $appointmentFormErrors = $validationErrors;
            $shouldOpenAppointmentModal = true;
        } elseif ($currentAction === 'workflow-create-task' || $currentAction === 'workflow-apply-template') {
            $workflowErrors = $validationErrors;
        } elseif ($currentAction === 'workflow-update-task' || $currentAction === 'workflow-delete-task') {
            $taskId = filter_var($_POST['task_id'] ?? null, FILTER_VALIDATE_INT);
            if ($taskId) {
                $workflowTaskErrors[(int) $taskId] = $validationErrors;
            } else {
                $workflowErrors = array_merge($workflowErrors, $validationErrors);
            }
        } elseif ($currentAction === 'assign-partner') {
            $partnerAssignErrors = $validationErrors;
        } elseif ($currentAction === 'request-customer-data') {
            $partnerRequestErrors = $validationErrors;
        } elseif ($currentAction === 'respond-customer-data-request') {
            $partnerResponseErrors = $validationErrors;
        } elseif ($currentAction === 'review-partner-estimate') {
            $partnerEstimateErrors = $validationErrors;
        } else {
            $errors = array_merge($errors, $validationErrors);
        }
    }
}

if ($currentAction === 'intake-attendance' && $attendanceErrors !== []) {
    $shouldShowAttendanceModal = true;
}

$appointmentCreated = filter_input(INPUT_GET, 'appointment_created', FILTER_VALIDATE_BOOLEAN);
$caseClosedSuccess = filter_input(INPUT_GET, 'case_closed', FILTER_VALIDATE_BOOLEAN);
$caseClosedPickupId = filter_input(INPUT_GET, 'pickup_id', FILTER_VALIDATE_INT);

$notes = $noteRepository->forCase((int) $caseId);
$csrfToken = Csrf::token();
$checklists = $checklistRepository->forCase((int) $caseId);
$checklistQuickActions = [
    [
        'id' => 'checklist-cleaning-modal',
        'label' => 'Czyszczenie',
        'eyebrow' => 'Szybkie działanie',
        'description' => 'Wybierz rodzaj czyszczenia, aby błyskawicznie zaplanować prace porządkowe przy urządzeniu.',
        'submit_label' => 'Dodaj zadanie czyszczenia',
        'options' => [
            [
                'id' => 'physical',
                'title' => 'Czyszczenie fizyczne',
                'description' => 'Odkurzenie obudowy, wentylatorów oraz filtrów powietrza.',
                'value' => 'Czyszczenie fizyczne',
            ],
            [
                'id' => 'system',
                'title' => 'Czyszczenie systemu',
                'description' => 'Usuwanie zbędnych aplikacji, plików tymczasowych i autostartu.',
                'value' => 'Czyszczenie systemu',
            ],
            [
                'id' => 'cooling',
                'title' => 'Konserwacja układu chłodzenia',
                'description' => 'Demontaż i czyszczenie radiatorów, wymiana pasty/padów termicznych.',
                'value' => 'Konserwacja układu chłodzenia',
            ],
            [
                'id' => 'finishing',
                'title' => 'Wykończenie i dezynfekcja',
                'description' => 'Czyszczenie powierzchni zewnętrznych i dezynfekcja punktów dotyku.',
                'value' => 'Końcowe czyszczenie i dezynfekcja',
            ],
        ],
    ],
    [
        'id' => 'checklist-replacement-modal',
        'label' => 'Wymiana',
        'eyebrow' => 'Szybkie działanie',
        'description' => 'Zaplanowane wymiany komponentów możesz dodać jednym kliknięciem.',
        'submit_label' => 'Dodaj zadanie wymiany',
        'options' => [
            [
                'id' => 'cpu',
                'title' => 'CPU',
                'description' => 'Demontaż starego procesora i instalacja nowego.',
                'value' => 'Wymiana CPU',
            ],
            [
                'id' => 'gpu',
                'title' => 'GPU',
                'description' => 'Wymiana karty graficznej wraz z konfiguracją sterowników.',
                'value' => 'Wymiana GPU',
            ],
            [
                'id' => 'motherboard',
                'title' => 'Motherboard',
                'description' => 'Wymiana płyty głównej oraz ponowne okablowanie.',
                'value' => 'Wymiana płyty głównej',
            ],
            [
                'id' => 'ram',
                'title' => 'RAM',
                'description' => 'Rozbudowa lub wymiana pamięci operacyjnej.',
                'value' => 'Wymiana / rozbudowa RAM',
            ],
            [
                'id' => 'psu',
                'title' => 'PSU',
                'description' => 'Demontaż i instalacja nowego zasilacza.',
                'value' => 'Wymiana zasilacza',
            ],
            [
                'id' => 'storage',
                'title' => 'Dysk',
                'description' => 'Wymiana dysku oraz przeniesienie danych jeśli wymagane.',
                'value' => 'Wymiana dysku',
            ],
            [
                'id' => 'other',
                'title' => 'Inne komponenty',
                'description' => 'Zadanie własne związane z wymianą lub montażem.',
                'requires_input' => true,
                'input_label' => 'Opisz komponent do wymiany',
                'input_placeholder' => 'Np. wymiana chłodzenia wodnego',
            ],
        ],
    ],
    [
        'id' => 'checklist-diagnostics-modal',
        'label' => 'Diagnostyka',
        'eyebrow' => 'Szybkie działanie',
        'description' => 'Dodaj standardowe kroki diagnostyczne, aby uporządkować proces analizy.',
        'submit_label' => 'Dodaj zadanie diagnostyczne',
        'options' => [
            [
                'id' => 'hardware',
                'title' => 'Diagnostyka sprzętowa',
                'description' => 'Testy komponentów: CPU, RAM, storage, zasilanie.',
                'value' => 'Diagnostyka sprzętowa',
            ],
            [
                'id' => 'software',
                'title' => 'Diagnostyka systemu',
                'description' => 'Analiza logów, stabilności systemu i konfliktów sterowników.',
                'value' => 'Diagnostyka systemowa',
            ],
            [
                'id' => 'stress',
                'title' => 'Testy obciążeniowe',
                'description' => 'Przeprowadzenie testów stresowych i monitorowanie temperatur.',
                'value' => 'Testy obciążeniowe',
            ],
            [
                'id' => 'report',
                'title' => 'Raport diagnostyczny',
                'description' => 'Przygotowanie i omówienie wyników diagnozy.',
                'value' => 'Przygotowanie raportu diagnostycznego',
            ],
            [
                'id' => 'custom',
                'title' => 'Własny scenariusz',
                'description' => 'Dodaj dowolny krok diagnozy specyficzny dla tej sprawy.',
                'requires_input' => true,
                'input_label' => 'Opisz zadanie diagnostyczne',
                'input_placeholder' => 'Np. diagnostyka RAID / macierzy NAS',
            ],
        ],
    ],
    [
        'id' => 'checklist-updates-modal',
        'label' => 'Aktualizacje i testy',
        'eyebrow' => 'Szybkie działanie',
        'description' => 'Wybierz działania związane z aktualizacjami, testami końcowymi lub zabezpieczeniami.',
        'submit_label' => 'Dodaj zadanie serwisowe',
        'options' => [
            [
                'id' => 'os_update',
                'title' => 'Aktualizacja systemu',
                'description' => 'Instalacja najnowszych aktualizacji systemu operacyjnego.',
                'value' => 'Aktualizacja systemu operacyjnego',
            ],
            [
                'id' => 'drivers',
                'title' => 'Sterowniki',
                'description' => 'Aktualizacja sterowników urządzeń oraz firmware.',
                'value' => 'Aktualizacja sterowników i firmware',
            ],
            [
                'id' => 'bios',
                'title' => 'BIOS / UEFI',
                'description' => 'Aktualizacja BIOS/UEFI i przywrócenie konfiguracji.',
                'value' => 'Aktualizacja BIOS / UEFI',
            ],
            [
                'id' => 'security',
                'title' => 'Poprawki bezpieczeństwa',
                'description' => 'Instalacja poprawek zabezpieczeń, konfiguracja antywirusa.',
                'value' => 'Instalacja poprawek bezpieczeństwa',
            ],
            [
                'id' => 'backup',
                'title' => 'Kopia zapasowa',
                'description' => 'Wykonanie kopii zapasowej danych klienta.',
                'value' => 'Wykonanie kopii zapasowej',
            ],
            [
                'id' => 'final-test',
                'title' => 'Testy końcowe',
                'description' => 'Sprawdzenie działania po naprawie, testy funkcjonalne.',
                'value' => 'Testy końcowe po serwisie',
            ],
            [
                'id' => 'custom',
                'title' => 'Dodatkowe zadanie',
                'description' => 'Dowolne działanie końcowe lub administracyjne.',
                'requires_input' => true,
                'input_label' => 'Opisz dodatkowe zadanie',
                'input_placeholder' => 'Np. konfiguracja oprogramowania branżowego',
            ],
        ],
    ],
];
$workflowTasks = $workflowRepository->forCase((int) $caseId);
$workflowBoard = [];
foreach (array_keys($workflowStages) as $stageKey) {
    $workflowBoard[$stageKey] = [];
}
$workflowStatusTotals = [];
foreach (array_keys($workflowStatuses) as $statusKey) {
    $workflowStatusTotals[$statusKey] = 0;
}
$workflowTotals = [
    'total' => count($workflowTasks),
    'active' => 0,
    'blocked' => 0,
    'overdue' => 0,
];
$priorityWeights = [
    'critical' => 0,
    'high' => 1,
    'normal' => 2,
    'low' => 3,
];
foreach ($workflowTasks as $taskRow) {
    $stageKey = isset($workflowStages[$taskRow['stage']]) ? (string) $taskRow['stage'] : array_key_first($workflowStages);
    if (!isset($workflowBoard[$stageKey])) {
        $workflowBoard[$stageKey] = [];
    }
    $workflowBoard[$stageKey][] = $taskRow;

    $statusKey = isset($workflowStatuses[$taskRow['status']]) ? (string) $taskRow['status'] : 'todo';
    if (!isset($workflowStatusTotals[$statusKey])) {
        $workflowStatusTotals[$statusKey] = 0;
    }
    $workflowStatusTotals[$statusKey]++;

    if ($statusKey === 'in_progress') {
        $workflowTotals['active']++;
    }
    if ($statusKey === 'blocked') {
        $workflowTotals['blocked']++;
    }

    if (!empty($taskRow['due_at']) && $statusKey !== 'done') {
        $dueTimestamp = strtotime((string) $taskRow['due_at']);
        if ($dueTimestamp !== false && $dueTimestamp < time()) {
            $workflowTotals['overdue']++;
        }
    }
}
foreach ($workflowBoard as &$stageTasks) {
    usort(
        $stageTasks,
        static function (array $left, array $right) use ($priorityWeights): int {
            $leftPriority = $priorityWeights[strtolower((string) ($left['priority'] ?? 'normal'))] ?? 5;
            $rightPriority = $priorityWeights[strtolower((string) ($right['priority'] ?? 'normal'))] ?? 5;
            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            $leftDue = !empty($left['due_at']) ? strtotime((string) $left['due_at']) : null;
            $rightDue = !empty($right['due_at']) ? strtotime((string) $right['due_at']) : null;
            if ($leftDue !== $rightDue) {
                return ($leftDue ?? PHP_INT_MAX) <=> ($rightDue ?? PHP_INT_MAX);
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        }
    );
}
unset($stageTasks);
$assignmentSuccess = filter_input(INPUT_GET, 'assigned', FILTER_VALIDATE_BOOLEAN);

$priorityOptions = [
    '' => 'Domyślny',
    'low' => 'Niski',
    'normal' => 'Normalny',
    'high' => 'Wysoki',
    'critical' => 'Krytyczny',
];

$caseStatusKey = strtolower((string) ($caseRecord['status'] ?? ''));
$statusClassMap = [
    'opgehaald' => 'case-badge--success',
    'gesloten' => 'case-badge--success',
    'geannuleerd' => 'case-badge--danger',
    'vertraagd' => 'case-badge--warning',
    'gepland' => 'case-badge--info',
    'intake' => 'case-badge--info',
    'in_behandeling' => 'case-badge--info',
];
$statusBadgeClass = $statusClassMap[$caseStatusKey] ?? 'case-badge--muted';
$caseStatusLabel = $caseRecord['status'] !== null ? ucfirst((string) $caseRecord['status']) : 'Onbekend';

$priorityKey = strtolower((string) $priorityValue);
$priorityBadgeMap = [
    'critical' => 'case-badge--danger',
    'high' => 'case-badge--warning',
    'normal' => 'case-badge--info',
    'low' => 'case-badge--muted',
    '' => 'case-badge--muted',
];
$priorityBadgeClass = $priorityBadgeMap[$priorityKey] ?? 'case-badge--muted';
$priorityLabel = $priorityOptions[$priorityKey] ?? $priorityOptions[''];

$caseLastUpdatedLabel = !empty($caseRecord['updated_at'])
    ? date('d-m-Y H:i', strtotime((string) $caseRecord['updated_at']))
    : '—';
$referenceCode = trim((string) ($caseRecord['reference_code'] ?? ''));
if ($referenceCode === '') {
    $referenceCode = 'Brak kodu';
}

$slaDueTimestamp = !empty($caseRecord['sla_due_at']) ? strtotime((string) $caseRecord['sla_due_at']) : false;
$slaDueLabel = $slaDueTimestamp ? date('d-m-Y H:i', $slaDueTimestamp) : 'Brak terminu';
$slaBadgeClass = $slaDueTimestamp && $slaDueTimestamp < time() ? 'case-badge--warning' : 'case-badge--info';

$caseTypeLabel = trim((string) ($caseRecord['type'] ?? 'Case'));
if ($caseTypeLabel === '') {
    $caseTypeLabel = 'Case';
}
$caseCustomerName = trim((string) ($caseRecord['full_name'] ?? 'Onbekende klant'));
if ($caseCustomerName === '') {
    $caseCustomerName = 'Onbekende klant';
}

$assignedPartner = $assignedPartnerId ? $partnerRepository->find($assignedPartnerId) : null;
$partnerContactConsent = !empty($caseDetails['partner_contact_consent']);
$partnerDataRequest = [];
if (isset($caseDetails['partner_data_request']) && is_array($caseDetails['partner_data_request'])) {
    $partnerDataRequest = $caseDetails['partner_data_request'];
}
$partnerRequestStatus = strtolower((string) ($partnerDataRequest['status'] ?? ''));
$partnerWorkflow = isset($caseDetails['partner_workflow']) && is_array($caseDetails['partner_workflow'])
    ? $caseDetails['partner_workflow']
    : [];
$partnerWorkflowStatus = (string) ($partnerWorkflow['status'] ?? 'awaiting_acceptance');
$partnerEstimate = isset($partnerWorkflow['estimate']) && is_array($partnerWorkflow['estimate']) ? $partnerWorkflow['estimate'] : null;
$partnerDecision = isset($partnerWorkflow['decision']) && is_array($partnerWorkflow['decision']) ? $partnerWorkflow['decision'] : null;
$partnerEstimateHistory = isset($partnerWorkflow['estimate_history']) && is_array($partnerWorkflow['estimate_history'])
    ? $partnerWorkflow['estimate_history']
    : [];
$partnerCorrectionRequest = isset($partnerWorkflow['correction_request']) && is_array($partnerWorkflow['correction_request'])
    ? $partnerWorkflow['correction_request']
    : null;
$partnerCorrectionStatus = (string) ($partnerCorrectionRequest['status'] ?? '');
$partnerAccessApproved = $partnerRequestStatus === 'approved';
$partnerMaskValue = static function (?string $value): string {
    $clean = trim((string) $value);
    if ($clean === '') {
        return '***';
    }

    if (function_exists('mb_strlen')) {
        $length = (int) mb_strlen($clean, 'UTF-8');
    } else {
        $length = strlen($clean);
    }

    return str_repeat('*', max(3, min(12, $length)));
};
$customerDisplay = [
    'name' => $caseCustomerName,
    'email' => trim((string) ($caseRecord['email'] ?? '')),
    'phone' => trim((string) ($caseRecord['phone'] ?? '')),
    'address' => trim((string) ($caseRecord['address'] ?? '')),
    'postal_code' => trim((string) ($caseRecord['postal_code'] ?? '')),
    'city' => trim((string) ($caseRecord['city'] ?? '')),
];
$partnerMaskingActive = $isPartnerUser && !$partnerAccessApproved;
$partnerPartialAllowed = $partnerMaskingActive && $partnerContactConsent && $partnerViewingOwnCase;
if ($partnerMaskingActive) {
    $customerDisplay['email'] = $partnerMaskValue($customerDisplay['email']);
    $customerDisplay['address'] = $partnerMaskValue($customerDisplay['address']);
    $customerDisplay['postal_code'] = $partnerMaskValue($customerDisplay['postal_code']);
    $customerDisplay['city'] = $partnerMaskValue($customerDisplay['city']);

    if (!$partnerPartialAllowed) {
        $customerDisplay['name'] = $partnerMaskValue($customerDisplay['name']);
        $customerDisplay['phone'] = $partnerMaskValue($customerDisplay['phone']);
    }
}
$caseCustomerName = $customerDisplay['name'];

$contactParts = [];
if ($customerDisplay['phone'] !== '') {
    $contactParts[] = $customerDisplay['phone'];
}
if ($customerDisplay['email'] !== '') {
    $contactParts[] = $customerDisplay['email'];
}
$primaryContactLabel = $contactParts !== [] ? implode(' · ', $contactParts) : 'Brak danych';

$caseDeviceSummaryParts = array_filter([
    isset($caseRecord['device_brand']) ? trim((string) $caseRecord['device_brand']) : null,
    isset($caseRecord['device_model']) ? trim((string) $caseRecord['device_model']) : null,
]);
$caseDeviceSummary = $caseDeviceSummaryParts !== [] ? implode(' · ', $caseDeviceSummaryParts) : 'Urządzenie nieznane';

$caseProblemSummary = 'Brak opisu awarii.';
if (isset($caseDetails['problem_description'])) {
    $rawSummary = trim((string) $caseDetails['problem_description']);
    if ($rawSummary !== '') {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            $caseProblemSummary = mb_strlen($rawSummary, 'UTF-8') > 160
                ? mb_substr($rawSummary, 0, 157, 'UTF-8') . '…'
                : $rawSummary;
        } else {
            $caseProblemSummary = strlen($rawSummary) > 160
                ? substr($rawSummary, 0, 157) . '…'
                : $rawSummary;
        }
    }
}

$nowTimestamp = time();
$nextAppointment = null;
$nextAppointmentTimestamp = null;
foreach ($caseAppointments as $appointment) {
    $startRaw = $appointment['start_at'] ?? null;
    if ($startRaw === null || $startRaw === '') {
        continue;
    }
    $startTimestamp = strtotime((string) $startRaw);
    if ($startTimestamp === false) {
        continue;
    }

    if ($startTimestamp < $nowTimestamp) {
        if ($nextAppointment === null && $nextAppointmentTimestamp === null) {
            $nextAppointment = $appointment;
            $nextAppointmentTimestamp = $startTimestamp;
        }
        continue;
    }

    if ($nextAppointmentTimestamp === null || $startTimestamp < $nextAppointmentTimestamp) {
        $nextAppointment = $appointment;
        $nextAppointmentTimestamp = $startTimestamp;
    }
}

if ($nextAppointment === null && $caseAppointments !== []) {
    $fallbackAppointment = $caseAppointments[0];
    $fallbackTimestamp = isset($fallbackAppointment['start_at'])
        ? strtotime((string) $fallbackAppointment['start_at'])
        : null;
    $nextAppointment = $fallbackAppointment;
    $nextAppointmentTimestamp = $fallbackTimestamp ?: null;
}

$nextAppointmentLabel = $nextAppointmentTimestamp ? date('d-m-Y H:i', $nextAppointmentTimestamp) : 'Brak wizyt';
$nextAppointmentTitle = $nextAppointment !== null
    ? (string) ($nextAppointment['title'] ?? 'Zaplanowana wizyta')
    : 'Brak wizyt w kalendarzu';
$nextAppointmentStatus = $nextAppointment !== null ? (string) ($nextAppointment['status'] ?? '') : '';

$caseHeroStats = [
    [
        'label' => 'Zespół',
        'value' => count($activeCaseAssignments),
        'hint' => 'aktywni technicy',
    ],
    [
        'label' => 'Magazyn',
        'value' => $warehouseSummary['total'],
        'hint' => 'powiązanych pozycji',
    ],
    [
        'label' => 'Workflow',
        'value' => $workflowTotals['total'],
        'hint' => 'zadania w toku',
    ],
];
$partners = $partnerRepository->all('all');
$assignedPartnerName = $assignedPartner !== null
    ? (string) ($assignedPartner['company_name'] ?? ($assignedPartner['full_name'] ?? 'Partner'))
    : 'Geen partner gekoppeld';
$partnerRequestPending = $partnerRequestStatus === 'pending';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Case #<?= (int) $caseId ?> - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/case.css">
</head>
<body<?= platform_body_attributes(); ?>>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="Digivriend dashboard">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title">Digivriend</span>
          <span class="logo__subtitle"><?= htmlspecialchars(platform_subtitle(), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        </span>
      </a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <?php render_main_nav('devices'); ?>
      </nav>
    </div>
  </header>

  <main class="container case-view">
    <?php if ($assignmentSuccess): ?>
      <div class="alert alert--success">Je bent nu verantwoordelijk voor dit dossier.</div>
    <?php endif; ?>
    <?php if ($caseClosedSuccess): ?>
      <div class="alert alert--success">
        Case succesvol afgesloten en ontvangstbevestiging geregistreerd.
        <?php if ($caseClosedPickupId): ?>
          <a class="btn-link" href="generate-apparaat-opgehaald.php?id=<?= (int) $caseClosedPickupId ?>" target="_blank" rel="noopener">Bekijk bevestiging</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($errors['general'])): ?>
      <?php $generalMessage = is_array($errors['general']) ? implode(' ', array_map('strval', $errors['general'])) : (string) $errors['general']; ?>
      <div class="alert alert--danger"><?= htmlspecialchars($generalMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($appointmentCreated): ?>
      <div class="alert alert--success">Nowa wizyta została zaplanowana.</div>
    <?php endif; ?>
    <?php if ($shouldShowAttendanceModal): ?>
      <div class="detail-modal" data-detail-modal="intake-attendance" data-open-on-load="true" aria-hidden="true">
        <div class="detail-modal__backdrop" data-modal-close></div>
        <div class="detail-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="attendanceModalTitle">
          <header class="detail-modal__header">
            <div>
              <p class="detail-modal__eyebrow">Intake aanwezigheid</p>
              <h3 id="attendanceModalTitle" class="detail-modal__title">Is de klant verschenen?</h3>
            </div>
            <button type="button" class="detail-modal__close" data-modal-close aria-label="Sluiten">&times;</button>
          </header>
          <div class="detail-modal__body">
            <p class="detail-modal__intro">Beoordeel de status van de afspraak zodat het team meteen weet wat de volgende stap is.</p>
            <?php if (!empty($attendanceErrors['general'])): ?>
              <?php $attendanceGeneral = is_array($attendanceErrors['general']) ? implode(' ', array_map('strval', $attendanceErrors['general'])) : (string) $attendanceErrors['general']; ?>
              <div class="alert alert--danger"><?= htmlspecialchars($attendanceGeneral, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="post" class="attendance-form" data-attendance-form>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="intake-attendance">
              <fieldset class="attendance-options">
                <legend class="attendance-options__legend">Kies een resultaat voor de afspraak</legend>
                <p class="attendance-options__description">Selecteer de status die het beste past bij de afspraak.</p>
                <label class="attendance-option">
                  <input type="radio" name="attendance_action" value="arrived" <?= $attendanceFormValues['attendance_action'] === 'arrived' ? 'checked' : '' ?>>
                  <span class="attendance-option__content">
                    <span class="attendance-option__title">Ja, de klant is verschenen en heeft het apparaat afgegeven.</span>
                    <span class="attendance-option__subtitle">Het apparaat kan direct worden ingenomen en verwerkt.</span>
                  </span>
                </label>
                <label class="attendance-option">
                  <input type="radio" name="attendance_action" value="no_show" <?= $attendanceFormValues['attendance_action'] === 'no_show' ? 'checked' : '' ?>>
                  <span class="attendance-option__content">
                    <span class="attendance-option__title">Nee, de klant is niet verschenen.</span>
                    <span class="attendance-option__subtitle">Noteer eventuele opvolging zodat het team weet wat te doen.</span>
                  </span>
                </label>
                <label class="attendance-option">
                  <input type="radio" name="attendance_action" value="rescheduled" <?= $attendanceFormValues['attendance_action'] === 'rescheduled' ? 'checked' : '' ?>>
                  <span class="attendance-option__content">
                    <span class="attendance-option__title">De klant wil de afspraak verplaatsen.</span>
                    <span class="attendance-option__subtitle">Plan een nieuwe datum en tijd die voor beide partijen werkt.</span>
                  </span>
                </label>
                <label class="attendance-option">
                  <input type="radio" name="attendance_action" value="cancelled" <?= $attendanceFormValues['attendance_action'] === 'cancelled' ? 'checked' : '' ?>>
                  <span class="attendance-option__content">
                    <span class="attendance-option__title">De klant heeft de afspraak geannuleerd.</span>
                    <span class="attendance-option__subtitle">Leg kort vast waarom de afspraak niet doorgaat.</span>
                  </span>
                </label>
              </fieldset>
              <?php if (!empty($attendanceErrors['attendance_action'])): ?>
                <small class="form-error"><?= htmlspecialchars(is_array($attendanceErrors['attendance_action']) ? implode(' ', array_map('strval', $attendanceErrors['attendance_action'])) : (string) $attendanceErrors['attendance_action'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
              <?php endif; ?>
              <div class="attendance-extra" data-reschedule-fields <?= $attendanceFormValues['attendance_action'] === 'rescheduled' ? '' : 'hidden' ?>>
                <label class="form-field">
                  <span class="form-field__label">Nieuwe datum &amp; tijd</span>
                  <input type="datetime-local" name="reschedule_at" value="<?= htmlspecialchars($attendanceFormValues['reschedule_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </label>
                <?php if (!empty($attendanceErrors['reschedule_at'])): ?>
                  <small class="form-error"><?= htmlspecialchars(is_array($attendanceErrors['reschedule_at']) ? implode(' ', array_map('strval', $attendanceErrors['reschedule_at'])) : (string) $attendanceErrors['reschedule_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                <?php endif; ?>
              </div>
              <div class="attendance-extra" data-cancellation-fields <?= $attendanceFormValues['attendance_action'] === 'cancelled' ? '' : 'hidden' ?>>
                <label class="form-field">
                  <span class="form-field__label">Reden van annulering</span>
                  <textarea name="cancellation_reason" rows="3" placeholder="Bijvoorbeeld: klant kon niet op tijd komen."><?= htmlspecialchars($attendanceFormValues['cancellation_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                </label>
                <?php if (!empty($attendanceErrors['cancellation_reason'])): ?>
                  <small class="form-error"><?= htmlspecialchars(is_array($attendanceErrors['cancellation_reason']) ? implode(' ', array_map('strval', $attendanceErrors['cancellation_reason'])) : (string) $attendanceErrors['cancellation_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                <?php endif; ?>
              </div>
              <div class="attendance-form__actions">
                <button type="button" class="btn btn--ghost" data-modal-close>Later herinneren</button>
                <button type="submit" class="btn btn--primary">Opslaan</button>
              </div>
            </form>
          </div>
          </div>
      </div>
    <?php endif; ?>
    <section class="case-hero" data-case-status="<?= htmlspecialchars($caseStatusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <div class="case-hero__content">
        <p class="case-hero__eyebrow">Case #<?= (int) $caseId ?> · <?= htmlspecialchars($caseTypeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars($caseCustomerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="case-hero__subtitle">
          <?= htmlspecialchars($caseDeviceSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          <span>· <?= htmlspecialchars($caseProblemSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </p>
        <div class="case-hero__badges">
          <span class="case-badge <?= htmlspecialchars($statusBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Status: <?= htmlspecialchars($caseStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="case-badge <?= htmlspecialchars($priorityBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Priorytet: <?= htmlspecialchars($priorityLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="case-badge <?= htmlspecialchars($slaBadgeClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">SLA: <?= htmlspecialchars($slaDueLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <dl class="case-hero__meta">
          <div>
            <dt>Referencja</dt>
            <dd><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div>
            <dt>Ostatnia aktualizacja</dt>
            <dd><?= htmlspecialchars($caseLastUpdatedLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div>
            <dt>Kontakt</dt>
            <dd><?= htmlspecialchars($primaryContactLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        </dl>
      </div>
      <div class="case-hero__panel">
        <h3>Najbliższe kroki</h3>
        <dl class="case-hero__panel-meta">
          <div>
            <dt>Następna wizyta</dt>
            <dd>
              <strong><?= htmlspecialchars($nextAppointmentLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
              <span><?= htmlspecialchars($nextAppointmentTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php if ($nextAppointmentStatus !== ''): ?>
                <span class="case-hero__panel-status"><?= htmlspecialchars(ucfirst($nextAppointmentStatus), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>Opis</dt>
            <dd><?= htmlspecialchars($caseProblemSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        </dl>
        <div class="case-hero__actions">
          <button type="button" class="btn btn--primary" data-modal-target="case-appointment-modal">Zaplanuj wizytę</button>
          <button type="button" class="btn btn--ghost" data-modal-target="case-close-modal">Zamknij case</button>
          <a class="btn btn--ghost" href="magazyn.php?case=<?= (int) $caseId ?>">Magazyn</a>
        </div>
      </div>
    </section>

    <section class="case-hero__stats" aria-label="Kluczowe wskaźniki sprawy">
      <?php foreach ($caseHeroStats as $heroStat): ?>
        <article class="hero-stat-card">
          <span class="hero-stat-card__value"><?= (int) ($heroStat['value'] ?? 0) ?></span>
          <span class="hero-stat-card__label"><?= htmlspecialchars((string) ($heroStat['label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <p class="hero-stat-card__hint"><?= htmlspecialchars((string) ($heroStat['hint'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </article>
      <?php endforeach; ?>
    </section>

    <div class="case-layout__grid">
      <div class="case-layout__primary">

      <article class="info-card info-card--wide">
        <h2>Partner</h2>
        <?php if ($isPartnerUser): ?>
          <p class="muted"><?= htmlspecialchars($assignedPartnerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php if ($partnerViewingOwnCase): ?>
            <p>Gegevens klienta są ukryte. <?= $partnerContactConsent ? 'Klient wyraził zgodę na kontakt telefoniczny.' : 'Brak zgody na udostępnienie danych.' ?></p>
            <div class="card-actions">
              <button type="button" class="btn btn--primary" data-modal-target="partner-request-modal">Poproś o dane klienta</button>
            </div>
            <?php if ($partnerRequestStatus === 'pending'): ?>
              <p class="muted">Twoje żądanie oczekuje na akceptację pracownika.</p>
            <?php elseif ($partnerRequestStatus === 'declined'): ?>
              <p class="alert alert--danger">Odmowa udostępnienia danych.</p>
            <?php elseif ($partnerRequestStatus === 'approved'): ?>
              <p class="alert alert--success">Dostęp do pełnych danych został przyznany.</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="muted">Case nie jest powiązana z Twoim kontem partnera.</p>
          <?php endif; ?>
        <?php else: ?>
          <form method="post" class="form-grid" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="assign-partner">
            <label class="form-field">
              <span class="form-field__label">Partner</span>
              <select name="partner_id">
                <option value="">— Geen —</option>
                <?php foreach ($partners as $partner): ?>
                  <?php $pid = (int) ($partner['id'] ?? 0); ?>
                  <option value="<?= $pid ?>" <?= $assignedPartnerId === $pid ? 'selected' : '' ?>><?= htmlspecialchars((string) ($partner['company_name'] ?? $partner['full_name'] ?? 'Partner'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($partnerAssignErrors['partner_id'])): ?><small class="form-error"><?= htmlspecialchars(is_array($partnerAssignErrors['partner_id']) ? implode(' ', array_map('strval', $partnerAssignErrors['partner_id'])) : (string) $partnerAssignErrors['partner_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <label class="form-field form-field--checkbox">
              <input type="checkbox" name="partner_contact_consent" value="1" <?= $partnerContactConsent ? 'checked' : '' ?>>
              <span>Klient zgadza się na kontakt telefoniczny przez partnera.</span>
            </label>
            <?php if (!empty($partnerAssignErrors['general'])): ?><div class="alert alert--danger"><?= htmlspecialchars(is_array($partnerAssignErrors['general']) ? implode(' ', array_map('strval', $partnerAssignErrors['general'])) : (string) $partnerAssignErrors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
            <div class="form-actions">
              <button type="submit" class="btn btn--primary">Opslaan</button>
            </div>
            <p class="muted">Bij het delen met partner worden klantgegevens verborgen. Po zaakceptowaniu zgody widoczne będą tylko imię, nazwisko i telefon. Reszta wymaga zatwierdzenia przez pracownika.</p>
            <?php if ($partnerRequestPending): ?>
              <div class="alert alert--info">Partner prosi o dodatkowe dane klienta.</div>
              <button type="button" class="btn btn--ghost" data-modal-target="partner-response-modal">Otwórz zgłoszenie</button>
            <?php endif; ?>
          </form>
          <div class="partner-estimate-review">
            <h3>Wycena partnera</h3>
            <p class="muted">Status: <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $partnerWorkflowStatus)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php if ($partnerEstimate !== null): ?>
              <p><strong>Kwota partnera:</strong> € <?= number_format((float) ($partnerEstimate['amount'] ?? 0), 2, ',', ' ') ?></p>
              <p><strong>Opis:</strong><br><?= nl2br(htmlspecialchars((string) ($partnerEstimate['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
              <?php if (!empty($partnerEstimate['submitted_at'])): ?>
                <p class="muted">Otrzymano: <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $partnerEstimate['submitted_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
              <?php if ($partnerDecision !== null): ?>
                <div class="alert alert--info">
                  Ostatnia decyzja: <?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($partnerDecision['status'] ?? ''))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  <?php if (!empty($partnerDecision['proposed_amount'])): ?>
                    · Propozycja: € <?= number_format((float) $partnerDecision['proposed_amount'], 2, ',', ' ') ?>
                  <?php endif; ?>
                  <?php if (!empty($partnerDecision['note'])): ?>
                    <br><small><?= nl2br(htmlspecialchars((string) $partnerDecision['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></small>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if ($partnerEstimateHistory !== []): ?>
                <div class="info-card__hint">
                  <p><strong>Historia wycen:</strong></p>
                  <ul class="muted" style="padding-left: 1.2rem; margin: 0;">
                    <?php foreach (array_reverse($partnerEstimateHistory) as $history): ?>
                      <li>
                        € <?= number_format((float) ($history['amount'] ?? 0), 2, ',', ' ') ?>
                        <?php if (!empty($history['replaced_at'])): ?>
                          · <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $history['replaced_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <?php endif; ?>
                        <?php if (!empty($history['replaced_by'])): ?>
                          · <?= htmlspecialchars((string) $history['replaced_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
              <?php if ($partnerCorrectionStatus === 'pending'): ?>
                <div class="alert alert--warning">Partner zgłosił błąd w zaakceptowanej wycenie. Sprawdź i zatwierdź korektę.</div>
                <p><strong>Proponowana kwota:</strong> € <?= number_format((float) ($partnerCorrectionRequest['amount'] ?? 0), 2, ',', ' ') ?></p>
                <p><strong>Opis korekty:</strong><br><?= nl2br(htmlspecialchars((string) ($partnerCorrectionRequest['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                <?php if (!empty($partnerCorrectionRequest['error_details'])): ?>
                  <p class="muted">Notatka partnera: <?= nl2br(htmlspecialchars((string) $partnerCorrectionRequest['error_details'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                <?php endif; ?>
                <form method="post" class="form-grid" novalidate>
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="review-correction-request">
                  <label class="form-field">
                    <span class="form-field__label">Notatka zwrotna</span>
                    <?php $correctionResponseNote = $currentAction === 'review-correction-request' ? (string) ($_POST['response_note'] ?? '') : ''; ?>
                    <textarea name="response_note" rows="3" maxlength="500" placeholder="Uzasadnij akceptację lub odrzucenie korekty."><?= htmlspecialchars($correctionResponseNote, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                  </label>
                  <?php if (!empty($partnerCorrectionErrors['general'])): ?><div class="alert alert--danger"><?= htmlspecialchars(is_array($partnerCorrectionErrors['general']) ? implode(' ', array_map('strval', $partnerCorrectionErrors['general'])) : (string) $partnerCorrectionErrors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                  <?php if (!empty($partnerCorrectionErrors['decision'])): ?><div class="alert alert--danger"><?= htmlspecialchars(is_array($partnerCorrectionErrors['decision']) ? implode(' ', array_map('strval', $partnerCorrectionErrors['decision'])) : (string) $partnerCorrectionErrors['decision'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                  <div class="form-actions">
                    <button type="submit" name="decision" value="approve" class="btn btn--primary">Zatwierdź korektę</button>
                    <button type="submit" name="decision" value="decline" class="btn btn--ghost">Odrzuć korektę</button>
                  </div>
                </form>
              <?php else: ?>
                <?php if ($partnerCorrectionStatus === 'approved' || $partnerCorrectionStatus === 'declined'): ?>
                  <div class="alert alert--success">Status korekty: <?= htmlspecialchars($partnerCorrectionStatus === 'approved' ? 'zaakceptowana' : 'odrzucona', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <?php endif; ?>
                <form method="post" class="form-grid" novalidate>
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="review-partner-estimate">
                  <label class="form-field">
                    <span class="form-field__label">Decyzja</span>
                    <?php $decisionValue = $currentAction === 'review-partner-estimate' ? (string) ($_POST['decision'] ?? '') : ''; ?>
                    <select name="decision" required>
                      <option value="">— Wybierz —</option>
                      <option value="approve" <?= $decisionValue === 'approve' ? 'selected' : '' ?>>Zatwierdź kwotę</option>
                      <option value="decline" <?= $decisionValue === 'decline' ? 'selected' : '' ?>>Odrzuć</option>
                      <option value="counter" <?= $decisionValue === 'counter' ? 'selected' : '' ?>>Zaproponuj zmianę</option>
                    </select>
                    <?php if (!empty($partnerEstimateErrors['decision'])): ?><small class="form-error"><?= htmlspecialchars(is_array($partnerEstimateErrors['decision']) ? implode(' ', array_map('strval', $partnerEstimateErrors['decision'])) : (string) $partnerEstimateErrors['decision'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                  </label>
                  <label class="form-field">
                    <span class="form-field__label">Proponowana kwota (€)</span>
                    <?php $counterValue = $currentAction === 'review-partner-estimate' ? (string) ($_POST['counter_amount'] ?? '') : (string) ($partnerEstimate['counter_amount'] ?? ''); ?>
                    <input type="number" name="counter_amount" step="0.01" min="0" value="<?= htmlspecialchars($counterValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Wpisz przy zmianie ceny">
                    <?php if (!empty($partnerEstimateErrors['counter_amount'])): ?><small class="form-error"><?= htmlspecialchars(is_array($partnerEstimateErrors['counter_amount']) ? implode(' ', array_map('strval', $partnerEstimateErrors['counter_amount'])) : (string) $partnerEstimateErrors['counter_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                  </label>
                  <label class="form-field">
                    <span class="form-field__label">Notatka dla partnera</span>
                    <?php $reviewNoteValue = $currentAction === 'review-partner-estimate' ? (string) ($_POST['review_note'] ?? '') : ''; ?>
                    <textarea name="review_note" rows="3" maxlength="500" placeholder="Uzasadnij akceptację, odrzucenie lub nową kwotę."><?= htmlspecialchars($reviewNoteValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                  </label>
                  <?php if (!empty($partnerEstimateErrors['general'])): ?><div class="alert alert--danger"><?= htmlspecialchars(is_array($partnerEstimateErrors['general']) ? implode(' ', array_map('strval', $partnerEstimateErrors['general'])) : (string) $partnerEstimateErrors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                  <div class="form-actions">
                    <button type="submit" class="btn btn--primary">Wyślij decyzję</button>
                  </div>
                </form>
              <?php endif; ?>
            <?php else: ?>
              <p class="muted">Partner nie przesłał jeszcze wyceny do zatwierdzenia.</p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </article>

    <section class="case-grid">
      <article class="info-card">
        <h2>Klantgegevens</h2>
        <ul>
          <li><strong>Naam:</strong> <?= htmlspecialchars((string) $customerDisplay['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <li><strong>E-mail:</strong> <?= htmlspecialchars((string) $customerDisplay['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <li><strong>Telefoon:</strong> <?= htmlspecialchars((string) $customerDisplay['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <li><strong>Adres:</strong> <?= htmlspecialchars((string) $customerDisplay['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php if ($customerDisplay['postal_code'] !== ''): ?>
            <li><strong>Postcode:</strong> <?= htmlspecialchars((string) $customerDisplay['postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php endif; ?>
          <?php if ($customerDisplay['city'] !== ''): ?>
            <li><strong>Plaats:</strong> <?= htmlspecialchars((string) $customerDisplay['city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php endif; ?>
        </ul>
      </article>

      <article class="info-card">
        <h2>Apparaatgegevens</h2>
        <?php if ($caseRecord['device_brand'] || $caseRecord['device_model']): ?>
          <ul>
            <?php if (!empty($caseRecord['device_brand'])): ?><li><strong>Merk:</strong> <?= htmlspecialchars((string) $caseRecord['device_brand'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endif; ?>
            <?php if (!empty($caseRecord['device_model'])): ?><li><strong>Model:</strong> <?= htmlspecialchars((string) $caseRecord['device_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endif; ?>
            <?php if (!empty($caseRecord['device_serial'])): ?><li><strong>Serienummer:</strong> <?= htmlspecialchars((string) $caseRecord['device_serial'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endif; ?>
          </ul>
        <?php else: ?>
          <p class="muted">Geen apparaatgegevens beschikbaar.</p>
        <?php endif; ?>
      </article>

      <article class="info-card info-card--wide">
        <h2>Details</h2>
        <?php if (empty($detailItems)): ?>
          <p class="muted">Geen aanvullende details opgeslagen.</p>
        <?php else: ?>
           <div class="details-grid">
            <?php foreach ($detailItems as $detail): ?>
              <div class="detail-card<?= !empty($detail['interactive']) ? ' detail-card--interactive' : '' ?>">
                <span class="detail-card__label"><?= htmlspecialchars((string) $detail['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php if (!empty($detail['interactive'])): ?>
                  <button type="button" class="detail-card__trigger" data-open-modal="<?= htmlspecialchars((string) ($detail['modal_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?= htmlspecialchars((string) $detail['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </button>
                <?php elseif (!empty($detail['preformatted'])): ?>
                  <pre class="detail-card__value detail-card__value--pre"><?= htmlspecialchars((string) $detail['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
                <?php elseif (!empty($detail['multiline'])): ?>
                  <p class="detail-card__value detail-card__value--multiline"><?= nl2br(htmlspecialchars((string) $detail['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                <?php else: ?>
                  <span class="detail-card__value"><?= htmlspecialchars((string) $detail['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
      <?php if ($deviceModalItems !== []): ?>
        <div class="detail-modal" data-detail-modal="device-details" aria-hidden="true">
          <div class="detail-modal__backdrop" data-modal-close></div>
          <div class="detail-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="device-details-title">
            <header class="detail-modal__header">
              <h3 id="device-details-title">Device details</h3>
              <button type="button" class="detail-modal__close" data-modal-close aria-label="Sluiten">&times;</button>
            </header>
            <div class="detail-modal__body">
              <dl class="detail-modal__list">
                <?php foreach ($deviceModalItems as $item): ?>
                  <div>
                    <dt><?= htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                    <dd><?= htmlspecialchars((string) ($item['value'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endforeach; ?>
              </dl>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section class="case-warehouse">
      <article class="warehouse-card">
        <div class="warehouse-card__header">
          <div>
            <h2>Powiązane zasoby magazynowe</h2>
            <p class="muted">Monitoruj komponenty i zestawy przypisane do tej sprawy serwisowej.</p>
          </div>
          <a class="btn btn--ghost" href="magazyn.php?case=<?= (int) $caseId ?>">Otwórz Magazyn</a>
        </div>
        <?php if ($warehouseItems === []): ?>
          <p class="muted">Brak powiązanych pozycji magazynowych. Dodaj sprzęt do sprawy bezpośrednio w zakładce Magazyn.</p>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="case-warehouse__table">
              <thead>
                <tr>
                  <th>Pozycja</th>
                  <th>Status</th>
                  <th>Ilość</th>
                  <th>Lokalizacja</th>
                  <th>Ostatni ruch</th>
                  <th>Etykieta</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($warehouseItems as $warehouseItem): ?>
                  <?php
                    $statusKey = (string) ($warehouseItem['status'] ?? '');
                    $statusLabel = $warehouseStatusLabels[$statusKey] ?? ucfirst($statusKey);
                    $quantity = (int) ($warehouseItem['quantity'] ?? 0);
                    $reserved = (int) ($warehouseItem['reserved_quantity'] ?? 0);
                    $location = trim((string) ($warehouseItem['location'] ?? ''));
                    $lastMovement = (string) ($warehouseItem['last_movement_at'] ?? $warehouseItem['updated_at'] ?? '');
                    $movementTimestamp = $lastMovement !== '' ? date('d-m-Y H:i', strtotime($lastMovement)) : '—';
                    $referenceCode = trim((string) ($warehouseItem['reference_code'] ?? ''));
                  ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars((string) ($warehouseItem['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                      <?php if ($referenceCode !== ''): ?><small>Ref: <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                      <?php if (!empty($warehouseItem['barcode'])): ?><small>Kod: <?= htmlspecialchars((string) $warehouseItem['barcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                    </td>
                    <td><span class="status-badge status-badge--<?= htmlspecialchars(str_replace('-', '_', $statusKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                    <td><?= number_format($quantity, 0, ',', ' ') ?><small>Zarezerwowane: <?= number_format($reserved, 0, ',', ' ') ?></small></td>
                    <td><?= $location !== '' ? htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—' ?></td>
                    <td><?= htmlspecialchars($movementTimestamp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><a class="btn btn--ghost btn--small" href="warehouse-label.php?id=<?= (int) ($warehouseItem['id'] ?? 0) ?>" target="_blank" rel="noopener">Etykieta</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </article>

      <aside class="warehouse-card warehouse-card--summary">
        <h3>Stan magazynowy dla case #<?= (int) $caseId ?></h3>
        <ul class="warehouse-card__list">
          <li><span>Powiązane pozycje</span><strong><?= (int) $warehouseSummary['total'] ?></strong></li>
          <li><span>Ilość łączna</span><strong><?= number_format($warehouseTotals['quantity'], 0, ',', ' ') ?></strong></li>
          <li><span>Zarezerwowane</span><strong><?= number_format($warehouseTotals['reserved'], 0, ',', ' ') ?></strong></li>
          <li><span>Gotowe do wydania</span><strong><?= (int) $warehouseSummary['ready'] ?></strong></li>
          <li><span>W naprawie</span><strong><?= (int) $warehouseSummary['in_service'] ?></strong></li>
        </ul>
        <p class="muted">Aktualizacje statusów oraz etykiety magazynowe dostępne są w zakładce Magazyn.</p>
        <a class="btn btn--ghost" href="magazyn.php?case=<?= (int) $caseId ?>#new-entry">Dodaj komponent</a>
      </aside>
    </section>

    <section class="checklists-section">
      <?php
        $totalChecklists = count($checklists);
        $totalItems = 0;
        $totalCompleted = 0;
        $checklistProgress = [];
        foreach ($checklists as $progressChecklist) {
            $items = is_array($progressChecklist['items'] ?? null) ? $progressChecklist['items'] : [];
            $itemsCount = count($items);
            $completedCount = 0;
            foreach ($items as $item) {
                if ((int) ($item['is_completed'] ?? 0) === 1) {
                    $completedCount++;
                }
            }
            $totalItems += $itemsCount;
            $totalCompleted += $completedCount;
            $checklistProgress[(int) $progressChecklist['id']] = [
                'total' => $itemsCount,
                'completed' => $completedCount,
            ];
        }
        $overallCompletion = $totalItems > 0 ? (int) round(($totalCompleted / $totalItems) * 100) : 0;
      ?>
      <header class="checklist-board__header">
        <div>
          <h2>Checklist workflow</h2>
          <p class="checklist-board__intro">Beheer iedere checklist vanuit een overzichtelijke werkbank en zie meteen hoe ver je bent.</p>
        </div>
        <dl class="checklist-board__stats">
          <div>
            <dt>Totaal checklists</dt>
            <dd><?= (int) $totalChecklists ?></dd>
          </div>
          <div>
            <dt>Openstaande stappen</dt>
            <dd><?= (int) ($totalItems - $totalCompleted) ?></dd>
          </div>
          <div>
            <dt>Voltooid</dt>
            <dd><span><?= (int) $overallCompletion ?></span>%</dd>
          </div>
        </dl>
      </header>
      <div class="checklist-board">
        <aside class="checklist-composer">
          <div class="checklist-composer__header">
            <h3>Nieuwe checklist</h3>
            <p class="muted">Maak een checklist en wijs deze direct toe aan de juiste collega.</p>
          </div>
          <?php if (!empty($checklistErrors['general'])): ?>
            <div class="alert alert--error"><?= htmlspecialchars((string) $checklistErrors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
          <form method="POST" class="checklist-composer__form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add-checklist">
            <label class="checklist-composer__field">
              <span>Titel</span>
              <input type="text" name="title" maxlength="160" required>
              <?php if (!empty($checklistErrors['title'])): ?><small class="form-error"><?= htmlspecialchars((string) $checklistErrors['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <label class="checklist-composer__field">
              <span>Toegewezen aan</span>
              <input type="text" name="assigned_to" maxlength="120" placeholder="Bijv. Technicus Jan">
              <?php if (!empty($checklistErrors['assigned_to'])): ?><small class="form-error"><?= htmlspecialchars((string) $checklistErrors['assigned_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <label class="checklist-composer__field">
              <span>Deadline</span>
              <input type="date" name="due_date">
              <?php if (!empty($checklistErrors['due_date'])): ?><small class="form-error"><?= htmlspecialchars((string) $checklistErrors['due_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <button type="submit" class="btn btn--full">Checklist toevoegen</button>
          </form>
        </aside>

        <div class="checklist-collection">
          <?php if (empty($checklists)): ?>
            <article class="checklist-empty">
              <h3>Geen checklists</h3>
              <p class="muted">Je hebt nog geen checklists aangemaakt voor deze case. Voeg er links eentje toe om te beginnen.</p>
            </article>
          <?php else: ?>
            <?php foreach ($checklists as $checklist): ?>
              <?php
                $progress = $checklistProgress[(int) $checklist['id']] ?? ['total' => 0, 'completed' => 0];
                $progressPercent = $progress['total'] > 0 ? (int) round(($progress['completed'] / $progress['total']) * 100) : 0;
                $metaParts = [];
                if (!empty($checklist['assigned_to'])) {
                    $metaParts[] = 'Toegewezen aan ' . htmlspecialchars((string) $checklist['assigned_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                if (!empty($checklist['due_at'])) {
                    $metaParts[] = 'Deadline ' . htmlspecialchars(date('d-m-Y', strtotime((string) $checklist['due_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
              ?>
              <article class="checklist-panel">
                <header class="checklist-panel__header">
                  <div class="checklist-panel__titles">
                    <h3><?= htmlspecialchars((string) $checklist['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                    <?php if ($metaParts !== []): ?>
                      <p class="checklist-panel__meta"><?= implode(' · ', $metaParts) ?></p>
                    <?php endif; ?>
                  </div>
                  <div class="checklist-panel__tools">
                    <div class="checklist-progress" role="group" aria-label="Voortgang">
                      <span class="checklist-progress__value" aria-hidden="true"><?= (int) $progressPercent ?>%</span>
                      <div class="checklist-progress__track" role="presentation">
                        <div class="checklist-progress__bar" style="width: <?= (int) $progressPercent ?>%"></div>
                      </div>
                      <span class="sr-only"><?= (int) $progress['completed'] ?> van <?= (int) $progress['total'] ?> stappen voltooid</span>
                    </div>
                    <form method="POST">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="remove-checklist">
                      <input type="hidden" name="checklist_id" value="<?= (int) $checklist['id'] ?>">
                      <button type="submit" class="btn btn--ghost btn--small" onclick="return confirm('Checklist verwijderen?')">Verwijder</button>
                    </form>
                  </div>
                </header>

              <?php if ($checklistQuickActions !== []): ?>
                  <div class="checklist-panel__shortcuts" role="group" aria-label="Snel toevoegen">
                    <?php foreach ($checklistQuickActions as $quickAction): ?>
                      <?php if (empty($quickAction['id']) || empty($quickAction['label'])) { continue; } ?>
                      <button type="button" class="checklist-shortcut" data-modal-target="<?= htmlspecialchars((string) $quickAction['id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-checklist="<?= (int) $checklist['id'] ?>">
                        <span><?= htmlspecialchars((string) $quickAction['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      </button>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>

              <ol class="checklist-steps">
                  <?php if (empty($checklist['items'])): ?>
                    <li class="checklist-steps__empty muted">Nog geen stappen toegevoegd.</li>
                  <?php else: ?>
                    <?php foreach ($checklist['items'] as $item): ?>
                      <?php $isCompleted = (int) ($item['is_completed'] ?? 0) === 1; ?>
                      <li class="checklist-step <?= $isCompleted ? 'checklist-step--done' : '' ?>">
                        <div class="checklist-step__marker" aria-hidden="true">
                          <span></span>
                        </div>
                        <div class="checklist-step__body">
                          <div class="checklist-step__content">
                            <strong><?= htmlspecialchars((string) $item['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                            <?php if ($isCompleted): ?>
                              <span class="checklist-step__meta">Voltooid door <?= htmlspecialchars((string) ($item['completed_by'] ?? 'Onbekend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> op <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $item['completed_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <?php endif; ?>
                          </div>
                          <form method="POST" class="checklist-step__action">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="toggle-checklist-item">
                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                            <input type="hidden" name="completed" value="<?= $isCompleted ? '0' : '1' ?>">
                            <button type="submit" class="btn btn--ghost btn--small"><?= $isCompleted ? 'Markeer open' : 'Markeer voltooid' ?></button>
                          </form>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </ol>

                <form method="POST" class="checklist-step-form">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="add-checklist-item">
                  <input type="hidden" name="checklist_id" value="<?= (int) $checklist['id'] ?>">
                  <label class="sr-only" for="item-<?= (int) $checklist['id'] ?>">Nieuwe stap</label>
                  <div class="checklist-step-form__fields">
                    <input id="item-<?= (int) $checklist['id'] ?>" type="text" name="description" maxlength="255" placeholder="Voeg een stap toe" required>
                    <button type="submit" class="btn btn--ghost">Stap toevoegen</button>
                  </div>
                </form>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($checklistQuickActions !== []): ?>
        <?php foreach ($checklistQuickActions as $quickActionModal): ?>
          <?php
            $modalId = (string) ($quickActionModal['id'] ?? '');
            if ($modalId === '') {
                continue;
            }
            $modalTitleId = $modalId . '-title';
            $modalOptions = is_array($quickActionModal['options'] ?? null) ? $quickActionModal['options'] : [];
          ?>
          <div class="modal checklist-modal" id="<?= htmlspecialchars($modalId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="<?= htmlspecialchars($modalTitleId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div class="modal__panel" role="document">
              <form method="POST" class="checklist-modal-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="add-checklist-item">
                <input type="hidden" name="checklist_id" value="">
                <input type="hidden" name="description" value="">
                <header class="modal__header">
                  <div>
                    <?php if (!empty($quickActionModal['eyebrow'])): ?>
                      <p class="modal__eyebrow"><?= htmlspecialchars((string) $quickActionModal['eyebrow'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <h2 id="<?= htmlspecialchars($modalTitleId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($quickActionModal['label'] ?? 'Checklist actie'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
                  </div>
                  <button type="button" class="modal__close" data-modal-close aria-label="Zamknij okno">&times;</button>
                </header>
                <div class="modal__body">
                  <?php if (!empty($quickActionModal['description'])): ?>
                    <p class="checklist-modal__description"><?= htmlspecialchars((string) $quickActionModal['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                  <?php endif; ?>
                  <fieldset class="checklist-modal__fieldset">
                    <legend class="sr-only">Wybierz zadanie do dodania</legend>
                    <div class="checklist-modal__options">
                      <?php foreach ($modalOptions as $index => $option): ?>
                        <?php
                          $optionId = $modalId . '-option-' . ($option['id'] ?? ('option-' . $index));
                          $optionTitle = (string) ($option['title'] ?? 'Opcja');
                          $optionDescription = (string) ($option['description'] ?? '');
                          $optionValue = (string) ($option['value'] ?? '');
                          $requiresInput = !empty($option['requires_input']);
                          $inputId = $requiresInput ? $optionId . '-input' : '';
                        ?>
                        <div class="checklist-modal__option">
                          <div class="checklist-modal__option-header">
                            <input type="radio"
                              id="<?= htmlspecialchars($optionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                              name="preset_option"
                              value="<?= htmlspecialchars((string) ($option['id'] ?? ('option-' . $index)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                              <?php if ($index === 0): ?>required<?php endif; ?>
                              <?php if ($optionValue !== ''): ?>data-description="<?= htmlspecialchars($optionValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?php endif; ?>
                              <?php if ($requiresInput): ?>data-requires-input="true" data-input-target="<?= htmlspecialchars($inputId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?php endif; ?>
                            >
                            <label for="<?= htmlspecialchars($optionId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                              <span class="checklist-modal__option-title"><?= htmlspecialchars($optionTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                              <?php if ($optionDescription !== ''): ?>
                                <span class="checklist-modal__option-text"><?= htmlspecialchars($optionDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                              <?php endif; ?>
                            </label>
                          </div>
                          <?php if ($requiresInput): ?>
                            <div class="checklist-modal__custom" data-custom-input="<?= htmlspecialchars($inputId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" hidden>
                              <label for="<?= htmlspecialchars($inputId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($option['input_label'] ?? 'Opis zadania'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                              <input type="text" id="<?= htmlspecialchars($inputId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars((string) ($option['input_placeholder'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="255">
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </fieldset>
                  <div class="checklist-modal__error" role="alert" hidden></div>
                </div>
                <footer class="modal__footer">
                  <button type="submit" class="btn">
                    <?= htmlspecialchars((string) ($quickActionModal['submit_label'] ?? 'Dodaj zadanie'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </button>
                </footer>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <section class="repair-workflow" id="repair-workflow">
      <div class="workflow-shell">
         <header class="workflow-hero">
          <div class="workflow-hero__content">
            <p class="workflow-hero__eyebrow">Proces naprawczy</p>
            <h2>Tablica zadań naprawy</h2>
            <p>Zebraliśmy wszystkie kroki naprawy w jednym miejscu – widzisz kontekst, priorytety i statusy zanim przekażesz zadanie dalej.</p>
            <div class="workflow-hero__chips">
              <span class="workflow-chip workflow-chip--inline">Śledzenie etapów</span>
              <span class="workflow-chip workflow-chip--inline">Automatyczne szablony</span>
            </div>
          </div>
          <ul class="workflow-hero__stats" role="list">
            <li class="workflow-stat workflow-stat--total">
              <span class="workflow-stat__label">Łącznie</span>
              <span class="workflow-stat__value"><?= (int) $workflowTotals['total'] ?></span>
              <span class="workflow-stat__hint">wszystkie zadania</span>
            </li>
            <li class="workflow-stat workflow-stat--active">
              <span class="workflow-stat__label">Aktywne</span>
              <span class="workflow-stat__value"><?= (int) $workflowTotals['active'] ?></span>
              <span class="workflow-stat__hint">w toku</span>
            </li>
            <li class="workflow-stat workflow-stat--blocked">
              <span class="workflow-stat__label">Zablokowane</span>
              <span class="workflow-stat__value"><?= (int) $workflowTotals['blocked'] ?></span>
              <span class="workflow-stat__hint">wymagają decyzji</span>
            </li>
            <li class="workflow-stat workflow-stat--overdue">
              <span class="workflow-stat__label">Po terminie</span>
              <span class="workflow-stat__value"><?= (int) $workflowTotals['overdue'] ?></span>
              <span class="workflow-stat__hint">konieczna reakcja</span>
            </li>
          </ul>
        </header>
        <div class="workflow-grid">
          <aside class="workflow-panels">
            <article class="workflow-panel workflow-panel--primary">
              <div class="workflow-panel__header">
                <p class="workflow-panel__eyebrow">Planowanie</p>
                <h3>Nowe zadanie</h3>
                <p>Wprowadź najważniejsze informacje, aby od razu przypisać pracę do odpowiedniej osoby.</p>
              </div>
              <?php if (!empty($workflowErrors['general'])): ?>
                <?php $workflowGeneral = is_array($workflowErrors['general']) ? implode(' ', array_map('strval', $workflowErrors['general'])) : (string) $workflowErrors['general']; ?>
                <div class="alert alert--error"><?= htmlspecialchars($workflowGeneral, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <?php endif; ?>
              <form method="POST" class="workflow-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="workflow-create-task">
                <div class="form-field form-field--wide">
                  <label>Temat zadania
                    <input type="text" name="title" maxlength="191" value="<?= htmlspecialchars($workflowFormValues['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                  </label>
                  <?php if (!empty($workflowErrors['title'])): ?><small class="form-error"><?= htmlspecialchars(is_array($workflowErrors['title']) ? implode(' ', array_map('strval', $workflowErrors['title'])) : (string) $workflowErrors['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </div>
                <div class="form-field">
                  <label>Etap procesu
                    <select name="stage" required>
                      <?php foreach ($workflowStages as $stageKey => $stageLabel): ?>
                        <option value="<?= htmlspecialchars($stageKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $workflowFormValues['stage'] === $stageKey ? 'selected' : '' ?>><?= htmlspecialchars($stageLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <?php if (!empty($workflowErrors['stage'])): ?><small class="form-error"><?= htmlspecialchars(is_array($workflowErrors['stage']) ? implode(' ', array_map('strval', $workflowErrors['stage'])) : (string) $workflowErrors['stage'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </div>
                <div class="form-field">
                  <label>Priorytet
                    <select name="priority" required>
                      <?php foreach ($workflowPriorities as $priorityKey => $priorityLabel): ?>
                        <option value="<?= htmlspecialchars($priorityKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $workflowFormValues['priority'] === $priorityKey ? 'selected' : '' ?>><?= htmlspecialchars($priorityLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <?php if (!empty($workflowErrors['priority'])): ?><small class="form-error"><?= htmlspecialchars(is_array($workflowErrors['priority']) ? implode(' ', array_map('strval', $workflowErrors['priority'])) : (string) $workflowErrors['priority'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </div>
                <div class="form-field">
                  <label>Przydzielony technik
                    <input type="text" name="assigned_to" maxlength="120" placeholder="Technik / zespół" value="<?= htmlspecialchars($workflowFormValues['assigned_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  </label>
                  <?php if (!empty($workflowErrors['assigned_to'])): ?><small class="form-error"><?= htmlspecialchars(is_array($workflowErrors['assigned_to']) ? implode(' ', array_map('strval', $workflowErrors['assigned_to'])) : (string) $workflowErrors['assigned_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </div>
                <div class="form-field">
                  <label>Termin wykonania
                    <input type="datetime-local" name="due_at" value="<?= htmlspecialchars($workflowFormValues['due_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  </label>
                  <?php if (!empty($workflowErrors['due_at'])): ?><small class="form-error"><?= htmlspecialchars(is_array($workflowErrors['due_at']) ? implode(' ', array_map('strval', $workflowErrors['due_at'])) : (string) $workflowErrors['due_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </div>
                <div class="form-field form-field--wide">
                  <label>Szczegóły zadania
                    <textarea name="description" rows="3" placeholder="Kroki do wykonania, oczekiwany wynik"><?= htmlspecialchars($workflowFormValues['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                  </label>
                  <?php if (!empty($workflowErrors['description'])): ?><small class="form-error"><?= htmlspecialchars(is_array($workflowErrors['description']) ? implode(' ', array_map('strval', $workflowErrors['description'])) : (string) $workflowErrors['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </div>
                <div class="form-field form-field--wide">
                  <button type="submit" class="btn btn--full">Dodaj zadanie</button>
                </div>
              </form>
            </article>
            <article class="workflow-panel">
              <div class="workflow-panel__header">
                <p class="workflow-panel__eyebrow">Statusy</p>
                <h3>Stan pracy</h3>
                <p>Natychmiastowa widoczność, które karty wymagają uwagi.</p>
              </div>
              <ul class="workflow-status-list" role="list">
                <?php foreach ($workflowStatuses as $statusKey => $statusLabel): ?>
                  <li>
                    <div>
                      <span class="workflow-status-list__label"><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <span class="workflow-status-list__value"><?= (int) ($workflowStatusTotals[$statusKey] ?? 0) ?></span>
                    </div>
                    <span class="workflow-status-list__code"><?= htmlspecialchars(strtoupper($statusKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </article>
            <article class="workflow-panel workflow-panel--templates">
              <div class="workflow-panel__header">
                <p class="workflow-panel__eyebrow">Automatyzacja</p>
                <h3>Szablony kroków</h3>
                <p>Jednym kliknięciem dodaj pełny zestaw czynności.</p>
              </div>
              <?php if (!empty($workflowErrors['template'])): ?><small class="form-error"><?= htmlspecialchars(is_array($workflowErrors['template']) ? implode(' ', array_map('strval', $workflowErrors['template'])) : (string) $workflowErrors['template'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
              <div class="workflow-templates">
                <?php foreach ($workflowTemplates as $templateKey => $template): ?>
                  <form method="POST" class="workflow-template" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="workflow-apply-template">
                    <input type="hidden" name="template" value="<?= htmlspecialchars($templateKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <div>
                      <p class="workflow-template__eyebrow"><?= htmlspecialchars((string) ($template['eyebrow'] ?? 'Szablon'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                      <h4><?= htmlspecialchars((string) $template['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h4>
                      <p><?= htmlspecialchars((string) $template['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                      <p class="workflow-template__count"><?= count($template['tasks']) ?> kroków</p>
                    </div>
                    <button type="submit" class="btn btn--ghost btn--small">Dodaj</button>
                  </form>
                <?php endforeach; ?>
              </div>
              </article>
          </aside>
          <div class="workflow-board" role="region" aria-label="Tablica procesu naprawczego">
            <div class="workflow-board__header">
              <div>
                <p class="workflow-board__eyebrow">Aktualne zadania</p>
                <h3>Mapa etapów</h3>
              </div>
              <p class="workflow-board__description">Kolumny automatycznie dopasowują się do szerokości ekranu, a karty zachowują pełny kontekst zadania.</p>
            </div>
            <div class="workflow-board__lanes">
              <?php foreach ($workflowStages as $stageKey => $stageLabel): ?>
                <?php $stageTasks = $workflowBoard[$stageKey] ?? []; ?>
                <section class="workflow-stage" aria-labelledby="workflow-stage-<?= htmlspecialchars($stageKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <header class="workflow-stage__header">
                    <div>
                      <p class="workflow-stage__eyebrow">Etap procesu</p>
                      <h3 id="workflow-stage-<?= htmlspecialchars($stageKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($stageLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                    </div>
                    <span class="workflow-stage__count" aria-label="Liczba zadań w kolumnie"><?= count($stageTasks) ?></span>
                  </header>
                  <div class="workflow-stage__list">
                    <?php if ($stageTasks === []): ?>
                      <p class="workflow-column__empty muted">Brak zadań w tej fazie.</p>
                    <?php else: ?>
                      <?php foreach ($stageTasks as $workflowTask): ?>
                        <?php
                          $taskId = (int) ($workflowTask['id'] ?? 0);
                          $currentStage = isset($workflowStages[$workflowTask['stage']]) ? (string) $workflowTask['stage'] : $stageKey;
                          $taskStatusKey = isset($workflowStatuses[$workflowTask['status']]) ? (string) $workflowTask['status'] : 'todo';
                          $taskStatusLabel = $workflowStatuses[$taskStatusKey] ?? ucfirst($taskStatusKey);
                        $taskPriorityKey = strtolower((string) ($workflowTask['priority'] ?? 'normal'));
                        $priorityLabel = $workflowPriorities[$taskPriorityKey] ?? ucfirst($taskPriorityKey);
                          $taskAssigned = trim((string) ($workflowTask['assigned_to'] ?? ''));
                          $taskDueRaw = $formatDateTime($workflowTask['due_at'] ?? null);
                          $taskDueLabel = $taskDueRaw !== '' ? $taskDueRaw : null;
                          $taskStartedLabel = $formatDateTime($workflowTask['started_at'] ?? null);
                          $taskCompletedLabel = $formatDateTime($workflowTask['completed_at'] ?? null);
                          $taskBlockedReason = trim((string) ($workflowTask['blocked_reason'] ?? ''));
                          $taskErrors = $workflowTaskErrors[$taskId] ?? [];
                          $hasErrors = $taskErrors !== [];
                        ?>
                        <article class="workflow-card workflow-card--status-<?= htmlspecialchars($taskStatusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> workflow-card--priority-<?= htmlspecialchars($taskPriorityKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                          <header class="workflow-card__header">
                            <div>
                              <h4><?= htmlspecialchars((string) ($workflowTask['title'] ?? 'Zadanie'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h4>
                              <?php if (!empty($workflowTask['description'])): ?>
                                <p class="workflow-card__description"><?= nl2br(htmlspecialchars((string) $workflowTask['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                              <?php endif; ?>
                            </div>
                          <div class="workflow-card__chips">
                              <span class="workflow-chip workflow-chip--status"><?= htmlspecialchars($taskStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                              <span class="workflow-chip workflow-chip--priority"><?= htmlspecialchars($priorityLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                              <dd><?= htmlspecialchars($taskStartedLabel !== '' ? $taskStartedLabel : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                            </div>
                            </header>
                          <dl class="workflow-card__meta">
                            <?php if ($taskAssigned !== ''): ?>
                              <div>
                                <dt>Przydzielony</dt>
                                <dd><?= htmlspecialchars($taskAssigned, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                              </div>
                            <?php endif; ?>
                            <?php if ($taskDueLabel !== null): ?>
                              <div>
                                <dt>Termin</dt>
                                <dd><?= htmlspecialchars($taskDueLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                              </div>
                            <?php endif; ?>
                            <?php if (!empty($workflowTask['started_at'])): ?>
                              <div>
                                <dt>Start</dt>
                                <dd><?= htmlspecialchars($taskStartedLabel !== '' ? $taskStartedLabel : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                              </div>
                            <?php endif; ?>
                            <?php if (!empty($workflowTask['completed_at'])): ?>
                              <div>
                                <dt>Zakończono</dt>
                                <dd><?= htmlspecialchars($taskCompletedLabel !== '' ? $taskCompletedLabel : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                              </div>
                            <?php endif; ?>
                          </dl>
                          <?php if ($taskStatusKey === 'blocked' && $taskBlockedReason !== ''): ?>
                            <p class="workflow-card__blocked-reason">Blokada: <?= nl2br(htmlspecialchars($taskBlockedReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                          <?php endif; ?>
                          <details class="workflow-card__controls" <?= $hasErrors ? 'open' : '' ?>>
                            <summary>Aktualizuj zadanie</summary>
                            <div class="workflow-card__actions">
                              <form method="POST" class="workflow-card__form" novalidate>
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="workflow-update-task">
                                <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                <label>
                                  <span>Etap</span>
                                  <select name="stage">
                                    <?php foreach ($workflowStages as $optionKey => $optionLabel): ?>
                                      <option value="<?= htmlspecialchars($optionKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $optionKey === $currentStage ? 'selected' : '' ?>><?= htmlspecialchars($optionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </label>
                                <?php if (!empty($taskErrors['stage'])): ?><small class="form-error"><?= htmlspecialchars(is_array($taskErrors['stage']) ? implode(' ', array_map('strval', $taskErrors['stage'])) : (string) $taskErrors['stage'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                                <label>
                                  <span>Status</span>
                                  <select name="status">
                                    <?php foreach ($workflowStatuses as $statusKey => $statusLabel): ?>
                                      <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $statusKey === $taskStatusKey ? 'selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </label>
                                <?php if (!empty($taskErrors['status'])): ?><small class="form-error"><?= htmlspecialchars(is_array($taskErrors['status']) ? implode(' ', array_map('strval', $taskErrors['status'])) : (string) $taskErrors['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                                <label>
                                  <span>Priorytet</span>
                                  <select name="priority">
                                    <?php foreach ($workflowPriorities as $priorityKey => $priorityOptionLabel): ?>
                                      <option value="<?= htmlspecialchars($priorityKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $priorityKey === $taskPriorityKey ? 'selected' : '' ?>><?= htmlspecialchars($priorityOptionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                  </select>
                                </label>
                                <?php if (!empty($taskErrors['priority'])): ?><small class="form-error"><?= htmlspecialchars(is_array($taskErrors['priority']) ? implode(' ', array_map('strval', $taskErrors['priority'])) : (string) $taskErrors['priority'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                                <label>
                                  <span>Powód blokady</span>
                                  <textarea name="blocked_reason" rows="2" placeholder="Opis przeszkody"><?= htmlspecialchars($workflowTask['blocked_reason'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                                </label>
                                <?php if (!empty($taskErrors['blocked_reason'])): ?><small class="form-error"><?= htmlspecialchars(is_array($taskErrors['blocked_reason']) ? implode(' ', array_map('strval', $taskErrors['blocked_reason'])) : (string) $taskErrors['blocked_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                                <button type="submit" class="btn btn--small">Zapisz zmiany</button>
                              </form>
                              <form method="POST" class="workflow-card__delete" onsubmit="return confirm('Usunąć zadanie?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="workflow-delete-task">
                                <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                <button type="submit" class="btn btn--ghost btn--danger btn--small">Usuń zadanie</button>
                              </form>
                            </div>
                          </details>
                        </article>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </section>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="notes-section">
      <div class="notes-header">
        <h2>Notities</h2>
      </div>
      <div class="notes-grid">
        <div class="notes-list">
          <?php if (empty($notes)): ?>
            <p class="muted">Er zijn nog geen notities voor deze case.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($notes as $note): ?>
                <li>
                  <div class="note-header">
                    <strong><?= htmlspecialchars((string) $note['author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <span class="muted"><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $note['created_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </div>
                  <div class="note-body"><?= nl2br(htmlspecialchars((string) $note['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                  <div class="note-actions">
                    <details class="note-edit"<?= isset($noteEditErrors[(int) $note['id']]) ? ' open' : '' ?>>
                      <summary>Bewerk notitie</summary>
                      <?php if (!empty($noteEditErrors[(int) $note['id']]['general'])): ?>
                        <div class="alert alert--error"><?= htmlspecialchars((string) $noteEditErrors[(int) $note['id']]['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <?php endif; ?>
                      <form method="POST" class="note-edit__form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update-note">
                        <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                        <label class="sr-only" for="note-body-<?= (int) $note['id'] ?>">Notitie</label>
                        <textarea id="note-body-<?= (int) $note['id'] ?>" name="body" rows="4" required><?= htmlspecialchars($noteEditValues[(int) $note['id']] ?? (string) $note['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                        <?php if (!empty($noteEditErrors[(int) $note['id']]['body'])): ?><small class="form-error"><?= htmlspecialchars((string) $noteEditErrors[(int) $note['id']]['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                        <div class="note-edit__actions">
                          <button type="submit" class="btn btn--ghost btn--small">Opslaan</button>
                        </div>
                      </form>
                    </details>
                    <form method="POST" class="note-delete-form" onsubmit="return confirm('Notitie verwijderen?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete-note">
                      <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                      <button type="submit" class="btn btn--ghost btn--small">Verwijderen</button>
                    </form>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <div class="note-form">
          <h3>Nieuwe notitie</h3>
          <?php if (!empty($errors['general'])): ?>
            <div class="alert alert--error"><?= htmlspecialchars((string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
             <input type="hidden" name="action" value="add-note">
            <label for="body">Notitie</label>
            <textarea name="body" id="body" rows="5" required></textarea>
            <?php if (!empty($errors['body'])): ?><small class="form-error"><?= htmlspecialchars((string) $errors['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            <button type="submit" class="btn">Opslaan</button>
          </form>
        </div>
      </div>
    </section>
    </div>
      <aside class="case-layout__secondary">
        <section class="case-sidebar" aria-label="Panel sterowania sprawą">
          <article class="case-sidebar__card case-sidebar__card--accent">
            <p class="case-sidebar__eyebrow">Zespół</p>
            <h2>Przejmij sprawę</h2>
            <p class="muted">Przypisz się do sprawy, aby pojawiła się w Twojej liście zadań.</p>
            <form method="post" class="case-self-assign__form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="self-assign">
              <button type="submit" class="btn btn--primary btn--full" <?= ($userAlreadyAssigned || $currentEmployeeId === null) ? 'disabled' : '' ?>>
                <?= $currentEmployeeId === null ? 'Koppel medewerkerprofiel' : ($userAlreadyAssigned ? 'Reeds toegewezen' : 'Przyjmij zlecenie') ?>
              </button>
            </form>
            <?php if ($currentEmployeeId === null): ?>
              <p class="form-error">Er is geen medewerkerprofiel gekoppeld aan dit account. Vraag een beheerder om jouw gegevens te koppelen.</p>
            <?php endif; ?>
          </article>

          <article class="case-sidebar__card">
            <h2>Priorytety i zespół</h2>
            <form method="post" class="case-meta-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="update-case-meta">
              <div class="form-grid">
                <label class="form-field">
                  <span class="form-field__label">Priorytet</span>
                  <select name="priority">
                    <?php foreach ($priorityOptions as $key => $label): ?>
                      <option value="<?= htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $priorityValue === $key ? ' selected' : '' ?>><?= htmlspecialchars((string) $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (!empty($errors['priority'])): ?><small class="form-error"><?= htmlspecialchars((string) $errors['priority'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <label class="form-field">
                  <span class="form-field__label">Termin SLA</span>
                  <input type="datetime-local" name="sla_due_at" value="<?= htmlspecialchars($slaDueInputValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <?php if (!empty($errors['sla_due_at'])): ?><small class="form-error"><?= htmlspecialchars((string) $errors['sla_due_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <label class="form-field">
                  <span class="form-field__label">Główny technik</span>
                  <select name="primary_employee_id">
                    <option value="">—</option>
                    <?php foreach ($activeEmployees as $employee): ?>
                      <?php $employeeId = (int) ($employee['id'] ?? 0); ?>
                      <option value="<?= $employeeId ?>"<?= $primaryEmployeeValue === $employeeId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($employee['full_name'] ?? 'Pracownik'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php if (!empty($errors['primary_employee_id'])): ?><small class="form-error"><?= htmlspecialchars((string) $errors['primary_employee_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </label>
              </div>

              <div class="assignments-editor">
                <h3>Przypisani pracownicy</h3>
                <table class="table">
                  <thead>
                    <tr>
                      <th>Pracownik</th>
                      <th>Rola</th>
                      <th>Notatki</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php $assignmentRowsCount = max(count($activeCaseAssignments) + 1, 3); ?>
                    <?php for ($i = 0; $i < $assignmentRowsCount; $i++): ?>
                      <?php $rowData = $activeCaseAssignments[$i] ?? ['employee_id' => '', 'assignment_type' => 'primary', 'notes' => '']; ?>
                      <tr>
                        <td>
                          <select name="assignments[<?= $i ?>][employee_id]">
                            <option value="">—</option>
                            <?php foreach ($activeEmployees as $employee): ?>
                              <?php $employeeId = (int) ($employee['id'] ?? 0); ?>
                              <option value="<?= $employeeId ?>"<?= (int) ($rowData['employee_id'] ?? 0) === $employeeId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($employee['full_name'] ?? 'Pracownik'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td>
                          <?php $assignmentType = (string) ($rowData['assignment_type'] ?? 'primary'); ?>
                          <select name="assignments[<?= $i ?>][type]">
                            <option value="primary"<?= $assignmentType === 'primary' ? ' selected' : '' ?>>Główny</option>
                            <option value="assistant"<?= $assignmentType === 'assistant' ? ' selected' : '' ?>>Wsparcie</option>
                            <option value="observer"<?= $assignmentType === 'observer' ? ' selected' : '' ?>>Obserwator</option>
                          </select>
                        </td>
                        <td>
                          <input type="text" name="assignments[<?= $i ?>][notes]" value="<?= htmlspecialchars((string) ($rowData['notes'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="191" placeholder="Uwagi">
                        </td>
                      </tr>
                    <?php endfor; ?>
                  </tbody>
                </table>
              </div>
              <button type="submit" class="btn btn--primary btn--full">Zapisz zespół</button>
            </form>
          </article>

          <article class="case-sidebar__card">
            <div class="case-sidebar__header">
              <h2>Odbiór urządzenia</h2>
              <p class="muted">Monitoruj potwierdzenia wydania i finalizację sprawy.</p>
            </div>
            <?php if ($pickupConfirmations === []): ?>
              <p class="muted">Er zijn nog geen ophaalbevestigingen gekoppeld aan deze case.</p>
            <?php else: ?>
              <div class="table-wrapper">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Code</th>
                      <th>Datum gereed</th>
                      <th>Status</th>
                      <th>Laatst bijgewerkt</th>
                      <th>Acties</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($pickupConfirmations as $confirmation): ?>
                      <?php
                        $confirmationId = (int) ($confirmation['id'] ?? 0);
                        $confirmationCode = (string) ($confirmation['ophaalcode'] ?? '');
                        $confirmationStatus = (string) ($confirmation['status'] ?? '');
                        $pickupStatusClass = $confirmationStatus === 'opgehaald' ? 'status-pill--picked' : 'status-pill--ready';
                        $statusLabel = $confirmationStatus !== '' ? ucfirst($confirmationStatus) : 'Onbekend';
                        $readyDateLabel = (string) ($confirmation['datumgereed'] ?? '');
                        $updatedAtRaw = $confirmation['updated_at'] ?? $confirmation['pickup_signed_at'] ?? $confirmation['created_at'] ?? null;
                        $updatedLabel = $updatedAtRaw ? date('d-m-Y H:i', strtotime((string) $updatedAtRaw)) : '—';
                        $hasSignature = !empty($confirmation['pickup_signature']);
                      ?>
                      <tr>
                        <td><?= htmlspecialchars($confirmationCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($readyDateLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><span class="status-pill <?= htmlspecialchars($pickupStatusClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars($updatedLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td>
                          <div class="button-row">
                            <a class="btn btn--ghost btn--small" href="generate-apparaat-opgehaald.php?id=<?= $confirmationId ?>" target="_blank" rel="noopener">PDF</a>
                            <?php if (!$hasSignature && $confirmationStatus === 'klaar'): ?>
                              <a class="btn btn--ghost btn--small" href="pickup-sign.php?id=<?= $confirmationId ?>">Handtekening</a>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
            <div class="button-row">
              <button class="btn btn--primary" type="button" data-modal-target="case-close-modal">Zamknij case i wystaw potwierdzenie</button>
              <?php if ($latestPickupConfirmation !== null): ?>
                <a class="btn btn--ghost" href="generate-apparaat-opgehaald.php?id=<?= (int) ($latestPickupConfirmation['id'] ?? 0) ?>" target="_blank" rel="noopener">Laatste bevestiging</a>
              <?php endif; ?>
            </div>
          </article>

          <article class="case-sidebar__card">
            <h2>Plan wizyt</h2>
            <?php if ($caseAppointments === []): ?>
              <p class="muted">Brak zaplanowanych wizyt dla tego zlecenia.</p>
            <?php else: ?>
              <table class="table">
                <thead>
                  <tr>
                    <th>Termin</th>
                    <th>Tytuł</th>
                    <th>Pracownicy</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($caseAppointments as $appointment): ?>
                    <tr>
                      <td><?= htmlspecialchars((string) ($appointment['start_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars((string) ($appointment['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td>
                        <?php if (empty($appointment['attendees'])): ?>
                          <span class="muted">—</span>
                        <?php else: ?>
                          <ul class="attendee-list">
                            <?php foreach ($appointment['attendees'] as $attendee): ?>
                              <li><?= htmlspecialchars((string) ($attendee['full_name'] ?? 'Pracownik'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                          </ul>
                        <?php endif; ?>
                      </td>
                      <td><?= htmlspecialchars((string) ($appointment['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
            <button class="btn btn--ghost btn--full" type="button" data-modal-target="case-appointment-modal">Zaplanuj wizytę</button>
          </article>
        </section>
      </aside>
    </div>

    <div
      class="modal"
      id="partner-request-modal"
      role="dialog"
      aria-modal="true"
      aria-hidden="true"
      aria-labelledby="partner-request-modal-title"
      <?= $partnerRequestErrors !== [] ? ' data-open-on-load="true"' : '' ?>
    >
      <div class="modal__panel" role="document">
        <header class="modal__header">
          <div>
            <p class="modal__eyebrow">Partner</p>
            <h2 id="partner-request-modal-title">Poproś o pełne dane klienta</h2>
          </div>
          <button type="button" class="modal__close" data-modal-close aria-label="Sluit">&times;</button>
        </header>
        <form method="post" class="modal__body" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="request-customer-data">
          <label class="form-field">
            <span class="form-field__label">Uzasadnienie</span>
            <textarea name="reason" rows="4" maxlength="500" required placeholder="Opisz, dlaczego potrzebujesz dodatkowych danych."></textarea>
            <?php if (!empty($partnerRequestErrors['reason'])): ?><small class="form-error"><?= htmlspecialchars(is_array($partnerRequestErrors['reason']) ? implode(' ', array_map('strval', $partnerRequestErrors['reason'])) : (string) $partnerRequestErrors['reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            <?php if (!empty($partnerRequestErrors['general'])): ?><small class="form-error"><?= htmlspecialchars(is_array($partnerRequestErrors['general']) ? implode(' ', array_map('strval', $partnerRequestErrors['general'])) : (string) $partnerRequestErrors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
          </label>
          <div class="modal__footer">
            <button type="button" class="btn btn--ghost" data-modal-close>Anuluj</button>
            <button type="submit" class="btn btn--primary">Wyślij prośbę</button>
          </div>
        </form>
      </div>
    </div>

    <div
      class="modal"
      id="partner-response-modal"
      role="dialog"
      aria-modal="true"
      aria-hidden="true"
      aria-labelledby="partner-response-modal-title"
      <?= $partnerResponseErrors !== [] ? ' data-open-on-load="true"' : '' ?>
    >
      <div class="modal__panel" role="document">
        <header class="modal__header">
          <div>
            <p class="modal__eyebrow">Partner</p>
            <h2 id="partner-response-modal-title">Wniosek o dane klienta</h2>
          </div>
          <button type="button" class="modal__close" data-modal-close aria-label="Sluit">&times;</button>
        </header>
        <form method="post" class="modal__body" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="respond-customer-data-request">
          <?php if (!empty($partnerDataRequest['reason'])): ?>
            <p><strong>Uzasadnienie partnera:</strong><br><?= nl2br(htmlspecialchars((string) $partnerDataRequest['reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
          <?php endif; ?>
          <label class="form-field">
            <span class="form-field__label">Notatka zwrotna</span>
            <textarea name="response_note" rows="3" maxlength="500" placeholder="Opcjonalnie podaj uzasadnienie decyzji."></textarea>
          </label>
          <?php if (!empty($partnerResponseErrors['general'])): ?><div class="alert alert--danger"><?= htmlspecialchars(is_array($partnerResponseErrors['general']) ? implode(' ', array_map('strval', $partnerResponseErrors['general'])) : (string) $partnerResponseErrors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
          <?php if (!empty($partnerResponseErrors['decision'])): ?><div class="alert alert--danger"><?= htmlspecialchars(is_array($partnerResponseErrors['decision']) ? implode(' ', array_map('strval', $partnerResponseErrors['decision'])) : (string) $partnerResponseErrors['decision'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
          <div class="modal__footer">
            <button type="submit" name="decision" value="approve" class="btn btn--primary">Zatwierdź</button>
            <button type="submit" name="decision" value="decline" class="btn btn--ghost">Odmów</button>
          </div>
        </form>
      </div>
    </div>

    <div
      class="modal"
      id="case-close-modal"
      role="dialog"
      aria-modal="true"
      aria-hidden="true"
      aria-labelledby="case-close-modal-title"
      data-case-close-modal
      <?= $shouldOpenCaseCloseModal ? ' data-open-on-load="true"' : '' ?>
    >
      <div class="modal__panel" role="document">
        <header class="modal__header">
          <div>
            <p class="modal__eyebrow">Case afsluiting</p>
            <h2 id="case-close-modal-title">Zamknij case #<?= (int) $caseId ?></h2>
          </div>
          <button type="button" class="modal__close" data-modal-close aria-label="Zamknij okno">&times;</button>
        </header>
        <form method="post" class="case-close-form" novalidate>
          <div class="modal__body">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="close-case">
            <?php if (!empty($closeCaseErrors['general'])): ?>
              <?php $closeGeneral = is_array($closeCaseErrors['general']) ? implode(' ', array_map('strval', $closeCaseErrors['general'])) : (string) $closeCaseErrors['general']; ?>
              <div class="alert alert--danger"><?= htmlspecialchars($closeGeneral, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="form-grid">
              <label class="form-field">
                <span class="form-field__label">Ophaalcode</span>
                <input type="text" name="pickup_code" value="<?= htmlspecialchars($closeCaseFormValues['pickup_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="32" placeholder="Automatisch genereren">
                <small class="muted">Laat leeg om automatisch een unieke code te genereren.</small>
                <?php if (!empty($closeCaseErrors['pickup_code'])): ?><small class="form-error"><?= htmlspecialchars(is_array($closeCaseErrors['pickup_code']) ? implode(' ', array_map('strval', $closeCaseErrors['pickup_code'])) : (string) $closeCaseErrors['pickup_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
              </label>
              <label class="form-field">
                <span class="form-field__label">Datum afgifte</span>
                <input type="date" name="ready_date" value="<?= htmlspecialchars($closeCaseFormValues['ready_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?php if (!empty($closeCaseErrors['ready_date'])): ?><small class="form-error"><?= htmlspecialchars(is_array($closeCaseErrors['ready_date']) ? implode(' ', array_map('strval', $closeCaseErrors['ready_date'])) : (string) $closeCaseErrors['ready_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
              </label>
            </div>
            <label class="form-field">
              <span class="form-field__label">Opmerkingen voor bevestiging</span>
              <textarea name="remarks" rows="3" placeholder="Bijvoorbeeld: apparaat opgehaald door klant"><?= htmlspecialchars($closeCaseFormValues['remarks'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              <?php if (!empty($closeCaseErrors['remarks'])): ?><small class="form-error"><?= htmlspecialchars(is_array($closeCaseErrors['remarks']) ? implode(' ', array_map('strval', $closeCaseErrors['remarks'])) : (string) $closeCaseErrors['remarks'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <label class="form-field">
              <span class="form-field__label">Notitie voor het dossier</span>
              <textarea name="closing_note" rows="3" placeholder="Deze notitie wordt toegevoegd aan de case"><?= htmlspecialchars($closeCaseFormValues['closing_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              <?php if (!empty($closeCaseErrors['closing_note'])): ?><small class="form-error"><?= htmlspecialchars(is_array($closeCaseErrors['closing_note']) ? implode(' ', array_map('strval', $closeCaseErrors['closing_note'])) : (string) $closeCaseErrors['closing_note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <fieldset class="form-field">
              <legend class="form-field__label">Automatische meldingen</legend>
              <label>
                <input type="checkbox" name="notify_email" value="1" <?= $closeCaseFormValues['notify_email'] === '1' ? 'checked' : '' ?> <?= $caseCustomerEmail === '' ? 'disabled' : '' ?>>
                <span>E-mail naar <?= $caseCustomerEmail !== '' ? htmlspecialchars($caseCustomerEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'geen e-mailadres beschikbaar' ?></span>
              </label>
              <label>
                <input type="checkbox" name="notify_sms" value="1" <?= $closeCaseFormValues['notify_sms'] === '1' ? 'checked' : '' ?> <?= $caseCustomerPhone === '' ? 'disabled' : '' ?>>
                <span>SMS naar <?= $caseCustomerPhone !== '' ? htmlspecialchars($caseCustomerPhone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'geen telefoonnummer beschikbaar' ?></span>
              </label>
            </fieldset>
          </div>
          <footer class="modal__footer">
            <button type="button" class="btn btn--ghost" data-modal-close>Annuleer</button>
            <button type="submit" class="btn btn--primary">Case sluiten</button>
          </footer>
        </form>
      </div>
    </div>

    <div
      class="modal appointment-modal"
      id="case-appointment-modal"
      role="dialog"
      aria-modal="true"
      aria-hidden="true"
      aria-labelledby="case-appointment-modal-title"
      data-case-appointment-modal
      <?= $shouldOpenAppointmentModal ? ' data-open-on-load="true"' : '' ?>
    >
      <div class="modal__panel" role="document">
        <header class="modal__header">
          <div>
            <p class="modal__eyebrow">Nowa wizyta</p>
            <h2 id="case-appointment-modal-title">Zaplanuj wizytę dla case #<?= (int) $caseId ?></h2>
          </div>
          <button type="button" class="modal__close" data-modal-close aria-label="Zamknij okno">&times;</button>
        </header>
        <form method="post" class="appointment-form" novalidate>
          <div class="modal__body">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create-appointment">
            <?php if (!empty($appointmentFormErrors['general'])): ?>
              <?php $appointmentGeneral = is_array($appointmentFormErrors['general']) ? implode(' ', array_map('strval', $appointmentFormErrors['general'])) : (string) $appointmentFormErrors['general']; ?>
              <div class="alert alert--danger"><?= htmlspecialchars($appointmentGeneral, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            <?php endif; ?>
            <section class="appointment-modal__layout">
              <div class="appointment-modal__types">
                <h3>Rodzaj wizyty</h3>
                <p class="appointment-modal__intro">Wybierz scenariusz, a my dopasujemy domyślne ustawienia do jego charakteru.</p>
                <div class="appointment-type-list">
                  <?php foreach ($appointmentTypePresets as $typeKey => $preset): ?>
                    <?php
                      $isSelectedType = $appointmentFormValues['appointment_type'] === $typeKey;
                      $presetTitle = (string) ($preset['default_title'] ?? '');
                      $presetDuration = (int) ($preset['default_duration'] ?? 60);
                      $presetStatus = (string) ($preset['status'] ?? 'scheduled');
                      $presetColor = (string) ($preset['color'] ?? '');
                      $presetConfirmationMethod = (string) ($preset['confirmation_method'] ?? '');
                      $presetConfirmationStatus = (string) ($preset['confirmation_status'] ?? '');
                    ?>
                    <label class="appointment-type-card<?= $isSelectedType ? ' appointment-type-card--active' : '' ?>">
                      <input
                        type="radio"
                        name="appointment_type"
                        value="<?= htmlspecialchars($typeKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        <?= $isSelectedType ? 'checked' : '' ?>
                        data-default-title="<?= htmlspecialchars($presetTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-default-duration="<?= $presetDuration ?>"
                        data-default-status="<?= htmlspecialchars($presetStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-default-color="<?= htmlspecialchars($presetColor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-default-confirmation-method="<?= htmlspecialchars($presetConfirmationMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-default-confirmation-status="<?= htmlspecialchars($presetConfirmationStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                      >
                      <span class="appointment-type-card__content">
                        <span class="appointment-type-card__label"><?= htmlspecialchars((string) ($preset['label'] ?? ucfirst($typeKey)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <?php if (!empty($preset['description'])): ?>
                          <span class="appointment-type-card__description"><?= htmlspecialchars((string) $preset['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <?php endif; ?>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <?php if (!empty($appointmentFormErrors['appointment_type'])): ?><small class="form-error"><?= htmlspecialchars(is_array($appointmentFormErrors['appointment_type']) ? implode(' ', array_map('strval', $appointmentFormErrors['appointment_type'])) : (string) $appointmentFormErrors['appointment_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
              </div>
              <div class="appointment-modal__details">
                <label class="form-field" for="appointment-title">
                  <span class="form-field__label">Tytuł wizyty</span>
                  <input
                    id="appointment-title"
                    type="text"
                    name="title"
                    value="<?= htmlspecialchars($appointmentFormValues['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    maxlength="191"
                    required
                    data-field="title"
                  >
                  <?php if (!empty($appointmentFormErrors['title'])): ?><small class="form-error"><?= htmlspecialchars(is_array($appointmentFormErrors['title']) ? implode(' ', array_map('strval', $appointmentFormErrors['title'])) : (string) $appointmentFormErrors['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <div class="appointment-form__datetime">
                  <label class="form-field" for="appointment-start">
                    <span class="form-field__label">Data i godzina rozpoczęcia</span>
                    <input
                      id="appointment-start"
                      type="datetime-local"
                      name="start_at"
                      value="<?= htmlspecialchars($appointmentFormValues['start_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                      required
                      data-autofocus
                    >
                    <?php if (!empty($appointmentFormErrors['start_at'])): ?><small class="form-error"><?= htmlspecialchars(is_array($appointmentFormErrors['start_at']) ? implode(' ', array_map('strval', $appointmentFormErrors['start_at'])) : (string) $appointmentFormErrors['start_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                  </label>
                  <label class="form-field" for="appointment-duration">
                    <span class="form-field__label">Czas trwania</span>
                    <select id="appointment-duration" name="duration" data-field="duration">
                      <?php foreach ($appointmentDurationOptions as $durationOption): ?>
                        <?php $durationSelected = (int) $appointmentFormValues['duration'] === (int) $durationOption; ?>
                        <option value="<?= (int) $durationOption ?>"<?= $durationSelected ? ' selected' : '' ?>><?= (int) $durationOption ?> minut</option>
                      <?php endforeach; ?>
                    </select>
                    <?php if (!empty($appointmentFormErrors['duration'])): ?><small class="form-error"><?= htmlspecialchars(is_array($appointmentFormErrors['duration']) ? implode(' ', array_map('strval', $appointmentFormErrors['duration'])) : (string) $appointmentFormErrors['duration'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                  </label>
                </div>
                <label class="form-field" for="appointment-location">
                  <span class="form-field__label">Lokalizacja</span>
                  <input
                    id="appointment-location"
                    type="text"
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
  <script>
    (function () {
      const modal = document.querySelector('[data-detail-modal="intake-attendance"]');
      if (!modal) {
        return;
      }

      const form = modal.querySelector('[data-attendance-form]');
      if (!form) {
        return;
      }

      const actionInputs = form.querySelectorAll('input[name="attendance_action"]');
      const rescheduleFields = form.querySelector('[data-reschedule-fields]');
      const cancellationFields = form.querySelector('[data-cancellation-fields]');
      const rescheduleInput = form.querySelector('input[name="reschedule_at"]');
      const cancellationInput = form.querySelector('textarea[name="cancellation_reason"]');

      const toggleFields = () => {
        const selected = form.querySelector('input[name="attendance_action"]:checked');
        const value = selected ? selected.value : '';

        if (rescheduleFields) {
          const showReschedule = value === 'rescheduled';
          rescheduleFields.hidden = !showReschedule;
          if (rescheduleInput) {
            rescheduleInput.toggleAttribute('required', showReschedule);
          }
        }

        if (cancellationFields) {
          const showCancellation = value === 'cancelled';
          cancellationFields.hidden = !showCancellation;
          if (cancellationInput) {
            cancellationInput.toggleAttribute('required', showCancellation);
          }
        }
      };

      actionInputs.forEach((input) => {
        input.addEventListener('change', toggleFields);
      });

      toggleFields();
    })();
  </script>
  <script>
    (function () {
      const modals = new Map();
      document.querySelectorAll('[data-detail-modal]').forEach((modal) => {
        const modalId = modal.getAttribute('data-detail-modal');
        if (modalId) {
          modals.set(modalId, modal);
        }
      });

      const openModal = (id) => {
        const modal = modals.get(id);
        if (!modal) {
          return;
        }

        modal.classList.add('is-visible');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('detail-modal-open');

        const focusTarget = modal.querySelector('[data-modal-close]') || modal.querySelector('button, [href], input, select, textarea');
        if (focusTarget) {
          focusTarget.focus();
        }
      };

      const closeModal = (modal) => {
        modal.classList.remove('is-visible');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('detail-modal-open');
      };

      document.querySelectorAll('[data-open-modal]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
          const targetId = trigger.getAttribute('data-open-modal');
          if (targetId) {
            openModal(targetId);
          }
        });
      });

      const handleKeyDown = (event) => {
        if (event.key !== 'Escape') {
          return;
        }

        const activeModal = document.querySelector('.detail-modal.is-visible');
        if (activeModal) {
          event.preventDefault();
          closeModal(activeModal);
        }
      };

      document.addEventListener('keydown', handleKeyDown);

      modals.forEach((modal) => {
        modal.querySelectorAll('[data-modal-close]').forEach((element) => {
          element.addEventListener('click', () => {
            closeModal(modal);
          });
        });

        modal.addEventListener('click', (event) => {
          if (event.target === modal) {
            closeModal(modal);
          }
        });
      });

      const autoOpenModal = document.querySelector('[data-detail-modal][data-open-on-load]');
      if (autoOpenModal) {
        const autoId = autoOpenModal.getAttribute('data-detail-modal');
        if (autoId) {
          setTimeout(() => {
            openModal(autoId);
          }, 100);
        }
      }
    })();
  </script>
  <script src="js/case-appointments.js"></script>
  <script src="js/modals.js"></script>
  <script src="js/checklist-quick-actions.js"></script>
  <script src="js/field-help.js"></script>
</body>
</html>