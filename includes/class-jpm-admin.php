<?php
class JPM_DB
{
    public static function create_tables()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'job_applications';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            job_id bigint(20) NOT NULL,
            application_date datetime DEFAULT CURRENT_TIMESTAMP,
            status varchar(50) DEFAULT 'pending',
            resume_file_path varchar(255),
            notes text,
            PRIMARY KEY (id),
            UNIQUE KEY unique_application (user_id, job_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function insert_application($user_id, $job_id, $resume_path, $notes = '')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';
        // Check for duplicate (only for logged-in users)
        if ($user_id > 0) {
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND job_id = %d", $user_id, $job_id));
            if ($existing) {
                return new WP_Error('duplicate', __('You have already applied for this job.', 'job-posting-manager'));
            }
        }
        return $wpdb->insert($table, [
            'user_id' => $user_id,
            'job_id' => $job_id,
            'resume_file_path' => $resume_path,
            'notes' => sanitize_textarea_field($notes),
            'status' => 'pending', // Explicitly set status to pending
        ]);
    }

    public static function update_status($id, $status)
    {
        global $wpdb;
        return $wpdb->update($wpdb->prefix . 'job_applications', ['status' => sanitize_text_field($status)], ['id' => $id]);
    }

    public static function get_applications($filters = [])
    {
        global $wpdb;
        $table = $wpdb->prefix . 'job_applications';
        $where = '1=1';
        if (!empty($filters['status']))
            $where .= $wpdb->prepare(" AND status = %s", $filters['status']);
        if (!empty($filters['job_id']))
            $where .= $wpdb->prepare(" AND job_id = %d", $filters['job_id']);
        return $wpdb->get_results("SELECT * FROM $table WHERE $where");
    }
}