<?php

declare(strict_types=1);

namespace Lumera\Services;

use Lumera\Core\Database;
use Lumera\Core\Logger;
use Lumera\Repositories\ContactFieldRepository;
use Lumera\Repositories\FunnelRepository;
use Lumera\Repositories\OptionRepository;
use Lumera\Repositories\StepRepository;
use Lumera\Support\Str;
use RuntimeException;

/**
 * Lifecycle operations for whole funnels: create, duplicate, archive, delete.
 *
 * Every multi-row operation runs inside a transaction, so a funnel is either
 * fully created/copied or not created at all.
 */
final class FunnelManager
{
    /**
     * The contact fields a brand-new funnel starts with, mirroring the seeded
     * funnel so a new funnel is usable immediately.
     *
     * @var list<array<string,mixed>>
     */
    private const DEFAULT_CONTACT_FIELDS = [
        ['full_name', 'text', 'Full Name', 'الاسم الكامل', 'Enter your full name', 'أدخل اسمك الكامل', 1, 1, 1, 120, null],
        ['country_code', 'country_code', 'Country Code', 'رمز الدولة', '+971', '+971', 1, 1, 1, 8, null],
        ['phone', 'tel', 'Phone Number', 'رقم الهاتف', '50 123 4567', '50 123 4567', 1, 1, 1, 20, null],
        ['email', 'email', 'Email Address', 'البريد الإلكتروني', 'you@example.com', 'you@example.com', 0, 1, 1, 190, null],
        ['preferred_language', 'select', 'Preferred Language', 'اللغة المفضلة', null, null, 1, 1, 1, null,
            '[{"value":"english","label_en":"English","label_ar":"الإنجليزية"},{"value":"arabic","label_en":"Arabic","label_ar":"العربية"}]'],
        ['nationality', 'text', 'Nationality', 'الجنسية', 'e.g. United Arab Emirates', 'مثال: الإمارات العربية المتحدة', 0, 0, 0, 100, null],
        ['preferred_contact_method', 'select', 'Preferred Contact Method', 'طريقة التواصل المفضلة', null, null, 0, 0, 0, null,
            '[{"value":"phone","label_en":"Phone Call","label_ar":"مكالمة هاتفية"},{"value":"whatsapp","label_en":"WhatsApp","label_ar":"واتساب"},{"value":"email","label_en":"Email","label_ar":"البريد الإلكتروني"}]'],
    ];

    public function __construct(
        private FunnelRepository $funnels = new FunnelRepository(),
        private StepRepository $steps = new StepRepository(),
        private OptionRepository $options = new OptionRepository(),
        private ContactFieldRepository $contactFields = new ContactFieldRepository(),
    ) {
    }

    /**
     * Creates an empty funnel with the default contact fields and one starter
     * step, so the administrator has something to edit straight away.
     *
     * @param array<string,mixed> $data validated funnel column data
     */
    public function create(array $data): int
    {
        return Database::transaction(function () use ($data) {
            $funnelId = $this->funnels->create($data);

            $this->seedContactFields($funnelId);

            // A single starter step keeps the funnel publishable from the start.
            $this->steps->create($funnelId, [
                'step_key'     => 'contact_information',
                'step_type'    => 'contact_information',
                'title_en'     => 'How can we reach you?',
                'title_ar'     => 'كيف يمكننا التواصل معك؟',
                'is_required'  => 1,
                'is_active'    => 1,
                'auto_advance' => 0,
                'sort_order'   => 1,
            ]);

            Logger::info('funnel.created', ['funnel_id' => $funnelId, 'slug' => $data['slug']]);

            return $funnelId;
        });
    }

