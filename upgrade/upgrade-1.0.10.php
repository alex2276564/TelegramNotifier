<?php
/**
 * Upgrade script for TelegramNotifier v1.0.10
 *
 * Changes:
 * - Adds {shop_name} placeholder to new customer template for multi-shop support
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
    // Use the module's constant for config key
    $configKey = 'TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE';

    // Get current template
    $currentTemplate = Configuration::get($configKey);

    // Skip if template is empty or already contains {shop_name}
    if (empty($currentTemplate) || strpos($currentTemplate, '{shop_name}') !== false) {
        return true;
    }

    // Add {shop_name} placeholder at the beginning
    $newTemplate = "🏪 Shop: {shop_name}\n" . $currentTemplate;

    // Save updated template
    $result = Configuration::updateValue($configKey, $newTemplate);

    if (!$result) {
        PrestaShopLogger::addLog(
            'TelegramNotifier upgrade 1.0.10: Failed to update new customer template',
            3,
            null,
            'Module',
            $module->id,
            true
        );
        return false;
    }

    PrestaShopLogger::addLog(
        'TelegramNotifier upgrade 1.0.10: Successfully added {shop_name} placeholder to new customer template',
        1,
        null,
        'Module',
        $module->id,
        true
    );

    return true;
}
