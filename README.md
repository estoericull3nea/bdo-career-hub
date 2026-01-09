# Job Posting Manager

A comprehensive WordPress plugin for managing job postings, collecting applications, and streamlining the hiring process through a user-friendly interface with customizable application forms, automated email notifications, and robust application tracking capabilities.

**Version:** 1.0.0  
**Author:** Ericson Palisoc  
**License:** GPL v2 or later  
**Text Domain:** job-posting-manager

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Getting Started](#getting-started)
- [Shortcodes](#shortcodes)
- [Admin Features](#admin-features)
- [Form Builder](#form-builder)
- [Email System](#email-system)
- [Application Tracking](#application-tracking)
- [File Structure](#file-structure)
- [Development](#development)
- [Support](#support)
- [License](#license)

---

## Overview

Job Posting Manager transforms any WordPress site into a fully functional job board with:

- **Custom Post Type** for job postings with rich metadata
- **Drag-and-Drop Form Builder** for creating custom application forms
- **Multi-Step Application Forms** with intelligent step detection
- **Automated Email Notifications** for applicants and administrators
- **Application Status Tracking** with color-coded status badges
- **Guest and Logged-in User Support** with automatic account creation
- **Template-Based Form Management** for reusable form configurations

---

## Features

### Core Functionality

#### 1. Job Posting Management

- Custom post type (`job_posting`) with public-facing pages
- Rich metadata fields:
  - Company name
  - Location
  - Salary information
  - Job duration/type
  - Company image/logo
- Full WordPress editor support for job descriptions
- Categories and tags support

#### 2. Application Management System

- Custom database table for application storage
- Unique application numbers (format: YY-BDO-XXXXXXXX)
- Application status workflow:
  - Pending
  - Under Review
  - Shortlisted
  - Interview Scheduled
  - Accepted
  - Rejected
  - Withdrawn
- Duplicate prevention for logged-in users
- Guest application support with automatic user account creation

#### 3. Drag-and-Drop Form Builder

- Visual form builder interface in admin
- 10+ field types:
  - Text
  - Textarea
  - Email
  - Phone/Tel
  - Select (dropdown)
  - Checkbox
  - Radio
  - File Upload
  - Date
  - Number
- Column-based layout system (12-column grid)
- Multi-column support (up to 3 fields per row)
- Real-time field editing
- Field reordering via drag-and-drop

#### 4. Multi-Step Application Forms

- Automatic step detection from field layout
- Intelligent step grouping
- Review step before submission
- Client-side and server-side validation
- Field-level error messages
- Progress indicators

#### 5. Email Notification System

- **Confirmation Emails** to applicants upon submission
- **Admin Notification Emails** for new applications
- **Status Update Emails** when application status changes
- **Account Creation Emails** for new users
- Customizable email templates with placeholders
- SMTP integration support (external plugins or built-in)

#### 6. Application Tracking

- Public application tracker shortcode
- Application lookup by application number
- Real-time status updates
- Detailed application information display

#### 7. Frontend Display Features

- Latest jobs shortcode with customizable count
- Full job listings with search and filters
- Quick View modal for job details
- Responsive job cards with company images
- Location and company filtering
- Pagination support

---

## Requirements

- **WordPress:** 5.0 or higher
- **PHP:** 7.4 or higher
- **MySQL:** 5.6 or higher
- **Recommended:** SMTP plugin (WP Mail SMTP or similar) for reliable email delivery

---

## Installation

### Manual Installation

1. Download the plugin files
2. Upload the `job-posting-manager` folder to `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. The plugin will automatically:
   - Create the necessary database tables
   - Initialize default form template
   - Set up SMTP settings (if no external SMTP plugin is detected)

### Post-Installation Setup

1. **Configure SMTP** (Settings → Job Posting Manager → Settings)

   - If you have an external SMTP plugin (e.g., WP Mail SMTP), it will be automatically detected
   - Otherwise, configure the built-in SMTP settings
   - Test the SMTP connection

2. **Customize Email Templates** (Job Posting Manager → Email Templates)

   - Edit confirmation email template
   - Edit admin notification template
   - Edit status update template
   - Customize colors and content

3. **Create Your First Job Posting**
   - Go to Job Posting Manager → Job Postings → Add New
   - Fill in job details (title, description, company, location, etc.)
   - Build your application form using the Form Builder
   - Publish the job posting

---

## Getting Started

### Creating a Job Posting

1. Navigate to **Job Posting Manager → Job Postings → Add New**
2. Enter the job title and description
3. Fill in the job metadata:
   - Company Name
   - Location
   - Salary
   - Duration
   - Company Image (upload logo)
4. Build the application form (see [Form Builder](#form-builder) section)
5. Publish the job posting

### Building an Application Form

1. In the job posting editor, scroll to the **Application Form Builder** meta box
2. Click **+ Add Field** to add form fields
3. Select a field type from the popup
4. Configure the field:
   - Label
   - Field name (auto-generated, can be edited)
   - Required status
   - Placeholder text
   - Options (for select/radio/checkbox)
   - Description/help text
5. Drag fields to reorder them
6. Fields automatically arrange into rows based on available space
7. Save the job posting

### Using Form Templates

1. Go to **Job Posting Manager → Form Templates**
2. Create a new template or edit an existing one
3. Build your form fields (same as above)
4. Save the template
5. Optionally set it as the default template
6. Apply templates to job postings via the template selector

---

## Shortcodes

### Latest Jobs

Display the latest job postings on any page or post.

```
[latest_jobs count="3" view_all_url=""]
```

**Parameters:**

- `count` (optional): Number of jobs to display (default: 3)
- `view_all_url` (optional): URL for the "View All Jobs" link

**Example:**

```
[latest_jobs count="5" view_all_url="/jobs/"]
```

### All Jobs

Display all job postings with search, filters, and pagination.

```
[all_jobs per_page="12"]
```

**Parameters:**

- `per_page` (optional): Number of jobs per page (default: 12)

**Features:**

- Search by job title
- Filter by location
- Filter by company
- Pagination
- Quick View modal
- Results count display

### Application Tracker

Allow applicants to track their application status.

```
[application_tracker title="Track Your Application"]
```

**Parameters:**

- `title` (optional): Title for the tracker section (default: "Track Your Application")

**Features:**

- Application number lookup
- Status display with color-coded badges
- Application details display
- Status descriptions

### User Applications

Display logged-in user's applications (requires user to be logged in).

```
[user_applications]
```

**Features:**

- Lists all applications for the current user
- Real-time status updates via AJAX polling
- Application history

---

## Admin Features

### Applications Management

Navigate to **Job Posting Manager → Applications** to:

- View all applications in a table format
- Search applications by name, email, or application number
- Filter by status or job posting
- Sort by date, name, or status
- View detailed application information
- Update application status
- Print application details
- Delete applications
- Bulk actions for status updates

### Form Templates Management

Navigate to **Job Posting Manager → Form Templates** to:

- Create new form templates
- Edit existing templates
- Set default template
- Delete templates
- View template field count

### Email Templates Management

Navigate to **Job Posting Manager → Email Templates** to:

- Customize confirmation email template
- Customize admin notification template
- Customize status update template
- Edit subject lines
- Customize email content with placeholders
- Set color schemes (header, body, footer, text colors)
- Preview templates

**Available Placeholders:**

- `[Application ID]` - Application database ID
- `[Application Number]` - Unique application number
- `[Job Title]` - Job posting title
- `[Full Name]` - Applicant's full name
- `[Email]` - Applicant's email address
- `[Status Name]` - Current application status
- `[Date]` - Application date

### Settings

Navigate to **Job Posting Manager → Settings** to:

- Configure SMTP settings (if no external SMTP plugin)
- Test SMTP connection
- Set admin email address
- Configure from email and name
- View external SMTP plugin status

---

## Form Builder

### Field Types

1. **Text** - Single-line text input
2. **Textarea** - Multi-line text input
3. **Email** - Email address with validation
4. **Phone/Tel** - Telephone number input
5. **Select** - Dropdown selection (requires options)
6. **Checkbox** - Multiple checkbox options (requires options)
7. **Radio** - Radio button group (requires options)
8. **File Upload** - File attachment (resume, documents, photos)
9. **Date** - Date picker
10. **Number** - Numeric input

### Field Configuration

Each field can be configured with:

- **Label** - Display label for the field
- **Name** - Field name (sanitized, lowercase, underscores)
- **Type** - Field type selection
- **Required** - Boolean flag for required fields
- **Placeholder** - Placeholder text
- **Options** - Options for select/radio/checkbox (one per line)
- **Description** - Help text displayed below field
- **Column Width** - Grid column width (1-12, auto-calculated on drag)

### Layout System

- 12-column grid system
- Fields automatically arrange into rows
- Drag-and-drop reordering
- Visual column width indicators
- Multi-column support (up to 3 fields per row)

### Special Field Handling

- **Resume/CV Fields** - Special handling for file uploads
- **Photo Fields** - Image preview and single upload for Photo 1
- **Position Choice Fields** - Auto-selected 1st choice, dropdowns for 2nd and 3rd
- **Auto-Generated Fields** - Application Number and Date of Registration

---

## Email System

### Email Types

#### 1. Confirmation Email (Applicant)

- **Trigger:** On successful application submission
- **Recipient:** Applicant email address
- **Content:** Application confirmation, ID, number, job title, status

#### 2. Admin Notification Email

- **Trigger:** On successful application submission
- **Recipient:** Admin email (configurable)
- **Content:** New application notification with key details
- **Priority:** High (X-Priority: 1, Importance: High)
- **Delivery:** Async via shutdown hook, direct HTTP request, or cron

#### 3. Status Update Email (Applicant)

- **Trigger:** When application status is updated
- **Recipient:** Applicant email address
- **Content:** Status update with color-coded badge, application details

#### 4. Account Creation Email (New Users)

- **Trigger:** When new user account is created for guest applicants
- **Recipient:** New user email address
- **Content:** Welcome message, account credentials, login URL

### SMTP Integration

The plugin supports:

- **External SMTP Plugins:**

  - WP Mail SMTP
  - Other WordPress SMTP plugins
  - Automatic detection and use

- **Built-in SMTP:**
  - Basic SMTP configuration
  - Host, port, encryption settings
  - Authentication support
  - Connection testing

**Note:** Email delivery requires a properly configured SMTP service. The plugin will not send emails without SMTP configuration.

---

## Application Tracking

### Application Number Format

Applications are assigned unique numbers in the format: `YY-BDO-XXXXXXXX`

- `YY` - Two-digit year
- `BDO` - Fixed prefix
- `XXXXXXXX` - 8-digit unique identifier

### Tracking Features

- Public application tracker (no login required)
- Application lookup by application number
- Real-time status display
- Detailed application information:
  - Application ID and number
  - Application date
  - Current status with color-coded badge
  - Job information
  - Applicant information
  - Form data

### Status Badges

Status badges are color-coded and customizable:

- **Pending** - Yellow (#ffc107)
- **Reviewed** - Cyan (#17a2b8)
- **Accepted** - Green (#28a745)
- **Rejected** - Red (#dc3545)

---

## File Structure

```
job-posting-manager/
├── assets/
│   ├── css/
│   │   ├── jpm-admin.css          # Admin styles
│   │   └── jpm-frontend.css       # Frontend styles
│   └── js/
│       ├── components/
│       │   ├── jpm-form-builder-core.js        # Core form builder
│       │   ├── jpm-form-builder-dragdrop.js   # Drag & drop functionality
│       │   ├── jpm-form-builder-fields.js     # Field management
│       │   ├── jpm-form-builder-layout.js      # Layout management
│       │   ├── jpm-form-builder-persistence.js # Data persistence
│       │   ├── jpm-form-builder-utils.js       # Utility functions
│       │   └── README.md                       # Component documentation
│       ├── jpm-admin.js           # Admin JavaScript
│       └── jpm-frontend.js        # Frontend JavaScript
├── includes/
│   ├── class-jpm-admin.php              # Admin interface
│   ├── class-jpm-db.php                 # Database operations
│   ├── class-jpm-email-templates.php    # Email template management
│   ├── class-jpm-emails.php             # Email sending
│   ├── class-jpm-form-builder.php       # Form builder & rendering
│   ├── class-jpm-frontend.php           # Frontend functionality
│   ├── class-jpm-settings.php           # Settings management
│   ├── class-jpm-smtp.php               # SMTP configuration
│   └── class-jpm-templates.php           # Form template management
├── languages/
│   └── job-posting-manager.pot          # Translation template
├── job-posting-manager.php              # Main plugin file
├── uninstall.php                        # Uninstall cleanup
├── PRD.md                               # Product Requirements Document
└── README.md                            # This file
```

---

## Development

### Class Architecture

#### Core Classes

- **JPM_DB** - Database operations (create tables, insert/update applications, queries)
- **JPM_Admin** - Admin interface management (menu, applications list, details view)
- **JPM_Frontend** - Frontend display and functionality (shortcodes, AJAX handlers)
- **JPM_Form_Builder** - Form builder interface and form rendering
- **JPM_Emails** - Email sending functionality (confirmations, notifications, status updates)
- **JPM_Settings** - Plugin settings management
- **JPM_Templates** - Form template management (CRUD operations)
- **JPM_Email_Templates** - Email template management (CRUD, rendering, placeholders)
- **JPM_SMTP** - SMTP configuration and testing

### Database Schema

#### Custom Table: `wp_job_applications`

```sql
CREATE TABLE wp_job_applications (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    user_id bigint(20) NOT NULL,
    job_id bigint(20) NOT NULL,
    application_date datetime DEFAULT CURRENT_TIMESTAMP,
    status varchar(50) DEFAULT 'pending',
    resume_file_path varchar(255),
    notes text,
    PRIMARY KEY (id),
    UNIQUE KEY unique_application (user_id, job_id)
);
```

#### WordPress Options

- `jpm_form_templates` - Form templates array
- `jpm_email_templates` - Email templates array
- `jpm_smtp_settings` - SMTP configuration
- `jpm_settings` - General plugin settings
- `jpm_application_statuses` - Custom application statuses

#### Post Meta

- `_jpm_form_fields` - Form fields array for job postings
- `_jpm_selected_template` - Selected template ID
- `company_name` - Company name
- `location` - Job location
- `salary` - Salary information
- `duration` - Job duration
- `company_image` - Company image attachment ID

### Hooks and Filters

#### Action Hooks

- `jpm_send_admin_notification_async` - Async admin notification
- `wp_ajax_jpm_apply` - Application submission
- `wp_ajax_jpm_submit_application_form` - Form submission
- `wp_ajax_jpm_get_status` - Status retrieval
- `wp_ajax_jpm_get_job_details` - Job details retrieval
- `wp_ajax_jpm_filter_jobs` - Job filtering
- `wp_ajax_jpm_track_application` - Application tracking
- `wp_ajax_jpm_add_field` - Add form field
- `wp_ajax_jpm_remove_field` - Remove form field
- `wp_ajax_jpm_reorder_fields` - Reorder form fields

#### Filter Hooks

- `the_content` - Application form injection on single job posting pages

### JavaScript Architecture

The form builder uses a modular, component-based architecture:

- **jpm-form-builder-utils.js** - Utility functions (column width, field name sanitization)
- **jpm-form-builder-fields.js** - Field management (add, remove, update, toggle editor)
- **jpm-form-builder-layout.js** - Layout management (row organization, column width)
- **jpm-form-builder-dragdrop.js** - Drag and drop functionality (jQuery UI integration)
- **jpm-form-builder-persistence.js** - Data persistence (update form fields JSON, autosave)
- **jpm-form-builder-core.js** - Main initialization and coordination

All components are namespaced under `window.JPM*` to avoid conflicts.

### Security

The plugin implements comprehensive security measures following WordPress security best practices and OWASP guidelines. All security measures are applied at multiple layers to ensure robust protection.

#### 1. Input Validation and Sanitization

**Comprehensive Input Sanitization:**

All user input is sanitized using WordPress's built-in sanitization functions before processing or storage:

- **Text Fields:** `sanitize_text_field()` - Removes invalid UTF-8 characters, converts special characters to HTML entities, strips tags, removes line breaks, tabs, and extra whitespace

  ```php
  $field_name = sanitize_text_field($_POST['field_name'] ?? '');
  ```

- **Textarea Fields:** `sanitize_textarea_field()` - Similar to `sanitize_text_field()` but preserves line breaks

  ```php
  $description = sanitize_textarea_field($_POST['description'] ?? '');
  ```

- **Email Fields:** `sanitize_email()` - Validates and sanitizes email addresses, removes illegal characters

  ```php
  $email = sanitize_email($form_data['email']);
  ```

- **Integer Values:** `intval()` - Converts to integer, prevents type confusion attacks

  ```php
  $job_id = intval($_POST['job_id'] ?? 0);
  ```

- **Arrays:** `array_map()` with sanitization functions for array inputs

  ```php
  $value = array_map('sanitize_text_field', $_POST['jpm_fields'][$field_name]);
  ```

- **JSON Data:** Validated and sanitized after decoding
  ```php
  $form_fields = json_decode($form_fields_json, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
      // Error handling
  }
  // Then sanitize each field
  foreach ($form_fields as $field) {
      $sanitized_fields[] = [
          'type' => sanitize_text_field($field['type'] ?? 'text'),
          'label' => sanitize_text_field($field['label'] ?? ''),
          // ...
      ];
  }
  ```

**Input Validation:**

- **Required Fields:** Server-side validation ensures all required fields are present and not empty
- **Email Format:** Email fields are validated using WordPress's email validation
- **Field Type Validation:** Each field type is validated against expected format
- **Data Type Validation:** Integers, strings, arrays are type-checked before use

#### 2. Output Escaping

All output is escaped using appropriate WordPress escaping functions to prevent XSS attacks:

- **HTML Content:** `esc_html()` - Escapes HTML entities

  ```php
  echo esc_html($company_name);
  ```

- **HTML Attributes:** `esc_attr()` - Escapes HTML attributes

  ```php
  <div data-job-id="<?php echo esc_attr($job->ID); ?>">
  ```

- **URLs:** `esc_url()` - Validates and escapes URLs

  ```php
  <a href="<?php echo esc_url($job_link); ?>">
  ```

- **JavaScript:** `esc_js()` - Escapes strings for JavaScript
- **Textarea Content:** `esc_textarea()` - Escapes textarea content
- **Allowed HTML:** `wp_kses_post()` - Strips disallowed HTML while preserving allowed tags
  ```php
  echo wp_kses_post(apply_filters('the_content', $job->post_content));
  ```

#### 3. SQL Injection Prevention

All database queries use prepared statements via `$wpdb->prepare()`:

- **Prepared Statements:** All user input in queries is parameterized

  ```php
  $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $table WHERE user_id = %d AND job_id = %d",
      $user_id,
      $job_id
  ));
  ```

- **Type Specifiers:** Proper type specifiers used (%d for integers, %s for strings, %f for floats)
- **Direct Database Methods:** `$wpdb->insert()`, `$wpdb->update()`, `$wpdb->delete()` automatically escape values

  ```php
  $wpdb->insert($table, [
      'user_id' => $user_id,
      'job_id' => $job_id,
      'notes' => sanitize_textarea_field($notes),
  ]);
  ```

- **LIKE Queries:** `$wpdb->esc_like()` used for LIKE queries
  ```php
  $search_term = '%' . $wpdb->esc_like($application_number_input) . '%';
  ```

#### 4. Cross-Site Scripting (XSS) Prevention

Multi-layered XSS protection:

- **Input Sanitization:** All input sanitized on receipt
- **Output Escaping:** All output escaped before display
- **Content Security:** HTML content filtered through `wp_kses_post()` for allowed tags only
- **JavaScript Context:** Proper escaping for JavaScript variables
- **Attribute Context:** Proper escaping for HTML attributes

#### 5. Cross-Site Request Forgery (CSRF) Protection

**Nonce Verification:**

All forms and AJAX requests are protected with WordPress nonces:

- **Form Nonces:** Every form includes a nonce field

  ```php
  wp_nonce_field('jpm_form_builder', 'jpm_form_builder_nonce');
  ```

- **AJAX Nonces:** All AJAX requests verify nonces

  ```php
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jpm_nonce')) {
      wp_send_json_error(['message' => __('Security check failed.', 'job-posting-manager')]);
  }
  ```

- **AJAX Referer Check:** `check_ajax_referer()` used for additional verification

  ```php
  check_ajax_referer('jpm_nonce');
  ```

- **Unique Nonce Contexts:** Different nonce actions for different operations:
  - `jpm_nonce` - General AJAX operations
  - `jpm_form_builder` - Form builder operations
  - `jpm_application_form` - Application form submissions
  - `jpm_email_nonce_{application_id}` - Email operations (unique per application)

#### 6. Authentication and Authorization

**Capability Checks:**

All admin operations verify user capabilities:

- **Edit Posts:** `current_user_can('edit_posts')` - Required for managing applications

  ```php
  if (!current_user_can('edit_posts')) {
      wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
  }
  ```

- **Edit Post:** `current_user_can('edit_post', $post_id)` - Required for editing specific job postings

  ```php
  if (!current_user_can('edit_post', $post_id)) {
      return;
  }
  ```

- **Role-Based Access:** Different capabilities checked for different operations
- **Print Access:** Capability checks before allowing print functionality
  ```php
  if (!current_user_can('edit_posts')) {
      wp_die(__('You do not have permission to view this page.', 'job-posting-manager'));
  }
  ```

#### 7. File Upload Security

**Comprehensive File Validation:**

- **WordPress Upload Handler:** Uses `wp_handle_upload()` which includes:

  - File type validation (MIME type checking)
  - File size limits (respects WordPress and PHP limits)
  - File extension validation
  - Secure file naming
  - Upload directory security

  ```php
  $upload = wp_handle_upload($file, ['test_form' => false]);
  if (isset($upload['error'])) {
      wp_send_json_error(['message' => $upload['error']]);
  }
  ```

- **Upload Error Handling:** All upload errors are checked and handled

  ```php
  if ($file['error'] !== UPLOAD_ERR_OK) {
      // Handle specific error codes
  }
  ```

- **File Size Limits:** Maximum file size enforced (10MB for imports, WordPress limits for uploads)
- **File Type Restrictions:** WordPress's allowed file types enforced
- **Secure Storage:** Files stored in WordPress uploads directory with proper permissions
- **File Name Sanitization:** WordPress automatically sanitizes file names

#### 8. Data Protection

**Sensitive Data Handling:**

- **Personal Information:** All personal data (names, emails, addresses) is sanitized before storage
- **JSON Data:** Form data stored as JSON is validated and sanitized
- **Database Storage:** All data stored using WordPress database methods which handle escaping
- **Transient Security:** Email data stored in transients uses unique, time-limited keys
  ```php
  $transient_key = 'jpm_email_' . $application_id . '_' . time();
  set_transient($transient_key, $email_data, 300); // 5 minutes
  ```

#### 9. JSON Security

**JSON Data Validation:**

- **Decode Validation:** JSON decode errors are checked and logged

  ```php
  $form_fields = json_decode($form_fields_json, true);
  if (json_last_error() !== JSON_ERROR_NONE) {
      error_log('JPM Form Builder: JSON decode error - ' . json_last_error_msg());
      return;
  }
  ```

- **Structure Validation:** Decoded data is validated to ensure it's an array
- **Field Sanitization:** Each field in JSON data is individually sanitized

#### 10. Direct Access Prevention

**File Protection:**

- **ABSPATH Check:** All PHP files check for WordPress environment

  ```php
  if (!defined('ABSPATH')) {
      exit;
  }
  ```

- **Direct File Access:** Prevents direct access to plugin files

#### 11. Error Handling and Information Disclosure

**Secure Error Messages:**

- **Generic Error Messages:** User-facing errors don't reveal system details
- **Error Logging:** Detailed errors logged server-side only

  ```php
  error_log('JPM: Failed to send admin email - ' . $e->getMessage());
  ```

- **No Stack Traces:** Stack traces not exposed to users
- **Graceful Failures:** All operations fail gracefully without exposing sensitive information

#### 12. Additional Security Measures

**Rate Limiting Considerations:**

- **Duplicate Prevention:** Logged-in users prevented from applying twice to same job
  ```php
  $existing = $wpdb->get_var($wpdb->prepare(
      "SELECT id FROM $table WHERE user_id = %d AND job_id = %d",
      $user_id,
      $job_id
  ));
  if ($existing) {
      return new WP_Error('duplicate', __('You have already applied for this job.', 'job-posting-manager'));
  }
  ```

**Input Type Validation:**

- **Integer Validation:** All IDs validated as positive integers
  ```php
  $job_id = intval($_POST['job_id'] ?? 0);
  if (!$job_id) {
      wp_send_json_error(['message' => __('Invalid job posting.', 'job-posting-manager')]);
  }
  ```

**Session Security:**

- **WordPress Sessions:** Relies on WordPress's secure session management
- **Cookie Security:** WordPress handles secure cookie settings

**Email Security:**

- **Email Validation:** All email addresses validated before sending
- **SMTP Security:** SMTP credentials stored securely in WordPress options (encrypted by WordPress)

**Database Security:**

- **Table Prefix:** Uses WordPress table prefix for custom tables
- **Charset Collation:** Proper charset and collation for international character support
- **Unique Constraints:** Database-level constraints prevent duplicate applications

#### Security Best Practices Summary

1. ✅ **Never trust user input** - All input sanitized
2. ✅ **Always escape output** - All output escaped appropriately
3. ✅ **Use prepared statements** - All database queries parameterized
4. ✅ **Verify nonces** - All forms and AJAX requests protected
5. ✅ **Check capabilities** - All admin operations verify permissions
6. ✅ **Validate file uploads** - All uploads validated and secured
7. ✅ **Handle errors securely** - No sensitive information exposed
8. ✅ **Follow WordPress standards** - Uses WordPress security functions
9. ✅ **Defense in depth** - Multiple layers of security
10. ✅ **Regular security reviews** - Code follows security best practices

### Performance

- Efficient database queries with proper indexing
- AJAX-based form submission (non-blocking)
- Async email delivery via shutdown hooks
- Lazy loading for job details modals
- Pagination for large datasets
- Optimized asset loading (only on relevant pages)

---

## Support

### Troubleshooting

**Emails not sending:**

- Ensure an SMTP plugin is installed and configured, or configure built-in SMTP settings
- Test SMTP connection in Settings
- Check server error logs

**Form not displaying:**

- Ensure the job posting is published
- Check that form fields are configured in the Form Builder
- Verify the form template is applied

**Application not submitting:**

- Check browser console for JavaScript errors
- Verify all required fields are filled
- Check file upload size limits
- Review server error logs

**Status not updating:**

- Verify user has proper permissions
- Check AJAX nonce verification
- Review browser console for errors

### Getting Help

For issues, feature requests, or contributions:

1. Review the PRD.md file for detailed specifications
2. Check the code comments for implementation details
3. Review WordPress error logs for debugging information

---

## License

This plugin is licensed under the GPL v2 or later.

```
Copyright (C) 2024 Ericson Palisoc

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

---

## Changelog

### Version 1.0.0

- Initial release
- Core functionality:
  - Job posting management
  - Drag-and-drop form builder
  - Multi-step application forms
  - Email notification system
  - Application tracking
  - Template management
  - SMTP integration

---

**Made with ❤️ for WordPress**
