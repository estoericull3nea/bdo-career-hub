<?php
class JPM_Emails {
    public static function send_confirmation($app_id) {
        $settings = get_option('jpm_settings');
        $subject = str_replace('[Application ID]', $app_id, $settings['confirmation_subject']);
        $body = str_replace(['[Applicant Name]', '[Job Title]'], [wp_get_current_user()->display_name, get_the_title($_POST['job_id'])], $settings['confirmation_body']);
        wp_mail(wp_get_current_user()->user_email, $subject, $body);
    }

    public static function send_status_update($app_id) {
        // Similar to above, fetch details and send
    }
}