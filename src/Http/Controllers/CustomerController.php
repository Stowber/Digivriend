<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
use App\Support\Customers\SuspiciousFlagRegistry;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerCompanyRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Documents\DocumentRepository;
use App\Support\View;
use App\Validation\InputValidator;
use PDO;
use Throwable;

final class CustomerController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function show(): string
    {
        require __DIR__ . '/../../../auth.php';

        $customerRepository = new CustomerRepository($this->pdo);
        $customerCompanyRepository = new CustomerCompanyRepository($this->pdo);
        $caseRepository = new CaseRepository($this->pdo);
        $documentRepository = new DocumentRepository($this->pdo);

        $customerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$customerId) {
            Response::redirect('customers.php');
        }

        $customer = $customerRepository->findById((int) $customerId);
        if ($customer === null) {
            Response::redirect('customers.php');
        }

        $csrfToken = Csrf::token();
        $profileErrors = [];
        $companyErrors = [];
        $generalError = '';
        $companyGeneralError = '';
        $companyModalShouldOpen = false;
        $personalModalShouldOpen = false;
        $successMessage = '';
        $suspiciousErrors = [];
        $suspiciousGeneralError = '';
        $suspiciousModalShouldOpen = false;
        $suspiciousFormData = [
            'suspicious_reason' => (string) ($customer['suspicious_reason'] ?? ''),
            'suspicious_flags' => SuspiciousFlagRegistry::decodeFlags($customer['suspicious_flags'] ?? null),
        ];
        $created = isset($_GET['created']) && $_GET['created'] === '1';
        if ($created) {
            $successMessage = __('customers.messages.created');
        }

        $customerCode = isset($customer['customer_code']) && trim((string) $customer['customer_code']) !== ''
            ? (string) $customer['customer_code']
            : __('customers.list.table.no_code');

        $fullName = (string) ($customer['full_name'] ?? '');
        $initials = 'DV';
        $trimmedName = trim($fullName);
        if ($trimmedName !== '') {
            $nameParts = preg_split('/\s+/u', $trimmedName) ?: [];
            $nameParts = array_values(array_filter($nameParts, static fn ($part) => $part !== ''));

            if ($nameParts !== []) {
                $firstPart = (string) ($nameParts[0] ?? '');
                $lastPart = (string) ($nameParts[count($nameParts) - 1] ?? '');

                $firstInitial = $firstPart !== '' ? mb_substr($firstPart, 0, 1, 'UTF-8') : '';
                $secondInitial = '';

                if (count($nameParts) > 1 && $lastPart !== '') {
                    $secondInitial = mb_substr($lastPart, 0, 1, 'UTF-8');
                } elseif (mb_strlen($firstPart, 'UTF-8') > 1) {
                    $secondInitial = mb_substr($firstPart, 1, 1, 'UTF-8');
                }

                $initialsCandidate = trim($firstInitial . $secondInitial);
                if ($initialsCandidate !== '') {
                    $initials = mb_strtoupper($initialsCandidate, 'UTF-8');
                }
            }
        }

        $company = $customerCompanyRepository->findByCustomerId((int) $customer['id']);

        $companyFormData = [
            'company_name' => (string) ($company['name'] ?? ''),
            'company_kvk' => (string) ($company['kvk'] ?? ''),
            'company_btw' => (string) ($company['btw'] ?? ''),
            'company_contact_person' => (string) ($company['contact_person'] ?? $fullName),
            'company_email' => (string) ($company['email'] ?? ($customer['email'] ?? '')),
            'company_phone' => (string) ($company['phone'] ?? ($customer['phone'] ?? '')),
            'company_address' => (string) ($company['address'] ?? ''),
            'company_postal_code' => (string) ($company['postal_code'] ?? ''),
            'company_city' => (string) ($company['city'] ?? ''),
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
                $generalError = __('messages.session_expired');
            } else {
                $formType = isset($_POST['form_type']) ? (string) $_POST['form_type'] : 'profile';

                if ($formType === 'company') {
                    try {
                        $companyFormData['company_name'] = InputValidator::requireString($_POST, 'company_name', 191);
                        $companyFormData['company_kvk'] = InputValidator::requireString($_POST, 'company_kvk', 32);
                        $companyFormData['company_btw'] = InputValidator::optionalString($_POST, 'company_btw', 32);
                        $companyFormData['company_contact_person'] = InputValidator::requireString($_POST, 'company_contact_person', 191);
                        $companyFormData['company_email'] = InputValidator::optionalEmail($_POST, 'company_email', 191);
                        $companyFormData['company_phone'] = InputValidator::optionalPhone($_POST, 'company_phone', 32);
                        $companyFormData['company_address'] = InputValidator::optionalString($_POST, 'company_address', 255);
                        $companyFormData['company_postal_code'] = InputValidator::optionalString($_POST, 'company_postal_code', 16);
                        $companyFormData['company_city'] = InputValidator::optionalString($_POST, 'company_city', 120);

                        if ($companyFormData['company_address'] === '' && isset($customer['address'])) {
                            $companyFormData['company_address'] = (string) $customer['address'];
                        }

                        if ($companyFormData['company_postal_code'] === '' && isset($customer['postal_code'])) {
                            $companyFormData['company_postal_code'] = (string) $customer['postal_code'];
                        }

                        if ($companyFormData['company_city'] === '' && isset($customer['city'])) {
                            $companyFormData['company_city'] = (string) $customer['city'];
                        }

                        $company = $customerCompanyRepository->upsert(
                            (int) $customer['id'],
                            $companyFormData['company_name'],
                            $companyFormData['company_kvk'],
                            $companyFormData['company_btw'] !== '' ? $companyFormData['company_btw'] : null,
                            $companyFormData['company_contact_person'],
                            $companyFormData['company_email'] !== '' ? $companyFormData['company_email'] : null,
                            $companyFormData['company_phone'] !== '' ? $companyFormData['company_phone'] : null,
                            $companyFormData['company_address'] !== '' ? $companyFormData['company_address'] : null,
                            $companyFormData['company_postal_code'] !== '' ? $companyFormData['company_postal_code'] : null,
                            $companyFormData['company_city'] !== '' ? $companyFormData['company_city'] : null
                        );

                        $customerRepository->updateType((int) $customer['id'], 'business');
                        $customer = $customerRepository->findById((int) $customer['id']);

                        $successMessage = __('customers.messages.company_saved');
                    } catch (ValidationException $exception) {
                        $companyErrors = $exception->errors();
                        $companyModalShouldOpen = true;
                    } catch (Throwable $exception) {
                        $companyGeneralError = __('customers.messages.company_failed');
                        $companyModalShouldOpen = true;
                    }
                } elseif ($formType === 'company_delete') {
                    try {
                        $deleted = $customerCompanyRepository->deleteByCustomerId((int) $customer['id']);

                        if ($deleted) {
                            $company = null;
                            $companyFormData = [
                                'company_name' => '',
                                'company_kvk' => '',
                                'company_btw' => '',
                                'company_contact_person' => $fullName,
                                'company_email' => (string) ($customer['email'] ?? ''),
                                'company_phone' => (string) ($customer['phone'] ?? ''),
                                'company_address' => '',
                                'company_postal_code' => '',
                                'company_city' => '',
                            ];

                            $customerRepository->updateType((int) $customer['id'], 'private');
                            $customer = $customerRepository->findById((int) $customer['id']);

                            $successMessage = __('customers.messages.company_deleted');
                        } else {
                            $companyGeneralError = __('customers.messages.company_delete_failed');
                        }
                    } catch (Throwable $exception) {
                        $companyGeneralError = __('customers.messages.company_delete_failed');
                    }
                } elseif ($formType === 'suspicious_mark') {
                    try {
                        $flagsInput = $_POST['suspicious_flags'] ?? [];
                        if (!is_array($flagsInput)) {
                            throw new ValidationException(['suspicious_flags' => __('customers.profile.suspicious.flags_required')]);
                        }

                        $selectedFlags = SuspiciousFlagRegistry::normalizeFlags(array_map('strval', $flagsInput));
                        if ($selectedFlags === []) {
                            throw new ValidationException(['suspicious_flags' => __('customers.profile.suspicious.flags_required')]);
                        }
                        $reason = InputValidator::requireString($_POST, 'suspicious_reason', 255);

                        $customerRepository->markSuspicious((int) $customer['id'], $selectedFlags, $reason);
                        $customer = $customerRepository->findById((int) $customer['id']);
                        $company = $customerCompanyRepository->findByCustomerId((int) $customer['id']);

                        $savedSuspiciousFlags = SuspiciousFlagRegistry::decodeFlags($customer['suspicious_flags'] ?? null);
                        $suspiciousFormData['suspicious_reason'] = (string) ($customer['suspicious_reason'] ?? '');
                        $suspiciousFormData['suspicious_flags'] = $savedSuspiciousFlags;
                        $successMessage = __('customers.messages.suspicious_marked');
                    } catch (ValidationException $exception) {
                        $suspiciousErrors = $exception->errors();
                        $suspiciousFormData['suspicious_reason'] = (string) ($_POST['suspicious_reason'] ?? '');
                        $suspiciousFormData['suspicious_flags'] = SuspiciousFlagRegistry::normalizeFlags(
                            is_array($_POST['suspicious_flags'] ?? null)
                                ? array_map('strval', (array) $_POST['suspicious_flags'])
                                : []
                        );
                        $suspiciousModalShouldOpen = true;
                    } catch (Throwable $exception) {
                        $suspiciousGeneralError = __('customers.messages.suspicious_failed');
                        $suspiciousFormData['suspicious_reason'] = (string) ($_POST['suspicious_reason'] ?? '');
                        $suspiciousFormData['suspicious_flags'] = SuspiciousFlagRegistry::normalizeFlags(
                            is_array($_POST['suspicious_flags'] ?? null)
                                ? array_map('strval', (array) $_POST['suspicious_flags'])
                                : []
                        );
                        $suspiciousModalShouldOpen = true;
                    }
                } elseif ($formType === 'suspicious_clear') {
                    try {
                        $customerRepository->clearSuspicious((int) $customer['id']);
                        $customer = $customerRepository->findById((int) $customer['id']);
                        $company = $customerCompanyRepository->findByCustomerId((int) $customer['id']);

                        $suspiciousFormData['suspicious_reason'] = '';
                        $suspiciousFormData['suspicious_flags'] = [];
                        $successMessage = __('customers.messages.suspicious_cleared');
                    } catch (Throwable $exception) {
                        $suspiciousGeneralError = __('customers.messages.suspicious_clear_failed');
                    }
                } else {
                    try {
                        $fullName = InputValidator::requireString($_POST, 'full_name', 191);
                        $email = InputValidator::requireEmail($_POST, 'email', 191);
                        $phone = InputValidator::requirePhone($_POST, 'phone', 32);
                        $address = InputValidator::requireString($_POST, 'address', 255);
                        $postalCode = InputValidator::requireString($_POST, 'postal_code', 16);
                        $city = InputValidator::requireString($_POST, 'city', 120);

                        $customerRepository->updateProfile(
                            (int) $customer['id'],
                            $fullName,
                            $email,
                            $phone,
                            $address,
                            $postalCode,
                            $city
                        );

                        $customer = $customerRepository->findById((int) $customer['id']);
                        $company = $customerCompanyRepository->findByCustomerId((int) $customer['id']);

                        $successMessage = __('customers.messages.updated');
                    } catch (ValidationException $exception) {
                        $profileErrors = $exception->errors();
                        $personalModalShouldOpen = true;
                    } catch (Throwable $exception) {
                        $generalError = __('customers.messages.update_failed');
                        $personalModalShouldOpen = true;
                    }
                }
            }
        }

        if ($company !== null) {
            $companyFormData = [
                'company_name' => (string) ($company['name'] ?? ''),
                'company_kvk' => (string) ($company['kvk'] ?? ''),
                'company_btw' => (string) ($company['btw'] ?? ''),
                'company_contact_person' => (string) ($company['contact_person'] ?? $fullName),
                'company_email' => (string) ($company['email'] ?? ''),
                'company_phone' => (string) ($company['phone'] ?? ''),
                'company_address' => (string) ($company['address'] ?? ''),
                'company_postal_code' => (string) ($company['postal_code'] ?? ''),
                'company_city' => (string) ($company['city'] ?? ''),
            ];
        } elseif (!isset($companyFormData['company_contact_person']) || $companyFormData['company_contact_person'] === '') {
            $companyFormData['company_contact_person'] = $fullName;
        }

        $companyAddressDefaults = [
            'address' => (string) ($customer['address'] ?? ''),
            'postal_code' => (string) ($customer['postal_code'] ?? ''),
            'city' => (string) ($customer['city'] ?? ''),
        ];

        $cases = $caseRepository->forCustomer((int) $customer['id'], 25);
        $documents = $documentRepository->forCustomer((int) $customer['id'], 25);

        $isSuspicious = isset($customer['is_suspicious']) && (int) $customer['is_suspicious'] === 1;
        $currentSuspiciousReason = $isSuspicious ? (string) ($customer['suspicious_reason'] ?? '') : '';
        $currentSuspiciousFlags = $isSuspicious ? SuspiciousFlagRegistry::decodeFlags($customer['suspicious_flags'] ?? null) : [];

        if (!$suspiciousModalShouldOpen) {
            $suspiciousFormData['suspicious_flags'] = $currentSuspiciousFlags;
        }

        $suspiciousFlagDefinitions = SuspiciousFlagRegistry::definitions();
        $suspiciousBlockDefinitions = SuspiciousFlagRegistry::blockDefinitions();
        $activeSuspiciousBlocks = SuspiciousFlagRegistry::blocksForFlags($currentSuspiciousFlags);
        $intakeBlocked = in_array(SuspiciousFlagRegistry::BLOCK_INTAKES, $activeSuspiciousBlocks, true);

        if ($isSuspicious && $suspiciousFormData['suspicious_reason'] === '') {
            $suspiciousFormData['suspicious_reason'] = $currentSuspiciousReason;
        }

        $content = View::render('customers/show.php', [
            'customer' => $customer,
            'customerCode' => $customerCode,
            'fullName' => $fullName,
            'initials' => $initials,
            'csrfToken' => $csrfToken,
            'profileErrors' => $profileErrors,
            'companyErrors' => $companyErrors,
            'generalError' => $generalError,
            'companyGeneralError' => $companyGeneralError,
            'companyModalShouldOpen' => $companyModalShouldOpen,
            'personalModalShouldOpen' => $personalModalShouldOpen,
            'successMessage' => $successMessage,
            'suspiciousErrors' => $suspiciousErrors,
            'suspiciousGeneralError' => $suspiciousGeneralError,
            'suspiciousModalShouldOpen' => $suspiciousModalShouldOpen,
            'suspiciousFormData' => $suspiciousFormData,
            'companyFormData' => $companyFormData,
            'companyAddressDefaults' => $companyAddressDefaults,
            'cases' => $cases,
            'documents' => $documents,
            'isSuspicious' => $isSuspicious,
            'currentSuspiciousReason' => $currentSuspiciousReason,
            'currentSuspiciousFlags' => $currentSuspiciousFlags,
            'suspiciousFlagDefinitions' => $suspiciousFlagDefinitions,
            'suspiciousBlockDefinitions' => $suspiciousBlockDefinitions,
            'activeSuspiciousBlocks' => $activeSuspiciousBlocks,
            'intakeBlocked' => $intakeBlocked,
            'company' => $company,
        ]);

        return View::render('layout/app.php', [
            'title' => __('customers.profile.meta.title', ['name' => $fullName]),
            'stylesheets' => ['css/customers.css'],
            'currentNav' => 'customers',
            'content' => $content,
        ]);
    }
}