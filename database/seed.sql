-- ---------------------------------------------------------------------------
-- Lumera Lead Capture — initial funnel seed ("Lumera Property Finder")
-- Idempotent: safe to re-run. Does NOT create an admin user (see README).
-- After seeding, publish version 1:  php bin/console.php funnel:publish
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- ------------------------------------------------------------------ funnel --
INSERT INTO `funnels`
    (`id`, `slug`, `name`, `company_name`, `status`, `default_language`, `enabled_languages`,
     `primary_color`, `accent_color`, `background_color`,
     `submit_label_en`, `submit_label_ar`,
     `success_title_en`, `success_title_ar`,
     `success_message_en`, `success_message_ar`,
     `success_button_en`, `success_button_ar`,
     `whatsapp_enabled`, `whatsapp_label_en`, `whatsapp_label_ar`,
     `progress_bar_enabled`, `step_counter_enabled`, `back_button_enabled`,
     `save_progress_enabled`, `min_completion_seconds`, `draft_updated_at`)
VALUES
    (1, 'property-finder', 'Lumera Property Finder', 'Lumera Dubai Real Estate', 'active', 'en', 'en,ar',
     '#0F2E4C', '#C9A227', '#F7F8FA',
     'Submit', 'إرسال',
     'Thank you', 'شكراً لك',
     'Your preferences have been received. A Lumera property consultant will contact you shortly with suitable options.',
     'تم استلام تفضيلاتك. سيتواصل معك أحد مستشاري لوميرا العقاريين قريباً لمشاركة الخيارات المناسبة.',
     'Done', 'تم',
     1, 'Chat on WhatsApp', 'تواصل عبر واتساب',
     1, 1, 1, 1, 5, NOW())
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ------------------------------------------------------------------- steps --
INSERT INTO `funnel_steps`
    (`id`, `funnel_id`, `step_key`, `step_type`, `title_en`, `title_ar`,
     `description_en`, `description_ar`, `is_required`, `is_active`,
     `auto_advance`, `sort_order`)
VALUES
    (1, 1, 'property_purpose', 'single_select',
     'What are you looking for?', 'ما الذي تبحث عنه؟',
     NULL, NULL, 1, 1, 1, 1),
    (2, 1, 'property_type', 'single_select',
     'Which property type interests you?', 'ما نوع العقار الذي تهتم به؟',
     NULL, NULL, 1, 1, 1, 2),
    (3, 1, 'budget', 'single_select',
     'What is your estimated budget?', 'ما ميزانيتك التقريبية؟',
     NULL, NULL, 1, 1, 1, 3),
    (4, 1, 'preferred_location', 'single_select',
     'Which location do you prefer?', 'ما المنطقة التي تفضلها؟',
     NULL, NULL, 1, 1, 1, 4),
    (5, 1, 'contact_information', 'contact_information',
     'Where should we send the available options?', 'أين نرسل لك الخيارات المتاحة؟',
     NULL, NULL, 1, 1, 0, 5),
    (6, 1, 'privacy_consent', 'consent',
     'I agree to be contacted by Lumera Dubai Real Estate regarding properties and investment opportunities.',
     'أوافق على تواصل شركة لوميرا دبي العقارية معي بخصوص العقارات والفرص الاستثمارية.',
     NULL, NULL, 1, 1, 0, 6)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ----------------------------------------------------------------- options --
INSERT INTO `funnel_step_options`
    (`step_id`, `option_value`, `label_en`, `label_ar`, `score`, `sort_order`, `is_active`)
