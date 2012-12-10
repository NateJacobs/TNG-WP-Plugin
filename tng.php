<?php
/*
Plugin Name: TNG Wordpress Integration
Plugin URI: http://www.4-14.org.uk/wordpress-plugins/tng
Description: Integrates TNG (The Next Generation of Genealogy) with Wordpress. TNG v8 & v9 compatibility added by Darrin Lythgoe.
Author: Mark Barnes
Updated by: Darrin Lythgoe, 9/20/2011
Version: 2.0
Author URI: http://www.4-14.org.uk/
Copyright (c) 2008 Mark Barnes
This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.


CONTENTS
========
* Add actions and initialise
* Admin functions
* Log-in/register functions
* User functions
* Output the TNG page
* Determine which page to display
* Widget functions
* 'Helper' functions

/************************************************
*                                               *
*           ADD ACTIONS AND INITIALISE          *
*                                               *
* mbtng_serve_static_files                      *
* mbtng_initialise                              *
************************************************/

add_action('plugins_loaded', 'mbtng_serve_static_files');				// Serves static files (.jpg, .css, etc.) as soon as possible

// Serves static files, if requested. Runs initialisation if not.
function mbtng_serve_static_files () {
	session_start();
	if (isset($_REQUEST['update_globalvars'])) {
		mbtng_rewrite_globalvars();
		header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=tng-wordpress-plugin/tng.php&updated=globalvars');
		die();
	}
	if (isset($_REQUEST['tng_search'])) {
		mbtng_search_for_tng (dirname(__FILE__));
	}
	if (mbtng_display_page()) {
		$tng_folder = get_option('mbtng_path');
		$filename = mbtng_filename();
		if (mbtng_extension() != 'php') {
			chdir ($tng_folder);
			$code = @file_get_contents ($filename);
            if ($code == '') {
                if (php_sapi_name()=='CGI') {
                    Header("Status: 404 Not Found");
                } else {
                    Header("HTTP/1.0 404 Not Found");
                }
                die();
            }
			header("Content-Length: ".filesize($filename));
			if (mbtng_extension() == 'css') {
				header("Content-type: text/css");
				$lines = explode("\n",$code);
				$newcode = '';
				foreach ($lines as $line) {
// Roger changes here (first line is looking of LB_ not .LB_)
					if (strpos($line, '{' ) !== FALSE && isset($_REQUEST['frontend']) && stripos($line, 'LB_') === FALSE)
// next lines control if the css is rewritten or not
//						$newcode .= '#tng_main '.str_ireplace('body {', '{', $line)."\n"; // rewrites CSS
						$newcode .= ''.str_ireplace('body {', '{', $line)."\n"; // does NOT rewrite CSS
					else
						$newcode .= $line."\n";
				}
				$code = $newcode;
			} else
				header("Content-type: ". mime_content_type($filename));
			$lastModifiedDate = filemtime($filename);
            header ("Content-Length: ".strlen($code));
			if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $lastModifiedDate) {
				if (php_sapi_name()=='CGI') {
					Header("Status: 304 Not Modified");
				} else {
					Header("HTTP/1.0 304 Not Modified");
				}
			} else {
				$gmtDate = gmdate("D, d M Y H:i:s\G\M\T",$lastModifiedDate);
				header('Last-Modified: '.$gmtDate);
				header('HTTP/1.1 200 OK');
			}
			echo $code;
			die();
		}
	}
	add_action('init', 'mbtng_initialise');
	add_action('widgets_init', 'mbtng_widget_init');						// Initialise the widgets
	add_action('admin_menu', 'mbtng_add_admin_page');						// Adds TNG menu to Wordpress admin
	add_action('admin_notices', 'mbtng_options_reminder');					// Adds reminder to set TNG page
	add_action('generate_rewrite_rules', 'mbtng_update_tng_url');			// Updates the URL of the TNG page in wp_options
}


// Add additional actions only if required
function mbtng_initialise () {
	global $wp_query;
	if (get_option('mbtng_url') == '' && get_option('mbtng_wordpress_page') != '')
		mbtng_update_tng_url(); // Remove these two lines when out of beta
	if (get_option('mbtng_redirect_login'))
		add_filter('login_redirect','mbtng_redirect_login', 10, 3);     // Redirects user back to referrer page, not dashboard
  	if (get_option('mbtng_integrate_logins')) {							// Adds integration actions only if needed
		add_action('login_head', 'mbtng_login_head');					// Adds styles to login header
		add_action('register_form', 'mbtng_register_form');				// Adds additional fields to Wordpress registration form
		add_action('register_post', 'mbtng_check_fields', 10, 3);		// Checks required fields are completed during registration
		add_action('user_register', 'mbtng_register_user', 10, 1);		// Adds a new user to the TNG database when a user is created in Wordpress
		add_action('delete_user', 'mbtng_delete_user', 10, 1);			// Deletes TNG users when deleted from Wordpress
		add_action('wp_authenticate', 'mbtng_intercept_login', 10, 1);	// Checks if a TNG user exists if Wordpress login unsuccessful
	}
	if (mbtng_display_page()) {
 		add_filter('the_posts','mbtng_fake_post');						// Return the Wordpress TNG page if any TNG page is requested
		add_action('template_redirect', 'mbtng_buffer_start');			// Intercept front-end to buffer output
		add_action('wp_head', 'mbtng_frontend_header');					// Adds TNG template <head> to WordPress <head>
		add_action('loop_start', 'mbtng_output_page');					// Outputs the TNG page when required
		add_action('loop_end', 'mbtng_discard_output');					// Discards post contents if TNG is displayed
		add_action('wp_footer', 'mbtng_frontend_footer');				// Adds TNG template footer to Wordpress footer
		add_action('shutdown', 'mbtng_buffer_end');						// Flushes output buffer
		if (get_option('mbtng_integrate_logins'))
 			if (is_user_logged_in()) 
				mbtng_login();											// Ensures user is logged into TNG
			else
				mbtng_logout();											// Ensures user is logged out of TNG
	}
}

/************************************************
*                                               *
*               ADMIN FUNCTIONS                 *
*                                               *
* mbtng_add_admin_page                          *
* mbtng_options_reminder                        *
* mbtng_search_for_tng                          *
* mbtng_rewrite_globalvars                      *
* mbtng_options                                 *
* mbtng_display_tng_admin                       *
************************************************/

//Adds the TNG menu to Wordpress admin
//Icon from FamFamFam: http://www.famfamfam.com/lab/icons/silk/
function mbtng_add_admin_page () {
	add_menu_page('TNG', 'TNG', 'manage_options', __FILE__, 'mbtng_options', plugins_url('tng-wordpress-plugin/icon.png'));
	add_submenu_page (__FILE__, 'Options', 'Options', 'manage_options', __FILE__, 'mbtng_options');
// Roger comment out the next line to remove Admin from WordPress Admin sidebar
//	add_submenu_page (__FILE__, 'Admin', 'Admin', 'manage_options', 'tng-wordpress-plugin/admin.php', 'mbtng_display_tng_admin');
}

// Adds reminder to set TNG page in options
function mbtng_options_reminder() {
	if (isset($_REQUEST['updated']) && $_REQUEST['updated'] == 'true') {
		update_option('mbtng_path', mbtng_folder_trailingslashit(get_option('mbtng_path')));
		mbtng_update_tng_url();
		mbtng_rewrite_globalvars();
	}
	if (get_option('mbtng_wordpress_page')=='')
		echo '<div id="message" class="updated"><p><b>In order to make TNG visible, you must go to <a href="'.get_bloginfo('wpurl').'/wp-admin/admin.php?page=tng-wordpress-plugin/tng.php">TNG options</a>, and specify a page where TNG will be displayed on your site.</b></div>';
	if (!mbtng_correct_path())
		echo '<div id="message" class="error"><p><b>Warning:</b> TNG cannot be found in '.get_option('mbtng_path').' - please <a href="'.get_bloginfo('wpurl').'/wp-admin/admin.php?page=tng-wordpress-plugin/tng.php">specify the full (absolute) path</a> to your TNG installation, or <a href="'.get_bloginfo('wpurl').'?tng_search">automatically search for the correct folder</a>.</p></div>';
}

