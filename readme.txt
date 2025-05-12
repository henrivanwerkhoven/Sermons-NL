=== Sermons-NL ===
Contributors: henrivanwerkhoven
Donate link:
Tags: church services, kerkdiensten, kerktijden, kerkomroep, youtube
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 0.2
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

Setting up the plugin is quite easy. After installation and activation, go to Sermons-NL > configuration in the WP dashboard and follow the instructions. Then go to Sermons-NL in the WP dashboard and use the shortcode builder. Copy the shortcode on a website page or in a post to display the sermons. You can also display a single sermon or one broadcasted item, which can be useful in posts about a specific event; get the shortcode for this from the administration menu.

Kerktijden allows church websites to retrieve data from their own church from kerktijden.nl in order to publish the on their own website, provided that (1) the logo of kerktijden must be visible and (2) the url to kerktijden.nl must be visible. The plugin will automatically add an logo with url when data from kerktijden is presented. (See https://www.kerktijden.nl/service/veelgestelde-vragen/ - in Dutch, accessed 09-May-2025.)

Kerkomroep also allows churches to present their own broadcasted sermons on their website provided that Kerkomroep is acknowledged. For consistency, the same approach is chosen to display the logo and url when kerkomroep data is shown.

For YouTube broadcasts, the YouTube Data API is used, see https://developers.google.com/youtube/v3/docs for documentation. Although formally not needed, for consistency the plugin takes the same approach with displaying the logo and url to acknowledge the use of this source.

The website administrator has the option to enable the Sermons-NL logo, which will then be shown next to the other logo(s). By default this is switched off.

If you have any suggestions for improvement, please feel free to visit the plugin website and post an issue.

== Frequently Asked Questions ==

= How do I start using the plugin? =

After installing the plugin, an item "Sermons-NL" is added to the main menu of your WP Admin. In the submenu "Configuration" you can enter the details of the services that you want to include. Specific instructions per service are provided there.

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

Please check if you are using cron jobs. Sermons-NL will regularly update data in the background. This can slow down your website. To optimize performance, check if your hosting server allows you to use cron jobs.  The recommended frequency of cron jobs is once every 15 minutes. Please refer to https://www.wpbeginner.com/wp-tutorials/how-to-disable-wp-cron-in-wordpress-and-set-up-proper-cron-jobs for instruction. If you are already using cron jobs and it is correctly configured, it is unlikely that Sermons-NL is slowing down your website.

= I get [Sermons-NL invalid shortcode] on my site where a Sermons-NL shortcode was used =

Shortcodes for standalone items may produce the error [invalid shortcode] for two reasons. If it mentions (duplication), this means that you have multiple standalone items on the page, one of them is a duplication. The plugin doesn't allow you to include a sermon and one of the items from the sermon on the same page. A second possible explanation is that the items that you have included does not exist (any more). Check the Administration submenu in your WP Admin for the correct shortcode.

= I encounter another problems with my plugin, what can I do to fix it? =

Please visit the Log submenu in your WP Admin first to see if you can identify the reason for your problems and fix it yourself. If you are not able to fix the problem, please report it on the issue page of the plugin or send an e-mail to the developer.

== Screenshots ==



== Changelog ==

= 0.1 =
* First release.

= 0.2 =
* Use of _POST and _GET input optimized
* Bug fix: page refresh required after resolving issue in plugin dashboard
* Bug fix: kerkomroep live broadcast can be deleted when no longer broadcasting

== Upgrade Notice ==

= 0.1 =
First release for review by Wordpress team.

= 0.2 =
Optimization following automated plugin review by Wordpress, and two bugs were fixed.
