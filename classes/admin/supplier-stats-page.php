<?php
defined('ABSPATH') or die();
$current_year = (int) date('Y');
?>

<style>
    .ispag-tip { position: relative; display: inline-block; margin-left: 4px; color: #2271b1; font-size: 12px; font-weight: normal; cursor: help; }
    .ispag-tip::after {
        content: attr(data-tip); display: none; position: absolute; top: 140%; left: 0; width: 270px;
        padding: 8px 10px; background: #1d2327; color: #fff; border-radius: 4px;
        font-size: 12px; line-height: 1.4; text-align: left; text-transform: none; white-space: normal;
        z-index: 1000; box-shadow: 0 2px 8px rgba(0,0,0,0.25);
    }
    .ispag-tip--right::after { left: auto; right: 0; }
    .ispag-tip:hover::after, .ispag-tip:focus::after { display: block; }
    #ispag-sup-head th { white-space: nowrap; }
</style>

<div class="wrap">
    <h1><?php echo esc_html__('Supplier Dashboard', 'ispag-project-dashboard'); ?> 🏭</h1>
    <p><?php echo esc_html__('Purchases, quantities and delivery performance per supplier and per year. Amounts are shown in each supplier\'s own currency.', 'ispag-project-dashboard'); ?></p>

    <div style="margin-bottom: 15px; display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
        <label>
            <strong><?php echo esc_html__('Year', 'ispag-project-dashboard'); ?></strong>
            <select id="ispag-sup-year">
                <?php for ($y = $current_year; $y >= $current_year - 5; $y--) : ?>
                    <option value="<?php echo esc_attr($y); ?>"><?php echo esc_html($y); ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label>
            <input type="checkbox" id="ispag-sup-products" checked>
            <?php echo esc_html__('Products only (excludes transport and services)', 'ispag-project-dashboard'); ?>
        </label>
    </div>

    <div id="ispag-sup-totals" style="margin-bottom: 15px;"></div>

    <div class="postbox">
        <h2 class="hndle"><span><?php echo esc_html__('Suppliers overview', 'ispag-project-dashboard'); ?></span></h2>
        <div class="inside" style="overflow-x: auto; min-height: 120px;">
            <table class="wp-list-table widefat striped">
                <thead><tr id="ispag-sup-head"></tr></thead>
                <tbody id="ispag-sup-body">
                    <tr><td colspan="13"><?php echo esc_html__('Loading…', 'ispag-project-dashboard'); ?></td></tr>
                </tbody>
            </table>
            <p class="description" style="margin-top: 10px;">
                <?php echo esc_html__('Hover the ⓘ symbol of a column header to see how it is calculated. Click a header to sort.', 'ispag-project-dashboard'); ?>
            </p>
        </div>
    </div>

    <div class="postbox">
        <h2 class="hndle"><span><?php echo esc_html__('Top 10 suppliers by purchases (selected year vs previous year)', 'ispag-project-dashboard'); ?></span></h2>
        <div class="inside">
            <canvas id="ispag-sup-chart" style="max-height: 400px;"></canvas>
        </div>
    </div>
</div>