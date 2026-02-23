<?php
defined('ABSPATH') or die();
?>

<div class="wrap">
    <h1><?php echo esc_html__('ISPAG Project Dashboard', 'creation-reservoir'); ?> 📊</h1> <p><?php echo esc_html__('Strategic overview of remaining deliveries and projects ready for invoicing for ISPAG (Vaulruz, Switzerland).', 'creation-reservoir'); ?></p> <div id="dashboard-container" style="display: flex; gap: 20px;">
        
        <div id="delivery-forecast-table" class="postbox" style="flex: 2;">
            <h2 class="hndle"><span>📅 <?php echo esc_html__('Delivery Forecast (Remaining to Deliver)', 'creation-reservoir'); ?></span></h2> <div class="inside">
                <p><?php echo esc_html__('Amount of **undelivered** items with a scheduled delivery date for the month. (3 previous months to 12 months after)', 'creation-reservoir'); ?></p> <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Month', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Amount (CHF)', 'creation-reservoir'); ?></th>
                            <th><?php echo esc_html__('Action', 'creation-reservoir'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="forecast-body">
                        <tr><td colspan="3"><?php echo esc_html__('Loading data...', 'creation-reservoir'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="projects-to-invoice-table" class="postbox" style="flex: 1;">
            <h2 class="hndle"><span>💰 <?php echo esc_html__('Projects Ready to Invoice', 'creation-reservoir'); ?></span></h2> <div class="inside">
                <p><?php echo esc_html__('Items **delivered** (Livre=1) but **not invoiced** (invoiced IS NULL OR = 0).', 'creation-reservoir'); ?></p> <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Project', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Net Total (CHF)', 'creation-reservoir'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="invoice-body">
                        <tr><td colspan="2"><?php echo esc_html__('Loading data...', 'creation-reservoir'); ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="ispag-pd-modal" class="modal" style="display:none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div class="modal-content" style="background-color: #fefefe; margin: 10% auto; padding: 20px; border: 1px solid #888; width: 80%; border-radius: 5px;">
            <span class="close-button" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
            <h2><?php echo esc_html__('Projects to be Delivered in', 'creation-reservoir'); ?> <span id="modal-month-label"></span></h2> <div id="modal-content-data">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Project', 'creation-reservoir'); ?></th>
                            <th><?php echo esc_html__('Article', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Quantity', 'creation-reservoir'); ?></th>
                            <th style="text-align: right;"><?php echo esc_html__('Price (CHF)', 'creation-reservoir'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="modal-project-list">
                    </tbody>
                </table>
            </div>
            <p style="text-align: right; font-weight: bold; margin-top: 20px;"><?php echo esc_html__('Monthly Total:', 'creation-reservoir'); ?> <span id="modal-total-amount"></span></p> </div>
    </div>
</div>