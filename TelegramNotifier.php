<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class TelegramNotifier extends Module
{
    private $configCache = [];

    private $configTypes = [
        'TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS' => 'bool',
        'TELEGRAMNOTIFY_MAX_MESSAGES' => 'int',
        'TELEGRAMNOTIFY_MAX_RETRIES' => 'int',

    ];

    private function getFromCache($key)
    {
        // If the value is in the cache - return it
        if (isset($this->configCache[$key])) {
            return $this->configCache[$key];
        }

        // If not - get from the database and save to cache
        $value = $this->getConfigValue($key);

        // In PrestaShop, configuration values in the ps_configuration table are stored as strings (in the value column of mediumtext type)
        if (isset($this->configTypes[$key])) {
            switch ($this->configTypes[$key]) {
                case 'int':
                    $value = (int) $value;
                    break;
                case 'bool':
                    $value = (bool) $value;
                    break;
                default:
                    $value = (string) $value;
            }
        }

        $this->configCache[$key] = $value;
        return $value;
    }

    public function __construct()
    {
        $this->name = 'TelegramNotifier';
        $this->tab = 'administration';
        $this->version = '1.0.5';
        $this->author = 'alex2276564';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Telegram Notifier');
        $this->description = $this->l('Sends Telegram notifications for new orders, admin logins, and new customer registrations.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        $this->loadConfiguration();
    }

    private function getConfigValue($key)
    {
        return Configuration::get($key);
    }

    private function setConfigValue($key, $value)
    {
        $this->configCache[$key] = $value;

        return Configuration::updateValue($key, $value);
    }

    private function deleteConfigValue($key)
    {
        unset($this->configCache[$key]);

        return Configuration::deleteByName($key);
    }

    private function loadConfiguration()
    {
        $this->configCache = [
            'TELEGRAMNOTIFY_BOT_TOKEN' => $this->getFromCache('TELEGRAMNOTIFY_BOT_TOKEN'),
            'TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID' => $this->getFromCache('TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID'),
            'TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID' => $this->getFromCache('TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID'),
            'TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID' => $this->getFromCache('TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID'),
            'TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS' => (bool) $this->getFromCache('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS'),
            'TELEGRAMNOTIFY_MAX_MESSAGES' => (int) $this->getFromCache('TELEGRAMNOTIFY_MAX_MESSAGES'),
            'TELEGRAMNOTIFY_MAX_RETRIES' => (int) $this->getFromCache('TELEGRAMNOTIFY_MAX_RETRIES'),
            'TELEGRAMNOTIFY_NEW_ORDER_TEMPLATE' => $this->getFromCache('TELEGRAMNOTIFY_NEW_ORDER_TEMPLATE'),
            'TELEGRAMNOTIFY_ADMIN_LOGIN_TEMPLATE' => $this->getFromCache('TELEGRAMNOTIFY_ADMIN_LOGIN_TEMPLATE'),
            'TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE' => $this->getFromCache('TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE')
        ];
    }

    public function install()
    {
        return parent::install() &&
            $this->registerHook('actionValidateOrder') &&
            $this->registerHook('actionAdminLoginControllerLoginAfter') &&
            $this->registerHook('actionCustomerAccountAdd') &&
            $this->setConfigValue('TELEGRAMNOTIFY_BOT_TOKEN', '') &&
            $this->setConfigValue('TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID', '') &&
            $this->setConfigValue('TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID', '') &&
            $this->setConfigValue('TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID', '') &&
            $this->setConfigValue('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS', true) &&
            $this->setConfigValue('TELEGRAMNOTIFY_MAX_MESSAGES', 5) &&
            $this->setConfigValue('TELEGRAMNOTIFY_MAX_RETRIES', 0) &&
            $this->setConfigValue('TELEGRAMNOTIFY_NEW_ORDER_TEMPLATE', $this->getDefaultNewOrderTemplate()) &&
            $this->setConfigValue('TELEGRAMNOTIFY_ADMIN_LOGIN_TEMPLATE', $this->getDefaultAdminLoginTemplate()) &&
            $this->setConfigValue('TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE', $this->getDefaultNewCustomerTemplate());
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_BOT_TOKEN') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_MAX_MESSAGES') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_MAX_RETRIES') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_NEW_ORDER_TEMPLATE') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_ADMIN_LOGIN_TEMPLATE') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE');
    }

    public function hookActionCustomerAccountAdd($params)
    {
        if (!empty($this->getFromCache('TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID'))) {
            $customer = $params['newCustomer'];
            $newCustomerTemplate = $this->getFromCache('TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE');

            $placeholders = [
                '{customer_name}' => '',
                '{customer_email}' => '',
                '{ip_address}' => '',
                '{country}' => '',
                '{date_time}' => '',
                '{birthday}' => '',
                '{gender}' => '',
                '{newsletter}' => '',
            ];

            if (strpos($newCustomerTemplate, '{customer_name}') !== false) {
                $placeholders['{customer_name}'] = $customer->firstname . ' ' . $customer->lastname;
            }

            if (strpos($newCustomerTemplate, '{customer_email}') !== false) {
                $placeholders['{customer_email}'] = $customer->email;
            }

            if (strpos($newCustomerTemplate, '{ip_address}') !== false || strpos($newCustomerTemplate, '{country}') !== false) {
                $ip = Tools::getRemoteAddr();

                if (strpos($newCustomerTemplate, '{ip_address}') !== false) {
                    $placeholders['{ip_address}'] = $ip;
                }

                if (strpos($newCustomerTemplate, '{country}') !== false) {
                    $placeholders['{country}'] = $this->getCountryFromIP($ip);
                }
            }

            if (strpos($newCustomerTemplate, '{date_time}') !== false) {
                $placeholders['{date_time}'] = date('Y-m-d H:i:s');
            }

            if (strpos($newCustomerTemplate, '{birthday}') !== false) {
                $birthday = $customer->birthday;
                $placeholders['{birthday}'] = !empty($birthday) ? date('Y-m-d', strtotime($birthday)) : '';
            }

            if (strpos($newCustomerTemplate, '{gender}') !== false) {
                $placeholders['{gender}'] = $this->getGenderName($customer->id_gender);
            }

            if (strpos($newCustomerTemplate, '{newsletter}') !== false) {
                $placeholders['{newsletter}'] = $customer->newsletter ? '✅' : '❌';
            }

            $message = strtr($newCustomerTemplate, $placeholders);

            $this->sendTelegramMessage($message, 'new_customer');
        }
    }

    public function hookActionAdminLoginControllerLoginAfter($params)
    {
        if (!empty($this->getFromCache('TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID'))) {
            $employee = $params['employee'];
            $adminLoginTemplate = $this->getFromCache('TELEGRAMNOTIFY_ADMIN_LOGIN_TEMPLATE');

            $placeholders = [
                '{employee_name}' => '',
                '{employee_email}' => '',
                '{ip_address}' => '',
                '{country}' => '',
                '{date_time}' => '',
            ];

            if (strpos($adminLoginTemplate, '{employee_name}') !== false) {
                $placeholders['{employee_name}'] = $employee->firstname . ' ' . $employee->lastname;
            }

            if (strpos($adminLoginTemplate, '{employee_email}') !== false) {
                $placeholders['{employee_email}'] = $employee->email;
            }

            if (strpos($adminLoginTemplate, '{ip_address}') !== false || strpos($adminLoginTemplate, '{country}') !== false) {
                $ip = Tools::getRemoteAddr();

                if (strpos($adminLoginTemplate, '{ip_address}') !== false) {
                    $placeholders['{ip_address}'] = $ip;
                }

                if (strpos($adminLoginTemplate, '{country}') !== false) {
                    $placeholders['{country}'] = $this->getCountryFromIP($ip);
                }
            }

            if (strpos($adminLoginTemplate, '{date_time}') !== false) {
                $placeholders['{date_time}'] = date('Y-m-d H:i:s');
            }

            $message = strtr($adminLoginTemplate, $placeholders);

            $this->sendTelegramMessage($message, 'admin_login');
        }
    }

    public function hookActionValidateOrder($params)
    {
        if (!empty($this->getFromCache('TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID'))) {
            $order = $params['order'];
            $customer = new Customer($order->id_customer);
            $address = new Address($order->id_address_delivery);

            $newOrderTemplate = $this->getFromCache('TELEGRAMNOTIFY_NEW_ORDER_TEMPLATE');

            $placeholders = [
                '{order_reference}' => '',
                '{shop_name}' => '',
                '{customer_name}' => '',
                '{customer_email}' => '',
                '{ip_address}' => '',
                '{country}' => '',
                '{date_time}' => '',
                '{phone_number}' => '',
                '{total_paid}' => '',
                '{shipping_address}' => '',
                '{delivery_method}' => '',
                '{payment_method}' => '',
                '{products_list}' => '',
                '{order_comment}' => '',
            ];

            if (strpos($newOrderTemplate, '{order_reference}') !== false) {
                $placeholders['{order_reference}'] = $order->reference;
            }

            if (strpos($newOrderTemplate, '{shop_name}') !== false) {
                $shop = new Shop($order->id_shop);
                $placeholders['{shop_name}'] = $shop->name;
            }

            if (strpos($newOrderTemplate, '{customer_name}') !== false) {
                $placeholders['{customer_name}'] = $customer->firstname . ' ' . $customer->lastname;
            }

            if (strpos($newOrderTemplate, '{customer_email}') !== false) {
                $placeholders['{customer_email}'] = $customer->email;
            }

            if (strpos($newOrderTemplate, '{ip_address}') !== false || strpos($newOrderTemplate, '{country}') !== false) {
                $ip = Tools::getRemoteAddr();

                if (strpos($newOrderTemplate, '{ip_address}') !== false) {
                    $placeholders['{ip_address}'] = $ip;
                }

                if (strpos($newOrderTemplate, '{country}') !== false) {
                    $placeholders['{country}'] = $this->getCountryFromIP($ip);
                }
            }

            if (strpos($newOrderTemplate, '{date_time}') !== false) {
                $placeholders['{date_time}'] = date('Y-m-d H:i:s');
            }

            if (strpos($newOrderTemplate, '{phone_number}') !== false) {
                $placeholders['{phone_number}'] = !empty($address->phone_mobile) ?
                    $address->phone_mobile : $address->phone;
            }

            if (strpos($newOrderTemplate, '{total_paid}') !== false) {
                $placeholders['{total_paid}'] = Tools::displayPrice(
                    $order->getOrdersTotalPaid(),
                    $order->id_currency,
                    false
                );
            }

            if (strpos($newOrderTemplate, '{shipping_address}') !== false) {
                $placeholders['{shipping_address}'] = $this->formatShippingAddress($address);
            }

            if (strpos($newOrderTemplate, '{delivery_method}') !== false) {
                $carrier = new Carrier($order->id_carrier);
                $placeholders['{delivery_method}'] = $carrier->name;
            }

            if (strpos($newOrderTemplate, '{payment_method}') !== false) {
                $placeholders['{payment_method}'] = $order->payment;
            }

            if (strpos($newOrderTemplate, '{products_list}') !== false) {
                $productslist = '';
                $products = $order->getProducts();

                foreach ($products as $product) {
                    $attributes = isset($product['attributes']) && !empty($product['attributes'])
                        ? " (" . $product['attributes'] . ")"
                        : "";
                    $productName = $product['product_name'];
                    $productslist .= "- " . $productName . $attributes .
                        " x " . (int) $product['product_quantity'] . "\n";
                }
                $placeholders['{products_list}'] = $productslist;
            }

            if (strpos($newOrderTemplate, '{order_comment}') !== false) {
                $orderMessage = '';
                if ($order->id) {
                    $orderMessages = Message::getMessagesByOrderId((int) $order->id);
                    if (!empty($orderMessages)) {
                        $orderMessage = $orderMessages[0]['message'];
                    }
                }
                $placeholders['{order_comment}'] = $orderMessage;
            }

            $message = strtr($newOrderTemplate, $placeholders);
            $this->sendTelegramMessage($message, 'order');
        }
    }

    private function sendTelegramMessage($message, $notificationType)
    {
        $botToken = $this->getFromCache('TELEGRAMNOTIFY_BOT_TOKEN');
        $maxRetries = (int) $this->getFromCache('TELEGRAMNOTIFY_MAX_RETRIES');

        switch ($notificationType) {
            case 'order':
                $chatIds = $this->getFromCache('TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID');
                break;
            case 'admin_login':
                $chatIds = $this->getFromCache('TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID');
                break;
            case 'new_customer':
                $chatIds = $this->getFromCache('TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID');
                break;
            case 'test':
                $chatIds = $this->getFromCache('TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID');
                break;
            default:
                $this->logError('Invalid notification type: ' . $notificationType);
                return false;
        }

        $maxMessages = $this->getFromCache('TELEGRAMNOTIFY_MAX_MESSAGES');

        $validate = $this->validateConfigurationData(
            $botToken,
            $this->getFromCache('TELEGRAMNOTIFY_NEW_ORDER_CHAT_ID'),
            $this->getFromCache('TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID'),
            $this->getFromCache('TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID'),
            $this->getFromCache('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS'),
            $maxMessages,
            $maxRetries,
            $this->getFromCache('TELEGRAMNOTIFY_NEW_ORDER_TEMPLATE'),
            $this->getFromCache('TELEGRAMNOTIFY_ADMIN_LOGIN_TEMPLATE'),
            $this->getFromCache('TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE')
        );
        if (is_array($validate) && isset($validate[0])) {
            $this->logError('Invalid configuration: ' . json_encode($validate));
            return false;
        }

        $chatIdsArray = array_map('trim', explode(',', $chatIds));

        // Add an update notification at the beginning of the message
        if ((bool) $this->getFromCache('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS')) {
            $newVersion = $this->checkForUpdates();
            if ($newVersion) {
                $updateMessage = '🎉 ' . $this->l('A new version of TelegramNotifier is available! Update to') . ' ' . $newVersion . ' ' . $this->l('to get the latest features and bug fixes.') . "\n";
                $updateMessage .= $this->l('Download:') . ' https://github.com/alex2276564/TelegramNotifier/releases/latest' . "\n\n";
                $message = $updateMessage . $message;
            }
        }

        $messageParts = $this->splitMessage($message);

        if ($maxMessages > 0) {
            $messageParts = array_slice($messageParts, 0, $maxMessages);
        }

        $urls = [];
        $postData = [];

        foreach ($chatIdsArray as $chatId) {
            foreach ($messageParts as $part) {
                $urls[] = "https://api.telegram.org/bot" . urlencode($botToken) . "/sendMessage";
                $postData[] = [
                    'chat_id' => $chatId,
                    'text' => $part,
                    'disable_web_page_preview' => true,
                ];
            }
        }

        if (empty($urls)) {
            $this->logError('No valid data for sending the message.');
            return false;
        }

        if ($maxRetries === 0) {
            $results = $this->executeCurlRequest($urls, $postData, [], true);
            $success = true;

            foreach ($results as $result) {
                if ($result['error']) {
                    $this->logError('Failed to send Telegram message: ' . $result['error']);
                    $success = false;
                } else {
                    $responseData = json_decode($result['result'], true);
                    if (!isset($responseData['ok']) || $responseData['ok'] !== true) {
                        $this->logError('Telegram API error: ' . ($responseData['description'] ?? 'Unknown error'));
                        $success = false;
                    }
                }
                // Telegram rate limiting (add a delay (1 second) between messages)
                sleep(1);
            }

            return $success;
        }

        $attempt = 0;
        $success = false;

        while ($attempt < $maxRetries) {
            $results = $this->executeCurlRequest($urls, $postData, [], true);
            $success = true;

            foreach ($results as $result) {
                if ($result['error']) {
                    $this->logError('Attempt ' . ($attempt + 1) . ': Failed to send Telegram message: ' . $result['error']);
                    $success = false;
                } else {
                    $responseData = json_decode($result['result'], true);
                    if (!isset($responseData['ok']) || $responseData['ok'] !== true) {
                        $this->logError('Attempt ' . ($attempt + 1) . ': Telegram API error: ' . ($responseData['description'] ?? 'Unknown error'));
                        $success = false;
                    }
                }
                // Telegram rate limiting (add a delay (1 second) between messages)
                sleep(1);
            }

            if ($success) {
                break;
            }

            $attempt++;
        }

        return $success;
    }

    // Splits a message into parts if its length exceeds the maximum allowed length
    private function splitMessage($message, $maxLength = 4096)
    {
        if (mb_strlen($message, 'UTF-8') <= $maxLength) {
            return [$message];
        }

        $parts = [];
        while (mb_strlen($message, 'UTF-8') > 0) {
            if (mb_strlen($message, 'UTF-8') <= $maxLength) {
                $parts[] = $message;
                break;
            }

            $part = mb_substr($message, 0, $maxLength, 'UTF-8');
            $lastSpace = mb_strrpos($part, ' ', 0, 'UTF-8');

            if ($lastSpace !== false) {
                $parts[] = mb_substr($message, 0, $lastSpace, 'UTF-8');
                $message = mb_substr($message, $lastSpace + 1, null, 'UTF-8');
            } else {
                $parts[] = $part;
                $message = mb_substr($message, $maxLength, null, 'UTF-8');
            }
        }

        return $parts;
    }

    private function executeCurlRequest($urls, $postData = null, $headers = [], $multiRequest = false)
    {
        if (!$multiRequest) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urls);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            if ($postData !== null) {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            }

            $result = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'result' => $result,
                'error' => $error,
                'httpCode' => $httpCode
            ];
        }

        $mh = curl_multi_init();
        $curlHandles = [];

        foreach ($urls as $index => $url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            if ($postData !== null && isset($postData[$index])) {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData[$index]));
            }

            curl_multi_add_handle($mh, $ch);
            $curlHandles[] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
        } while ($running > 0);

        $results = [];
        foreach ($curlHandles as $ch) {
            $results[] = [
                'result' => curl_multi_getcontent($ch),
                'error' => curl_error($ch),
                'httpCode' => curl_getinfo($ch, CURLINFO_HTTP_CODE)
            ];
            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        return $results;
    }

    private function formatShippingAddress($address)
    {
        $fields = [
            'company' => '🏢 ',
            'vat_number' => '📝 ',
            'address1' => '📍 ',
            'address2' => '📍2️⃣ ',
            'postcode' => '📮 ',
            'city' => '🏙️ ',
            'state' => '🏛️ ',
            'country' => '🌍 '
        ];

        $parts = [];
        foreach ($fields as $field => $emoji) {
            if (!empty($address->$field)) {
                $parts[$field] = $emoji . $address->$field;
            }
        }

        if (isset($parts['city']) && isset($parts['postcode'])) {
            $parts['city'] = $parts['postcode'] . ' ' . $parts['city'];
            unset($parts['postcode']);
        }

        return implode("\n", $parts);
    }

    private function validateConfigurationData($botToken, $newOrdersChatId, $adminLoginChatId, $newCustomerChatId, $updateNotifications, $maxMessages, $maxRetries, $newOrderTemplate, $adminLoginTemplate, $newCustomerTemplate)
    {
        $errors = [];
        $default = false;

        if (empty($botToken)) {
            $errors[] = $this->l('Bot Token is required.');
        }

        if (empty($newOrdersChatId) && empty($adminLoginChatId) && empty($newCustomerChatId)) {
            $errors[] = $this->l('At least one of New Orders, Admin Login, or New Customer Chat ID must be filled.');
        }

        $this->validateChatIds($newOrdersChatId, 'New Orders Notification Chat ID(s)', $errors);
        $this->validateChatIds($adminLoginChatId, 'Admin Login Notifications Chat ID(s)', $errors);
        $this->validateChatIds($newCustomerChatId, 'New Customer Registration Notifications Chat ID(s)', $errors);

        if (!is_bool($updateNotifications)) {
            $errors[] = $this->l('Update Notifications must be a boolean value.');
        }

        if (!is_numeric($maxMessages) || $maxMessages < 0 || !ctype_digit(strval($maxMessages))) {
            $errors[] = $this->l('Max Messages must be a non-negative integer.');
        }

        if (!is_numeric($maxRetries) || $maxRetries < 0 || !ctype_digit(strval($maxRetries))) {
            $errors[] = $this->l('Max Retry Attempts must be a non-negative integer.');
        }

        if (empty($newOrderTemplate)) {
            $newOrderTemplate = $this->getDefaultNewOrderTemplate();
            $default = true;
        }
        if (empty($adminLoginTemplate)) {
            $adminLoginTemplate = $this->getDefaultAdminLoginTemplate();
            $default = true;
        }
        if (empty($newCustomerTemplate)) {
            $newCustomerTemplate = $this->getDefaultNewCustomerTemplate();
            $default = true;
        }

        if (!empty($errors)) {
            return $errors;
        }

        return [
            'newOrderTemplate' => $newOrderTemplate,
            'adminLoginTemplate' => $adminLoginTemplate,
            'newCustomerTemplate' => $newCustomerTemplate,
            'default' => $default
        ];
    }

    private function validateChatIds($chatIds, $fieldName, &$errors)
    {
        if (!empty($chatIds)) {
            $chatIdsArray = array_map('trim', explode(',', $chatIds));
            foreach ($chatIdsArray as $chatId) {
                if (!preg_match('/^-?\d{9,14}$/', $chatId)) {
                    $errors[] = $this->l('Invalid ') . $fieldName . ': ' . $chatId;
                }
            }
        }
    }

    private function logError($message)
    {
        PrestaShopLogger::addLog(
            'TelegramNotifier error: ' . $message,
            3,
            null,
            'TelegramNotifier',
            null,
            true
        );
    }

    private function getDefaultNewOrderTemplate()
    {
        return "🆕 New order #{order_reference}\n" .
            "🏪 Shop: {shop_name}\n" .
            "👤 Customer: {customer_name}\n" .
            "📧 Email: {customer_email}\n" .
            "🌐 IP: {ip_address}\n" .
            "🏳️ Country: {country}\n" .
            "🕒 Date/Time: {date_time} (Server time)\n" .
            "📞 Phone: {phone_number}\n" .
            "💰 Amount: {total_paid}\n" .
            "🏠 Shipping address:\n{shipping_address}\n" .
            "📦 Delivery method: {delivery_method}\n" .
            "💳 Payment method: {payment_method}\n" .
            "🛍️ Products:\n{products_list}\n" .
            "📝 Comment: {order_comment}";
    }

    private function getDefaultAdminLoginTemplate()
    {
        return "🔐 Admin Login Alert\n" .
            "👤 Employee: {employee_name}\n" .
            "📧 Email: {employee_email}\n" .
            "🌐 IP Address: {ip_address}\n" .
            "🏳️ Country: {country}\n" .
            "🕒 Date/Time: {date_time} (Server time)\n" .
            "⚠️ If you don't recognize this login, change your password immediately!";
    }

    private function getDefaultNewCustomerTemplate()
    {
        return "🆕 New Customer Registration\n" .
            "👤 Customer: {customer_name}\n" .
            "📧 Email: {customer_email}\n" .
            "🌐 IP: {ip_address}\n" .
            "🏳️ Country: {country}\n" .
            "🕒 Date/Time: {date_time} (Server time)\n" .
            "🎂 Birthday: {birthday}\n" .
            "👫 Gender: {gender}\n" .
            "📰 Subscribed to newsletter: {newsletter}";
    }

    private function getCountryFromIP($ip)
    {
        $url = "http://ip-api.com/json/{$ip}";
        $response = $this->executeCurlRequest($url);
        if ($response['error'] || $response['httpCode'] != 200) {
            return 'Unknown';
        }
        $data = json_decode($response['result'], true);
        return $data['country'] ?? 'Unknown';
    }

    private function getGenderName($id_gender)
    {
        if (empty($id_gender)) {
            return '';
        }

        $gender = new Gender($id_gender, $this->context->language->id);
        return $gender->name;
    }

    private function testTelegramMessage()
    {
        $testMessage = 'This is a test message from your PrestaShop Telegram Notifier.';
        $result = $this->sendTelegramMessage($testMessage, "test");
        if ($result) {
            return $this->displayConfirmation($this->l('Test message sent successfully.'));
        } else {
            return $this->displayError($this->l('Failed to send test message. Please check your settings.'));
        }
    }

    private function checkForUpdates()
    {
        $url = "https://api.github.com/repos/alex2276564/TelegramNotifier/releases/latest";
        $headers = ["User-Agent: php"];

        $response = $this->executeCurlRequest($url, null, $headers);

        if ($response['error']) {
            $this->logError('Failed to check for updates: ' . $response['error']);
            return false;
        }

        if ($response['httpCode'] != 200) {
            $this->logError('Failed to check for updates: HTTP code ' . $response['httpCode']);
            return false;
        }

        $release = json_decode($response['result'], true);

        if (isset($release['tag_name']) && version_compare($release['tag_name'], $this->version, '>')) {
            return $release['tag_name'];
        }

        return false;
    }

    public function getContent()
    {
        $output = '';

        $getConfigValueFromForm = function ($key) {
            return Tools::getValue($key);
        };

        $newVersion = $this->checkForUpdates();
        if ($newVersion) {
            $updateLink = '<a href="https://github.com/alex2276564/TelegramNotifier/releases/latest" target="_blank">' . $newVersion . '</a>';
            $output .= $this->displayConfirmation(
                sprintf(
                    $this->l('A new version %s is available. Please update the module to get the latest features and bug fixes. - Download: %s'),
                    $updateLink,
                    '<a href="https://github.com/alex2276564/TelegramNotifier/releases/latest" target="_blank">' . $this->l('here') . '</a>'
                )
            );
        }

        if (Tools::isSubmit('submit' . $this->name)) {
            $botToken = $getConfigValueFromForm('TELEGRAMNOTIFY_BOT_TOKEN');
            $newOrdersChatId = $getConfigValueFromForm('TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID');
            $adminLoginChatId = $getConfigValueFromForm('TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID');
            $newCustomerChatId = $getConfigValueFromForm('TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID');
            $updateNotifications = (bool) $getConfigValueFromForm('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS');
            $maxMessages = $getConfigValueFromForm('TELEGRAMNOTIFY_MAX_MESSAGES');
            $maxRetries = $getConfigValueFromForm('TELEGRAMNOTIFY_MAX_RETRIES');
            $newOrderTemplate = $getConfigValueFromForm('TELEGRAMNOTIFY_NEW_ORDER_TEMPLATE');
            $adminLoginTemplate = $getConfigValueFromForm('TELEGRAMNOTIFY_ADMIN_LOGIN_TEMPLATE');
            $newCustomerTemplate = $getConfigValueFromForm('TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE');

            $validationResult = $this->validateConfigurationData(
                $botToken,
                $newOrdersChatId,
                $adminLoginChatId,
                $newCustomerChatId,
                $updateNotifications,
                $maxMessages,
                $maxRetries,
                $newOrderTemplate,
                $adminLoginTemplate,
                $newCustomerTemplate
            );

            if (is_array($validationResult) && array_key_exists('newOrderTemplate', $validationResult)) {
                $this->setConfigValue('TELEGRAMNOTIFY_BOT_TOKEN', $botToken);
                $this->setConfigValue('TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID', $newOrdersChatId);
                $this->setConfigValue('TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID', $adminLoginChatId);
                $this->setConfigValue('TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID', $newCustomerChatId);
                $this->setConfigValue('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS', $updateNotifications);
                $this->setConfigValue('TELEGRAMNOTIFY_MAX_MESSAGES', $maxMessages);
                $this->setConfigValue('TELEGRAMNOTIFY_MAX_RETRIES', $maxRetries);
                $this->setConfigValue('TELEGRAMNOTIFY_NEW_ORDER_TEMPLATE', $validationResult['newOrderTemplate']);
                $this->setConfigValue('TELEGRAMNOTIFY_ADMIN_LOGIN_TEMPLATE', $validationResult['adminLoginTemplate']);
                $this->setConfigValue('TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE', $validationResult['newCustomerTemplate']);

                $output .= $this->displayConfirmation($this->l('Settings updated'));
                if ($validationResult['default']) {
                    $output .= $this->displayWarning($this->l('One or more templates were empty. Using default templates.'));
                }
            } else {
                foreach ($validationResult as $error) {
                    $output .= $this->displayError($error);
                }
            }
        }

        if (Tools::isSubmit('test_telegram_message')) {
            $output .= $this->testTelegramMessage();
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $default_lang = (int) $this->getConfigValue('PS_LANG_DEFAULT');

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Telegram Notifier Settings'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'html',
                        'name' => 'TELEGRAMNOTIFY_GENERAL_INFO',
                        'html_content' => '<div class="alert alert-info">' . $this->l('Configure your Telegram bot settings here.') . '</div>'
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Telegram Bot Token'),
                        'name' => 'TELEGRAMNOTIFY_BOT_TOKEN',
                        'size' => 50,
                        'required' => true,
                        'desc' => $this->l('To get a Bot Token, create a new bot via @BotFather in Telegram.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('New Orders Notification Chat ID(s)'),
                        'name' => 'TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID',
                        'size' => 50,
                        'required' => false,
                        'desc' => $this->l('Enter one or more Chat IDs separated by commas. Use positive numbers for personal chats (e.g., 123456789), negative numbers for group chats (e.g., -987654321), or numbers starting with -100 for channels and some supergroups (e.g., -1001234567890) to receive new order notifications.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Admin Login Notifications Chat ID(s)'),
                        'name' => 'TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID',
                        'size' => 50,
                        'required' => false,
                        'desc' => $this->l('Enter Chat IDs to receive notifications when someone logs into the admin panel. Use the same format as above.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('New Customer Registration Notifications Chat ID(s)'),
                        'name' => 'TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID',
                        'size' => 50,
                        'required' => false,
                        'desc' => $this->l('Enter Chat IDs to receive notifications for new customer registrations. Use the same format as above.')
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Telegram Update Notifications'),
                        'name' => 'TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS',
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled'),
                            ],
                        ],
                        'desc' => $this->l('Receive notifications about module updates directly in Telegram. This may slightly slow down your store due to the external API call used for checking updates, but it is recommended to keep it enabled.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Max Messages per Action'),
                        'name' => 'TELEGRAMNOTIFY_MAX_MESSAGES',
                        'size' => 5,
                        'required' => true,
                        'desc' => $this->l('Enter the maximum number of messages to send per action (0 for unlimited).')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Max Retry Attempts'),
                        'name' => 'TELEGRAMNOTIFY_MAX_RETRIES',
                        'size' => 5,
                        'required' => true,
                        'desc' => $this->l('Number of retry attempts for sending messages (0 to disable, recommended for stable connections).')
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('New Order Notification Template'),
                        'name' => 'TELEGRAMNOTIFY_NEW_ORDER_TEMPLATE',
                        'cols' => 60,
                        'rows' => 10,
                        'required' => true,
                        'desc' => $this->l('Available placeholders: {order_reference}, {shop_name}, {customer_name}, {customer_email}, {ip_address}, {country}, {date_time}, {phone_number}, {total_paid}, {shipping_address}, {delivery_method}, {payment_method}, {products_list}, {order_comment}.'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('Admin Login Notification Template'),
                        'name' => 'TELEGRAMNOTIFY_ADMIN_LOGIN_TEMPLATE',
                        'cols' => 60,
                        'rows' => 10,
                        'required' => true,
                        'desc' => $this->l('Available placeholders: {employee_name}, {employee_email}, {ip_address}, {country}, {date_time}.')
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('New Customer Notification Template'),
                        'name' => 'TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE',
                        'cols' => 60,
                        'rows' => 10,
                        'required' => true,
                        'desc' => $this->l('Available placeholders: {customer_name}, {customer_email}, {ip_address}, {country}, {date_time}, {birthday}, {gender}, {newsletter}.')
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right'
                ],
                'buttons' => [
                    [
                        'type' => 'submit',
                        'title' => $this->l('Test Message'),
                        'icon' => 'process-icon-envelope',
                        'name' => 'test_telegram_message',
                        'class' => 'btn btn-default pull-right'
                    ]
                ]
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;

        foreach ($fields_form['form']['input'] as $input) {
            if (isset($input['name'])) {
                $helper->fields_value[$input['name']] = $this->getFromCache($input['name']);
            }
        }

        return $helper->generateForm([$fields_form]);
    }
}
