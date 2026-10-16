<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap tscout-wrap" dir="rtl">
    <div class="tscout-header">
        <div class="tscout-header-icon">🔍</div>
        <div class="tscout-header-text">
            <h1>کشف آثار موسیقی</h1>
            <p>نام هنرمند را وارد کنید تا آثار او را از منابع مختلف کشف کنیم</p>
        </div>
    </div>

    <div class="tscout-card">
        <div class="tscout-card-title">🎤 جستجوی هنرمند</div>
        
        <form id="tscout-search-form" class="tscout-form">
            <div class="tscout-grid-2">
                <div class="tscout-field">
                    <label>نام انگلیسی هنرمند <span class="req">*</span></label>
                    <input type="text" name="artist_en" id="artist_en" placeholder="مثال: Mohsen Yeganeh" required dir="ltr">
                    <p class="tscout-hint">نام اصلی هنرمند به انگلیسی، همانطور که در منابع خارجی ثبت شده</p>
                </div>
                <div class="tscout-field">
                    <label>نام‌های جایگزین (اختیاری)</label>
                    <input type="text" name="alt_names" placeholder="نام‌های مستعار با کاما جدا کنید">
                </div>
            </div>

            <div class="tscout-field">
                <label>انتخاب منابع</label>
                <div class="tscout-checkbox-group">
                    <?php foreach ($providers as $id => $provider): 
                        $is_enabled = isset($enabled[$id]);
                    ?>
                    <label class="tscout-checkbox">
                        <input type="checkbox" name="providers[]" value="<?php echo esc_attr($id); ?>" <?php checked($is_enabled); ?>>
                        <?php echo esc_html($provider->get_label()); ?> - <?php echo esc_html($provider->get_name()); ?>
                        <?php if (!$is_enabled): ?><small>(غیرفعال در تنظیمات)</small><?php endif; ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tscout-grid-3">
                <div class="tscout-field">
                    <label>نوع آثار</label>
                    <select name="types[]" multiple style="height: 120px;">
                        <option value="single" selected>تک‌آهنگ</option>
                        <option value="album">آلبوم</option>
                        <option value="remix">ریمیکس</option>
                        <option value="live">اجرای زنده</option>
                        <option value="collab">همکاری</option>
                        <option value="video">ویدیو</option>
                    </select>
                </div>
                <div class="tscout-field">
                    <label>تعداد نتایج</label>
                    <input type="number" name="limit" value="20" min="1" max="100">
                </div>
                <div class="tscout-field">
                    <label>بازه زمانی (اختیاری)</label>
                    <select name="year_range">
                        <option value="">همه سال‌ها</option>
                        <option value="2024-2025">2024-2025</option>
                        <option value="2020-2023">2020-2023</option>
                        <option value="2015-2019">2015-2019</option>
                    </select>
                </div>
            </div>

            <div class="tscout-submit-row">
                <button type="submit" class="button button-primary tscout-btn-primary">🔍 شروع جستجو</button>
                <span id="tscout-search-status" class="tscout-status"></span>
            </div>
        </form>
    </div>

    <div id="tscout-job-progress" class="tscout-card" style="display:none;">
        <div class="tscout-card-title">⏳ پیشرفت جستجو</div>
        <div class="tscout-progress-bar">
            <div class="tscout-progress-fill" id="tscout-progress-fill" style="width: 0%"></div>
        </div>
        <div id="tscout-progress-text">در حال جستجو...</div>
        <div id="tscout-job-log"></div>
    </div>

    <div class="tscout-card">
        <div class="tscout-card-title">💡 نکات</div>
        <ul>
            <li>نام انگلیسی را دقیق وارد کنید، مثلاً <code>Mohsen Yeganeh</code> نه <code>محسن یگانه</code></li>
            <li>جستجو ممکن است 1-2 دقیقه طول بکشد، لطفاً صبر کنید</li>
            <li>نتایج در صفحه <a href="<?php echo admin_url('admin.php?page=tscout-results'); ?>">نتایج کشف</a> نمایش داده می‌شوند</li>
            <li>برای منابع، فقط داده‌های مجاز دریافت می‌شود و هیچ محدودیتی دور زده نمی‌شود</li>
            <li>فایل‌های صوتی تا تأیید مجوز منتقل نمی‌شوند</li>
        </ul>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#tscout-search-form').on('submit', function(e) {
        e.preventDefault();
        
        var artist_en = $('#artist_en').val().trim();
        if (!artist_en) {
            alert('نام هنرمند الزامی است');
            return;
        }
        
        var providers = [];
        $('input[name="providers[]"]:checked').each(function() {
            providers.push($(this).val());
        });
        
        if (providers.length === 0) {
            alert('حداقل یک منبع را انتخاب کنید');
            return;
        }
        
        var data = {
            action: 'tscout_search',
            nonce: tscout_ajax.nonce,
            artist_en: artist_en,
            providers: providers,
            limit: $('input[name="limit"]').val(),
            types: $('select[name="types[]"]').val()
        };
        
        $('#tscout-search-status').text(tscout_ajax.i18n.searching);
        $('.tscout-btn-primary').prop('disabled', true);
        
        $.post(tscout_ajax.ajax_url, data, function(response) {
            if (response.success) {
                $('#tscout-search-status').text('✅ ' + response.data.message);
                $('#tscout-job-progress').show();
                monitorJob(response.data.job_id);
            } else {
                $('#tscout-search-status').text('❌ ' + response.data.message);
                $('.tscout-btn-primary').prop('disabled', false);
            }
        }).fail(function() {
            $('#tscout-search-status').text('❌ خطا در ارتباط');
            $('.tscout-btn-primary').prop('disabled', false);
        });
    });
    
    function monitorJob(jobId) {
        var interval = setInterval(function() {
            $.post(tscout_ajax.ajax_url, {
                action: 'tscout_job_status',
                nonce: tscout_ajax.nonce,
                job_id: jobId
            }, function(response) {
                if (response.success) {
                    var job = response.data;
                    $('#tscout-progress-fill').css('width', job.progress + '%');
                    $('#tscout-progress-text').text('وضعیت: ' + job.status + ' - پیشرفت: ' + job.progress + '%');
                    
                    if (job.status === 'completed') {
                        clearInterval(interval);
                        $('#tscout-progress-text').text('✅ جستجو تکمیل شد! به صفحه نتایج بروید.');
                        $('.tscout-btn-primary').prop('disabled', false);
                        setTimeout(function() {
                            window.location.href = '<?php echo admin_url('admin.php?page=tscout-results'); ?>';
                        }, 2000);
                    } else if (job.status === 'failed') {
                        clearInterval(interval);
                        $('#tscout-progress-text').text('❌ خطا: ' + (job.last_error || 'نامشخص'));
                        $('.tscout-btn-primary').prop('disabled', false);
                    }
                }
            });
        }, 3000);
    }
});
</script>