//Searches server for TNG files
function mbtng_search_for_tng ($path = '', $go_back = true) {
	global $countit;
	if ($path == '..')
		$oldpath = basename(getcwd());
	else
		$oldpath = '';
	if ($path != '')
		if (!@chdir($path)) {
			header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=tng-wordpress-plugin/tng.php&updated=notfound');
			die();
		}
	$path=getcwd();
	$dir = @opendir($path);
    if ($dir) {
		$element=readdir($dir);
        while ($element !== false) {
            if (is_dir(mbtng_folder_trailingslashit($path).$element) && $element != "." && $element != ".." && $element != $oldpath) {
                mbtng_search_for_tng(mbtng_folder_trailingslashit($path).$element, FALSE);
				chdir($path);
			}
            elseif ($element != "." && $element != "..") {
				if ($element == 'ahnentafel.php' && mbtng_correct_path($path)) {
					update_option('mbtng_path', mbtng_folder_trailingslashit($path));
					header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=tng-wordpress-plugin/tng.php&updated=search');
					die();
				}
			}
		$element=readdir($dir);
        }
        closedir($dir);
    }
	if ($go_back)
		mbtng_search_for_tng('..', TRUE);
    return;
}

//Searches TNG code for global variables ready to be passed to mbtng_eval_php
function mbtng_rewrite_globalvars() {

	function mbtng_trim (&$item, $key) {
		$item = trim ($item);
	}

	$folders[0] = mbtng_folder_trailingslashit(get_option('mbtng_path'));
	$folders[1] = mbtng_folder_trailingslashit($folders[0].'templates');
	$folders[2] = mbtng_folder_trailingslashit($folders[1].'template1');
	$folders[3] = mbtng_folder_trailingslashit($folders[1].'template2');
	$folders[4] = mbtng_folder_trailingslashit($folders[1].'template3');
	$folders[5] = mbtng_folder_trailingslashit($folders[1].'template4');
	$folders[6] = mbtng_folder_trailingslashit($folders[1].'template5');
	$folders[7] = mbtng_folder_trailingslashit($folders[1].'template6');
	$folders[8] = mbtng_folder_trailingslashit($folders[1].'template7');
	$folders[9] = mbtng_folder_trailingslashit($folders[1].'template8');
	$folders[10] = mbtng_folder_trailingslashit($folders[1].'template9');
	$folders[11] = mbtng_folder_trailingslashit($folders[1].'template10');
	$folders[12] = mbtng_folder_trailingslashit($folders[1].'template11');
	$bnn = $keep = array();
	foreach ($folders as $folder) {
		if ($dh = @opendir($folder)) {
			while (false !== ($file = readdir($dh))) {
		    	if (substr($file, -4) == ".php" && !is_dir($folder.$file) && !in_array($file, $bnn) && $file != "tng.php") {
		    		$lines=file($folder.$file);
					foreach ($lines as $line)
						if (stripos($line, 'global $') !== FALSE) {
							$start = stripos($line, 'global $')+7;
							$end = strpos($line, ';', $start);
							$these = explode (',', substr($line, $start, $end-$start));
							$keep = array_merge ($keep, $these);
						}
		       	}	
			}
		   	closedir($dh);
		}
	}
	array_walk ($keep, 'mbtng_trim');
	$keep = array_unique($keep);
	if (is_array($keep))
		update_option('mbtng_globalvars', '<?php GLOBAL '.implode (', ', $keep).'; ?>'); //Think about BASE64
}

// Displays options page
function mbtng_options () {
	global $wp_version;
	echo "<div class=\"wrap\">\n";
	echo "<h2>TNG / Wordpress integration</h2>\n";
	if (isset($_REQUEST['updated']) && $_REQUEST['updated'] == 'globalvars')
		echo '<div id="message" class="updated fade"><p>TNG variables updated.</p></div>';
	if (isset($_REQUEST['updated']) && $_REQUEST['updated'] == 'search')
		echo '<div id="message" class="updated fade"><p>TNG path found as '.get_option('mbtng_path').'.</p></div>';
	if (isset($_REQUEST['updated']) && $_REQUEST['updated'] == 'notfound')
		echo '<div id="message" class="error"><p>Could not find TNG path.</p></div>';
	if (isset($_REQUEST['updated']) && $_REQUEST['updated'] == 'true') {
		echo '<div id="message" class="updated fade"><p>TNG settings saved.</p></div>';
		update_option('mbtng_timestamp', strtotime('now'));
	}
	if (get_option('permalink_structure')=='')
		echo '<div id="message" class="error"><p>Sorry, you cannot use the default <a href="options-permalink.php">permalink structure</a> with this plugin</p></div>';
	if (!isset($_SERVER['REQUEST_URI']))
		echo '<div id="message" class="error"><p>Sorry, this site appears to be running on a Windows server, which is not currently compatible with the TNG plugin. Please <a href="http://www.4-14.org.uk/contact">contact the developer</a> if you would like to test a Windows version.</p></div>';
	$pages = get_pages('sort_column=menu_order');
	echo "<h3>TNG Options</h3>\n";
	echo "<form method=\"post\" action=\"options.php\">\n";
// Roger added a width to this table
	echo "\t<table width=\"800\">\n";
	echo "\t\t<tr>\n";
// Roger added a width to this table column
echo "\t\t\t<td width=\"200\" style=\"padding: 0.5em 0\">Show TNG on:</td>\n";
	echo "\t\t\t<td style=\"padding: 0.5em 0\"><select name=\"mbtng_wordpress_page\">";
	foreach ($pages as $page) {
		if (get_bloginfo('wpurl') != get_permalink($page->ID)) { // Don't allow homepage to be selected
			if ($page->ID == get_option('mbtng_wordpress_page'))
				$selected = ' selected="selected"';
			else
				$selected='';
			echo "<option value=\"{$page->ID}\"{$selected}>{$page->post_title}</option>";
		}
	}
	echo "</select></td>\n";
	echo "\t\t</tr>\n";
	echo "\t\t<tr>\n";
	echo "\t\t\t<td  style=\"padding: 0.5em 0\"valign=\"top\">Path to TNG files:&nbsp;</td>\n";
// Roger changes here to alter message about paths to wp and tng folders
	echo "\t\t\t<td style=\"padding: 0.5em 0\"><input type=\"text\" name=\"mbtng_path\" value=\"".get_option('mbtng_path')."\" size=\"50\" \><br/>This folder should be publicly accessible in order for TNG Admin to work best.<br />One suggested configuration is to have WordPress in one folder in your site's root folder, and TNG in another folder in your site's root folder. So you might have:<br /><strong>/public_html/wp/<br />/public_html/tng/</strong><!--<br /><br />It is best if this folder is <b>publically inaccessible</b>. On many webservers, this means outside the public_html folder. You should set \$rootpath to the same value in your TNG config.php or customconfig.php file. If you do this, you <strong>must</strong> use the TNG Admin inside this WordPress Frame.--></td>\n";
	echo "\t\t</tr>\n";
//Roger added next table row to add field for URL to Admin
	echo "\t\t<tr>\n";
	echo "\t\t\t<td  style=\"padding: 0.5em 0\"valign=\"top\">URL to TNG Admin:&nbsp;</td>\n";
	echo "\t\t\t<td style=\"padding: 0.5em 0\"><input type=\"text\" name=\"mbtng_url_to_admin\" value=\"".get_option('mbtng_url_to_admin')."\" size=\"50\" \><br/>This is the URL to the TNG Admin page inside the TNG folder. So if you've put TNG into a folder called tng in your site's root folder it will be of the form http://YourSite.com/tng/admin.php</td>\n";
	echo "\t\t</tr>\n";
//End or Roger addition
	echo "\t\t<tr>\n";
	echo "\t\t\t<td  style=\"padding: 0.5em 0\"valign=\"top\">Integrate TNG/Wordpress logins:&nbsp;</td>\n";
	echo "\t\t\t<td style=\"padding: 0.5em 0\"><input type=\"checkbox\" name=\"mbtng_integrate_logins\"";
	if (get_option('mbtng_integrate_logins')) echo "checked='checked'";
	echo "\></td>\n";
	echo "\t\t</tr>\n";
	if (version_compare($wp_version, '2.6.1', '>')) {
		echo "\t\t<tr>\n";
		echo "\t\t\t<td  style=\"padding: 0.5em 0\"valign=\"top\">Redirect successful login to referrer page:&nbsp;</td>\n";
		echo "\t\t\t<td style=\"padding: 0.5em 0\"><input type=\"checkbox\" name=\"mbtng_redirect_login\"";
		if (get_option('mbtng_redirect_login')) echo "checked='checked'";
		echo "\></td>\n";
		echo "\t\t</tr>\n";
	}
	echo "\t\t<tr>\n";
	echo "\t\t\t<td  style=\"padding: 0.5em 0\"valign=\"top\">Replace TNG homepage with Wordpress page:&nbsp;</td>\n";
	echo "\t\t\t<td style=\"padding: 0.5em 0\"><input type=\"checkbox\" name=\"mbtng_use_wordpress_homepage\"";
	if (get_option('mbtng_use_wordpress_homepage')) echo "checked='checked'";
	echo "\></td>\n";
	echo "\t\t</tr>\n";
	echo "\t\t<tr>\n";
	echo "\t\t\t<td>&nbsp;</td>\n";
	echo "\t\t\t<td><p class=\"submit\" style=\"padding:0\"><input type=\"submit\" name=\"Submit\" value=\"Save Changes\" /></p></td>\n";
	echo "\t\t</tr>\n";
	wp_nonce_field('update-options');
	echo "<input type=\"hidden\" name=\"action\" value=\"update\" />";
// Roger added new variable mbtng_url_to_admin here to store path directly to TNG Admin
	echo "<input type=\"hidden\" name=\"page_options\" value=\"mbtng_wordpress_page, mbtng_path, mbtng_integrate_logins, mbtng_redirect_login, mbtng_use_wordpress_homepage, mbtng_url_to_admin\" />";
	echo "</form>\n";
	echo "\t</table>\n";
	echo "<h3>Advanced</h3>\n";
	echo "<form method=\"post\">\n";
// Roger added a width to this table
	echo "\t<table width=\"800\">\n";
	echo "<tr>\n";
// Roger added a width to this table column
	echo "<td width=\"200\"><p class=\"submit\" style=\"padding:0\"><input type=\"submit\" name=\"tng_search\" value=\"Search for TNG installation\" style=\"margin-right:1em\"/></p></td>\n";
	echo "<td>Click here to attempt to automatically find your TNG installation folder.</td>\n";
	echo "</tr><tr>\n";
	echo "<td><p class=\"submit\" style=\"padding:0\"><input type=\"submit\" name=\"update_globalvars\" value=\"Update TNG variables\" /></p></td>\n";
	echo "<td>You should press this button if you upgrade your TNG version, or if pages display incorrectly.</td>\n";
	echo "</tr>\n";
	echo "</table>\n";
	echo "</form>\n";
	echo "</div>\n";
	
// Roger changes here - these lines add link to open TNG Admin in new window
	$tng_folder = get_option('mbtng_path');
	chdir($tng_folder);
	include("begin.php");
	echo "<h3>TNG Admin</h3>\n";
	echo "<form method=\"post\">\n";
	echo "\t<table width=\"800\">\n";
	echo "<tr>\n";
// Roger this next line uses the Genealogy URL to get to Admin
//	echo "<td width=\"200\"><p class=\"submit\" style=\"padding:0\"><input type=\"submit\" name=\"GoToAdmin\" value=\"Go to TNG Admin\" onclick=\"newwindow=window.open('" . trailingslashit($tngdomain) . "admin.php')\" style=\"margin-right:1em\"/></p></td>\n";
// Roger this next line uses the new variable direct to TNG Admin
	echo "<td width=\"200\"><p class=\"submit\" style=\"padding:0\"><input type=\"submit\" name=\"GoToAdmin\" value=\"Go to TNG Admin\" onclick=\"newwindow=window.open('" . get_option(mbtng_url_to_admin) . "')\" style=\"margin-right:1em\"/></p></td>\n";
// Roger changes here
//	echo "<td>Use the Admin link to the left to open TNG Admin in this frame. (note that long pages won't scroll to the top when paging through lists). You should press this button to go to the TNG Admin area in a new window outside of the WordPress environment.</td>\n";
	echo "<td>Press this button to go to the TNG Admin area in a new window outside of the WordPress environment.</td>\n";
	echo "</tr>\n";
	echo "</table>\n";
	echo "</form>\n";
// End of additions for TNG Admin Link

}

