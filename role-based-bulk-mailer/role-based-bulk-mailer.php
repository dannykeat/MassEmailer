<?php
/**
 * Plugin Name: Role-Based Bulk Mailer
 * Description: Send formatted, template-driven emails to users by role with logging and placeholder support.
 * Version: 1.0.0
 * Author: MassEmailer
 * License: GPL2+
 */

if (!defined('ABSPATH')) {
    exit;
}

class RBM_Role_Based_Bulk_Mailer
{
    const TEMPLATE_CPT = 'rbm_mail_template';
    const LOG_TABLE_SUFFIX = 'rbm_mail_log';

    public function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('init', [$this, 'register_template_cpt']);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_template_save']);
        add_action('admin_post_rbm_send_email', [$this, 'handle_send_email']);
    }

    public function activate()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sent_at DATETIME NOT NULL,
            role_targets TEXT NOT NULL,
            recipient_count INT(11) NOT NULL DEFAULT 0,
            subject TEXT NOT NULL,
            template_id BIGINT(20) UNSIGNED NULL,
            sent_by BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'success',
            notes TEXT NULL,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $this->register_template_cpt();
        flush_rewrite_rules();
    }

    public function register_template_cpt()
    {
        register_post_type(self::TEMPLATE_CPT, [
            'labels' => [
                'name' => __('Email Templates', 'rbm'),
                'singular_name' => __('Email Template', 'rbm'),
                'add_new' => __('Add Template', 'rbm'),
                'add_new_item' => __('Add New Email Template', 'rbm'),
                'edit_item' => __('Edit Email Template', 'rbm'),
            ],
            'public' => false,
            'show_ui' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function register_admin_menu()
    {
        add_users_page(
            __('Role Mailer', 'rbm'),
            __('Role Mailer', 'rbm'),
            'manage_options',
            'rbm-role-mailer',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'rbm'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'compose';
        $roles = wp_roles()->roles;
        $templates = $this->get_templates();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Role-Based Bulk Mailer', 'rbm'); ?></h1>
            <p><?php esc_html_e('Send HTML emails to all users in one or more WordPress roles.', 'rbm'); ?></p>

            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url(admin_url('users.php?page=rbm-role-mailer&tab=compose')); ?>" class="nav-tab <?php echo $tab === 'compose' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Compose', 'rbm'); ?></a>
                <a href="<?php echo esc_url(admin_url('users.php?page=rbm-role-mailer&tab=templates')); ?>" class="nav-tab <?php echo $tab === 'templates' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Templates', 'rbm'); ?></a>
                <a href="<?php echo esc_url(admin_url('users.php?page=rbm-role-mailer&tab=history')); ?>" class="nav-tab <?php echo $tab === 'history' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('History', 'rbm'); ?></a>
            </h2>

            <?php $this->render_admin_notices(); ?>

            <?php if ($tab === 'templates') : ?>
                <?php $this->render_templates_tab($templates); ?>
            <?php elseif ($tab === 'history') : ?>
                <?php $this->render_history_tab(); ?>
            <?php else : ?>
                <?php $this->render_compose_tab($roles, $templates); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_admin_notices()
    {
        if (isset($_GET['rbm_notice']) && $_GET['rbm_notice'] === 'sent') {
            $count = isset($_GET['count']) ? (int) $_GET['count'] : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' .
                sprintf(esc_html__('Email campaign sent to %d recipients.', 'rbm'), $count) .
                '</p></div>';
        }

        if (isset($_GET['rbm_notice']) && $_GET['rbm_notice'] === 'template_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Template saved.', 'rbm') . '</p></div>';
        }

        if (isset($_GET['rbm_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['rbm_error']))) . '</p></div>';
        }
    }

    private function render_compose_tab($roles, $templates)
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('rbm_send_email_action', 'rbm_send_email_nonce'); ?>
            <input type="hidden" name="action" value="rbm_send_email" />

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="rbm_template_id"><?php esc_html_e('Load template', 'rbm'); ?></label></th>
                    <td>
                        <select id="rbm_template_id" name="template_id">
                            <option value=""><?php esc_html_e('— None —', 'rbm'); ?></option>
                            <?php foreach ($templates as $template) : ?>
                                <option value="<?php echo esc_attr($template->ID); ?>"><?php echo esc_html($template->post_title); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Select a template and update fields as needed.', 'rbm'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="rbm_target_roles"><?php esc_html_e('Target roles', 'rbm'); ?></label></th>
                    <td>
                        <select id="rbm_target_roles" name="target_roles[]" multiple size="6" required>
                            <?php foreach ($roles as $role_key => $role_data) : ?>
                                <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_data['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Use Ctrl/Cmd + Click to choose multiple roles.', 'rbm'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="rbm_subject"><?php esc_html_e('Subject', 'rbm'); ?></label></th>
                    <td><input type="text" class="regular-text" id="rbm_subject" name="subject" required /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Email body (HTML allowed)', 'rbm'); ?></th>
                    <td>
                        <?php
                        wp_editor('', 'rbm_body_editor', [
                            'textarea_name' => 'body',
                            'textarea_rows' => 12,
                            'media_buttons' => false,
                        ]);
                        ?>
                        <p class="description">
                            <?php esc_html_e('Available placeholders: {display_name}, {user_email}, {first_name}, {last_name}, {role_list}, {site_name}', 'rbm'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Send Email Campaign', 'rbm')); ?>
        </form>

        <script>
            (function() {
                const templateSelect = document.getElementById('rbm_template_id');
                if (!templateSelect) return;
                const templates = <?php echo wp_json_encode($this->template_payload($templates)); ?>;

                templateSelect.addEventListener('change', function() {
                    const selected = templates[this.value];
                    if (!selected) return;
                    const subjectInput = document.getElementById('rbm_subject');
                    if (subjectInput) subjectInput.value = selected.subject || '';
                    if (window.tinyMCE && tinyMCE.get('rbm_body_editor')) {
                        tinyMCE.get('rbm_body_editor').setContent(selected.body || '');
                    } else {
                        const textarea = document.getElementById('rbm_body_editor');
                        if (textarea) textarea.value = selected.body || '';
                    }
                });
            })();
        </script>
        <?php
    }

    private function render_templates_tab($templates)
    {
        ?>
        <h2><?php esc_html_e('Save a Template', 'rbm'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('users.php?page=rbm-role-mailer&tab=templates')); ?>">
            <?php wp_nonce_field('rbm_save_template_action', 'rbm_save_template_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="rbm_template_name"><?php esc_html_e('Template name', 'rbm'); ?></label></th>
                    <td><input type="text" id="rbm_template_name" name="template_name" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="rbm_template_subject"><?php esc_html_e('Subject', 'rbm'); ?></label></th>
                    <td><input type="text" id="rbm_template_subject" name="template_subject" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Template body', 'rbm'); ?></th>
                    <td>
                        <?php
                        wp_editor('', 'rbm_template_body_editor', [
                            'textarea_name' => 'template_body',
                            'textarea_rows' => 12,
                            'media_buttons' => false,
                        ]);
                        ?>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Template', 'rbm')); ?>
        </form>

        <h2><?php esc_html_e('Existing Templates', 'rbm'); ?></h2>
        <?php if (empty($templates)) : ?>
            <p><?php esc_html_e('No templates yet.', 'rbm'); ?></p>
        <?php else : ?>
            <ul>
                <?php foreach ($templates as $template) : ?>
                    <li><strong><?php echo esc_html($template->post_title); ?></strong></li>
                <?php endforeach; ?>
            </ul>
        <?php endif;
    }

    private function render_history_tab()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;
        $rows = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY sent_at DESC LIMIT 25"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        echo '<h2>' . esc_html__('Recent Campaigns', 'rbm') . '</h2>';

        if (empty($rows)) {
            echo '<p>' . esc_html__('No campaigns have been sent yet.', 'rbm') . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Sent At', 'rbm') . '</th>';
        echo '<th>' . esc_html__('Roles', 'rbm') . '</th>';
        echo '<th>' . esc_html__('Recipients', 'rbm') . '</th>';
        echo '<th>' . esc_html__('Subject', 'rbm') . '</th>';
        echo '<th>' . esc_html__('Status', 'rbm') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row->sent_at) . '</td>';
            echo '<td>' . esc_html($row->role_targets) . '</td>';
            echo '<td>' . esc_html((string) $row->recipient_count) . '</td>';
            echo '<td>' . esc_html(wp_trim_words($row->subject, 12)) . '</td>';
            echo '<td>' . esc_html($row->status) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function handle_template_save()
    {
        if (!isset($_POST['rbm_save_template_nonce'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        check_admin_referer('rbm_save_template_action', 'rbm_save_template_nonce');

        $name = isset($_POST['template_name']) ? sanitize_text_field(wp_unslash($_POST['template_name'])) : '';
        $subject = isset($_POST['template_subject']) ? sanitize_text_field(wp_unslash($_POST['template_subject'])) : '';
        $body = isset($_POST['template_body']) ? wp_kses_post(wp_unslash($_POST['template_body'])) : '';

        if ($name === '' || $subject === '' || $body === '') {
            wp_safe_redirect(admin_url('users.php?page=rbm-role-mailer&tab=templates&rbm_error=' . rawurlencode(__('Template fields are required.', 'rbm'))));
            exit;
        }

        $template_id = wp_insert_post([
            'post_type' => self::TEMPLATE_CPT,
            'post_status' => 'publish',
            'post_title' => $name,
            'post_content' => $body,
        ]);

        if (!is_wp_error($template_id)) {
            update_post_meta($template_id, '_rbm_template_subject', $subject);
            wp_safe_redirect(admin_url('users.php?page=rbm-role-mailer&tab=templates&rbm_notice=template_saved'));
            exit;
        }

        wp_safe_redirect(admin_url('users.php?page=rbm-role-mailer&tab=templates&rbm_error=' . rawurlencode(__('Could not save template.', 'rbm'))));
        exit;
    }

    public function handle_send_email()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized request.', 'rbm'));
        }

        check_admin_referer('rbm_send_email_action', 'rbm_send_email_nonce');

        $roles = isset($_POST['target_roles']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['target_roles'])) : [];
        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $body = isset($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : '';
        $template_id = isset($_POST['template_id']) ? absint($_POST['template_id']) : 0;

        if (empty($roles) || $subject === '' || $body === '') {
            wp_safe_redirect(admin_url('users.php?page=rbm-role-mailer&tab=compose&rbm_error=' . rawurlencode(__('Roles, subject, and body are required.', 'rbm'))));
            exit;
        }

        $users = get_users([
            'role__in' => $roles,
        ]);

        add_filter('wp_mail_content_type', [$this, 'set_html_mail_content_type']);

        $sent_count = 0;
        foreach ($users as $user) {
            $personalized_subject = $this->replace_placeholders($subject, $user);
            $personalized_body = $this->replace_placeholders($body, $user);
            $result = wp_mail($user->user_email, $personalized_subject, wpautop($personalized_body));

            if ($result) {
                $sent_count++;
            }
        }

        remove_filter('wp_mail_content_type', [$this, 'set_html_mail_content_type']);

        $this->log_campaign($roles, $sent_count, $subject, $template_id, $sent_count === count($users) ? 'success' : 'partial');

        wp_safe_redirect(admin_url('users.php?page=rbm-role-mailer&tab=compose&rbm_notice=sent&count=' . $sent_count));
        exit;
    }

    public function set_html_mail_content_type()
    {
        return 'text/html';
    }

    private function replace_placeholders($content, $user)
    {
        $first_name = get_user_meta($user->ID, 'first_name', true);
        $last_name = get_user_meta($user->ID, 'last_name', true);

        $map = [
            '{display_name}' => $user->display_name,
            '{user_email}' => $user->user_email,
            '{first_name}' => $first_name ?: $user->display_name,
            '{last_name}' => $last_name ?: '',
            '{role_list}' => $this->get_user_role_list($user),
            '{site_name}' => get_bloginfo('name'),
        ];

        return strtr($content, $map);
    }

    private function get_user_role_list($user)
    {
        if (empty($user->roles) || !is_array($user->roles)) {
            return '';
        }

        $all_roles = wp_roles()->roles;
        $role_names = [];

        foreach ($user->roles as $role_key) {
            $role_names[] = isset($all_roles[$role_key]['name']) ? $all_roles[$role_key]['name'] : $role_key;
        }

        return implode(', ', $role_names);
    }

    private function log_campaign($roles, $count, $subject, $template_id, $status)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . self::LOG_TABLE_SUFFIX;

        $wpdb->insert(
            $table_name,
            [
                'sent_at' => current_time('mysql'),
                'role_targets' => implode(', ', $roles),
                'recipient_count' => $count,
                'subject' => $subject,
                'template_id' => $template_id > 0 ? $template_id : null,
                'sent_by' => get_current_user_id(),
                'status' => $status,
                'notes' => null,
            ],
            ['%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s']
        );
    }

    private function get_templates()
    {
        return get_posts([
            'post_type' => self::TEMPLATE_CPT,
            'numberposts' => 100,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
    }

    private function template_payload($templates)
    {
        $payload = [];

        foreach ($templates as $template) {
            $payload[$template->ID] = [
                'subject' => get_post_meta($template->ID, '_rbm_template_subject', true),
                'body' => $template->post_content,
            ];
        }

        return $payload;
    }
}

new RBM_Role_Based_Bulk_Mailer();
