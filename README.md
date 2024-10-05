# TelegramNotifier for PrestaShop 🛍️📱

[![PrestaShop](https://img.shields.io/badge/PrestaShop-1.7%20%7C%208.x-blue.svg)](https://prestashop.com/)
[![PHP](https://img.shields.io/badge/PHP-7.2%20%7C%207.3%20%7C%207.4%20%7C%208.0%20%7C%208.1-8892BF.svg?style=flat-square)](https://www.php.net/)
[![Version](https://img.shields.io/github/v/release/alex2276564/TelegramNotifier?color=blue)](https://github.com/alex2276564/TelegramNotifier/releases/latest)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

TelegramNotifier is a powerful PrestaShop module that sends instant notifications about new orders directly to your Telegram. Stay informed about every sale in real-time, right on your smartphone or computer!

## ✨ Features

- 🚀 Instant notifications for new orders
- 📊 Detailed order information, including product list and shipping address
- 🛠 Easy setup through PrestaShop admin panel
- 👥 Support for multiple notification recipients
- 🔒 Secure data transmission via Telegram API
- 🛠 Integrated error logging system with PrestaShop (PrestaShopLogger)

## 📦 Installation

1. Download the latest version of the module from the [Releases](https://github.com/alex2276564/TelegramNotifier/releases) section.
2. Log in to your PrestaShop admin panel.
3. Navigate to "Modules" > "Upload a module".
4. Upload the downloaded ZIP file of the module.
5. Once installed, find "TelegramNotifier" in the module list and click "Configure".

## ⚙️ Configuration

1. **Telegram Bot Token**: 
   - Create a new bot via [@BotFather](https://t.me/BotFather) on Telegram.
   - Obtain the Bot Token provided by BotFather.

2. **Telegram Chat ID(s)**:
   - Send a message to your newly created bot.
   - Visit `https://api.telegram.org/bot<YourBOTToken>/getUpdates` to get the chat ID.
   - You can enter multiple chat IDs separated by commas.

3. **Message Template**:
   Customize the notification message using available placeholders:
   - `{order_reference}`: The unique order reference 📦
   - `{customer_name}`: Name of the customer 👤
   - `{customer_email}`: Email address of the customer 📧
   - `{phone_number}`: Customer's phone number 📞
   - `{total_paid}`: Total amount paid for the order 💰
   - `{shipping_address}`: Delivery address 🏠
   - `{delivery_method}`: Chosen delivery method 🚚
   - `{payment_method}`: Method of payment used 💳
   - `{products_list}`: List of products in the order 🛍️
   - `{order_comment}`: Any comment left by the customer 📝

4. **Max Messages per Order**:
   - This setting allows you to control the maximum number of messages sent per order.
   - In most cases, you don't need to change this value.
   - If your store frequently receives many simultaneous orders, it's recommended to reduce this value to 2. This will ensure more efficient message delivery, but the messages may be less detailed.
   - All messages will be sent 100% but not fully detailed if you decrease this value.
   - Set this value to 0 for unlimited messages per order.

4. Save your settings and use the "Test Message" button to verify the configuration.

## 🔧 Troubleshooting

Errors can be found in the Advanced Parameters and Logs section of the PrestaShop admin panel. You can navigate to `Advanced Parameters > Logs` to view the event log and debug any issues related to the module.

## 🆘 Support

If you encounter any issues or have suggestions for improving the module, please create an [issue](https://github.com/alex2276564/TelegramNotifier/issues) in this repository.

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 👨‍💻 Author

[Alex] - [https://github.com/alex2276564]

We appreciate your contribution to the project! If you like this module, please give it a star on GitHub.

## 🤝 Contributing

Contributions, issues, and feature requests are welcome! Feel free to check the [issues page](https://github.com/alex2276564/TelegramNotifier/issues).

### How to Contribute

1. Fork the repository.
2. Create a new branch (`git checkout -b feature/YourFeature`).
3. Commit your changes (`git commit -m 'Add some feature'`).
4. Push to the branch (`git push origin feature/YourFeature`).
5. Open a Pull Request.

---

Thank you for using **TelegramNotifier for PrestaShop**! We hope it helps you stay on top of your e-commerce business. 🚀📊
