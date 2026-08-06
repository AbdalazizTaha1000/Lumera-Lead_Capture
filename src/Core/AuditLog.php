<?php

declare(strict_types=1);

namespace Lumera\Core;

use Lumera\Support\Request;

/**
 * Administrative action trail. Stores no passwords and no secrets.
 */
final class AuditLog
{
    public const LOGIN_SUCCESS   = 'login_success';
    public const LOGIN_FAILURE   = 'login_failure';
    public const LOGOUT          = 'logout';
    public const FUNNEL_UPDATED  = 'funnel_updated';
    public const STEP_CREATED    = 'step_created';
    public const STEP_UPDATED    = 'step_updated';
    public const STEP_DELETED    = 'step_deleted';
    public const STEP_DUPLICATED = 'step_duplicated';
    public const STEP_REORDERED  = 'step_reordered';
    public const OPTION_CREATED  = 'option_created';
    public const OPTION_UPDATED  = 'option_updated';
    public const OPTION_DELETED  = 'option_deleted';
    public const OPTION_REORDERED = 'option_reordered';
    public const CONTACT_FIELD_UPDATED = 'contact_field_updated';
    public const FUNNEL_PUBLISHED = 'funnel_published';
    public const LEAD_STATUS_CHANGED = 'lead_status_changed';
    public const LEAD_NOTE_ADDED  = 'lead_note_added';
    public const LEAD_ARCHIVED    = 'lead_archived';
    public const LEAD_DELETED     = 'lead_deleted';
    public const CSV_EXPORTED     = 'csv_exported';
    public const SETTINGS_UPDATED = 'settings_updated';
    public const FILE_UPLOADED    = 'file_uploaded';

    /** @param array<string,mixed> $metadata */
    public static function record(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $metadata = [],
        ?int $adminUserId = null
    ): void {
        try {
            $adminUserId ??= Auth::id();

            Database::execute(
                'INSERT INTO `audit_logs`
                    (`admin_user_id`, `action`, `entity_type`, `entity_id`, `metadata`, `ip_hash`)
                 VALUES (:uid, :action, :etype, :eid, :meta, :iph)',
                [
                    'uid'    => $adminUserId,
                    'action' => mb_substr($action, 0, 64),
                    'etype'  => $entityType !== null ? mb_substr($entityType, 0, 64) : null,
                    'eid'    => $entityId !== null ? mb_substr((string) $entityId, 0, 64) : null,
                    'meta'   => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    'iph'    => Request::ipHash(),
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('audit.write_failed', ['action' => $action, 'message' => $e->getMessage()]);
        }
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $limit = 20): array
    {
        $limit = max(1, min(200, $limit));

        return Database::select(
            "SELECT a.*, u.`email` AS admin_email
             FROM `audit_logs` a
             LEFT JOIN `admin_users` u ON u.`id` = a.`admin_user_id`
             ORDER BY a.`id` DESC LIMIT {$limit}"
        );
    }
}