//Displays TNG admin in an iframe
//Should consider javascript to set iframe height
function mbtng_display_tng_admin ($echo='') {
	$iframe = '<iframe name="tng" id="tng" src ="'.trailingslashit(get_option('mbtng_url')).'admin/index.php?true" height = 2000 width="100%"></iframe>';
	if ($echo === false)
		return $iframe;
	else
		echo $iframe;
}

/************************************************
*                                               *
*           LOG-IN/REGISTER FUNCTIONS           *
*                                               *
* mbtng_login_head                              *
* mbtng_register_form                           *
* mbtng_check_fields                            *
* mbtng_intercept_login                         *
* mbtng_register_user                           *
* mbtng_login                                   *
* mbtng_logout                                  *
* mbtng_redirect_login                          *
************************************************/

// if get_option('mbtng_integrate_logins') is true
// Adds styles to login header
// add_action('login_head', 'mbtng_login_head');
function mbtng_login_head() {
	echo "<style type=\"text/css\">#realname, #tree { font-size: 24px; width: 97%; padding: 3px; margin-top: 2px; margin-right: 6px; margin-bottom: 16px; border: 1px solid #e5e5e5; background: #fbfbfb; }</style>\n";
}

// if get_option('mbtng_integrate_logins') is true
// Adds additional fields to Wordpress registration form
// add_action('register_form', 'mbtng_register_form');
function mbtng_register_form() {
	// get the tng file path
	$tng_folder = get_option('mbtng_path');
	// change directory to tng folder
	chdir($tng_folder);
	// include tng file begin.php
	include('begin.php');
	// include tng file genlib.php
	// QUESTION: What is the $cms[tngpath] variable and where is it set?
	include_once($cms['tngpath'] . "genlib.php");
	// include tng file getlang.php
	include($cms['tngpath'] . "getlang.php");
	// set the textpart to login
	$textpart = "login";
	// include tng file language/text.php
	include($cms['tngpath'] . "{$mylanguage}/text.php");
	// connect to tng db
	mbtng_db_connect() or exit;
	// write query
	$query = "SELECT gedcom, treename FROM {$trees_table} ORDER BY treename";
	// select tree info from trees table
	$treeresult = mysql_query($query) or die ("{$admtext['cannotexecutequery']}: {$query}");
	// get the number of rows in the results
	$numtrees = mysql_num_rows( $treeresult );
	// print out the new fields on the registration form
	echo "<p><label>{$text['realname']}<br/><input id =\"realname\" class=\"input\" size=\"25\" name=\"realname\"></label>\n";
	echo "<p><label>{$text['tree']}<br/><select id=\"tree\" style=\"margin-bottom: 5px\" name=\"tree\"><option value=\"\"></option>";
	// while the trees are there print out a dropdown with the trees to pick from
	while($treerow = mysql_fetch_assoc($treeresult))
			echo "<option value=\"{$treerow['gedcom']}\">{$treerow['treename']}</option>\n";
	echo"</select><br/>{$text['leaveblank']}</label></p><br/>";
	// close the tng db connection and change back to WP
	mbtng_close_tng_table();
}

// if get_option('mbtng_integrate_logins') is true
// Checks required fields are completed during registration
// add_action('register_post', 'mbtng_check_fields', 10, 3);
// FIX: use add_filter('registration_errors', 'FUNCTION_NAME', 10, 3);
function mbtng_check_fields($login, $email, $errors) {
	// get the globals
	global $mbtng_realname, $mbtng_tree, $mbtng_password;
	// if the realname form field is blank
	if ($_POST['realname'] == '') {
		// get the tng folder path
		$tng_folder = get_option('mbtng_path');
		// change directory to tng folder
		chdir($tng_folder);
		// include tng file begin.php
		include('begin.php');
		// include tng file genlib.php
		include_once($cms['tngpath'] . "genlib.php");
		// // include tng file getlang.php
		include($cms['tngpath'] . "getlang.php");
		// set text part equal to login
		$textpart = "login";
		// include tng file language/text.php
		include($cms['tngpath'] . "{$mylanguage}/text.php");
		// add the errors for empty real name
		$errors->add('empty_realname', "<strong>ERROR</strong>: {$text['enterrealname']}");
	} else {
		// set the two POST variables to new variables
		$mbtng_realname = $_POST['realname'];
		$mbtng_tree = $_POST['tree'];
	}
}

