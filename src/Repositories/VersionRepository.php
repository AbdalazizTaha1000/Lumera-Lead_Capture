<?php

declare(strict_types=1);

namespace Lumera\Repositories;

use Lumera\Core\Database;

final class VersionRepository
{
    /** @param array<string,mixed> $snapshot */
    public function create(int $funnelId, int $version, array $snapshot, ?int $publishedBy, ?string $notes = null): int
    {
        Database::execute(
            'INSERT INTO `funnel_versions` (`funnel_id`, `version`, `snapshot_json`, `published_by`, `notes`)
             VALUES (:fid, :ver, :snap, :by, :notes)',
            [
                'fid'   => $funnelId,
                'ver'   => $version,
                'snap'  => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'by'    => $publishedBy,
                'notes' => $notes,
            ]
        );

        return Database::lastInsertId();
    }

    /** @return array<string,mixed>|null decoded snapshot */
    public function published(int $funnelId): ?array
    {
        $row = Database::selectOne(
            'SELECT v.`snapshot_json`, v.`version`, v.`published_at`
             FROM `funnel_versions` v
             INNER JOIN `funnels` f ON f.`id` = v.`funnel_id` AND f.`published_version` = v.`version`
             WHERE v.`funnel_id` = :fid
             LIMIT 1',
            ['fid' => $funnelId]
        );

        if ($row === null) {
            return null;
        }

        $snapshot = json_decode((string) $row['snapshot_json'], true);

        if (!is_array($snapshot)) {
            return null;
        }

        $snapshot['version']      = (int) $row['version'];
        $snapshot['published_at'] = $row['published_at'];

        return $snapshot;
    }

    /** @return array<string,mixed>|null */
    public function byVersion(int $funnelId, int $version): ?array
    {
        $row = Database::selectOne(
            'SELECT * FROM `funnel_versions` WHERE `funnel_id` = :fid AND `version` = :v LIMIT 1',
            ['fid' => $funnelId, 'v' => $version]
        );

        if ($row === null) {
            return null;
        }

        $snapshot = json_decode((string) $row['snapshot_json'], true);

        return is_array($snapshot) ? $snapshot : null;
    }

    public function nextVersion(int $funnelId): int
    {
        return (int) Database::scalar(
            'SELECT COALESCE(MAX(`version`), 0) + 1 FROM `funnel_versions` WHERE `funnel_id` = :fid',
            ['fid' => $funnelId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function history(int $funnelId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        return Database::select(
            "SELECT v.`version`, v.`published_at`, v.`notes`, u.`email` AS published_by_email
             FROM `funnel_versions` v
             LEFT JOIN `admin_users` u ON u.`id` = v.`published_by`
             WHERE v.`funnel_id` = :fid
             ORDER BY v.`version` DESC LIMIT {$limit}",
            ['fid' => $funnelId]
        );
    }
}
