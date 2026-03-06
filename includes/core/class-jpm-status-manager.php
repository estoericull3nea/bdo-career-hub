<?php
/**
 * Status Management
 * 
 * Handles application status operations
 */
class JPM_Status_Manager
{
    /**
     * Get all statuses with full information
     * 
     * @return array Array of status arrays with id, name, slug, color, etc.
     */
    public static function get_all_statuses_info()
    {
        // Get statuses from option
        $statuses = get_option('jpm_application_statuses', []);

        // If no custom statuses, return default ones
        if (empty($statuses)) {
            $statuses = self::get_default_statuses();
        }

        // Ensure ordering field exists for all statuses and set default if missing
        foreach ($statuses as $index => $status) {
            if (!isset($status['ordering'])) {
                $statuses[$index]['ordering'] = isset($status['id']) ? $status['id'] : 0;
            }
        }

        // Sort by ordering, then by ID as fallback
        usort($statuses, function($a, $b) {
            $order_a = isset($a['ordering']) ? intval($a['ordering']) : (isset($a['id']) ? $a['id'] : 0);
            $order_b = isset($b['ordering']) ? intval($b['ordering']) : (isset($b['id']) ? $b['id'] : 0);
            if ($order_a == $order_b) {
                return (isset($a['id']) ? $a['id'] : 0) - (isset($b['id']) ? $b['id'] : 0);
            }
            return $order_a - $order_b;
        });

        return $statuses;
    }

    /**
     * Get default statuses
     * 
     * @return array Default status array
     */
    public static function get_default_statuses()
    {
        return [
            ['id' => 1, 'name' => 'Pending', 'slug' => 'pending', 'color' => '#ffc107', 'text_color' => '#000000', 'description' => 'This means that your application was successfully submitted and is currently pending review by our hiring team. We will notify you once your application has been reviewed.', 'ordering' => 1],
            ['id' => 2, 'name' => 'Reviewed', 'slug' => 'reviewed', 'color' => '#17a2b8', 'text_color' => '#ffffff', 'description' => 'Thank you. Your application has been reviewed by our hiring team and is currently under consideration. We will contact you with further updates.', 'ordering' => 2],
            ['id' => 3, 'name' => 'Accepted', 'slug' => 'accepted', 'color' => '#28a745', 'text_color' => '#ffffff', 'description' => 'Congratulations! Your application has been accepted. Our team will contact you shortly with next steps and additional information.', 'ordering' => 3],
            ['id' => 4, 'name' => 'Rejected', 'slug' => 'rejected', 'color' => '#dc3545', 'text_color' => '#ffffff', 'description' => 'We appreciate your interest in this position. Unfortunately, your application was not selected for this role at this time. We encourage you to apply for other positions that match your qualifications.', 'ordering' => 4],
        ];
    }

    /**
     * Get status options for dropdowns
     * 
     * @return array Array of status options (slug => name)
     */
    public static function get_status_options()
    {
        $statuses = self::get_all_statuses_info();
        $options = [];
        
        foreach ($statuses as $status) {
            $options[$status['slug']] = $status['name'];
        }
        
        return $options;
    }

    /**
     * Get status by slug
     * 
     * @param string $slug Status slug
     * @return array|null Status array or null if not found
     */
    public static function get_status_by_slug($slug)
    {
        $statuses = self::get_all_statuses_info();
        
        foreach ($statuses as $status) {
            if ($status['slug'] === $slug) {
                return $status;
            }
        }
        
        return null;
    }

    /**
     * Get status slug for "For Medical" status
     * 
     * @return string Status slug or empty string
     */
    public static function get_medical_status_slug()
    {
        $statuses = self::get_all_statuses_info();
        foreach ($statuses as $status) {
            $slug = strtolower($status['slug']);
            $name = strtolower($status['name']);
            if ($slug === 'for-medical' || $slug === 'for_medical' || $name === 'for medical') {
                return $status['slug'];
            }
        }
        return '';
    }

    /**
     * Get status slug for "For Interview" status
     * 
     * @return string Status slug or empty string
     */
    public static function get_interview_status_slug()
    {
        $statuses = self::get_all_statuses_info();
        foreach ($statuses as $status) {
            $slug = strtolower($status['slug']);
            $name = strtolower($status['name']);
            if ($slug === 'for-interview' || $slug === 'for_interview' || $slug === 'forinterview' || 
                $name === 'for interview' || stripos($name, 'for interview') !== false || stripos($name, 'interview') !== false) {
                return $status['slug'];
            }
        }
        return '';
    }

    /**
     * Check if status is rejected
     * 
     * @param string $status_slug Status slug
     * @return bool True if status is rejected
     */
    public static function is_rejected_status($status_slug)
    {
        $status = self::get_status_by_slug($status_slug);
        if (!$status) {
            return false;
        }
        
        $slug = strtolower($status['slug']);
        $name = strtolower($status['name']);
        
        return $slug === 'rejected' || $name === 'rejected' || stripos($name, 'reject') !== false;
    }

    /**
     * Check if status is accepted
     * 
     * @param string $status_slug Status slug
     * @return bool True if status is accepted
     */
    public static function is_accepted_status($status_slug)
    {
        $status = self::get_status_by_slug($status_slug);
        if (!$status) {
            return false;
        }
        
        $slug = strtolower($status['slug']);
        $name = strtolower($status['name']);
        
        return $slug === 'accepted' || $name === 'accepted' || stripos($name, 'accept') !== false;
    }
}