    /**
     * Deep-copies a funnel.
     *
     * Copied:     branding, settings, steps, options, contact fields,
     *             conditional rules.
     * NOT copied: leads, lead answers, notes, published versions, audit logs.
     *
     * The copy always starts unpublished and archived-free, so it cannot go
     * live by accident.
     *
     * @return array{id: int, slug: string, name: string}
     */
    public function duplicate(int $sourceId, ?string $newName = null, ?string $newSlug = null): array
    {
        $source = $this->funnels->find($sourceId);

        if ($source === null) {
            throw new RuntimeException('Funnel not found.');
        }

        return Database::transaction(function () use ($source, $sourceId, $newName, $newSlug) {
            $name = Str::clean($newName ?? ($source['name'] . ' (Copy)'), 190);
            $slug = $this->funnels->availableSlug(
                Str::slug($newSlug !== null && $newSlug !== '' ? $newSlug : $source['slug'] . '-copy')
            );

            // Copy every writable column, then override identity and state.
            $data = [];

            foreach (FunnelRepository::WRITABLE as $column) {
                if (array_key_exists($column, $source)) {
                    $data[$column] = $source[$column];
                }
            }

            $data['slug'] = $slug;
            $data['name'] = $name;
            // A copy is never live until the administrator publishes it.
            $data['status'] = 'draft';

            $funnelId = $this->funnels->create($data);

            // --- contact fields (including conditional/validation config) ---
            foreach ($this->contactFields->forFunnel($sourceId, false) as $field) {
                Database::execute(
                    'INSERT INTO `funnel_contact_fields`
                        (`funnel_id`, `field_key`, `field_type`, `label_en`, `label_ar`,
                         `placeholder_en`, `placeholder_ar`, `is_required`, `is_active`,
                         `is_system`, `sort_order`, `min_length`, `max_length`,
                         `validation_pattern`, `choices`)
                     VALUES (:fid, :key, :type, :len, :lar, :pen, :par, :req, :act, :sys, :ord,
                             :min, :max, :pattern, :choices)',
                    [
                        'fid'     => $funnelId,
                        'key'     => $field['field_key'],
                        'type'    => $field['field_type'],
                        'len'     => $field['label_en'],
                        'lar'     => $field['label_ar'],
                        'pen'     => $field['placeholder_en'],
                        'par'     => $field['placeholder_ar'],
                        'req'     => $field['is_required'],
                        'act'     => $field['is_active'],
                        'sys'     => $field['is_system'],
                        'ord'     => $field['sort_order'],
                        'min'     => $field['min_length'],
                        'max'     => $field['max_length'],
                        'pattern' => $field['validation_pattern'],
                        'choices' => $field['choices'],
                    ]
                );
            }

            // --- steps + their options ---
            $stepCount = 0;
            $optionCount = 0;

            foreach ($this->steps->allForFunnel($sourceId, false) as $step) {
                $newStepId = $this->steps->create($funnelId, [
                    'step_key'   => $step['step_key'],
                    'step_type'  => $step['step_type'],
                    'title_en'   => $step['title_en'],
                    'title_ar'   => $step['title_ar'],
                    'description_en' => $step['description_en'],
                    'description_ar' => $step['description_ar'],
                    'placeholder_en' => $step['placeholder_en'],
                    'placeholder_ar' => $step['placeholder_ar'],
                    'image_path'     => $step['image_path'],
                    'is_required'    => $step['is_required'],
                    'is_active'      => $step['is_active'],
                    'auto_advance'   => $step['auto_advance'],
                    'min_length'     => $step['min_length'],
                    'max_length'     => $step['max_length'],
                    'min_value'      => $step['min_value'],
                    'max_value'      => $step['max_value'],
                    'validation_pattern'    => $step['validation_pattern'],
                    'validation_message_en' => $step['validation_message_en'],
                    'validation_message_ar' => $step['validation_message_ar'],
                    // Conditional rules reference a step KEY, not an id, so they
                    // stay valid inside the copy without any remapping.
                    'condition_parent_key'  => $step['condition_parent_key'],
                    'condition_operator'    => $step['condition_operator'],
                    'condition_value'       => $step['condition_value'],
                    'sort_order'            => $step['sort_order'],
                ]);

                $stepCount++;

                foreach ($this->options->forStep((int) $step['id'], false) as $option) {
                    $this->options->create($newStepId, [
                        'option_value' => $option['option_value'],
                        'label_en'     => $option['label_en'],
                        'label_ar'     => $option['label_ar'],
                        'icon'         => $option['icon'],
                        'score'        => $option['score'],
                        'is_active'    => $option['is_active'],
                        'metadata'     => $option['metadata'],
                        'sort_order'   => $option['sort_order'],
                    ]);

                    $optionCount++;
                }
            }

            Logger::info('funnel.duplicated', [
                'source_funnel_id' => $sourceId,
                'funnel_id'        => $funnelId,
                'steps'            => $stepCount,
                'options'          => $optionCount,
            ]);

            return ['id' => $funnelId, 'slug' => $slug, 'name' => $name];
        });
    }

    /**
     * Decides whether a delete may proceed.
     *
     * A funnel that has ever been published, or that has collected leads, needs
     * an explicit permanent-delete confirmation. Leads themselves are never
     * removed: the foreign key nulls `leads.funnel_id` and every answer keeps
     * its own label snapshot.
     *
     * @param array<string,mixed> $funnel
     * @return array{allowed: bool, requires_confirmation: bool, leads: int, reason: string}
     */
    public function deletionGuard(array $funnel, bool $confirmedPermanent): array
    {
        $leads = $this->funnels->leadCount((int) $funnel['id']);
        $published = (int) $funnel['published_version'] > 0;
        $requiresConfirmation = $published || $leads > 0;

        if ($requiresConfirmation && !$confirmedPermanent) {
            $reason = $published && $leads > 0
                ? "This funnel is published (v{$funnel['published_version']}) and has {$leads} lead(s)."
                : ($published
                    ? "This funnel has been published (v{$funnel['published_version']})."
                    : "This funnel has {$leads} lead(s).");

            return [
                'allowed'               => false,
                'requires_confirmation' => true,
                'leads'                 => $leads,
                'reason'                => $reason . ' Confirm permanent deletion to continue, or archive it instead.',
            ];
        }

        return [
            'allowed'               => true,
            'requires_confirmation' => $requiresConfirmation,
            'leads'                 => $leads,
            'reason'                => '',
        ];
    }

    /** Inserts the default contact-field set for a new funnel. */
    private function seedContactFields(int $funnelId): void
    {
        $order = 0;

        foreach (self::DEFAULT_CONTACT_FIELDS as $field) {
            $order++;

            Database::execute(
                'INSERT INTO `funnel_contact_fields`
                    (`funnel_id`, `field_key`, `field_type`, `label_en`, `label_ar`,
                     `placeholder_en`, `placeholder_ar`, `is_required`, `is_active`,
                     `is_system`, `sort_order`, `max_length`, `choices`)
                 VALUES (:fid, :key, :type, :len, :lar, :pen, :par, :req, :act, :sys, :ord, :max, :choices)',
                [
                    'fid'     => $funnelId,
                    'key'     => $field[0],
                    'type'    => $field[1],
                    'len'     => $field[2],
                    'lar'     => $field[3],
                    'pen'     => $field[4],
                    'par'     => $field[5],
                    'req'     => $field[6],
                    'act'     => $field[7],
                    'sys'     => $field[8],
                    'ord'     => $order,
                    'max'     => $field[9],
                    'choices' => $field[10],
                ]
            );
        }
    }
}
