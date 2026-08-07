<?php
/**
 * Public funnel shell.
 *
 * @var array<string,mixed>|null $funnel
 * @var string $slug
 * @var string $name
 * @var string $companyName
 * @var string $tagline
 * @var string $logo
 * @var string $favicon
 * @var string $backgroundImage
 * @var array<string,string> $theme
 * @var list<string> $languages
 * @var string $defaultLanguage
 * @var string $csrfToken
 * @var string $submissionToken
 * @var bool $preview
 */

use Lumera\Support\Str;

$e   = static fn ($v) => Str::e((string) $v);
$dir = $defaultLanguage === 'ar' ? 'rtl' : 'ltr';

// Page metadata: "{Company Name} — {Tagline}", or just the company name when no
// tagline is configured. The company name resolves from the funnel first and
// only falls back to the global setting, so each funnel brands its own page.
$tagline   = trim((string) ($tagline ?? ''));
$pageTitle = $tagline !== '' ? $companyName . ' — ' . $tagline : $companyName;
?>
<!doctype html>
<html lang="<?= $e($defaultLanguage) ?>" dir="<?= $e($dir) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($pageTitle) ?></title>
<?php if ($tagline !== ''): ?>
<meta name="description" content="<?= $e($tagline) ?>">
<?php endif; ?>
<meta property="og:title" content="<?= $e($pageTitle) ?>">
<?php if ($tagline !== ''): ?>
<meta property="og:description" content="<?= $e($tagline) ?>">
<?php endif; ?>
<?php if ($logo !== ''): ?>
<meta property="og:image" content="<?= $e($logo) ?>">
<?php endif; ?>
<?php if (($favicon ?? '') !== ''): ?>
<link rel="icon" href="<?= $e($favicon) ?>">
<?php else: ?>
<link rel="icon" href="/favicon.ico" sizes="any">
<?php endif; ?>
<meta name="theme-color" content="<?= $e($theme['primary']) ?>">
<link rel="stylesheet" href="/assets/css/public.css?v=3">
<style>
:root {
    --brand-primary: <?= $e($theme['primary']) ?>;
    --brand-secondary: <?= $e($theme['accent']) ?>;
    --brand-accent: <?= $e($theme['accent']) ?>;
    --brand-background: <?= $e($theme['background']) ?>;
    <?php if ($backgroundImage !== ''): ?>
    --brand-background-image: url("<?= $e($backgroundImage) ?>");
    <?php endif; ?>
}
</style>
</head>
<body class="funnel-body<?= $backgroundImage !== '' ? ' has-bg-image' : '' ?><?= $preview ? ' is-preview' : '' ?>">

<?php if ($preview): ?>
<div class="preview-banner" role="status">
    <span class="preview-banner__dot" aria-hidden="true"></span>
    <span data-i18n="preview_badge">Preview mode — draft configuration, no lead will be created</span>
</div>
<?php endif; ?>

<main class="funnel" id="funnel-root"
      data-slug="<?= $e($slug) ?>"
      data-preview="<?= $preview ? '1' : '0' ?>"
      data-default-language="<?= $e($defaultLanguage) ?>"
      data-languages="<?= $e(implode(',', $languages)) ?>"
      data-csrf="<?= $e($csrfToken) ?>"
      data-submission-token="<?= $e($submissionToken) ?>">

    <header class="funnel__header">
        <div class="funnel__brand">
            <?php if ($logo !== ''): ?>
                <img class="funnel__logo" src="<?= $e($logo) ?>" alt="<?= $e($companyName) ?>">
            <?php else: ?>
                <span class="funnel__wordmark"><?= $e($companyName) ?></span>
            <?php endif; ?>
        </div>

        <?php if (count($languages) > 1): ?>
        <div class="lang-switch" role="group" aria-label="Language">
            <?php foreach ($languages as $language): ?>
                <button type="button" class="lang-switch__btn" data-language="<?= $e($language) ?>"
                        aria-pressed="<?= $language === $defaultLanguage ? 'true' : 'false' ?>">
                    <?= $language === 'ar' ? 'العربية' : strtoupper($e($language)) ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </header>

    <!-- Progress is computed from the number of active published steps, never a fixed value. -->
    <div class="funnel__progress" id="funnel-progress" hidden>
        <div class="progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
            <div class="progress-bar__fill" id="progress-fill"></div>
        </div>
        <p class="funnel__counter" id="funnel-counter"></p>
    </div>

    <section class="funnel__stage" id="funnel-stage" aria-live="polite">
        <div class="state state--loading" id="state-loading">
            <span class="spinner" aria-hidden="true"></span>
            <p data-i18n="loading">Loading…</p>
        </div>

        <div class="state state--error" id="state-error" hidden>
            <h1 class="state__title" data-i18n="error_title">We could not load this form</h1>
            <p class="state__text" id="error-text" data-i18n="error_text">Please refresh the page and try again.</p>
            <button type="button" class="btn btn--primary" id="retry-button" data-i18n="retry">Try again</button>
        </div>

        <form class="step-form" id="step-form" novalidate hidden>
            <!-- Populated entirely from the published configuration. -->
            <div class="step" id="step-container"></div>

            <p class="step__error" id="step-error" role="alert" hidden></p>

            <!-- Honeypot: hidden from humans, tempting to bots. -->
            <div class="hp-field" aria-hidden="true">
                <label for="company_website">Company website</label>
                <input type="text" id="company_website" name="company_website" tabindex="-1" autocomplete="off">
            </div>

            <div class="step__actions">
                <button type="button" class="btn btn--ghost" id="back-button" hidden data-i18n="back">Back</button>
                <button type="submit" class="btn btn--primary" id="next-button" data-i18n="next">Next</button>
            </div>
        </form>

        <div class="state state--success" id="state-success" hidden>
            <div class="state__check" aria-hidden="true">
                <svg viewBox="0 0 52 52" width="52" height="52"><circle cx="26" cy="26" r="24" fill="none" stroke="currentColor" stroke-width="2"/><path fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" d="M15 27l8 8 15-16"/></svg>
            </div>
            <h1 class="state__title" id="success-title"></h1>
            <p class="state__text" id="success-message"></p>
            <div class="state__actions">
                <a class="btn btn--whatsapp" id="whatsapp-cta" hidden rel="noopener noreferrer" target="_blank"></a>
                <a class="btn btn--primary btn--centered" id="success-cta" hidden></a>
            </div>
            <p class="state__redirect" id="redirect-note" hidden></p>
        </div>
    </section>

    <footer class="funnel__footer">
        <p class="funnel__legal">
            <span id="footer-company"><?= $e($companyName) ?></span>
            <a id="privacy-link" href="#" hidden data-i18n="privacy">Privacy Policy</a>
        </p>
    </footer>
</main>

<script src="/assets/js/public-funnel.js?v=2" defer></script>
</body>
</html>
