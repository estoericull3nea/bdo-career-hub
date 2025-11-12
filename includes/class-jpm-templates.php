<?php
/**
 * Template Management Class
 * Handles form templates for job applications
 */
class JPM_Templates {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_templates_menu']);
        add_action('wp_ajax_jpm_save_template', [$this, 'ajax_save_template']);
        add_action('wp_ajax_jpm_delete_template', [$this, 'ajax_delete_template']);
        add_action('wp_ajax_jpm_get_template', [$this, 'ajax_get_template']);
        add_action('add_meta_boxes', [$this, 'add_template_meta_box']);
        add_action('save_post', [$this, 'apply_template_to_job'], 10, 2);
        add_action('wp_insert_post', [$this, 'auto_apply_template_on_create'], 10, 3);
    }

    /**
     * Add templates menu
     */
    public function add_templates_menu() {
        add_submenu_page(
            'jpm-dashboard',
            __('Form Templates', 'job-posting-manager'),
            __('Form Templates', 'job-posting-manager'),
            'manage_options',
            'jpm-templates',
            [$this, 'templates_page']
        );
    }

    /**
     * Templates management page
     */
    public function templates_page() {
        $templates = $this->get_all_templates();
        $editing_template = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $template_data = null;
        
        if ($editing_template) {
            $template_data = $this->get_template($editing_template);
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Form Templates', 'job-posting-manager'); ?></h1>
            
            <div class="jpm-templates-wrapper">
                <div class="jpm-templates-list">
                    <h2><?php _e('Available Templates', 'job-posting-manager'); ?></h2>
                    <a href="<?php echo admin_url('admin.php?page=jpm-templates&edit=0'); ?>" class="button button-primary">
                        <?php _e('+ Create New Template', 'job-posting-manager'); ?>
                    </a>
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Template Name', 'job-posting-manager'); ?></th>
                                <th><?php _e('Fields', 'job-posting-manager'); ?></th>
                                <th><?php _e('Default', 'job-posting-manager'); ?></th>
                                <th><?php _e('Actions', 'job-posting-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $template): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($template['name']); ?></strong></td>
                                    <td><?php echo count($template['fields']); ?> <?php _e('fields', 'job-posting-manager'); ?></td>
                                    <td><?php echo !empty($template['is_default']) ? __('Yes', 'job-posting-manager') : __('No', 'job-posting-manager'); ?></td>
                                    <td>
                                        <a href="<?php echo admin_url('admin.php?page=jpm-templates&edit=' . $template['id']); ?>" class="button button-small">
                                            <?php _e('Edit', 'job-posting-manager'); ?>
                                        </a>
                                        <?php if (empty($template['is_default'])): ?>
                                            <button type="button" class="button button-small jpm-delete-template" data-id="<?php echo $template['id']; ?>">
                                                <?php _e('Delete', 'job-posting-manager'); ?>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Add template selector meta box
     */
    public function add_template_meta_box() {
        add_meta_box(
            'jpm_template_selector',
            __('Form Template', 'job-posting-manager'),
            [$this, 'template_selector_meta_box'],
            'job_posting',
            'side',
            'high' // Higher priority to appear before form builder
        );
    }

    /**
     * Template selector meta box
     */
    public function template_selector_meta_box($post) {
        $templates = $this->get_all_templates();
        $selected_template = get_post_meta($post->ID, '_jpm_selected_template', true);
        wp_nonce_field('jpm_template_selector', 'jpm_template_selector_nonce');
        ?>
        <p>
            <label for="jpm_template_select">
                <?php _e('Select a template to auto-populate form fields:', 'job-posting-manager'); ?>
            </label>
            <select name="jpm_selected_template" id="jpm_template_select" style="width: 100%;">
                <option value=""><?php _e('-- Select Template --', 'job-posting-manager'); ?></option>
                <?php foreach ($templates as $template): ?>
                    <option value="<?php echo esc_attr($template['id']); ?>" <?php selected($selected_template, $template['id']); ?>>
                        <?php echo esc_html($template['name']); ?>
                        <?php if (!empty($template['is_default'])): ?>
                            (<?php _e('Default', 'job-posting-manager'); ?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="description">
            <?php _e('When you save this job, the selected template will be applied to create the application form.', 'job-posting-manager'); ?>
        </p>
        <?php
    }

    /**
     * Auto-apply default template when a new job is created
     */
    public function auto_apply_template_on_create($post_id, $post, $update) {
        // Only run for new posts, not updates
        if ($update) {
            return;
        }

        // Check post type
        if (!isset($post->post_type) || $post->post_type !== 'job_posting') {
            return;
        }

        // Check if form fields already exist
        $existing_fields = get_post_meta($post_id, '_jpm_form_fields', true);
        if (!empty($existing_fields)) {
            return;
        }

        // Apply default template automatically
        $default_template = $this->get_default_template();
        if ($default_template && !empty($default_template['fields'])) {
            update_post_meta($post_id, '_jpm_form_fields', $default_template['fields']);
            update_post_meta($post_id, '_jpm_selected_template', $default_template['id']);
        }
    }

    /**
     * Apply template to job when saved
     */
    public function apply_template_to_job($post_id, $post) {
        // Check post type
        if ($post->post_type !== 'job_posting') {
            return;
        }

        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check user permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Apply template if selected
        if (isset($_POST['jpm_selected_template']) && !empty($_POST['jpm_selected_template'])) {
            // Verify nonce if it exists
            if (isset($_POST['jpm_template_selector_nonce']) && !wp_verify_nonce($_POST['jpm_template_selector_nonce'], 'jpm_template_selector')) {
                return;
            }
            
            $template_id = intval($_POST['jpm_selected_template']);
            $template = $this->get_template($template_id);
            
            if ($template && !empty($template['fields'])) {
                // Save template fields to job
                update_post_meta($post_id, '_jpm_form_fields', $template['fields']);
                update_post_meta($post_id, '_jpm_selected_template', $template_id);
            }
        } else {
            // If no template selected and no form fields exist, apply default template
            $existing_fields = get_post_meta($post_id, '_jpm_form_fields', true);
            if (empty($existing_fields)) {
                $default_template = $this->get_default_template();
                if ($default_template) {
                    update_post_meta($post_id, '_jpm_form_fields', $default_template['fields']);
                    update_post_meta($post_id, '_jpm_selected_template', $default_template['id']);
                }
            }
        }
    }

    /**
     * Get default template
     */
    public function get_default_template() {
        $templates = $this->get_all_templates();
        foreach ($templates as $template) {
            if (!empty($template['is_default'])) {
                return $template;
            }
        }
        return null;
    }

    /**
     * Get all templates
     */
    public function get_all_templates() {
        $templates = get_option('jpm_form_templates', []);
        
        // Ensure default template exists
        if (empty($templates)) {
            $this->create_default_template();
            $templates = get_option('jpm_form_templates', []);
        } else {
            // Update default template if it exists and has old fields
            foreach ($templates as $index => $template) {
                if (!empty($template['is_default'])) {
                    // Check if template has the old fields that should be removed
                    $has_old_fields = false;
                    if (is_array($template['fields'])) {
                        foreach ($template['fields'] as $field) {
                            if (isset($field['name']) && ($field['name'] === 'date_of_registration' || $field['name'] === 'applicant_number')) {
                                $has_old_fields = true;
                                break;
                            }
                        }
                    }
                    
                    // If old fields exist, update the template with new default fields
                    if ($has_old_fields) {
                        $templates[$index]['fields'] = $this->get_default_template_fields();
                        update_option('jpm_form_templates', $templates);
                    }
                    break;
                }
            }
        }
        
        return $templates;
    }

    /**
     * Get a specific template
     */
    public function get_template($template_id) {
        $templates = $this->get_all_templates();
        foreach ($templates as $template) {
            if ($template['id'] == $template_id) {
                return $template;
            }
        }
        return null;
    }

    /**
     * Create default template
     */
    public function create_default_template() {
        $default_template = [
            'id' => 1,
            'name' => 'Default Application Form',
            'is_default' => true,
            'fields' => $this->get_default_template_fields()
        ];
        
        $templates = [$default_template];
        update_option('jpm_form_templates', $templates);
        
        return $default_template;
    }

    /**
     * Get default template fields structure
     */
    private function get_default_template_fields() {
        // Position choice fields will be populated dynamically from available jobs
        // So we don't need to set options here - they'll be loaded when the form is rendered
        
        return [
            // POSITION APPLIED section
            [
                'type' => 'select',
                'label' => '1st Choice',
                'name' => 'position_1st_choice',
                'required' => true,
                'placeholder' => '',
                'options' => '', // Will be populated dynamically from available jobs
                'description' => '',
                'column_width' => '4'
            ],
            [
                'type' => 'select',
                'label' => '2nd Choice',
                'name' => 'position_2nd_choice',
                'required' => true,
                'placeholder' => '',
                'options' => '', // Will be populated dynamically from available jobs
                'description' => '',
                'column_width' => '4'
            ],
            [
                'type' => 'select',
                'label' => '3rd Choice',
                'name' => 'position_3rd_choice',
                'required' => true,
                'placeholder' => '',
                'options' => '', // Will be populated dynamically from available jobs
                'description' => '',
                'column_width' => '4'
            ],
            
            // PERSONAL HISTORY section
            [
                'type' => 'text',
                'label' => 'SURNAME',
                'name' => 'surname',
                'required' => true,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '4'
            ],
            [
                'type' => 'text',
                'label' => 'GIVEN NAME',
                'name' => 'given_name',
                'required' => true,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '4'
            ],
            [
                'type' => 'text',
                'label' => 'MIDDLE NAME',
                'name' => 'middle_name',
                'required' => true,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '4'
            ],
            [
                'type' => 'textarea',
                'label' => 'Present Address',
                'name' => 'present_address',
                'required' => true,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '12'
            ],
            [
                'type' => 'tel',
                'label' => 'Mobile Number',
                'name' => 'mobile_number',
                'required' => true,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '6'
            ],
            [
                'type' => 'email',
                'label' => 'Email Address',
                'name' => 'email_address',
                'required' => true,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '6'
            ],
            [
                'type' => 'date',
                'label' => 'Birth Date (mm/dd/yyyy)',
                'name' => 'birth_date',
                'required' => true,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '4'
            ],
            [
                'type' => 'text',
                'label' => 'Birth Place',
                'name' => 'birth_place',
                'required' => true,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '4'
            ],
            [
                'type' => 'number',
                'label' => 'Age',
                'name' => 'age',
                'required' => false,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '2'
            ],
            [
                'type' => 'select',
                'label' => 'Sex',
                'name' => 'sex',
                'required' => false,
                'placeholder' => '',
                'options' => "Male\nFemale\nOther",
                'description' => '',
                'column_width' => '2'
            ],
            [
                'type' => 'text',
                'label' => 'Height (ft) / (mtrs)',
                'name' => 'height',
                'required' => false,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '6'
            ],
            [
                'type' => 'text',
                'label' => 'Weight (bls) / (kg)',
                'name' => 'weight',
                'required' => false,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '6'
            ],
            [
                'type' => 'select',
                'label' => 'Civil Status',
                'name' => 'civil_status',
                'required' => true,
                'placeholder' => '',
                'options' => "Single\nMarried\nDivorced\nWidowed",
                'description' => '',
                'column_width' => '6'
            ],
            [
                'type' => 'text',
                'label' => 'Religion',
                'name' => 'religion',
                'required' => true,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '6'
            ],
            [
                'type' => 'text',
                'label' => 'Name of Spouse',
                'name' => 'spouse_name',
                'required' => false,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '6'
            ],
            [
                'type' => 'text',
                'label' => 'Occupation',
                'name' => 'occupation',
                'required' => false,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '6'
            ],
            [
                'type' => 'text',
                'label' => 'Who referred you to BDOI',
                'name' => 'referred_by',
                'required' => false,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '6'
            ],
            [
                'type' => 'text',
                'label' => 'Relationship',
                'name' => 'relationship',
                'required' => false,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '6'
            ],
            [
                'type' => 'text',
                'label' => 'From what company',
                'name' => 'from_company',
                'required' => false,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '12'
            ],
            [
                'type' => 'textarea',
                'label' => 'Address',
                'name' => 'company_address',
                'required' => false,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '8'
            ],
            [
                'type' => 'tel',
                'label' => 'Tel / Mobile no.',
                'name' => 'company_tel',
                'required' => false,
                'placeholder' => '',
                'options' => '',
                'description' => '',
                'column_width' => '4'
            ],
        ];
    }

    /**
     * AJAX: Save template
     */
    public function ajax_save_template() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jpm_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'job-posting-manager')]);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }

        $template_id = intval($_POST['template_id'] ?? 0);
        $template_name = sanitize_text_field($_POST['template_name'] ?? '');
        $form_fields = json_decode(stripslashes($_POST['form_fields'] ?? '[]'), true);

        if (empty($template_name)) {
            wp_send_json_error(['message' => __('Template name is required', 'job-posting-manager')]);
        }

        $templates = $this->get_all_templates();
        
        if ($template_id > 0) {
            // Update existing template
            foreach ($templates as &$template) {
                if ($template['id'] == $template_id) {
                    $template['name'] = $template_name;
                    $template['fields'] = $form_fields;
                    break;
                }
            }
        } else {
            // Create new template
            $new_id = 1;
            foreach ($templates as $template) {
                if ($template['id'] >= $new_id) {
                    $new_id = $template['id'] + 1;
                }
            }
            $templates[] = [
                'id' => $new_id,
                'name' => $template_name,
                'is_default' => false,
                'fields' => $form_fields
            ];
        }

        update_option('jpm_form_templates', $templates);
        wp_send_json_success(['message' => __('Template saved successfully', 'job-posting-manager')]);
    }

    /**
     * AJAX: Delete template
     */
    public function ajax_delete_template() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jpm_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'job-posting-manager')]);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }

        $template_id = intval($_POST['template_id'] ?? 0);
        
        $templates = $this->get_all_templates();
        $templates = array_filter($templates, function($template) use ($template_id) {
            return $template['id'] != $template_id || !empty($template['is_default']);
        });
        
        update_option('jpm_form_templates', array_values($templates));
        wp_send_json_success(['message' => __('Template deleted successfully', 'job-posting-manager')]);
    }

    /**
     * AJAX: Get template
     */
    public function ajax_get_template() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'jpm_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'job-posting-manager')]);
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'job-posting-manager')]);
        }

        $template_id = intval($_POST['template_id'] ?? 0);
        $template = $this->get_template($template_id);
        
        if ($template) {
            wp_send_json_success(['template' => $template]);
        } else {
            wp_send_json_error(['message' => __('Template not found', 'job-posting-manager')]);
        }
    }

    /**
     * Initialize default template on activation
     */
    public static function init_default_template() {
        $templates = get_option('jpm_form_templates', []);
        if (empty($templates)) {
            $instance = new self();
            $instance->create_default_template();
        }
    }
}

