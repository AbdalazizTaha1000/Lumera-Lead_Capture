<?php

declare(strict_types=1);

namespace Lumera\Support;

/**
 * Stable internal step-type identifiers. The admin UI renders the labels;
 * the database and the API only ever use the keys.
 */
final class StepType
{
    public const SINGLE_SELECT       = 'single_select';
    public const MULTI_SELECT        = 'multi_select';
    public const SHORT_TEXT          = 'short_text';
    public const EMAIL               = 'email';
    public const PHONE               = 'phone';
    public const NUMBER              = 'number';
    public const DROPDOWN            = 'dropdown';
    public const CONTACT_INFORMATION = 'contact_information';
    public const CONSENT             = 'consent';
    public const INFORMATION         = 'information';

    /** @var array<string,string> */
    public const LABELS = [
        self::SINGLE_SELECT       => 'Single Select',
        self::MULTI_SELECT        => 'Multi Select',
        self::SHORT_TEXT          => 'Short Text',
        self::EMAIL               => 'Email',
        self::PHONE               => 'Phone',
        self::NUMBER              => 'Number',
        self::DROPDOWN            => 'Dropdown',
        self::CONTACT_INFORMATION => 'Contact Information',
        self::CONSENT             => 'Consent',
        self::INFORMATION         => 'Information Screen',
    ];

    /** Types whose answers come from a managed option list. */
    public const WITH_OPTIONS = [
        self::SINGLE_SELECT,
        self::MULTI_SELECT,
        self::DROPDOWN,
    ];

    /** Types that never produce an answer. */
    public const NON_ANSWERING = [
        self::INFORMATION,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(string $type): bool
    {
        return isset(self::LABELS[$type]);
    }

    public static function usesOptions(string $type): bool
    {
        return in_array($type, self::WITH_OPTIONS, true);
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    /** Selection types can auto-advance to the next step. */
    public static function supportsAutoAdvance(string $type): bool
    {
        return in_array($type, [self::SINGLE_SELECT, self::CONSENT, self::INFORMATION], true);
    }
}
