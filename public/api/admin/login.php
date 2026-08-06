<?php

declare(strict_types=1);

/**
 * POST /api/admin/login.php
 *
 * Rate limited by IP and by email, with a deliberately generic failure message.
 * There is no registration endpoint anywhere in this application.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Auth;
use Lumera\Core\Csrf;
use Lumera\Core\Response;
use Lumera\Support\Request;

App::bootApi(dirname(__DIR__, 3));

if (Request::method() !== 'POST') {
    Response::error('Method not allowed.', 405);
}

if (!Request::isJson()) {
    Response::error('Unsupported content type.', 415);
}

[$body, $error] = Request::jsonBody();

if ($body === null) {
    Response::error($error ?? 'Malformed request.', 400);
}

if (!Csrf::validate(Csrf::fromRequest($body), 'admin_login')) {
    Response::error('Your session has expired. Please reload the page.', 419);
}

$email    = is_string($body['email'] ?? null) ? $body['email'] : '';
$password = is_string($body['password'] ?? null) ? $body['password'] : '';

if ($email === '' || $password === '') {
    Response::error('Invalid email or password.', 401);
}

$result = Auth::attempt($email, $password);

if (!$result['ok']) {
    Response::error(
        $result['error'] ?? 'Invalid email or password.',
        isset($result['retry_after']) ? 429 : 401,
        isset($result['retry_after']) ? ['retry_after' => $result['retry_after']] : []
    );
}

Response::success([
    'user' => [
        'id'    => (int) $result['user']['id'],
        'email' => $result['user']['email'],
        'name'  => $result['user']['name'],
    ],
    'csrf_token' => Csrf::token('admin'),
    'redirect'   => '/admin/',
]);
