<?php
/**
 * Shown when a slug does not resolve to a live funnel — unknown, archived or
 * deleted. Deliberately does not distinguish between those cases.
 *
 * @var array<string,mixed> $public global public settings
 */

use Lumera\Support\Str;

$company = $public['company_name'] ?? '';
$e = static fn ($v) => Str::e((string) $v);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Not found</title>
<link rel="stylesheet" href="/assets/css/public.css?v=2">
</head>
<body class="funnel-body">
<main class="funnel">
    <section class="funnel__stage">
        <div class="state">
            <h1 class="state__title">This form is not available</h1>
            <p class="state__text">
                The link may have expired or been moved. Please check the address and try again.
            </p>
        </div>
    </section>
    <?php if ($company !== ''): ?>
    <footer class="funnel__footer">
        <p class="funnel__legal"><?= $e($company) ?></p>
    </footer>
    <?php endif; ?>
</main>
</body>
</html>
