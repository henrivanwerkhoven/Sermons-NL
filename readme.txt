=== Sermons-NL ===
Contributors: henrivanwerkhoven
Donate link:
Tags: church services, kerkdiensten, kerktijden, kerkomroep, youtube
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

This plugin brings together and nicely presents sermon data from Kerktijden.nl, Kerkomroep.nl and Youtube.com, frequently used by Dutch churches.

== Description ==

This Wordpress plugin brings together church sermon data from different sources that are being used by Dutch churches and presents them as a single overview. Intended to be used on a church's website to display planned and previously broadcasted sermons.

Services being used are:

+ Kerktijden.nl: a website where churches can record their scheduled church services
+ Kerkomroep.nl: a website that churches use to audio and video broadcast their church services 
+ Youtube.com: a website that churches use to video broadcast their church services

== Installation ==

You can install the Sermons-NL plugin from the WordPress Dashboard (either by searching the plugin or by uploading the zip file) or copy the source files through the `/wp-content/plugins/` directory via FTP. After installation, activate the plugin.

Setting up the plugin is very easy. Go to Sermons-NL > configuration in the WP dashboard menu and follow the instructions. Then go to Sermons-NL in the WP dashboard and use the shortcode builder. Copy the shortcode on a website page or in a post to display the sermons. You can also display a single sermon or one broadcasted item, which can be useful in posts about a specific event; you can get the shortcode for this from the Sermons-NL Administration menu. See the frequently asked questions for more tips & trics.

== External services ==

This plugin connects to a total of three APIs to obtain sermon data. This is needed to present the planned and broadcasted sermons on the website.

