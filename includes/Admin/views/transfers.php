<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap tscout-wrap" dir="rtl">
    <div class="tscout-header">
        <div class="tscout-header-icon">📡</div>
        <div class="tscout-header-text">
            <h1>وضعیت انتقال‌ها</h1>
            <p>مدیریت صف پردازش و انتقال فایل‌ها</p>
        </div>
    </div>

    <div class="tscout-stats-grid">
        <div class="tscout-stat-card"><span class="stat-number"><?php echo intval($stats['queued']); ?></span><span class="stat-label">در صف</span></div>
        <div class="tscout-stat-card"><span class="stat-number"><?php echo intval($stats['processing']); ?></span><span class="stat-label">در حال پردازش</span></div>
        <div class="tscout-stat-card"><span class="stat-number"><?php echo intval($stats['completed']); ?></span><span class="stat-label">تکمیل شده</span></div>
        <div class="tscout-stat-card"><span class="stat-number"><?php echo intval($stats['failed']); ?></span><span class="stat-label">ناموفق</span></div>
    </div>

    <div class="tscout-card">
        <table class="widefat">
            <thead>
                <tr>
                    <th>شناسه Job</th>
                    <th>نوع</th>
                    <th>وضعیت</th>
                    <th>پیشرفت</th>
                    <th>تلاش‌ها</th>
                    <th>خطا</th>
                    <th>زمان ایجاد</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($jobs)): ?>
                <tr><td colspan="8">هیچ jobی یافت نشد</td></tr>
                <?php else: foreach ($jobs as $job): ?>
                <tr>
                    <td><code><?php echo esc_html($job['job_id']); ?></code></td>
                    <td><?php echo esc_html($job['job_type']); ?></td>
                    <td><span class="tscout-badge status-<?php echo esc_attr($job['status']); ?>"><?php echo esc_html($job['status']); ?></span></td>
                    <td>
                        <div class="tscout-progress-bar small"><div class="tscout-progress-fill" style="width: <?php echo intval($job['progress']); ?>%"></div></div>
                        <?php echo intval($job['progress']); ?>%
                    </td>
                    <td><?php echo intval($job['attempts']); ?>/<?php echo intval($job['max_attempts']); ?></td>
                    <td><?php echo esc_html(mb_substr($job['last_error'] ?? '', 0, 100)); ?></td>
                    <td><?php echo esc_html($job['created_at']); ?></td>
                    <td>
                        <?php if ($job['status'] === 'failed'): ?>
                        <button class="button button-small tscout-retry" data-id="<?php echo esc_attr($job['job_id']); ?>">تلاش مجدد</button>
                        <?php endif; ?>
                        <?php if (in_array($job['status'], array('queued', 'processing', 'failed'))): ?>
                        <button class="button button-small tscout-cancel" data-id="<?php echo esc_attr($job['job_id']); ?>">لغو</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('.tscout-retry').on('click', function() {
        var id = $(this).data('id');
        $.post(tscout_ajax.ajax_url, {
            action: 'tscout_retry_job',
            nonce: tscout_ajax.nonce,
            job_id: id
        }, function(response) {
            if (response.success) location.reload();
            else alert(response.data.message);
        });
    });
    $('.tscout-cancel').on('click', function() {
        var id = $(this).data('id');
        if (!confirm('لغو شود؟')) return;
        $.post(tscout_ajax.ajax_url, {
            action: 'tscout_cancel_job',
            nonce: tscout_ajax.nonce,
            job_id: id
        }, function(response) {
            if (response.success) location.reload();
            else alert(response.data.message);
        });
    });
});
</script>
