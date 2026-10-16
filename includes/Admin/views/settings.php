<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap tscout-wrap" dir="rtl">
    <div class="tscout-header">
        <div class="tscout-header-icon">⚙️</div>
        <div class="tscout-header-text">
            <h1>تنظیمات ترانه‌یار اسکات</h1>
            <p>پیکربندی ارائه‌دهندگان، تطبیق و سیاست‌های مجوز</p>
        </div>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field('tscout_settings'); ?>
        
        <div class="tscout-card">
            <div class="tscout-card-title">🎯 تنظیمات تطبیق</div>
            <table class="form-table">
                <tr>
                    <th>آستانه تطبیق دقیق (%)</th>
                    <td><input type="number" name="matching_threshold_exact" value="<?php echo intval($options['matching_threshold_exact'] ?? 95); ?>" min="0" max="100"></td>
                </tr>
                <tr>
                    <th>آستانه تطبیق بالا (%)</th>
                    <td><input type="number" name="matching_threshold_high" value="<?php echo intval($options['matching_threshold_high'] ?? 80); ?>" min="0" max="100"></td>
                </tr>
                <tr>
                    <th>آستانه تطبیق متوسط (%)</th>
                    <td><input type="number" name="matching_threshold_medium" value="<?php echo intval($options['matching_threshold_medium'] ?? 60); ?>" min="0" max="100"></td>
                </tr>
            </table>
        </div>

        <div class="tscout-card">
            <div class="tscout-card-title">🔍 تنظیمات جستجو</div>
            <table class="form-table">
                <tr>
                    <th>حداکثر نتایج هر جستجو</th>
                    <td><input type="number" name="max_results_per_search" value="<?php echo intval($options['max_results_per_search'] ?? 50); ?>" min="1" max="100"></td>
                </tr>
                <tr>
                    <th>timeout درخواست (ثانیه)</th>
                    <td><input type="number" name="request_timeout" value="<?php echo intval($options['request_timeout'] ?? 30); ?>" min="10" max="60"></td>
                </tr>
                <tr>
                    <th>پاک‌سازی خودکار پس از (روز)</th>
                    <td><input type="number" name="cleanup_days" value="<?php echo intval($options['cleanup_days'] ?? 30); ?>" min="1" max="365"></td>
                </tr>
                <tr>
                    <th>اجازه URL خارجی</th>
                    <td><label><input type="checkbox" name="allow_external_urls" value="1" <?php checked($options['allow_external_urls'] ?? 0, 1); ?>> اگر هاست دانلود در دسترس نباشد، از URL خارجی استفاده شود (فقط در صورت داشتن مجوز)</label></td>
                </tr>
                <tr>
                    <th>فعال‌سازی ارائه‌دهنده آزمایشی</th>
                    <td><label><input type="checkbox" name="enable_mock_provider" value="1" <?php checked($options['enable_mock_provider'] ?? 0, 1); ?>> Mock Provider برای تست</label></td>
                </tr>
            </table>
        </div>

        <div class="tscout-card">
            <div class="tscout-card-title">🔌 سیاست ارائه‌دهندگان</div>
            <p class="tscout-hint">برای هر ارائه‌دهنده، وضعیت فعال‌سازی و سیاست مجوز را مشخص کنید.</p>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>ارائه‌دهنده</th>
                        <th>فعال</th>
                        <th>وضعیت مجوز پیش‌فرض</th>
                        <th>اجازه دانلود صوتی</th>
                        <th>اجازه دانلود کاور</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($providers as $id => $provider): 
                        $policies = $options['provider_policies'][$id] ?? array();
                        $enabled = $policies['enabled'] ?? ($id === 'mock' ? 0 : 1);
                        $license = $policies['license_status'] ?? 'needs_review';
                        $allow_audio = $policies['allow_audio_download'] ?? 0;
                        $allow_cover = $policies['allow_cover_download'] ?? 1;
                    ?>
                    <tr>
                        <td><?php echo esc_html($provider->get_label()); ?> (<?php echo esc_html($provider->get_name()); ?>)</td>
                        <td><input type="checkbox" name="provider_enabled_<?php echo esc_attr($id); ?>" value="1" <?php checked($enabled, 1); ?>></td>
                        <td>
                            <select name="provider_license_<?php echo esc_attr($id); ?>">
                                <option value="verified" <?php selected($license, 'verified'); ?>>تأیید شده</option>
                                <option value="needs_review" <?php selected($license, 'needs_review'); ?>>نیاز به بررسی</option>
                                <option value="metadata_only" <?php selected($license, 'metadata_only'); ?>>فقط متادیتا</option>
                                <option value="file_allowed_not_verified" <?php selected($license, 'file_allowed_not_verified'); ?>>فایل مجاز، تأیید نشده</option>
                                <option value="forbidden" <?php selected($license, 'forbidden'); ?>>ممنوع</option>
                            </select>
                        </td>
                        <td><input type="checkbox" name="provider_allow_audio_<?php echo esc_attr($id); ?>" value="1" <?php checked($allow_audio, 1); ?>></td>
                        <td><input type="checkbox" name="provider_allow_cover_<?php echo esc_attr($id); ?>" value="1" <?php checked($allow_cover, 1); ?>></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="tscout-hint">
                <strong>توضیح وضعیت مجوز:</strong><br>
                - تأیید شده: استفاده و بازنشر مجاز است<br>
                - نیاز به بررسی: باید مجوز بررسی شود<br>
                - فقط متادیتا: فقط اطلاعات متنی قابل استفاده است<br>
                - فایل مجاز، تأیید نشده: فایل قابل دریافت است اما بازنشر تأیید نشده<br>
                - ممنوع: استفاده ممنوع
            </p>
        </div>

        <p class="submit">
            <button type="submit" name="tscout_save_settings" class="button button-primary">💾 ذخیره تنظیمات</button>
        </p>
    </form>
</div>
