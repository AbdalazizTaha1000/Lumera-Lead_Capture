<?php
/**
 * Shown when a slug does not resolve to a live funnel — unknown, archived or
 * deleted. Deliberately does not distinguish between those cases.
 *
 * @var array<string,mixed> $public global public settings
 */

use Lumera\Support\Str;

$company = $public['company_name'] ?? '';
$tagline = trim((string) ($public['site_tagline'] ?? ''));
$e = static fn ($v) => Str::e((string) $v);

$pageTitle = $company !== ''
    ? ($tagline !== '' ? $company . ' — ' . $tagline : $company)
    : 'Not found';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($pageTitle) ?></title>
<?php if ($tagline !== ''): ?>
<meta name="description" content="<?= $e($tagline) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/public.css?v=3">
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
