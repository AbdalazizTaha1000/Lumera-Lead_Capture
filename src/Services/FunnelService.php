<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Core\Config;
use Lumera\Repositories\ContactFieldRepository;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\OptionRepository;
use Lumera\Repositories\StepRepository;
use Lumera\Repositories\VersionRepository;
use Lumera\Support\StepType;

/**
 * Builds the canonical funnel configuration structure.
 *
 * The same structure is used for:
 *   - the published snapshot stored in `funnel_versions`
 *   - the admin draft preview
 *   - authoritative server-side validation of a submission
 *
 * `toPublicConfig()` is the only thing ever sent to a browser and it strips
 * scoring and internal metadata.
 */
final class FunnelService
{
    public function __construct(
        private FunnelRepository $funnels = new FunnelRepository(),
        private StepRepository $steps = new StepRepository(),
        private OptionRepository $options = new OptionRepository(),
        private ContactFieldRepository $contactFields = new ContactFieldRepository(),
        private VersionRepository $versions = new VersionRepository(),
    ) {
    }

    /**
     * Builds a snapshot from the current draft tables.
     *
     * @param bool $activeOnly when true, inactive steps/options/fields are excluded
     * @return array<string,mixed>
     */
    public function buildSnapshot(int $funnelId, bool $activeOnly = true): array
    {
        $funnel = $this->funnels->find($funnelId);

        if ($funnel === null) {
            return [];
        }

        $steps    = $this->steps->allForFunnel($funnelId, $activeOnly);
        $stepIds  = array_map(static fn ($s) => (int) $s['id'], $steps);
        $optionsByStep = $this->options->forSteps($stepIds, $activeOnly);
        $fields   = $this->contactFields->forFunnel($funnelId, $activeOnly);

        $snapshotSteps = [];

        foreach ($steps as $step) {
            $type = (string) $step['step_type'];

            $entry = [
                'id'           => (int) $step['id'],
                'key'          => (string) $step['step_key'],
                'type'         => $type,
                'required'     => (bool) $step['is_required'],
                'active'       => (bool) $step['is_active'],
                'auto_advance' => (bool) $step['auto_advance'] && StepType::supportsAutoAdvance($type),
                'title'        => ['en' => (string) $step['title_en'], 'ar' => (string) $step['title_ar']],
                'description'  => ['en' => (string) ($step['description_en'] ?? ''), 'ar' => (string) ($step['description_ar'] ?? '')],
                'placeholder'  => ['en' => (string) ($step['placeholder_en'] ?? ''), 'ar' => (string) ($step['placeholder_ar'] ?? '')],
                // Optional. Null for every step that has no image, so funnels
                // built before this feature keep rendering unchanged.
                'image'        => ($step['image_path'] ?? '') ?: null,
                'validation'   => [
                    'min_length' => $step['min_length'] !== null ? (int) $step['min_length'] : null,
                    'max_length' => $step['max_length'] !== null ? (int) $step['max_length'] : null,
                    'min_value'  => $step['min_value'] !== null ? (float) $step['min_value'] : null,
                    'max_value'  => $step['max_value'] !== null ? (float) $step['max_value'] : null,
                    'pattern'    => $step['validation_pattern'] !== null && $step['validation_pattern'] !== ''
                        ? (string) $step['validation_pattern'] : null,
                    'message'    => [
                        'en' => (string) ($step['validation_message_en'] ?? ''),
                        'ar' => (string) ($step['validation_message_ar'] ?? ''),
                    ],
                ],
                'condition'    => $this->buildCondition($step),
                'options'      => [],
            ];

            if (StepType::usesOptions($type)) {
                foreach ($optionsByStep[(int) $step['id']] ?? [] as $option) {
                    $entry['options'][] = [
                        'id'       => (int) $option['id'],
                        'value'    => (string) $option['option_value'],
                        'label'    => ['en' => (string) $option['label_en'], 'ar' => (string) $option['label_ar']],
                        'icon'     => $option['icon'] !== null && $option['icon'] !== '' ? (string) $option['icon'] : null,
                        'score'    => (int) $option['score'],
                        'active'   => (bool) $option['is_active'],
                        'metadata' => $option['metadata'] !== null ? json_decode((string) $option['metadata'], true) : null,
                    ];
                }
            }

            if ($type === StepType::CONTACT_INFORMATION) {
                $entry['fields'] = $this->mapContactFields($fields);
            }

            $snapshotSteps[] = $entry;
        }

        return [
            'funnel' => [
                'id'                => (int) $funnel['id'],
                'slug'              => (string) $funnel['slug'],
                'name'              => (string) $funnel['name'],
                // Branding is per funnel, so one installation can serve many
                // companies from the same code.
                'company_name'      => $this->companyName($funnel),
                'status'            => (string) $funnel['status'],
                'default_language'  => (string) $funnel['default_language'],
                'languages'         => $this->languages($funnel),
                'theme'             => [
                    'primary'    => (string) $funnel['primary_color'],
                    'secondary'  => (string) $funnel['accent_color'],
                    'accent'     => (string) $funnel['accent_color'],
                    'background' => (string) $funnel['background_color'],
                    'logo'       => $funnel['logo_path'] ?: null,
                    'favicon'    => ($funnel['favicon_path'] ?? '') ?: null,
                    'background_image' => $funnel['background_image_path'] ?: null,
                ],
                'labels'            => [
                    'submit'          => ['en' => (string) $funnel['submit_label_en'], 'ar' => (string) $funnel['submit_label_ar']],
                    'success_title'   => ['en' => (string) $funnel['success_title_en'], 'ar' => (string) $funnel['success_title_ar']],
                    'success_message' => ['en' => (string) ($funnel['success_message_en'] ?? ''), 'ar' => (string) ($funnel['success_message_ar'] ?? '')],
                    'success_button'  => ['en' => (string) ($funnel['success_button_en'] ?? ''), 'ar' => (string) ($funnel['success_button_ar'] ?? '')],
                    'whatsapp'        => ['en' => (string) $funnel['whatsapp_label_en'], 'ar' => (string) $funnel['whatsapp_label_ar']],
                ],
                // Public-facing only. The email recipient and the webhook URL
                // are deliberately NOT in the snapshot: they are read live,
                // server-side, at submission time and never reach a browser.
                'redirect'          => [
                    'url'   => ($funnel['redirect_url'] ?? '') ?: null,
                    'delay' => (int) ($funnel['redirect_delay'] ?? 5),
                ],
                'privacy_policy_url' => $funnel['privacy_policy_url'] ?: null,
                'ui'                => [
                    'progress_bar'   => (bool) $funnel['progress_bar_enabled'],
                    'step_counter'   => (bool) $funnel['step_counter_enabled'],
                    'back_button'    => (bool) $funnel['back_button_enabled'],
                    'save_progress'  => (bool) $funnel['save_progress_enabled'],
                    'whatsapp_cta'   => (bool) $funnel['whatsapp_enabled'],
                ],
                'min_completion_seconds' => (int) $funnel['min_completion_seconds'],
            ],
            'steps'        => $snapshotSteps,
            'version'      => (int) $funnel['published_version'],
            'generated_at' => date('c'),
        ];
    }

