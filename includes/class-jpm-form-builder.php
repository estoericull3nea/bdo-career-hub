<?php
/**
 * Form Builder Class
 * Handles form building interface and form rendering
 */
class JPM_Form_Builder
{

    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'add_form_builder_meta_box']);
        add_action('save_post', [$this, 'save_form_fields']);
        add_action('wp_ajax_jpm_add_field', [$this, 'ajax_add_field']);
        add_action('wp_ajax_jpm_remove_field', [$this, 'ajax_remove_field']);
        add_action('wp_ajax_jpm_reorder_fields', [$this, 'ajax_reorder_fields']);
        add_filter('the_content', [$this, 'render_application_form'], 20);
        add_action('wp_ajax_jpm_submit_application_form', [$this, 'handle_form_submission']);
        add_action('wp_ajax_nopriv_jpm_submit_application_form', [$this, 'handle_form_submission']);
    }

    /**
     * Add form builder meta box to job postings
     */
    public function add_form_builder_meta_box()
    {
        add_meta_box(
            'jpm_form_builder',
            __('Application Form Builder', 'job-posting-manager'),
            [$this, 'form_builder_meta_box'],
            'job_posting',
            'normal',
            'high'
        );
    }

    /**
     * Form builder meta box content
     */
    public function form_builder_meta_box($post)
    {
        wp_nonce_field('jpm_form_builder', 'jpm_form_builder_nonce');

        $form_fields = get_post_meta($post->ID, '_jpm_form_fields', true);
        if (!is_array($form_fields)) {
            $form_fields = [];
        }

        $field_types = [
            'text' => __('Text', 'job-posting-manager'),
            'textarea' => __('Textarea', 'job-posting-manager'),
            'email' => __('Email', 'job-posting-manager'),
            'tel' => __('Phone', 'job-posting-manager'),
            'select' => __('Select', 'job-posting-manager'),
            'checkbox' => __('Checkbox', 'job-posting-manager'),
            'radio' => __('Radio', 'job-posting-manager'),
            'file' => __('File Upload', 'job-posting-manager'),
            'date' => __('Date', 'job-posting-manager'),
            'number' => __('Number', 'job-posting-manager'),
        ];
        ?>
        <div id="jpm-form-builder">
            <div class="jpm-form-builder-header">
                <h3><?php _e('Form Fields', 'job-posting-manager'); ?></h3>
                <button type="button" class="button button-primary" id="jpm-add-field-btn">
                    <?php _e('+ Add Field', 'job-posting-manager'); ?>
                </button>
            </div>

            <div id="jpm-field-types" style="display:none;">
                <h4><?php _e('Select Field Type', 'job-posting-manager'); ?></h4>
                <div class="jpm-field-types-grid">
                    <?php foreach ($field_types as $type => $label): ?>
                        <button type="button" class="button jpm-field-type-btn" data-type="<?php echo esc_attr($type); ?>">
                            <?php echo esc_html($label); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="jpm-form-fields-container" class="jpm-form-fields-container">
                <?php if (!empty($form_fields)): ?>
                    <?php foreach ($form_fields as $index => $field): ?>
                        <?php $this->render_field_editor($field, $index); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <input type="hidden" name="jpm_form_fields_json" id="jpm-form-fields-json"
                value="<?php echo esc_attr(json_encode($form_fields)); ?>">
        </div>
        <?php
    }

    /**
     * Render field editor
     */
    private function render_field_editor($field, $index)
    {
        $field = wp_parse_args($field, [
            'type' => 'text',
            'label' => '',
            'name' => '',
            'required' => false,
            'placeholder' => '',
            'options' => '',
            'description' => '',
        ]);
        ?>
        <div class="jpm-field-editor" data-index="<?php echo esc_attr($index); ?>">
            <div class="jpm-field-header">
                <span class="jpm-field-handle dashicons dashicons-menu"></span>
                <strong
                    class="jpm-field-title"><?php echo esc_html($field['label'] ?: __('Untitled Field', 'job-posting-manager')); ?></strong>
                <span class="jpm-field-type-badge"><?php echo esc_html($field['type']); ?></span>
                <button type="button" class="button-link jpm-field-toggle">
                    <span class="dashicons dashicons-arrow-down"></span>
                </button>
                <button type="button" class="button-link jpm-field-remove" style="color: #a00;">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
            <div class="jpm-field-content" style="display:none;">
                <table class="form-table">
                    <tr>
                        <th><label><?php _e('Field Label', 'job-posting-manager'); ?></label></th>
                        <td>
                            <input type="text" class="jpm-field-label" value="<?php echo esc_attr($field['label']); ?>"
                                placeholder="<?php _e('Field Label', 'job-posting-manager'); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Field Name', 'job-posting-manager'); ?></label></th>
                        <td>
                            <input type="text" class="jpm-field-name" value="<?php echo esc_attr($field['name']); ?>"
                                placeholder="<?php _e('field_name', 'job-posting-manager'); ?>">
                            <p class="description">
                                <?php _e('Lowercase letters, numbers, and underscores only', 'job-posting-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Field Type', 'job-posting-manager'); ?></label></th>
                        <td>
                            <select class="jpm-field-type">
                                <option value="text" <?php selected($field['type'], 'text'); ?>>
                                    <?php _e('Text', 'job-posting-manager'); ?>
                                </option>
                                <option value="textarea" <?php selected($field['type'], 'textarea'); ?>>
                                    <?php _e('Textarea', 'job-posting-manager'); ?>
                                </option>
                                <option value="email" <?php selected($field['type'], 'email'); ?>>
                                    <?php _e('Email', 'job-posting-manager'); ?>
                                </option>
                                <option value="tel" <?php selected($field['type'], 'tel'); ?>>
                                    <?php _e('Phone', 'job-posting-manager'); ?>
                                </option>
                                <option value="select" <?php selected($field['type'], 'select'); ?>>
                                    <?php _e('Select', 'job-posting-manager'); ?>
                                </option>
                                <option value="checkbox" <?php selected($field['type'], 'checkbox'); ?>>
                                    <?php _e('Checkbox', 'job-posting-manager'); ?>
                                </option>
                                <option value="radio" <?php selected($field['type'], 'radio'); ?>>
                                    <?php _e('Radio', 'job-posting-manager'); ?>
                                </option>
                                <option value="file" <?php selected($field['type'], 'file'); ?>>
                                    <?php _e('File Upload', 'job-posting-manager'); ?>
                                </option>
                                <option value="date" <?php selected($field['type'], 'date'); ?>>
                                    <?php _e('Date', 'job-posting-manager'); ?>
                                </option>
                                <option value="number" <?php selected($field['type'], 'number'); ?>>
                                    <?php _e('Number', 'job-posting-manager'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Placeholder', 'job-posting-manager'); ?></label></th>
                        <td>
                            <input type="text" class="jpm-field-placeholder"
                                value="<?php echo esc_attr($field['placeholder']); ?>">
                        </td>
                    </tr>
                    <tr class="jpm-field-options-row"
                        style="<?php echo in_array($field['type'], ['select', 'radio', 'checkbox']) ? '' : 'display:none;'; ?>">
                        <th><label><?php _e('Options', 'job-posting-manager'); ?></label></th>
                        <td>
                            <textarea class="jpm-field-options" rows="4"
                                placeholder="<?php _e('One option per line', 'job-posting-manager'); ?>"><?php echo esc_textarea($field['options']); ?></textarea>
                            <p class="description"><?php _e('One option per line', 'job-posting-manager'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Description', 'job-posting-manager'); ?></label></th>
                        <td>
                            <textarea class="jpm-field-description"
                                rows="2"><?php echo esc_textarea($field['description']); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php _e('Required', 'job-posting-manager'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" class="jpm-field-required" <?php checked($field['required'], true); ?>>
                                <?php _e('This field is required', 'job-posting-manager'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Save form fields
     */
    public function save_form_fields($post_id)
    {
        // Check if nonce is set and verify
        if (!isset($_POST['jpm_form_builder_nonce']) || !wp_verify_nonce($_POST['jpm_form_builder_nonce'], 'jpm_form_builder')) {
            return;
        }

        // Check if user has permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Save form fields
        if (isset($_POST['jpm_form_fields_json'])) {
            $form_fields = json_decode(stripslashes($_POST['jpm_form_fields_json']), true);
            if (is_array($form_fields)) {
                // Sanitize form fields
                $sanitized_fields = [];
                foreach ($form_fields as $field) {
                    $sanitized_fields[] = [
                        'type' => sanitize_text_field($field['type'] ?? 'text'),
                        'label' => sanitize_text_field($field['label'] ?? ''),
                        'name' => sanitize_text_field($field['name'] ?? ''),
                        'required' => !empty($field['required']),
                        'placeholder' => sanitize_text_field($field['placeholder'] ?? ''),
                        'options' => sanitize_textarea_field($field['options'] ?? ''),
                        'description' => sanitize_textarea_field($field['description'] ?? ''),
                    ];
                }
                update_post_meta($post_id, '_jpm_form_fields', $sanitized_fields);
            }
        }
    }

    /**
     * AJAX: Add field
     */
    public function ajax_add_field()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jpm_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'job-posting-manager')]);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }

        $field_type = sanitize_text_field($_POST['field_type'] ?? 'text');
        $index = intval($_POST['index'] ?? 0);

        $field = [
            'type' => $field_type,
            'label' => '',
            'name' => '',
            'required' => false,
            'placeholder' => '',
            'options' => '',
            'description' => '',
        ];

        ob_start();
        $this->render_field_editor($field, $index);
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * AJAX: Remove field
     */
    public function ajax_remove_field()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jpm_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'job-posting-manager')]);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }
        wp_send_json_success();
    }

    /**
     * AJAX: Reorder fields
     */
    public function ajax_reorder_fields()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jpm_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'job-posting-manager')]);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }
        wp_send_json_success();
    }

    /**
     * Render application form in content
     */
    public function render_application_form($content)
    {
        global $post;

        if (!is_singular('job_posting') || !is_user_logged_in()) {
            return $content;
        }

        $form_fields = get_post_meta($post->ID, '_jpm_form_fields', true);
        if (empty($form_fields) || !is_array($form_fields)) {
            return $content;
        }

        ob_start();
        ?>
        <div class="jpm-application-form-wrapper">
            <h3><?php _e('Apply for this Position', 'job-posting-manager'); ?></h3>
            <form id="jpm-application-form" class="jpm-application-form" method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('jpm_application_form', 'jpm_application_nonce'); ?>
                <input type="hidden" name="job_id" value="<?php echo esc_attr($post->ID); ?>">

                <?php foreach ($form_fields as $index => $field): ?>
                    <div class="jpm-form-field-group">
                        <label for="jpm_field_<?php echo esc_attr($index); ?>">
                            <?php echo esc_html($field['label']); ?>
                            <?php if (!empty($field['required'])): ?>
                                <span class="required">*</span>
                            <?php endif; ?>
                        </label>
                        <?php if (!empty($field['description'])): ?>
                            <p class="description"><?php echo esc_html($field['description']); ?></p>
                        <?php endif; ?>
                        <?php echo $this->render_form_field($field, $index); ?>
                    </div>
                <?php endforeach; ?>

                <div class="jpm-form-submit">
                    <button type="submit" class="button button-primary">
                        <?php _e('Submit Application', 'job-posting-manager'); ?>
                    </button>
                </div>
                <div id="jpm-form-message" class="jpm-form-message"></div>
            </form>
        </div>
        <?php
        $form_html = ob_get_clean();

        return $content . $form_html;
    }

    /**
     * Render individual form field
     */
    private function render_form_field($field, $index)
    {
        $field_id = 'jpm_field_' . $index;
        $field_name = 'jpm_fields[' . esc_attr($field['name']) . ']';
        $required = !empty($field['required']) ? 'required' : '';
        $placeholder = !empty($field['placeholder']) ? 'placeholder="' . esc_attr($field['placeholder']) . '"' : '';

        switch ($field['type']) {
            case 'textarea':
                return sprintf(
                    '<textarea id="%s" name="%s" class="jpm-form-field" rows="5" %s %s></textarea>',
                    $field_id,
                    $field_name,
                    $required,
                    $placeholder
                );

            case 'select':
                $options_html = '<option value="">' . __('Select...', 'job-posting-manager') . '</option>';
                if (!empty($field['options'])) {
                    $options = explode("\n", $field['options']);
                    foreach ($options as $option) {
                        $option = trim($option);
                        if (!empty($option)) {
                            $options_html .= sprintf('<option value="%s">%s</option>', esc_attr($option), esc_html($option));
                        }
                    }
                }
                return sprintf(
                    '<select id="%s" name="%s" class="jpm-form-field" %s>%s</select>',
                    $field_id,
                    $field_name,
                    $required,
                    $options_html
                );

            case 'checkbox':
                $options_html = '';
                if (!empty($field['options'])) {
                    $options = explode("\n", $field['options']);
                    foreach ($options as $opt_index => $option) {
                        $option = trim($option);
                        if (!empty($option)) {
                            $options_html .= sprintf(
                                '<label><input type="checkbox" name="%s[]" value="%s" class="jpm-form-field"> %s</label><br>',
                                $field_name,
                                esc_attr($option),
                                esc_html($option)
                            );
                        }
                    }
                }
                return $options_html ?: sprintf('<input type="checkbox" id="%s" name="%s" class="jpm-form-field" %s>', $field_id, $field_name, $required);

            case 'radio':
                $options_html = '';
                if (!empty($field['options'])) {
                    $options = explode("\n", $field['options']);
                    foreach ($options as $opt_index => $option) {
                        $option = trim($option);
                        if (!empty($option)) {
                            $options_html .= sprintf(
                                '<label><input type="radio" name="%s" value="%s" class="jpm-form-field" %s> %s</label><br>',
                                $field_name,
                                esc_attr($option),
                                $required,
                                esc_html($option)
                            );
                        }
                    }
                }
                return $options_html;

            case 'file':
                return sprintf(
                    '<input type="file" id="%s" name="%s" class="jpm-form-field" %s>',
                    $field_id,
                    $field_name,
                    $required
                );

            case 'date':
                return sprintf(
                    '<input type="date" id="%s" name="%s" class="jpm-form-field" %s %s>',
                    $field_id,
                    $field_name,
                    $required,
                    $placeholder
                );

            case 'number':
                return sprintf(
                    '<input type="number" id="%s" name="%s" class="jpm-form-field" %s %s>',
                    $field_id,
                    $field_name,
                    $required,
                    $placeholder
                );

            case 'email':
                return sprintf(
                    '<input type="email" id="%s" name="%s" class="jpm-form-field" %s %s>',
                    $field_id,
                    $field_name,
                    $required,
                    $placeholder
                );

            case 'tel':
                return sprintf(
                    '<input type="tel" id="%s" name="%s" class="jpm-form-field" %s %s>',
                    $field_id,
                    $field_name,
                    $required,
                    $placeholder
                );

            default: // text
                return sprintf(
                    '<input type="text" id="%s" name="%s" class="jpm-form-field" %s %s>',
                    $field_id,
                    $field_name,
                    $required,
                    $placeholder
                );
        }
    }

    /**
     * Handle form submission
     */
    public function handle_form_submission()
    {
        check_ajax_referer('jpm_application_nonce', 'jpm_application_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Please log in to apply.', 'job-posting-manager')]);
        }

        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => __('Invalid job posting.', 'job-posting-manager')]);
        }

        $form_fields = get_post_meta($job_id, '_jpm_form_fields', true);
        if (empty($form_fields)) {
            wp_send_json_error(['message' => __('No form configured for this job.', 'job-posting-manager')]);
        }

        // Validate required fields
        $errors = [];
        foreach ($form_fields as $field) {
            if (!empty($field['required'])) {
                $field_name = $field['name'];
                $value = '';

                if ($field['type'] === 'file') {
                    $value = $_FILES['jpm_fields']['name'][$field_name] ?? '';
                } elseif (isset($_POST['jpm_fields'][$field_name])) {
                    $value = $_POST['jpm_fields'][$field_name];
                }

                if (empty($value)) {
                    $errors[] = sprintf(__('%s is required.', 'job-posting-manager'), $field['label']);
                }
            }
        }

        if (!empty($errors)) {
            wp_send_json_error(['message' => implode('<br>', $errors)]);
        }

        // Process form data
        $form_data = [];
        $resume_path = '';

        foreach ($form_fields as $field) {
            $field_name = $field['name'];

            if ($field['type'] === 'file') {
                // Handle file upload
                if (!empty($_FILES['jpm_fields']['name'][$field_name])) {
                    $file = [
                        'name' => $_FILES['jpm_fields']['name'][$field_name],
                        'type' => $_FILES['jpm_fields']['type'][$field_name],
                        'tmp_name' => $_FILES['jpm_fields']['tmp_name'][$field_name],
                        'error' => $_FILES['jpm_fields']['error'][$field_name],
                        'size' => $_FILES['jpm_fields']['size'][$field_name]
                    ];
                    $upload = wp_handle_upload($file, ['test_form' => false]);
                    if (isset($upload['error'])) {
                        wp_send_json_error(['message' => $upload['error']]);
                    }
                    if ($field_name === 'resume' || strpos($field_name, 'resume') !== false) {
                        $resume_path = $upload['file'];
                    }
                    $form_data[$field_name] = $upload['url'];
                }
            } else {
                $value = sanitize_text_field($_POST['jpm_fields'][$field_name] ?? '');
                if ($field['type'] === 'textarea') {
                    $value = sanitize_textarea_field($_POST['jpm_fields'][$field_name] ?? '');
                } elseif (in_array($field['type'], ['checkbox', 'radio', 'select'])) {
                    if (is_array($_POST['jpm_fields'][$field_name] ?? '')) {
                        $value = array_map('sanitize_text_field', $_POST['jpm_fields'][$field_name]);
                        $value = implode(', ', $value);
                    } else {
                        $value = sanitize_text_field($_POST['jpm_fields'][$field_name] ?? '');
                    }
                }
                $form_data[$field_name] = $value;
            }
        }

        // Save application
        $user_id = get_current_user_id();
        $notes = json_encode($form_data);

        $result = JPM_DB::insert_application($user_id, $job_id, $resume_path, $notes);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Send confirmation email
        if (class_exists('JPM_Emails')) {
            JPM_Emails::send_confirmation($result);
        }

        wp_send_json_success([
            'message' => __('Application submitted successfully!', 'job-posting-manager')
        ]);
    }
}

