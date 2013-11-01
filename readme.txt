=== WPForm FLG360 ===
Contributors: Webby Scots
Tags: forms, flg360
Requires at least: 3.0
Tested up to: 3.6
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allows the user to create forms simply using a shortcode [flg-form], with all the common fields pre-built and ready for use

== Description ==

The default field set is:

'title', 'first_name', 'last_name', 'email', 'telephone', 'submit'

So if you simply use the shortcode with no attributes it will create a form with those fields, and with title, first_name
last_name and email as required fields, and with html5 enabled and the html5shiv enqued also.

If you set form_type to 'subscribe' it adds a field for username and password, with password automatically having a confirm field as well, which is of course part of the in-built form validation. The user is added to WP as a subscriber and notifications are sent also.

If you set 'contact' as the form_type attribute, then instead of username and password the plugin adds a text field for company, and a subscribe to newsletter checkbox. If they hit subscribe then they are added to Wp as a subscriber, with the first part of their email as username (numbers are appended if the name exists, and continue to be changed until unique name is found).

The function that does all that field adding ends with a filter, as does the function that configures all the field settings such as type/required etc for easy addition of custom field types.

More from [Webby Scots](http://webbyscots.com/)

== Installation ==

1. Extract the zip and add the entire plugin directory to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress
1. Then simply use the shortcode with the following parameters:
            'fields' => '' // build your own set of fields
            'api_url' => '',//the API url as flg360 uses username subdomains, may change this to FLG user and build url from that
            'site' => '', //the FLG360 site to add the lead under
            'leadgroup' => '' //the FLG360 leadgroup to add the lead under,
            'source' => '', //FLG360 source
            'medium' => '', //FLG360 source
            'form_type' => '', // as laid out above.
            'legend' => 'Please fill out form',
            'submit_label' => 'Submit'

== Frequently Asked Questions ==

= Can I change the properties of the fields =

Yes, there is a filter for the fields, both when they are set and when they are setup (required, type etc)

== Screenshots ==

== Changelog ==

= 1.0.0 =
First release of plugin

