<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;

if (!Auth::check()) {
    $currentUrl = $_SERVER['REQUEST_URI'] ?? 'index.php';
    Response::redirect('login.php?redirect=' . urlencode($currentUrl));
}