// if get_option('mbtng_integrate_logins') is true
// Handles unsuccesful Wordpress login (checks to see if log-in would be successful in TNG)
// add_action('wp_authenticate', 'mbtng_intercept_login', 10, 1);
// FIX: use add_filter( 'authenticate', 'FUNCTION_NAME' );
// pass in the username
function mbtng_intercept_login($username) {
	// get the globals
	global $wpdb, $users_table;
	// get the tng folder path
    $tng_folder = get_option('mbtng_path');
    // change directory to tng folder
    chdir($tng_folder);
    // include the tng file pwdlib.php
    include_once('pwdlib.php');
    // if there is a username
	if ($username) {
		// get WP userdata
		// FIX: get_userdatabylogin is deprecated. use get_user_by('login', $username);
		$userdata = get_userdatabylogin($username);
		// if the username is not in WP
		if ($userdata === FALSE) { // Username not in WP user list
			// connect to the tng db
			$link = mbtng_db_connect() or exit;
			// write the query
			$query = "SELECT * FROM $users_table WHERE username='{$username}'";
			// select the user info from the tng userstable
			$result = mysql_query($query) or die ("Cannot execute query: $query");
			// get the user information
			$row = mysql_fetch_assoc($result);
			// close the tng db connection and change back to WP
			mbtng_close_tng_table();
			// if the username is a TNG user
			if ($row) { // Username IS in TNG userlist
				// set a new password variable
				$tng_password_hash = $row['password'];
				// if all three equal '1' (true) then tng admin equals '1' (true)
				$tng_admin = $row['allow_edit'] && $row['allow_add'] && $row['allow_delete'];
				// set a new email variable
				$tng_email = $row['email'];
				// set a new description variable
				$tng_nickname = $row['description'];
				// set a new userID variable
				$tng_id = $row['userID'];
				// check if user is already in WP by email, but with a different username
				// FIX: no need to do a manual db query. use get_user_by('email', $useremail)
				if($wpdb->get_var("select ID from {$wpdb->prefix}users WHERE user_email='{$tng_email}'"))							// throw up an error message by killing WP
					// FIX: much more graceful way of showing error
					wp_die ('You have logged in with your TNG username, which in your case is different from your Wordpress username. Click back to try again, or use your email address to <a href="?action=lostpassword">retrieve your password</a>.');
				else {
					// set a new password variable from user supplied password
					$password_supplied = $_POST['pwd'];
					// if the tng PasswordCheck function equals 1 or the password supplied equals the password hash
					if (PasswordCheck( $password_supplied, $tng_password_hash, $row['password_type'] ) == 1 || $password_supplied === $tng_password_hash) { // User has attempted to login to WP with correct TNG credentials
						// include WP file admin.php
						// FIX: Why? no need for this
						include_once(ABSPATH . 'wp-admin/includes/admin.php');
						// create a new WP user using the information from TNG
						$wp_id = wp_create_user($username, $password_supplied, $tng_email);
						// update the new user's metadata
						update_usermeta($wp_id, 'tng_user_id', $tng_id);
						update_usermeta($wp_id, 'nickname', $tng_nickname);
						// if the new user is a TNG admin, update their permissions
						if ($tng_admin) {
							// FIX: user levels are no longer used
							update_usermeta($wp_id, 'wp_user_level', '10');
							// incorrect way to set a role
							update_usermeta($wp_id, 'wp_capabilities', 'a:1:{s:13:"administrator";b:1;}');
						} else {
							// if not an admin set them up as a subscriber
							update_usermeta($wp_id, 'wp_capabilities', 'a:1:{s:10:"subscriber";b:1;}');
						}
					}
					else
						// the TNG PasswordCheck function did not work out
						// FIX: much more graceful way of showing error
						wp_die ('You have attempted to log in with your incorrect TNG credentials. You cannot reset your TNG password, so either click back to try again, or contact your system administrator.');
				}
			}
		}
		// Username IS in WP userlist, so sync TNG password with Wordpress password
		else {
			//  does the password supplied with the WP username match?
			if (user_pass_ok($username, $_POST['pwd'])) {
				// if it does do this stuff
				// FIX: get_userdatabylogin is deprecated. use get_user_by('login', $username);
				$userdata = get_userdatabylogin($username);
				// get their TNG username
				$tng_user_name = mbtng_check_user($userdata->ID);
				// get the current encryption type from TNG PasswordType function
				$password_type = PasswordType();
				// encrypt with the current encryption setting
	    		$password_hash = PasswordEncode($_POST['pwd'], $password_type);
	    		// connect to the tng db
				$link = mbtng_db_connect() or exit;
				// write the query
				$query = "UPDATE {$users_table} SET password='{$password_hash}' WHERE username='{$tng_user_name}'";
				// update the TNG password for the specified user
				$result = mysql_query($query) or die ("Cannot execute query: $query");
				// close the tng db connection and change back to WP
				mbtng_close_tng_table();
			} // end user_pass_ok() check
		} // end username is in WP
	} // end check if there is a username passed
} // end mbtng_intercept_login() function

// if get_option('mbtng_integrate_logins') is true
// Adds a new user to the TNG database when a user registers on WP
// add_action('user_register', 'mbtng_register_user', 10, 1);
// pass in the user ID
function mbtng_register_user ($user_ID) {
	// get global values
	global $mbtng_realname, $mbtng_tree;
	// pass the user ID to the mbtng create user function to create a new user
	mbtng_create_user($user_ID, trim($mbtng_realname), $mbtng_tree);
}

// Logs a user into TNG
// Replicates the functionality of processlogin.php
function mbtng_login() {
	global $current_user, $rootpath, $users_table;
    get_currentuserinfo();
	$tng_user_name = mbtng_check_user($current_user->ID);
	if (isset($_SESSION['currentuser']) && $tng_user_name == $_SESSION['currentuser'])
		return $tng_user_name;
	else {
		if (isset($_SESSION['currentuser']))
			mbtng_logout();
		$tng_folder = get_option('mbtng_path');
		chdir($tng_folder);
		include("begin.php");
		$textpart = "login";
		include($cms['tngpath'] . "getlang.php");
		$tngconfig['maint'] = "";
// Roger - Next line commented out per Darrin and Chuck Bush
//		include_once($cms['tngpath'] . "genlib.php");
		mbtng_db_connect() or exit;
		$query = "SELECT * FROM $users_table WHERE username='{$tng_user_name}'";
		$result = mysql_query($query) or die ("Cannot execute query: $query");
		$row = mysql_fetch_assoc( $result );
		mysql_free_result($result);
		$newdate = date ("Y-m-d H:i:s", time() + ( 3600 * $time_offset ) );
		$query = "UPDATE $users_table SET lastlogin=\"$newdate\" WHERE userID=\"{$row['userID']}\"";
		$uresult = mysql_query($query) or die ("{$admtext['cannotexecutequery']}: {$query}");
		$newroot = ereg_replace( "/", "", $rootpath );
		$newroot = ereg_replace( " ", "", $newroot );
		$newroot = ereg_replace( "\.", "", $newroot );
		setcookie("tnguser_$newroot", $tngusername, time()+31536000, "/");
		setcookie("tngpass_$newroot", $row['password'], time()+31536000, "/");
		setcookie("tngpasstype_$newroot", $row['password_type'], time()+31536000, "/");
		include_once("session_register.php");

		$allow_admin = $row['allow_edit'] || $row['allow_add'] || $row['allow_delete'] ? 1 : 0;
		if($allow_admin)
			setcookie("tngloggedin_$newroot", "1", 0, "/");

		$logged_in = $_SESSION['logged_in'] = 1;

		$allow_edit = $_SESSION['allow_edit'] = ($row['allow_edit'] == 1 ? 1 : 0);
		$allow_add = $_SESSION['allow_add'] = ($row['allow_add'] == 1 ? 1 : 0);
		$tentative_edit = $_SESSION['tentative_edit'] = $row['tentative_edit'];
		$allow_delete = $_SESSION['allow_delete'] = ($row['allow_delete'] == 1 ? 1 : 0);

		$allow_media_edit = $_SESSION['allow_media_edit'] = ($row['allow_edit'] ? 1 : 0);
		$allow_media_add = $_SESSION['allow_media_add'] = ($row['allow_add'] ? 1 : 0);
		$allow_media_delete = $_SESSION['allow_media_delete'] = ($row['allow_delete'] ? 1 : 0);

		$_SESSION['mygedcom'] = $row['mygedcom'];
		$_SESSION['mypersonID'] = $row['personID'];
		$_SESSION['allow_admin'] = $allow_admin;

		$allow_living = $_SESSION['allow_living'] = $row['allow_living'];
        $allow_private = $_SESSION['allow_private'] = $row['allow_private'];

		$allow_ged = $_SESSION['allow_ged'] = $row['allow_ged'];
		$allow_pdf = $_SESSION['allow_pdf'] = $row['allow_pdf'];
		$allow_profile = $_SESSION['allow_profile'] = $row['allow_profile'];

		$allow_lds = $_SESSION['allow_lds'] = $row['allow_lds'];

		$assignedtree = $_SESSION['assignedtree'] = $row['gedcom'];
		$assignedbranch = $_SESSION['assignedbranch'] = $row['branch'];
		$currentuser = $_SESSION['currentuser'] = $row['username'];
		$currentuserdesc = $_SESSION['currentuserdesc'] = $row['description'];
		$session_rp = $_SESSION['session_rp'] = $rootpath;
		// close the tng db connection and change back to WP
		mbtng_close_tng_table();
		return $tngusername;
	}
}

// Logs a user out of TNG
function mbtng_logout() {
	global $rootpath;
	$tng_folder = get_option('mbtng_path');
	chdir($tng_folder);
	include("begin.php");
	if ($_SESSION['currentuser'] != '')
		include('logout.php');
}

