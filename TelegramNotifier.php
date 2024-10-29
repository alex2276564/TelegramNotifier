<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class TelegramNotifier extends Module
{
    private $botToken;
    private $chatIds;
    private $messageTemplate;
    private $maxMessages;
    private $updateNotifications;

    public function __construct()
    {
        $this->name = 'TelegramNotifier';
        $this->tab = 'administration';
        $this->version = '1.0.3';
        $this->author = 'alex2276564';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Telegram Order Notifier');
        $this->description = $this->l('Sends a Telegram notification when a new order is placed.');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        $this->loadConfiguration();
    }

    private function getConfigValue($key)
    {
        return Configuration::get($key);
    }

    private function setConfigValue($key, $value)
    {
        return Configuration::updateValue($key, $value);
    }

    private function deleteConfigValue($key)
    {
        return Configuration::deleteByName($key);
    }

    private function loadConfiguration()
    {
        $this->botToken = $this->getConfigValue('TELEGRAMNOTIFY_BOT_TOKEN');
        $this->chatIds = $this->getConfigValue('TELEGRAMNOTIFY_CHAT_ID');
        $this->messageTemplate = $this->getConfigValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE');
        $this->maxMessages = (int) $this->getConfigValue('TELEGRAMNOTIFY_MAX_MESSAGES');
        $this->updateNotifications = (bool) $this->getConfigValue('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS');
    }

    public function install()
    {
        return parent::install() &&
            $this->registerHook('actionValidateOrder') &&
            $this->setConfigValue('TELEGRAMNOTIFY_BOT_TOKEN', '') &&
            $this->setConfigValue('TELEGRAMNOTIFY_CHAT_ID', '') &&
            $this->setConfigValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE', $this->getDefaultMessageTemplate()) &&
            $this->setConfigValue('TELEGRAMNOTIFY_MAX_MESSAGES', 5) &&
            $this->setConfigValue('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS', true);
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_BOT_TOKEN') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_CHAT_ID') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_MAX_MESSAGES') &&
            $this->deleteConfigValue('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS');
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

        $botToken = $this->botToken;
        $chatIds = $this->chatIds;
        $messageTemplate = $this->messageTemplate;
        $maxMessages = $this->maxMessages;
        $validate = $this->validateConfigurationData($botToken, $chatIds, $messageTemplate, $maxMessages);
        if (is_array($validate) && array_key_exists('messageTemplate', $validate)) {
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
    }

    private function sendTelegramMessage($message)
    {
        $botToken = $this->botToken;
        $chatIds = $this->chatIds;
        $maxMessages = $this->maxMessages;

        $chatIdsArray = array_map('trim', explode(',', $chatIds));

        $newVersion = $this->checkForUpdates();
        if ($newVersion && (bool) $this->getConfigValue('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS')) {
            $updateMessage = '🎉 ' . $this->l('A new version of TelegramNotifier is available! Update to') . ' ' . $newVersion . ' ' . $this->l('to get the latest features and bug fixes.') . "\n";
            $updateMessage .= $this->l('Download:') . ' https://github.com/alex2276564/TelegramNotifier/releases/latest' . "\n\n";
            $message = $updateMessage . $message;
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
            $chatId = $getConfigValueFromForm('TELEGRAMNOTIFY_CHAT_ID');
            $messageTemplate = $getConfigValueFromForm('TELEGRAMNOTIFY_MESSAGE_TEMPLATE');
            $maxMessages = $getConfigValueFromForm('TELEGRAMNOTIFY_MAX_MESSAGES');
            $updateNotifications = (bool) $getConfigValueFromForm('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS');

            $validationResult = $this->validateConfigurationData($botToken, $chatId, $messageTemplate, $maxMessages);
            if (is_array($validationResult) && array_key_exists('messageTemplate', $validationResult)) {
                $this->setConfigValue('TELEGRAMNOTIFY_BOT_TOKEN', $botToken);
                $this->setConfigValue('TELEGRAMNOTIFY_CHAT_ID', $chatId);
                $this->setConfigValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE', $validationResult['messageTemplate']);
                $this->setConfigValue('TELEGRAMNOTIFY_MAX_MESSAGES', $maxMessages);
                $this->setConfigValue('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS', $updateNotifications);

                // Reload configuration
                $this->loadConfiguration();

                $output .= $this->displayConfirmation($this->l('Settings updated'));
                if ($validationResult['default']) {
                    $output .= $this->displayWarning($this->l('Message Template is empty. Using default template.'));
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
                    'desc' => $this->l('Receive notifications about module updates directly in Telegram. This may slightly slow down your store, but it is recommended to keep it enabled.'),
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

        $helper->fields_value['TELEGRAMNOTIFY_BOT_TOKEN'] = $this->getConfigValue('TELEGRAMNOTIFY_BOT_TOKEN');
        $helper->fields_value['TELEGRAMNOTIFY_CHAT_ID'] = $this->getConfigValue('TELEGRAMNOTIFY_CHAT_ID');
        $helper->fields_value['TELEGRAMNOTIFY_MESSAGE_TEMPLATE'] = $this->getConfigValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE');
        $helper->fields_value['TELEGRAMNOTIFY_MAX_MESSAGES'] = $this->getConfigValue('TELEGRAMNOTIFY_MAX_MESSAGES');
        $helper->fields_value['TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS'] = $this->getConfigValue('TELEGRAMNOTIFY_UPDATE_NOTIFICATIONS', 1);

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

    private function validateConfigurationData($botToken, $chatIds, $messageTemplate, $maxMessages)
    {
        $errors = [];
        $default = false;

        if (empty($botToken)) {
            $errors[] = $this->l('Bot Token is required.');
        }

        if (empty($chatIds)) {
            $errors[] = $this->l('Chat ID(s) are required.');
        } else {
            $chatIdsArray = array_map('trim', explode(',', $chatIds));
            foreach ($chatIdsArray as $chatId) {
                if (!ctype_digit($chatId) || strlen($chatId) < 9 || strlen($chatId) > 10) {
                    $errors[] = $this->l('Invalid Chat ID: ') . $chatId;
                }
            }
        }
        if (empty($messageTemplate)) {
            $messageTemplate = $this->getDefaultMessageTemplate();
            $default = true;
        }

        if (!is_numeric($maxMessages) || $maxMessages < 0 || !ctype_digit(strval($maxMessages))) {
            $errors[] = $this->l('Max Messages must be a non-negative integer.');
        }

        if (!empty($errors)) {
            return $errors;
        }

        return ['messageTemplate' => $messageTemplate, 'default' => $default];
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

    private function testTelegramMessage()
    {
        $testMessage = 'This is a test message from your PrestaShop Telegram Notifier.';
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
