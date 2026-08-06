<?php

declare(strict_types=1);

/** Admin login page. There is no registration counterpart by design. */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Lumera\Core\App;
use Lumera\Core\Auth;
use Lumera\Core\Csrf;
use Lumera\Core\Response;
use Lumera\Repositories\SettingsRepository;
use Lumera\Support\Str;

App::boot(dirname(__DIR__, 2));
Response::securityHeaders();

if (Auth::check()) {
    Response::redirect('/admin/');
}

$csrf    = Csrf::token('admin_login');
$company = (new SettingsRepository())->get('company_name', 'Lead Capture');
$e       = static fn ($v) => Str::e((string) $v);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in — <?= $e($company) ?></title>
<link rel="stylesheet" href="/assets/css/admin.css?v=1">
</head>
<body class="auth-body">

<main class="auth">
    <div class="auth__card">
        <div class="auth__brand">
            <span class="auth__mark">L</span>
            <div>
                <p class="auth__company"><?= $e($company) ?></p>
                <p class="auth__subtitle">Lead capture dashboard</p>
            </div>
        </div>

        <form id="login-form" class="auth__form" autocomplete="on">
            <input type="hidden" id="csrf" value="<?= $e($csrf) ?>">

            <div class="form-group">
                <label class="form-label" for="email">Email address</label>
                <input class="form-control" type="email" id="email" name="email" required autocomplete="username" autofocus>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <p class="alert alert--danger" id="login-error" hidden role="alert"></p>

            <button class="btn btn--primary btn--block" type="submit" id="login-submit">Sign in</button>
        </form>

        <p class="auth__note">Access is restricted to authorised administrators.</p>
    </div>
</main>

<script>
(function () {
    var form = document.getElementById('login-form');
    var error = document.getElementById('login-error');
    var submit = document.getElementById('login-submit');

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        error.hidden = true;
        submit.disabled = true;
        submit.textContent = 'Signing in…';

        fetch('/api/admin/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.getElementById('csrf').value },
            credentials: 'same-origin',
            body: JSON.stringify({
                email: document.getElementById('email').value,
                password: document.getElementById('password').value,
                csrf_token: document.getElementById('csrf').value
            })
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (data && data.ok) {
                window.location.href = data.redirect || '/admin/';
                return;
            }

            error.textContent = (data && data.error) || 'Invalid email or password.';
            error.hidden = false;
            submit.disabled = false;
            submit.textContent = 'Sign in';
        }).catch(function () {
            error.textContent = 'Unable to reach the server. Please try again.';
            error.hidden = false;
            submit.disabled = false;
            submit.textContent = 'Sign in';
        });
    });
})();
</script>
</body>
</html>