// Redirects the user back to the referrer page, rather than the dashboard
function mbtng_redirect_login ($link, $request_link, $user) {
	if (isset($_SERVER['HTTP_REFERER']) && strtolower(substr($_SERVER['HTTP_REFERER'], -12)) != 'wp-login.php')
		return $_SERVER['HTTP_REFERER'];
	else
		return $link;
}

/************************************************
*                                               *
*                USER FUNCTIONS                 *
*                                               *
* mbtng_create_user                             *
* mbtng_check_user                              *
* mbtng_delete_user                             *
************************************************/

// Adds a new TNG user and returns the username
function mbtng_create_user($user_ID, $realname='', $tree='') {
	// gets the items stored in the global scope
	global $users_table, $trees_table, $tngconfig;
	// get the TNG file path
	$tng_folder = get_option('mbtng_path');
	// changes directory to the TNG directory
    chdir($tng_folder);
    //include the pwdlib file
    include_once('pwdlib.php');
    // get the user info from WP
	$user_info = get_userdata($user_ID);
	// open the connection to the TNG db
	$link = mbtng_db_connect() or exit;
	// write the query
	$query = "SELECT userID FROM $users_table WHERE email='{$user_info->user_email}'";
	// check if a user already exists in TNG with the same email address
	// really shouldn't be using this anymore...
	$result = mysql_query($query) or die ("Cannot execute query: $query");
	// return the number of rows (same email address)
	$found = mysql_num_rows($result);
	
	/*** Start test for users with matching email ***/
	
	// if no matches to the email address are found
	if ($found == 0) {
		// close the tng db connection and change back to WP
		mbtng_close_tng_table();
		// if the real name passed through the function is empty
		if ($realname == '')
			// use the WP nickname
			$realname = trim(get_user_meta($user_ID, 'nickname', true));
		//	get the current password encryption setting
		$password_type = PasswordType();   // the current encryption setting
		// generate a new password using the WP function
		$password = wp_generate_password();

		//$password = md5(wp_generate_password());
		// get the new user's email
		$email = $user_info->user_email;
		//get the new user's login to use as a TNG username
		$username = $user_info->user_login;
		// get the time offset from the WP options table
		$time_offset = get_option('gmt_offset');
		// deprecated and removed from php as of 5.4
		if (get_magic_quotes_gpc() == 0) {
			// add slashes to make it safe for db insertion O'Reilly becomes O\'Reilly
			$username = addslashes($username);
			$password = addslashes($password);
			$realname = addslashes($realname);
			$email = addslashes($email);
		}
		
		/*** Start test if tree is set ***/
		
		// if there is no tree set and autotree is set to 1
		if(!$tree && $tngconfig['autotree']) {
			// write the query
			$query = "SELECT MAX(0+SUBSTRING(gedcom,5)) as oldID FROM $trees_table WHERE gedcom LIKE \"tree%\"";
			// get the old tree id where the gedcom column starts with tree
			$result = mysql_query($query) or die ("Cannot execute query: $query");
			// if the number of rows returns true
			if(mysql_num_rows) {
				// set maxrow to equal the old tree id
				$maxrow = mysql_fetch_array( $result );
				// set gedcom to be tree + the old id
				$gedcom = "tree" . ($maxrow['oldID'] + 1);
			}
			else
				// otherwise set gedcom to tree1
				$gedcom = "tree1";
			// free the result
			mysql_free_result($result);
			// write the query
			$query = "INSERT IGNORE INTO $trees_table (gedcom,treename,description,owner,email,address,city,state,country,zip,phone,secret,disallowgedcreate) VALUES (\"$gedcom\",\"$realname\",\"\",\"$realname\",\"$email\",\"\",\"\",\"\",\"\",\"\",\"\",\"0\",\"0\")";
			// insert a new row with the user's information, ignore the query if it would duplicate a row
			$result = mysql_query($query) or die ("Cannot execute query: $query");
		}
		else
			// if no tree is set and autotree is set to 0 
			// gedcom is set to the passed value tree if assignedtree is not present 
			$gedcom = $assignedtree ? $assignedtree : $tree;

		/*** End test if tree is set ***/
		
		// get today's date
		$today = date("Y-m-d H:i:s", time() + (3600*$time_offset));
		// start a counter
		$i=0;
		// found now equals 1
		$found=1;
		// open a connection to the TNG db
		$link = mbtng_db_connect() or exit;
		// while found equals something other than 1
		// QUESTION: What the heck is this doing?
		while ($found !=0) {
			// and if $i is not equal to 0
			if ($i !=0)
				// username is now equal to the username + $i
				$username = $username.$i;
			// write query	
			$query = "SELECT username FROM $users_table WHERE username='{$username}'";
			// are there any users that match that username?
			$result = mysql_query($query) or die ("Cannot execute query: $query");
			// set found to equal the number of rows found
			$found = mysql_num_rows($result);
			// add one to $i
			$i++;
			
			// the first time through this while loop, it looks for any users in the TNG db that have the 
			// same user name as the newly created WP user. 
			// if there is, then found is now equal to the number of rows returned
			// if no user is found, jump out of while loop
			// the second time through username is now equal to the username + 1
			// checks TNG for a username again and either exits or continues the loop
		}
		// is there a user level set and does it equal 10 (administrator)
		// user levels were replaced in WP in 2.0 and deprecated in 3.0
		if (isset($user_info->user_level) && $user_info->user_level == 10) {
			// encrypt the password using the function from TNG pwdlib.php
	    	$password = PasswordEncode($password, $password_type);  // encrypt with the current encryption setting
			// write the query
			$query = "INSERT INTO $users_table (description,username,password,password_type,realname,email,gedcom,allow_edit,allow_add,allow_delete,allow_lds,allow_ged,allow_living,allow_private,dt_registered) VALUES (\"$realname\",\"$username\",\"$password\",\"$password_type\",\"$realname\",\"$email\",\"$gedcom\",1,1,1,1,1,1,1,\"$today\")";
		}
		// so the userlevel is not set or it does not equal 10
		else {
			// does the autoapp setting equal 1?
			if($tngconfig['autoapp']) {
				// if so set allow living and private to 0
				$allow_livingprivate_val = 0;
				// encode the password using the function from TNG pwdlib.php
				$password = PasswordEncode($password, $password_type);
			}
			// autoapp is set to something other than 1
			else {
				// set the allow living and private to -1
				// QUESTION: What about the PasswordEncode for this else condition?
				$allow_livingprivate_val = -1;
			}
			// write the query
			$query = "INSERT INTO $users_table (description,username,password,password_type,realname,email,gedcom,allow_living,allow_private,dt_registered) VALUES (\"$realname\",\"$username\",\"$password\",\"$password_type\",\"$realname\",\"$email\",\"$gedcom\",$allow_livingprivate_val,$allow_livingprivate_val,\"$today\")";
		}
		// insert the new user into the TNG db users table
		$result = mysql_query($query) or die (mysql_errno($link) . ": " . mysql_error($link). "\n Query:\n".$query);
		// if all works out get the new TNG userID
		$success = mysql_insert_id($link);
		// close the tng db connection and change back to WP
		mbtng_close_tng_table();
		// update WP user meta table with the new tng userID
		update_usermeta($user_ID, 'tng_user_id', $success);
		// then log the user into TNG
		return mbtng_login();
	}
	// okay, found one user with the same email address
	elseif ($found == 1) {
		// get the info about that user
		$row = mysql_fetch_assoc($result);
		// close the tng db connection and change back to WP
		mbtng_close_tng_table();
		// update WP user meta table with the TNG userID
		update_usermeta($user_ID, 'tng_user_id', $row['userID']);
		// return the TNG userID
		return $row['userID'];
	}
	// if more than one user in TNG is found with the same email address as the newly created WP user
	// just die
	else
		wp_die('There is more than one user with that email address in TNG. Wordpress only supports one account per e-mail address.');
	
	/*** End test for users with matching email ***/
}

// Checks to see if user exists in TNG. If it does, returns the username.
// pass in userID
function mbtng_check_user($user_ID) {
	// if the userID is not present, return nothing
	if(!$user_ID)
		return "";
	// get the global users_table	
	global $users_table;
	// the tng user id is stored in the WP usermeta table
	$tng_user_ID = get_user_meta($user_ID, 'tng_user_id', true);
	
	// if the tng user id is empty, run the create user function
	if ($tng_user_ID == '')
		return mbtng_create_user($user_ID); //User doesn't exist, or link not created
	// otherwise	
	else {
		// open connection to tng db
		$link = mbtng_db_connect() or exit;
		// write query
		$query = "SELECT username FROM $users_table WHERE userID='{$tng_user_ID}'";
		// select the record from the users table where the userID is equal to the userID passed in
		$result = mysql_query($query) or die ("Cannot execute query: $query");
		// get the results in a row
		$row = mysql_fetch_assoc($result);
		// get the number of rows returned
		$found = mysql_num_rows($result);
		// close the tng db connection and change back to WP
		mbtng_close_tng_table();
		// if the number of rows found is 0
		if($found == 0) {
			// delete the usermeta for this WP user as the user does not exist in TNG
			delete_usermeta($user_ID, 'tng_user_id'); // Link is invalid
			// run the create user function
			return mbtng_create_user($user_ID);
		}
		// the number of rows found is greater than 1 or more
		else
			// return the tng username
			return $row['username']; // Link is correct
	}
}

