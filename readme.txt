=== Sermons-NL ===
Contributors: henrivanwerkhoven
Donate link:
Tags: church services, kerkdiensten, kerktijden, kerkomroep, youtube
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The plugin brings together church services and broadcasting data from Kerktijden.nl, Kerkomroep.nl and Youtube.com, frequently used by Dutch churches.
== Description ==

This Wordpress plugin brings together planned church services and broadcasting data from different sources that are being used by Dutch churches and presents them as a single overview. Intended to be used on a church's website to display planned and previously broadcasted church services and other events.

Services being used are:

+ Kerktijden.nl: a website where churches can record their scheduled church services
+ Kerkomroep.nl: a website that churches use to audio and video broadcast their church services 
+ Youtube.com: a website that churches use to video broadcast their church services

== Installation ==

You can install the Sermons-NL plugin from the WordPress Dashboard (either by searching the plugin or by uploading the zip file) or copy the source files through the `/wp-content/plugins/` directory via FTP. After installation, activate the plugin.

Setting up the plugin is very easy. Go to Sermons-NL > configuration in the WP dashboard menu and follow the instructions. Then go to Sermons-NL in the WP dashboard and use the shortcode builder. Copy the shortcode on a website page or in a post to display the (planned) broadcasts. You can also display a single event or one broadcasted item, which can be useful in posts about a specific event; you can get the shortcode for this from the Sermons-NL Administration menu. See the frequently asked questions for more tips & trics.

== External services ==

This plugin connects to a total of three APIs to obtain church services and broadcasting data. This is needed to present the planned and broadcasted church services and other events on the website.

