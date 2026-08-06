<?php
/**
 * New lead notification (HTML part).
 *
 * @var array<string,mixed>       $lead
 * @var list<array<string,mixed>> $answers
 * @var array<string,?string>     $attribution
 * @var string                    $company
 * @var array<string,?string>     $brand
 * @var string                    $adminUrl
 * @var ?string                   $whatsappUrl
 */

use Lumera\Support\Str;

$e = static fn ($v) => Str::e((string) $v);
$phone = trim((string) ($lead['country_code'] ?? '') . ' ' . (string) ($lead['phone'] ?? ''));

// Per-funnel brand colours, so a notification looks like the funnel it came from.
$primary = $brand['primary'] ?? '#0F2E4C';
$accent  = $brand['accent'] ?? '#C9A227';
$logoUrl = $brand['logo'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>New Lead</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1c2733;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
  <tr>
    <td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e3e6ea;">

        <tr>
          <td style="background:<?= $e($primary) ?>;padding:22px 26px;">
            <?php if ($logoUrl !== null): ?>
              <img src="<?= $e($logoUrl) ?>" alt="<?= $e($company) ?>" height="34" style="max-height:34px;width:auto;display:block;margin-bottom:10px;border:0;">
            <?php endif; ?>
            <div style="color:#ffffff;font-size:18px;font-weight:600;letter-spacing:.2px;"><?= $e($company) ?></div>
            <div style="color:<?= $e($accent) ?>;font-size:13px;margin-top:4px;">
              New lead &middot; #<?= (int) ($lead['id'] ?? 0) ?> &middot; Score <?= (int) ($lead['lead_score'] ?? 0) ?>
            </div>
          </td>
        </tr>

        <tr>
          <td style="padding:24px 26px 8px;">
            <h2 style="margin:0 0 14px;font-size:16px;color:<?= $e($primary) ?>;">Contact information</h2>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
              <tr><td style="padding:6px 0;color:#67727e;width:150px;">Full name</td><td style="padding:6px 0;font-weight:600;"><?= $e($lead['full_name'] ?? '') ?></td></tr>
              <tr><td style="padding:6px 0;color:#67727e;">Phone</td><td style="padding:6px 0;"><a href="tel:<?= $e($lead['phone_normalized'] ?? '') ?>" style="color:<?= $e($primary) ?>;"><?= $e($phone) ?></a></td></tr>
              <?php if (!empty($lead['phone_normalized'])): ?>
              <tr><td style="padding:6px 0;color:#67727e;">Normalised</td><td style="padding:6px 0;"><?= $e($lead['phone_normalized']) ?></td></tr>
              <?php endif; ?>
              <tr><td style="padding:6px 0;color:#67727e;">Email</td><td style="padding:6px 0;"><?= !empty($lead['email']) ? '<a href="mailto:' . $e($lead['email']) . '" style="color:' . $e($primary) . ';">' . $e($lead['email']) . '</a>' : '&mdash;' ?></td></tr>
              <tr><td style="padding:6px 0;color:#67727e;">Preferred language</td><td style="padding:6px 0;"><?= $e($lead['preferred_language'] ?: '—') ?></td></tr>
              <tr><td style="padding:6px 0;color:#67727e;">Consent</td><td style="padding:6px 0;"><?= !empty($lead['consent_given']) ? 'Given ' . $e($lead['consent_at'] ?? '') : 'Not given' ?></td></tr>
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:16px 26px 8px;">
            <h2 style="margin:0 0 12px;font-size:16px;color:<?= $e($primary) ?>;">Submitted answers</h2>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border-collapse:collapse;">
              <?php foreach ($answers as $answer): ?>
                <?php if (($answer['step_type'] ?? '') === 'contact_information') { continue; } ?>
                <tr>
                  <td style="padding:9px 0;border-bottom:1px solid #eef0f3;color:#67727e;width:50%;vertical-align:top;">
                    <?= $e($answer['step_title'] ?: $answer['step_key']) ?>
                  </td>
                  <td style="padding:9px 0;border-bottom:1px solid #eef0f3;font-weight:600;vertical-align:top;">
                    <?= $e($answer['answer_label'] ?: ($answer['answer_value'] ?? '—')) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          </td>
        </tr>

        <?php $hasAttribution = false; foreach ($attribution as $v) { if ($v !== null && $v !== '') { $hasAttribution = true; break; } } ?>
        <?php if ($hasAttribution): ?>
        <tr>
          <td style="padding:16px 26px 8px;">
            <h2 style="margin:0 0 12px;font-size:16px;color:<?= $e($primary) ?>;">Attribution</h2>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
              <?php foreach ($attribution as $label => $value): ?>
                <?php if ($value === null || $value === '') { continue; } ?>
                <tr>
                  <td style="padding:5px 0;color:#67727e;width:150px;vertical-align:top;"><?= $e($label) ?></td>
                  <td style="padding:5px 0;word-break:break-all;"><?= $e($value) ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </td>
        </tr>
        <?php endif; ?>

        <tr>
          <td style="padding:20px 26px 26px;">
            <?php if ($whatsappUrl !== null): ?>
              <a href="<?= $e($whatsappUrl) ?>" style="display:inline-block;background:#25D366;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:6px;font-size:14px;font-weight:600;margin:0 8px 8px 0;">Contact on WhatsApp</a>
            <?php endif; ?>
            <a href="<?= $e($adminUrl) ?>" style="display:inline-block;background:<?= $e($primary) ?>;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:6px;font-size:14px;font-weight:600;margin:0 8px 8px 0;">Open in dashboard</a>
          </td>
        </tr>

        <tr>
          <td style="padding:14px 26px;background:#fafbfc;border-top:1px solid #eef0f3;font-size:12px;color:#8a95a1;">
            Lead #<?= (int) ($lead['id'] ?? 0) ?>
            &middot; Funnel version <?= (int) ($lead['funnel_version'] ?? 0) ?>
            &middot; Interface <?= $e($lead['interface_language'] ?? 'en') ?>
            &middot; Device <?= $e($lead['device_type'] ?? '—') ?>
            &middot; Submitted <?= $e($lead['submitted_at'] ?? '') ?>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