    /** @return array<string,mixed>|null */
    public function publishedSnapshot(int $funnelId): ?array
    {
        return $this->versions->published($funnelId);
    }

    /**
     * Strips everything the visitor must not see: scores, internal metadata,
     * inactive records, DB ids of options.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function toPublicConfig(array $snapshot, bool $preview = false): array
    {
        if ($snapshot === []) {
            return [];
        }

        $steps = [];

        foreach ($snapshot['steps'] ?? [] as $step) {
            if (($step['active'] ?? true) === false) {
                continue;
            }

            $publicStep = [
                'key'          => $step['key'],
                'type'         => $step['type'],
                'required'     => (bool) ($step['required'] ?? true),
                'auto_advance' => (bool) ($step['auto_advance'] ?? false),
                'title'        => $step['title'] ?? ['en' => '', 'ar' => ''],
                'description'  => $step['description'] ?? ['en' => '', 'ar' => ''],
                'placeholder'  => $step['placeholder'] ?? ['en' => '', 'ar' => ''],
                'image'        => $step['image'] ?? null,
                'validation'   => $step['validation'] ?? [],
                'condition'    => $step['condition'] ?? null,
                'options'      => [],
            ];

            foreach ($step['options'] ?? [] as $option) {
                if (($option['active'] ?? true) === false) {
                    continue;
                }

                // Note: no score, no metadata, no database id.
                $publicStep['options'][] = [
                    'value' => $option['value'],
                    'label' => $option['label'],
                    'icon'  => $option['icon'] ?? null,
                ];
            }

            if (isset($step['fields'])) {
                $publicStep['fields'] = array_values(array_filter(
                    $step['fields'],
                    static fn ($f) => ($f['active'] ?? true) !== false
                ));
            }

            $steps[] = $publicStep;
        }

        return [
            'funnel'  => $snapshot['funnel'] ?? [],
            'steps'   => $steps,
            'version' => (int) ($snapshot['version'] ?? 0),
            'preview' => $preview,
            'whatsapp' => $this->whatsappConfig($snapshot),
        ];
    }

    /**
     * WhatsApp CTA data. The number comes from the environment and is only
     * exposed as a click-to-chat link, never as any other credential.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>|null
     */
    public function whatsappConfig(array $snapshot): ?array
    {
        if (($snapshot['funnel']['ui']['whatsapp_cta'] ?? false) !== true) {
            return null;
        }

        $number = preg_replace('/\D+/', '', Config::string('WHATSAPP_NUMBER', '')) ?? '';

        if ($number === '') {
            return null;
        }

        return [
            'number'  => $number,
            'message' => Config::string('WHATSAPP_DEFAULT_MESSAGE', ''),
            'label'   => $snapshot['funnel']['labels']['whatsapp'] ?? ['en' => 'Chat on WhatsApp', 'ar' => 'تواصل عبر واتساب'],
        ];
    }

