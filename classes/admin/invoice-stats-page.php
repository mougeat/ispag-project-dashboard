<?php
defined('ABSPATH') or die();
?>

<div class="wrap">
    <h1><?php echo esc_html__('Revenue Analysis (Sales/Turnover)', 'creation-reservoir'); ?> 💰</h1>
    <p><?php echo esc_html__('Comparison of Invoiced Amounts (or Delivered/Paid) Against Current Year Targets', 'creation-reservoir'); ?> (<?php echo date('Y'); ?>).</p>
    
    <div id="monthly-invoice-stats-container" style="display: flex; gap: 20px; margin-bottom: 20px;">
        
        <div id="monthly-invoice-table-box" class="postbox" style="flex: 1;">
            <h2 class="hndle"><span><?php echo esc_html__('Monthly Invoicing vs. Targets', 'creation-reservoir'); ?></span></h2>
            <div class="inside">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Month', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Invoiced (CHF)', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Target (CHF)', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Variance (%)', 'creation-reservoir'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="monthly-invoice-body">
                        <tr><td colspan="4"><?php echo esc_html__('Loading data...', 'creation-reservoir'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="monthly-invoice-chart-box" class="postbox" style="flex: 1;">
            <h2 class="hndle"><span><?php echo esc_html__('Monthly Comparison', 'creation-reservoir'); ?></span></h2>
            <div class="inside">
                <canvas id="monthly-invoice-chart" style="max-height: 400px;"></canvas>
            </div>
        </div>
    </div>

    <div id="cumulative-invoice-stats-container" style="display: flex; gap: 20px;">
        
        <div id="cumulative-invoice-table-box" class="postbox" style="flex: 1;">
            <h2 class="hndle"><span><?php echo esc_html__('Annual Cumulative vs. Targets', 'creation-reservoir'); ?></span></h2>
            <div class="inside">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Month', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Cumulative Invoiced (CHF)', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Cumulative Target (CHF)', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Variance (%)', 'creation-reservoir'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="cumulative-invoice-body">
                        <tr><td colspan="4"><?php echo esc_html__('Loading data...', 'creation-reservoir'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="cumulative-invoice-chart-box" class="postbox" style="flex: 1;">
            <h2 class="hndle"><span><?php echo esc_html__('Cumulative Comparison', 'creation-reservoir'); ?></span></h2>
            <div class="inside">
                <canvas id="cumulative-invoice-chart" style="max-height: 400px;"></canvas>
            </div>
        </div>
    </div>

    <div id="ispag-pd-modal" class="ispag-pd-modal" style="
        display: none; 
        position: fixed; 
        z-index: 99999; 
        left: 0; 
        top: 0; 
        width: 100%; 
        height: 100%; 
        overflow: auto; 
        background-color: rgba(0,0,0,0.4); 
        padding-top: 50px;
    ">
        <div class="modal-content" style="
            background-color: #fefefe;
            margin: auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 900px;
            box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
        ">
            <span class="close-button" style="
                color: #aaa;
                float: right;
                font-size: 28px;
                font-weight: bold;
                cursor: pointer;
            ">&times;</span>

            <h2><?php echo esc_html__('Billing Details for:', 'creation-reservoir'); ?> <span id="modal-month-label"></span></h2>
            
            <div id="modal-content-data">
                <p>
                    <?php echo esc_html__('Total Invoiced:', 'creation-reservoir'); ?> <strong id="modal-total-amount">0 CHF</strong>
                </p>
                
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Project', 'creation-reservoir'); ?></th>
                            <th><?php echo esc_html__('Invoice Status', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Invoiced Amount (CHF)', 'creation-reservoir'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="modal-project-list">
                        </tbody>
                </table>
            </div>
        </div>
    </div>
</div>