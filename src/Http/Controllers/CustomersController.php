<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Customers\SuspiciousFlagRegistry;
use App\Support\Lang\Translator;
use App\Support\Repositories\CustomerRepository;
use App\Support\View;
use PDO;

final class CustomersController
{
    public function __construct(private PDO $pdo)
    {
    }

    public function index(): string
    {
        require __DIR__ . '/../../../auth.php';

        $customerRepository = new CustomerRepository($this->pdo);

        $searchTerm = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
        $limit = 200;
        $privateCustomers = $customerRepository->listCustomers($searchTerm !== '' ? $searchTerm : null, $limit, 'private');
        $businessCustomers = $customerRepository->listCustomers($searchTerm !== '' ? $searchTerm : null, $limit, 'business');
        $privateCount = count($privateCustomers);
        $businessCount = count($businessCustomers);
        $totalCustomers = $privateCount + $businessCount;
        $locale = Translator::locale();
        $decimalSeparator = '.';
        $thousandsSeparator = ',';

        if ($locale === 'nl') {
            $decimalSeparator = ',';
            $thousandsSeparator = '.';
        } elseif ($locale === 'pl') {
            $decimalSeparator = ',';
            $thousandsSeparator = ' ';
        }

        $formatNumber = static function (int $value) use ($decimalSeparator, $thousandsSeparator): string {
            return number_format($value, 0, $decimalSeparator, $thousandsSeparator);
        };
        $createdMessage = '';
        if (isset($_GET['created']) && $_GET['created'] === '1') {
            $createdMessage = __('customers.messages.created');
        }

        $suspiciousFlagDefinitions = SuspiciousFlagRegistry::definitions();
        $suspiciousBlockDefinitions = SuspiciousFlagRegistry::blockDefinitions();

        $customerGroups = [
            [
                'type' => 'private',
                'title' => __('customers.list.private_title'),
                'description' => __('customers.list.private_description'),
                'customers' => $privateCustomers,
                'count' => $privateCount,
                'empty' => __('customers.list.empty_private'),
            ],
            [
                'type' => 'business',
                'title' => __('customers.list.business_title'),
                'description' => __('customers.list.business_description'),
                'customers' => $businessCustomers,
                'count' => $businessCount,
                'empty' => __('customers.list.empty_business'),
            ],
        ];

        $defaultGroup = $privateCount > 0 ? 'private' : ($businessCount > 0 ? 'business' : 'private');

        $content = View::render('customers/index.php', [
            'searchTerm' => $searchTerm,
            'createdMessage' => $createdMessage,
            'customerGroups' => $customerGroups,
            'defaultGroup' => $defaultGroup,
            'formatNumber' => $formatNumber,
            'totalCustomers' => $totalCustomers,
            'privateCount' => $privateCount,
            'businessCount' => $businessCount,
            'limit' => $limit,
            'suspiciousFlagDefinitions' => $suspiciousFlagDefinitions,
            'suspiciousBlockDefinitions' => $suspiciousBlockDefinitions,
        ]);

        return View::render('layout/app.php', [
            'title' => __('customers.meta.title'),
            'stylesheets' => ['css/customers.css'],
            'currentNav' => 'customers',
            'content' => $content,
        ]);
    }
}