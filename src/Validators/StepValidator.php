<?php

declare(strict_types=1);

namespace Lumera\Validators;

use Lumera\Repositories\OptionRepository;
use Lumera\Repositories\StepRepository;
use Lumera\Support\StepType;
use Lumera\Support\Str;

/**
 * Validates and normalises admin-supplied step / option payloads before they
 * reach the repositories. Every writable column is produced here, so unknown
 * keys in the request body can never reach SQL.
 */
final class StepValidator
{
    private const OPERATORS = ['equals', 'not_equals', 'contains'];

    /** @var array<string,string> */
    private array $errors = [];

    public function __construct(
        private StepRepository $steps = new StepRepository(),
        private OptionRepository $options = new OptionRepository(),
    ) {
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null normalised column data, or null on error
     */
    public function validateStep(int $funnelId, array $input, ?int $stepId = null): ?array
    {
        $this->errors = [];

        $type = (string) ($input['step_type'] ?? '');

        if (!StepType::isValid($type)) {
            $this->errors['step_type'] = 'Choose a valid step type.';
            $type = StepType::SHORT_TEXT;
        }

        $key = Str::key((string) ($input['step_key'] ?? ''));

        if ($key === '') {
            $key = Str::key((string) ($input['title_en'] ?? 'step')) ?: 'step_' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) !== 1) {
            $this->errors['step_key'] = 'Internal key must start with a letter and use only lowercase letters, numbers and underscores.';
        } elseif ($this->steps->keyExists($funnelId, $key, $stepId)) {
            $this->errors['step_key'] = 'Another step already uses this internal key.';
        }

        $titleEn = Str::clean($input['title_en'] ?? '', 255);

        if ($titleEn === '') {
            $this->errors['title_en'] = 'An English title is required.';
        }

        $minLength = $this->nullableInt($input['min_length'] ?? null, 0, 5000);
        $maxLength = $this->nullableInt($input['max_length'] ?? null, 1, 5000);

        if ($minLength !== null && $maxLength !== null && $minLength > $maxLength) {
            $this->errors['min_length'] = 'Minimum length cannot exceed maximum length.';
        }

        $minValue = $this->nullableFloat($input['min_value'] ?? null);
        $maxValue = $this->nullableFloat($input['max_value'] ?? null);

        if ($minValue !== null && $maxValue !== null && $minValue > $maxValue) {
            $this->errors['min_value'] = 'Minimum value cannot exceed maximum value.';
        }

        $pattern = Str::clean($input['validation_pattern'] ?? '', 255);

        if ($pattern !== '' && @preg_match('/' . str_replace('/', '\/', $pattern) . '/u', '') === false) {
            $this->errors['validation_pattern'] = 'The validation pattern is not a valid regular expression.';
        }

        $condition = $this->normalizeCondition($funnelId, $input, $key);

        if ($this->errors !== []) {
            return null;
        }

        return [
            'step_key'   => $key,
            'step_type'  => $type,
            'title_en'   => $titleEn,
            'title_ar'   => Str::clean($input['title_ar'] ?? '', 255),
            'description_en' => Str::cleanMultiline($input['description_en'] ?? '', 2000) ?: null,
            'description_ar' => Str::cleanMultiline($input['description_ar'] ?? '', 2000) ?: null,
            'placeholder_en' => Str::clean($input['placeholder_en'] ?? '', 190) ?: null,
            'placeholder_ar' => Str::clean($input['placeholder_ar'] ?? '', 190) ?: null,
            'is_required'  => $this->bool($input['is_required'] ?? true) ? 1 : 0,
            'is_active'    => $this->bool($input['is_active'] ?? true) ? 1 : 0,
            'auto_advance' => ($this->bool($input['auto_advance'] ?? false) && StepType::supportsAutoAdvance($type)) ? 1 : 0,
            'min_length'   => $minLength,
            'max_length'   => $maxLength,
            'min_value'    => $minValue,
            'max_value'    => $maxValue,
            'validation_pattern'    => $pattern ?: null,
            'validation_message_en' => Str::clean($input['validation_message_en'] ?? '', 255) ?: null,
            'validation_message_ar' => Str::clean($input['validation_message_ar'] ?? '', 255) ?: null,
            'condition_parent_key'  => $condition['parent_key'],
            'condition_operator'    => $condition['operator'],
            'condition_value'       => $condition['value'],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    public function validateOption(int $stepId, array $input, ?int $optionId = null): ?array
    {
        $this->errors = [];

        $value = Str::key((string) ($input['option_value'] ?? ''));

        if ($value === '') {
            $value = Str::key((string) ($input['label_en'] ?? ''));
        }

        if (preg_match('/^[a-z0-9][a-z0-9_]{0,63}$/', $value) !== 1) {
            $this->errors['option_value'] = 'Internal value may contain only lowercase letters, numbers and underscores.';
        } elseif ($this->options->valueExists($stepId, $value, $optionId)) {
            $this->errors['option_value'] = 'Another option in this step already uses this internal value.';
        }

        $labelEn = Str::clean($input['label_en'] ?? '', 190);

        if ($labelEn === '') {
            $this->errors['label_en'] = 'An English label is required.';
        }

        $metadata = null;

        if (isset($input['metadata']) && $input['metadata'] !== '' && $input['metadata'] !== null) {
            $decoded = is_array($input['metadata'])
                ? $input['metadata']
                : json_decode((string) $input['metadata'], true);

            if (!is_array($decoded)) {
                $this->errors['metadata'] = 'Metadata must be valid JSON.';
            } else {
                $metadata = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
        }

        if ($this->errors !== []) {
            return null;
        }

        return [
            'option_value' => $value,
            'label_en'     => $labelEn,
            'label_ar'     => Str::clean($input['label_ar'] ?? '', 190),
            'icon'         => Str::clean($input['icon'] ?? '', 64) ?: null,
            'score'        => max(-999, min(999, (int) ($input['score'] ?? 0))),
            'is_active'    => $this->bool($input['is_active'] ?? true) ? 1 : 0,
            'metadata'     => $metadata,
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{parent_key: ?string, operator: ?string, value: ?string}
     */
    private function normalizeCondition(int $funnelId, array $input, string $ownKey): array
    {
        $parent   = Str::key((string) ($input['condition_parent_key'] ?? ''));
        $operator = (string) ($input['condition_operator'] ?? '');

        if ($parent === '' || $operator === '') {
            return ['parent_key' => null, 'operator' => null, 'value' => null];
        }

        if (!in_array($operator, self::OPERATORS, true)) {
            $this->errors['condition_operator'] = 'Unsupported condition operator.';
            return ['parent_key' => null, 'operator' => null, 'value' => null];
        }

        if ($parent === $ownKey) {
            $this->errors['condition_parent_key'] = 'A step cannot depend on itself.';
            return ['parent_key' => null, 'operator' => null, 'value' => null];
        }

        if ($this->steps->findByKey($funnelId, $parent) === null) {
            $this->errors['condition_parent_key'] = 'No step exists with that internal key.';
            return ['parent_key' => null, 'operator' => null, 'value' => null];
        }

        return [
            'parent_key' => $parent,
            'operator'   => $operator,
            'value'      => Str::clean($input['condition_value'] ?? '', 190),
        ];
    }

    private function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function nullableInt(mixed $value, int $min, int $max): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return max($min, min($max, (int) $value));
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
