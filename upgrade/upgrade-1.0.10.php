<?php
/**
 * Upgrade script for TelegramNotifier v1.0.10
 *
 * Changes:
 * - Adds {shop_name} placeholder to new customer template for multi-shop support.
 * - Resets cached update information
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to version 1.0.10
 *
 * @param TelegramNotifier $module
 * @return bool
 */
function upgrade_module_1_0_10($module)
{
    $ok = true;

    // -------------------------------------------------------------------------
    // 1) Add {shop_name} placeholder to the existing "New Customer" template
    // -------------------------------------------------------------------------

    $configKey = 'TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE';

    // Get current template for new customer notifications.
    $currentTemplate = Configuration::get($configKey);

    // Only modify the template if it is non-empty and does not yet contain {shop_name}.
    if (!empty($currentTemplate) && strpos($currentTemplate, '{shop_name}') === false) {
        // Prepend the shop line at the beginning.
        $newTemplate = "🏪 Shop: {shop_name}\n" . $currentTemplate;

        if (!Configuration::updateValue($configKey, $newTemplate)) {
            PrestaShopLogger::addLog(
                'TelegramNotifier upgrade 1.0.10: Failed to update new customer template',
                3,
                null,
                'Module',
                $module->id,
                true
            );
            $ok = false;
        } else {
            PrestaShopLogger::addLog(
                'TelegramNotifier upgrade 1.0.10: Successfully added {shop_name} placeholder to new customer template',
                1,
                null,
                'Module',
                $module->id,
                true
            );
        }
    }

    // -------------------------------------------------------------------------
    // 2) Reset cached update information
    // -------------------------------------------------------------------------

    Configuration::updateValue('TELEGRAMNOTIFY_LAST_UPDATE_CHECK', 0);
    Configuration::updateValue('TELEGRAMNOTIFY_CACHED_VERSION', '');

    return $ok;
}
