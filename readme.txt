=== Plugin Name ===
Contributors: mark8barnes
Donate link: http://www.4-14.org.uk/wordpress-plugins/donate
Tags: tng, the next generation, genealogy, integration, bridge, family history
Requires at least: 2.5
Tested up to: 2.7.1
Stable tag: trunk

Integrates TNG (The Next Generation) genealogy software into Wordpress.

== Description ==

[The Next Generation](http://lythgoes.net/genealogy/software.php) is a powerful PHP/MySQL script that acts as a central repository for all your genealogy research, and allows others to view and search through your records. This plugin integrates TNG with your Wordpress site. This plugin is free, but to use it you need to purchase TNG (currently $30).

#### Features ####

1. Displays TNG within Wordpress with no iFrames (great for SEO!), on whichever page you choose.
2. Requires no mods, and overwrites no core files. Just upload the plugin to your Wordpress plugins folder.
3. Moves the TNG menu into Wordpress sidebar, and optionally adds a search box there, too.
4. Optionally add a template switcher so every user can choose the TNG template they like best.
5. Optionally integrates Wordpress and TNG sign-on - effectively merges TNG and Wordpress users.
6. Can be used with any TNG theme, and any variable width Wordpress theme.
7. TNG and Wordpress are kept in separate folders for easy upgrading.
8. TNG and Wordpress can share the same database, or you can keep them separate.
9. Should be compatible with custom themes and mods.

#### Demo ####

You can view a demo at [Family and Local History](http://test.familyandlocalhistory.com/tng/).

#### Limitations ####

1. This plugin is still in beta. Some things may not work. Do not install on your main site, nor with your only copy of your data.
2. 'Pretty' permalinks must be turned on.
3. It may not work with IIS (Windows server).

== Installation ==

1. Upload TNG to your server if you’ve not already done so, and enter the database connection details in config.php. You’ll probably also need to change $rootpath to point to the folder you installed TNG in.
2. Create a blank page on your Wordpress site where you want TNG to be displayed. Unfortunately, this cannot be your home page.
3. Copy the tng folder (from the plugin .zip file) into your Wordpress plugins folder (or use the install plugin option in Wordpress 2.7).
4. Chose options from the new TNG menu that appears, and set the path to your TNG installation, and confirm the page you want TNG to be displayed on.
5. Add the TNG widgets at the top of your sidebar. They’ll only be displayed on your TNG page.
6. That’s it (hopefully!)

== Screenshots ==

1. TNG template 2, with the Wordpress [Fluid Blue](http://srinig.com/wordpress/themes/fluid-blue/) theme.
2. TNG template 4, with the Wordpress classic theme.