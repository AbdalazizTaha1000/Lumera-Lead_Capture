<?php
/**
 * Admin application shell.
 *
 * @var array<string,mixed> $admin
 * @var string $csrf
 * @var string $company
 * @var int    $funnelId
 * @var string $funnelSlug
 * @var list<string> $timezones
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
<title>Dashboard — <?= $e($company) ?></title>
<link rel="stylesheet" href="/assets/css/admin.css?v=1">
</head>
<body class="admin-body"
      data-csrf="<?= $e($csrf) ?>"
      data-funnel-id="<?= (int) $funnelId ?>"
      data-funnel-slug="<?= $e($funnelSlug) ?>">

<div class="layout" id="layout">

    <!-- ------------------------------------------------------------ sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar__head">
            <span class="sidebar__mark">L</span>
            <span class="sidebar__name"><?= $e($company) ?></span>
        </div>

        <nav class="sidebar__nav">
            <a class="nav-item" href="#/dashboard" data-route="dashboard">
                <span class="nav-item__icon" aria-hidden="true">▦</span>
                <span class="nav-item__label">Dashboard</span>
            </a>
            <a class="nav-item" href="#/funnels" data-route="funnels">
                <span class="nav-item__icon" aria-hidden="true">⊞</span>
                <span class="nav-item__label">Funnels</span>
            </a>
            <a class="nav-item" href="#/builder" data-route="builder">
                <span class="nav-item__icon" aria-hidden="true">⌗</span>
                <span class="nav-item__label">Funnel Builder</span>
            </a>
            <a class="nav-item" href="#/leads" data-route="leads">
                <span class="nav-item__icon" aria-hidden="true">☰</span>
                <span class="nav-item__label">Leads</span>
            </a>
            <a class="nav-item" href="#/settings" data-route="settings">
                <span class="nav-item__icon" aria-hidden="true">⚙</span>
                <span class="nav-item__label">Settings</span>
            </a>
        </nav>

        <div class="sidebar__foot">
            <button class="nav-item nav-item--button" type="button" id="logout-button">
                <span class="nav-item__icon" aria-hidden="true">⏻</span>
                <span class="nav-item__label">Logout</span>
            </button>
        </div>
    </aside>

    <div class="sidebar-scrim" id="sidebar-scrim" hidden></div>

    <!-- ------------------------------------------------------------- main -->
    <div class="main">
        <header class="topbar">
            <button class="icon-button topbar__toggle" type="button" id="sidebar-toggle" aria-label="Toggle navigation">☰</button>

            <div class="topbar__titles">
                <h1 class="topbar__title" id="page-title">Dashboard</h1>
                <p class="topbar__subtitle" id="page-subtitle">Overview of your lead capture funnel</p>
            </div>

            <div class="topbar__actions" id="page-actions"></div>

            <div class="topbar__user">
                <span class="avatar" aria-hidden="true"><?= $e(strtoupper(substr((string) $admin['email'], 0, 1))) ?></span>
                <span class="topbar__email"><?= $e($admin['email']) ?></span>
            </div>
        </header>

        <main class="content" id="content">
            <div class="loading-block" id="global-loading">
                <span class="spinner"></span>
                <p>Loading…</p>
            </div>

            <!-- ------------------------------------------------- dashboard -->
            <section class="view" id="view-dashboard" hidden>
                <div class="stat-grid" id="stat-grid"></div>

                <div class="panel-grid">
                    <section class="panel">
                        <header class="panel__head">
                            <h2 class="panel__title">Latest submissions</h2>
                            <a class="link" href="#/leads">View all</a>
                        </header>
                        <div class="table-wrap" id="latest-leads"></div>
                    </section>

                    <section class="panel">
                        <header class="panel__head"><h2 class="panel__title">Funnel status</h2></header>
                        <div class="panel__body" id="funnel-status-card"></div>
                    </section>
                </div>

                <div class="panel-grid panel-grid--thirds">
                    <section class="panel">
                        <header class="panel__head"><h2 class="panel__title">Sources</h2></header>
                        <div class="panel__body" id="breakdown-source"></div>
                    </section>
                    <section class="panel">
                        <header class="panel__head"><h2 class="panel__title">Budget</h2></header>
                        <div class="panel__body" id="breakdown-budget"></div>
                    </section>
                    <section class="panel">
                        <header class="panel__head"><h2 class="panel__title">Purpose</h2></header>
                        <div class="panel__body" id="breakdown-purpose"></div>
                    </section>
                </div>
            </section>

            <!-- --------------------------------------------------- funnels -->
            <section class="view" id="view-funnels" hidden>
                <div class="panel">
                    <header class="panel__head">
                        <h2 class="panel__title">All funnels</h2>
                        <div class="panel__tools" id="funnels-tools"></div>
                    </header>
                    <div class="table-wrap" id="funnels-table"></div>
                </div>
            </section>

            <!-- --------------------------------------------------- builder -->
            <section class="view" id="view-builder" hidden>
                <div class="publish-bar" id="publish-bar"></div>

                <div class="funnel-switcher" id="funnel-switcher"></div>

                <div class="builder">
                    <aside class="builder__list">
                        <header class="builder__list-head">
                            <h2 class="panel__title">Steps</h2>
                            <button class="btn btn--sm btn--primary" type="button" id="add-step">Add Step</button>
                        </header>
                        <ol class="step-list" id="step-list"></ol>
                        <p class="builder__hint">Drag a step by its handle to reorder, or use the arrows.</p>
                    </aside>

                    <section class="builder__editor" id="step-editor">
                        <div class="empty-state">
                            <p class="empty-state__title">Select a step</p>
                            <p class="empty-state__text">Choose a step on the left to edit its content, settings and options.</p>
                        </div>
                    </section>
                </div>

                <section class="panel" id="contact-fields-panel" hidden>
                    <header class="panel__head">
                        <h2 class="panel__title">Contact fields</h2>
                        <span class="panel__hint">Shown on any Contact Information step</span>
                    </header>
                    <div class="panel__body" id="contact-fields"></div>
                </section>

                <section class="panel">
                    <header class="panel__head">
                        <h2 class="panel__title">Branding</h2>
                        <span class="panel__hint">Shown on this funnel's public page</span>
                    </header>
                    <div class="panel__body" id="funnel-branding"></div>
                </section>

                <section class="panel">
                    <header class="panel__head"><h2 class="panel__title">Funnel settings</h2></header>
                    <div class="panel__body" id="funnel-settings"></div>
                </section>
            </section>

            <!-- ----------------------------------------------------- leads -->
            <section class="view" id="view-leads" hidden>
                <div class="panel">
                    <div class="filters" id="lead-filters"></div>
                </div>

                <div class="panel">
                    <div class="table-wrap" id="leads-table"></div>
                    <div class="pagination" id="leads-pagination"></div>
                </div>
            </section>

            <!-- -------------------------------------------------- settings -->
            <section class="view" id="view-settings" hidden>
                <div class="panel">
                    <header class="panel__head">
                        <h2 class="panel__title">Application settings</h2>
                        <span class="panel__hint">Secrets stay in .env and are never editable here</span>
                    </header>
                    <div class="panel__body" id="settings-form"></div>
                </div>

                <div class="panel">
                    <header class="panel__head"><h2 class="panel__title">Environment</h2></header>
                    <div class="panel__body" id="settings-environment"></div>
                </div>
            </section>
        </main>
    </div>
</div>

<!-- ------------------------------------------------------------- modal -->
<div class="modal" id="modal" hidden>
    <div class="modal__scrim" data-modal-close></div>
    <div class="modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <header class="modal__head">
            <h2 class="modal__title" id="modal-title"></h2>
            <button class="icon-button" type="button" data-modal-close aria-label="Close">✕</button>
        </header>
        <div class="modal__body" id="modal-body"></div>
        <footer class="modal__foot" id="modal-foot"></footer>
    </div>
</div>

<div class="toast-stack" id="toast-stack" aria-live="polite"></div>

<template id="timezone-options">
    <?php foreach ($timezones as $timezone): ?><option value="<?= $e($timezone) ?>"><?= $e($timezone) ?></option><?php endforeach; ?>
</template>

<script src="/assets/js/admin.js?v=1" defer></script>
<script src="/assets/js/funnel-builder.js?v=1" defer></script>
</body>
</html>