// Deletes TNG users when deleted from Wordpress
// pass in userID
function mbtng_delete_user($user_ID) {
	// get the global users_table
	global $users_table;
	// the tng user id is stored in the WP usermeta table
	$tng_user_id = get_user_meta($user_ID, 'tng_user_id', true);
	// if the tng user id is empty
	if ($tng_user_id != '') {
		// get the tng path
		$tng_folder = get_option('mbtng_path');
		// change directory to tng folder
		chdir($tng_folder);
		// open connection to tng db
		$link = mbtng_db_connect() or exit;
		// write query
		$query = "DELETE FROM $users_table WHERE userID='{$tng_user_id}'";
		// delete the user specified from the tng users table
		$result = mysql_query($query) or die ("Cannot execute query: $query");
		// close the tng db connection and change back to WP
		mbtng_close_tng_table();
	}
}

/************************************************
*                                               *
*             OUTPUT THE TNG PAGE               *
*                                               *
* mbtng_use_tng_homepage                        *
* mbtng_frontend_header                         *
* mbtng_frontend_footer                         *
* mbtng_fake_post                               *
* mbtng_output_page                             *
* mbtng_buffer_start                            *
* mbtng_buffer_end                              *
* mbtng_discard_output                          *
* mbtng_eval_php                                *
************************************************/

function mbtng_use_tng_homepage() {
	if (get_option('mbtng_use_wordpress_homepage')==TRUE && mbtng_requested_url() == get_option('mbtng_url'))
		return false;
	else
		return true;
}

//Adds TNG header to Wordpress header
function mbtng_frontend_header() {
	global $tng_head, $languages_path;
// Roger - if you want to over-ride these styles, copy these lines to mytngstyle.css and change them there
    $tng_head = "<style type=\"text/css\">
    #tng_main {line-height: 1.2em}
    #tng_main tr td {padding: 2px 4px; margin:0; border-top: none}
    p, pre {line-height: 1.2em}
    </style>\r\n".$tng_head;
	if (strpos(mbtng_filename(), 'admin') !== FALSE)
		$tng_head .= '<base href = "'.get_permalink(get_option('mbtng_wordpress_page')).'"/>';
	$tng_head .= "<style type=\"text/css\">#tng_main td.menuback, #tng_main td.spacercol, #tng_main table.page td.section td.fieldname {display:none}</style>";
	if ($tng_head != '')
		echo $tng_head;
}

//Adds TNG footer to Wordpress footer
function mbtng_frontend_footer() {
	global $tng_footer;
	if ($tng_footer != '')
		echo $tng_footer;
}

// Returns the TNG Wordpress post whenever a TNG page is requested
function mbtng_fake_post($posts){
	if (mbtng_display_page()){
		$posts = get_pages('include='.get_option('mbtng_wordpress_page'));
		add_filter('user_trailingslashit', 'mbtng_smart_trailingslashit');
	}
	return $posts;
}

//Outputs the TNG code (outside the loop to minimise CSS conflicts)
function mbtng_output_page() {
	global $tng_output;
	if (mbtng_display_page() && mbtng_use_tng_homepage()) {
		echo $tng_output;
		ob_start();
	}
}

// Buffers Wordpress and TNG code to allow for correct merging of HTML
function mbtng_buffer_start() {
	global $tng_output, $tng_head, $tng_footer;
	$query = mbtng_requested('query');
	if (mbtng_display_page()) {
		$tng_folder = get_option('mbtng_path');
		$filename = mbtng_filename();
		if (mbtng_extension() == 'php') {
 			if ($filename == 'newacctform.php' && get_option('mbtng_integrate_logins')) {
				header("Location: ".trailingslashit(get_bloginfo('wpurl'))."wp-login.php?action=register");
				die();
			}
 			if ($filename == 'login.php' && get_option('mbtng_integrate_logins')) {
				header("Location: ".trailingslashit(get_bloginfo('wpurl'))."wp-login.php");
				die();
			}
 			if ($filename == 'logout.php' && get_option('mbtng_integrate_logins')) {
				if (function_exists('wp_logout_url'))
					header("Location: ".html_entity_decode(wp_logout_url()));
				else
					header("Location: ".trailingslashit(get_bloginfo('wpurl'))."wp-login.php?action=logout");
				die();
			}
			$tng_output = mbtng_eval_php($filename);
			$non_template_files = array ('tngrss.php', 'addbookmark.php', 'fpdf.php', 'ufpdf.php');
			if (in_array($filename, $non_template_files) || (isset($_REQUEST['tngprint']) && $_REQUEST['tngprint']==1) || stripos($filename, 'admin') !== FALSE || stripos($filename, 'ajx_') !== FALSE || stripos($filename, 'img_') !== FALSE || stripos($filename, 'find') !== FALSE || stripos($filename, 'rpt_') !== FALSE || stripos($tng_output, '<!-- The Next Generation of Genealogy Sitebuilding') === FALSE) {
				echo $tng_output;
				die();
			} else {
				if ($filename == 'admin/index.php') {
					$tng_output = '<div id="tng_main">'.mbtng_display_tng_admin(false).'</div>';
				}
				else {
					$head_start = stripos ($tng_output, '<head>')+6;
					$head_end = stripos ($tng_output, '</head>');
					$tng_head = substr ($tng_output, $head_start, $head_end-$head_start);
					$tng_head = str_replace('.css?', '.css?frontend&amp;', $tng_head);
                    $tng_head = str_replace('.css" rel', '.css?frontend" rel', $tng_head);
					$footer_start = stripos ($tng_output, '</body>')+7;
					$footer_end = stripos ($tng_output, '</html>');
					$tng_footer = substr ($tng_output, $footer_start, $footer_end-$footer_start);
					$output_start = stripos($tng_output, '>', stripos ($tng_output, '<body'))+1;
					$output_end = $footer_start-8;
					$tng_output = '<div id="tng_main">'.substr ($tng_output, $output_start, $output_end-$output_start).'</div>';
				}
				ob_start('');
			}
		}
	}
}
 
//Flushes the output buffer
function mbtng_buffer_end() {
	@ob_end_flush();
}
 
// Discards contents of page if TNG is displayed
function mbtng_discard_output() {
	if (mbtng_display_page() && mbtng_use_tng_homepage()) {
		ob_clean();
		// Remove actions in case loop used elsewhere (e.g. in sidebar)
		remove_action('loop_start', 'mbtng_output_page');
		remove_action('loop_end', 'mbtng_discard_output');
	}

}

//Runs the TNG PHP code and returns the HTML
function mbtng_eval_php($filename) {
	global $tng_output;
	if ($tng_output == '') {
		$tng_folder = get_option('mbtng_path');
		if (stripos($filename, 'admin') !== FALSE) {
			//$filename = substr ($filename, 6);
			$admin = true;
			//$tng_folder .= 'admin';
		} else {
			ini_set('include_path', ini_get('include_path').PATH_SEPARATOR.$tng_folder);
			$admin = false;
		}
		eval('?>'.get_option('mbtng_globalvars').'<?php ');
		ob_start();
		$code = file_get_contents(mbtng_folder_trailingslashit($tng_folder).$filename);
		if ($admin || $filename == 'pdfform.php')
			chdir($tng_folder);
		eval("?>$code<?php ");
		$output = ob_get_contents();
		ob_end_clean();
		$tng_output = $output;
	}
	// close the tng db connection and change back to WP
	mbtng_close_tng_table();
	return $tng_output;
}

/************************************************
*                                               *
*        DETERMINE WHICH PAGE TO DISPLAY        *
*                                               *
* mbtng_requested                               *
* mbtng_extension                               *
* mbtng_filename                                *
************************************************/

// Returns the requested TNG page or query
function mbtng_requested ($type = 'url') {
	$requested = mbtng_requested_url();
	$query_pos = strrpos($requested, '?')+1;
	$query = '';
	if ($query_pos !== 1) {
		$query = substr($requested, $query_pos);
		$requested = substr($requested, 0, $query_pos-1);
		$query_pos = strrpos($query, 'tng_template='); // Check
		if ($query_pos !== FALSE)
			$query = substr($query, 0, $query_pos);
	}
	if ($type == 'query')
		return $query;
	elseif ($type='url') {
		$requested = substr($requested, strlen(get_option('mbtng_url')));
		if (substr($requested, 0, 1) != '/')
			$requested = '/'.$requested;
		if (substr($requested, -1) == '/')
			$requested .= 'index.php';
		return $requested;
	}
}

