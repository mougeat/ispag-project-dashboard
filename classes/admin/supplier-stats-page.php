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

    .ispag-toolbar { margin-bottom: 15px; display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }

    #ispag-sup-modal { display: none; position: fixed; z-index: 100000; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0,0,0,0.5); overflow: auto; padding: 40px 20px; box-sizing: border-box; }
    #ispag-sup-modal .ispag-modal-inner { background: #fff; max-width: 1100px; margin: 0 auto; padding: 20px 24px 24px;
        border-radius: 6px; box-shadow: 0 8px 30px rgba(0,0,0,0.3); }
    .ispag-modal-head { display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; }
    .ispag-modal-head h2 { margin: 0; }
    .ispag-modal-actions { display: flex; gap: 8px; align-items: center; }
    .ispag-modal-close { font-size: 28px; line-height: 1; cursor: pointer; color: #787c82; padding: 0 6px; }
    .ispag-modal-close:hover { color: #000; }
    .ispag-kpis { display: flex; gap: 12px; flex-wrap: wrap; margin: 15px 0 20px; }
    .ispag-kpi { background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 8px 14px; min-width: 120px; }
    .ispag-kpi span { display: block; font-size: 11px; color: #50575e; text-transform: uppercase; }
    .ispag-kpi strong { font-size: 16px; }
    #ispag-sup-modal h3 { margin: 22px 0 8px; }
    a.ispag-sup-open { font-weight: 600; text-decoration: none; }
</style>

<div class="wrap">
    <h1><?php echo esc_html__('Supplier Dashboard', 'ispag-dashboard'); ?> 🏭</h1>
    <p><?php echo esc_html__('Purchases, quantities and delivery performance per supplier and per year. Amounts are shown in each supplier\'s own currency.', 'ispag-dashboard'); ?></p>

    <div class="ispag-toolbar">
        <label>
            <strong><?php echo esc_html__('Year', 'ispag-dashboard'); ?></strong>
            <select id="ispag-sup-year">
                <?php for ($y = $current_year; $y >= $current_year - 5; $y--) : ?>
                    <option value="<?php echo esc_attr($y); ?>"><?php echo esc_html($y); ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <label>
            <input type="checkbox" id="ispag-sup-products" checked>
            <?php echo esc_html__('Products only (excludes transport and services)', 'ispag-dashboard'); ?>
        </label>
        <a id="ispag-sup-export" class="button" href="#"><?php echo esc_html__('Export CSV', 'ispag-dashboard'); ?></a>
    </div>

    <div id="ispag-sup-totals" style="margin-bottom: 15px;"></div>

    <div class="postbox">
        <h2 class="hndle"><span><?php echo esc_html__('Suppliers overview', 'ispag-dashboard'); ?></span></h2>
        <div class="inside" style="overflow-x: auto; min-height: 120px;">
            <table class="wp-list-table widefat striped">
                <thead><tr id="ispag-sup-head"></tr></thead>
                <tbody id="ispag-sup-body">
                    <tr><td colspan="13"><?php echo esc_html__('Loading…', 'ispag-dashboard'); ?></td></tr>
                </tbody>
            </table>
            <p class="description" style="margin-top: 10px;">
                <?php echo esc_html__('Hover the ⓘ symbol of a column header to see how it is calculated. Click a header to sort, click a supplier name to open its record.', 'ispag-dashboard'); ?>
            </p>
        </div>
    </div>

    <div class="postbox">
        <h2 class="hndle"><span><?php echo esc_html__('Top 10 suppliers by purchases (selected year vs previous year)', 'ispag-dashboard'); ?></span></h2>
        <div class="inside">
            <canvas id="ispag-sup-chart" style="max-height: 400px;"></canvas>
        </div>
    </div>

    <div class="postbox" id="ispag-late">
        <h2 class="hndle"><span>⏰ <?php echo esc_html__('Late deliveries', 'ispag-dashboard'); ?></span></h2>
        <div class="inside" style="overflow-x: auto;">
            <p class="description">
                <?php echo esc_html__('Order lines not fully received (and not marked as delivered in the project) whose delivery date confirmed by the supplier has passed. Lines without a confirmed date are not listed.', 'ispag-dashboard'); ?>
            </p>
            <div class="ispag-toolbar">
                <select id="ispag-late-filter"></select>
                <a id="ispag-late-export" class="button" href="#"><?php echo esc_html__('Export CSV', 'ispag-dashboard'); ?></a>
                <span id="ispag-late-summary"></span>
            </div>
            <table class="wp-list-table widefat striped">
                <thead><tr id="ispag-late-head"></tr></thead>
                <tbody id="ispag-late-body">
                    <tr><td colspan="8"><?php echo esc_html__('Loading…', 'ispag-dashboard'); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="ispag-sup-modal" role="dialog" aria-modal="true">
    <div class="ispag-modal-inner">
        <div class="ispag-modal-head">
            <h2 id="ispag-sup-modal-title"></h2>
            <div class="ispag-modal-actions">
                <a id="ispag-sup-modal-export" class="button" href="#"><?php echo esc_html__('Export CSV', 'ispag-dashboard'); ?></a>
                <button type="button" id="ispag-sup-modal-print" class="button button-primary"><?php echo esc_html__('Print meeting sheet', 'ispag-dashboard'); ?></button>
                <span class="ispag-modal-close" aria-label="<?php echo esc_attr__('Close', 'ispag-dashboard'); ?>">&times;</span>
            </div>
        </div>
        <div id="ispag-sup-modal-body"></div>
    </div>
</div>