VALUES
    -- Step 1 : property_purpose
    (1, 'buy',        'Buy a Property', 'شراء عقار',        15, 1, 1),
    (1, 'invest',     'Invest',         'الاستثمار',        20, 2, 1),
    (1, 'rent',       'Rent',           'الاستئجار',         5, 3, 1),
    (1, 'exploring',  'Just Exploring', 'أستكشف الخيارات',   0, 4, 1),

    -- Step 2 : property_type
    (2, 'studio',              'Studio',      'استوديو',              0, 1, 1),
    (2, 'one_bedroom',         '1 Bedroom',   'غرفة نوم واحدة',       0, 2, 1),
    (2, 'two_bedrooms',        '2 Bedrooms',  'غرفتا نوم',            0, 3, 1),
    (2, 'three_plus_bedrooms', '3+ Bedrooms', '3 غرف نوم أو أكثر',    5, 4, 1),
    (2, 'villa',               'Villa',       'فيلا',                10, 5, 1),
    (2, 'townhouse',           'Townhouse',   'تاون هاوس',            5, 6, 1),

    -- Step 3 : budget
    (3, 'under_750k', 'Under AED 750K', 'أقل من 750 ألف درهم',            0, 1, 1),
    (3, '750k_1m',    'AED 750K – 1M',  'من 750 ألف إلى مليون درهم',      5, 2, 1),
    (3, '1m_2m',      'AED 1M – 2M',    'من مليون إلى مليوني درهم',      10, 3, 1),
    (3, '2m_5m',      'AED 2M – 5M',    'من مليوني إلى 5 ملايين درهم',   20, 4, 1),
    (3, 'above_5m',   'Above AED 5M',   'أكثر من 5 ملايين درهم',         30, 5, 1),

    -- Step 4 : preferred_location
    (4, 'dubai_marina',   'Dubai Marina',            'دبي مارينا',              0, 1, 1),
    (4, 'downtown_dubai', 'Downtown Dubai',          'وسط مدينة دبي',           0, 2, 1),
    (4, 'business_bay',   'Business Bay',            'الخليج التجاري',          0, 3, 1),
    (4, 'jvc',            'Jumeirah Village Circle', 'قرية جميرا الدائرية',      0, 4, 1),
    (4, 'dubai_hills',    'Dubai Hills Estate',      'دبي هيلز استيت',          0, 5, 1),
    (4, 'dubai_south',    'Dubai South',             'دبي الجنوب',              0, 6, 1),
    (4, 'no_preference',  'No Preference',           'لا يوجد تفضيل',           0, 7, 1)
ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`);

-- --------------------------------------------------------- contact fields --
INSERT INTO `funnel_contact_fields`
    (`funnel_id`, `field_key`, `field_type`, `label_en`, `label_ar`,
     `placeholder_en`, `placeholder_ar`, `is_required`, `is_active`, `is_system`,
     `sort_order`, `max_length`, `choices`)
VALUES
    (1, 'full_name', 'text', 'Full Name', 'الاسم الكامل',
     'Enter your full name', 'أدخل اسمك الكامل', 1, 1, 1, 1, 120, NULL),

    (1, 'country_code', 'country_code', 'Country Code', 'رمز الدولة',
     '+971', '+971', 1, 1, 1, 2, 8, NULL),

    (1, 'phone', 'tel', 'Phone Number', 'رقم الهاتف',
     '50 123 4567', '50 123 4567', 1, 1, 1, 3, 20, NULL),

    (1, 'email', 'email', 'Email Address', 'البريد الإلكتروني',
     'you@example.com', 'you@example.com', 0, 1, 1, 4, 190, NULL),

    (1, 'preferred_language', 'select', 'Preferred Language', 'اللغة المفضلة',
     NULL, NULL, 1, 1, 1, 5, NULL,
     '[{"value":"english","label_en":"English","label_ar":"الإنجليزية"},{"value":"arabic","label_en":"Arabic","label_ar":"العربية"}]'),

    (1, 'nationality', 'text', 'Nationality', 'الجنسية',
     'e.g. United Arab Emirates', 'مثال: الإمارات العربية المتحدة', 0, 0, 0, 6, 100, NULL),

    (1, 'preferred_contact_method', 'select', 'Preferred Contact Method', 'طريقة التواصل المفضلة',
     NULL, NULL, 0, 0, 0, 7, NULL,
     '[{"value":"phone","label_en":"Phone Call","label_ar":"مكالمة هاتفية"},{"value":"whatsapp","label_en":"WhatsApp","label_ar":"واتساب"},{"value":"email","label_en":"Email","label_ar":"البريد الإلكتروني"}]')
ON DUPLICATE KEY UPDATE `field_key` = VALUES(`field_key`);

-- ---------------------------------------------------------- app settings ---
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `is_public`) VALUES
    ('company_name',                'Lumera Dubai Real Estate', 'string', 1),
    ('company_logo',                '',                          'string', 1),
    ('admin_interface_language',    'en',                        'string', 0),
    ('timezone',                    'Asia/Dubai',                'string', 0),
    ('privacy_policy_url',          '',                          'string', 1),
    ('notification_subject_template','New Lead #{lead_id} — {full_name} ({purpose})', 'string', 0)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