// Returns the file extension of the requested page
function mbtng_extension () {
	$requested = mbtng_requested();
	if (mbtng_display_page())
		return strtolower(substr($requested, strrpos($requested, '.') + 1));
}

// Returns the filename of the requested page
function mbtng_filename () {
	return substr(mbtng_requested(),1);
}

/************************************************
*                                               *
*               WIDGET FUNCTIONS                *
*                                               *
* mbtng_widget_init                             *
* mbtng_check_parent                            *
* mbtng_display_widget                          *
* mbtng_output_search                           *
* mbtng_output_menu                             *
* mbtng_output_template_menu                    *
* mbtng_base_url                                *
************************************************/

// Initialise the widget
function mbtng_widget_init() {
	wp_register_sidebar_widget('mbtng_output_search', 'TNG search', 'mbtng_output_search', array('classname' => 'mbtng_output_search', 'description' => 'Displays the TNG search in the sidebar.'));
	wp_register_sidebar_widget('mbtng_output_menu', 'TNG menu', 'mbtng_output_menu', array('classname' => 'mbtng_output_menu', 'description' => 'Displays the TNG menu in the sidebar.'));
}

// Returns true if page is descendant of TNG page
function mbtng_check_parent($check_id = -10) {
	global $post, $wpdb;
	if ($check_id == -10)
		$check_id = $post->ID;
	if ($check_id == get_option('mbtng_wordpress_page'))
		return true;
	elseif ($check_id == 0)
		return false;
	else {
		$parent = $wpdb->get_var("SELECT post_parent FROM {$wpdb->prefix}posts WHERE id='{$check_id}'");
		return mbtng_check_parent($parent);
	}
}

// Returns true if the widgets should be displayed
function mbtng_display_widget() {
	if (mbtng_display_page())
		return true;
	else
		return mbtng_check_parent();
}

//Outputs the TNG search in the sidebar
function mbtng_output_search ($args) {
	global $languages_path;
	if (!mbtng_display_widget()) {
		extract($args);
		$tng_folder = get_option('mbtng_path');
		chdir($tng_folder);
		include('begin.php');
		include_once($cms['tngpath'] . "genlib.php");
		include($cms['tngpath'] . "getlang.php");
		include($cms['tngpath'] . "{$mylanguage}/text.php");
		echo $before_widget;
		echo $before_title.'People Search'.$after_title;
		$base_url = mbtng_base_url();
		echo "<form action=\"{$base_url}search.php\" method=\"post\" target=\"_blank\">\n";
		echo "<table class=\"menuback\">\n";
		echo "<tr><td><span class=\"normal\">{$text['mnulastname']}:<br /><input type=\"text\" name=\"mylastname\" class=\"searchbox\" size=\"14\" /></span></td></tr>\n";
		echo "<tr><td><span class=\"normal\">{$text['mnufirstname']}:<br /><input type=\"text\" name=\"myfirstname\" class=\"searchbox\" size=\"14\" /></span></td></tr>\n";
		echo "<tr><td><input type=\"hidden\" name=\"mybool\" value=\"AND\" /><input type=\"submit\" name=\"search\" value=\"{$text['mnusearchfornames']}\" class=\"small\" /></td></tr>\n";
		echo "</table>\n";
		echo "</form>\n";
		echo "<ul>\n";
		echo "<li style=\"font-weight:bold\"><a href=\"{$base_url}searchform.php\" target=\"_blank\">{$text['mnuadvancedsearch']}</a></li>\n";
		echo "</ul>\n";
		echo $after_widget;
	}
}

//Outputs the TNG menu in the sidebar
function mbtng_output_menu ($args) {
//	global $allow_admin, $languages_path;
	global $languages_path;
	$allow_admin = $_SESSION['allow_admin'];
	if (!mbtng_display_widget()) {
		extract($args);
		$tng_folder = get_option('mbtng_path');
		chdir($tng_folder);
		include('begin.php');
		include_once($cms['tngpath'] . "genlib.php");
		include($cms['tngpath'] . "getlang.php");
		include($cms['tngpath'] . "{$mylanguage}/text.php");
		include($cms['tngpath'] . "{$mylanguage}/alltext.php");
		echo $before_widget;
		echo $before_title.'Genealogy Menu'.$after_title;
		$base_url = mbtng_base_url();
		echo "<ul>\n";
		echo "<li class=\"suggest\" style=\"font-weight:bold\"><a href=\"{$base_url}suggest.php\" target=\"_blank\">{$text['contactus']}</a></li>\n";
		echo "</ul>\n";
		echo "<ul style=\"margin-top:0.75em\">\n";
		echo "<li class=\"surnames\"><a href=\"{$base_url}surnames.php\" target=\"_blank\">{$text['mnulastnames']}</a></li>\n";
		echo "<li class=\"places\"><a href=\"{$base_url}places.php\" target=\"_blank\">{$text['places']}</a></li>\n";
		echo "<li class=\"cemeteries\"><a href=\"{$base_url}cemeteries.php\" target=\"_blank\">{$text['mnucemeteries']}</a></li>\n";
		echo "<li class=\"headstones\"><a href=\"{$base_url}browsemedia.php?mediatypeID=headstones\" target=\"_blank\">{$text['mnutombstones']}</a></li>\n";
		echo "<li class=\"albums\"><a href=\"{$base_url}browsealbums.php\" target=\"_blank\">{$text['albums']}</a></li>\n";		
		echo "<li class=\"media\"><a href=\"{$base_url}browsemedia.php\" target=\"_blank\">{$text['allmedia']}</a>\n";
			echo "<ul>\n";
			echo "<li class=\"photos\"><a href=\"{$base_url}browsemedia.php?mediatypeID=photos\" target=\"_blank\">{$text['mnuphotos']}</a></li>\n";
			echo "<li class=\"documents\"><a href=\"{$base_url}browsemedia.php?mediatypeID=documents\" target=\"_blank\">{$text['documents']}</a></li>\n";
			echo "<li class=\"histories\"><a href=\"{$base_url}browsemedia.php?mediatypeID=histories\" target=\"_blank\">{$text['mnuhistories']}</a></li>\n";
			echo "<li class=\"videos\"><a href=\"{$base_url}browsemedia.php?mediatypeID=videos\" target=\"_blank\">{$text['videos']}</a></li>\n";
			echo "<li class=\"recordings\"><a href=\"{$base_url}browsemedia.php?mediatypeID=recordings\" target=\"_blank\">{$text['recordings']}</a></li>\n";
			echo "</ul></li>";
		echo "<li class=\"whatsnew\"><a href=\"{$base_url}whatsnew.php\" target=\"_blank\">{$text['mnuwhatsnew']}</a></li>\n";
		echo "<li class=\"mostwanted\"><a href=\"{$base_url}mostwanted.php\" target=\"_blank\">{$text['mostwanted']}</a></li>\n";
		echo "<li class=\"anniversaries\"><a href=\"{$base_url}anniversaries.php\" target=\"_blank\">{$text['anniversaries']}</a></li>\n";
//		echo "<li class=\"notes\"><a href=\"{$base_url}browsenotes.php\">{$text['notes']}</a></li>\n";
//		echo "<li class=\"reports\"><a href=\"{$base_url}reports.php\">{$text['mnureports']}</a></li>\n";
//		echo "<li class=\"sources\"><a href=\"{$base_url}browsesources.php\">{$text['mnusources']}</a></li>\n";
//		echo "<li class=\"repos\"><a href=\"{$base_url}browserepos.php\">{$text['repositories']}</a></li>\n";

//		echo "<li class=\"language\"><a href=\"{$base_url}changelanguage.php\">{$text['mnulanguage']}</a></li>\n";
//		echo "<li class=\"bookmarks\"><a href=\"{$base_url}bookmarks.php\">{$text['bookmarks']}</a></li>\n";
		echo "</ul>\n";
		echo "<ul style=\"margin-top:0.75em\">\n";
		if ($allow_admin) {
			echo "<li class=\"admin\" style=\"font-weight:bold\"><a href=\"{$base_url}admin.php\" target=\"_blank\">{$text['mnuadmin']}</a></li>\n";
			echo "<li class=\"trees\"><a href=\"{$base_url}browsetrees.php\" target=\"_blank\">{$text['mnustatistics']}</a></li>\n";
			echo "<li class=\"showlog\"><a href=\"{$base_url}showlog.php\" target=\"_blank\">{$text['mnushowlog']}</a></li>\n";
		}
		echo "</ul>\n";
		echo "<ul style=\"margin-top:0.75em\">\n";
		if (!is_user_logged_in()) {
			echo "<li class=\"register\" style=\"font-weight:bold\"><a href=\"{$base_url}newacctform.php\">{$text['mnuregister']}</a></li>\n";
			echo "<li class=\"login\" style=\"font-weight:bold\"><a href=\"{$base_url}login.php\">{$text['mnulogon']}</a></li>\n";
		} else {
			if (function_exists('wp_logout_url'))
				echo "<li class=\"logout\" style=\"font-weight:bold\"><a href=\"".html_entity_decode(wp_logout_url())."\">{$text['logout']}</a></li>\n";
			else
				echo "<li class=\"logout\" style=\"font-weight:bold\"><a href=\"".trailingslashit(get_bloginfo('wpurl'))."wp-login.php?action=logout"."\">{$text['logout']}</a></li>\n";
		}
		echo "</ul>";
		echo $after_widget;
	}
}

