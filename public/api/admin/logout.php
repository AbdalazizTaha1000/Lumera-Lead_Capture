<?php

declare(strict_types=1);

/** POST /api/admin/logout.php */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\AdminEndpoint;
use Lumera\Core\Auth;
use Lumera\Core\Response;

[$admin, $body] = AdminEndpoint::write(dirname(__DIR__, 3));

Auth::logout();

Response::success(['redirect' => '/admin/login.php']);
