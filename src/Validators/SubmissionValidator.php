<?php

declare(strict_types=1);

namespace Lumera\Validators;

use Lumera\Support\Phone;
use Lumera\Support\StepType;
use Lumera\Support\Str;

/**
 * Authoritative server-side validation of a public submission.
 *
 * Nothing the client sends about the *shape* of the funnel is trusted: step
 * types, option labels, scores, required flags and the funnel version are all
 * resolved from the published snapshot. The client only supplies raw answer
 * values.
 */
final class SubmissionValidator
{
    private const MAX_MULTI_SELECT = 25;
    private const MAX_TEXT         = 500;

    /** @var array<string,string> */
    private array $errors = [];

    /** @var list<array<string,mixed>> */
    private array $answers = [];

    /** @var array<string,mixed> */
    private array $contact = [];

    private int $score = 0;
    private bool $consentGiven = false;
    private bool $consentRequired = false;

    /**
     * @param array<string,mixed> $snapshot published funnel snapshot
     * @param array<string,mixed> $submitted raw client answers keyed by step key
     * @param string $language interface language, used only for message selection
     */
    public function validate(array $snapshot, array $submitted, string $language = 'en'): bool
    {
        $this->errors = [];
        $this->answers = [];
        $this->contact = [];
        $this->score = 0;
        $this->consentGiven = false;
        $this->consentRequired = false;

        $steps = $snapshot['steps'] ?? [];

        if ($steps === []) {
            $this->errors['_form'] = 'This form is not available right now.';
            return false;
        }

        $knownKeys = [];

        foreach ($steps as $position => $step) {
            $key = (string) $step['key'];
            $knownKeys[$key] = true;

            $type = (string) $step['type'];

            if (in_array($type, StepType::NON_ANSWERING, true)) {
                continue;
            }

            $raw = $submitted[$key] ?? null;

            match (true) {
                StepType::usesOptions($type)            => $this->validateOptionStep($step, $raw, $position, $language),
                $type === StepType::CONTACT_INFORMATION => $this->validateContactStep($step, $raw, $position, $language),
                $type === StepType::CONSENT             => $this->validateConsentStep($step, $raw, $position, $language),
                $type === StepType::EMAIL               => $this->validateEmailStep($step, $raw, $position, $language),
                $type === StepType::PHONE               => $this->validatePhoneStep($step, $raw, $position, $language),
                $type === StepType::NUMBER              => $this->validateNumberStep($step, $raw, $position, $language),
                default                                 => $this->validateTextStep($step, $raw, $position, $language),
            };
        }

        // Reject any key the published funnel does not define.
        foreach (array_keys($submitted) as $submittedKey) {
            if (!isset($knownKeys[(string) $submittedKey])) {
                $this->errors['_form'] = 'The form has been updated. Please refresh the page and try again.';
                break;
            }
        }

        return $this->errors === [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return list<array<string,mixed>> */
    public function answers(): array
    {
        return $this->answers;
    }

    /** @return array<string,mixed> */
    public function contact(): array
    {
        return $this->contact;
    }

    public function score(): int
    {
        return $this->score;
    }

    public function consentGiven(): bool
    {
        return $this->consentGiven;
    }

    public function consentRequired(): bool
    {
        return $this->consentRequired;
    }

    // ---------------------------------------------------------------- steps --

    /** @param array<string,mixed> $step */
    private function validateOptionStep(array $step, mixed $raw, int $position, string $lang): void
    {
        $key      = (string) $step['key'];
        $type     = (string) $step['type'];
        $required = (bool) ($step['required'] ?? true);
        $multi    = $type === StepType::MULTI_SELECT;

        /** @var array<string, array<string,mixed>> $byValue */
        $byValue = [];

        foreach ($step['options'] ?? [] as $option) {
            if (($option['active'] ?? true) === false) {
                continue; // disabled options are not selectable
            }
            $byValue[(string) $option['value']] = $option;
        }

        $selected = [];

        if ($multi) {
            if ($raw !== null && !is_array($raw)) {
                $this->errors[$key] = $this->message($step, $lang, 'Please choose from the available options.');
                return;
            }

            $raw ??= [];

            if (count($raw) > self::MAX_MULTI_SELECT) {
                $this->errors[$key] = 'Too many options selected.';
                return;
            }

            foreach ($raw as $value) {
                if (!is_string($value) && !is_int($value)) {
                    $this->errors[$key] = 'Malformed selection.';
                    return;
                }

                $value = (string) $value;

                if (!isset($byValue[$value])) {
                    $this->errors[$key] = 'One of the selected options is no longer available.';
                    return;
                }

                $selected[$value] = $byValue[$value];
            }
        } else {
            if ($raw !== null && $raw !== '') {
                if (!is_string($raw) && !is_int($raw)) {
                    $this->errors[$key] = 'Malformed selection.';
                    return;
                }

                $value = (string) $raw;

                if (!isset($byValue[$value])) {
                    $this->errors[$key] = 'The selected option is no longer available.';
                    return;
                }

                $selected[$value] = $byValue[$value];
            }
        }

        if ($selected === []) {
            if ($required) {
                $this->errors[$key] = $this->message($step, $lang, 'Please make a selection.');
            }
            return;
        }

        $values = array_keys($selected);
        $labels = [];
        $stepScore = 0;

        foreach ($selected as $option) {
            $labels[] = (string) ($option['label'][$lang] ?? $option['label']['en'] ?? $option['value']);
            $stepScore += (int) ($option['score'] ?? 0); // authoritative score from the snapshot
        }

        $this->score += $stepScore;

        $this->answers[] = [
            'step_id'      => $step['id'] ?? null,
            'step_key'     => $key,
            'step_title'   => Str::limit((string) ($step['title'][$lang] ?? $step['title']['en'] ?? ''), 255),
            'step_type'    => $type,
            'answer_value' => $multi ? implode(',', $values) : $values[0],
            'answer_label' => implode(', ', $labels),
            'answer_json'  => $multi
                ? json_encode(['values' => $values, 'labels' => $labels], JSON_UNESCAPED_UNICODE)
                : null,
            'score'        => $stepScore,
            'sort_order'   => $position,
        ];
    }

    /** @param array<string,mixed> $step */
    private function validateContactStep(array $step, mixed $raw, int $position, string $lang): void
    {
        $key = (string) $step['key'];

        if ($raw !== null && !is_array($raw)) {
            $this->errors[$key] = 'Malformed contact details.';
            return;
        }

        /** @var array<string,mixed> $raw */
        $raw    = $raw ?? [];
        $fields = $step['fields'] ?? [];
        $values = [];
        $summary = [];

        foreach ($fields as $field) {
            if (($field['active'] ?? true) === false) {
                continue;
            }

            $fieldKey = (string) $field['key'];
            $required = (bool) ($field['required'] ?? false);
            $value    = $raw[$fieldKey] ?? null;

            if (is_array($value) || is_object($value)) {
                $this->errors[$key . '.' . $fieldKey] = 'Malformed value.';
                continue;
            }

            $value = Str::clean(is_scalar($value) ? (string) $value : '', self::MAX_TEXT);

            if ($value === '') {
                if ($required) {
                    $this->errors[$key . '.' . $fieldKey] = $this->fieldLabel($field, $lang) . ' is required.';
                }
                $values[$fieldKey] = null;
                continue;
            }

            $error = $this->validateContactValue($field, $fieldKey, $value, $raw, $lang);

            if ($error !== null) {
                $this->errors[$key . '.' . $fieldKey] = $error;
                continue;
            }

            $values[$fieldKey] = $value;
            $summary[] = $this->fieldLabel($field, $lang) . ': ' . $value;
        }

        // Phone must be checkable against its country code.
        if (($values['phone'] ?? null) !== null) {
            $normalized = Phone::normalize($values['country_code'] ?? null, $values['phone']);

            if ($normalized === null) {
                $this->errors[$key . '.phone'] = 'Please enter a valid phone number.';
            } else {
                $values['phone_normalized'] = $normalized;
            }
        }

        $this->contact = $values;

        $this->answers[] = [
            'step_id'      => $step['id'] ?? null,
            'step_key'     => $key,
            'step_title'   => Str::limit((string) ($step['title'][$lang] ?? $step['title']['en'] ?? ''), 255),
            'step_type'    => (string) $step['type'],
            'answer_value' => Str::limit($values['full_name'] ?? '', 190),
            'answer_label' => implode(' · ', $summary),
            'answer_json'  => json_encode($values, JSON_UNESCAPED_UNICODE),
            'score'        => 0,
            'sort_order'   => $position,
        ];
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,mixed> $all
     */
    private function validateContactValue(array $field, string $fieldKey, string $value, array $all, string $lang): ?string
    {
        $type  = (string) ($field['type'] ?? 'text');
        $label = $this->fieldLabel($field, $lang);

        $min = $field['min_length'] ?? null;
        $max = $field['max_length'] ?? null;

        if (is_int($min) && mb_strlen($value) < $min) {
            return $label . " must be at least {$min} characters.";
        }

        if (is_int($max) && mb_strlen($value) > $max) {
            return $label . " must be at most {$max} characters.";
        }

        $pattern = $field['pattern'] ?? null;

        if (is_string($pattern) && $pattern !== '' && !$this->matchesPattern($pattern, $value)) {
            return 'Please enter a valid ' . mb_strtolower($label) . '.';
        }

        return match ($type) {
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                ? 'Please enter a valid email address.' : null,
            'country_code' => Phone::isValidCountryCode($value)
                ? null : 'Please select a valid country code.',
            'tel' => preg_match('/^[0-9+\-\s().]{5,20}$/', $value) === 1
                ? null : 'Please enter a valid phone number.',
            'select' => $this->isAllowedChoice($field, $value)
                ? null : 'Please choose one of the available options.',
            default => null,
        };
    }

    /** @param array<string,mixed> $field */
    private function isAllowedChoice(array $field, string $value): bool
    {
        foreach ($field['choices'] ?? [] as $choice) {
            if ((string) ($choice['value'] ?? '') === $value) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $step */
    private function validateConsentStep(array $step, mixed $raw, int $position, string $lang): void
    {
        $key      = (string) $step['key'];
        $required = (bool) ($step['required'] ?? true);
        $given    = $raw === true || $raw === 1 || $raw === '1' || $raw === 'true' || $raw === 'on';

        $this->consentRequired = $this->consentRequired || $required;

        if ($required && !$given) {
            $this->errors[$key] = $this->message($step, $lang, 'Your consent is required to continue.');
            return;
        }

        $this->consentGiven = $this->consentGiven || $given;

        $this->answers[] = [
            'step_id'      => $step['id'] ?? null,
            'step_key'     => $key,
            'step_title'   => Str::limit((string) ($step['title'][$lang] ?? $step['title']['en'] ?? ''), 255),
            'step_type'    => (string) $step['type'],
            'answer_value' => $given ? 'yes' : 'no',
            'answer_label' => $given ? 'Consent given' : 'Consent not given',
            'answer_json'  => null,
            'score'        => 0,
            'sort_order'   => $position,
        ];
    }

    /** @param array<string,mixed> $step */
    private function validateEmailStep(array $step, mixed $raw, int $position, string $lang): void
    {
        $value = $this->scalarValue($step, $raw, $lang);

        if ($value === null) {
            return;
        }

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[(string) $step['key']] = $this->message($step, $lang, 'Please enter a valid email address.');
            return;
        }

        $this->pushScalarAnswer($step, $value, $position, $lang);
    }

    /** @param array<string,mixed> $step */
    private function validatePhoneStep(array $step, mixed $raw, int $position, string $lang): void
    {
        $value = $this->scalarValue($step, $raw, $lang);

        if ($value === null) {
            return;
        }

        if ($value !== '' && preg_match('/^[0-9+\-\s().]{6,20}$/', $value) !== 1) {
            $this->errors[(string) $step['key']] = $this->message($step, $lang, 'Please enter a valid phone number.');
            return;
        }

        $this->pushScalarAnswer($step, $value, $position, $lang);
    }

    /** @param array<string,mixed> $step */
    private function validateNumberStep(array $step, mixed $raw, int $position, string $lang): void
    {
        $key      = (string) $step['key'];
        $required = (bool) ($step['required'] ?? true);

        if ($raw === null || $raw === '') {
            if ($required) {
                $this->errors[$key] = $this->message($step, $lang, 'This field is required.');
            }
            return;
        }

        if (is_array($raw) || !is_numeric($raw)) {
            $this->errors[$key] = $this->message($step, $lang, 'Please enter a valid number.');
            return;
        }

        $number = (float) $raw;
        $min    = $step['validation']['min_value'] ?? null;
        $max    = $step['validation']['max_value'] ?? null;

        if (is_numeric($min) && $number < (float) $min) {
            $this->errors[$key] = $this->message($step, $lang, 'The value is below the allowed minimum.');
            return;
        }

        if (is_numeric($max) && $number > (float) $max) {
            $this->errors[$key] = $this->message($step, $lang, 'The value is above the allowed maximum.');
            return;
        }

        $display = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');

        $this->pushScalarAnswer($step, $display === '' ? '0' : $display, $position, $lang);
    }

    /** @param array<string,mixed> $step */
    private function validateTextStep(array $step, mixed $raw, int $position, string $lang): void
    {
        $value = $this->scalarValue($step, $raw, $lang);

        if ($value === null) {
            return;
        }

        $key = (string) $step['key'];
        $min = $step['validation']['min_length'] ?? null;
        $max = $step['validation']['max_length'] ?? null;

        if ($value !== '' && is_int($min) && mb_strlen($value) < $min) {
            $this->errors[$key] = $this->message($step, $lang, "Please enter at least {$min} characters.");
            return;
        }

        if (is_int($max) && $max > 0 && mb_strlen($value) > $max) {
            $this->errors[$key] = $this->message($step, $lang, "Please enter no more than {$max} characters.");
            return;
        }

        $pattern = $step['validation']['pattern'] ?? null;

        if ($value !== '' && is_string($pattern) && $pattern !== '' && !$this->matchesPattern($pattern, $value)) {
            $this->errors[$key] = $this->message($step, $lang, 'Please check the format of your answer.');
            return;
        }

        $this->pushScalarAnswer($step, $value, $position, $lang);
    }

    // --------------------------------------------------------------- helpers --

    /**
     * Returns the cleaned scalar value, or null when the step should be
     * skipped (missing optional answer, or a validation error was recorded).
     *
     * @param array<string,mixed> $step
     */
    private function scalarValue(array $step, mixed $raw, string $lang): ?string
    {
        $key      = (string) $step['key'];
        $required = (bool) ($step['required'] ?? true);

        if (is_array($raw)) {
            $this->errors[$key] = 'Malformed value.';
            return null;
        }

        $value = Str::clean(is_scalar($raw) ? (string) $raw : '', self::MAX_TEXT);

        if ($value === '') {
            if ($required) {
                $this->errors[$key] = $this->message($step, $lang, 'This field is required.');
            }
            return null;
        }

        return $value;
    }

    /** @param array<string,mixed> $step */
    private function pushScalarAnswer(array $step, string $value, int $position, string $lang): void
    {
        $this->answers[] = [
            'step_id'      => $step['id'] ?? null,
            'step_key'     => (string) $step['key'],
            'step_title'   => Str::limit((string) ($step['title'][$lang] ?? $step['title']['en'] ?? ''), 255),
            'step_type'    => (string) $step['type'],
            'answer_value' => $value,
            'answer_label' => $value,
            'answer_json'  => null,
            'score'        => 0,
            'sort_order'   => $position,
        ];
    }

    /**
     * Runs an admin-configured pattern safely: the pattern body is wrapped by
     * us, so no delimiters or modifiers (like /e) can be injected.
     */
    private function matchesPattern(string $pattern, string $value): bool
    {
        $compiled = '/' . str_replace('/', '\/', $pattern) . '/u';
        $result   = @preg_match($compiled, $value);

        // An invalid pattern must not block a legitimate submission.
        return $result === false ? true : $result === 1;
    }

    /** @param array<string,mixed> $step */
    private function message(array $step, string $lang, string $fallback): string
    {
        $custom = $step['validation']['message'][$lang] ?? $step['validation']['message']['en'] ?? '';

        return is_string($custom) && trim($custom) !== '' ? $custom : $fallback;
    }

    /** @param array<string,mixed> $field */
    private function fieldLabel(array $field, string $lang): string
    {
        $label = $field['label'][$lang] ?? $field['label']['en'] ?? $field['key'];

        return (string) $label;
    }
}
