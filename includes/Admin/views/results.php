<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap tscout-wrap" dir="rtl">
    <div class="tscout-header">
        <div class="tscout-header-icon">📋</div>
        <div class="tscout-header-text">
            <h1>نتایج کشف آثار</h1>
            <p>بررسی، تطبیق و انتشار آثار کشف شده</p>
        </div>
    </div>

    <div class="tscout-filters">
        <form method="get" action="">
            <input type="hidden" name="page" value="tscout-results">
            <div class="tscout-filter-row">
                <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="جستجو عنوان یا هنرمند...">
                <select name="status">
                    <option value="">همه وضعیت‌ها</option>
                    <option value="not_published" <?php selected($filter_status, 'not_published'); ?>>منتشر نشده</option>
                    <option value="published" <?php selected($filter_status, 'published'); ?>>منتشر شده</option>
                    <option value="possible_duplicate" <?php selected($filter_status, 'possible_duplicate'); ?>>احتمال تکراری</option>
                    <option value="needs_manual_review" <?php selected($filter_status, 'needs_manual_review'); ?>>نیاز به بررسی</option>
                    <option value="draft" <?php selected($filter_status, 'draft'); ?>>پیش‌نویس</option>
                </select>
                <select name="provider">
                    <option value="">همه منابع</option>
                    <?php foreach ($providers as $id => $provider): ?>
                    <option value="<?php echo esc_attr($id); ?>" <?php selected($filter_provider, $id); ?>><?php echo esc_html($provider->get_label()); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">فیلتر</button>
                <a href="<?php echo admin_url('admin.php?page=tscout-results'); ?>" class="button">پاک کردن</a>
            </div>
        </form>
    </div>

    <div class="tscout-bulk-actions">
        <select id="bulk-action-select">
            <option value="">عملیات گروهی...</option>
            <option value="match">تطبیق با آرشیو</option>
            <option value="ignore">نادیده گرفتن</option>
            <?php if (current_user_can('manage_options')): ?>
            <option value="delete">حذف</option>
            <?php endif; ?>
        </select>
        <button id="bulk-action-btn" class="button">اجرا</button>
        <span class="tscout-hint"><?php echo $total; ?> اثر یافت شد</span>
    </div>

    <div class="tscout-card">
        <table class="widefat tscout-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all"></th>
                    <th>عنوان</th>
                    <th>هنرمند</th>
                    <th>منبع</th>
                    <th>نوع</th>
                    <th>وضعیت تطبیق</th>
                    <th>مجوز</th>
                    <th>اطمینان</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($results)): ?>
                <tr><td colspan="9">هیچ اثری یافت نشد. <a href="<?php echo admin_url('admin.php?page=tscout-discovery'); ?>">جستجوی جدید</a></td></tr>
                <?php else: foreach ($results as $row): 
                    $match_label = '';
                    $match_color = '#6b7280';
                    switch ($row['match_status']) {
                        case 'published': $match_label = 'منتشر شده'; $match_color = '#10b981'; break;
                        case 'draft': $match_label = 'پیش‌نویس'; $match_color = '#f59e0b'; break;
                        case 'possible_duplicate': $match_label = 'احتمال تکراری'; $match_color = '#ef4444'; break;
                        case 'not_published': $match_label = 'منتشر نشده'; $match_color = '#3b82f6'; break;
                        case 'needs_manual_review': $match_label = 'نیاز به بررسی'; $match_color = '#8b5cf6'; break;
                        default: $match_label = 'نامشخص'; $match_color = '#6b7280';
                    }
                    $license_labels = array(
                        'verified' => 'تأیید شده',
                        'needs_review' => 'نیاز به بررسی',
                        'metadata_only' => 'فقط متادیتا',
                        'file_allowed_not_verified' => 'فایل مجاز، تأیید نشده',
                        'forbidden' => 'ممنوع'
                    );
                    $license_label = isset($license_labels[$row['license_status']]) ? $license_labels[$row['license_status']] : $row['license_status'];
                ?>
                <tr data-id="<?php echo intval($row['id']); ?>">
                    <td><input type="checkbox" class="row-checkbox" value="<?php echo intval($row['id']); ?>"></td>
                    <td>
                        <strong><?php echo esc_html($row['title']); ?></strong>
                        <?php if (!empty($row['title_fa']) && $row['title_fa'] !== $row['title']): ?>
                        <br><small><?php echo esc_html($row['title_fa']); ?></small>
                        <?php endif; ?>
                        <br><small><a href="<?php echo esc_url($row['source_url']); ?>" target="_blank">منبع</a></small>
                    </td>
                    <td><?php echo esc_html($row['artist_en']); ?><?php if (!empty($row['artist_fa'])) echo '<br><small>' . esc_html($row['artist_fa']) . '</small>'; ?></td>
                    <td><?php echo esc_html($row['provider']); ?></td>
                    <td>
                        <?php echo esc_html($row['track_type']); ?>
                        <?php if ($row['is_remix']) echo '<br><small>ریمیکس</small>'; ?>
                        <?php if ($row['is_live']) echo '<br><small>زنده</small>'; ?>
                    </td>
                    <td><span class="tscout-badge" style="background: <?php echo esc_attr($match_color); ?>"><?php echo esc_html($match_label); ?></span>
                        <?php if (!empty($row['matched_post_id'])): ?>
                        <br><small><a href="<?php echo get_edit_post_link($row['matched_post_id']); ?>" target="_blank">#<?php echo intval($row['matched_post_id']); ?></a></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="tscout-badge license-<?php echo esc_attr($row['license_status']); ?>"><?php echo esc_html($license_label); ?></span></td>
                    <td><?php echo esc_html($row['confidence']); ?>%</td>
                    <td>
                        <button class="button button-small tscout-preview-btn" data-id="<?php echo intval($row['id']); ?>">پیش‌نمایش</button>
                        <?php if ($row['match_status'] === 'not_published'): ?>
                        <button class="button button-primary button-small tscout-publish-btn" data-id="<?php echo intval($row['id']); ?>">انتشار</button>
                        <?php elseif ($row['match_status'] === 'needs_manual_review' || $row['match_status'] === 'possible_duplicate'): ?>
                        <button class="button button-small tscout-publish-btn" data-id="<?php echo intval($row['id']); ?>">بررسی</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => 'قبلی',
                    'next_text' => 'بعدی',
                    'total' => $total_pages,
                    'current' => $page
                ));
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="tscout-preview-modal" class="tscout-modal" style="display:none;">
    <div class="tscout-modal-content">
        <span class="tscout-modal-close">&times;</span>
        <div id="tscout-preview-body">در حال بارگذاری...</div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    $('#select-all').on('change', function() {
        $('.row-checkbox').prop('checked', $(this).prop('checked'));
    });

    $('#bulk-action-btn').on('click', function() {
        var action = $('#bulk-action-select').val();
        var ids = [];
        $('.row-checkbox:checked').each(function() {
            ids.push($(this).val());
        });
        
        if (!action) {
            alert('عملیات را انتخاب کنید');
            return;
        }
        if (ids.length === 0) {
            alert('حداقل یک مورد را انتخاب کنید');
            return;
        }
        
        if (!confirm('آیا از انجام عملیات گروهی اطمینان دارید؟')) return;
        
        $.post(tscout_ajax.ajax_url, {
            action: 'tscout_bulk_action',
            nonce: tscout_ajax.nonce,
            bulk_action: action,
            ids: ids
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data.message);
            }
        });
    });

    $('.tscout-preview-btn').on('click', function() {
        var id = $(this).data('id');
        $('#tscout-preview-modal').show();
        $('#tscout-preview-body').html('در حال بارگذاری...');
        
        $.post(tscout_ajax.ajax_url, {
            action: 'tscout_publish_preview',
            nonce: tscout_ajax.nonce,
            discovery_id: id
        }, function(response) {
            if (response.success) {
                var data = response.data;
                var html = '<h2>پیش‌نمایش انتشار</h2>';
                html += '<h3>' + data.post_data.title + '</h3>';
                html += '<p><strong>هنرمند:</strong> ' + data.discovery.artist_en + '</p>';
                html += '<p><strong>وضعیت تطبیق:</strong> ' + data.match.label + ' (' + data.match.similarity + '%)</p>';
                html += '<p><strong>دلیل:</strong> ' + data.match.reason + '</p>';
                html += '<p><strong>مجوز:</strong> ' + data.license_status + '</p>';
                html += '<p><strong>فایل 320:</strong> ' + (data.file_status.has_320 ? '✅ دارد' : '❌ ندارد') + '</p>';
                html += '<p><strong>فایل 128:</strong> ' + (data.file_status.has_128 ? '✅ دارد' : '❌ ندارد') + '</p>';
                
                if (data.warnings.length > 0) {
                    html += '<div class="notice notice-warning"><ul>';
                    data.warnings.forEach(function(w) {
                        html += '<li>' + w + '</li>';
                    });
                    html += '</ul></div>';
                }
                
                html += '<div class="tscout-preview-content">' + data.post_data.content + '</div>';
                
                if (data.can_publish) {
                    html += '<button class="button button-primary tscout-modal-publish" data-id="' + data.discovery.id + '">تأیید و انتشار</button>';
                } else {
                    html += '<p>این اثر قابل انتشار نیست یا نیاز به تأیید مجوز دارد.</p>';
                    if (data.discovery.license_status === 'needs_review') {
                        html += '<label><input type="checkbox" id="license-approved"> مجوز را تأیید می‌کنم</label><br>';
                        html += '<button class="button button-primary tscout-modal-publish" data-id="' + data.discovery.id + '">انتشار با تأیید مجوز</button>';
                    }
                }
                
                $('#tscout-preview-body').html(html);
            } else {
                $('#tscout-preview-body').html('خطا: ' + response.data.message);
            }
        });
    });

    $('.tscout-publish-btn').on('click', function() {
        var id = $(this).data('id');
        if (!confirm(tscout_ajax.i18n.confirm_publish)) return;
        
        $.post(tscout_ajax.ajax_url, {
            action: 'tscout_publish',
            nonce: tscout_ajax.nonce,
            discovery_id: id,
            publish_status: 'draft',
            license_approved: false
        }, function(response) {
            if (response.success) {
                alert('✅ منتشر شد! پست #' + response.data.post_id);
                location.reload();
            } else {
                alert('❌ ' + response.data.message);
            }
        });
    });

    $(document).on('click', '.tscout-modal-publish', function() {
        var id = $(this).data('id');
        var license_approved = $('#license-approved').is(':checked');
        
        $.post(tscout_ajax.ajax_url, {
            action: 'tscout_publish',
            nonce: tscout_ajax.nonce,
            discovery_id: id,
            publish_status: 'draft',
            license_approved: license_approved
        }, function(response) {
            if (response.success) {
                alert('✅ منتشر شد!');
                $('#tscout-preview-modal').hide();
                location.reload();
            } else {
                alert('❌ ' + response.data.message);
            }
        });
    });

    $('.tscout-modal-close').on('click', function() {
        $('#tscout-preview-modal').hide();
    });
});
</script>
