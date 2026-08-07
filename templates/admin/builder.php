<?php
/**
 * Funnel Builder shell.
 *
 * Deliberately thin: everything below the top bar is rendered by builder.js
 * from the existing admin APIs, so there is one source of truth for state.
 *
 * @var array<string,mixed> $funnel
 * @var array<string,mixed> $admin
 * @var string $csrf
 * @var string $company
 * @var string $appUrl
 * @var array<string,string> $stepTypes
 */

use Lumera\Support\Str;

$e = static fn ($v) => Str::e((string) $v);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($funnel['name']) ?> — Builder</title>
<link rel="stylesheet" href="/assets/css/builder.css?v=1">
</head>
<body class="builder-body"
      data-csrf="<?= $e($csrf) ?>"
      data-funnel-id="<?= (int) $funnel['id'] ?>"
      data-app-url="<?= $e($appUrl) ?>">

<div class="shell">

    <!-- ============================================================ top bar -->
    <header class="topbar">
        <a class="topbar__back" href="/admin/#/funnels" title="Back to funnels" aria-label="Back to funnels">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <path d="M10 12.5 5.5 8 10 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>

        <div class="topbar__brand">
            <span id="brand-mark"></span>
            <div class="topbar__titles">
                <!-- Renamed in place; there is no separate "name" form. -->
                <input class="topbar__title" id="funnel-title" value="<?= $e($funnel['name']) ?>"
                       aria-label="Funnel name" spellcheck="false" autocomplete="off">
            </div>
        </div>

        <span id="status-pill"></span>

        <div class="topbar__spacer"></div>

        <div class="urlbar" id="urlbar"></div>

        <span class="save-state" id="save-state" data-state="saved" role="status" aria-live="polite"></span>

        <span id="publish-slot"></span>
    </header>

    <!-- =============================================================== body -->
    <div class="body">
        <nav class="rail" id="rail" aria-label="Builder sections"></nav>

        <main class="canvas" id="canvas">
            <div class="canvas__inner" id="canvas-inner">
                <div class="boot">
                    <span class="boot__spinner"></span>
                    <p>Loading your funnel…</p>
                </div>
            </div>
        </main>

        <aside class="preview" id="preview" aria-label="Live preview">
            <div class="preview__bar">
                <div class="device-group" id="device-group"></div>
                <div class="topbar__spacer"></div>
                <button class="btn btn--quiet btn--sm btn--icon" id="preview-reload" title="Reload preview" aria-label="Reload preview">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                        <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9M13.5 2v3h-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
            <div class="preview__stage" id="preview-stage"></div>
        </aside>
    </div>
</div>

<div class="scrim" id="scrim" hidden>
    <div class="modal" id="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal__head">
            <h2 class="modal__title" id="modal-title"></h2>
            <p class="modal__sub" id="modal-sub"></p>
        </div>
        <div class="modal__body" id="modal-body"></div>
        <div class="modal__foot" id="modal-foot"></div>
    </div>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>

<script id="step-types" type="application/json"><?= json_encode($stepTypes, JSON_UNESCAPED_UNICODE) ?></script>
<script src="/assets/js/qr.js?v=1" defer></script>
<script src="/assets/js/builder.js?v=1" defer></script>
</body>
</html>
