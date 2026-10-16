<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap tscout-wrap" dir="rtl">
    <div class="tscout-header">
        <div class="tscout-header-icon">🔍</div>
        <div class="tscout-header-text">
            <h1>ترانه‌یار اسکات | داشبورد</h1>
            <p>کشف کن، تطبیق بده، هوشمندانه منتشر کن.</p>
        </div>
    </div>

    <div class="tscout-stats-grid">
        <div class="tscout-stat-card">
            <div class="stat-icon">🎵</div>
            <div class="stat-info">
                <span class="stat-number"><?php echo intval($discovery_stats['total'] ?? 0); ?></span>
                <span class="stat-label">کل آثار کشف شده</span>
            </div>
        </div>
        <div class="tscout-stat-card">
            <div class="stat-icon">✨</div>
            <div class="stat-info">
                <span class="stat-number"><?php echo intval($discovery_stats['not_published'] ?? 0); ?></span>
                <span class="stat-label">آماده انتشار</span>
            </div>
        </div>
        <div class="tscout-stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-info">
                <span class="stat-number"><?php echo intval($discovery_stats['published'] ?? 0); ?></span>
                <span class="stat-label">منتشر شده</span>
            </div>
        </div>
        <div class="tscout-stat-card">
            <div class="stat-icon">⚠️</div>
            <div class="stat-info">
                <span class="stat-number"><?php echo intval($discovery_stats['needs_review'] ?? 0); ?></span>
                <span class="stat-label">نیاز به بررسی مجوز</span>
            </div>
        </div>
    </div>

    <div class="tscout-grid-2">
        <div class="tscout-card">
            <div class="tscout-card-title">📡 وضعیت صف</div>
            <div class="tscout-queue-stats">
                <div>در صف: <strong><?php echo intval($stats['queued'] ?? 0); ?></strong></div>
                <div>در حال پردازش: <strong><?php echo intval($stats['processing'] ?? 0); ?></strong></div>
                <div>موفق: <strong><?php echo intval($stats['completed'] ?? 0); ?></strong></div>
                <div>ناموفق: <strong><?php echo intval($stats['failed'] ?? 0); ?></strong></div>
            </div>
            <p><a href="<?php echo admin_url('admin.php?page=tscout-transfers'); ?>" class="button">مشاهده صف</a></p>
        </div>

        <div class="tscout-card">
            <div class="tscout-card-title">🔌 ارائه‌دهندگان</div>
            <ul class="tscout-provider-list">
                <?php foreach ($providers as $id => $provider): 
                    $is_enabled = isset($enabled_providers[$id]);
                    $status = $provider->get_status();
                ?>
                <li>
                    <span class="provider-name"><?php echo esc_html($provider->get_label()); ?> (<?php echo esc_html($provider->get_name()); ?>)</span>
                    <span class="provider-status <?php echo $is_enabled ? 'enabled' : 'disabled'; ?>">
                        <?php echo $is_enabled ? 'فعال' : 'غیرفعال'; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <p><a href="<?php echo admin_url('admin.php?page=tscout-settings'); ?>" class="button">تنظیمات ارائه‌دهندگان</a></p>
        </div>
    </div>

    <div class="tscout-card">
        <div class="tscout-card-title">🚀 شروع سریع</div>
        <ol>
            <li>به بخش <a href="<?php echo admin_url('admin.php?page=tscout-discovery'); ?>">کشف آثار</a> بروید.</li>
            <li>نام انگلیسی خواننده را وارد کنید، مثلاً <code>Mohsen Yeganeh</code></li>
            <li>منابع جستجو را انتخاب و جستجو را اجرا کنید.</li>
            <li>نتایج را در <a href="<?php echo admin_url('admin.php?page=tscout-results'); ?>">صفحه نتایج</a> بررسی و تطبیق دهید.</li>
            <li>پس از تأیید مجوز، آثار را منتشر کنید.</li>
        </ol>
    </div>

    <div class="tscout-card tscout-about-card">
        <div class="tscout-card-title">👨‍💻 درباره افزونه</div>
        <p>
            <strong>ترانه‌یار اسکات</strong> نسخه <?php echo esc_html(TSCOUT_VERSION); ?> — ابزار هوشمند کشف و مدیریت آثار موسیقی<br>
            توسعه‌دهنده: <a href="https://etehadwp.com/" target="_blank">اتحاد وردپرس</a><br>
            شعار: کشف کن، تطبیق بده، هوشمندانه منتشر کن.
        </p>
        <p><small>این افزونه مکمل ترانه‌یار و ترانه‌یار HostDL است و به صورت مستقل عمل می‌کند.</small></p>
    </div>
</div>