//Returns a full URL only if required
function mbtng_base_url () {
	if (!mbtng_display_page())
		return get_permalink(get_option('mbtng_wordpress_page'));
	else
		return '';
}

/************************************************
*                                               *
*                'HELPER' FUNCTIONS             *
*                                               *
* mbtng_db_connect                              *
* mbtng_close_tng_table                         *
* mbtng_requested_url                           *
* mbtng_is_windows                              *
* mbtng_correct_path                            *
* mbtng_display_page                            *
* mbtng_get_template_list                       *
* mbtng_get_template                            *
* mbtng_smart_trailingslashit                   *
* mbtng_folder_trailingslashit                  *
* mime_content_type                             *
************************************************/

//Replicates tng_db_connect
// FIX: Use wpdb class
function mbtng_db_connect() {
	// get global values
	global $textpart, $session_charset;
	// get the tng path
	$tng_folder = get_option('mbtng_path');
	// change to the TNG folder directory
	chdir($tng_folder);
	// get the contents of config.php
	$config = file_get_contents ('config.php');
	// get the contents of customconfig.php
	$config .= file_get_contents ('customconfig.php');
	// split the contents of the two files on each new line
	$configlines = explode("\n",$config);
	// set the config variable to blank
	$config = '';
	// set the globalvars variable
	$globalvars = 'global $tngconfig';
	// loop through each new line
	foreach ($configlines as $line) {
		// find each line that starts with $database_
		if (substr(trim($line), 0, 10) == '$database_')
			// once found trim the line
			$config .= trim($line)."\n";
		// if the line contains the string _table
		if (stripos($line, '_table') !== FALSE) {
			// set the globalvars variable
			// return the value of the line with _table starting from position 0 and going to the position of _table +6
			// this gets each table onto a separate line
			$globalvars .= ', '.substr(trim($line), 0, stripos($line, '_table')+6);
			$config .= trim($line)."\n";
		}
		// if the string tngconfig is present
		else if(strpos($line, 'tngconfig') !== FALSE) {
			$config .= trim($line)."\n";
		}
	} // end foreach loop
	
	// the use of eval is highly discouraged
	// FIX: remove eval
	// create global variables for database values and table names
	eval($globalvars.';'.$config);
	// create connection to TNG db, suppressing errors
	$link = @mysql_connect($database_host, $database_username, $database_password);
	// if the session character set is UTF-8 
	if ($session_charset == 'UTF-8')
		// mysql api call to set character set to utf8
		@mysql_query("SET NAMES 'utf8'");
	// if the connection is good and can select the tng database
	if( $link && mysql_select_db($database_name, $link))
		// return true
		return $link;
	// otherwise throw and error
	else {
		echo "Error: TNG is not communicating with your database. Please check your database settings and try again.";
		exit;
	}
	// return false if nothing is working
	return( FALSE );
}

// Reselects the Wordpress database table
function mbtng_close_tng_table () {
	// close the tng db connection and change back to WP
	mysql_select_db (DB_NAME);
}

//Returns the full URL requested. May need modifying for IIS.
function mbtng_requested_url () {
	// determines the url requsted and returns it
	return "http://".$_SERVER['SERVER_NAME'].urldecode($_SERVER['REQUEST_URI']);
}

//Returns TRUE if running on Windows
function mbtng_is_windows () {
	// checks the PHP_OS constant to determine the server OS
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN')
		// if it is windows return true
		return true;
	else
		// otherwise return false
		return false;
}

// Returns true if mbtng_path option is correct
// pass in a path
function mbtng_correct_path($path='') {
	// get the current working directory
	$current_folder = getcwd();
	// if the path is not there
	if ($path == '')
		// then the path is stored in the WP database
		$path = get_option('mbtng_path');
	// now change directory to the path variable: either from the WP db or passed to the function
	@chdir($path);
	// check if these three files exist in this new directory
	if (file_exists('begin.php') && file_exists('admin.php') && file_exists('genlib.php')) {
		// if they do change the directory back to the current working directory
		chdir($current_folder);
		// and return true (the path is valid)
		return true;
	} else {
		// otherwise change the directory back to the current working directory
		chdir($current_folder);
		// and return false (the path is invalid)
		return false;
	}
}

// Returns true if TNG page requested
function mbtng_display_page () {
	if (get_option('mbtng_wordpress_page')=='' | !mbtng_correct_path())
		return false;
	else {
		$requested = mbtng_requested_url();
		$query_pos = strrpos($requested, '?')+1;
		if ($query_pos !== 1)
			$requested = substr($requested, 0, $query_pos-1);
		if (stripos ($requested, get_option('mbtng_url')) !== FALSE) {
			$tng_folder = get_option('mbtng_path');
			$filename = mbtng_filename();
			if (file_exists("{$tng_folder}/{$filename}")) {
				return true;
			}
		}
		else
			return false;
	}
}

// Updates the TNG URL in wp_options
function mbtng_update_tng_url() {
	update_option('mbtng_url', get_permalink(get_option('mbtng_wordpress_page')));
}

//Removes the trailing slash from the URL
function mbtng_smart_trailingslashit ($url) {
	$url = rtrim($url, '/') . '/';
	if ( 0 < preg_match("#\.[^/]+/$#", $url) )
		$url = rtrim($url, '/');
	return $url;
}

//Adds a server-specific slash for folders
function mbtng_folder_trailingslashit ($folder) {
	if (mbtng_is_windows())
		return rtrim($folder, '\\').'\\';
	else
		return trailingslashit($folder);
}

//Replacement mime_content_type_function if required
if(!function_exists('mime_content_type')) {
	function mime_content_type($filename) {
		$mime_types = array(
			'txt' => 'text/plain',
			'htm' => 'text/html',
			'html' => 'text/html',
			'php' => 'text/html',
			'css' => 'text/css',
			'js' => 'application/javascript',
			'json' => 'application/json',
			'xml' => 'application/xml',
			'swf' => 'application/x-shockwave-flash',
			'flv' => 'video/x-flv',
			'png' => 'image/png',
			'jpe' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpg' => 'image/jpeg',
			'gif' => 'image/gif',
			'bmp' => 'image/bmp',
			'ico' => 'image/vnd.microsoft.icon',
			'tiff' => 'image/tiff',
			'tif' => 'image/tiff',
			'svg' => 'image/svg+xml',
			'svgz' => 'image/svg+xml',
			'zip' => 'application/zip',
			'rar' => 'application/x-rar-compressed',
			'exe' => 'application/x-msdownload',
			'msi' => 'application/x-msdownload',
			'cab' => 'application/vnd.ms-cab-compressed',
			'mp3' => 'audio/mpeg',
			'qt' => 'video/quicktime',
			'mov' => 'video/quicktime',
			'pdf' => 'application/pdf',
			'psd' => 'image/vnd.adobe.photoshop',
			'ai' => 'application/postscript',
			'eps' => 'application/postscript',
			'ps' => 'application/postscript',
			'doc' => 'application/msword',
			'rtf' => 'application/rtf',
			'xls' => 'application/vnd.ms-excel',
			'ppt' => 'application/vnd.ms-powerpoint',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'odt' => 'application/vnd.oasis.opendocument.text',
			'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
			'ged' => 'application/x-gedcom',
		);
		$ext = strtolower(array_pop(explode('.',$filename)));
		if (array_key_exists($ext, $mime_types)) {
			return $mime_types[$ext];
		} elseif (function_exists('finfo_open')) {
			$finfo = finfo_open(FILEINFO_MIME);
			$mimetype = finfo_file($finfo, $filename);
			finfo_close($finfo);
			return $mimetype;
		} else {
			return 'application/octet-stream';
		}
	}
}

//Compatibility for WP 2.9
if (!function_exists('get_user_meta')) {
    function get_user_meta ($user_id, $key, $single) {
        return get_metadata('user', $user_id, $key, $single);
    }
}

/* TO DO
========
Get it working with non-pretty permalinks
Find error in cemeteries page
Make it work with the home page
*/
?>