    /**
     * Indexes snapshot steps by their internal key for O(1) validation lookups.
     *
     * @param array<string,mixed> $snapshot
     * @return array<string, array<string,mixed>>
     */
    public function indexStepsByKey(array $snapshot): array
    {
        $index = [];

        foreach ($snapshot['steps'] ?? [] as $position => $step) {
            $step['_position'] = $position;
            $index[(string) $step['key']] = $step;
        }

        return $index;
    }

    /**
     * The company this funnel is branded as. Falls back to the funnel name so a
     * funnel is never rendered unbranded.
     *
     * @param array<string,mixed> $funnel
     */
    public function companyName(array $funnel): string
    {
        $company = trim((string) ($funnel['company_name'] ?? ''));

        return $company !== '' ? $company : (string) ($funnel['name'] ?? '');
    }

    /** @param array<string,mixed> $funnel */
    public function languages(array $funnel): array
    {
        $raw  = (string) ($funnel['enabled_languages'] ?? 'en');
        $list = array_values(array_filter(array_map('trim', explode(',', $raw))));

        return $list === [] ? ['en'] : $list;
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @return list<array<string,mixed>>
     */
    private function mapContactFields(array $fields): array
    {
        $mapped = [];

        foreach ($fields as $field) {
            $mapped[] = [
                'key'         => (string) $field['field_key'],
                'type'        => (string) $field['field_type'],
                'label'       => ['en' => (string) $field['label_en'], 'ar' => (string) $field['label_ar']],
                'placeholder' => ['en' => (string) ($field['placeholder_en'] ?? ''), 'ar' => (string) ($field['placeholder_ar'] ?? '')],
                'required'    => (bool) $field['is_required'],
                'active'      => (bool) $field['is_active'],
                'min_length'  => $field['min_length'] !== null ? (int) $field['min_length'] : null,
                'max_length'  => $field['max_length'] !== null ? (int) $field['max_length'] : null,
                'pattern'     => $field['validation_pattern'] ?: null,
                'choices'     => $field['choices'] !== null ? (json_decode((string) $field['choices'], true) ?: []) : [],
            ];
        }

        return $mapped;
    }

    /**
     * @param array<string,mixed> $step
     * @return array<string,mixed>|null
     */
    private function buildCondition(array $step): ?array
    {
        $parent   = $step['condition_parent_key'] ?? null;
        $operator = $step['condition_operator'] ?? null;

        if (!is_string($parent) || $parent === '' || !is_string($operator) || $operator === '') {
            return null;
        }

        return [
            'parent_key' => $parent,
            'operator'   => $operator,
            'value'      => (string) ($step['condition_value'] ?? ''),
        ];
    }
}
