<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;

require __DIR__ . '/bootstrap.php';

Auth::logout();

Response::redirect('login.php?redirect=index.php');