<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Repositories\AppointmentRepository;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\View;
use DateTimeImmutable;
use PDO;

final class DashboardController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function index(): string
    {
        require __DIR__ . '/../../../auth.php';

        $caseRepository = new CaseRepository($this->pdo);
        $appointmentRepository = new AppointmentRepository($this->pdo);
        $customerRepository = new CustomerRepository($this->pdo);

        $now = new DateTimeImmutable('now');
        $todayString = $now->format('Y-m-d');
        $nowString = $now->format('Y-m-d H:i:s');

        $upcomingAppointmentsRaw = $appointmentRepository->upcoming($nowString, null, null, null, 12);
        $upcomingAppointments = array_values(array_filter($upcomingAppointmentsRaw, static function (array $appointment): bool {
            $status = strtolower((string) ($appointment['status'] ?? ''));

            return !in_array($status, ['cancelled', 'completed'], true);
        }));
        $upcomingAppointments = array_slice($upcomingAppointments, 0, 5);

        $appointmentCustomerLookup = [];
        if ($upcomingAppointments !== []) {
            $customerIds = array_values(array_filter(array_unique(array_map(
                static function (array $appointment): int {
                    $customerId = $appointment['customer_id'] ?? null;

                    return is_numeric($customerId) ? (int) $customerId : 0;
                },
                $upcomingAppointments
            ))));

            if ($customerIds !== []) {
                $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
                $statement = $this->pdo->prepare('SELECT id, full_name, email, phone FROM customers WHERE id IN (' . $placeholders . ')');

                foreach ($customerIds as $index => $customerId) {
                    $statement->bindValue($index + 1, $customerId, PDO::PARAM_INT);
                }

                $statement->execute();
                foreach ($statement->fetchAll() ?: [] as $customerRow) {
                    $customerId = (int) ($customerRow['id'] ?? 0);
                    if ($customerId > 0) {
                        $appointmentCustomerLookup[$customerId] = $customerRow;
                    }
                }
            }
        }

        $typeFilter = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'all';
        $caseType = $typeFilter === 'all' ? null : $typeFilter;
        $recentCases = $caseRepository->recentCaseOverview(6, $caseType);
        $recentCustomers = $customerRepository->listCustomers(null, 6);

        $openCases = (int) ($this->pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('opgehaald', 'gesloten')")?->fetchColumn() ?: 0);
        $totalCustomers = (int) ($this->pdo->query('SELECT COUNT(*) FROM customers')?->fetchColumn() ?: 0);
        $upcomingAppointmentsCount = count($upcomingAppointments);
        $appointmentsToday = count(array_filter($upcomingAppointments, static function (array $appointment) use ($todayString): bool {
            $startAt = $appointment['start_at'] ?? null;
            if (!is_string($startAt) || trim($startAt) === '') {
                return false;
            }

            $startDate = date_create_immutable($startAt);

            return $startDate instanceof DateTimeImmutable && $startDate->format('Y-m-d') === $todayString;
        }));

        $caseTypes = $this->pdo->query('SELECT DISTINCT type FROM cases ORDER BY type')?->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $caseTypeLabel = $caseType === null
            ? __('dashboard.minimal.cases.filter_all')
            : $this->translateDashboardEnum('dashboard.case_types.', (string) $caseType);

        $formatDate = static function (?string $value, string $format = 'd-m-Y H:i'): ?string {
            if (!is_string($value) || trim($value) === '') {
                return null;
            }

            $date = date_create_immutable($value);

            return $date instanceof DateTimeImmutable ? $date->format($format) : null;
        };

        $formatTime = static function (?string $value, string $format = 'H:i'): ?string {
            if (!is_string($value) || trim($value) === '') {
                return null;
            }

            $date = date_create_immutable($value);

            return $date instanceof DateTimeImmutable ? $date->format($format) : null;
        };

        $content = View::render('dashboard/index.php', [
            'appointmentCustomerLookup' => $appointmentCustomerLookup,
            'appointmentsToday' => $appointmentsToday,
            'caseType' => $caseType,
            'caseTypeLabel' => $caseTypeLabel,
            'caseTypes' => $caseTypes,
            'formatDate' => $formatDate,
            'formatTime' => $formatTime,
            'openCases' => $openCases,
            'recentCases' => $recentCases,
            'recentCustomers' => $recentCustomers,
            'totalCustomers' => $totalCustomers,
            'typeFilter' => $typeFilter,
            'upcomingAppointments' => $upcomingAppointments,
            'upcomingAppointmentsCount' => $upcomingAppointmentsCount,
            'translateDashboardEnum' => fn (string $prefix, string $key): string => $this->translateDashboardEnum($prefix, $key),
        ]);

        return View::render('layout/app.php', [
            'title' => __('dashboard.meta.title'),
            'stylesheets' => ['css/dashboard.css'],
            'currentNav' => 'dashboard',
            'content' => $content,
        ]);
    }

    private function translateDashboardEnum(string $prefix, string $key): string
    {
        $normalizedKey = strtolower(str_replace(' ', '_', $key));
        $translationKey = $prefix . $normalizedKey;
        $translated = __($translationKey);

        if ($translated === $translationKey) {
            return ucfirst(str_replace('_', ' ', $normalizedKey));
        }

        return $translated;
    }
}