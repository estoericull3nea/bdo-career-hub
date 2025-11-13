<?php
/**
 * Form Builder Class
 * Handles form building interface and form rendering
 */
class JPM_Form_Builder
{
    private $current_job_id = 0;
    private $current_job_title = '';

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
            'default' // Lower priority than template selector
        );
    }

    /**
     * Form builder meta box content
     */
    public function form_builder_meta_box($post)
    {
        wp_nonce_field('jpm_form_builder', 'jpm_form_builder_nonce');

        $form_fields = get_post_meta($post->ID, '_jpm_form_fields', true);
        if (!is_array($form_fields) || empty($form_fields)) {
            // If no fields exist, try to load from default template
            if (class_exists('JPM_Templates')) {
                // Get default template directly
                $templates_option = get_option('jpm_form_templates', []);
                foreach ($templates_option as $template) {
                    if (!empty($template['is_default']) && !empty($template['fields'])) {
                        $form_fields = $template['fields'];
                        // Save to post meta so it persists
                        update_post_meta($post->ID, '_jpm_form_fields', $form_fields);
                        update_post_meta($post->ID, '_jpm_selected_template', $template['id']);
                        break;
                    }
                }
                if (empty($form_fields)) {
                    $form_fields = [];
                }
            } else {
                $form_fields = [];
            }
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
                <div class="jpm-form-rows">
                    <?php if (!empty($form_fields)): ?>
                        <?php
                        // Group fields into rows based on column width
                        $current_row = [];
                        $current_row_width = 0;
                        $row_index = 0;

                        foreach ($form_fields as $index => $field):
                            $column_width = intval($field['column_width'] ?? 12);

                            // If adding this field would exceed 12 columns, start a new row
                            if ($current_row_width + $column_width > 12 && !empty($current_row)) {
                                // Render current row
                                echo '<div class="jpm-form-row' . (count($current_row) > 1 ? ' jpm-row-has-columns' : '') . '" data-row-index="' . esc_attr($row_index) . '">';
                                foreach ($current_row as $row_field) {
                                    if ($row_field['column_width'] < 12) {
                                        echo '<div class="jpm-form-column">';
                                    }
                                    $this->render_field_editor($row_field['field'], $row_field['index']);
                                    if ($row_field['column_width'] < 12) {
                                        echo '</div>';
                                    }
                                }
                                echo '</div>';
                                $current_row = [];
                                $current_row_width = 0;
                                $row_index++;
                            }

                            // Add field to current row
                            $current_row[] = [
                                'field' => $field,
                                'index' => $index,
                                'column_width' => $column_width
                            ];
                            $current_row_width += $column_width;

                            // If row is full (12 columns), render it
                            if ($current_row_width >= 12) {
                                echo '<div class="jpm-form-row' . (count($current_row) > 1 ? ' jpm-row-has-columns' : '') . '" data-row-index="' . esc_attr($row_index) . '">';
                                foreach ($current_row as $row_field) {
                                    if ($row_field['column_width'] < 12) {
                                        echo '<div class="jpm-form-column">';
                                    }
                                    $this->render_field_editor($row_field['field'], $row_field['index']);
                                    if ($row_field['column_width'] < 12) {
                                        echo '</div>';
                                    }
                                }
                                echo '</div>';
                                $current_row = [];
                                $current_row_width = 0;
                                $row_index++;
                            }
                        endforeach;

                        // Render any remaining fields
                        if (!empty($current_row)) {
                            echo '<div class="jpm-form-row' . (count($current_row) > 1 ? ' jpm-row-has-columns' : '') . '" data-row-index="' . esc_attr($row_index) . '">';
                            foreach ($current_row as $row_field) {
                                if ($row_field['column_width'] < 12) {
                                    echo '<div class="jpm-form-column">';
                                }
                                $this->render_field_editor($row_field['field'], $row_field['index']);
                                if ($row_field['column_width'] < 12) {
                                    echo '</div>';
                                }
                            }
                            echo '</div>';
                        }
                        ?>
                    <?php endif; ?>
                </div>
            </div>

            <input type="hidden" name="jpm_form_fields_json" id="jpm-form-fields-json"
                value="<?php echo esc_attr(json_encode($form_fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
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
            'column_width' => '12', // Default full width (12 columns)
        ]);
        ?>
        <div class="jpm-field-editor" data-index="<?php echo esc_attr($index); ?>">
            <div class="jpm-field-header">
                <span class="jpm-field-handle dashicons dashicons-menu"></span>
                <strong
                    class="jpm-field-title"><?php echo esc_html($field['label'] ?: __('Untitled Field', 'job-posting-manager')); ?></strong>
                <span class="jpm-field-type-badge"><?php echo esc_html($field['type']); ?></span>
                <span class="jpm-field-column-badge" title="<?php _e('Column Width', 'job-posting-manager'); ?>">
                    <?php
                    $col_width = intval($field['column_width'] ?? 12);
                    $col_text = $col_width == 12 ? __('Full', 'job-posting-manager') : sprintf(__('%d cols', 'job-posting-manager'), $col_width);
                    echo esc_html($col_text);
                    ?>
                </span>
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
                    <tr>
                        <th><label><?php _e('Column Width', 'job-posting-manager'); ?></label></th>
                        <td>
                            <input type="text" class="jpm-field-column-width"
                                value="<?php echo esc_attr($field['column_width'] ?? '12'); ?>" readonly>
                            <p class="description">
                                <?php _e('Column width is automatically calculated based on drag-and-drop position. Drag fields left/right to create columns (max 3 per row).', 'job-posting-manager'); ?>
                            </p>
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
        // Check post type first (before nonce check for autosaves)
        if (get_post_type($post_id) !== 'job_posting') {
            return;
        }

        // Check if nonce is set and verify
        // Note: For autosaves, nonce might not be present, but we still want to save form fields
        if (isset($_POST['jpm_form_builder_nonce'])) {
            if (!wp_verify_nonce($_POST['jpm_form_builder_nonce'], 'jpm_form_builder')) {
                return;
            }
        } else {
            // If nonce is not present, only proceed if it's an autosave or we have form fields data
            if (!defined('DOING_AUTOSAVE') || !DOING_AUTOSAVE) {
                // For regular saves, nonce is required
                if (!isset($_POST['jpm_form_fields_json']) || empty($_POST['jpm_form_fields_json'])) {
                    return;
                }
            }
        }

        // Check if user has permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save form fields
        if (isset($_POST['jpm_form_fields_json']) && !empty($_POST['jpm_form_fields_json'])) {
            $form_fields_json = stripslashes($_POST['jpm_form_fields_json']);
            $form_fields = json_decode($form_fields_json, true);

            // Check for JSON decode errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JPM Form Builder: JSON decode error - ' . json_last_error_msg());
                error_log('JPM Form Builder: JSON data - ' . substr($form_fields_json, 0, 500));
                return;
            }

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
                        'column_width' => sanitize_text_field($field['column_width'] ?? '12'),
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
            'column_width' => '12',
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

        // Only on single job posting pages
        if (!is_singular('job_posting')) {
            return $content;
        }

        // Show the form to all users (logged in or not)
        $form_fields = get_post_meta($post->ID, '_jpm_form_fields', true);
        if (empty($form_fields) || !is_array($form_fields)) {
            return $content;
        }

        // Store current job ID and title for auto-filling 1st choice
        $this->current_job_id = $post->ID;
        $this->current_job_title = $post->post_title;

        // Group fields into steps
        $steps = $this->group_fields_into_steps($form_fields);

        ob_start();
        ?>
        <div class="jpm-application-form-wrapper">
            <h3><?php _e('Apply for this Position', 'job-posting-manager'); ?></h3>

            <!-- Stepper Navigation -->
            <div class="jpm-stepper-navigation">
                <?php foreach ($steps as $step_index => $step): ?>
                    <div class="jpm-stepper-step <?php echo $step_index === 0 ? 'active' : ''; ?>"
                        data-step="<?php echo esc_attr($step_index); ?>">
                        <span class="jpm-stepper-number"><?php echo esc_html($step_index + 1); ?>.</span>
                        <span class="jpm-stepper-label"><?php echo esc_html($step['title']); ?></span>
                        <?php if ($step_index < count($steps) - 1): ?>
                            <span class="jpm-stepper-chevron">›</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <form id="jpm-application-form" class="jpm-application-form" method="post" enctype="multipart/form-data" action="#"
                novalidate>
                <?php wp_nonce_field('jpm_application_form', 'jpm_application_nonce'); ?>
                <input type="hidden" name="job_id" value="<?php echo esc_attr($post->ID); ?>">

                <?php
                // Generate application number: YY-BDO-XXXXXXXX (8 random digits)
                $year = date('y'); // Last 2 digits of current year
                $random_digits = str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT); // 8 random digits
                $application_number = $year . '-BDO-' . $random_digits;

                // Generate date of registration: mm/dd/yyyy
                $date_of_registration = date('m/d/Y'); // Current date in mm/dd/yyyy format
                ?>

                <!-- Step 0: Application Info (always visible) -->
                <div class="jpm-form-step jpm-step-application-info" data-step="0">
                    <div class="jpm-auto-fill-fields-container">
                        <div class="jpm-form-field-group jpm-application-number-field">
                            <label
                                for="jpm_application_number"><?php _e('Application Number', 'job-posting-manager'); ?></label>
                            <input type="text" id="jpm_application_number" name="application_number" class="jpm-form-field"
                                value="<?php echo esc_attr($application_number); ?>" readonly
                                style="background-color: #f5f5f5; cursor: not-allowed;">
                            <p class="description">
                                <?php _e('Your unique application reference number', 'job-posting-manager'); ?>
                            </p>
                        </div>

                        <div class="jpm-form-field-group jpm-date-of-registration-field">
                            <label
                                for="jpm_date_of_registration"><?php _e('Date of Registration', 'job-posting-manager'); ?></label>
                            <input type="text" id="jpm_date_of_registration" name="date_of_registration" class="jpm-form-field"
                                value="<?php echo esc_attr($date_of_registration); ?>" readonly
                                style="background-color: #f5f5f5; cursor: not-allowed;">
                            <p class="description"><?php _e('Date when the application is submitted', 'job-posting-manager'); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <?php
                // Render each step
                foreach ($steps as $step_index => $step):
                    $step_number = $step_index + 1;
                    ?>
                    <div class="jpm-form-step" data-step="<?php echo esc_attr($step_number); ?>"
                        style="display: <?php echo $step_index === 0 ? 'block' : 'none'; ?>;">
                        <?php
                        // Group fields in this step into rows based on column width
                        $current_row = [];
                        $current_row_width = 0;

                        foreach ($step['fields'] as $field_data):
                            $field = $field_data['field'];
                            $index = $field_data['index'];
                            $column_width = intval($field['column_width'] ?? 12);

                            // If adding this field would exceed 12 columns, render current row
                            if ($current_row_width + $column_width > 12 && !empty($current_row)) {
                                $this->render_form_row($current_row);
                                $current_row = [];
                                $current_row_width = 0;
                            }

                            // Add field to current row
                            $current_row[] = [
                                'field' => $field,
                                'index' => $index,
                                'column_width' => $column_width
                            ];
                            $current_row_width += $column_width;

                            // If row is full (12 columns), render it
                            if ($current_row_width >= 12) {
                                $this->render_form_row($current_row);
                                $current_row = [];
                                $current_row_width = 0;
                            }
                        endforeach;

                        // Render any remaining fields
                        if (!empty($current_row)) {
                            $this->render_form_row($current_row);
                        }
                        ?>
                    </div>
                <?php endforeach; ?>

                <!-- Navigation Buttons -->
                <div class="jpm-stepper-buttons">
                    <button type="button" class="jpm-btn jpm-btn-prev" style="display: none;">
                        <?php _e('Previous', 'job-posting-manager'); ?>
                    </button>
                    <button type="button" class="jpm-btn jpm-btn-next">
                        <?php _e('Next', 'job-posting-manager'); ?>
                    </button>
                    <button type="submit" class="jpm-btn jpm-btn-submit" style="display: none;">
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
     * Group form fields into logical steps
     */
    private function group_fields_into_steps($form_fields)
    {
        $steps = [];
        $total_fields = count($form_fields);

        // Determine number of steps (3-4 steps for better UX)
        $num_steps = min(4, max(3, ceil($total_fields / 5)));
        $fields_per_step = ceil($total_fields / $num_steps);

        // Default step titles
        $default_titles = [
            __('Personal', 'job-posting-manager'),
            __('Education', 'job-posting-manager'),
            __('Employment', 'job-posting-manager'),
            __('Additional', 'job-posting-manager'),
        ];

        $field_index = 0;
        for ($step = 0; $step < $num_steps; $step++) {
            $step_fields = [];
            $step_title = $default_titles[$step] ?? sprintf(__('Step %d', 'job-posting-manager'), $step + 1);

            // Collect fields for this step
            $fields_in_step = 0;
            while ($field_index < $total_fields && $fields_in_step < $fields_per_step) {
                $field = $form_fields[$field_index];

                // Try to detect step from field name/label
                $field_name_lower = strtolower($field['name'] ?? '');
                $field_label_lower = strtolower($field['label'] ?? '');

                // Auto-detect step title from first field if not set
                if ($step === 0 && $fields_in_step === 0) {
                    if (
                        stripos($field_name_lower, 'personal') !== false ||
                        stripos($field_label_lower, 'personal') !== false ||
                        stripos($field_name_lower, 'name') !== false ||
                        stripos($field_name_lower, 'email') !== false
                    ) {
                        $step_title = __('Personal', 'job-posting-manager');
                    }
                } elseif ($step === 1 && $fields_in_step === 0) {
                    if (
                        stripos($field_name_lower, 'education') !== false ||
                        stripos($field_label_lower, 'education') !== false ||
                        stripos($field_name_lower, 'school') !== false ||
                        stripos($field_name_lower, 'degree') !== false
                    ) {
                        $step_title = __('Education', 'job-posting-manager');
                    }
                } elseif ($step === 2 && $fields_in_step === 0) {
                    if (
                        stripos($field_name_lower, 'employment') !== false ||
                        stripos($field_label_lower, 'employment') !== false ||
                        stripos($field_name_lower, 'work') !== false ||
                        stripos($field_name_lower, 'experience') !== false
                    ) {
                        $step_title = __('Employment', 'job-posting-manager');
                    }
                } elseif ($step === 3 && $fields_in_step === 0) {
                    if (
                        stripos($field_name_lower, 'achievement') !== false ||
                        stripos($field_label_lower, 'achievement') !== false ||
                        stripos($field_name_lower, 'skill') !== false
                    ) {
                        $step_title = __('Achievements', 'job-posting-manager');
                    }
                }

                $step_fields[] = [
                    'field' => $field,
                    'index' => $field_index
                ];

                $field_index++;
                $fields_in_step++;
            }

            if (!empty($step_fields)) {
                $steps[] = [
                    'title' => $step_title,
                    'fields' => $step_fields
                ];
            }
        }

        return $steps;
    }

    /**
     * Render a row of form fields
     */
    private function render_form_row($row_fields)
    {
        echo '<div class="jpm-form-row">';
        foreach ($row_fields as $row_field) {
            echo '<div class="jpm-form-col jpm-col-' . esc_attr($row_field['column_width']) . '">';
            echo '<div class="jpm-form-field-group">';
            echo '<label for="jpm_field_' . esc_attr($row_field['index']) . '">';
            echo esc_html($row_field['field']['label']);
            if (!empty($row_field['field']['required'])) {
                echo ' <span class="required">*</span>';
            }
            echo '</label>';
            if (!empty($row_field['field']['description'])) {
                echo '<p class="description">' . esc_html($row_field['field']['description']) . '</p>';
            }
            echo $this->render_form_field($row_field['field'], $row_field['index']);
            echo '<span class="jpm-field-error" data-field-name="' . esc_attr($row_field['field']['name']) . '" style="display: none;"></span>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
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

                // Check if this is a position choice field that needs dynamic job options
                if (in_array($field['name'], ['position_1st_choice', 'position_2nd_choice', 'position_3rd_choice'])) {
                    // Get all published job postings
                    $jobs = get_posts([
                        'post_type' => 'job_posting',
                        'posts_per_page' => -1,
                        'post_status' => 'publish',
                        'orderby' => 'title',
                        'order' => 'ASC'
                    ]);
                    foreach ($jobs as $job) {
                        $selected = '';
                        // Auto-select current job for 1st choice only
                        if ($field['name'] === 'position_1st_choice' && !empty($this->current_job_title) && $job->post_title === $this->current_job_title) {
                            $selected = ' selected="selected"';
                        }
                        $options_html .= sprintf('<option value="%s"%s>%s</option>', esc_attr($job->post_title), $selected, esc_html($job->post_title));
                    }
                } elseif (!empty($field['options'])) {
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
        // Verify nonce
        if (!isset($_POST['jpm_application_nonce']) || !wp_verify_nonce($_POST['jpm_application_nonce'], 'jpm_application_form')) {
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page and try again.', 'job-posting-manager')]);
        }

        // Allow both logged in and non-logged-in users to apply
        $job_id = intval($_POST['job_id'] ?? 0);
        if (!$job_id) {
            wp_send_json_error(['message' => __('Invalid job posting.', 'job-posting-manager')]);
        }

        $form_fields = get_post_meta($job_id, '_jpm_form_fields', true);
        if (empty($form_fields)) {
            wp_send_json_error(['message' => __('No form configured for this job.', 'job-posting-manager')]);
        }

        // Validate required fields
        $field_errors = [];
        $general_errors = [];

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
                    $field_errors[$field_name] = sprintf(__('%s is required.', 'job-posting-manager'), $field['label']);
                }
            }
        }

        if (!empty($field_errors) || !empty($general_errors)) {
            wp_send_json_error([
                'message' => __('Please correct the errors below.', 'job-posting-manager'),
                'field_errors' => $field_errors,
                'general_errors' => $general_errors
            ]);
        }

        // Get application number from form
        $application_number = sanitize_text_field($_POST['application_number'] ?? '');

        // Get date of registration from form
        $date_of_registration = sanitize_text_field($_POST['date_of_registration'] ?? '');

        // Process form data
        $form_data = [];
        $resume_path = '';

        // Add application number to form data
        if (!empty($application_number)) {
            $form_data['application_number'] = $application_number;
        }

        // Add date of registration to form data
        if (!empty($date_of_registration)) {
            $form_data['date_of_registration'] = $date_of_registration;
        }

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

        // Extract first name, last name, and email from form data
        $first_name = '';
        $last_name = '';
        $email = '';

        // Try to find first name, last name, and email in form data
        // Check common field names (including variations)
        $first_name_fields = ['first_name', 'firstname', 'fname', 'first-name', 'given_name', 'givenname', 'given-name', 'given name'];
        $last_name_fields = ['last_name', 'lastname', 'lname', 'last-name', 'surname', 'family_name', 'familyname', 'family-name', 'family name'];
        $email_fields = ['email', 'email_address', 'e-mail', 'email-address'];

        // Try exact field name matches first
        foreach ($first_name_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $first_name = sanitize_text_field($form_data[$field_name]);
                break;
            }
        }

        foreach ($last_name_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $last_name = sanitize_text_field($form_data[$field_name]);
                break;
            }
        }

        // If still not found, try case-insensitive and partial matches
        if (empty($first_name)) {
            foreach ($form_data as $field_name => $field_value) {
                $field_name_lower = strtolower(str_replace(['_', '-', ' '], '', $field_name));
                if (in_array($field_name_lower, ['firstname', 'fname', 'givenname', 'given']) && !empty($field_value)) {
                    $first_name = sanitize_text_field($field_value);
                    break;
                }
            }
        }

        if (empty($last_name)) {
            foreach ($form_data as $field_name => $field_value) {
                $field_name_lower = strtolower(str_replace(['_', '-', ' '], '', $field_name));
                if (in_array($field_name_lower, ['lastname', 'lname', 'surname', 'familyname', 'family']) && !empty($field_value)) {
                    $last_name = sanitize_text_field($field_value);
                    break;
                }
            }
        }

        foreach ($email_fields as $field_name) {
            if (isset($form_data[$field_name]) && !empty($form_data[$field_name])) {
                $email = sanitize_email($form_data[$field_name]);
                break;
            }
        }

        // Create or get user account if we have first name, last name, and email
        $user_id = get_current_user_id();
        $new_user_created = false;

        if (!empty($first_name) && !empty($last_name) && !empty($email) && is_email($email)) {
            // Check if user already exists by email
            $existing_user = get_user_by('email', $email);

            if ($existing_user) {
                // User exists, use their ID
                $user_id = $existing_user->ID;

                // Update user meta if needed
                if (empty($existing_user->first_name)) {
                    update_user_meta($user_id, 'first_name', $first_name);
                }
                if (empty($existing_user->last_name)) {
                    update_user_meta($user_id, 'last_name', $last_name);
                }
            } else {
                // Create new user account
                $username = sanitize_user($email, true);

                // Ensure username is unique
                $original_username = $username;
                $counter = 1;
                while (username_exists($username)) {
                    $username = $original_username . $counter;
                    $counter++;
                }

                // Generate random password
                $password = wp_generate_password(32, false);

                // Create user
                $user_id = wp_create_user($username, $password, $email);

                if (!is_wp_error($user_id)) {
                    $new_user_created = true;

                    // Set user role to 'customer' or 'subscriber'
                    $user = new WP_User($user_id);
                    if (get_role('customer')) {
                        $user->set_role('customer');
                    } else {
                        $user->set_role('subscriber');
                    }

                    // Set user meta
                    update_user_meta($user_id, 'first_name', $first_name);
                    update_user_meta($user_id, 'last_name', $last_name);

                    // Update display name
                    wp_update_user([
                        'ID' => $user_id,
                        'display_name' => trim($first_name . ' ' . $last_name),
                        'first_name' => $first_name,
                        'last_name' => $last_name
                    ]);

                    // Send account creation email to customer
                    if (class_exists('JPM_Emails')) {
                        try {
                            JPM_Emails::send_account_creation_notification($user_id, $email, $password, $first_name, $last_name);
                        } catch (Exception $e) {
                            error_log('JPM: Failed to send account creation email - ' . $e->getMessage());
                        }
                    }

                    // Send admin notification about new customer
                    if (class_exists('JPM_Emails')) {
                        try {
                            JPM_Emails::send_new_customer_notification($user_id, $email, $first_name, $last_name);
                        } catch (Exception $e) {
                            error_log('JPM: Failed to send new customer notification - ' . $e->getMessage());
                        }
                    }
                } else {
                    // If user creation failed, log error but continue with guest application
                    error_log('JPM: Failed to create user account - ' . $user_id->get_error_message());
                    $user_id = 0;
                }
            }
        }

        // Save application
        $notes = json_encode($form_data);

        $result = JPM_DB::insert_application($user_id, $job_id, $resume_path, $notes);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        // Get application ID from database insert
        global $wpdb;
        $application_id = $wpdb->insert_id;

        // Send confirmation email to applicant
        $email_errors = [];
        if (class_exists('JPM_Emails')) {
            try {
                $result = JPM_Emails::send_confirmation($application_id, $job_id, $email, $first_name, $last_name, $form_data);
                if (!$result) {
                    $email_errors[] = __('Failed to send confirmation email to applicant.', 'job-posting-manager');
                }
            } catch (Exception $e) {
                $email_errors[] = __('Error sending confirmation email: ', 'job-posting-manager') . $e->getMessage();
            }
        }

        // Send admin notification email
        if (class_exists('JPM_Emails')) {
            try {
                $result = JPM_Emails::send_admin_notification($application_id, $job_id, $form_data, 'palisocericson87@gmail.com', $email, $first_name, $last_name);
                if (!$result) {
                    $email_errors[] = __('Failed to send notification email to admin.', 'job-posting-manager');
                }
            } catch (Exception $e) {
                $email_errors[] = __('Error sending admin notification: ', 'job-posting-manager') . $e->getMessage();
            }
        }

        // Prepare success message
        $message = __('Application submitted successfully!', 'job-posting-manager');
        if (!empty($email_errors)) {
            $message .= ' ' . __('Note: Some emails may not have been sent.', 'job-posting-manager');
            // Log email errors for debugging
            error_log('JPM Email Errors: ' . implode(' | ', $email_errors));
        }

        wp_send_json_success([
            'message' => $message,
            'email_errors' => $email_errors
        ]);
    }
}

