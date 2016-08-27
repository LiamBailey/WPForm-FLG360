<?php

/*
  Plugin Name: WP Form to FLG
  Description: Creates a flg-form shortcode, and sends submissions as leads into FLG360
  Version: 1.0.3
  Author: Liam Bailey (Webby Scots Wordpress - WSWP)
  Author URI: http://webbyscots.com/
  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

$flgForm = new wswpFormFLG();

class wswpFormFLG {

    function __construct() {
        session_start();
        define("PLUGIN_PATH", plugin_dir_path(__FILE__));
        define("PLUGIN_TEXTDOMAIN", 'flg_form');
        define('WP_SUBSCRIPTIONS',true);
        add_shortcode('flg-form', array($this, 'flg_form'));
        add_action('wp_enqueue_scripts', array($this, 'add_scripts_styles'));
        add_action('wp_ajax_process_flg_form', array($this, 'process_form'));
        add_action('wp_ajax_nopriv_process_flg_form', array($this, 'process_form'));
        add_action('wp', array($this, 'process_form'));
        require_once('wswp-form-flg-settings.php');
        register_activation_hook(__FILE__, setup_form_flg_settings());
        add_action('admin_notices', array($this, 'key_needed'));
    }

    function key_needed() {
        global $form_flg_settings;
        if ($form_flg_settings['flg_key'] == '') {
            echo "<div class='error'><h4>You need to enter your FLG API Key - please navigate to <a href='" . add_query_arg("page", "form_flg_settings", admin_url("options-general.php")) . "'>FLG form settings page</a> to do so</h4></div>";
        }
    }

    function add_scripts_styles() {
        wp_enqueue_script('jquery');
        wp_enqueue_style('flg_form_css', plugins_url('flg_form.css', __FILE__));
        wp_enqueue_script('flg_form_js',plugins_url('flg_form.js', __FILE__),array('jquery'));
    }

    function flg_form($atts, $content = '') {
        static $instance = 0;
        $instance++;
        $this->instance = $instance;
        global $post;
        $this->id = $post->ID;
        $this->post_url = ($post->post_type == "post" || $post->post_type == "page") ? get_permalink($this->id) : get_post_permalink($this->id);
        $this->atts = shortcode_atts(array(
            'fields' => '',
            'api_url' => '',
            'site' => '',
            'leadgroup' => '',
            'source' => '',
            'medium' => '',
            'form_type' => 'subscribe',
            'legend' => 'Please fill out form',
            'submit_label' => 'Submit',
                ), $atts);
        extract($this->atts);
        if (is_user_logged_in()) {
            global $current_user;
            get_currentuserinfo();
        }
        $fields = (empty($fields)) ? $this->get_form_fields($form_type) : apply_filters('wswp_flgform_filter_default_fields',explode(",",trim($fields)));
        array_splice($fields, 2, 0, array("middle_name"));
        $_SESSION['flg_fields'] = serialize($fields);
        if ($form_type == "login")
            $fields['password']['confirm'] = false;
        $content = $this->build_form($fields, $form_type);
        return $content;
    }

    function process_form() {
        if (isset($_POST['flg_form'])) {
            global $post;
            $this->response = array();
            $post_url = ($post->post_type == "post" || $post->post_type == "page") ? get_permalink($_POST['flg_form_post_id']) : get_post_permalink($_POST['flg_form_post_id']);
            if (!wp_verify_nonce($_POST['flg_form_nonce'], 'flg_form_noncerator_' . $_POST['flg_form_post_id']))
                return;

            if ($_SERVER['HTTP_REFERER'] != $post_url)
                return;

            if (isset($_POST['flg_form']['user_middlename']) && $_POST['flg_form']['user_middlename'] != "") {
                $this->response['success'] = true;
                $_SESSION['flg_response'] = serialize($this->response);
                return;
            }

            $form_fields = !empty($_SESSION['flg_fields']) ? unserialize($_SESSION['flg_fields']) : $this->get_form_fields[$_POST['form_type']];
            unset($form_fields[array_search('middle_name',$form_fields)]);
            $main_fields = $this->get_fields();
            $this->response['flg_form_errors'] = array();
            $this->response['success'] = true;
            foreach ($form_fields as $field) {
                $field_info = $main_fields[$field];
                if ($field_info['required'] && $_POST['flg_form'][$field_info['name']] == '') {
                    $this->response['success'] = false;
                    $this->response['flg_form_errors']['required_error'] = "Please fill in all required fields";
                }
                if ($field == "email" && !$this->valid_email($_POST['flg_form']['user_email'])) {
                    $this->resonse['success'] = false;
                    $this->response['flg_form_errors']['email_eror'] = "Invalid email";
                }
                if ($field_info['confirm'] && $_POST['flg_form'][$field_info['name']] != $_POST['flg_form'][$field_info['name']."_confirm"]) {
                    $this->response['success'] = false;
                    $this->response['flg_form_errors']['confirm_error'] = $field_info['name'] . " entry " . $_POST['flg_form'][$field_info['name']] . " and confirmation " . $_POST['flg_form'][$field_info['name']."_confirm"] . " don't match";
                }
                $_SESSION['flg_form'][$field_info['name']] = $_POST['flg_form'][$field_info['name']];
            }
            if ($this->response['success'] == true) {
                $this->response['success'] = (intval($this->process_data()) > 0);
            }
            if (defined('DOING_AJAX') && DOING_AJAX) {
                echo json_encode($this->response);
            } else {
                $_SESSION['flg_response'] = serialize($this->response);
            }
        }
    }

    protected function process_data() {
        $wp_user_array = array();
        foreach ($_POST['flg_form'] as $key => $info_field) {
            if (strstr($key, "user_")) {
                $user_array[$key] = $info_field;
            } else if (strstr($key, "meta_")) {
                $meta_array[$key] = $info_field;
            }
            else if (strstr($key,"email_")) {
                $email_array[$key] = $info_field;
            }
        }
        $got_user = false;
        if ($_POST['form_type'] == "subscribe" || ($_POST['flg_formsubscribe'] == "Yes" && WP_SUBSCRIPTIONS == true)) {
            if (is_user_logged_in()) {
                $got_user = true;
                $uid = get_current_user_id();
                $user = new WP_User($uid);
                $user->add_role('subscriber');
            } else {
                $username = $_POST['form_type'] == "subscribe" ? $_POST['flg_form']['user_login'] : $_POST['flg_form']['user_email'];
                $ulw = explode("@",$_POST['flg_form']['user_email']);
                if (strstr($username,"@")) {
                    $use_email = true;
                    $email = $username;
                    $user_login = $ulw[0];
                }
                else {
                    $user_login = $username;
                }
                if ($use_email && get_user_by('email',$email)) {
                    $this->response['flg_form_errors']['not_subscribed'] = "There is already a member with that email";
                    $db_string = "User is already a member with that email";
                }
                else {
                    if (intval(username_exists($user_login)) > 0) {
                        $original = $user_login;
                        $user_login = $ulw[0] . "_" . $ulw[1];
                        $this->response['flg_form_errors']['username_changed'] = "We had to use $user_login as your username";
                    }
                    if (intval(username_exists($user_login)) > 0) {
                        $got_user = true;
                        $uid = $user_login;
                    }
                    else {
                        $user_array['user_login'] = $user_login;
                        $uid = wp_insert_user($user_array);
                        wp_new_user_notification($uid, $_POST['user_pass']);
                    }
                }
            }
            if (!is_wp_error($uid)) {
                $got_user = true;
                foreach ($meta_array as $key => $value) {
                    if (!update_user_meta($uid, $key, $value, get_user_meta($uid, $key, $value))) {
                        add_user_meta($uid, $key, $value, true);
                    }
                }
                $db_string .= "User was successfully added to subscriber list";
            } else {
                $db_string .= "Insert subscriber to DB failed - reason: " . $uid->get_error_message();
            }
        }
        $FLGResponse = $this->doFLG($user_array, $meta_array);

        if ($got_user && isset($FLGResponse['flgNo']))
            add_user_meta($uid,'FLGNo',$FLGResponse['flgNo'],true);

            $data_array = array_merge($user_array, $meta_array);

            $email_array = (!empty($email_array)) ? array_merge($data_array,$email_array) : $data_array;

        $response = $this->build_send_email($email_array, $db_string, $FLGResponse);
        return $response;
    }

    protected function doFLG($user_array, $meta_array) {
        global $form_flg_settings;
        $key = $form_flg_settings['flg_key'];
        $url = $_POST['flg_form_api_url'];
        $lead = array();
        $lead['key'] = $key;
        $lead['leadgroup'] = $_POST['flg_form_leadgroup'];
        $lead['site'] = $_POST['flg_form_site'];
        //$lead['type'] = 'Brochure Download';
        $lead['source'] = $_POST['flg_form_source'];
        $lead['medium'] = $_POST['flg_form_medium'];
        $lead['title'] = $meta_array['meta_title'];
        $lead['firstname'] = $user_array['user_firstname'];
        $lead['lastname'] = $user_array['user_lastname'];
        $lead['email'] = $user_array['user_email'];
        $lead['phone1'] = $meta_array['meta_telephone'];
        $lead['data12'] = "Intends to Buy: " . $meta_array['meta_when_buy'];


        $dom = new DOMDocument('1.0', 'iso-8859-1');
        $root = $dom->createElement('data');
        $dom->appendChild($root);
        $wrap = $dom->createElement('lead');
        foreach ($lead as $key => $data) {
            $element = $dom->createElement($key);
            $value = $dom->createTextNode($data);
            $element->appendChild($value);
            $wrap->appendChild($element);
        }
        $root->appendChild($wrap);
        $send_xml = $dom->saveXML();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $send_xml);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
        $result = curl_exec($ch);
        $output = array();
        $output['success'] = true;
        if (curl_errno($ch)) {
            $output['success'] = false;
            $output['message'] = 'ERROR from curl_errno -> ' . curl_errno($ch) . ': ' . curl_error($ch);
        } else {
            $returnCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            switch ($returnCode) {
                case 200:
                    $dom->loadXML($result);
                    if ($dom->getElementsByTagName('status')->item(0)->textContent == "0") {
                        //good request
                        $output['message'] = "<p> Response Status: Passed - Message: " . $dom->getElementsByTagName('message')->item(0)->textContent;
                        $output['message'] .= "<p> FLG NUMBER: " . $dom->getElementsByTagName('id')->item(0)->textContent;
                        $output['flgNo'] = $dom->getElementsByTagName('id')->item(0)->textContent;
                        return $output;
                    } else {
                        $output['success'] = false;
                        $output['message'] = "<p> API Connection: Success - Lead Entry: Failed - Reason: " . $dom->getElementsByTagName('message')->item(0)->textContent;
                    }
                    break;
                default:
                    $output['success'] = false;
                    $output['message'] = '<p>HTTP ERROR -> ' . $returnCode;
                    break;
            }
        }
        curl_close($ch);

        return $output;
    }

    protected function build_send_email($email_array, $db_string, $FLGResponse) {
        $email = "<h2>Flg " . $_POST['form_type'] . " Form Submitted</h2>";
        $subject = $email;
        foreach ($email_array as $key => $info) {
            $label_parts = explode("_", $key);
            $label = $label_parts[1];
            if (!empty($info))
                $email .= "<p><b>{$label}: </b>{$info}</p>";
        }
        if (isset($_POST['flg_form']['description']) && !empty($_POST['flg_form']['description']))
            $email .= "<p><b>Additional info: </b>" . $_POST['flg_form']['description'] . "</p>";

        $email .= $FLGResponse['message'];
        $email .= " : " . $db_string;
        return $this->send_mail($subject, $email);
    }

    protected function send_mail($subject, $message) {
        require_once PLUGIN_PATH . "swift/lib/swift_required.php";
        $plain_message = strip_tags(str_replace("<p>", "\r\n", $message));
        // Create the message
        $message = Swift_Message::newInstance()

                // Give the message a subject
                ->setSubject($subject)

                // Set the From address with an associative array
                ->setFrom(get_option('admin_email'))

                // Set the To addresses with an associative array
                ->setTo(array(get_option('admin_email')))

                // Give it a body
                ->setBody($plain_message)

                // And optionally an alternative body
                ->addPart($message, 'text/html');
        $transport = Swift_MailTransport::newInstance();


        // Create the Mailer using your created Transport
        $mailer = Swift_Mailer::newInstance($transport);
        $mail_output = $mailer->send($message);
        return $mail_output;
    }

    protected function valid_email($email) {
        return(filter_var(filter_var($email, FILTER_SANITIZE_EMAIL), FILTER_VALIDATE_EMAIL)) ? true : false;
    }

    protected function build_form($fields = array(), $form_type) {
        $form_fields = $this->get_fields();
        $content = "<a name='flg-form'></a>";
        $content .= "<div id='flg_form_message'>" . $this->display_messages() . "</div>";
        $content .= "<form class='flg-form form-type-{$form_type}' action='{$this->post_url}#flg-form' method='POST' id='flg_form-{$this->instance}'>";
        $content .= "<fieldset>" . "\r\n" . "<legend>" . $this->atts['legend'] . "</legend>";
        $content .= "\r\n" . "<input type='hidden' name='form_type' value='" . $this->atts['form_type'] . "' />";
        $content .= "\r\n" . wp_nonce_field("flg_form_noncerator_" . $this->id, 'flg_form_nonce', false, false);
        $content .= "\r\n" . "<input type='hidden' name='flg_form_post_id' value='{$this->id}' />";
        foreach (array('api_url', 'site', 'leadgroup', 'source', 'medium') as $field) {
            $content .= "\r\n" . "<input type='hidden' name='flg_form_{$field}' value='{$this->atts[$field]}' />";
        }
        foreach ($fields as $field_key) {
            $content .= "\r\n" . $this->build_field($form_fields[$field_key]);
        }
        $content .= "\r\n" . "</fieldset>";
        $content .= "\r\n" . "</form>";
        return $content;
    }

    protected function display_messages() {
        if (!isset($_SESSION['flg_response']))
            return;

        $response = unserialize($_SESSION['flg_response']);
        if ($response['success']) {
            echo "<p class='flg-success'>The form was submitted successfully, thank you</p>";
        } else {
            $error_mess = array();

            foreach ($response['flg_form_errors'] as $error) {
                $error_mess[] = "<li>$error</li>";
            }
            printf("<ul class='flg-error'>%s</ul>", implode("", $error_mess));
        }
        unset($_SESSION['flg_response']);
    }

    protected function build_field($field) {
        extract($field);
        global $current_user;
        if ($type != "password") {
            if (isset($field_val)) {
                $current_val = $field_val;
            }
            else if (isset($_SESSION['flg_form'][$name])) {
                $current_val = $_SESSION['flg_form'][$name];
            } else if (isset($current_user) && get_class($current_user) == "WP_User" && isset($current_user->$name)) {
                $current_val = $current_user->$name;
            } else if (get_user_meta(get_current_user_id(), $name, true)) {
                $current_val = get_user_meta(get_current_user_id(), $name, true);
            }
        }
        $required_class = ($required === true) ? "required" : "";
        $output = ($type != "hidden") ? "<p class='field_container {$type}-container {$required_class}'>" . "\r\n" .
                "<label for='{$name}'>{$label}</label>" . "\r\n" : "";
        switch ($type) {
            case "text" :
            case "password" :
            case "email" :
            case "hidden":
                $output .= "<input type='{$type}' name='flg_form[{$name}]' id='{$name}' class='{$class}' value='{$current_val}' />";
                if ($confirm == true) {
                    $output.= "<label for='{$name}-confirm'>Confirm {$label}</label>";
                    $output .= "<input type='{$type}' name='flg_form[{$name}_confirm]' id='{$name}-confirm' class='{$class}' value='{$current_val}' />";
                }
                break;
            case "submit":
                $output .= "<input type='{$type}' name='{$name}' id='{$name}' class='{$class}' value='{$this->atts['submit_label']}' />";
                break;
            case "select":
                $output .= "<select name='flg_form[{$name}]' id='{$name}' class='{$class}'>" . "\r\n";
                if ($please_select)
                    $output .= "<option value=''>Please Select</option>";
                foreach ($options as $value => $optlabel) {
                    $selected = ($current_val == $value) ? "selected" : "";
                    $output .= "<option value='{$value}' {$selected}>{$optlabel}</option>";
                }
                $output .= "</select>";
                break;
            case "textarea":
                $output .= "<textarea name='flg_form[{$name}]' class='{$class}' id='{$name}' rows='{$rows}' cols='{$cols}'>{$current_val}</textarea>";
                break;
            case "subscribe":
                $checked = $_SESSION['flg_form'][$name] == "Yes" ? "checked" : "";
                $output .= "<input $checked type='checkbox' name='flg_form{$name}' class='${class}' id='{$name}' value='Yes' />";
                break;
        }
        return $output;
    }

    function display_errors() {

    }

    function get_form_fields($form_type) {
        $default_fields = array('title', 'first_name', 'last_name', 'email', 'telephone', 'submit');
        switch ($form_type) {
            case "subscribe":
                array_splice($default_fields, 3, 0, array("username", "password"));
                break;
            case "contact":
                array_splice($default_fields, 3, 0, array("company"));
                array_splice($default_fields, count($default_fields) - 1, 0, array('subscribe'));
                break;
        }
        return apply_filters('wswp_flgform_filter_default_fields', $default_fields);
    }

    protected function get_fields() {
        $fields = array(
            'title' => array(
                'name' => 'meta_title',
                'label' => __('Title', PLUGIN_TEXTDOMAIN),
                'required' => true,
                'position' => 10,
                'type' => 'select',
                'options' => array('mr' => 'Mr', 'mrs' => 'Mrs', 'miss' => 'Miss','dr' => 'Dr'),
                'please_select' => false
            ),
            'first_name' => array(
                'name' => 'user_firstname',
                'label' => __('Name', PLUGIN_TEXTDOMAIN),
                'type' => 'text',
                'required' => true,
                'position' => 20
            ),
            'middle_name' => array(
                'name' => 'user_middlename',
                'label' => __('Middle Name',PLUGIN_TEXTDOMAIN),
                'type' => 'text',
                'required' => true,
                'position' => 30
                //This is a field hidden by js as a quiet spam blocker
            ),
            'last_name' => array(
                'name' => 'user_lastname',
                'label' => __('Surname', PLUGIN_TEXTDOMAIN),
                'required' => true,
                'type' => 'text',
                'position' => 40
            ),
            'username' => array(
                'name' => 'user_login',
                'label' => __('Username', PLUGIN_TEXTDOMAIN),
                'required' => true,
                'type' => 'text',
                'position' => 50
            ),
            'password' => array(
                'name' => 'user_pass',
                'label' => __('Password', PLUGIN_TEXTDOMAIN),
                'required' => true,
                'confirm' => true,
                'type' => 'password',
                'position' => 60
            ),
            'company' => array(
                'name' => 'meta_company',
                'label' => __('Company', PLUGIN_TEXTDOMAIN),
                'type' => 'text',
                'required' => false,
                'position' => 70,
            ),
            'email' => array(
                'name' => 'user_email',
                'label' => __('Email', PLUGIN_TEXTDOMAIN),
                'required' => true,
                'type' => 'email',
                'position' => 80,
            ),
            'telephone' => array(
                'name' => 'meta_telephone',
                'label' => __('Telephone', PLUGIN_TEXTDOMAIN),
                'type' => 'text',
                'required' => false,
                'position' => 90
            ),
            'additional_info' => array(
                'label' => __('Additional Info', PLUGIN_TEXTDOMAIN),
                'name' => 'description',
                'type' => 'textarea',
                'required' => false,
                'position' => 100,
                'cols' => 10,
                'rows' => 5
            ),
            'captcha' => array(
                'label' => __('Anti-Spam', PLUGIN_TEXTDOMAIN),
                'name' => 'captcha',
                'required' => true,
                'type' => 'captcha',
                'position' => 110
            ),
            'subscribe' => array(
                'label' => __('Sign up for ' . get_bloginfo('name') . ' alerts', PLUGIN_TEXTDOMAIN),
                'position' => 120,
                'type' => 'subscribe',
                'name' => 'subscribe'
            ),
            'submit' => array(
                'label' => '',
                'name' => 'flg-submit',
                'type' => 'submit',
                'position' => 130,
            )
        );
        $fields = apply_filters('wswp_flgform_filter_fields', $fields);
        return wswp_sort_array_by_position($fields);
    }

}

function wswp_sort_array_by_position($array = array(), $order = SORT_NUMERIC) {

    if (!is_array($array))
        return;

    if (empty($array))
        return;
    // Sort array by position

    $position = array();

    foreach ($array as $key => $row) {
        $position[$key] = $row['position'];
    }

    array_multisort($position, $order, $array);

    return $array;
}

?>