The first API, api.kerktijden.nl, contains data from their own church from [Kerktijden](https://www.kerktijden.nl), such as the date and time of the sermon and the name of the church service leader. The plugin will send one request to load future sermons once every 15 minutes. It will additionally load the historical archive, up to the number of weeks indicated in the plugin's configuation page, once every day so that changes of these data are kept up to date. These data entered by the church themselves and are publicly available. "Kerktijden.nl offers local church communities the opportunity to use the entered data to their local website by means of a widget. [...] Two conditions apply: 1) The logo of kerktijden must remain visible. 2) The link to kerktijden.nl must remain visible." (Translated from (https://www.kerktijden.nl/service/veelgestelde-vragen/).) The plugin will automatically add the Kerktijden logo with url to the church's Kerktijden.nl page when data from this service are shown. This service is provided by Kerktijden: Terms of use: not available, [Privacy policy, in Dutch](https://service.erdee.nl/application/files/4217/3151/1647/Privacyverklaring-EMG-2022-03-29_1.pdf).

The second API, www.kerkomroep.nl, contains data of broadcasted sermons, both video and audio, broadcasting date and time, and potentially additional data such as the name of the church service leader. The plugin will check for life broadcasts every time a person visits the website page containing Sermons-NL data. Additinally it will update the archive once every 15 minutes. The plugin will only be able to retrieve these data when publicly available (a setting in Kerkomroep managed by the church). "The church is free to publish its own church services on its own website. Its archives that are on [the Kerkomroep] servers are and remain [the church's] property. What you do with them and how you implement these services (via a link or an API or Plugin) does not matter." (Personal communication from Kerktijden.) To acknowledge the source and for consistency, the plugin will display the logo and url of Kerkomroep if data from this service is shown. This service is provided by Kerkomroep: Terms of use: not avaiable, [Privacy policy, in Dutch](https://kerkomroep.nl/privacy/).

The third API, www.googleapis.com (YouTube Data API) contains data of video's. The plugin obtains video's from the channel indicated in the settings page. It will load data from the most recent 10 broadcasted or planned videos once every 15 minutes and will obtain the entire archive (up to the number of weeks indicated in the plugin's configuation page) once every day. When a broadcast is planned and approaching, the plugin will request the status once every minute, if there is a site visitor. To acknowledge the source and for consistency, the plugin display the YouTube logo with url to the YouTube channel if data from this service is shown. This service is provided by Google: [Terms of use](https://developers.google.com/youtube/terms/api-services-terms-of-service), [Privacy policy](https://www.youtube.com/howyoutubeworks/privacy/).

For all services, the plugin will only send the settings entered in the plugin's configuration page to the respective service in order to request the required data.

== Frequently Asked Questions ==

= How do I start using the plugin? =

After installing and activating the plugin, a page "Sermons-NL" is added to the main menu of your WP Admin. In the submenu "Configuration" you can enter the details of the services that you want to include. Specific instructions per service are provided there.

= How do I add a list of sermons to my website? =

The plugin Sermons-NL uses shortcodes to add sermons to your website. For a complete list of sermons, you will find a shortcode builder on the landing page of the plugin, accessible via the main menu of the WP Admin. You can also add individual sermons or even separate broadcasts to your website. For this, navigate to the Administration submenu, find the relevant sermon or item, and click the copy icon for the shortcode. You can paste the shortcode on your page or in your message.

= We have broadcasted an event, but I don't want it to be listed under the sermons =

You can do so by finding the event in the Administration submenu, and unticking the "Include in sermons list" option. Don't forget to press the Save button.

If you want to prevent a planned broadcast to be listed under the sermons, you can create a new event manually ("Create new event" option in the Administration submenu) and enter the  date and time of the planned broadcast. Untick the "Inclde in sermons list" option. Note that the "Protect from automated deletion" option should be on, especially if you create the manual event entry before the day of the broadcast, or else you will loose it overnight. As soon as the new broadcast is detected, the plugin will link it to this manual event and will avoid the creation of a new one.

Note that you can include this broadcast on your website, for example in a news message, by using the event shortcode that you find in the Administration page.

= The automatic linkage of items from different services has gone wrong. What should I do? =

This sometimes happens, e.g. if the broadcasting is started much earlier so that linking it to the planned sermon is not unambiguous. It is easy to fix afterwards. Go to the Administration submenu and find the sermon that has this error. You can first unlink the item that was not correctly linked. It will end up under the "Unlinked items". If the sermons has no other linked items, you can now delete it. Next, go to the unlinked items and link it to another sermon. Only sermons with the same date can be linked.

= Why does Sermons-NL not support Kerkdienst Gemist? =

Kerkdienst Gemist is a service similar to Kerkomroep. Currently, only Kerkomroep is included, because the church for which the plugin was first developed uses that service. However, adding support for Kerkdienst Gemist is possible, I would really like to add it in one of the next releases. I welcome volunteers to test this functionality in a beta version if their church is using Kerkdienst Gemist and if they would love to use this plugin. For this, please visit the issue page and add your reaction or send an e-mail to the developer.

= Wordpress is occasionally responding very slow since I am using Sermons-NL. What can I do about it? =

Please check if you are using cron jobs. Sermons-NL will regularly update data in the background. This can slow down your website. To optimize performance, check if your hosting server allows you to use cron jobs.  The recommended frequency of cron jobs for this Plugin is once every 15 minutes. Please check for example [this instruction to disable cron in wordpress](https://www.wpbeginner.com/wp-tutorials/how-to-disable-wp-cron-in-wordpress-and-set-up-proper-cron-jobs) for instruction. If you are already using cron jobs and it is correctly configured, it is unlikely that the Sermons-NL plugin is slowing down your website.

= I get [Sermons-NL invalid shortcode] on my site where a Sermons-NL shortcode was used =

Shortcodes for standalone items may produce the error [invalid shortcode] for two reasons. If the error mentions (duplication), this means that you have multiple standalone items on the page, one of them is a duplication. The plugin doesn't allow you to include a standalone sermon and also a standalone item from the same sermon on one page as this will cause conflicts. A second possible explanation is that the standalone sermon or item that you have included does not exist (any more). Check the Administration submenu in your WP Admin for the correct shortcode.

= I encounter another problem with my plugin, what can I do to fix it? =

Please visit the Log submenu in your WP Admin first to see if you can identify the reason for your problem. Check the settings if the Log indicates errors when obtaining data. If you are not able to fix the problem, please report it on the issue page of the plugin or e-mail to the developer while including as much detail as possible.

== Screenshots ==

1. Site-embedded list of sermons while one is live broadcasting. Clicking one of these links will play the media (audio or video).
2. Site-embedded list of sermons, including earlier audio and video broadcasted services, a planned youtube broadcast, and future sermons.
3. In the administrations page on the WordPress Dashboard, sermons can be adjusted individually when needed. You can also find shortcodes to add individual sermons or broadcasts to your website.
4. There is a shortcode creator in the WordPress Dashboard that helps you embed the list of sermons on your website according to your wishes.

== Changelog ==

= 0.1 =
* First release.

= 0.2 =
* Use of _POST and _GET input optimized
* Bug fix: page refresh required after resolving issue in plugin dashboard
* Bug fix: kerkomroep live broadcast can be deleted when no longer broadcasting

= 0.3 =
* Use of Wordpress build-in http api
* Proper use of inline script
* Removing dependency on external script
* Added documentation of external services

= 1.0 =
* Stable version
* Fixes opening of unlinked items screen
* Improved consistency of Dutch translation

= 1.1 =
* Added plugin settings for color of the audio/video icons

= 1.2 =
* Linking multiple items of the same type to an event is disabled
* Option to link an item from one event directly to another or new event is added
* Error in shortcode builder is fixed

== Upgrade Notice ==

= 0.1 =
First release for review by Wordpress team.

= 0.2 =
Optimization following automated plugin review by Wordpress, and two bugs were fixed.

= 0.3 =
Optimization following manual plugin review by Wordpress.

= 1.0 =
Official first stable version

= 1.1 =
Audio/video icon color settings

= 1.2 =
Several small improvements
