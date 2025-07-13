# TelegramNotifier for PrestaShop 🛍️📱

[![PrestaShop](https://img.shields.io/badge/PrestaShop-1.7%20%7C%208.x-blue.svg)](https://prestashop.com/)
[![PHP](https://img.shields.io/badge/PHP-7.2%20%7C%207.3%20%7C%207.4%20%7C%208.0%20%7C%208.1-8892BF.svg?style=flat-square)](https://www.php.net/)
[![Version](https://img.shields.io/github/v/release/alex2276564/TelegramNotifier?color=blue)](https://github.com/alex2276564/TelegramNotifier/releases/latest)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

TelegramNotifier is a powerful PrestaShop module that sends instant notifications about new orders, admin logins, and new customer registrations directly to your Telegram. Stay informed about every sale, monitor your store's security, and keep track of new customers in real-time, right on your smartphone or computer!

## ✨ Features

- 🚀 Instant notifications for new orders
- 🔐 Admin login notifications with IP address, country, and timestamp
- 👤 New customer registration notifications with detailed customer information
- 📊 Detailed order information, including product list and shipping address
- 🛠 Easy setup through PrestaShop admin panel
- 👥 Support for multiple notification recipients
- 🔒 Secure data transmission via Telegram API
- 🛠 Integrated error logging system with PrestaShop (PrestaShopLogger)
- 🔄 Automatic check for module updates directly through Telegram messages
- 🌐 Multi-shop support with `{shop_name}` placeholder
- 🔗 **Supports groups and channels**: You can add the bot to Telegram groups and channels and receive notifications there

## 📦 Installation

1. Download the latest version of the module from the [Releases](https://github.com/alex2276564/TelegramNotifier/releases) section.
2. Log in to your PrestaShop admin panel.
3. Navigate to "Modules" > "Upload a module".
4. Upload the downloaded ZIP file of the module.
5. Once installed, find "TelegramNotifier" in the module list and click "Configure".

## 🛠️ How It Works

After installing TelegramNotifier, you'll enter the module configuration and encounter several settings. Here's how to set up your notification system:

1. **Telegram Bot Token:**
   - Create a new bot via [@BotFather](https://t.me/BotFather) on Telegram.
   - Obtain the Bot Token provided by BotFather.

2. **Telegram Chat ID(s):**

   A **Chat ID** is required to specify where the bot should send notifications.

   **There are different methods to obtain Chat IDs. For most cases and first-time setup, we recommend starting with personal chat method as it's the simplest to understand:**

   **Personal Chat Method (Recommended for First Setup):**
   - Send any message to your newly created bot (like "Hello").
   - Open the following link in your browser (replace `<YourBOTToken>` with your bot's token):

   ```text
   https://api.telegram.org/bot<YourBOTToken>/getUpdates
   ```

   - In the JSON response, look for **`"chat": {"id": ...}`** – this is your **Chat ID** (e.g., `123456789`).
   - Add this chat ID to your configuration (for example, in the "New Orders Notifications" field).
   - You can enter multiple chat IDs separated by commas (e.g., `123456789, -987654321, -1001234567890`).
   - You can enter up to **30** chat IDs per notification type (**Telegram API limit**).

   **Other Methods (Groups and Channels):**
   If you want to experiment with team notifications or broadcasts, you can also use:

   - **Group Chat Method:**
     1. Add the bot to the group.
     2. Send any message in the group with the bot (like "Hello").
     3. Visit `https://api.telegram.org/bot<YourBOTToken>/getUpdates` to get the chat ID of the group.
        **Note:** The chat ID for groups will be **negative** (e.g., `-987654321`).
     4. Add this chat ID to your configuration.
   - **Channel Method:**
     1. Add the bot as an administrator of the channel.
     2. Open **[Web Telegram](https://web.telegram.org/a/)** and navigate to the channel.
     3. Look at the **URL** in your browser; it will be something like:

        ```text
        https://web.telegram.org/a/#-1001234567890
        ```

        **Channel Chat IDs always start with** `-100` (e.g., `-1001234567890`).
     4. Add this chat ID to your configuration.

   **Important Validation Rule:** At least one of the three notification types (**New Orders, Admin Login, or New Customer**) must have at least one chat ID specified. This is required for the module to work - the validator won't allow saving settings without at least one chat ID in any of these notification types.

   **This means you can be selective:** For example, you can fill in only the "New Orders Notifications" field with your Chat ID and leave "Admin Login" and "New Customer" fields empty if you only want order notifications. The validator will approve this configuration. However, you cannot leave all three notification types completely empty.

   **Example usage:**
   - If you have a courier, you can add their chat ID to the **New Orders Notifications** (to avoid sending them unnecessary notifications).
   - You can add a sysadmin's chat ID to all types of notifications.

3. **Telegram Update Notifications:**
   - Stay informed about new updates to the TelegramNotifier module directly through Telegram messages.

4. **Max Messages per Action:**
   - This setting allows you to control the maximum number of messages sent per action.
   - In most cases, you don't need to change this value.
   - If your store generates many simultaneous Telegram notifications, it's recommended to reduce this value to **2**. This will ensure more efficient message delivery through Telegram API, but the messages may be less detailed.
   - All messages will be sent 100%, but with reduced details if you lower this value.
   - **Important:** If you reduce this value, make sure the most important information appears first in your message templates (like order reference, customer name, total paid). The default templates are already optimized with critical data at the top.
   - Set this value to **0** for unlimited messages per action.

5. **Max Retry Attempts:**
   - Number of retry attempts if sending fails.
   - Set to **0** to disable retries (**recommended** for dedicated hosting with a stable network).
   - Increase this value (**1-3**) for shared hosting or an unstable network.
   - **Note:** The Telegram API can sometimes return incorrect responses, so it's better to keep this at **0** on stable connections.
   - **Tip:** Enable this option (**3-10**) if you have:
     - Multiple PrestaShop installations with TelegramNotifier on the same server (sharing the same IP address)
     - Multiple shops sending notifications to the same chat IDs (even with different bot tokens)

     This can help manage Telegram's rate limits when multiple notifications are sent simultaneously. Telegram applies rate limits per chat, not per bot token.

6. **Message Templates:**
   Customize the notification messages using available placeholders:

   **New Order Notification Template:**
   - `{order_reference}`: The unique order reference 📦
   - `{shop_name}`: The name of the shop 🛍️
   - `{customer_name}`: Name of the customer 👤
   - `{customer_email}`: Email address of the customer 📧
   - `{ip_address}`: The IP address of the customer 🌐
   - `{country}`: The country of the customer 🏳️
   - `{date_time}`: The date and time of the order (server time) 🕒
   - `{phone_number}`: Customer's phone number 📞
   - `{total_paid}`: Total amount paid for the order 💰
   - `{shipping_address}`: Delivery address 🏠
   - `{delivery_method}`: Chosen delivery method 🚚
   - `{payment_method}`: Method of payment used 💳
   - `{products_list}`: List of products in the order 🛍️
   - `{order_comment}`: Any comment left by the customer 📝

   **Admin Login Notification Template:**
   - `{employee_name}`: Name of the employee who logged in 👤
   - `{employee_email}`: Email address of the employee 📧
   - `{ip_address}`: IP address used for login 🌐
   - `{country}`: Country associated with the IP address 🏳️
   - `{date_time}`: Date and time of the login (server time) 🕒

   **New Customer Notification Template:**
   - `{customer_name}`: Name of the customer 👤
   - `{customer_email}`: Email address of the customer 📧
   - `{ip_address}`: The IP address of the customer 🌐
   - `{country}`: The country of the customer 🏳️
   - `{date_time}`: The date and time of the registration (server time) 🕒
   - `{birthday}`: Customer's birthday 🎂
   - `{gender}`: Customer's gender 👫
   - `{newsletter}`: Whether the customer subscribed to the newsletter 📰

7. **Final Step:**
   Save your settings and use the **"Test Message"** button to verify the configuration.

   **Note:** The test message will only be sent to the chat ID(s) specified in the **New Orders Notifications** field.

## ⚠️ Notes

- Using the `{country}` placeholder in the message templates might slightly slow down your store due to the external API call required to retrieve the country information.

## 🔐 Security Note

The Admin Login Notifications feature helps you monitor access to your store's backend. This is especially useful in cases where an employee's account has been compromised, allowing you to react quickly and prevent potential damage. **For example, if you receive a login notification from a country you don't typically operate in, it could indicate unauthorized access.** If you receive a notification for a login you don't recognize, change your admin password immediately!

## 🔧 Troubleshooting

### 📋 Viewing Error Logs

Errors can be found in the Advanced Parameters and Logs section of the PrestaShop admin panel. You can navigate to `Advanced Parameters > Logs` to view the event log and debug any issues related to the module.

### 🔒 Unable to Access Admin Panel

If you've configured chat IDs for admin login notifications and are now unable to access the admin panel, follow these steps:

1. **📡 Connect to Server**: Connect to your server via FTP/SFTP.
2. **📁 Locate Root Directory**: Navigate to the root directory of your PrestaShop installation.
3. **📂 Find Modules Folder**: Go to the `/modules` folder.
4. **🗑️ Remove Module**: Locate and delete the `TelegramNotifier` folder.

This will disable the module and should restore your ability to access the admin panel. After regaining access, you can reinstall the module and reconfigure it with the correct settings.

### ⚠️ Important Note

Remember to always test your configuration thoroughly, especially when setting up admin login notifications, to ensure you don't accidentally lock yourself out of the admin panel.

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
