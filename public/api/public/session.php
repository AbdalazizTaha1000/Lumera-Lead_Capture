<?php

declare(strict_types=1);

/**
 * GET /api/public/session.php
 *
 * Starts the visitor session and hands out the two tokens the funnel needs.
 * Returns no configuration, no secrets and no admin data.
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Csrf;
use Lumera\Core\Response;
use Lumera\Core\SubmissionToken;
use Lumera\Support\Request;

App::bootApi(dirname(__DIR__, 3));

if (Request::method() !== 'GET') {
    Response::error('Method not allowed.', 405);
}

Response::success([
    'csrf_token'       => Csrf::token('public'),
    'submission_token' => SubmissionToken::issue(),
    'server_time'      => time(),
]);
