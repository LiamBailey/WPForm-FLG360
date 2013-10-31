<?php
/*
 * wswp-form-flg-settings.php
 * 
 * Copyright 2012 Liam Bailey Webby Scots
 * 
 * Hook into Settings API and set up fields for setting publish url and webmaster tools etc
 * 
 * 
 */
global $form_flg_settings;
$form_flg_settings = (get_option('form-flg-settings')) ? get_option('form-flg-settings') : form_flg_get_settings();
//print_r($options);

//Add theme options menu under Settings heading
add_action('admin_menu','addmenu_form_flg_settings');
function addmenu_form_flg_settings() {
    add_options_page('FLG Form','FLG Form Settings','manage_options','form_flg_settings','renderpage_pluginopts');
}

//Set default options
function form_flg_get_settings() {
	$settings = array(
                    'flg_key' => ''
        );
	return $settings;
}

//add_action('admin_init','setup_essential_settings');

function setup_form_flg_settings() {
	//Normally this would be added as an activation hook on a plugin, in which case we wouldn't need is_admin() check
	add_option('form-flg-settings',form_flg_get_settings());
}
//end initialising defaults

/*Add settings sections and fields to new option group essential-seo-settings*/
add_action('admin_init','register_form_flg_settings');

function register_form_flg_settings() {
	register_setting('form-flg-settings','form-flg-settings','validate_settings');
	add_settings_section('section-one', 'FLG Setup', 'addsection_settings','form_flg_settings');
	//FLG Key
	add_settings_field('settingsfield_flg_key','FLG API Key','addsettingsfield_flg_key','form_flg_settings','section-one');
	
}

/*Codes to display settings fields */

function addsection_settings()
{
	?><p>Please setup FLG Form plugin by entering information in the fields below.</p><?php
}

function addsettingsfield_flg_key() {
	global $form_flg_settings;
	
	echo "<input type='text' id='settingsfield_flg_key' name='form-flg-settings[flg_key]' value='" . $form_flg_settings['flg_key'] . "' />";
}


function validate_settings($input) {
    session_start();
	
        return apply_filters('validate_settings',$input);
}

/*Function to render options page */
function renderpage_pluginopts() {
?>
	<div class="wrap">
		<div class="icon32" id="icon-themes"></div>
		<h2>FLG Form Settings</h2>
		<form action="options.php" method="post">
		<?php settings_fields('form-flg-settings'); ?>
		<?php do_settings_sections('form_flg_settings'); ?>
		<p class="submit">
			<input name="form-flg-settings[submit]" type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes'); ?>" />
		</p>
		</form>
	</div>
<?php
}