# Job Posting Manager

## Plugin Metadata

- Stable Tag: 5.2.1
- Tested up to: 6.8
- Requires at least: 5.0
- Requires PHP: 7.4
- License: GPL-2.0-or-later
- License URI: https://www.gnu.org/licenses/gpl-2.0.html
- Author: Ericson Palisoc
- Text Domain: job-posting-manager

## Short Description

Manage job postings, applications, and applicant communication with customizable forms, status workflows, and email notifications.

## Description

Job Posting Manager helps WordPress sites run a complete hiring flow from publishing openings to tracking applicants.

Key features:

- Job posting custom post type with company/location metadata
- Drag-and-drop application form builder with reusable templates
- Applicant tracking by application number
- Status workflows with customizable labels/colors
- Automated emails for confirmation, admin alerts, and status updates
- Frontend shortcodes for job listing, filtering, and application tracking
- SMTP-compatible delivery (works with common SMTP plugins)

Included shortcodes:

- `[latest_jobs]`
- `[all_jobs]`
- `[application_tracker]`
- `[user_applications]`
- `[jpm_register]`
- `[jpm_login]`
- `[jpm_forgot_password]`
- `[jpm_reset_password]`
- `[jpm_user_profile]`

This plugin is intended for hiring teams that need an integrated, WordPress-native application management workflow.

## Installation

1. Upload the `job-posting-manager` folder to `/wp-content/plugins/`.
2. Activate the plugin from the WordPress Plugins screen.
3. Configure settings under Job Posting Manager in wp-admin.

## Changelog

### 5.2.1

- Improved query safety and caching in database/frontend/email flows.
- Hardened sanitization, nonce checks, and output escaping across plugin modules.
- Updated uninstall handling and compliance-related cleanup.

## License

This plugin is licensed under the GNU General Public License v2.0 or later.
