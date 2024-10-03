<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class TelegramNotifier extends Module
{
    public function __construct()
    {
        $this->name = 'TelegramNotifier';
        $this->tab = 'administration';
        $this->version = '1.0.2';
        $this->author = 'alex2276564';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Telegram Order Notifier');
        $this->description = $this->l('Sends a Telegram notification when a new order is placed.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    public function install()
    {
        return parent::install() &&
            $this->registerHook('actionValidateOrder') &&
            Configuration::updateValue('TELEGRAMNOTIFY_BOT_TOKEN', '') &&
            Configuration::updateValue('TELEGRAMNOTIFY_CHAT_ID', '') &&
            Configuration::updateValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE', $this->getDefaultMessageTemplate()) &&
            Configuration::updateValue('TELEGRAMNOTIFY_MAX_MESSAGES', 5);
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            Configuration::deleteByName('TELEGRAMNOTIFY_BOT_TOKEN') &&
            Configuration::deleteByName('TELEGRAMNOTIFY_CHAT_ID') &&
            Configuration::deleteByName('TELEGRAMNOTIFY_MESSAGE_TEMPLATE') &&
            Configuration::deleteByName('TELEGRAMNOTIFY_MAX_MESSAGES');
    }

    public function hookActionValidateOrder($params)
    {
        $order = $params['order'];
        $customer = new Customer($order->id_customer);
        $address = new Address($order->id_address_delivery);

        $phoneNumber = !empty($address->phone_mobile) ? $address->phone_mobile : $address->phone;

        $customerEmail = $customer->email;

        $orderMessage = '';
        if ($order->id) {
            $orderMessages = Message::getMessagesByOrderId((int) $order->id);
            if (!empty($orderMessages)) {
                $orderMessage = $orderMessages[0]['message'];
            }
        }

        $carrier = new Carrier($order->id_carrier);
        $deliveryMethod = $carrier->name;

        $productslist = '';
        $products = $order->getProducts();

        foreach ($products as $product) {
            $attributes = isset($product['attributes']) && !empty($product['attributes']) ? " (" . $product['attributes'] . ")" : "";
            $productName = $product['product_name'];
            $productslist .= "- " . $productName . $attributes . " x " . (int) $product['product_quantity'] . "\n";
        }

        $messageTemplate = Configuration::get('TELEGRAMNOTIFY_MESSAGE_TEMPLATE');
        $message = strtr($messageTemplate, [
            '{order_reference}' => $order->reference,
            '{customer_name}' => $customer->firstname . ' ' . $customer->lastname,
            '{customer_email}' => $customerEmail,
            '{phone_number}' => $phoneNumber,
            '{total_paid}' => $order->getOrdersTotalPaid(),
            '{shipping_address}' => $this->formatShippingAddress($address),
            '{delivery_method}' => $deliveryMethod,
            '{payment_method}' => $order->payment,
            '{products_list}' => $productslist,
            '{order_comment}' => $orderMessage,
        ]);

        $this->sendTelegramMessage($message);
    }

    private function sendTelegramMessage($message)
    {
        $botToken = Configuration::get('TELEGRAMNOTIFY_BOT_TOKEN');
        $chatIds = Configuration::get('TELEGRAMNOTIFY_CHAT_ID');
        $maxMessages = (int) Configuration::get('TELEGRAMNOTIFY_MAX_MESSAGES');

        if (empty($botToken) || empty($chatIds)) {
            $this->logError('Bot Token or Chat ID is empty');
            return false;
        }

        $chatIdsArray = array_map('trim', explode(',', $chatIds));
        $messageParts = $this->splitMessage($message);

        if ($maxMessages > 0) {
            $messageParts = array_slice($messageParts, 0, $maxMessages);
        }

        $urls = [];
        $postData = [];

        foreach ($chatIdsArray as $chatId) {
            if (!ctype_digit($chatId) || strlen($chatId) < 9 || strlen($chatId) > 10) {
                $this->logError('Invalid Chat ID: ' . $chatId);
                continue;
            }

            foreach ($messageParts as $part) {
                $urls[] = "https://api.telegram.org/bot" . urlencode($botToken) . "/sendMessage";
                $postData[] = [
                    'chat_id' => $chatId,
                    'text' => $part,
                ];
            }
        }

        if (empty($urls)) {
            $this->logError('No valid Chat IDs found');
            return false;
        }

        $results = $this->executeCurlRequest($urls, $postData, [], true);

        $success = true;
        foreach ($results as $index => $result) {
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

    //Splits a message into parts if its length exceeds the maximum allowed length
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

    public function getContent()
    {
        $output = '';

        $newVersion = $this->checkForUpdates();
        if ($newVersion) {
            $output .= $this->displayConfirmation($this->l('A new version ' . $newVersion . ' is available. Please update the module.'));
        }

        if (Tools::isSubmit('submit' . $this->name)) {
            $botToken = Tools::getValue('TELEGRAMNOTIFY_BOT_TOKEN');
            $chatId = Tools::getValue('TELEGRAMNOTIFY_CHAT_ID');
            $messageTemplate = Tools::getValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE');
            $maxMessages = Tools::getValue('TELEGRAMNOTIFY_MAX_MESSAGES');

            if (empty($botToken) || empty($chatId)) {
                $output .= $this->displayError($this->l('Bot Token or Chat ID are required.'));
            } elseif (!is_numeric($maxMessages) || $maxMessages < 0) {
                $output .= $this->displayError($this->l('Max Messages must be a non-negative number.'));
            } else {
                Configuration::updateValue('TELEGRAMNOTIFY_BOT_TOKEN', $botToken);
                Configuration::updateValue('TELEGRAMNOTIFY_CHAT_ID', $chatId);
                Configuration::updateValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE', $messageTemplate);
                Configuration::updateValue('TELEGRAMNOTIFY_MAX_MESSAGES', $maxMessages);
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        if (Tools::isSubmit('test_telegram_message')) {
            $output .= $this->testTelegramMessage();
        }

        return $output . $this->displayForm();
    }

    public function displayForm()
    {
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');

        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l('Settings'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Telegram Bot Token'),
                    'name' => 'TELEGRAMNOTIFY_BOT_TOKEN',
                    'size' => 50,
                    'required' => true,
                    'desc' => $this->l('To get a Bot Token, create a new bot via @BotFather in Telegram.'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Telegram Chat ID(s)'),
                    'name' => 'TELEGRAMNOTIFY_CHAT_ID',
                    'size' => 50,
                    'required' => true,
                    'desc' => $this->l('Enter one or more Chat IDs separated by commas. To get a Chat ID, send a message to your bot and visit the URL: https://api.telegram.org/bot<YourBOTToken>/getUpdates.')
                ],
                [
                    'type' => 'textarea',
                    'label' => $this->l('Message Template'),
                    'name' => 'TELEGRAMNOTIFY_MESSAGE_TEMPLATE',
                    'cols' => 60,
                    'rows' => 10,
                    'required' => true,
                    'desc' => $this->l('Available placeholders: {order_reference}, {customer_name}, {customer_email}, {total_paid}, {products_list}, {shipping_address}, {payment_method}, {phone_number}, {order_comment}, {delivery_method}.')
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Max Messages per Order'),
                    'name' => 'TELEGRAMNOTIFY_MAX_MESSAGES',
                    'size' => 5,
                    'required' => true,
                    'desc' => $this->l('Enter the maximum number of messages to send per order (0 for unlimited).')
                ]
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

        $helper->fields_value['TELEGRAMNOTIFY_BOT_TOKEN'] = Configuration::get('TELEGRAMNOTIFY_BOT_TOKEN');
        $helper->fields_value['TELEGRAMNOTIFY_CHAT_ID'] = Configuration::get('TELEGRAMNOTIFY_CHAT_ID');
        $helper->fields_value['TELEGRAMNOTIFY_MESSAGE_TEMPLATE'] = Configuration::get('TELEGRAMNOTIFY_MESSAGE_TEMPLATE');
        $helper->fields_value['TELEGRAMNOTIFY_MAX_MESSAGES'] = Configuration::get('TELEGRAMNOTIFY_MAX_MESSAGES');

        return $helper->generateForm($fields_form);
    }

    private function formatShippingAddress($address)
    {
        $formattedAddress = $address->address1;
        if (!empty($address->address2)) {
            $formattedAddress .= ", " . $address->address2;
        }
        $formattedAddress .= "\n" . $address->postcode . " " . $address->city;
        if (!empty($address->state)) {
            $formattedAddress .= ", " . $address->state;
        }
        $formattedAddress .= "\n" . $address->country;

        return $formattedAddress;
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

    private function getDefaultMessageTemplate()
{
    return "🆕 New order #{order_reference}\n" .
        "👤 Customer: {customer_name}\n" .
        "📧 Email: {customer_email}\n" .
        "📞 Phone: {phone_number}\n" .
        "💰 Amount: {total_paid}\n" .
        "🏠 Shipping address:\n{shipping_address}\n" .
        "📦 Delivery method: {delivery_method}\n" .
        "💳 Payment method: {payment_method}\n" .
        "🛍️ Products:\n{products_list}\n" .
        "📝 Comment: {order_comment}";
}

    public function testTelegramMessage()
    {
        $testMessage = "This is a test message from your PrestaShop Telegram Notifier.";
        $result = $this->sendTelegramMessage($testMessage);

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
}
