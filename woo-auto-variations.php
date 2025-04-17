<?php
/**
 * Plugin Name: Woo Auto Variations
 * Plugin URI: https://github.com/daunampc/Woo-Auto-Variations
 * Description: Tự động tạo biến thể sản phẩm theo Color và Size, hỗ trợ bulk action, pagination, AJAX, và cập nhật tự động từ GitHub.
 * Version: 2.2.9
 * Author: DNPC
 * Author URI: https://github.com/daunampc/Woo-Auto-Variations
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/daunampc/Woo-Auto-Variations
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page(
        'Auto Variations',
        'Auto Variations',
        'manage_woocommerce',
        'woo-auto-variations-paginate',
        'woo_variations_admin_page',
        'dashicons-editor-table',
        56
    );
});
add_action('admin_post_bulk_generate_selected', function () {
    if (!current_user_can('manage_woocommerce') || !isset($_POST['product_ids']) || !is_array($_POST['product_ids'])) {
        wp_die('Permission denied or no product IDs provided.');
    }

    foreach ($_POST['product_ids'] as $product_id) {
        woo_create_variations_from_existing_terms(intval($product_id));
    }
    wp_redirect(admin_url('admin.php?page=woo-auto-variations-paginate'));
    exit;
});
add_action('admin_post_remove_variations', function () {
    if (!current_user_can('manage_woocommerce') || !isset($_POST['product_ids']) || !is_array($_POST['product_ids'])) {
        wp_die('Permission denied or no product IDs provided.');
    }

    check_admin_referer('woo_bulk_remove_variations');

    foreach ($_POST['product_ids'] as $product_id) {
        $product = wc_get_product($product_id);
        if ($product && $product->get_type() === 'variable') {
            foreach ($product->get_children() as $child_id) {
                wp_delete_post($child_id, true);
            }
            delete_post_meta($product_id, '_product_attributes');
            wp_set_object_terms($product_id, 'simple', 'product_type');
        }
    }
    wp_redirect(admin_url('admin.php?page=woo-auto-variations-paginate'));
    exit;
});

add_action('admin_footer', function () {
    ?>
    <style>
        .woo-loading-spinner { display: none; margin-left: 10px; }
        .woo-is-loading .woo-loading-spinner { display: inline-block; }
        #woo-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 18px;
            background: #23282d;
            color: #fff;
            border-radius: 4px;
            z-index: 9999;
            display: none;
        }
        #woo-overlay-loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            z-index: 9998;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }
        #woo-overlay-loader .spinner {
            border: 6px solid #f3f3f3;
            border-top: 6px solid #007cba;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin-bottom: 10px;
        }
        #woo-overlay-loader .status-text {
            color: white;
            font-weight: 600;
            font-size: 14px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
    <div id="woo-toast"></div>
    <div id="woo-overlay-loader">
        <div class="spinner"></div>
        <div class="status-text" id="woo-status-text">Đang xử lý...</div>
    </div>
 	<script>
       document.addEventListener("DOMContentLoaded", function () {
            const removeForm = document.getElementById("remove-variations-form");
            const mainForm = document.getElementById("variation-form");
            const overlay = document.getElementById("woo-overlay-loader");

            if (removeForm && mainForm && overlay) {
                removeForm.addEventListener("submit", function (e) {
                    const selected = mainForm.querySelectorAll("input[name='product_ids[]']:checked");
                    if (!selected.length) {
                        e.preventDefault();
                        alert("Vui lòng chọn ít nhất 1 sản phẩm để xoá biến thể.");
                        return;
                    }

                    if (!confirm("Bạn có chắc muốn xóa toàn bộ biến thể và chuyển về sản phẩm đơn?")) {
                        e.preventDefault();
                        return;
                    }

                    // Hiện loading overlay
                    overlay.style.display = "flex";

                    removeForm.querySelectorAll("input[name='product_ids[]']").forEach(el => el.remove());

                    selected.forEach(cb => {
                        const input = document.createElement("input");
                        input.type = "hidden";
                        input.name = "product_ids[]";
                        input.value = cb.value;
                        removeForm.appendChild(input);
                    });
                });
            }
        });
    </script>
    <script>
        function wooShowToast(message) {
            const toast = document.getElementById("woo-toast");
            toast.textContent = message;
            toast.style.display = "block";
            setTimeout(function () { toast.style.display = "none"; }, 3000);
        }

        function wooShowLocalLoader(show, text = '') {
            let overlay = document.getElementById("woo-overlay-loader");
            let status = document.getElementById("woo-status-text");
            overlay.style.display = show ? "flex" : "none";
            if (text && status) status.textContent = text;
        }
		
        document.addEventListener("DOMContentLoaded", function () {
            const actionButtons = document.querySelectorAll("a.button");
            actionButtons.forEach(function (btn) {
                if (btn.textContent.includes('Tạo') && btn.href.includes('&generate_for=')) {
                    btn.addEventListener("click", async function (e) {
                        e.preventDefault();
                        btn.classList.add("woo-is-loading");
                        wooShowLocalLoader(true, "Đang xử lý sản phẩm...");

                        await fetch(btn.href)
                            .then(() => wooShowToast("✅ Đã tạo xong"))
                            .catch(() => wooShowToast("❌ Lỗi khi tạo biến thể"));

                        wooShowLocalLoader(false);
                        setTimeout(() => location.reload(), 1000);
                    });
                }
            });

            const bulkForm = document.querySelector("form[method='post']");
            if (bulkForm) {
                const generateSelectedBtn = document.createElement("button");
                generateSelectedBtn.type = "submit";
                generateSelectedBtn.name = "bulk_generate_selected";
                generateSelectedBtn.className = "button button-secondary";
                generateSelectedBtn.textContent = "Tạo biến thể cho sản phẩm đã chọn";
                bulkForm.insertBefore(generateSelectedBtn, bulkForm.querySelector(".button-primary"));

                bulkForm.addEventListener("submit", async function (e) {
                    if (!e.submitter || e.submitter.name !== 'bulk_generate_selected') return;

                    e.preventDefault();
                    const checkboxes = bulkForm.querySelectorAll("input[name='product_ids[]']:checked");
                    if (!checkboxes.length) {
                        alert("Chọn ít nhất 1 sản phẩm");
                        return;
                    }

                    if (!confirm("Bạn có chắc chắn muốn tạo biến thể cho " + checkboxes.length + " sản phẩm đã chọn?")) {
                        return;
                    }

                    wooShowLocalLoader(true, "Đang bắt đầu xử lý...");

                    const button = e.submitter;
                    button.disabled = true;
                    button.classList.add("woo-is-loading");
                    let count = 0;

                    for (const checkbox of checkboxes) {
                        const productId = checkbox.value;
                        wooShowLocalLoader(true, `Đang xử lý sản phẩm ${count + 1}/${checkboxes.length}`);
                        await fetch(window.location.href + '&generate_for=' + productId)
                            .then(() => {
                                count++;
                                wooShowToast('Đã tạo ' + count + '/' + checkboxes.length);
                            })
                            .catch(() => wooShowToast('Lỗi với sản phẩm ID ' + productId));
                        await new Promise(function (r) { setTimeout(r, 1000); });
                    }

                    button.disabled = false;
                    button.classList.remove("woo-is-loading");
                    wooShowLocalLoader(false);
                    wooShowToast("✅ Đã hoàn tất tạo biến thể cho sản phẩm được chọn.");
                    setTimeout(function () { location.reload(); }, 2000);
                });
            }
        });
    </script>
    <?php
});



function woo_variations_admin_page()
{
    
   $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20;
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $filter_no_variations = isset($_GET['filter_no_variations']) ? true : false;

    if (isset($_GET['generate_for'])) {
        $product_id = intval($_GET['generate_for']);
        woo_create_variations_from_existing_terms($product_id);
    }

    $args = [
        'post_type' => 'product',
        'posts_per_page' => $per_page,
        'paged' => $paged,
        'post_status' => 'publish',
        'orderby' => 'ID',
        'order' => 'DESC',
    ];

    if (!empty($search)) {
        if (is_numeric($search)) {
            $args['p'] = intval($search);
        } else {
            $args['meta_query'] = [[
                'key' => '_sku',
                'value' => $search,
                'compare' => 'LIKE'
            ]];
            $args['s'] = $search;
        }
    }

    $query = new WP_Query($args);

    echo '<div class="wrap"><h1>Danh sách sản phẩm</h1>';
    echo '<form method="get" style="margin-bottom:20px; display:flex; gap:10px; align-items:center;">';
    echo '<input type="hidden" name="page" value="woo-auto-variations-paginate" />';
    echo '<input type="text" name="s" style="width:239px" placeholder="Tìm theo tên, SKU hoặc ID..." value="' . esc_attr($search) . '" />';
    echo '<label><input type="checkbox" name="filter_no_variations" ' . checked($filter_no_variations, true, false) . '> Chỉ sản phẩm chưa có biến thể</label>';
    echo '<button type="submit" class="button">Lọc</button>';
    echo '</form>';

    echo '<form method="post" action="' . admin_url('admin-post.php?action=bulk_generate_selected') . '" id="variation-form">';
    echo '<input type="hidden" name="action" value="bulk_generate_selected" />';
    echo '<table class="wp-list-table widefat striped"><thead>
            <tr><th>ID</th><th>Tên sản phẩm</th><th>Loại</th><th>Biến thể</th><th>Thao tác</th></tr></thead><tbody>';

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $product = wc_get_product(get_the_ID());
            if (!$product) continue;
            $type = $product->get_type();
            $has_children = $product->has_child();

            if ($filter_no_variations && $has_children) continue;

            echo '<tr>';
            echo '<td>' . get_the_ID() . '</td>';
            echo '<td>' . get_the_title() . '</td>';
            echo '<td>' . ucfirst($type) . '</td>';
            echo '<td>' . ($has_children ? 'Đã có' : 'Chưa có') . '</td>';
            echo '<td>';

            echo '<input type="checkbox" name="product_ids[]" value="' . get_the_ID() . '" />';

            $product_url = get_edit_post_link(get_the_ID());
            echo '<a href="' . esc_url($product_url) . '" class="button" target="_blank" style="margin-left: 6px;">Xem</a> ';

            if (!$has_children) {
                echo ' <a href="' . admin_url('admin.php?page=woo-auto-variations-paginate&generate_for=' . get_the_ID() . '&paged=' . $paged) . '" class="button">Tạo</a>';
            } else {
                echo '<span style="color: #777;">Đã có biến thể</span>';
            }

            echo '</td></tr>';
        }
    } else {
        echo '<tr><td colspan="5">Không có sản phẩm nào.</td></tr>';
    }

    echo '</tbody></table>';
    echo '<p style="margin-top: 10px; display: flex; gap: 10px;">';
    echo '<button type="submit" class="button button-secondary">Tạo biến thể cho sản phẩm đã chọn</button>';
    echo '</p>';
    echo '</form>';

    echo '<form method="post" id="remove-variations-form" action="' . admin_url('admin-post.php?action=remove_variations') . '">';
    wp_nonce_field('woo_bulk_remove_variations');
    echo '<p><button type="submit" class="button" id="woo-remove-variations">🗑️ Xoá biến thể đã chọn</button></p>';
    echo '</form>';

    $total_pages = $query->max_num_pages;
    if ($total_pages > 1) {
        $base_url = admin_url('admin.php?page=woo-auto-variations-paginate&paged=%#%');
        if (!empty($search)) $base_url .= '&s=' . urlencode($search);
        if ($filter_no_variations) $base_url .= '&filter_no_variations=1';

        echo '<div class="tablenav"><div class="tablenav-pages" style="display: flex; align-items: center; gap: 6px;">';

        $total_items = $query->found_posts;
        echo '<span>' . $total_items . ' items</span>';

        $base_url = remove_query_arg('paged', $base_url); // Clear &paged to append correctly
        $page_links = paginate_links([
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'current' => $paged,
            'total' => $total_pages,
            'prev_text' => '«',
            'next_text' => '»',
            'type' => 'array',
        ]);

        // Hiển thị trang hiện tại / tổng số trang
        echo '<span>' . $paged . ' of ' . $total_pages . '</span>';

        // Hiển thị các nút trang styled như ảnh
        if (!empty($page_links)) {
            foreach ($page_links as $link) {
                if (strpos($link, 'current') !== false) {
                    echo '<span style="border: 1px solid #ccc; padding: 4px 8px; background: #f0f0f1;">' . strip_tags($link) . '</span>';
                } else {
                    echo str_replace('page-numbers', 'page-numbers button', $link);
                }
            }
        }

        echo '</div></div>';
    }

    echo '</div>';
    wp_reset_postdata();
}



function woo_create_variations_from_existing_terms($product_id){
     $attributes_data = array(
        array(
            'name'      => 'Fit Type',
            'options'   => array("Girls","Men","Men's Big and Tall","Women","Women's Plus","Youth"),
            'visible'   => 1,
            'variation' => 1
        ),
        array(
            'name'      => 'Color',
            'options'   => array('Black','Navy Blue','Asphalt Grey','Cranberry Red','Red','Grass Green','Kelly Green','Brown','Olive Green','Dark Heather Grey','Heather Blue','Sapphire Blue','Purple','Orange','Royal Blue','Olive Heather','Purple Heather','Red Heather'),
            'visible'   => 1,
            'variation' => 1
        ),
        array(
            'name'      => 'Size',
            'options'   => array('S', 'M', 'L', 'XL'),
            'visible'   => 1,
            'variation' => 1
        ),
  
    );

    $attributes = [];

    foreach ($attributes_data as $index => $attribute) {
        $taxonomy = 'pa_' . wc_sanitize_taxonomy_name($attribute['name']);
        $term_ids = [];

        foreach ($attribute['options'] as $option_name) {
            if (term_exists($option_name, $taxonomy)) {
                wp_set_object_terms($product_id, $option_name, $taxonomy, true);
                $term = get_term_by('name', $option_name, $taxonomy);
                if ($term && !is_wp_error($term)) {
                    $term_ids[] = $term->term_id;
                }
            }
        }

        if (!empty($term_ids)) {
            $attributes[$taxonomy] = array(
                'name'          => $taxonomy,
                'value'         => $term_ids,
                'position'      => $index + 1,
                'is_visible'    => $attribute['visible'],
                'is_variation'  => $attribute['variation'],
                'is_taxonomy'   => 1
            );
        }
    }

    if (!empty($attributes)) {
        update_post_meta($product_id, '_product_attributes', $attributes);
    }

    $product = wc_get_product($product_id);
    if (!$product) return;

    if ($product->get_type() !== 'variable') {
        wp_set_object_terms($product_id, 'variable', 'product_type');
        $product = new WC_Product_Variable($product_id);
    }

    $color_terms = wp_get_post_terms($product_id, 'pa_color');
    $size_terms  = wp_get_post_terms($product_id, 'pa_size');
    $fit_terms   = wp_get_post_terms($product_id, 'pa_fit-type');


    $existing_variations = [];

    foreach ($product->get_children() as $child_id) {
        $variation = wc_get_product($child_id);
        if (!$variation) continue;
        $attrs = $variation->get_attributes();
        $key = $attrs['pa_color'] . '|' . $attrs['pa_size'] . $attrs['pa_fit-type'];
        $existing_variations[$key] = true;
    }

    $base_price = $product->get_regular_price();
    if (!$base_price || !is_numeric($base_price)) $base_price = 10;

    foreach ($color_terms as $color) {
        foreach ($size_terms as $size) {
          foreach ($fit_terms as $fit) {
                $key = $color->slug . '|' . $size->slug . '|' . $fit->slug ;
                if (isset($existing_variations[$key])) continue;

                $variation = new WC_Product_Variation();
                $variation->set_parent_id($product_id);
                $variation->set_attributes([
                    'pa_color'    => $color->slug,
                    'pa_size'     => $size->slug,
                    'pa_fit-type' => $fit->slug
                ]);
                $variation->set_regular_price($base_price);
                $variation->set_stock_status('instock');
                $variation->save();
          }
        }
    }
}
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
        'https://github.com/daunampc/Woo-Auto-Variations',
        __FILE__,
        'woo-auto-variations'
);
$myUpdateChecker->setBranch('main');


