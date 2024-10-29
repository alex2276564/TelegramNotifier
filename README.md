# TelegramNotifier for PrestaShop 🛍️📱

[![PrestaShop](https://img.shields.io/badge/PrestaShop-1.7%20%7C%208.x-blue.svg)](https://prestashop.com/)
[![PHP](https://img.shields.io/badge/PHP-7.2%20%7C%207.3%20%7C%207.4%20%7C%208.0%20%7C%208.1-8892BF.svg?style=flat-square)](https://www.php.net/)
[![Version](https://img.shields.io/github/v/release/alex2276564/TelegramNotifier?color=blue)](https://github.com/alex2276564/TelegramNotifier/releases/latest)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

TelegramNotifier is a powerful PrestaShop module that sends instant notifications about new orders and admin logins directly to your Telegram. Stay informed about every sale and monitor your store's security in real-time, right on your smartphone or computer!

## ✨ Features

- 🚀 Instant notifications for new orders
- 🔐 Admin login notifications with IP address, country, and timestamp
- 📊 Detailed order information, including product list and shipping address
- 🛠 Easy setup through PrestaShop admin panel
- 👥 Support for multiple notification recipients
- 🔒 Secure data transmission via Telegram API
- 🛠 Integrated error logging system with PrestaShop (PrestaShopLogger)
- 🔄 Automatic check for module updates directly through Telegram messages

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
   - `{ip_address}`: The IP address of the customer 🌐
   - `{country}`: The country of the customer 🏳️ (**Note:** Using this placeholder might slightly slow down your store due to the external API call.) 
   - `{date_time}`: The date and time of the order (server time) 🕒
   - `{phone_number}`: Customer's phone number 📞
   - `{total_paid}`: Total amount paid for the order 💰
   - `{shipping_address}`: Delivery address 🏠
   - `{delivery_method}`: Chosen delivery method 🚚
   - `{payment_method}`: Method of payment used 💳
   - `{products_list}`: List of products in the order 🛍️
   - `{order_comment}`: Any comment left by the customer 📝

4. **Admin Login Notification Template**:
   Customize the notification message for admin logins using these placeholders:
   - `{employee_name}`: Name of the employee who logged in 👤
   - `{employee_email}`: Email address of the employee 📧
   - `{ip_address}`: IP address used for login 🌐
   - `{country}`: Country associated with the IP address 🏳️ (**Note:** Using this placeholder might slightly slow down your store due to the external API call.) 
   - `{date_time}`: Date and time of the login (server time) 🕒

5. **Max Messages per Order**:
   - This setting allows you to control the maximum number of messages sent per order.
   - In most cases, you don't need to change this value.
   - If your store frequently receives many simultaneous orders, it's recommended to reduce this value to 2. This will ensure more efficient message delivery, but the messages may be less detailed.
   - All messages will be sent 100% but not fully detailed if you decrease this value.
   - Set this value to 0 for unlimited messages per order.

6. **Telegram Update Notifications**:
   - Stay informed about new updates to the TelegramNotifier module directly through Telegram messages.
   - **Note:** This feature might slightly slow down your store due to the external API call used to check for updates, but it is recommended to keep it enabled.

7. **Admin Login Notifications**:
   - Enable to receive alerts when an admin logs into the PrestaShop backend, providing security insights in real-time.

8. Save your settings and use the "Test Message" button to verify the configuration.

## ⚠️ Security Note
The Admin Login Notifications feature helps you monitor access to your store's backend. This is especially useful in cases where an employee's account has been compromised, allowing you to react quickly and prevent potential damage. **For example, if you receive a login notification from a country you don't typically operate in, it could indicate unauthorized access.** If you receive a notification for a login you don't recognize, change your admin password immediately!

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
