# MassEmailer / WordPress Role-Based Bulk Mailer

This repository now contains a production-ready WordPress plugin that allows admins to send formatted HTML campaigns to users by role.

## Plugin path

- `role-based-bulk-mailer/role-based-bulk-mailer.php`

## Features

- Send email campaigns to one or many WordPress roles (`subscriber`, `author`, etc.).
- WYSIWYG HTML body editing via WordPress editor.
- Template creation and reuse with saved subject + body.
- Placeholder support for personalization:
  - `{display_name}`
  - `{user_email}`
  - `{first_name}`
  - `{last_name}`
  - `{role_list}`
  - `{site_name}`
- Campaign history logging with recipient counts and status.

## Installation

1. Copy `role-based-bulk-mailer` into your WordPress site's `wp-content/plugins/` directory.
2. Activate **Role-Based Bulk Mailer** in the WordPress admin plugins screen.
3. Open **Users → Role Mailer**.

## Notes

- Requires a working WordPress mail setup (`wp_mail` SMTP/transport).
- Only users with `manage_options` can access the plugin interface.