The first API, api.kerktijden.nl, contains data from their own church from [Kerktijden](https://www.kerktijden.nl), such as the date and time of the church service and the name of the church service leader. The plugin will send one request to load future church services once every 15 minutes. It will additionally load the historical archive, up to the number of weeks indicated in the plugin's configuation page, once every day so that changes of these data are kept up to date. These data entered by the church themselves and are publicly available. "Kerktijden.nl offers local church communities the opportunity to use the entered data to their local website by means of a widget. [...] Two conditions apply: 1) The logo of kerktijden must remain visible. 2) The link to kerktijden.nl must remain visible." (Translated from (https://www.kerktijden.nl/service/veelgestelde-vragen/).) The plugin will automatically add the Kerktijden logo with url to the church's Kerktijden.nl page when data from this service are shown. This service is provided by Kerktijden: Terms of use: not available, [Privacy policy, in Dutch](https://service.erdee.nl/application/files/4217/3151/1647/Privacyverklaring-EMG-2022-03-29_1.pdf).

The second API, www.kerkomroep.nl, contains data of broadcasts, both video and audio, broadcasting date and time, and potentially additional data such as the name of the church service leader. The plugin will check for life broadcasts every time a person visits the website page containing Sermons-NL data. Additinally it will update the archive once every 15 minutes. The plugin will only be able to retrieve these data when publicly available (a setting in Kerkomroep managed by the church). "The church is free to publish its own church services on its own website. Its archives that are on [the Kerkomroep] servers are and remain [the church's] property. What you do with them and how you implement these services (via a link or an API or Plugin) does not matter." (Personal communication from Kerktijden.) To acknowledge the source and for consistency, the plugin will display the logo and url of Kerkomroep if data from this service is shown. This service is provided by Kerkomroep: Terms of use: not avaiable, [Privacy policy, in Dutch](https://kerkomroep.nl/privacy/).

The third API, www.googleapis.com (YouTube Data API) contains data of video's. The plugin obtains video's from the channel indicated in the settings page. It will load data from the most recent 10 broadcasted or planned videos once every 15 minutes and will obtain the entire archive (up to the number of weeks indicated in the plugin's configuation page) once every day. When a broadcast is planned and approaching, the plugin will request the status once every minute, if there is a site visitor. To acknowledge the source and for consistency, the plugin display the YouTube logo with url to the YouTube channel if data from this service is shown. This service is provided by Google: [Terms of use](https://developers.google.com/youtube/terms/api-services-terms-of-service), [Privacy policy](https://www.youtube.com/howyoutubeworks/privacy/).

For all services, the plugin will only send the settings entered in the plugin's configuration page to the respective service in order to request the required data.

== Frequently Asked Questions ==

= How do I start using the plugin? =

After installing and activating the plugin, a page "Sermons-NL" is added to the main menu of your WP Admin. In the submenu "Configuration" you can enter the details of the services that you want to include. Specific instructions per service are provided there.

= How do I add a list of (planned) broadcasts to my website? =

The plugin Sermons-NL uses shortcodes to add (planned) broadcasts to your website. For a list of (planned) broadcasts, you will find a shortcode builder on the landing page of the plugin, via the main menu of the WP Admin. You can also add individual events or even a single broadcast to your website. To this end, navigate to the Administration submenu, find the relevant church service or item, and click the copy icon for the given shortcode. You can paste the shortcode on a page or in a message.

= We have broadcasted an event that I don't want to include in the list of (planned) broadcasts. How do I do that? =

You can do so by finding the event in the Administration submenu, and unticking the "Include in (planned) broadcasts list" option. Don't forget to press the Save button.

If you want to prevent a future broadcasted event to be included in the (planned) broadcasts list, you can create a new event manually ("Create new event" option in the Administration submenu) and enter the appropriate date and time of the planned event. Untick the "Include in (planned) broadcasts list" option. Note that the "Protect from automated deletion" option should be ticked if you create the manual event entry before the day of the broadcasted event, otherwise you it will be deleted overnight. As soon as the new broadcast is detected, the plugin will link it to this manual event, which will avoid inclusion in the (planned) broadcasts list.

Note that you can include this broadcasted event on your website, for example in a news message, by using the shortcode for events that you find in the Administration page.

= The automatic linkage of items from different services has gone wrong. What should I do? =

This sometimes happens, e.g. if the broadcasting is started much earlier so that linking it to the planned event is not unambiguous, or if multiple broadcasts of the same type are detected, for example if the broadcast service has been interrupted. It is easy to fix afterwards. Go to the Administration submenu and find the event  that has this error. You can unlink the item that was not correctly linked (it will end up under the \"Unlinked items\") or directly link it to another event. If the event has no other linked items, you can now delete it. Go to the unlinked items if you want to link them to another event. Only items with the same date can be linked.

= Why does Sermons-NL not support Kerkdienst Gemist? =

Kerkdienst Gemist is a service similar to Kerkomroep. Currently, only Kerkomroep is included, because the church for which the plugin was first developed uses that service. However, adding support for Kerkdienst Gemist is possible if a church using Kerkdienst Gemist is willing to assist in testing and debugging. In that case, please visit the issue page and add your reaction or send an e-mail to the developer.

= Wordpress is occasionally responding very slow since I am using Sermons-NL. What can I do about it? =

Please check if you are using cron jobs. Sermons-NL will regularly update data in the background. This can slow down your website. To optimize performance, check if your hosting server allows you to use cron jobs.  The recommended frequency of cron jobs for this Plugin is once every 15 minutes. Please check for example [this instruction to disable cron in wordpress](https://www.wpbeginner.com/wp-tutorials/how-to-disable-wp-cron-in-wordpress-and-set-up-proper-cron-jobs) for instruction. If you are already using cron jobs and it is correctly configured, it is unlikely that the Sermons-NL plugin is slowing down your website.

= I get [Sermons-NL invalid shortcode] on my site where a Sermons-NL shortcode was used =

Shortcodes for standalone items may produce the error [invalid shortcode] for two reasons. If the error mentions (duplication), this means that you have multiple standalone items on the page, one of them is a duplication. The plugin doesn't allow you to include the same item or event twice on the same page as this will cause conflicts. A second possible explanation is that the standalone event or item that you have included does not exist (any more). Check the Administration submenu in your WP Admin for the correct shortcode.

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

= 1.3 =
* Added feature: Allow to customize the time interval allowed to link items together in one event.
* Improved language: In particular that "sermons" is replaced by "church service", "broadcast" or "event" as appropriate.
* Bug fixed: When kerkomroep broadcast is ended and it is already archived, the live broadcast is first deleted from memory before adding the item from the archive, to avoid that a new event is created.
* Bug fixed: Type-casting of the feature "is_live" to integer resolved an error.
* Bug fixed: When planned youtube broadcasts are relocated to another event, no error occurs due to the lack of an end date.

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

= 1.3 =
Small feature, improved language and some bug fixes
