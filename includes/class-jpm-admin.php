<?php
/**
 * JPM_DB - Backward Compatibility Wrapper
 * 
 * This class maintains backward compatibility while using the new modular structure.
 * All methods delegate to the new JPM_Database and JPM_Status_Manager classes.
 */
class JPM_DB
{
    /**
     * Create database tables
     * Delegates to JPM_Database
     */
    public static function create_tables()
    {
        return JPM_Database::create_tables();
    }

    /**
     * Insert a new application
     * Delegates to JPM_Database
     */
    public static function insert_application($user_id, $job_id, $resume_path, $notes = '')
    {
        return JPM_Database::insert_application($user_id, $job_id, $resume_path, $notes);
    }

    /**
     * Update application status
     * Delegates to JPM_Database
     */
    public static function update_status($id, $status)
    {
        return JPM_Database::update_status($id, $status);
    }

    /**
     * Get applications with filters
     * Delegates to JPM_Database
     */
    public static function get_applications($filters = [])
    {
        return JPM_Database::get_applications($filters);
    }

    /**
     * Get all statuses with full information
     * Delegates to JPM_Status_Manager
     */
    public static function get_all_statuses_info()
    {
        return JPM_Status_Manager::get_all_statuses_info();
    }
}