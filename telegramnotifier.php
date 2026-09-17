<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class TelegramNotifier extends Module
{
    // Configuration keys
    private const CFG_BOT_TOKEN = 'TELEGRAMNOTIFY_BOT_TOKEN';
    private const CFG_NEW_ORDERS_CHAT_ID = 'TELEGRAMNOTIFY_NEW_ORDERS_CHAT_ID';
    private const CFG_ADMIN_LOGIN_CHAT_ID = 'TELEGRAMNOTIFY_ADMIN_LOGIN_CHAT_ID';
    private const CFG_NEW_CUSTOMER_CHAT_ID = 'TELEGRAMNOTIFY_NEW_CUSTOMER_CHAT_ID';
    private const CFG_UPDATE_NOTIFICATIONS = 'TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS';
    private const CFG_UPDATE_CHECK_INTERVAL = 'TELEGRAMNOTIFY_UPDATE_CHECK_INTERVAL';
    private const CFG_MAX_MESSAGES = 'TELEGRAMNOTIFY_MAX_MESSAGES';
    private const CFG_MAX_RETRIES = 'TELEGRAMNOTIFY_MAX_RETRIES';
    private const CFG_NEW_ORDER_TEMPLATE = 'TELEGRAMNOTIFY_NEW_ORDER_TEMPLATE';
    private const CFG_ADMIN_LOGIN_TEMPLATE = 'TELEGRAMNOTIFY_ADMIN_LOGIN_TEMPLATE';
    private const CFG_NEW_CUSTOMER_TEMPLATE = 'TELEGRAMNOTIFY_NEW_CUSTOMER_TEMPLATE';
    private const CFG_LAST_UPDATE_CHECK = 'TELEGRAMNOTIFY_LAST_UPDATE_CHECK';
    private const CFG_CACHED_VERSION = 'TELEGRAMNOTIFY_CACHED_VERSION';

    private $configCache = [];

    private function getConfigSchema()
    {
        return [
            self::CFG_BOT_TOKEN => ['type' => 'string', 'default' => ''],
            self::CFG_NEW_ORDERS_CHAT_ID => ['type' => 'string', 'default' => ''],
            self::CFG_ADMIN_LOGIN_CHAT_ID => ['type' => 'string', 'default' => ''],
            self::CFG_NEW_CUSTOMER_CHAT_ID => ['type' => 'string', 'default' => ''],

            self::CFG_UPDATE_NOTIFICATIONS => ['type' => 'bool', 'default' => true],
            self::CFG_UPDATE_CHECK_INTERVAL => ['type' => 'int', 'default' => 12], // hours
            self::CFG_MAX_MESSAGES => ['type' => 'int', 'default' => 5],
            self::CFG_MAX_RETRIES => ['type' => 'int', 'default' => 0],

            self::CFG_NEW_ORDER_TEMPLATE => ['type' => 'string', 'default' => $this->getDefaultNewOrderTemplate()],
            self::CFG_ADMIN_LOGIN_TEMPLATE => ['type' => 'string', 'default' => $this->getDefaultAdminLoginTemplate()],
            self::CFG_NEW_CUSTOMER_TEMPLATE => ['type' => 'string', 'default' => $this->getDefaultNewCustomerTemplate()],

            self::CFG_LAST_UPDATE_CHECK => ['type' => 'int', 'default' => 0],
            self::CFG_CACHED_VERSION => ['type' => 'string', 'default' => ''],
        ];
    }

    private function castFromDb($key, $value)
    {
        // In PrestaShop, configuration values are stored as strings in database.
        // We use the schema to consistently cast values back to their intended types.
        $schema = $this->getConfigSchema();
        $type = isset($schema[$key]['type']) ? $schema[$key]['type'] : 'string';

        if ($value === false || $value === null) {
            return $schema[$key]['default'] ?? null;
        }

        switch ($type) {
            case 'int':
                return (int) $value;
            case 'bool':
                // PrestaShop stores booleans as '1'/'0' strings; cast to bool here.
                return (bool) $value;
            default:
                return (string) $value;
        }
    }

    private function castToDb($key, $value)
    {
        // Convert typed values to a database-friendly string representation.
        $schema = $this->getConfigSchema();
        $type = isset($schema[$key]['type']) ? $schema[$key]['type'] : 'string';

        switch ($type) {
            case 'int':
                return (string) (int) $value;
            case 'bool':
                return $value ? '1' : '0';
            default:
                return (string) $value;
        }
    }

    private function getFromCache($key)
    {
        if (array_key_exists($key, $this->configCache)) {
            return $this->configCache[$key];
        }

        // In PrestaShop, configuration values are stored as strings in the 'ps_configuration' table.
        // Convert to intended type based on the schema.
        $rawValue = $this->getConfigValue($key);
        $value = $this->castFromDb($key, $rawValue);

        $this->configCache[$key] = $value;
        return $value;
    }

    public function __construct()
    {
        $this->name = 'telegramnotifier';
        $this->tab = 'administration';
        $this->version = '1.0.9';
        $this->author = 'alex2276564';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Telegram Notifier');
        $this->description = $this->l('Sends Telegram notifications for new orders, admin logins (PS 1.7-8), and new customer registrations.');

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
        return Configuration::updateValue($key, $this->castToDb($key, $value));
    }

    private function deleteConfigValue($key)
    {
        unset($this->configCache[$key]);

        return Configuration::deleteByName($key);
    }

    private function loadConfiguration()
    {
        // Load all known configuration keys as per schema into the local cache.
        $this->configCache = [];

        $schema = $this->getConfigSchema();
        $keys = array_keys($schema);

        // Use a single query to fetch all configuration values for the current shop context.
        $rawValues = Configuration::getMultiple($keys);

        foreach ($schema as $key => $meta) {
            $rawValue = array_key_exists($key, $rawValues) ? $rawValues[$key] : false;
            $this->configCache[$key] = $this->castFromDb($key, $rawValue);
        }
    }

    private function isPrestaShop9()
    {
        return version_compare(_PS_VERSION_, '9.0.0', '>=');
    }

    public function install()
    {
        $hooks = [
            'actionValidateOrder',
            'actionCustomerAccountAdd'
        ];

        if (!$this->isPrestaShop9()) {
            $hooks[] = 'actionAdminLoginControllerLoginAfter';
        }

        $installResult = parent::install();

        foreach ($hooks as $hook) {
            $installResult = $installResult && $this->registerHook($hook);
        }

        return $installResult &&
            $this->setConfigValue(self::CFG_BOT_TOKEN, '') &&
            $this->setConfigValue(self::CFG_NEW_ORDERS_CHAT_ID, '') &&
            $this->setConfigValue(self::CFG_ADMIN_LOGIN_CHAT_ID, '') &&
            $this->setConfigValue(self::CFG_NEW_CUSTOMER_CHAT_ID, '') &&
            $this->setConfigValue(self::CFG_UPDATE_NOTIFICATIONS, true) &&
            $this->setConfigValue(self::CFG_UPDATE_CHECK_INTERVAL, 12) &&
            $this->setConfigValue(self::CFG_MAX_MESSAGES, 5) &&
            $this->setConfigValue(self::CFG_MAX_RETRIES, 0) &&
            $this->setConfigValue(self::CFG_NEW_ORDER_TEMPLATE, $this->getDefaultNewOrderTemplate()) &&
            $this->setConfigValue(self::CFG_ADMIN_LOGIN_TEMPLATE, $this->getDefaultAdminLoginTemplate()) &&
            $this->setConfigValue(self::CFG_NEW_CUSTOMER_TEMPLATE, $this->getDefaultNewCustomerTemplate()) &&
            $this->setConfigValue(self::CFG_LAST_UPDATE_CHECK, 0) &&
            $this->setConfigValue(self::CFG_CACHED_VERSION, '');
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            $this->deleteConfigValue(self::CFG_BOT_TOKEN) &&
            $this->deleteConfigValue(self::CFG_NEW_ORDERS_CHAT_ID) &&
            $this->deleteConfigValue(self::CFG_ADMIN_LOGIN_CHAT_ID) &&
            $this->deleteConfigValue(self::CFG_NEW_CUSTOMER_CHAT_ID) &&
            $this->deleteConfigValue(self::CFG_UPDATE_NOTIFICATIONS) &&
            $this->deleteConfigValue(self::CFG_UPDATE_CHECK_INTERVAL) &&
            $this->deleteConfigValue(self::CFG_MAX_MESSAGES) &&
            $this->deleteConfigValue(self::CFG_MAX_RETRIES) &&
            $this->deleteConfigValue(self::CFG_NEW_ORDER_TEMPLATE) &&
            $this->deleteConfigValue(self::CFG_ADMIN_LOGIN_TEMPLATE) &&
            $this->deleteConfigValue(self::CFG_NEW_CUSTOMER_TEMPLATE) &&
            $this->deleteConfigValue(self::CFG_LAST_UPDATE_CHECK) &&
            $this->deleteConfigValue(self::CFG_CACHED_VERSION);
    }

    public function hookActionCustomerAccountAdd($params)
    {
        if (!empty($this->getFromCache(self::CFG_NEW_CUSTOMER_CHAT_ID))) {
            $customer = $params['newCustomer'];
            $newCustomerTemplate = $this->getFromCache(self::CFG_NEW_CUSTOMER_TEMPLATE);

            $placeholders = [
                '{shop_name}' => '',
                '{customer_name}' => '',
                '{customer_email}' => '',
                '{ip_address}' => '',
                '{country}' => '',
                '{date_time}' => '',
                '{birthday}' => '',
                '{gender}' => '',
                '{newsletter}' => '',
            ];

            if (strpos($newCustomerTemplate, '{shop_name}') !== false) {
                $shop = new Shop($customer->id_shop);
                $placeholders['{shop_name}'] = $shop->name;
            }

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
        if (!empty($this->getFromCache(self::CFG_ADMIN_LOGIN_CHAT_ID))) {
            $employee = $params['employee'];
            $adminLoginTemplate = $this->getFromCache(self::CFG_ADMIN_LOGIN_TEMPLATE);

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
        if (!empty($this->getFromCache(self::CFG_NEW_ORDERS_CHAT_ID))) {
            $order = $params['order'];
            $customer = new Customer($order->id_customer);
            $address = new Address($order->id_address_delivery);

            $newOrderTemplate = $this->getFromCache(self::CFG_NEW_ORDER_TEMPLATE);

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
                $totalPaid = $this->getOrderTotalPaid($order);
                $placeholders['{total_paid}'] = $this->formatPrice($totalPaid, $order->id_currency);
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
                $currency = new Currency($order->id_currency);
                $link = Context::getContext()->link;

                foreach ($products as $product) {
                    $attributes = isset($product['attributes']) && !empty($product['attributes'])
                        ? " (" . $product['attributes'] . ")"
                        : "";
                    $productName = $product['product_name'];
                    $productPrice = $product['unit_price_tax_incl'];
                    $formattedPrice = $this->formatPrice($productPrice, $currency->id);
                    $productLink = $link->getProductLink($product['id_product']);
                    $quantity = (int) $product['product_quantity'];

                    $productslist .= "- <a href=\"$productLink\">$productName</a>$attributes" .
                        " x " . $quantity .
                        " (" . $formattedPrice . ")\n";
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
            $this->sendTelegramMessage($message, 'new_order');
        }
    }

    private function sendTelegramMessage($message, $notificationType)
    {
        $botToken = $this->getFromCache(self::CFG_BOT_TOKEN);
        $maxRetries = (int) $this->getFromCache(self::CFG_MAX_RETRIES);

        switch ($notificationType) {
            case 'new_order':
                $chatIds = $this->getFromCache(self::CFG_NEW_ORDERS_CHAT_ID);
                break;
            case 'admin_login':
                $chatIds = $this->getFromCache(self::CFG_ADMIN_LOGIN_CHAT_ID);
                break;
            case 'new_customer':
                $chatIds = $this->getFromCache(self::CFG_NEW_CUSTOMER_CHAT_ID);
                break;
            case 'test':
                $chatIds = $this->getFromCache(self::CFG_NEW_ORDERS_CHAT_ID);
                break;
            default:
                $this->logError('Invalid notification type: ' . $notificationType);
                return false;
        }

        $maxMessages = $this->getFromCache(self::CFG_MAX_MESSAGES);

        $validate = $this->validateConfigurationData(
            $botToken,
            $this->getFromCache(self::CFG_NEW_ORDERS_CHAT_ID),
            $this->getFromCache(self::CFG_ADMIN_LOGIN_CHAT_ID),
            $this->getFromCache(self::CFG_NEW_CUSTOMER_CHAT_ID),
            $this->getFromCache(self::CFG_UPDATE_NOTIFICATIONS),
            $this->getFromCache(self::CFG_UPDATE_CHECK_INTERVAL),
            $maxMessages,
            $maxRetries,
            $this->getFromCache(self::CFG_NEW_ORDER_TEMPLATE),
            $this->getFromCache(self::CFG_ADMIN_LOGIN_TEMPLATE),
            $this->getFromCache(self::CFG_NEW_CUSTOMER_TEMPLATE)
        );
        if (is_array($validate) && isset($validate[0])) {
            $this->logError('Invalid configuration: ' . json_encode($validate));
            return false;
        }

        $chatIdsArray = array_map('trim', explode(',', $chatIds));

        // Add an update notification at the beginning of the message
        if ((bool) $this->getFromCache(self::CFG_UPDATE_NOTIFICATIONS)) {
            $newVersion = $this->checkForUpdates();
            if ($newVersion) {
                $updateMessage = '🎉 ' . $this->l('A new version of TelegramNotifier is available! Update to') . ' ' . $newVersion . ' ' . $this->l('to get the latest features and bug fixes.') . "\n";
                $updateMessage .= $this->l('Download:') . ' https://github.com/alex2276564/TelegramNotifier/releases/latest' . "\n\n";
                $message = $updateMessage . $message;
            }
        }

        $messageParts = $this->splitMessageHtmlSafe($message);

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
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ];
            }
        }

        if (empty($urls)) {
            $this->logError('No valid data for sending the message.');
            return false;
        }

        if ($maxRetries === 0) {
            $headers = [
                'Content-Type: application/json',
                'Accept: application/json',
            ];

            $results = $this->executeCurlRequest($urls, $postData, $headers, true);
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

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        while ($attempt < $maxRetries) {
            $results = $this->executeCurlRequest($urls, $postData, $headers, true);
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

    /**
     * Split long HTML message into chunks not exceeding Telegram's limit (4096 chars),
     * trying not to break HTML tags (especially <a>...</a>).
     * Strategy:
     *  - Pack by lines first to avoid splitting inline tags across parts.
     *  - For a single line exceeding the limit, split it at safe positions:
     *    after </a> if possible, otherwise before <a if it opens at the end,
     *    otherwise at the last whitespace (consuming it), otherwise hard split.
     */
    private function splitMessageHtmlSafe($html, $maxLength = 4096)
    {
        if (mb_strlen($html, 'UTF-8') <= $maxLength) {
            return [$html];
        }

        $lines = preg_split('/\R/u', $html);
        $parts = [];
        $current = '';

        foreach ($lines as $line) {
            $candidate = ($current === '') ? $line : ($current . "\n" . $line);

            if (mb_strlen($candidate, 'UTF-8') <= $maxLength) {
                $current = $candidate;
            } else {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }

                // Split a long single line into safe chunks
                foreach ($this->splitLongLineHtmlSafe($line, $maxLength) as $chunk) {
                    if ($current === '') {
                        $current = $chunk;
                    } else {
                        // Join chunks within the same message with a newline,
                        // to avoid gluing words when we consumed a space.
                        $withNewline = $current . "\n" . $chunk;
                        if (mb_strlen($withNewline, 'UTF-8') <= $maxLength) {
                            $current = $withNewline;
                        } else {
                            $parts[] = $current;
                            $current = $chunk;
                        }
                    }

                    if (mb_strlen($current, 'UTF-8') === $maxLength) {
                        $parts[] = $current;
                        $current = '';
                    }
                }
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Split a single long line safely:
     *  - Prefer to break AFTER a complete <a>...</a> pair within the slice.
     *  - If an <a opens but doesn't close within the slice, break BEFORE <a.
     *  - Otherwise break at the last whitespace (consuming that space).
     *  - As a last resort, hard cut at maxLength.
     */
    private function splitLongLineHtmlSafe($line, $maxLength)
    {
        $chunks = [];
        $remaining = $line;

        while (mb_strlen($remaining, 'UTF-8') > $maxLength) {
            $slice = mb_substr($remaining, 0, $maxLength, 'UTF-8');
            $breakAt = false;
            $splitOnSpace = false;

            // Prefer to break after a complete <a>...</a> pair within the slice
            $lastCloseA = mb_strrpos($slice, '</a>', 0, 'UTF-8');
            $lastOpenA = mb_strrpos($slice, '<a ', 0, 'UTF-8');

            if ($lastCloseA !== false && $lastOpenA !== false && $lastOpenA < $lastCloseA) {
                $breakAt = $lastCloseA + 4; // length of '</a>'
            }

            // If there is an opening <a but no closing </a> inside the slice, break BEFORE <a
            if ($breakAt === false && $lastOpenA !== false && ($lastCloseA === false || $lastOpenA > $lastCloseA)) {
                $breakAt = $lastOpenA;
            }

            // Otherwise, break at the last whitespace
            if ($breakAt === false) {
                $lastSpace = mb_strrpos($slice, ' ', 0, 'UTF-8');
                if ($lastSpace !== false) {
                    $breakAt = $lastSpace;
                    $splitOnSpace = true; // consume the whitespace at the break point
                }
            }

            // As a last resort, hard cut
            if ($breakAt === false || $breakAt < 1) {
                $breakAt = $maxLength;
                $splitOnSpace = false;
            }

            // If we split on a whitespace, consume it (avoid leading space in next chunk)
            if ($splitOnSpace) {
                $breakAt++;
            }

            $chunks[] = mb_substr($remaining, 0, $breakAt, 'UTF-8');
            $remaining = mb_substr($remaining, $breakAt, null, 'UTF-8');
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    private function executeCurlRequest($urls, $postData = null, $headers = [], $multiRequest = false)
    {
        if (!$multiRequest) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $urls);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            if ($postData !== null) {
                curl_setopt($ch, CURLOPT_POST, 1);

                $payload = json_encode($postData);
                if ($payload === false) {
                    $payload = '{}';
                }

                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $buffer = '';
            $bodyTooLarge = false;
            $maxBytes = 262144; // 256 KiB

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($_ch, $chunk) use (&$buffer, &$bodyTooLarge, $maxBytes) {
                $length = strlen($chunk);
                if (strlen($buffer) + $length > $maxBytes) {
                    $bodyTooLarge = true;
                    // Returning 0 aborts the transfer with a write error.
                    return 0;
                }
                $buffer .= $chunk;
                return $length;
            });

            $success = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            unset($ch);

            if ($bodyTooLarge && $error === '') {
                $error = 'HTTP response too large';
            }

            return [
                'result' => ($success && !$bodyTooLarge && $error === '') ? $buffer : '',
                'error' => $error,
                'httpCode' => $httpCode,
            ];
        }

        // Multi-request branch
        $mh = curl_multi_init();
        $curlHandles = [];
        $bodies = [];
        $tooLarge = [];

        foreach ($urls as $index => $url) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            if ($postData !== null && isset($postData[$index])) {
                curl_setopt($ch, CURLOPT_POST, 1);

                $payload = json_encode($postData[$index]);
                if ($payload === false) {
                    $payload = '{}';
                }

                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $bodies[$index] = '';
            $tooLarge[$index] = false;
            $maxBytes = 262144; // 256 KiB

            curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($_ch, $chunk) use (&$bodies, &$tooLarge, $index, $maxBytes) {
                $length = strlen($chunk);
                if (strlen($bodies[$index]) + $length > $maxBytes) {
                    $tooLarge[$index] = true;
                    return 0;
                }
                $bodies[$index] .= $chunk;
                return $length;
            });

            curl_multi_add_handle($mh, $ch);
            $curlHandles[$index] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status == CURLM_OK);

        $results = [];
        foreach ($curlHandles as $index => $ch) {
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($tooLarge[$index] && $error === '') {
                $error = 'HTTP response too large';
            }

            $results[] = [
                'result' => ($error === '' && !$tooLarge[$index]) ? $bodies[$index] : '',
                'error' => $error,
                'httpCode' => $httpCode,
            ];

            curl_multi_remove_handle($mh, $ch);

            unset($curlHandles[$index], $ch);
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

    private function validateConfigurationData($botToken, $newOrdersChatId, $adminLoginChatId, $newCustomerChatId, $updateNotifications, $updateCheckInterval, $maxMessages, $maxRetries, $newOrderTemplate, $adminLoginTemplate, $newCustomerTemplate)
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

        if (!is_numeric($updateCheckInterval) || $updateCheckInterval < 1 || !ctype_digit(strval($updateCheckInterval))) {
            $errors[] = $this->l('Update Check Interval must be a positive integer (hours).');
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

            if (count($chatIdsArray) > 30) {
                $errors[] = $this->l('You can only configure up to 30 ' . $fieldName . 's.');
                return;
            }

            foreach ($chatIdsArray as $chatId) {
                if (!preg_match('/^-?\d{9,15}$/', $chatId)) {
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
            "🏪 Shop: {shop_name}\n" .
            "👤 Customer: {customer_name}\n" .
            "📧 Email: {customer_email}\n" .
            "🌐 IP: {ip_address}\n" .
            "🏳️ Country: {country}\n" .
            "🕒 Date/Time: {date_time} (Server time)\n" .
            "🎂 Birthday: {birthday}\n" .
            "👫 Gender: {gender}\n" .
            "📰 Subscribed to newsletter: {newsletter}";
    }

    // =========================================================================
    // EXTERNAL API SANITIZATION
    // =========================================================================
    /**
     * The following methods apply lightweight, context-aware sanitization
     * strictly to data received from external third-party services (APIs).
     *
     * Internal module data and values originating from the PrestaShop shop
     * environment are NOT processed here, as they are already inherently
     * validated and escaped by PrestaShop's core infrastructure.
     */
    private function sanitizeExternalCountry($value)
    {
        if (!is_string($value)) {
            return 'Unknown';
        }

        // Remove ASCII control characters.
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);

        // Strip angle brackets to prevent HTML tag injection in Telegram (parse_mode=HTML).
        $value = str_replace(['<', '>'], '', $value);

        // Collapse whitespace.
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = trim($value);

        if ($value === '') {
            return 'Unknown';
        }

        // Limit length to a reasonable size.
        if (mb_strlen($value, 'UTF-8') > 60) {
            $value = mb_substr($value, 0, 57, 'UTF-8') . '...';
        }

        return $value;
    }

    private function sanitizeExternalVersionTag($value)
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (!preg_match('/^[0-9A-Za-z._+\-~]{1,40}$/', $value)) {
            return '';
        }

        return $value;
    }

    private function getCountryFromIP($ip)
    {
        // SECURITY NOTE: Plain HTTP is used because ip-api.com requires a paid subscription for HTTPS access.
        // MITM exposure is accepted here as these geolocation data points are non-critical and
        // strictly validated via sanitizeExternalCountry to prevent any injection vectors.
        $url = "http://ip-api.com/json/{$ip}";
        $response = $this->executeCurlRequest($url);
        if ($response['error'] || $response['httpCode'] != 200) {
            return 'Unknown';
        }

        $data = json_decode($response['result'], true);
        if (!is_array($data) || !isset($data['country'])) {
            return 'Unknown';
        }

        return $this->sanitizeExternalCountry($data['country']);
    }

    private function getGenderName($id_gender)
    {
        if (empty($id_gender)) {
            return '';
        }

        $gender = new Gender($id_gender, $this->context->language->id);
        return $gender->name;
    }

    private function getOrderTotalPaid($order)
    {
        if ($this->isPrestaShop9()) {
            return $order->total_paid_tax_incl;
        } else {
            if (method_exists($order, 'getOrdersTotalPaid')) {
                return $order->getOrdersTotalPaid();
            } else {
                return $order->total_paid_tax_incl;
            }
        }
    }

    private function formatPrice($price, $currencyId)
    {
        if ($this->isPrestaShop9()) {
            $currency = new Currency($currencyId);
            return $currency->sign . number_format($price, 2, '.', ' ');
        } else {
            return Tools::displayPrice($price, $currencyId, false);
        }
    }

    private function testTelegramMessage()
    {
        if (empty($this->getFromCache(self::CFG_NEW_ORDERS_CHAT_ID))) {
            return $this->displayError($this->l('Test message only sent to New Orders Chat ID. Please configure it first.'));
        }

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
        $lastCheckTime = $this->getFromCache(self::CFG_LAST_UPDATE_CHECK);
        $checkIntervalHours = $this->getFromCache(self::CFG_UPDATE_CHECK_INTERVAL);
        $checkIntervalSeconds = $checkIntervalHours * 3600; // Convert hours to seconds
        $currentTime = time();

        // Check if we need to perform update check based on configured interval
        if ($lastCheckTime && ($currentTime - $lastCheckTime < $checkIntervalSeconds)) {
            $cachedVersion = $this->getFromCache(self::CFG_CACHED_VERSION);
            return !empty($cachedVersion) ? $cachedVersion : '';
        }

        $url = "https://api.github.com/repos/alex2276564/TelegramNotifier/releases/latest";
        $headers = ["User-Agent: php"];

        $response = $this->executeCurlRequest($url, null, $headers);

        $this->setConfigValue(self::CFG_LAST_UPDATE_CHECK, $currentTime);

        if ($response['error']) {
            $this->logError('Failed to check for updates: ' . $response['error']);
            return '';
        }

        if ($response['httpCode'] != 200) {
            $this->logError('Failed to check for updates: HTTP code ' . $response['httpCode']);
            return '';
        }

        $release = json_decode($response['result'], true);

        if (isset($release['tag_name'])) {
            $tag = $this->sanitizeExternalVersionTag($release['tag_name']);

            if ($tag !== '' && version_compare($tag, $this->version, '>')) {
                $this->setConfigValue(self::CFG_CACHED_VERSION, $tag);
                return $tag;
            }
        }

        // Either tag_name is missing, invalid, or not newer than current version.
        $this->setConfigValue(self::CFG_CACHED_VERSION, '');
        return '';
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
            $botToken = $getConfigValueFromForm(self::CFG_BOT_TOKEN);
            $newOrdersChatId = $getConfigValueFromForm(self::CFG_NEW_ORDERS_CHAT_ID);
            $adminLoginChatId = $getConfigValueFromForm(self::CFG_ADMIN_LOGIN_CHAT_ID);
            $newCustomerChatId = $getConfigValueFromForm(self::CFG_NEW_CUSTOMER_CHAT_ID);
            $updateNotifications = (bool) $getConfigValueFromForm(self::CFG_UPDATE_NOTIFICATIONS);
            $updateCheckInterval = $getConfigValueFromForm(self::CFG_UPDATE_CHECK_INTERVAL);
            $maxMessages = $getConfigValueFromForm(self::CFG_MAX_MESSAGES);
            $maxRetries = $getConfigValueFromForm(self::CFG_MAX_RETRIES);
            $newOrderTemplate = $getConfigValueFromForm(self::CFG_NEW_ORDER_TEMPLATE);
            $adminLoginTemplate = $getConfigValueFromForm(self::CFG_ADMIN_LOGIN_TEMPLATE);
            $newCustomerTemplate = $getConfigValueFromForm(self::CFG_NEW_CUSTOMER_TEMPLATE);

            $validationResult = $this->validateConfigurationData(
                $botToken,
                $newOrdersChatId,
                $adminLoginChatId,
                $newCustomerChatId,
                $updateNotifications,
                $updateCheckInterval,
                $maxMessages,
                $maxRetries,
                $newOrderTemplate,
                $adminLoginTemplate,
                $newCustomerTemplate
            );

            if (is_array($validationResult) && array_key_exists('newOrderTemplate', $validationResult)) {
                $this->setConfigValue(self::CFG_BOT_TOKEN, $botToken);
                $this->setConfigValue(self::CFG_NEW_ORDERS_CHAT_ID, $newOrdersChatId);
                $this->setConfigValue(self::CFG_ADMIN_LOGIN_CHAT_ID, $adminLoginChatId);
                $this->setConfigValue(self::CFG_NEW_CUSTOMER_CHAT_ID, $newCustomerChatId);
                $this->setConfigValue(self::CFG_UPDATE_NOTIFICATIONS, $updateNotifications);
                $this->setConfigValue(self::CFG_UPDATE_CHECK_INTERVAL, $updateCheckInterval);
                $this->setConfigValue(self::CFG_MAX_MESSAGES, $maxMessages);
                $this->setConfigValue(self::CFG_MAX_RETRIES, $maxRetries);
                $this->setConfigValue(self::CFG_NEW_ORDER_TEMPLATE, $validationResult['newOrderTemplate']);
                $this->setConfigValue(self::CFG_ADMIN_LOGIN_TEMPLATE, $validationResult['adminLoginTemplate']);
                $this->setConfigValue(self::CFG_NEW_CUSTOMER_TEMPLATE, $validationResult['newCustomerTemplate']);

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
                        'label' => $this->l('🔑 Telegram Bot Token'),
                        'name' => self::CFG_BOT_TOKEN,
                        'size' => 50,
                        'required' => true,
                        'desc' => $this->l('To get a Bot Token, create a new bot via @BotFather in Telegram.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('📦 New Orders Notification Chat ID(s)'),
                        'name' => self::CFG_NEW_ORDERS_CHAT_ID,
                        'size' => 50,
                        'required' => false,
                        'desc' => $this->l('Enter one or more Chat IDs separated by commas. Use positive numbers for personal chats (e.g., 123456789), negative numbers for group chats (e.g., -987654321), or numbers starting with -100 for channels and some supergroups (e.g., -1001234567890) to receive new order notifications.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('🔐 Admin Login Notifications Chat ID(s)'),
                        'name' => self::CFG_ADMIN_LOGIN_CHAT_ID,
                        'size' => 50,
                        'required' => false,
                        'desc' => $this->l('Enter Chat IDs to receive notifications when someone logs into the admin panel. Use the same format as above.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('🆕 New Customer Registration Notifications Chat ID(s)'),
                        'name' => self::CFG_NEW_CUSTOMER_CHAT_ID,
                        'size' => 50,
                        'required' => false,
                        'desc' => $this->l('Enter Chat IDs to receive notifications for new customer registrations. Use the same format as above.')
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('🔔 Telegram Update Notifications'),
                        'name' => self::CFG_UPDATE_NOTIFICATIONS,
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
                        'desc' => $this->l('Receive notifications about module updates directly in Telegram.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('⏰ Update Check Interval (hours)'),
                        'name' => self::CFG_UPDATE_CHECK_INTERVAL,
                        'size' => 5,
                        'required' => true,
                        'desc' => $this->l('How often to check for module updates (in hours). Default: 12 hours.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('📊 Max Messages per Action'),
                        'name' => self::CFG_MAX_MESSAGES,
                        'size' => 5,
                        'required' => true,
                        'desc' => $this->l('Enter the maximum number of messages to send per action (0 for unlimited). Default: 5.')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('🔄 Max Retry Attempts'),
                        'name' => self::CFG_MAX_RETRIES,
                        'size' => 5,
                        'required' => true,
                        'desc' => $this->l('Number of retry attempts for sending messages (0 to disable, recommended for stable connections). Default: 0.')
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('📝 New Order Notification Template'),
                        'name' => self::CFG_NEW_ORDER_TEMPLATE,
                        'cols' => 60,
                        'rows' => 10,
                        'required' => true,
                        'desc' => $this->l('Available placeholders: {order_reference}, {shop_name}, {customer_name}, {customer_email}, {ip_address}, {country}, {date_time}, {phone_number}, {total_paid}, {shipping_address}, {delivery_method}, {payment_method}, {products_list}, {order_comment}.'),
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('🔒 Admin Login Notification Template'),
                        'name' => self::CFG_ADMIN_LOGIN_TEMPLATE,
                        'cols' => 60,
                        'rows' => 10,
                        'required' => true,
                        'desc' => $this->l('Available placeholders: {employee_name}, {employee_email}, {ip_address}, {country}, {date_time}.')
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->l('👤 New Customer Notification Template'),
                        'name' => self::CFG_NEW_CUSTOMER_TEMPLATE,
                        'cols' => 60,
                        'rows' => 10,
                        'required' => true,
                        'desc' => $this->l('Available placeholders: {shop_name}, {customer_name}, {customer_email}, {ip_address}, {country}, {date_time}, {birthday}, {gender}, {newsletter}.')
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
