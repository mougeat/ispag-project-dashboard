<?php
defined('ABSPATH') or die();
$current_year = date('Y');
$previous_year = $current_year - 1; 
?>

<div class="wrap">
    <h1><?php echo esc_html__('Quotation Analysis (isQotation)', 'creation-reservoir'); ?> 📝</h1> <p><?php echo esc_html__('Visualization of the volume of **quotations (offers)** created by ISPAG each month, compared to the previous year.', 'creation-reservoir'); ?></p> <div id="monthly-quotation-stats-container" style="display: flex; gap: 20px; margin-bottom: 20px;">
        
        <div id="monthly-quotation-table-box" class="postbox" style="flex: 1;">
            <h2 class="hndle"><span><?php echo esc_html__('Monthly Quotation Volume (Comparison', 'creation-reservoir'); ?> <?php echo $current_year; ?> / <?php echo $previous_year; ?>)</span></h2> <div class="inside">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Month', 'creation-reservoir'); ?></th> <th style="text-align: right;"><?php echo esc_html__('Quotations', 'creation-reservoir'); ?> (<?php echo $current_year; ?>)</th> <th style="text-align: right;"><?php echo esc_html__('Quotations', 'creation-reservoir'); ?> (<?php echo $previous_year; ?>)</th> <th style="text-align: right;"><?php echo esc_html__('Evolution', 'creation-reservoir'); ?></th> </tr>
                    </thead>
                    <tbody id="monthly-quotation-body">
                        <tr><td colspan="4"><?php echo esc_html__('Loading data...', 'creation-reservoir'); ?></td></tr> </tbody>
                </table>
            </div>
        </div>

        <div id="monthly-quotation-chart-box" class="postbox" style="flex: 1;">
            <h2 class="hndle"><span><?php echo esc_html__('Monthly Volume Comparison', 'creation-reservoir'); ?></span></h2> <div class="inside">
                <canvas id="monthly-quotation-chart" style="max-height: 400px;"></canvas>
            </div>
        </div>
    </div>
    
</div>