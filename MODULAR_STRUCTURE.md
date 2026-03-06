# Job Posting Manager - Modular Structure

## Overview

This document describes the modular structure of the Job Posting Manager plugin. The codebase has been refactored to be more maintainable and scalable while maintaining full backward compatibility.

## Directory Structure

```
job-posting-manager/
├── includes/
│   ├── core/                    # Core functionality modules
│   │   ├── class-jpm-database.php          # Database operations
│   │   └── class-jpm-status-manager.php     # Status management
│   ├── admin/                   # Admin-specific modules (future)
│   ├── frontend/                # Frontend-specific modules (future)
│   ├── emails/                  # Email modules
│   │   └── class-jpm-email-base.php        # Base email class
│   ├── forms/                   # Form-related modules (future)
│   ├── handlers/                # Handler modules (future)
│   └── [legacy files]           # Original files maintained for compatibility
├── assets/
└── job-posting-manager.php      # Main plugin file
```

## Core Modules

### JPM_Database (`includes/core/class-jpm-database.php`)

Handles all database operations for job applications:

- `create_tables()` - Create database tables
- `insert_application()` - Insert new application
- `update_status()` - Update application status
- `get_applications()` - Get applications with filters
- `get_application()` - Get single application by ID
- `update_application_notes()` - Update application notes
- `delete_application()` - Delete an application

### JPM_Status_Manager (`includes/core/class-jpm-status-manager.php`)

Manages application statuses:

- `get_all_statuses_info()` - Get all statuses with full information
- `get_default_statuses()` - Get default status array
- `get_status_options()` - Get status options for dropdowns
- `get_status_by_slug()` - Get status by slug
- `get_medical_status_slug()` - Get "For Medical" status slug
- `get_interview_status_slug()` - Get "For Interview" status slug
- `is_rejected_status()` - Check if status is rejected
- `is_accepted_status()` - Check if status is accepted

## Email Modules

### JPM_Email_Base (`includes/emails/class-jpm-email-base.php`)

Base class providing common email functionality:

- `is_smtp_available()` - Check if SMTP is available
- `replace_placeholders()` - Replace placeholders in text
- `add_email_recipients()` - Add CC/BCC from settings
- `get_default_headers()` - Get default email headers
- `send_email()` - Send email with logging
- `get_default_medical_address()` - Get default medical address

## Backward Compatibility

All original classes are maintained and updated to use the new modular structure internally:

### JPM_DB (`includes/class-jpm-admin.php`)

This class now acts as a compatibility wrapper that delegates to `JPM_Database` and `JPM_Status_Manager`:

```php
class JPM_DB
{
    public static function create_tables() {
        return JPM_Database::create_tables();
    }
    
    public static function insert_application(...) {
        return JPM_Database::insert_application(...);
    }
    
    // ... other methods delegate to modular classes
}
```

### JPM_Admin (`includes/class-jpm-db.php`)

The `get_all_statuses_info()` method now delegates to `JPM_Status_Manager`:

```php
public static function get_all_statuses_info() {
    return JPM_Status_Manager::get_all_statuses_info();
}
```

## Benefits of Modular Structure

1. **Separation of Concerns**: Each module has a single, well-defined responsibility
2. **Maintainability**: Easier to locate and fix bugs
3. **Scalability**: New features can be added as separate modules
4. **Testability**: Individual modules can be tested in isolation
5. **Reusability**: Core functionality can be reused across different parts of the plugin
6. **Backward Compatibility**: All existing code continues to work without changes

## Future Modularization

The following areas are candidates for further modularization:

1. **Admin Components**:
   - Menu management
   - Dashboard page
   - Applications page
   - Status management page
   - AJAX handlers
   - Meta boxes

2. **Frontend Components**:
   - Shortcodes
   - AJAX handlers
   - Authentication
   - Job listings

3. **Email Components**:
   - Customer emails
   - Admin emails
   - Email templates

4. **Form Components**:
   - Form builder
   - Form validation
   - Form rendering

## Usage Examples

### Using the Database Module

```php
// Create tables
JPM_Database::create_tables();

// Insert application
$app_id = JPM_Database::insert_application($user_id, $job_id, $resume_path, $notes);

// Get applications
$applications = JPM_Database::get_applications(['status' => 'pending']);
```

### Using the Status Manager

```php
// Get all statuses
$statuses = JPM_Status_Manager::get_all_statuses_info();

// Get status by slug
$status = JPM_Status_Manager::get_status_by_slug('pending');

// Check if rejected
$is_rejected = JPM_Status_Manager::is_rejected_status('rejected');
```

## Migration Notes

- All existing code using `JPM_DB` or `JPM_Admin` will continue to work
- New code should use the modular classes directly (`JPM_Database`, `JPM_Status_Manager`)
- The old classes serve as compatibility wrappers and will be maintained
