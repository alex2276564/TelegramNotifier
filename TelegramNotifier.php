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
        $this->version = '1.0.1';
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
            Configuration::updateValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE', $this->getDefaultMessageTemplate());
    }

    public function uninstall()
    {
        return parent::uninstall() &&
            Configuration::deleteByName('TELEGRAMNOTIFY_BOT_TOKEN') &&
            Configuration::deleteByName('TELEGRAMNOTIFY_CHAT_ID') &&
            Configuration::deleteByName('TELEGRAMNOTIFY_MESSAGE_TEMPLATE');
    }

    public function hookActionValidateOrder($params)
    {
        $order = $params['order'];
        $customer = new Customer($order->id_customer);
        $address = new Address($order->id_address_delivery);

        $phoneNumber = !empty($address->phone_mobile) ? $address->phone_mobile : $address->phone;

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
            $attributes = isset($product['attributes']) ? $product['attributes'] : '';
            $productName = $product['product_name'];
            $productslist .= "- " . $productName . " (" . $attributes . ") x " . (int) $product['product_quantity'] . "\n";
        }

        $messageTemplate = Configuration::get('TELEGRAMNOTIFY_MESSAGE_TEMPLATE');
        $message = strtr($messageTemplate, [
            '{order_reference}' => $order->reference,
            '{customer_name}' => $customer->firstname . ' ' . $customer->lastname,
            '{total_paid}' => $order->getOrdersTotalPaid(),
            '{products_list}' => $productslist,
            '{shipping_address}' => $this->formatShippingAddress($address),
            '{payment_method}' => $order->payment,
            '{phone_number}' => $phoneNumber,
            '{order_comment}' => $orderMessage,
            '{delivery_method}' => $deliveryMethod
        ]);

        $this->sendTelegramMessage($message);
    }

    private function sendTelegramMessage($message)
    {
        $botToken = Configuration::get('TELEGRAMNOTIFY_BOT_TOKEN');
        $chatIds = Configuration::get('TELEGRAMNOTIFY_CHAT_ID');

        if (empty($botToken) || empty($chatIds)) {
            $this->logError('Bot Token or Chat ID is empty');
            return false;
        }

        $chatIdsArray = explode(',', $chatIds);
        $success = true;

        // Splitting a message into parts if it exceeds the limit
        $messageParts = $this->splitMessage($message);

        foreach ($chatIdsArray as $chatId) {
            $chatId = trim($chatId);

            if (!ctype_digit($chatId) || strlen($chatId) < 9 || strlen($chatId) > 10) {
                $this->logError('Invalid Chat ID: ' . $chatId);
                $success = false;
                continue;
            }

            foreach ($messageParts as $part) {
                $url = "https://api.telegram.org/bot" . urlencode($botToken) . "/sendMessage";
                $data = [
                    'chat_id' => $chatId,
                    'text' => $part,
                ];

                $options = [
                    'http' => [
                        'method' => 'POST',
                        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'content' => http_build_query($data),
                        'timeout' => 10,
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ];

                $context = stream_context_create($options);
                $result = @file_get_contents($url, false, $context);

                if ($result === false) {
                    $this->logError('Failed to send Telegram message part to chat ID ' . $chatId . ': ' . error_get_last()['message']);
                    $success = false;
                    break;  // Stop sending parts if an error occurs
                } else {
                    // Add delay between sending messages (rate limit)
                    sleep(1);
                }
            }
        }

        return $success;
    }

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

    public function getContent()
    {
        $output = '';

        // Check for updates
        $newVersion = $this->checkForUpdates();
        if ($newVersion) {
            $output .= $this->displayConfirmation($this->l('A new version ' . $newVersion . ' is available. Please update the module.'));
        }

        if (Tools::isSubmit('submit' . $this->name)) {
            $botToken = Tools::getValue('TELEGRAMNOTIFY_BOT_TOKEN');
            $chatId = Tools::getValue('TELEGRAMNOTIFY_CHAT_ID');
            $messageTemplate = Tools::getValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE');

            if (empty($botToken) || empty($chatId)) {
                $output .= $this->displayError($this->l('Bot Token and Chat ID are required.'));
            } else {
                Configuration::updateValue('TELEGRAMNOTIFY_BOT_TOKEN', $botToken);
                Configuration::updateValue('TELEGRAMNOTIFY_CHAT_ID', $chatId);
                Configuration::updateValue('TELEGRAMNOTIFY_MESSAGE_TEMPLATE', $messageTemplate);
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
                    'desc' => $this->l('Enter one or more Chat IDs separated by commas. To get a Chat ID, send a message to your bot and visit the URL: https://api.telegram.org/bot<YourBOTToken>/getUpdates')
                ],
                [
                    'type' => 'textarea',
                    'label' => $this->l('Message Template'),
                    'name' => 'TELEGRAMNOTIFY_MESSAGE_TEMPLATE',
                    'cols' => 60,
                    'rows' => 10,
                    'required' => true,
                    'desc' => $this->l('Available placeholders: {order_reference}, {customer_name}, {total_paid}, {products_list}, {shipping_address}, {payment_method}, {phone_number}, {order_comment}, {delivery_method}')
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
            "📞 Phone: {phone_number}\n" .
            "💰 Amount: {total_paid}\n" .
            "🛍️ Products:\n{products_list}\n" .
            "🏠 Shipping address:\n{shipping_address}\n" .
            "📦 Delivery method: {delivery_method}\n" .
            "💳 Payment method: {payment_method}\n" .
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
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "User-Agent: php",
            ],
        ];
        $context = stream_context_create($opts);
        $result = file_get_contents($url, false, $context);
        $release = json_decode($result, true);

        if (version_compare($release['tag_name'], $this->version, '>')) {
            return $release['tag_name'];
        }

        return false;
    }
}
