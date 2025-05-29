<?php

/*
	Plugin Name: Sermons-NL
	Plugin URI: 
	Description: List planned and broadcasted Dutch church services in a convenient way
	Version: 1.0
	Author: Henri van Werkhoven
	Author URI: https://profiles.wordpress.org/henrivanwerkhoven/
	Plugin URI: https://wordpress.org/plugins/sermons-nl/
	License: GPL2
	Text Domain: sermons-nl
	Domain Path: /languages
*/

if(!defined('ABSPATH')) exit; // Exit if accessed directly

class sermons_nl{

    const PLUGIN_URL = "https://wordpress.org/plugins/sermons-nl/";
    const LOG_RETENTION_DAYS = 30; // how many days to keep the log items
    const INVALID_SHORTCODE_TEXT = '<div>[Sermons-NL invalid shortcode]</div>';
    const CHECK_INTERVAL = 60; /* check for live broadcasts each x seconds with json query; this might become a setting later */


    // SETTINGS
    // timezones are defined at the end of this file
    public static $timezone_db = null;
    public static $timezone_ko = null;
    public static $timezone_kt = null;
    // youtube includes the timezone ('Z') in the data, so no timezone indicator is needed

    // make sure all setting names are included in this array here to ensure 
    // they get deleted upon plugin deinstallation
    const OPTION_NAMES = array(
        "sermons_nl_kerktijden_id"                  => array('type' => 'integer', 'default' => null),
        "sermons_nl_kerktijden_weeksback"           => array('type' => 'integer', 'default' => 52),
        "sermons_nl_kerktijden_weeksahead"          => array('type' => 'integer', 'default' => 52),
        "sermons_nl_kerkomroep_mountpoint"          => array('type' => 'integer', 'default' => null),
        "sermons_nl_youtube_channel"                => array('type' => 'string',  'default' => null),
        "sermons_nl_youtube_key"                    => array('type' => 'string',  'default' => null),
        "sermons_nl_youtube_weeksback"              => array('type' => 'integer', 'default' => 52),
        "sermons_nl_last_update_time"               => array('type' => 'integer', 'default' => 0),
        "sermons_nl_icon_color_archive"             => array('type' => 'string',  'default' => '#000000'),
        "sermons_nl_icon_color_planned"             => array('type' => 'string',  'default' => '#8c8c8c'),
        "sermons_nl_icon_color_live"                => array('type' => 'string',  'default' => '#0000ff')
    );

	public static function register_settings(){
		foreach(self::OPTION_NAMES as $optname => $args){
			register_setting('sermons_nl_options_group', $optname, $args);
		}
		// add dummy translations for the header
		$dummy_plugin_name = __("Sermons-NL", "sermons-nl");
		$dummy_description = __("List planned and broadcasted Dutch church services in a convenient way", "sermons-nl");
	}
	
	// access rights
	// in future possibly make this a setting and/or make a plugin-specific user capability string
	private static $capability = 'manage_options'; 
	
	// functions to load complete linked records
	// used mainly by shortcode functions to quickly obtain required data
	
	private static function get_complete_records_by_ids($ids, bool $include_non_included=false){
	    if(empty($ids)) return array();
	    if(!is_array($ids)) $ids = array($ids);
	    $where = implode(" OR ", array_map(function($id){return "id=".(int)$id;}, $ids));
	    return self::get_complete_records($where, $include_non_included);
	}
	
    private static function get_complete_records_by_dates(?string $date_offset, ?string $date_ending = null, bool $include_date = false, bool $include_non_included=false){
	    if($date_offset !== null || $date_ending !== null){
	        $where = "";
	        if($date_offset !== null) $where .= " n.dt_start > '$date_offset " . ($include_date ? "00:00:00" : "23:59:59") . "'";
	        if($date_offset !== null && $date_ending !== null) $where .= " AND";
	        if($date_ending !== null) $where .= " n.dt_start < '$date_ending " . ($include_date ? "23:59:59" : "00:00:00") . "'";
	    }else{
	        $where = null;
	    }
	    return self::get_complete_records($where, $include_non_included);
	}
	
	private static function get_complete_records_by_live(){
	    return self::get_complete_records("yt_live=1 OR ko_live=1");
	}
	
	private static function get_complete_records_by_planned(){
	    return self::get_complete_records("yt_planned=1");
	}
	
	// this function will obtain events that have no linked items and that are not protected
	// it is run by one of the update_xxx functions from this class - they will then be deleted
	private static function get_complete_redundant_records(){
	    return self::get_complete_records("kt_id IS NULL AND ko_id IS NULL AND yt_video_id IS NULL AND protected=0");
	}
	
	private static function get_complete_records(?string $where = null, bool $include_non_included=false){
	    // get data from the events with all relevant linked information included 
	    global $wpdb;
	    $sql = "SELECT 
	        e.id, 
	        e.include, 
	        e.protected,
	        (case when dt_from='manual' AND e.dt_manual IS NOT NULL then e.dt_manual
                  when dt_from='kerktijden' AND kt.dt IS NOT NULL then kt.dt
                  when dt_from='kerkomroep' AND ko.dt IS NOT NULL then ko.dt
                  when dt_from='youtube' AND yt.dt_planned IS NOT NULL then yt.dt_planned
                  when dt_from='youtube' AND yt.dt_actual IS NOT NULL then yt.dt_actual
                  when kt.dt IS NOT NULL then kt.dt
                  when yt.dt_planned IS NOT NULL then yt.dt_planned
                  when ko.dt IS NOT NULL then ko.dt
                  when yt.dt_actual IS NOT NULL then yt.dt_actual
                  else e.dt_min
                  end) as dt_start,
            (case when sermontype_from='manual' then e.sermontype_manual
                  when sermontype_from='kerktijden' then kt.sermontype
                  else kt.sermontype
                  end) as sermontype,
            (case when pastor_from='manual' then e.pastor_manual
                  when pastor_from='kerktijden' then ktp.pastor
                  when pastor_from='kerkomroep' then ko.pastor
                  when ktp.pastor IS NOT NULL then ktp.pastor
                  else ko.pastor
                  end) as pastor,
            (case when description_from='manual' then e.description_manual
                  when description_from='youtube' then yt.description
                  when description_from='kerkomroep' then ko.description
                  when yt.description IS NOT NULL then yt.description
                  else ko.description
                  end) as description,
    	    kt.id AS kt_id, 
    	    kt.cancelled AS kt_cancelled,
    	    ko.id AS ko_id,
    	    ko.audio_url AS ko_audio_url,
    	    ko.audio_mimetype AS ko_audio_mimetype,
    	    ko.video_url AS ko_video_url,
    	    ko.video_mimetype AS ko_video_mimetype,
    	    ko.live AS ko_live,
    	    yt.id AS yt_id,
    	    yt.video_id AS yt_video_id,
    	    yt.planned AS yt_planned,
    	    yt.dt_actual AS yt_dt_actual,
    	    yt.live AS yt_live
	    FROM {$wpdb->prefix}sermons_nl_events AS e
	    LEFT JOIN {$wpdb->prefix}sermons_nl_kerktijden AS kt ON e.id = kt.event_id
	    LEFT JOIN {$wpdb->prefix}sermons_nl_kerktijdenpastors AS ktp ON ktp.id = kt.pastor_id
	    LEFT JOIN {$wpdb->prefix}sermons_nl_kerkomroep AS ko ON e.id = ko.event_id
	    LEFT JOIN {$wpdb->prefix}sermons_nl_youtube AS yt ON e.id = yt.event_id";
	    if(!$include_non_included){
    	    $sql .= "
    	    WHERE e.include = 1";
	    }
	    
	    if($where !== null){
			// proper escaping of $where should be handled by the calling function
	        $sql = "SELECT n.* FROM ($sql) AS n WHERE $where";
	    }
	    
	    $sql .= "
	    ORDER BY dt_start ASC";
	    
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$data = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql
		);
	    
	    return $data;

	}
	
	private static function num_complete_records_from_date(string $dt, string $direction, bool $include_non_included=false){
	    global $wpdb;
	    $sql = "SELECT count(n.id) as nrec FROM (
	    SELECT e.id, 
	        (case when dt_from='manual' then e.dt_manual
                  when dt_from='kerktijden' then kt.dt
                  when dt_from='kerkomroep' then ko.dt
                  when dt_from='youtube' AND yt.dt_planned IS NOT NULL then yt.dt_planned
                  when dt_from='youtube' AND yt.dt_actual IS NOT NULL then yt.dt_actual
                  when kt.dt IS NOT NULL then kt.dt
                  when yt.dt_planned IS NOT NULL then yt.dt_planned
                  when ko.dt IS NOT NULL then ko.dt
                  when yt.dt_actual IS NOT NULL then yt.dt_actual
                  else e.dt_min
                  end) as dt_start
        FROM {$wpdb->prefix}sermons_nl_events AS e
	    LEFT JOIN {$wpdb->prefix}sermons_nl_kerktijden AS kt ON e.id = kt.event_id
	    LEFT JOIN {$wpdb->prefix}sermons_nl_kerktijdenpastors AS ktp ON ktp.id = kt.pastor_id
	    LEFT JOIN {$wpdb->prefix}sermons_nl_kerkomroep AS ko ON e.id = ko.event_id
	    LEFT JOIN {$wpdb->prefix}sermons_nl_youtube AS yt ON e.id = yt.event_id";
	    if(!$include_non_included){
    	    $sql .= "
    	    WHERE e.include=1";
	    }
	    $sql .= "
	    ) as n WHERE n.dt_start " . ($direction == 'up' ? '<' : '>') . " '" . esc_sql($dt) . "'";
	    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$res = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql
		);
	    return (int)$res[0]->nrec;
	}
	
	private static function get_events_with_issues(){
	    // get ids from the events that have multiple items of the same type
	    global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$data = $wpdb->get_results("SELECT * FROM (
			SELECT
			e.id,
			count(e.id) as n_rec,
			count(DISTINCT(kt.id)) as n_kt,
			count(DISTINCT(ko.id)) as n_ko,
			count(DISTINCT(yt.id)) as n_yt,
			(case when dt_from='manual' then e.dt_manual
				when dt_from='kerktijden' then kt.dt
				when dt_from='kerkomroep' then ko.dt
				when dt_from='youtube' AND yt.dt_planned IS NOT NULL then yt.dt_planned
				when dt_from='youtube' AND yt.dt_actual IS NOT NULL then yt.dt_actual
				when kt.dt IS NOT NULL then kt.dt
				when yt.dt_planned IS NOT NULL then yt.dt_planned
				when ko.dt IS NOT NULL then ko.dt
				when yt.dt_actual IS NOT NULL then yt.dt_actual
				else e.dt_min
				end) as dt_start
			FROM {$wpdb->prefix}sermons_nl_events AS e
			LEFT JOIN {$wpdb->prefix}sermons_nl_kerktijden AS kt ON e.id = kt.event_id
			LEFT JOIN {$wpdb->prefix}sermons_nl_kerkomroep AS ko ON e.id = ko.event_id
			LEFT JOIN {$wpdb->prefix}sermons_nl_youtube AS yt ON e.id = yt.event_id
			GROUP BY e.id
		) as e
		WHERE n_rec > 1");
	    
	    return $data;
	}
	
	
	
	// ITEM FUNCTIONS
	// i.e. kerktijden, kerkomroep, youtube

    // get all items that are not linked to an event
	private static function get_unlinked_items($sort=true){
	    global $wpdb;
	    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$kt = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermons_nl_kerktijden WHERE event_id IS NULL ORDER BY dt");
	    $kt = array_map(function($item){$item->item_type = "kerktijden"; return $item;}, $kt);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ko = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermons_nl_kerkomroep WHERE event_id IS NULL ORDER BY dt");
	    $ko = array_map(function($item){$item->item_type = "kerkomroep"; return $item;}, $ko);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$yt = $wpdb->get_results("SELECT y.*,
								 (case when dt_planned IS NOT NULL then dt_planned else dt_actual end) as dt
								 FROM {$wpdb->prefix}sermons_nl_youtube as y WHERE event_id IS NULL ORDER BY dt");
	    $yt = array_map(function($item){$item->item_type = "youtube"; return $item;}, $yt);
	    $all = array_merge($kt, $ko, $yt);
	    if($sort){
	        usort($all, function($item1, $item2){return strtotime($item1->dt) - strtotime($item2->dt);});
	    }
	    return $all;
	}
	
	// get the number of items that are not linked to an event
	private static function num_unlinked_items(){
	    $items = self::get_unlinked_items(false);
	    return count($items);
	}
	
	// get a single item by the type and id
	public static function get_item_by_type(string $type, int $id){
	    switch($type){
	        case 'kerktijden':
	            return sermons_nl_kerktijden::get_by_id($id);
	        case 'kerkomroep':
	            return sermons_nl_kerkomroep::get_by_id($id);
	        case 'youtube':
	            return sermons_nl_youtube::get_by_id($id);
	        default:
	            wp_trigger_error(__CLASS__."::get_item_by_type", "Wrong type '$type' parsed.", E_USER_ERROR);
	            return null;
	    }
	}


	// this can be called for youtube and kerktijden to load the archive in the background when the
	// settings have changed, because these processes are slow and we don't want the user to wait for it
	public static function get_remote_data_in_background(){

		// check that this is called in the background
		check_ajax_referer('sermons-nl-background-action');

		/*
		 * this process runs in the background without impacting the user experience, and it should not terminate early.
		 * ignore_user_abort and set_time_limit are set to avoid termination of the script.
		 * Warning 'The use of function set_time_limit() is discouraged': OK.
		 */
		ignore_user_abort();
		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
		set_time_limit(999);

		$done = array();

		if(isset($_GET['resources'])){
			$resources = explode(",", sanitize_text_field(filter_input(INPUT_GET, 'resources')));

			// load kerktijden archive
			if(array_search('kt', $resources) !== false){
				sermons_nl_kerktijden::get_remote_data_forward();
				sermons_nl_kerktijden::get_remote_data_backward();
				$done[] = 'kt';
			}

			// load kerkomroep archive
			if(array_search('ko', $resources) !== false){
				sermons_nl_kerkomroep::get_remote_data();
				$done[] = 'ko';
			}

			// load youtube archive
			if(array_search('yt', $resources) !== false){
				sermons_nl_youtube::get_remote_data();
				$done[] = 'yt';
			}
		}

		print wp_json_encode(array('action' => 'sermons_nl_get_remote_data_in_background', 'done' => $done));
		wp_die();
	}

    // ADMIN PAGE HANDLING
    public static function add_admin_menu(){
        $num_issues = count(self::get_events_with_issues());
        $tag_issue = ($num_issues ? ' <span class="awaiting-mod">'.$num_issues.'</span>' : '');
        add_menu_page(__('Sermons-NL plugin', 'sermons-nl'), __('Sermons-NL','sermons-nl') . $tag_issue, self::$capability, 'sermons-nl', array('sermons_nl','admin_overview_page'), 'dashicons-admin-media', 7);
        add_submenu_page('sermons-nl', __('Sermons-NL administration','sermons-nl'), __('Administration','sermons-nl'), self::$capability, 'sermons-nl-admin', array('sermons_nl','admin_administration_page'));
	    add_submenu_page('sermons-nl', __('Sermons-NL configuration','sermons-nl'), __('Configuration','sermons-nl'), self::$capability, 'sermons-nl-config', array('sermons_nl','admin_edit_config_page'));
	    add_submenu_page('sermons-nl', __('Sermons-NL log','sermons-nl'), __('Log','sermons-nl'), self::$capability, 'sermons-nl-log', array('sermons_nl','admin_view_log_page'));
	}

	public static function add_admin_scripts_and_styles($hook){
	    if(strpos($hook,'sermons-nl') === false) return;
	    wp_enqueue_style('sermons-nl', plugin_dir_url(__FILE__).'css/admin.css', array(), '0.3');
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_script('sermons-nl', plugin_dir_url(__FILE__) . 'js/admin.js', array('jquery','wp-color-picker'), '0.3', true);
		wp_add_inline_script('sermons-nl', 'sermons_nl_admin.admin_url = "' . esc_url(admin_url('admin-ajax.php')) . '";sermons_nl_admin.nonce = "' . esc_attr(wp_create_nonce('sermons-nl-administration')) . '";');
	}
	
    public static function admin_overview_page(){
        // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}

		global $wpdb;
		
		$events = sermons_nl_event::get_all();
		$kerktijden = sermons_nl_kerktijden::get_all();
		$kerkomroep = sermons_nl_kerkomroep::get_all();
		$youtube = sermons_nl_youtube::get_all();
		
        $issues = self::get_events_with_issues();

		$cron_msg = (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$log_dt = $wpdb->get_results("SELECT max(dt) AS dt FROM {$wpdb->prefix}sermons_nl_log");
		$log_time = time() - (new DateTime($log_dt[0]->dt, self::$timezone_db))->getTimestamp();
		$cron_fail = ($log_time > 24 * 3600);

		print '
		<div class="sermons-nl-overview">
		    <h2>
		        ' . esc_html__("Sermons-NL overview page","sermons-nl") . '
	            <img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/waiting.gif" id="sermons_nl_waiting"/><!-- icon freely available at https://icons8.com/preloaders/en/circular/floating-rays/ -->
    	    </h2>
		    <div class="sermons-nl-container">
		        <h3>' . esc_html__('Status','sermons-nl') . ':</h3>
		        <div>
		            <p>' . 
                    sprintf(
						/* translators: %d is replaced by the number of sermons/events. */
						esc_html__('Your site includes a total of %d sermons.','sermons-nl'), count($events)
					) .
                    ' ' .
                    sprintf(
						/* translators: %1$d, %2$d and %3$d are replaced by the number of items from Kerktijden, Kerkomroep and YouTube, respectively. */
						esc_html__('These are based on %1$d entries from Kerktijden, %2$d from Kerkomroep, and %3$d from Youtube.', 'sermons-nl'),
						count($kerktijden), count($kerkomroep), count($youtube)
					) .
                    '</p>';
		if($cron_msg){
			print '
					<p>' .
				sprintf(
					/* translators: %1$s and %2$s are replace by opening and closing tags for bold text. %3$s and %4$s are replaced by opening and closing tags for url to instruction for setting cron jobs. */
					esc_html__('%1$sNote: It is recommended to disable WordPress cron.%2$s Sermons-NL will regularly update data in the background. This can slow down your website. To optimize performance, check if your hosting server allows you to use cron jobs. The recommended frequency of cron jobs is once every 15 minutes. %3$s Please refer to this instruction. %4$s','sermons-nl'),
					'<strong>',
					'</strong>',
					'<a href="https://www.wpbeginner.com/wp-tutorials/how-to-disable-wp-cron-in-wordpress-and-set-up-proper-cron-jobs/" target="_blank">',
					'</a>'
				) .
				'</p>';
		}elseif($cron_fail){
			print '
					<p>' .
/* translators: 1: opening tag for bold text, 2: time in hours since the last log was saved, 3: closing tag for bold text, 4: opening tag for url, 5: closing tag for url. Please don't switch order of the url tags. */
					sprintf(esc_html__('%1$sNote: the last check for updates is %$2d hours ago. It seems that the cron job is not correctly configured.%3$s %4$sPlease refer to this instruction. %5$s', 'sermons-nl'), '<strong>', esc_html(round($log_time / 3600)), '</strong>', '<a href="https://www.wpbeginner.com/wp-tutorials/how-to-disable-wp-cron-in-wordpress-and-set-up-proper-cron-jobs/" target="_blank">', '</a>') .
					'</p>';
		}
		print '
                    <p>';
        if(!empty($issues)){
/* translators: number of issues detected. */
            print '<strong>' . sprintf(esc_html__("There are %d issues that require your attention.", 'sermons-nl'), count($issues)) . '</strong>';
        }
        if(empty($issues) && !$cron_msg && !$cron_fail){
            print esc_html__("There are currently no issues to resolve.","sermons-nl");
        }
        print '</p>
	            </div>';

        if(count($issues)){
    		print '
    		    <h3>' .
    		    /* translators: number of issues detected. */
    		    sprintf(esc_html__("Resolve %d issues","sermons-nl"), count($issues)) . ':</h3>
    		    <div>
            		<p>
            		    ' . esc_html__("These events have more than one items of the same type. Open the event to unlink one of the items. You can find the unlinked items in the administration tab if you want to create a new event from this item.","sermons-nl") . '
    		        </p>
    		        <p>
                		<table id="sermons_nl_issues_table">'; //  class="wp-list-table widefat fixed striped pages"
        	print '
                		    <tr>
            	    	        <th>' . esc_html__('Date / time','sermons-nl') . '</th>
            		            <th>' .
            		            /* Translators: Service type */
            		            sprintf(esc_html__('Number of %s items','sermons-nl'), 'Kerktijden') . '</th>
            		            <th>' .
            		            /* Translators: Service type */
								sprintf(esc_html__('Number of %s items','sermons-nl'), 'Kerkomroep') . '</th>
            		            <th>' .
            		            /* Translators: Service type */
								sprintf(esc_html__('Number of %s items','sermons-nl'), 'YouTube') . '</th>
                	        </tr>';
            foreach($issues as $event){
                print '
                            <tr>
                                <td><a href="javascript:;" onclick="sermons_nl_admin.show_details(' . esc_attr($event->id) . ');">' . esc_html(ucfirst(self::datefmt('short', $event->dt_start))) . '</a></td>
                                <td>' . esc_html($event->n_kt) . '</td>
                                <td>' . esc_html($event->n_ko) . '</td>
                                <td>' . esc_html($event->n_yt) . '</td>
                            </tr>';
            }
            print '
                            </tr>
                        </table>
                    </p>
                    <div id="sermons_nl_details_view"></div>
                </div>';
        }
        
        print '
                <h3>' . esc_html__("Shortcode builder","sermons-nl") . ':</h3>
                <div>
                    <p>' . esc_html__("Build a shortcode to insert the list of sermons to your page.","sermons-nl") . '</p>
                    <p>
                        <a class="sermons-nl-copyshort" onclick="sermons_nl_admin.copy_shortcode(this);" title="' . esc_html__('Click to copy the shortcode','sermons-nl') . '">
        		            <img src="' . esc_attr(plugin_dir_url(__FILE__)) . 'img/copy.png">
        		            <span id="sermons_nl_shortcode">[sermons-nl-list offset="now -13 days" ending="now +8 days" more-buttons=1]</span>
        		        </a>
    		        </p>
                    <table>
                        <tr>
                            <td>' . esc_html__("Selection method","sermons-nl") . ':</td>
                            <td>
                                <select id="sermons_nl_selection_method">
                                    <option value="start-stop-date">' . esc_html__('Start and end date','sermons-nl') . '</option>
                                    <option value="start-date-count">' . esc_html__('Fixed number from start date','sermons-nl') . '</option>
                                    <option value="stop-date-count">' . esc_html__('Fixed number back from end date','sermons-nl') . '</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td>' . esc_html__("Start date",'sermons-nl') . ':<sup>1</sup></td>
                            <td><input type="text" id="sermons_nl_start_date" value="now -13 days"/></td>
                        </tr>
                        <tr>
                            <td>' . esc_html__("End date",'sermons-nl') . ':<sup>1</sup></td>
                            <td><input type="text" id="sermons_nl_end_date" value="now +8 days"/></td>
                        </tr>
                        <tr>
                            <td>' . esc_html__("Number of events",'sermons-nl') . ':</td>
                            <td><input type="text" id="sermons_nl_count" value="10" disabled/></td>
                        </tr>
                        <tr>
                            <td>' . esc_html__("Date format",'sermons-nl') . ':<sup>2</sup></td>
                            <td><input type="text" id="sermons_nl_datefmt", value="long"/></td>
                        </tr>
                        <tr>
                            <td>' . esc_html__("Buttons","sermons-nl") . ':</td>
                            <td><input type="checkbox" id="sermons_nl_more_buttons" checked/><label for="sermons_nl_more_buttons"> ' . esc_html__('Include buttons to load earlier and later sermons','sermons-nl') . '</label></td>
                        </tr>
                        <tr>
							<td>' . esc_html__("Sermons-NL logo","sermons-nl") . ':<sup>3</sup></td>
							<td><input type="checkbox" id="sermons_nl_show_logo"/><label for="sermons_nl_show_logo"> ' . esc_html__('Display the Sermons-NL logo next to obligatory logos.','sermons-nl') . '</label></td>
						</tr>
                    </table>
                    <p><small>
						<sup>1</sup> ' .
					/* Translators: Url to the DateTime manpage of php.net. */
                    sprintf(esc_html__('For start and end date, you can enter any text that can be interpreted as a date based on supported %s formats.', 'sermons-nl'), '<a href="https://www.php.net/manual/en/datetime.formats.php" target="_blank">DateTime</a>') .
					'<br/>
						<sup>2</sup> ' .
					/* Translators: Url to the Datetime::format() manpage of php.net. */
					sprintf(esc_html__('The date format, that is used for printing the sermon dates can be either "long" or "short" (which will print a long or short date format followed by hours and minutes) or a suitable date-time format, see %s.', 'sermons-nl'), '<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank">DateTime::format()</a>') .
					'<br/>
						<sup>3</sup>' .
					esc_html__('The Sermons-NL logo will be displayed next to the obligatory logos of the services that are used. Thank you if you switch this on, it will help others find the plugin!', 'sermons-nl') .
					'</small></p>
                </div>
            </div>
            <div class="sermons-nl-container">
                <h3>' . esc_html__("Frequently asked questions","sermons-nl") . ':</h3>
                <div>';

		$faq = array(
			esc_html__("How do I start using the plugin?","sermons-nl") =>
			esc_html__("After installing and activating the plugin, a page \"Sermons-NL\" is added to the main menu of your WP Admin. In the submenu \"Configuration\" you can enter the details of the services that you want to include. Specific instructions per service are provided there.","sermons-nl"),

			esc_html__("How do I add a list of sermons to my website?","sermons-nl") =>
			esc_html__("The plugin Sermons-NL uses shortcodes to add sermons to your website. For a complete list of sermons, you will find a shortcode builder on the landing page of the plugin, accessible via the main menu of the WP Admin. You can also add individual sermons or even separate broadcasts to your website. For this, navigate to the Administration submenu, find the relevant sermon or item, and click the copy icon for the shortcode. You can paste the shortcode on your page or in your message.","sermons-nl"),

			esc_html__("We have broadcasted an event, but I don't want it to be listed under the sermons","sermons-nl") =>
			esc_html__("You can do so by finding the event in the Administration submenu, and unticking the \"Include in sermons list\" option. Don't forget to press the Save button.
If you want to prevent a planned broadcast to be listed under the sermons, you can create a new event manually (\"Create new event\" option in the Administration submenu) and enter the  date and time of the planned broadcast. Untick the \"Inclde in sermons list\" option. Note that the \"Protect from automated deletion\" option should be on, especially if you create the manual event entry before the day of the broadcast, or else you will loose it overnight. As soon as the new broadcast is detected, the plugin will link it to this manual event and will avoid the creation of a new one.
Note that you can include this broadcast on your website, for example in a news message, by using the event shortcode that you find in the Administration page.","sermons-nl"),

			esc_html__("The automatic linkage of items from different services has gone wrong. What should I do?,","sermons-nl") =>
			esc_html__("This sometimes happens, e.g. if the broadcasting is started much earlier so that linking it to the planned sermon is not unambiguous. It is easy to fix afterwards. Go to the Administration submenu and find the sermon that has this error. You can first unlink the item that was not correctly linked. It will end up under the \"Unlinked items\". If the sermons has no other linked items, you can now delete it. Next, go to the unlinked items and link it to another sermon. Only sermons with the same date can be linked.","sermons-nl"),

			esc_html__("Why does Sermons-NL not support Kerkdienst Gemist?","sermons-nl") =>
			esc_html__("Kerkdienst Gemist is a service similar to Kerkomroep. Currently, only Kerkomroep is included, because the church for which the plugin was first developed uses that service. However, adding support for Kerkdienst Gemist is possible, I would really like to add it in one of the next releases. I welcome volunteers to test this functionality in a beta version if their church is using Kerkdienst Gemist and if they would love to use this plugin. For this, please visit the issue page and add your reaction or send an e-mail to the developer.","sermons-nl"),

			esc_html__("Wordpress is occasionally responding very slow since I am using Sermons-NL. What can I do about it?","sermons-nl") =>
			sprintf(
				/* Translators: Opening and closing tags of url to instruction page. */
				esc_html__('Please check if you are using cron jobs. Sermons-NL will regularly update data in the background. This can slow down your website. To optimize performance, check if your hosting server allows you to use cron jobs.  The recommended frequency of cron jobs for this Plugin is once every 15 minutes. Please check for example %1$s this instruction to disable cron in wordpress %2$s for instruction. If you are already using cron jobs and it is correctly configured, it is unlikely that the Sermons-NL plugin is slowing down your website.','sermons-nl'),
				'<a href="https://www.wpbeginner.com/wp-tutorials/how-to-disable-wp-cron-in-wordpress-and-set-up-proper-cron-jobs" target="_blank">',
				'</a>'
			),

			esc_html__("I get [Sermons-NL invalid shortcode] on my site where a Sermons-NL shortcode was used","sermons-nl") =>
			esc_html__("Shortcodes for standalone items may produce the error [invalid shortcode] for two reasons. If the error mentions (duplication), this means that you have multiple standalone items on the page, one of them is a duplication. The plugin doesn't allow you to include a standalone sermon and also a standalone item from the same sermon on one page as this will cause conflicts. A second possible explanation is that the standalone sermon or item that you have included does not exist (any more). Check the Administration submenu in your WP Admin for the correct shortcode.","sermons-nl"),

			esc_html__("I encounter another problem with my plugin, what can I do to fix it?","sermons-nl") =>
			esc_html__("Please visit the Log submenu in your WP Admin first to see if you can identify the reason for your problem. Check the settings if the Log indicates errors when obtaining data. If you are not able to fix the problem, please report it on the issue page of the plugin or e-mail to the developer while including as much detail as possible.","sermons-nl")

		);

		foreach($faq as $q => $a){
			# $q and $a are already escaped above which is needed for some because tags are included with sprintf
			print '<h4>' .
				// phpcs:ignore  WordPress.Security.EscapeOutput.OutputNotEscaped
				$q .
				'</h4><p>' .
				// phpcs:ignore  WordPress.Security.EscapeOutput.OutputNotEscaped
				nl2br($a) .
				'</p>';
		}
		print '
                </div>
            </div>';
        
        print '
		</div>';

    }

	public static function admin_administration_page(){
	    // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		$num_unlinked = self::num_unlinked_items();
		print '
		<div>
		    <h2>' . esc_html__("Manage church service calendar and broadcasts","sermons-nl") . '</h2>
		    <p class="sermons-nl-abuttons">
		        <a href="javascript:;" onclick="sermons_nl_admin.navigate(-1);">' . esc_html__('Previous month','sermons-nl') . '</a>
		        <a href="javascript:;" onclick="sermons_nl_admin.navigate(1);">' . esc_html__('Next month','sermons-nl') . '</a>
		        <a href="javascript:;" onclick="sermons_nl_admin.show_details(0);">' . esc_html__('Unlinked items','sermons-nl') . ' (<span id="sermons_nl_unlinked_num">' . esc_html($num_unlinked) . '</span>)</a>
		        <a href="javascript:;" onclick="sermons_nl_admin.show_details(null);">' . esc_html__('Create new event','sermons-nl') . '</a>
		        <img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/waiting.gif" id="sermons_nl_waiting"/><!-- icon freely available at https://icons8.com/preloaders/en/circular/floating-rays/ -->
		    </p>
		    <div id="sermons_nl_admin_table">';
		
		/* escaping is handled by the function */
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		print self::admin_edit_sermons_page_get_table();

		print '
		    </div>
		    <div id="sermons_nl_details_view"></div>
		</div>';
		
	}
	
	public static function admin_navigate_table(){
		// check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		ob_clean();
		//  Processing form data without nonce verification OK (logged in user with permission & the function is not saving any input)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$m = (isset($_GET['month']) ? (int) $_GET['month'] : 0);
	    $html = self::admin_edit_sermons_page_get_table($m);
	    $json = array(
	        'action' => 'sermons_nl_admin_navigate_table',
	        'month' => $m,
	        'html' => $html
	        );
	    print wp_json_encode($json);
		wp_die();
	}
	
	private static function admin_edit_sermons_page_get_table($m=0){
	    if($m == 0) $m = 'this';
	    $dt1 = gmdate("Y-m-d", strtotime("first day of $m month"));
		$dt2 = gmdate("Y-m-d", strtotime("last day of $m month"));
		
		$data = self::get_complete_records_by_dates($dt1, $dt2, true, true);

        $html = '
    		    <h3 id="sermons_nl_admin_month">' . esc_html(ucfirst(wp_date("F Y", strtotime($dt1)))) . '</h3>';
		if(empty($data)){
		    $html .= '<p>' . esc_html__('No records found.','sermons-nl') . '</p>';
		}else{
		    //  class="wp-list-table widefat fixed striped pages"
    		$html .= '
    	      <table id="sermons_nl_events_table">
    	        <thead>
    	          <tr>
    	            <th></th>
    	            <th>' . esc_html__("Date","sermons-nl") . '</th>
    	            <th>' . esc_html__("Pastor","sermons-nl") . '</th>
    	            <th colspan="3">' . esc_html__("Items","sermons-nl") . '</th>
    	          </tr>
    	        </thead>
    	        <tbody>';
	        foreach($data as $rec){
	            $html .= '
	              <tr>
	                <td>' . ($rec->include ? '' : '<img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/not_included.gif" alt="X" title="' . esc_html__("Not included","sermons-nl") . '"/>') .
					($rec->protected ? '<img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/protected.png" alt="8" title="' . esc_html__("Protected","sermons-nl") . '" height="20"/>' : '') . '</td>
	                <td><a href="javascript:;" onclick="sermons_nl_admin.show_details('.$rec->id.');">' . esc_html(ucfirst(self::datefmt("short", $rec->dt_start))) . '</a></td>
	                <td>' . $rec->pastor . '</td>
	                <td>' . ($rec->kt_id ? '<img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/has_kt.gif" alt="KT" title="' . esc_html__("Uses Kerktijden source","sermons-nl") . '"/>' : '') . '</td>
	                <td>' . ($rec->ko_id ? '<img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/has_ko.gif" alt="KO" title="' . esc_html__("Uses Kerkomroep source","sermons-nl") . '"/>' : '') . '</td>
	                <td>' . ($rec->yt_video_id ? '<img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/has_yt.gif" alt="YT" title="' . esc_html__("Uses YouTube source","sermons-nl") . '"/>' : '') . '</td>
	              </tr>';
	        }
	        $html .= '
    	        </tbody>
    	      </table>';
		}
		return $html;
	}
	
	public static function admin_show_details(){
		// check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		//  Processing form data without nonce verification OK: logged in user with permission & the function is not saving any input
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if(!isset($_GET['event_id'])){
			wp_die("missing argument event_id");
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if($_GET['event_id'] === ""){
			$event_id = null;
			// show form to create new event
		    $html = '
		        <h3>
		            <img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/close.gif" class="sermons-nl-closebtn" title="'.esc_html__("Cancel","sermons-nl").'" onclick="sermons_nl_admin.hide_details(null);"/>
		            Create event manually
		        </h3>';
			$event = (object) array(
				"id" => 0,
				"dt_from" => "manual",
				"dt_manual" => null,
				"sermontype_from" => "auto",
				"sermontype_manual" => null,
				"pastor_from" => "auto",
				"pastor_manual" => null,
				"description_from" => "auto",
				"description_manual" => null,
				"include" => 1,
				"protected" => 1
			);
			$html .= self::html_form_update_event($event);
		}else{
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$event_id = (int)$_GET['event_id'];
    		$html = '
    		    <h3><img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/close.gif" class="sermons-nl-closebtn" title="'.esc_html__("Close","sermons-nl").'" onclick="sermons_nl_admin.hide_details('.$event_id.');"/>';
    		if($event_id == 0){
				// show unlinked items with options to link them to an event or create a new event from the unlinked item
    		    $items = self::get_unlinked_items();
    		    $html .= esc_html__("Unlinked items","sermons-nl") . '</h3>
        		    <ul>
        		        <li>' . esc_html__('Items not linked to an event will not be shown in the sermons listing.','sermons-nl').'</li>
            		    <li>' . esc_html__('Link the item to an existing event by clicking the date or create a new one.','sermons-nl').'</li>
            		    <li>' . esc_html__('For new events, the date and time of the linked item is used.','sermons-nl').'</li>
        		        <li>' . esc_html__('Or copy the shortcode for adding the single item to your website.','sermons-nl').'</li>
        		    </ul>';
    		    if(empty($items)){
    		        $html .= '
    		        <p><em>' . esc_html__('There are no unlinked items.','sermons-nl').'</em></p>';
    		    }else{
    		        //  class="wp-list-table widefat fixed striped pages"
    		        $html .= '
        		    <table>
        		        <thead>
            		        <tr>
            		            <th>'.esc_html__('Type','sermons-nl').':</th>
            		            <th>'.esc_html__('Date / time','sermons-nl').':</th>
            		            <th colspan="2">'.esc_html__('Actions','sermons-nl').':</th>
            		        </tr>
            		    </thead>
            		    <tbody>';
            		foreach($items as $item){
            		    $html .= '
            		        <tr>
            		            <td>' . esc_html($item->item_type) . '</td>
            		            <td>' . esc_html(ucfirst(self::datefmt("short", $item->dt))) . '</td>
            		            <td>';
						if($item->item_type != 'kerktijden'){
							$html .= '
            		                <a onclick="sermons_nl_admin.copy_shortcode(this);" title="' . esc_attr__('Click to copy the shortcode','sermons-nl') . '" class="sermons-nl-copyshort">
            		                    <img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/copy.png"/>
            		                    Shortcode
            		                    <div>';
							$html .= '[sermons-nl-item type="' . esc_attr($item->item_type) . '" id="' . esc_attr($item->id) . '"]';
							$html .= '</div>
            		                </a>';
						}
						$html .= '
            		            </td>
            		            <td>
            		                <a class="sermons-nl-linktoevent"><img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/link.png"/> ' . esc_html__('Link to event','sermons-nl') . '<div><ul>';
						$item_dt = new DateTime($item->dt, sermons_nl::$timezone_db);
						$dt1 = $item_dt->format("Y-m-d 00:00:00");
						$dt2 = $item_dt->format("Y-m-d 23:59:59");
            		    $events = sermons_nl_event::get_by_dt($dt1, $dt2, true);
            		    if(empty($events)) $html .= '<em>' . esc_html__('No existing event on this date','sermons-nl') . '</em>';
            		    else{
                		    $first = true;
                		    usort($events, function($d1, $d2){return strtotime($d1->dt) - strtotime($d2->dt);});
                		    foreach($events as $event){
                		        if($first) $first = false;
                		        else $html .= '<br/>';
                		        $html .= '<li onclick="sermons_nl_admin.link_item_to_event(\''.esc_attr($item->item_type).'\', '.esc_attr($item->id).', '.esc_attr($event->id).');">';
                		        if($event->dt){
            		                $html .= esc_html(ucfirst(self::datefmt('short', $event->dt)));
            		            }
            		            $html .= '</li>';
            		        }
        		        }
        	    	    $html .= '<br/><li onclick="sermons_nl_admin.link_item_to_event(\''.esc_attr($item->item_type).'\', '.esc_attr($item->id).', null);">' . esc_html__('Create new event','sermons-nl') . '</li>';
        	    	    $html .= '</ul></div></a>
            		                </td>
            		            </tr>'; 
        	    	}
        	    	$html .= '
            		    </tbody>
            		</table>';
    		    }
    		}else{
				// show form to edit an event and/or unlink items from the event
    		    $event = sermons_nl_event::get_by_id($event_id);
    		    if(!$event){
    		        $html = "Error: no event with id #$event_id.</h3>";
    		    }else{
        		    $html .= esc_html(ucfirst(self::datefmt("short", $event->dt))) . '</h3>
        		    <div>
            		    <a class="sermons-nl-copyshort" onclick="sermons_nl_admin.copy_shortcode(this);" title="' . esc_attr__('Click to copy the shortcode','sermons-nl') . '">
        		            <img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/copy.png" />
        		            ' . esc_html__('Copy event shortcode','sermons-nl') . '
        		            <div>';
            		$html .= '[sermons-nl-event id="' . esc_html($event_id) . '"]';
            		$html .= '</div>
            		    </a>
            		</div>';
        		    $items = $event->get_all_items();
        		    if(empty($items)){
        		        $html .= '
        		        <p>
        		            <em>' . esc_html__("This event has no linked items.","sermons-nl") . '</em>
        		            <a href="javascript:;" onclick="if(confirm(\'' . esc_attr__('Are you sure you want to delete this event?','sermons-nl') . '\')){sermons_nl_admin.delete_event('.esc_attr($event->id).');}">' . esc_html__('Delete event','sermons-nl') . '</a>
        		        </p>';
        		    }else{
        		        $abbr = array('kerktijden'=>'kt', 'kerkomroep'=>'ko', 'youtube'=>'yt');
        		        //  class="wp-list-table widefat fixed striped pages"
        		        $html .= '
        		        <p><b>' . esc_html__("This event has the following linked items:", "sermons-nl") . '</b></p>
        		        <table>';
        		        foreach($items as $type => $subitems){
        		            foreach($subitems as $item){
        		                $html .= '
        		                <tr>
									<td>' . ucfirst($type) . '</td><td>' . esc_html(ucfirst(self::datefmt("short", $item->dt))) . '</td>
									<td>';
								if($type != 'kerktijden'){
									$html .= '
										<a onclick="sermons_nl_admin.copy_shortcode(this);" title="' . esc_attr__('Click to copy the shortcode','sermons-nl') . '" class="sermons-nl-copyshort">
											<img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/copy.png"/>
											Shortcode
											<div>';
									$html .= '[sermons-nl-item type="' . esc_html($type) . '" id=' . esc_html($item->id) . ']';
									$html .= '</div>
										</a>';
								}
								$html .= '
									</td>
									<td><a class="sermons-nl-linktoevent" href="javascript:;" onclick="sermons_nl_admin.unlink_item(\'' . esc_attr($type) . '\', ' . esc_attr($item->id) . ');"><img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/link.png"/> ' . esc_html__('Unlink item','sermons-nl') . '</a></td>
								</tr>';
        		            }
        		        }
        		        $html .= '
        		        </table>';
        		    }
        		    $html .= self::html_form_update_event($event);
    		    }
    		}
		}
		$json = array(
		    'action' => "sermons_nl_admin_show_details",
		    'event_id' => $event_id,
		    'html' => $html
		);
		ob_clean();
		print wp_json_encode($json);
		wp_die();
	}

	private static function html_form_update_event($event){
		$html = '
		<form id="sermons_nl_update_event" onsubmit="return !sermons_nl_admin.submit_update_event(this);">
			<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('sermons-nl-administration')) . '"/>
			<input type="hidden" name="action" value="sermons_nl_submit_update_event"/>
			<input type="hidden" name="event_id" value="' . esc_attr($event->id) . '"/>
			<div id="sermons_nl_event_errmsg"></div>
			<table>
				<tr>
					<td><h4>' . esc_html__('Event settings', 'sermons-nl') . '</h4></td>
					<td></td>
				</tr>
				<tr>
					<td>' . esc_html__('Select date-time from','sermons-nl') . ': <sup>1</sup></td>
					<td><select name="dt_from" onchange="sermons_nl_admin.toggle_manual_row(this,\'dt\');">';
		$options_str = array(
			'auto' => __('auto','sermons-nl'),
			'manual' => __('manual','sermons-nl'),
			'kerktijden' => 'kerktijden',
			'kerkomroep' => 'kerkomroep',
			'youtube' => 'youtube'
		);
		foreach(array('auto','manual','kerktijden','kerkomroep','youtube') as $value){
			$html .= '<option value="'.esc_attr($value).'"' . ($value == $event->dt_from ? ' selected="selected"' : '') . '>' . esc_html($options_str[$value]) . '</option>';
		}
		$html .= '</select></td>
				</tr>
				<tr id="sermons_nl_dt_manual"' . ($event->dt_from == 'manual' ? '' : ' class="sermons-nl-manual-closed"') . '>
					<td></td>
					<td><input type="datetime-local" name="dt_manual"';
		if($event->dt_manual !== null){
			$dt = new DateTime($event->dt_manual, self::$timezone_db);
			$dt->setTimeZone(wp_timezone());
			$html .= ' value="' . esc_attr($dt->format("Y-m-d\TH:i")) . '"';
		}
		$html .= '/></td>
				</tr>
				<tr>
					<td>' . esc_html__('Select sermon type from','sermons-nl') . ': <sup>2</sup></td>
					<td><select name="sermontype_from" onchange="sermons_nl_admin.toggle_manual_row(this,\'sermontype\');">';
		foreach(array('auto','manual','kerktijden') as $value){
			$html .= '<option value="'.esc_attr($value).'"' . ($value == $event->sermontype_from ? ' selected="selected"' : '') . '>' . esc_html($options_str[$value]) . '</option>';
		}
		$html .= '</select></td>
				</tr>
				<tr id="sermons_nl_sermontype_manual"' . ($event->sermontype_from == 'manual' ? '' : ' class="sermons-nl-manual-closed"') . '>
					<td></td>
					<td><input type="text" name="sermontype_manual"' . ($event->sermontype_manual === null ? '' : ' value="' . esc_attr($event->sermontype_manual)) . '"/></td>
				</tr>
				<tr>
					<td>' . esc_html__('Select pastor name from','sermons-nl') . ': <sup>3</sup></td>
					<td><select name="pastor_from" onchange="sermons_nl_admin.toggle_manual_row(this,\'pastor\');">';
		foreach(array('auto','manual','kerktijden','kerkomroep') as $value){
			$html .= '<option value="'.esc_attr($value).'"' . ($value == $event->pastor_from ? ' selected="selected"' : '') . '>' . esc_html($options_str[$value]) . '</option>';
		}
		$html .= '</select></td>
				</tr>
				<tr id="sermons_nl_pastor_manual"' . ($event->pastor_from == 'manual' ? '' : ' class="sermons-nl-manual-closed"') . '>
					<td></td>
					<td><input type="text" name="pastor_manual"' . ($event->pastor_manual === null ? '' : ' value="' . esc_attr($event->pastor_manual)) . '"/></td>
				</tr>
				<tr>
					<td>' . esc_html__('Select description from','sermons-nl') . ': <sup>4</sup></td>
					<td><select name="description_from" onchange="sermons_nl_admin.toggle_manual_row(this,\'description\');">';
		foreach(array('auto','manual','youtube','kerkomroep') as $value){
			$html .= '<option value="'.esc_attr($value).'"' . ($value == $event->description_from ? ' selected="selected"' : '') . '>' . esc_html($options_str[$value]) . '</option>';
		}
		$html .= '</select></td>
				</tr>
				<tr id="sermons_nl_description_manual"' . ($event->description_from == 'manual' ? '' : ' class="sermons-nl-manual-closed"') . '>
					<td></td>
					<td><textarea name="description_manual">' . esc_html($event->description_manual) . '</textarea></td>
				</tr>
				<tr>
					<td>' . esc_html__('Other settings','sermons-nl') . ':</td>
					<td><input type="checkbox" name="include" id="sermons_nl_include"' . ($event->include ? ' checked' : '') . '/><label for="sermons_nl_include"> ' . esc_html__('Include in sermons list','sermons-nl') . ' <sup>5</sup></label></td>
				</tr>
				<tr>
					<td></td>
					<td><input type="checkbox" name="protected" id="sermons_nl_protected"' . ($event->protected ? ' checked' : '') . '/><label for="sermons_nl_protected"> ' . esc_html__('Protect from automated deletion','sermons-nl').' <sup>6</sup></label></td>
				</tr>
				<tr>
					<td></td>
					<td>' . get_submit_button(esc_html__('Save settings','sermons-nl'), 'primary') . '</td>
				</tr>
			</table>
			<p class="sermons-nl-footnotes">
				<sup>1.</sup> ' .
				esc_html__('If auto is selected or if an item is selected that has no date-time, the order of picking the date-time is (based on availability): kerktijden, youtube (if it has a planned date), kerkomroep, youtube (if it has been broadcasted).','sermons-nl') .
				'<br/>
				<sup>2.</sup> ' .
				esc_html__('If auto is selected, sermons type will be selected from kerktijden.','sermons-nl') .
				'<br/>
				<sup>3.</sup> ' .
				esc_html__('If auto is selected, pastor name will be selected from kerktijden or kerkomroep (in this order based on availability).','sermons-nl')  .
				'<br/>
				<sup>4.</sup> ' .
				esc_html__('If auto is selected, description will be selected from youtube or kerkomroep (in this order, based on availability).','sermons-nl') .
				'<br/>
				<sup>5.</sup> ' .
				esc_html__('If this option is checked, the event will be included when displaying sermons on the website using the sermons-nl-list shortcode.','sermons-nl') .
				'<br/>
				<sup>6.</sup> ' .
				esc_html__('Events that have no linked items are deleted over night. Tick this box to prevent that from happening.','sermons-nl') . '
			</p>
		</form>';
		return $html;
	}

	public static function sermons_nl_submit_update_event(){
		// check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		// check nonce
		check_ajax_referer('sermons-nl-administration');
		// prep json function
		function returnIt(bool $ok, $errMsg = null){
			$json = array("action" => "sermons_nl_submit_update_event", "ok" => $ok);
			if($errMsg) $json['errMsg'] = $errMsg;
			ob_clean();
			print wp_json_encode($json);
			wp_die();
		}
		// process posted data
		$event_id = (empty($_POST['event_id']) ? 0 : filter_input(INPUT_POST, 'event_id', FILTER_SANITIZE_NUMBER_INT));
		if(empty($_POST['dt_manual'])){
			$dt_manual = null;
		}else{
			$dt_manual = new DateTime(sanitize_text_field(filter_input(INPUT_POST, 'dt_manual')), wp_timezone());
			if($dt_manual) $dt_manual = $dt_manual->setTimeZone(self::$timezone_db)->format("Y-m-d H:i:s");
		}
		$data = array(
			'dt_from' => sanitize_text_field(filter_input(INPUT_POST, 'dt_from')),
			'dt_manual' => $dt_manual,
			'sermontype_from' => sanitize_text_field(filter_input(INPUT_POST, 'sermontype_from')),
			'sermontype_manual' => (empty($_POST['sermontype_manual']) ? null : sanitize_text_field(filter_input(INPUT_POST, 'sermontype_manual'))),
			'pastor_from' => sanitize_text_field(filter_input(INPUT_POST, 'pastor_from')),
			'pastor_manual' => (empty($_POST['pastor_manual']) ? null : sanitize_text_field(filter_input(INPUT_POST, 'pastor_manual'))),
			'description_from' => sanitize_text_field(filter_input(INPUT_POST, 'description_from')),
			'description_manual' => (empty($_POST['description_manual']) ? null : sanitize_textarea_field(filter_input(INPUT_POST, 'description_manual'))),
			'include' => (empty($_POST['include']) ? 0 : 1),
			'protected' => (empty($_POST['protected']) ? 0 : 1)
		);
		// data checks
		if($data['dt_from'] == 'manual' && empty($data['dt_manual'])){
			returnIt(false, esc_html__('An incorrect manual date is entered.', 'sermons-nl'));
		}
		if($event_id > 0){
			if(!($event = sermons_nl_event::get_by_id($event_id))){
				/* Translators: the ID of the event. */
				returnIt(false, sprintf(esc_html__('Failed to save event settings: no event with id %d found.', 'sermons-nl'), $event_id));
			}
			$items = $event->get_all_items();
			// check that the manual date is not out of range of the items, if there are any
			if($data['dt_from'] == 'manual' && !empty($items)){
				// rely on dt_min and dt_max for ease
				if($data['dt_manual'] < $event->dt_min || $data['dt_manual'] > $event->dt_max){
					returnIt(false, esc_html__('The manual date and time should not be out of the range of the linked items.', 'sermons-nl'));
				}
			}elseif($data['dt_from'] != 'manual' && empty($items)){
				returnIt(false, esc_html__('A manual date must be set when the event has no linked items.', 'sermons-nl'));
			}
		}else{
			// check that date is manual, it has to be manual for new events!
			if($data['dt_from'] != 'manual'){
				returnIt(false, esc_html__('When adding a new event, the date and time has to be set manually.', 'sermons-nl'));
			}
			$event = sermons_nl_event::add_record($data['dt_manual']);
			if(!$event){
				returnIt(false, esc_html__('Sorry, failed to create a new event due to an unknown error.', 'sermons-nl'));
			}
		}
		// save data
		$event->update($data);
		returnIt(true);
	}
	
	public static function link_item_to_event(){
	    // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
	    // check nonce
	    check_ajax_referer('sermons-nl-administration');
	    
	    // check input
	    if(!array_key_exists('item_type',$_POST) || !array_key_exists('item_id',$_POST) || !array_key_exists('event_id',$_POST)){
	        wp_trigger_error(__CLASS__."::link_item_to_event", "Missing post parameters.", E_USER_ERROR);
	        wp_die(-1);
	    }
	    
	    // prepare return
	    $json = array('action' => 'sermons_nl_admin_link_item_to_event');
	    
	    // get values
	    $item_type = sanitize_text_field(filter_input(INPUT_POST, 'item_type'));
	    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_SANITIZE_NUMBER_INT);
	    $event_id = ($_POST['event_id'] == "" ? null : filter_input(INPUT_POST, 'event_id', FILTER_SANITIZE_NUMBER_INT));
	    
	    // get item
	    $item = self::get_item_by_type($item_type, $item_id);
	    if($item === null){
	        // no such item
	        $json['ok'] = false;
	        $json['errMsg'] = "Item #$item_id doesn't exist.";
	    }else{
	        if($event_id === null){
	            $event = sermons_nl_event::add_record($item->dt, $item->dt_end);
	            $item->event_id = $event->id;
	            $json['ok'] = true;
	        }else{
	            $event = sermons_nl_event::get_by_id($event_id);
	            if(!$event){
	                // no such event
	                $json['ok'] = false;
	                $json['errMsg'] = "Event #$event_id doesn't exist.";
	            }else{
	                $item->event_id = $event->id;
	                $event->update_dt_min_max($item->dt, $item->dt_end);
	                $json['ok'] = true;
	            }
	        }
	    }
	    $json['unlinked_num'] = self::num_unlinked_items();
	    
		ob_clean();
		print wp_json_encode($json);
		wp_die();
	}

	public static function unlink_item(){
	    // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		// check nonce
	    check_ajax_referer('sermons-nl-administration');
	    
	    // check input
	    if(!array_key_exists('item_type',$_POST) || !array_key_exists('item_id',$_POST)){
	        wp_trigger_error(__CLASS__."::unlink_item_to", "Missing post parameters.", E_USER_ERROR);
	        wp_die(-1);
	    }
	    
	    // prepare return
	    $json = array('action' => 'sermons_nl_admin_unlink_item');
	    
	    // get values
	    $item_type = sanitize_text_field(filter_input(INPUT_POST, 'item_type'));
	    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_SANITIZE_NUMBER_INT);
	    
	    // get item
	    $item = self::get_item_by_type($item_type, $item_id);
	    if($item === null){
	        // no such item
	        $json['ok'] = false;
	        $json['errMsg'] = "Item #$item_id doesn't exist.";
	    }else{
	        $json['ok'] = true;
            $json['event_id'] = $item->event_id;
            $item->event_id = null;
	    }
	    $json['unlinked_num'] = self::num_unlinked_items();
	    
		ob_clean();
		print wp_json_encode($json);
		wp_die();
	}
	
	public static function delete_event(){
	    // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		// check nonce
	    check_ajax_referer('sermons-nl-administration');
	    
	    // check input
	    if(!array_key_exists('event_id',$_POST)){
	        wp_trigger_error(__CLASS__."::delete_event", "Missing post parameters.", E_USER_ERROR);
	        wp_die(-1);
	    }
	    
	    // prepare return
	    $json = array('action' => 'sermons_nl_admin_delete_event');
	    
	    // get values
	    $event_id = filter_input(INPUT_POST, 'event_id', FILTER_SANITIZE_NUMBER_INT);
	    
	    // get event
	    $event = sermons_nl_event::get_by_id($event_id);
	    if($event === null){
	        // no such item
	        $json['ok'] = false;
	        $json['errMsg'] = "Event #$event_id doesn't exist.";
	    }else{
	        $json['ok'] = true;
	        $json['event_id'] = $event_id;
	        $event->delete();
	    }
	    
		ob_clean();
		print wp_json_encode($json);
		wp_die();
	}
	
	public static function admin_edit_config_page(){
		// check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		
		$kt_id = get_option('sermons_nl_kerktijden_id');
		$kt_weeksback = get_option('sermons_nl_kerktijden_weeksback');
		$kt_weeksahead = get_option('sermons_nl_kerktijden_weeksahead');
        $ko_mp = get_option('sermons_nl_kerkomroep_mountpoint');
        $yt_channel = get_option('sermons_nl_youtube_channel');
		$yt_key = get_option('sermons_nl_youtube_key');
		$yt_weeksback = get_option('sermons_nl_youtube_weeksback');
		$color_archive = get_option('sermons_nl_icon_color_archive');
		$color_planned = get_option('sermons_nl_icon_color_planned');
		$color_live = get_option('sermons_nl_icon_color_live');

		// return settings form
		print '
		<div class="sermons-nl-settings">
			<h2>' . esc_html__("Settings for church services", 'sermons-nl') .
				/* icon freely available at https://icons8.com/preloaders/en/circular/floating-rays/ */
				' <img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/waiting.gif" id="sermons_nl_waiting"/></h2>
			<div id="sermons_nl_config_save_msg"></div>';

		print '
			<form method="post" onsubmit="sermons_nl_admin.config_submit(this); return false;">
				<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('sermons-nl-administration')) . '"/>
				<input type="hidden" name="action" value="sermons_nl_config_submit"/>';
		print '
				<table>

					<tbody id="color_settings">
						<tr>
							<th colspan="2">' . esc_html__("Audio and video icons","sermons-nl") . '</th>
						</tr>
						<tr class="always-visible">
							<td>' . esc_html__("Color for past broadcasts","sermons-nl") . ':</td>
							<td><input type="text" name="sermons_nl_icon_color_archive" id="sermons_nl_icon_color_archive" value="'.esc_attr($color_archive).'" class="sermons-nl-colorpicker" data-default-color="' . esc_attr(self::OPTION_NAMES["sermons_nl_icon_color_archive"]["default"]) . '"/></td>
						</tr>
						<tr class="always-visible">
							<td>' . esc_html__("Color for live broadcasts","sermons-nl") . ':</td>
							<td><input type="text" name="sermons_nl_icon_color_live" id="sermons_nl_icon_color_live" value="'.esc_attr($color_live).'" class="sermons-nl-colorpicker" data-default-color="' . esc_attr(self::OPTION_NAMES["sermons_nl_icon_color_live"]["default"]) . '"/></td>
						</tr>
						<tr class="always-visible">
							<td>' . esc_html__("Color for planned broadcasts","sermons-nl") . ':</td>
							<td><input type="text" name="sermons_nl_icon_color_planned" id="sermons_nl_icon_color_planned" value="'.esc_attr($color_planned).'" class="sermons-nl-colorpicker" data-default-color="' . esc_attr(self::OPTION_NAMES["sermons_nl_icon_color_planned"]["default"]) . '"/></td>
						</tr>
					</tbody>
					<tbody id="kerktijden_settings"' . ($kt_id ? '' : ' class="settings-disabled"') . '>
						<tr>
							<th colspan="2">Kerktijden.nl</th>
						</tr>
						<tr class="always-visible">
						    <td colspan="2"><input type="checkbox"' . ($kt_id ? ' checked="checked"' : '') . ' id="kerktijden_checkbox" onclick="sermons_nl_admin.toggle_kerktijden(this);"/><label for="kerktijden_checkbox">' .
			/* Translators: service type. */
			sprintf(esc_html__("Enable %s","sermons-nl"), "Kerktijden") . '</label></td>
						</tr>
						<tr class="collapsible-setting condition">
						    <td colspan="2">' . esc_html__("The use of data from this tool on your own website is permitted, provided that the url and logo are provided. The plugin will add it for you, please do not hide it.", "sermons-nl") . '</td>
						</tr>
						<tr class="collapsible-setting">
							<td>' . esc_html__("Kerktijden identifier", "sermons-nl") . ':
								<div class="help">
									<figure><img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/kt_identifier.jpg"/><figcaption>' .
									/* Translators: url www.kerktijden.nl. */
									sprintf(esc_html__("Browse to your church's page on %s and copy the number from the url, e.g. 999 in this figure.", "sermons-nl"), '<a href="https://www.kerktijden.nl" target="_blank">www.kerktijden.nl</a>') .
									'</figcaption></figure>
								</div>
							</td>
							<td><input type="text" name="sermons_nl_kerktijden_id" id="input_kerktijden_id" value="'. ($kt_id ? esc_attr($kt_id) : '') . '"/></td>
						</tr>
						<tr class="collapsible-setting">
						    <td>' . esc_html__("Number of weeks back","sermons-nl") . ':
						        <div class="help"><div>' .
						        /* Translators: service type. */
						        sprintf(esc_html__("How many weeks to look back when loading the %s archive","sermons-nl"), "Kerktijden") . '</div></div>
						    </td>
						    <td><input type="text" name="sermons_nl_kerktijden_weeksback" value="'. ($kt_weeksback ? esc_attr($kt_weeksback) : '') . '""/></td>
						</tr>
						<tr class="collapsible-setting">
							<td>' . esc_html__("Number of weeks ahead","sermons-nl") . ':
								<div class="help"><div>' .
								/* Translators: service type. */
								sprintf(esc_html__("How many weeks ahead in time to load %s data","sermons-nl"), "Kerktijden") . '</div></div>
							</td>
							<td><input type="text" name="sermons_nl_kerktijden_weeksahead" value="'. ($kt_weeksahead ? esc_attr($kt_weeksahead) : '') . '""/></td>
						</tr>
					</tbody>
					
					<tbody id="kerkomroep_settings"' . ($ko_mp ? '' : ' class="settings-disabled"') . '>
					    <tr>
							<th colspan="2">Kerkomroep.nl</th>
						</tr>
						<tr class="always-visible">
						    <td colspan="2"><input type="checkbox"' . ($ko_mp ? ' checked="checked"' : '') . ' id="kerkomroep_checkbox" onclick="sermons_nl_admin.toggle_kerkomroep(this);"/><label for="kerkomroep_checkbox">' .
							/* Translators: service type. */
							sprintf(esc_html__("Enable %s","sermons-nl"), "Kerkomroep") . '</label></td>
						</tr>
						<tr class="collapsible-setting condition">
						    <td colspan="2">' . esc_html__("The use of data from this tool on your own website is permitted, provided that the url and logo are provided. The plugin will add it for you, please do not hide it.", "sermons-nl") . '</td>
						</tr>
						<tr class="collapsible-setting">
							<td>' . esc_html__("Mount point", "sermons-nl") . ':
								<div class="help">
									<figure><img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/ko_url.jpg"/><figcaption>' .
									/* Translators: url www.kerkomroep.nl. */
									sprintf(esc_html__("Browse to your church's page on %s and copy the number from the end of the url, e.g. 99999 in this figure.", 'sermons-nl'), '<a href="https://www.kerkomroep.nl" target="_blank">kerkomroep.nl</a>') . '</figcaption></figure>
								</div>
							</td>
							<td><input type="text" name="sermons_nl_kerkomroep_mountpoint" id="input_kerkomroep_id" value="' . ($ko_mp ? esc_attr($ko_mp) : '') . '"/></td>
						</tr>
					</tbody>
                    <tbody id="youtube_settings"' . ($yt_channel ? '' : ' class="settings-disabled"') . '>
						<tr>
							<th colspan="2">YouTube.com</th>
						</tr>
						<tr class="always-visible">
						    <td colspan="2"><input type="checkbox"' . ($yt_channel ? ' checked="checked"' : '') . ' id="youtube_checkbox" onclick="sermons_nl_admin.toggle_youtube(this);"/><label for="youtube_checkbox">' .
							/* Translators: service type. */
							sprintf(esc_html__("Enable %s","sermons-nl"),"YouTube") . '</label></td>
						</tr>
						<tr class="collapsible-setting condition">
						    <td colspan="2">' . esc_html__("Setting the channel may take a while depending on the number of available videos.", "sermons-nl") . '</td>
						</tr>
						<tr class="collapsible-setting">
							<td>YouTube channel ID: 
								<div class="help">
									<figure><img src="' . esc_url(plugin_dir_url(__FILE__)) . '/img/yt_channel.jpg"/><figcaption>' . esc_html__("Browse to your church's YouTube channel and copy the youtube channel ID from the url.","sermons-nl") . '</figcaption></figure>
								</div>
							</td>
							<td><input type="text" name="sermons_nl_youtube_channel" id="input_youtube_id" value="' . esc_attr($yt_channel) . '"/></td>
						</tr>
						<tr class="collapsible-setting">
							<td>YouTube api key: 
								<div class="help">
									<div>' .
									/* Translators: 1: opening tag for url to youtube api developers guide, 2: closing tag for url. */
									sprintf(esc_html__('Visit %1$sthe YouTube api developers guide%2$s to learn how to obtain a YouTube api key.','sermons-nl'),'<a href="https://developers.google.com/youtube/v3/getting-started" target="_blank">','</a>') . '</div>
								</div>
							</td>
							<td><input type="text" name="sermons_nl_youtube_key" value="' . esc_attr($yt_key) . '"/></td>
						</tr>
						<tr class="collapsible-setting">
							<td>' . esc_html__("Number of weeks back","sermons-nl") . ':
								<div class="help"><div>' .
								/* Translators: service type. */
								sprintf(esc_html__("How many weeks to look back when loading the %s archive","sermons-nl"), "YouTube") . '</div></div>
							</td>
							<td><input type="text" name="sermons_nl_youtube_weeksback" value="'. ($yt_weeksback ? esc_attr($yt_weeksback) : '') . '""/></td>
						</tr>
					</tbody>
				</table>';
		submit_button();
		print '
			</form>
		</div>';
	}

	// if an option is changed, in some cases one of the tables needs to be truncated
	// and sometimes a specific archive need to be loaded
	public static function config_submit(){
		// check access
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		// check nonce
		check_ajax_referer('sermons-nl-administration');
		// may be needed
		global $wpdb;
		// loop through const OPTION_NAMES: if name is found in _POST, it can be saved.
		// check for some if they changed, if so an action is required
		$get_data = array();
		$drop_redundant = false;
		foreach(self::OPTION_NAMES as $option_name => $attr){
			if(array_key_exists($option_name, $_POST)){
				$old_value = get_option($option_name);
				$new_value = sanitize_text_field(filter_input(INPUT_POST, $option_name));
				if($attr['default'] === null && $new_value === ""){
					$new_value = null;
				}elseif($attr['type'] == 'integer'){
					$new_value = (int)$new_value;
				}
				if($old_value != $new_value){
					update_option($option_name, $new_value);
					switch($option_name){
						case "sermons_nl_kerktijden_id":
							// clean kerktijden data when the kerktijden_id changes
							$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sermons_nl_kerktijden");
							$drop_redundant = true;
							if(!empty($new_value)){
								$get_data[] = 'kt';
							}
							break;
						case "sermons_nl_kerktijden_weeksback":
							if($new_value > $old_value){
								$get_data[] = 'kt';
							}
							break;
						case "sermons_nl_kerktijden_weeksahead":
							if($old_value > $old_value){
								$get_data[] = 'kt';
							}
							break;
						case "sermons_nl_kerkomroep_mountpoint":
							// clean kerkomroep data when the mountpoint changes
							$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sermons_nl_kerkomroep");
							$drop_redundant = true;
							if(!empty($new_value)){
								$get_data[] = 'ko';
							}
							break;
						case "sermons_nl_youtube_channel":
							// clean youtube data when the channel id changes
							$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sermons_nl_youtube");
							$drop_redundant = true;
							if(!empty($new_value)){
								$get_data[] = 'yt';
							}
							break;
						case "sermons_nl_youtube_key":
							if(!empty($new_value)){
								// it is uncertain whether this is needed, but if the initial yt key was wrong
								// and is now corrected, updating the key needs to trigger loading the archive.
								$get_data[] = 'yt';
							}
							break;
						case "sermons_nl_youtube_weeksback":
							if($new_value > $old_value){
								$get_data[] = 'yt';
							}
							break;
					}
				}
			}
		}
		if($drop_redundant){
			// delete events that have become redundant
			$rec = self::get_complete_redundant_records();
			if(!empty($rec)){
				self::log('update_daily', 'removing redundant records (n='.count($rec).')');
				foreach($rec as $e){
					$event = sermons_nl_event::get_by_id($e->id);
					$event->delete();
				}
			}
		}
		$msg = esc_html__('Successfully saved. ', 'sermons-nl') .
			(empty($get_data) ? "" : esc_html__('It may take some seconds before new data are loaded from the different archives. If data doesn\'t load as expected, check the log first.','sermons-nl'));
		// return success
		$json = array(
			'ok' => true,
			'action' => 'sermons_nl_config_submit',
			'sucMsg' => $msg,
			'resources' => implode(',', array_unique($get_data)),
			'nonce' => wp_create_nonce('sermons-nl-background-action')
		);
		print wp_json_encode($json);
		wp_die();
	}
	
	public static function admin_view_log_page(){
		// check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}

		global $wpdb;

		print '<div>
        <h2>'.esc_html__('Sermons-NL','sermons-nl').' '.esc_html__('Log page','sermons-nl').'</h2>
        <p>' .
        /* Translators: Number of log retention days. */
        sprintf(esc_html__('Updating data from the sources happens mostly during background processes. To identify a potential cause of issues that you encounter, you can scroll through the logged messages of these update functions from the past %d days.','sermons-nl'), esc_html(self::LOG_RETENTION_DAYS)) . '</p>';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$log = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermons_nl_log ORDER BY id DESC");
        
	    if(empty($log)){
	        print '<p>' . esc_html__('There are no logged messages available.','sermons-nl') . '</p>';
	    }else{
	        print '<p class="sermons-nl-log">';
	        foreach($log as $r => $line){
				$dt = (new DateTime($line->dt, self::$timezone_db))->setTimeZone(wp_timezone())->format("Y-m-d H:i:s");
				print '<span>' . esc_html($r) . ':</span> ' . esc_html($dt) . ' (' . esc_html($line->fun) . ') ' . esc_html($line->log). '<br/>';
    	    }
    	    print '</p>';
	    }
	}
	
    // UPDATE FUNCTIONS HANDLED BY CRON JOBS
    
    // handles (1) verifying data of all youtube broadcasts (2) get/update additional data about pastors (name, town) (3) delete old sermons if there is no broadcast; which should be done daily to avoid exceeding the limit (of youtube) and spare resources
    public static function update_daily(){
        // kerktijden: update the archive
        if(get_option('sermons_nl_kerktijden_id')){
            self::log('update_daily', 'updating kerktijden (backward + pastors)');
            sermons_nl_kerktijden::get_remote_data_backward();
            sermons_nl_kerktijdenpastors::get_remote_data();
        }
        // kerkomroep: update the archive and check whether all items are present
        if(get_option('sermons_nl_kerkomroep_mountpoint')){
            self::log('update_daily', 'updating kerkoproep (all + url validation)');
            sermons_nl_kerkomroep::get_remote_data();
        }
        // youtube: update the entire archive, don't search for new items
        if(get_option('sermons_nl_youtube_channel')){
            self::log('update_daily', 'updating youtube (update all known records)');
            sermons_nl_youtube::get_remote_update_all();
        }
        // delete events that have become redundant
        $rec = self::get_complete_redundant_records();
		if(!empty($rec)){
			self::log('update_daily', 'removing redundant records (n='.count($rec).')');
			foreach($rec as $e){
				$event = sermons_nl_event::get_by_id($e->id);
				$event->delete();
			}
		}
        // delete log items >LOG_RETENTION_DAYS days old
        global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}sermons_nl_log WHERE DATEDIFF(CURDATE(),dt) > %d;",self::LOG_RETENTION_DAYS));
        self::log('update_daily', 'done');
        return true;
    }

    // handles updates of the available sources that need to be done frequently but not immediately
    public static function update_quarterly(){
        // kerktijden
        if(get_option('sermons_nl_kerktijden_id')){
            self::log('update_quarterly', 'updating kerktijden (forward)');
            sermons_nl_kerktijden::get_remote_data_forward();
        }
        // kerkomroep
        if(get_option('sermons_nl_kerkomroep_mountpoint')){
            self::log('update_quarterly','updating kerkomroep (all)');
            sermons_nl_kerkomroep::get_remote_data();
        }
        // youtube: get recent ones. it will include new planned broadcasts, new live broadcases, and update recent items
        if(get_option('sermons_nl_youtube_channel')){
            self::log('update_quarterly','updating youtube (last 10)');
            sermons_nl_youtube::get_remote_data(10);
        }
        self::log('update_quarterly', 'done');
        return true;
    }
    
    // UPDATE FUNCTION CALLED BY THE SITE FUNCTIONS
    
    // only checks for live broadcasts. in case of youtube it only does so when a sermon is close to start
    private static function update_now(){
        $last_update_now_time = get_option("sermons_nl_last_update_time", 0);
		// first check if the last check was >60 seconds ago. to avoid running out of quota for the youtube api.
        // perhaps the interval will be a setting later
        if(time() - $last_update_now_time >= 60){
            update_option('sermons_nl_last_update_time', time(), false);
            if(get_option('sermons_nl_kerkomroep_mountpoint')){
                self::log('update_now', 'updating kerkomroep (most recent record)');
                // checking only the first records is reasonably fast (<0.05 sec) so we can do it during page load
                sermons_nl_kerkomroep::get_remote_data(true);
            }
            if(get_option('sermons_nl_youtube_channel')){
                // check if any sermon is close to start (30 min), or that 
                // should have started, and is not broadcasting yet, or that is
                // live. include overnight broadcasts: check yesterday and today
                $data = self::get_complete_records_by_dates(gmdate("Y-m-d", strtotime("now -1 day")), gmdate("Y-m-d"), true);
                foreach($data as $item){
                    $time_to = strtotime($item->dt_start) - time();
                    if($item->yt_live || ($item->yt_planned && $time_to < 1800 && $time_to > -3600 && !$item->yt_dt_actual)){
                        // update the last items. 
                        // live and planned ones should be on top of the list, so we only need those planned + 1 that may be live
                        $planned = sermons_nl_youtube::get_planned(true);
                        $n = count($planned) + 1;
                        self::log('update_now', 'updating youtube (last '.$n.')');
                        sermons_nl_youtube::get_remote_data($n);
                        break;
                    }
                }
            }
            self::log('update_now', 'done');

        }
        return true;
    }

    // SITE FUNCTIONS
    

    // shortcode function to list sermons
    public static function html_sermons_list(array $atts=[], ?string $content=null){
        // default attributes
		$atts = shortcode_atts( array(
			'offset' => null, // date string for the first record to be included in the initial view, e.g. "now -14 days". At least one of the parameters offset or ending should be provided
			'ending' => null, // date string for the last record to be included in the initial view, e.g. "now +14 days".
			'count' => 10, // The number of items to show. This is ignored if both parameters offset and ending are provided.
			'datefmt' => 'long', // either "long" (weekday and month written out) or "short" (abbreviated day and numeric month) or a valid date format for the php date function
			'more-buttons' => 0, // whether to include the show-more buttons; set to 1 to enable these buttons
			'plugin-logo' => 0 // whether to display the plugin logo; set to 1 to enable the logo. Note that other logos are obligatory when using the service, these are always shown.
		), $atts);
		
		$get_date = function($d){
		   if($d === null) return null;
		   try{
		       $dt = new DateTime($d, wp_timezone());
		   }catch(DateMalformedStringException | Exception $e){
		       // probably bad date format
		       return null;
		   }
		   return $dt->format("Y-m-d");
		};

		// check if any service is active
		$kt_id = (int)get_option("sermons_nl_kerktijden_id");
		$ko_mp = (int)get_option("sermons_nl_kerkomroep_mountpoint");
		$yt_ch = get_option("sermons_nl_youtube_channel");
		if(!$kt_id && !$ko_mp && !$yt_ch){
			return "<p>" . esc_html__("Sermons-NL has not been configured yet. Please check the Sermons-NL configuration page in the WordPress dashboard.","sermons-nl") . "</p>";
		}
		
		$dt1 = $get_date($atts['offset']);
		$dt2 = $get_date($atts['ending']);
		$count = (int) $atts['count'];
		$datefmt = esc_html((string)$atts['datefmt']);
		$morebuttons = (int)$atts['more-buttons'];

		if($atts['offset'] !== null && !$dt1) return esc_html__("Error: Parameter `offset` is an invalid date string in the shortcode.",'sermons-nl');
		if($atts['ending'] !== null && !$dt2) return esc_html__("Error: Parameter `ending` is an invalid date string in the shortcode.",'sermons-nl');
		if(!$dt1 && !$dt2) return esc_html__("Error: At least one of the parameters `offset` and/or `ending` should be provided in the shortcode.",'sermons-nl');
		if((!$count || $count <= 0) && (!$dt1 || !$dt2)) return esc_html__("Error: If one of the parameters `offset` or `ending` is not provided in the shortcode, parameter `count` should be a positive number.",'sermons-nl');
		
		// check new live broadcasts
		self::update_now();
		
		// get data
		$data = self::get_complete_records_by_dates($dt1, $dt2, true);
		
		// determine which (if any) of the items in data should be opened initially
		$live = null;
		$planned = null;
		$latest = null;
		foreach($data as $i => $item){
		    if($item->ko_live || $item->yt_live) $live = $i;
		    elseif($item->yt_planned && $planned === null) $planned = $i;
		    elseif($item->dt_start < gmdate("Y-m-d H:i:s")) $latest = $i;
		}
		if($live !== null){
		    $data[$live]->display_open = true;
		}elseif($planned !== null){
		    $data[$planned]->display_open = true;
		}else{
		    $data[$latest]->display_open = true;
		}
		
		// update count if $data includes less than the number to display, and negative if it is a backward selection
		$count = min($count, count($data));
		if($dt1 === null){
		    // use a negative count to select backward from the end of $data
		    $count = -$count;
		}elseif($dt1 && $dt2){
		    // in this case it could be more than the count setting
		    $count = count($data);
		}

		// ready to build html
        $html = '
		<div>';
		
		if($morebuttons){ 
		    // check if there are earlier sermons
		    $showit = false;
		    if($dt1 === null){
		        // can rely on $data being longer than -$count ($count is negative)
		        if(count($data) > -$count) $showit = true;
		    }else{
		        // need to check in db
		        $num_rec = self::num_complete_records_from_date($dt1, 'up');
		        if($num_rec) $showit = true;
		    }
		    if($showit){
    			$html .= '<div><a href="javascript:;" id="sermons_nl_more_up" onclick="sermons_nl.showmore(\'up\');">' . esc_html__("Load earlier sermons", 'sermons-nl') . '</a></div>';
		    }
		}

		$html .= '<ul id="sermons_nl_list" list-datefmt="'.esc_attr($datefmt).'">';
		
		$html .= self::html_list_items($data, $count, $datefmt, false);
	
		$html .= '</ul>';

		if($morebuttons){ 
		    // check if there are later sermons
		    $showit = false;
		    if($dt2 === null){
		        // can rely on $data being longer than $count
		        if(count($data) > $count) $showit = true;
		    }else{
		        // need to check in db
		        $num_rec = self::num_complete_records_from_date($dt2, 'down');
		        if($num_rec) $showit = true;
		    }
		    if($showit){
    			$html .= '
    			<div>
					<a href="javascript:;" id="sermons_nl_more_down" onclick="sermons_nl.showmore(\'down\');">' . esc_html__("Load later sermons", 'sermons-nl') . '</a>
				</div>';
		    }
		}
		
		// add logos to general source pages
		$html .= self::add_logos(
			$kt_id,
			$ko_mp,
			null,
			$yt_ch,
			$atts['plugin-logo'] != 0
		);

		$html .= '
		</div>';

        return $html;
    }

    private static function add_logos(?int $kt_id, ?int $ko_mp, ?string $yt_vid, ?string $yt_ch, bool $plugin_logo=false, bool $div_embed=true){
		$html = '';
		$logos = array();
		if($kt_id){
			$logos[] = array(
				"url" => "https://www.kerktijden.nl/gemeente/$kt_id/",
				"img" => "logo_kerktijden.svg",
				"pad" => 9,
				/* Translators: service type. */
				"txt" => sprintf(esc_html__("Open the %s website","sermons-nl"), "Kerktijden")
			);
		}
		if($ko_mp){
			$logos[] = array(
				"url" => "https://www.kerkomroep.nl/kerken/$ko_mp",
				"img" => "logo_kerkomroep.svg",
				"pad" => 11,
				/* Translators: service type. */
				"txt" => sprintf(esc_html__("Open the %s website","sermons-nl"), "Kerkomroep")
			);
		}
		if(($yt_vid)){
			$logos[] = array(
				"url" => "https://www.youtube.com/watch?v=$yt_vid",
				"img" => "logo_youtube.jpg",
				"pad" => 6,
				"txt" => esc_html__("Watch this video on YouTube","sermons-nl"));
		}
		if($yt_ch){
			$logos[] = array(
				"url" => "https://www.youtube.com/channel/$yt_ch",
				"img" => "logo_youtube.jpg",
				"pad" => 6,
				"txt" => esc_html__("Open the YouTube channel","sermons-nl")
			);
		}
		$html .= '
				<' . ($div_embed ? 'div':'span') . ' class="sermons-nl-logos">
					' . esc_html__("Source:", "sermons-nl") . '<br/>';
		foreach($logos as $link){
			$html .= '
					<a href="' . $link["url"] . '" target="_blank" title="'.$link["txt"].'"><img src="' . plugin_dir_url(__FILE__) . 'img/' . $link['img'] . '" alt="' . $link["txt"] . '"' . (!empty($link['pad']) ? ' style="padding: '.$link['pad'].'px 0;"':'') . '/></a>';
		}
		if($plugin_logo){
			$html .= '
					<a href="'.self::PLUGIN_URL.'" target="_blank" title="' . esc_html__("Find out more about the Sermons-NL plugin","sermons-nl") . '"><img src="' . plugin_dir_url(__FILE__) . 'img/logo_sermons_nl_' . (get_locale() == 'nl_NL' ? 'NL' : 'EN') . '.png" alt="' . esc_html__("Sermons-NL logo","sermons-nl") . '"/></a>';
		}
		$html .= '
				</' . ($div_embed ? 'div':'span') . '>';
		return $html;
	}

	// ajax server handler to check current status of live broadcasts and new live broadcasts
	// no nonce verification needed because no input data are stored, only publicly available data retrieved
	public static function check_status(){
	    
		ob_clean();
		
		// check new live broadcasts
		self::update_now();
		
		// which to update
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$check_list = (isset($_GET['check_list']) ? (int) $_GET['check_list'] : 0);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$check_lone = (isset($_GET['check_lone']) ? (int) $_GET['check_lone'] : 0);

		// get data
		$items = array();
		$events = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if(isset($_GET['live']) && is_array($_GET['live'])){
			$live = array_map(function($id_str){return sanitize_text_field($id_str);}, filter_input(INPUT_GET, 'live', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY));
		    foreach($live as $id_str){
                preg_match_all("/^sermons_nl_([a-z]+)_(audio_|video_|)([0-9]+)(_lone|)$/", $id_str, $matches);
                $type = $matches[1][0];
                $id = $matches[3][0];
				$item = self::get_item_by_type($type, $id);
                $items[] = $item;
            }
        }
        $events_client = array();
		foreach($items as $item){
			if($item->event_id && array_search($item->event, $events) === false && $item->event->include){
				$events[] = $item->event;
				$events_client[] = $item->event;
			}
		}


        $yt_live = sermons_nl_youtube::get_live();
		if($yt_live !== null){
			$items[] = $yt_live;
			if($yt_live->event_id && array_search($yt_live->event, $events) === false && $yt_live->event->include){
				$events[] = $yt_live->event;
			}
		}
		$ko_live = sermons_nl_kerkomroep::get_live();
		if($ko_live !== null){
			$items[] = $ko_live;
			if($ko_live->event_id && array_search($ko_live->event, $events) === false && $ko_live->event->include){
				$events[] = $ko_live->event;
			}
		}

		$items = array_unique($items, SORT_REGULAR);

		// get html of all selected events
		$json = array(
			'call' => "sermons_nl_checkstatus",
			'events_list' => (count($events) > 0 && $check_list ? array() : null),
			'events_lone' => (count($events) > 0 && $check_lone ? array() : null),
			'items_lone' => (count($items) > 0 && $check_lone ? array() : null)
		);
		if($check_lone){
			foreach($events as $event){
				$json['events_lone'][] = array(
					'id' => 'sermons_nl_event_'.$event->id.'_lone_links',
					'html' => self::html_event_links($event, true)
				);
			}
			foreach($items as $item){
				$id = 'sermons_nl_item_'.$item->type.'_'.$item->id.'_lone';
				switch($item->type){
					case 'youtube':
						$html = self::html_yt_video_link($item, true);
						break;
					case 'kerkomroep':
						$html = self::html_ko_audio_video_link($item, true);
						break;
				}
				$json['items_lone'][] = array(
					'id' => $id,
					'html' => $html
				);
			}
		}
		if($check_list){
			foreach($events as $event){
				$json['events_list'][] = array(
					'id' => 'sermons_nl_event_'.$event->id.'_links',
					'html' => self::html_event_links($event, false),
					'audio_class' => self::css_audio_class($event),
					'video_class' => self::css_video_class($event)
				);
				if($event->live && array_search($event, $events_client) === false){
					// perhaps items is not in the list yet. If so, I want it to be added in the right place
					$datefmt = 'short'; // replace by javascript input
					$i = count($json['events_list'])-1;
					$json['events_list'][$i]['event_html'] = self::html_list_items(array($event), 1, $datefmt, false);
					$json['events_list'][$i]['event_timestamp'] = (new DateTime($event->dt, sermons_nl::$timezone_db))->getTimestamp();
					$event->timestamp;
				}
			}
		}
		print wp_json_encode($json);
		wp_die();
	}

	// AJAX RESPONSE TO CLICK FOR MORE ACTION
	public static function show_more(){
		ob_clean();
		$json = array('call' => "sermons_nl_showmore");
		//  Processing form data without nonce verification OK (logged in user with permission & the function is not saving any input)
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$direction = sanitize_text_field(filter_input(INPUT_GET, 'direction'));
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = sanitize_text_field(filter_input(INPUT_GET, 'current'));
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$datefmt = sanitize_text_field(filter_input(INPUT_GET, 'datefmt'));
		if($direction === null || $current === null){
		    $json["error"] = "insufficient parameters provided";
		}else{
    		$datefmt = (empty($datefmt) ? 'long' : (string)$datefmt);
    		$direction = (string)$direction;
    		$json['direction'] = $direction;
    		$count = 10; // fixed (optional to make setting later)
    		$json['current'] = $current;
    		$current = (int) str_replace("sermons_nl_event_", "", $current);
    		$cur_item = self::get_complete_records_by_ids($current);
    		$cur_dt = $cur_item[0]->dt_start;
    		$json['cur_dt'] = $cur_dt;
    		if($direction == 'up'){
    		    $data = self::get_complete_records("dt_start < '$cur_dt'");
    		    $data = array_slice($data, -$count);
    		    if(!empty($data)) $last_date = $data[array_key_first($data)]->dt_start;
    		}elseif($direction == 'down'){
    		    $data = self::get_complete_records("dt_start > '$cur_dt'");
    		    $data = array_slice($data, 0, $count);
    		    if(!empty($data)) $last_date = $data[array_key_last($data)]->dt_start;
    		}else{
    		    $json['error'] = "Wrong direction parsed";
    		}
    		if(empty($json['error'])){
    		    if(!empty($data)){
        		    $json['count'] = count($data);
            		$json['html'] = self::html_list_items($data, min(count($data),$count), $datefmt);
            		$num_rec = self::num_complete_records_from_date($last_date, $direction);
        		    $json['num_more_rec'] = $num_rec;
    		    }else{
    		        $json['count'] = 0;
    		        $json['num_more_rec'] = 0;
    		        $json['error'] = "No records obtained";
    		    }
    		}
		}
		print wp_json_encode($json);
		wp_die();
	}

	private static function css_audio_class($event){
		if($event instanceof sermons_nl_event){
			$item = $event->kerkomroep;
			$url = ($item ? $item->audio_url : null);
			$live = ($item ? $item->live : null);
		}else{
			$url = $event->ko_audio_url;
			$live = $event->ko_live;
		}
		return 'sermons-nl-av' . ($url ? ' sermons-nl-audio' . ($live ? '-live' : '') : '');
	}

	private static function css_video_class($event){
		if($event instanceof sermons_nl_event){
			$ko = $event->kerkomroep;
			$ko_url = ($ko ? $ko->video_url : null);
			$ko_live = ($ko && $ko_url ? $ko->live : null);
			$yt = $event->youtube;
			$yt_live = ($yt ? $yt->live : null);
			$yt_planned = ($yt ? $yt->planned : null);

		}else{
			$ko_url = $event->ko_video_url;
			$ko_live = $ko_url && $event->ko_live;
			$yt = $event->yt_video_id;
			$yt_live = $event->yt_live;
			$yt_planned = $event->yt_planned;
		}
		return 'sermons-nl-av' . ($yt || $ko_url ? ' sermons-nl-video' . ($yt_live || $ko_live ? '-live' : ($yt_planned ? '-planned' : '')) : '');
	}

    // html list items - function used by above site functions
	private static function html_list_items(array $data, int $count, string $datefmt, bool $standalone=false){
		if($count > 0){
		    $seq = range(0, $count-1);
		}elseif($count < 0){
    		$seq = range(count($data) + $count, count($data)-1);
		}else{
		    return ''; 
		}
		$html = '';
		foreach($seq as $i){
			$event = $data[$i];
			$dt = $event->dt_start;
			if($standalone){
				$html .= '<div>';
			}else{
				$html .= '<li id="sermons_nl_event_' . esc_attr($event->id) . '"' . (!empty($event->display_open) ? ' class="sermons-nl-open"' : '') . ' onclick="sermons_nl.toggledetails(this);" event-timestamp="'.(new DateTime($dt, sermons_nl::$timezone_db))->getTimestamp().'">';
				$html .= '<span class="'.esc_attr(self::css_audio_class($event)).'"></span>';
				$html .= '<span class="'.esc_attr(self::css_video_class($event)).'"></span>';
			}
			$html .= '<span class="sermons-nl-dt">' . esc_html(ucfirst(self::datefmt($datefmt, $dt))) . ' </span><span class="sermons-nl-pastor">' . esc_html($event->pastor) . ' </span><span class="sermons-nl-type">' . ($event->sermontype == "Reguliere dienst" ? "" : esc_html($event->sermontype)) . '</span>';
			if(!$standalone){
				$html .= '<div class="sermons-nl-details"><div>';
			}
			$html .= '<div id="sermons_nl_event_'.esc_attr($event->id).($standalone?'_lone':'').'_links" class="sermons-nl-links">';
			if(!($event->yt_video_id || $event->ko_id)){
				$html .= ($event->kt_cancelled ? esc_html__('This sermon has been cancelled.','sermons-nl') : esc_html__('There are no broadcasts for this sermon.','sermons-nl'));
			}
			else{
				$html .= self::html_event_links($event, $standalone);
			}
			$html .= '</div>';
    		$html .= '<div class="sermons-nl-description">' . nl2br(esc_html($event->description)) . '</div>';
			if($standalone){
				$html .= '</div>';
			}else{
				$html .= '</div></div></li>';
			}
		}
		return $html;
	}
	
	// container to save the items that have already been displayed on the page to avoid duplication
	private static $standalone_items = array();

	// shortcode function to list a single item
	public static function html_sermons_item(array $atts=[], ?string $content=null){
		// default attributes
		$atts = shortcode_atts( array(
			'type' => null, // either kerkomroep or youtube
			'id' => null // the item id to be shown
		), $atts);
		$item_type = (string)$atts['type'];
		$item_id = (int)$atts['id'];
		if(empty($item_type) || false === array_search($item_type, array('kerkomroep','youtube')) || empty($item_id)) return self::INVALID_SHORTCODE_TEXT;

		$item = self::get_item_by_type($item_type, $item_id);

		if(!$item){
			return self::INVALID_SHORTCODE_TEXT;
		}

		// protect the page from having twice the same standalone item
		$item_str = sprintf("%s-%d", $item_type, $item_id);
		if(false !== array_search($item_str, self::$standalone_items)){
			return str_replace(']', ': duplication]', self::INVALID_SHORTCODE_TEXT);
		}
		self::$standalone_items[] = $item_str;

		$dt = $item->dt;
		if($dt) $dt = self::datefmt('long',$dt);

		$html = '
		<div class="sermons_nl_item_lone">
		<div>
		<p>' . esc_html(ucfirst($dt)) . ' (';
		if($item_type == 'kerkomroep'){
			$html .= esc_html($item->pastor);
		}elseif($item_type == 'youtube'){
			$html .= esc_html($item->title);
		}
		$html .= ')</p>
		<div id="sermons_nl_item_'.esc_attr($item_type).'_'.esc_attr($item_id).'_lone" class="sermons-nl-links">';

		if($item_type == 'kerkomroep'){
			/* escaping is done by function */
			$html .= self::html_ko_audio_video_link($item, true);
		}elseif($item_type == 'youtube'){
			/* escaping is done by function */
			$html .= self::html_yt_video_link($item, true);
		}

		$html .= '
		</div>
		</div>';

		// add logo
		/* escaping is done by function */
		$html .= self::add_logos(
			null,
			($item_type == 'kerkomroep' ? get_option('sermons_nl_kerkomroep_mountpoint') : null),
								 ($item_type == 'youtube' ? $item->video_id : null),
								 null,
						   false // no plugin logo
		);

		$html .= '
		</div>';
		return $html;
	}

	// shortcode function to list the items of a single event
	public static function html_sermons_event(array $atts=[], ?string $content=null){
		// default attributes
		// in future perhaps (1) add date format option, currently 'long' by default, (2) add plugin logo option, currently not shown
		$atts = shortcode_atts( array(
			'id' => null // the item id to be shown
		), $atts);
		$event_id = (int)$atts['id'];
		if(empty($event_id)) return self::INVALID_SHORTCODE_TEXT;

		$event = self::get_complete_records_by_ids(array($event_id));
		if(empty($event)){
			return self::INVALID_SHORTCODE_TEXT;
		}

		// protect the page from having twice the same standalone item
		$items = array(
			'kerktijden' => ($event[0]->kt_id ? sprintf("kerktijden-%d", $event[0]->kt_id) : null),
					   'kerkomroep' => ($event[0]->ko_id ? sprintf("kerkomroep-%d", $event[0]->ko_id) : null),
					   'youtube' => ($event[0]->yt_id ? sprintf("youtube-%d", $event[0]->yt_id) : null)
		);
		foreach($items as $item_str){
			if($item_str){
				if(false !== array_search($item_str, self::$standalone_items)){
					return str_replace(']', ': duplication]', self::INVALID_SHORTCODE_TEXT);
				}
				self::$standalone_items[] = $item_str;
			}
		}

		$html = '
		<div class="sermons_nl_event_lone">';

		/* escaping is done by the function */
		$html .= self::html_list_items($event, 1, 'long', true);

		/* escaping is done by the function */
		$html .= self::add_logos(
			($items['kerktijden'] ? get_option('sermons_nl_kerktijden_id') : null),
								 ($items['kerkomroep'] ? get_option('sermons_nl_kerkomroep_mountpoint') : null),
								 ($items['youtube'] ? $event[0]->yt_video_id : null),
								 null, // no yt channel
						   false // no logo
		);

		$html .= '
		</div>';
			return $html;
	}

	private static function html_event_links($event, bool $standalone=false){
		if($event instanceof sermons_nl_event){
			$ko_audio_url = ($event->kerkomroep ? $event->kerkomroep->audio_url : null);
			$ko_video_url = ($event->kerkomroep ? $event->kerkomroep->video_url : null);
			$yt_id = ($event->youtube ? $event->youtube->id : null);
		}else{
			$ko_audio_url = $event->ko_audio_url;
			$ko_video_url = $event->ko_video_url;
			$yt_id = $event->yt_id;
		}
	    $html = '';
	    if($ko_audio_url || $ko_video_url){
			/* escaping is done by the function */
			$html .= self::html_ko_audio_video_link($event, $standalone);
		}
		if($yt_id){
			/* escaping is done by the function */
			$html .= self::html_yt_video_link($event, $standalone);
		}
        return $html;
	}

	private static function html_ko_audio_video_link($data, $standalone){
		if($data instanceof sermons_nl_event){
			$item = $data->kerkomroep;
			if(!$item) return '';
		}elseif($data instanceof sermons_nl_kerkomroep){
			$item = $data;
		}else{
			$item = null;
		}
		if($item){
			$ko_id = $item->id;
			$ko_live = $item->live;
			$ko_audio_url = $item->audio_url;
			$ko_audio_mimetype = $item->audio_mimetype;
			$ko_video_url = $item->video_url;
			$ko_video_mimetype = $item->video_mimetype;
		}else{
			$ko_id = $data->ko_id;
			$ko_live = $data->ko_live;
			$ko_audio_url = $data->ko_audio_url;
			$ko_audio_mimetype = $data->ko_audio_mimetype;
			$ko_video_url = $data->ko_video_url;
			$ko_video_mimetype = $data->ko_video_mimetype;
		}
		$html = '';
		if($ko_audio_url){
			$html .= '<p id="sermons_nl_kerkomroep_audio_'.esc_attr($ko_id).($standalone?'_lone':'').'" class="sermons-nl-audio' . ($ko_live ? '-live' : '') . '"><a id="ko_audio_'.esc_attr($ko_id).($standalone?'_lone':'').'" href="' . esc_url($ko_audio_url) . '" target="_blank" title="' .
					/* Translators: service type. */
					sprintf(esc_html__("Listen to %s audio","sermons-nl"),"Kerkomroep") . '" onclick="return !sermons_nl.playmedia(this, \'' . esc_attr($ko_audio_mimetype) . '\', \'ko-audio\''.($standalone?',true':'').');">Kerkomroep' . ($ko_live ? ' (' . esc_html__('live','sermons-nl') . ')' : '') . '</a></p>';
		}
		if($ko_video_url){
			$html .= '<p id="sermons_nl_kerkomroep_video_'.esc_attr($ko_id).($standalone?'_lone':'').'" class="sermons-nl-video' . ($ko_live ? '-live' : '') . '"><a id="ko_video_'.esc_attr($ko_id).($standalone?'_lone':'').'" href="' . esc_url($ko_video_url) . '" target="_blank" title="' .
					/* Translators: service type. */
					sprintf(esc_html__('Watch %s video','sermons-nl'),"Kerkomroep") . '" onclick="return !sermons_nl.playmedia(this, \'' . esc_attr($ko_video_mimetype) . '\', \'ko-video\''.($standalone?',true':'').');">Kerkomroep' . ($ko_live ? ' (' . esc_html__('live','sermons-nl') . ')' : '') . '</a></p>';
		}
		return $html;
	}

	private static function html_yt_video_link($data, $standalone){
		if($data instanceof sermons_nl_event){
			$item = $data->youtube;
			if(!$item) return '';
		}elseif($data instanceof sermons_nl_youtube){
			$item = $data;
		}else{
			$item = null;
		}
		if($item){
			$yt_id = $item->id;
			$yt_live = $item->live;
			$yt_planned = $item->planned;
			$yt_video_id = $item->video_id;
		}else{
			$yt_id = $data->yt_id;
			$yt_live = $data->yt_live;
			$yt_planned = $data->yt_planned;
			$yt_video_id = $data->yt_video_id;
		}
		$html = '<p id="sermons_nl_youtube_'.esc_attr($yt_id).($standalone?'_lone':'').'" class="sermons-nl-video' . ($yt_live ? '-live' : ($yt_planned ? '-planned' : '')) . '"><a id="yt_video_'.esc_attr($yt_id).($standalone?'_lone':'').'" href="https://www.youtube.com/watch?v='.esc_attr($yt_video_id).'" target="_blank" title="' .
					/* Translators: service type. */
					sprintf(esc_html__("Watch %s video","sermons-nl"), "YouTube") . '" onclick="return !sermons_nl.playmedia(this, \'video/youtube\',\'yt-video\''.($standalone?',true':'').');">YouTube' . ($yt_live ? ' (' . esc_html__('live','sermons-nl') . ')' : ($yt_planned ? ' (' . esc_html__('planned','sermons-nl') . ')' : '')) . '</a>';
		if(!$standalone){
			$html .= ' <a href="https://www.youtube.com/watch?v='.esc_attr($yt_video_id).'" target="_blank" title="' . esc_html__("Open video on YouTube","sermons-nl") . '"><img src="' . esc_url(plugin_dir_url(__FILE__)) . 'img/icon_newwindow.png" style="height:15px;"/></a>';
		}
		$html .= '</p>'; // open in new window icon: https://commons.wikimedia.org/wiki/File:OOjs_UI_icon_newWindow-ltr.svg
		return $html;
	}
	
	// formats the date including the possible use of "long" and "short" as date formats. 
	// For "long" and "short" it will output days (and months) in the local language if available.
	// The function assumes that $datetime is in UTC (set at the end of this script) and will output the datetime in the local time zone (wp setting).
	private static function datefmt(string $fmt, string $datetime){
	    $time = new DateTime($datetime, self::$timezone_db);
		$timestamp = $time->getTimestamp();
	    if($fmt == 'long'){
			$fmt = 'l j F Y H:i';
		}elseif($fmt == 'short'){
			$fmt = 'D d-m-Y H:i';
		}
		return wp_date($fmt, $timestamp);
	}

    // adds js and css to the site
    public static function add_site_scripts_and_styles(){
		wp_enqueue_style('sermons-nl', plugin_dir_url(__FILE__) . 'css/site.css', array(), '0.3');
		$url = esc_url(plugin_dir_url(__FILE__));
		$cs = array_map(function($k){
			$c = get_option("sermons_nl_icon_color_".$k);
			if(!preg_match('/^#[0-9a-fA-F]{6}$/',$c)){
				#fall back to default color
				$c = self::OPTION_NAMES['sermons_nl_icon_color_'.$k]['default'];
			}
			return str_replace("#","",$c);
		}, array('archive'=>'archive','live'=>'live','planned'=>'planned'));
		$css_tpl = '.sermons-nl-%1$s{background-image: url("%2$sicon.php?c=%3$s&m=%4$s");} ';
		$css = sprintf($css_tpl, 'audio', $url, $cs['archive'], 'a');
		$css .= sprintf($css_tpl, 'audio-live', $url, $cs['live'], 'a');
		$css .= sprintf($css_tpl, 'video', $url, $cs['archive'], 'v');
		$css .= sprintf($css_tpl, 'video-live', $url, $cs['live'], 'v');
		$css .= sprintf($css_tpl, 'video-planned', $url, $cs['planned'], 'v');
		wp_add_inline_style('sermons-nl', $css);
		wp_enqueue_script('sermons-nl', plugin_dir_url(__FILE__) . 'js/site.js', array('jquery'), '0.3', true);
		wp_add_inline_script('sermons-nl', 'sermons_nl.admin_url = "' . esc_url(admin_url( 'admin-ajax.php')) . '"; sermons_nl.plugin_url = "' . esc_url(plugin_dir_url(__FILE__)) . '"; sermons_nl.check_interval = ' . esc_attr(self::CHECK_INTERVAL) . ';');
	}

    // HOOK FUNCTIONS
    
    // activation
    public static function activate_plugin(){
        // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}

        // prepare create database tables
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        
        // create table for broadcast events
        $sql = sermons_nl_event::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        
        // create tables for kerktijden scheduled sermons and pastors
        $sql = sermons_nl_kerktijden::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        $sql = sermons_nl_kerktijdenpastors::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        
        // create table for kerkomroep broadcasts
        $sql = sermons_nl_kerkomroep::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        
        // create table for youtube broadcasts
        $sql = sermons_nl_youtube::query_create_table($prefix, $charset_collate);
        dbDelta($sql);

		// create table for log
		$sql = "CREATE TABLE {$prefix}sermons_nl_log (
			id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
			dt datetime NULL,
			fun varchar(255) DEFAULT '' NOT NULL,
			log varchar(255) DEFAULT '' NOT NULL,
			PRIMARY KEY  (id)
			) $charset_collate;";
		dbDelta($sql);

        // set plugin options to the default if they don't exist
        foreach(self::OPTION_NAMES as $opt_name => $args){
            if(null === get_option($opt_name, null)){
                update_option($opt_name, (isset($args['default']) ? $args['default'] : null));
            }
        }

        // set scheduled jobs
        if(!wp_next_scheduled('sermons_nl_cron_quarterly'))
            wp_schedule_event(time(), 'fifteen_minutes', "sermons_nl_cron_quarterly");
        if(!wp_next_scheduled('sermons_nl_cron_daily'))
    		wp_schedule_event(strtotime('tomorrow 03:00:00'), 'daily', 'sermons_nl_cron_daily');

    }
    
    public static function add_cron_interval(array $schedules){
		$schedules['fifteen_minutes'] = array(
			'interval' => 900,
			'display'  => 'Every 15 minutes');
		return $schedules;
	}
    
    // deactivation
    public static function deactivate_plugin(){
        // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
        // stop scheduled jobs
        $timestamp = wp_next_scheduled('sermons_nl_cron_quarterly');
        if($timestamp) wp_unschedule_event($timestamp, 'sermons_nl_cron_quarterly');
        $timestamp = wp_next_scheduled('sermons_nl_cron_daily');
        if($timestamp) wp_unschedule_event($timestamp, 'sermons_nl_cron_daily');
    }
    
    // uninstall
    public static function uninstall_plugin(){
        // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
        // delete database tables
        global $wpdb;
        foreach(
            array(
                "events",
                "kerktijden",
                "kerktijdenpastors",
                "kerkomroep",
                "youtube",
				"log"
            ) as $surname){
            $table_name = $wpdb->prefix . "sermons_nl_" . $surname;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query("DROP TABLE IF EXISTS `$table_name`");
        }

        // delete plugin options
        foreach(self::OPTION_NAMES as $opt_name => $args){
            delete_option($opt_name);
        }
    }

    // RECORD ACTIVITIES RELATED TO LOADING REMOTE DATA
    public static function log(string $fun, string $log){
		$data = array(
			'dt' => (new DateTime("now", self::$timezone_db))->format("Y-m-d H:i:s"),
			'fun' => $fun,
			'log' => $log
		);
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert($wpdb->prefix . 'sermons_nl_log', $data);
		return $data;
	}
    
}

sermons_nl::$timezone_db = new DateTimeZone("UTC");
sermons_nl::$timezone_ko = sermons_nl::$timezone_kt = new DateTimeZone("Europe/Amsterdam");

// include other classes
require_once(plugin_dir_path(__FILE__) . 'event.php');
require_once(plugin_dir_path(__FILE__) . 'kerktijden.php');
require_once(plugin_dir_path(__FILE__) . 'kerkomroep.php');
require_once(plugin_dir_path(__FILE__) . 'youtube.php');

// ACTIVATION, DEACTIVATION AND UNINSTALL HOOKS
register_activation_hook(__FILE__, array('sermons_nl', 'activate_plugin'));
register_deactivation_hook(__FILE__, array('sermons_nl', 'deactivate_plugin'));
register_uninstall_hook(__FILE__, array('sermons_nl', 'uninstall_plugin'));

// FILTERS TO GET THE CRON JOBS DONE (UPDATING FROM THE SOURCES)
add_filter('cron_schedules', array('sermons_nl','add_cron_interval'));
add_action('sermons_nl_cron_quarterly', array('sermons_nl','update_quarterly'));
add_action('sermons_nl_cron_daily', array('sermons_nl','update_daily'));

// ACTION NEEDED TO LET THE YOUTUBE / KERKOMROEP / KERKTIJDEN ARCHIVES BE LOADED IN THE BACKGROUND
add_action('wp_ajax_sermons_nl_get_remote_data_in_background', array('sermons_nl', 'get_remote_data_in_background'));

// ACTIONS FOR ADMIN
add_action('admin_init', array('sermons_nl','register_settings'));
add_action('admin_menu', array('sermons_nl','add_admin_menu'));
add_action('admin_enqueue_scripts', array('sermons_nl','add_admin_scripts_and_styles'));
add_action('wp_ajax_sermons_nl_admin_navigate_table', array('sermons_nl','admin_navigate_table'));
add_action('wp_ajax_sermons_nl_admin_show_details', array('sermons_nl','admin_show_details'));
add_action('wp_ajax_sermons_nl_admin_link_item_to_event', array('sermons_nl','link_item_to_event'));
add_action('wp_ajax_sermons_nl_admin_unlink_item', array('sermons_nl','unlink_item'));
add_action('wp_ajax_sermons_nl_admin_delete_event', array('sermons_nl','delete_event'));
add_action('wp_ajax_sermons_nl_submit_update_event', array('sermons_nl','sermons_nl_submit_update_event'));
add_action('wp_ajax_sermons_nl_config_submit', array('sermons_nl','config_submit'));

// ACTIONS AND SHORTCODES FOR SHOWING CONTENT ON THE WEBSITE
// shortcodes
add_shortcode('sermons-nl-list', array('sermons_nl', 'html_sermons_list'));
add_shortcode('sermons-nl-event', array('sermons_nl', 'html_sermons_event'));
add_shortcode('sermons-nl-item', array('sermons_nl', 'html_sermons_item'));
// html, js and css
add_action('wp_enqueue_scripts', array('sermons_nl','add_site_scripts_and_styles'));

// ajax: show more button
add_action('wp_ajax_sermons_nl_showmore', array('sermons_nl','show_more'));
add_action('wp_ajax_nopriv_sermons_nl_showmore', array('sermons_nl','show_more'));
// ajax: check status
add_action('wp_ajax_sermons_nl_checkstatus', array('sermons_nl','check_status'));
add_action('wp_ajax_nopriv_sermons_nl_checkstatus', array('sermons_nl','check_status'));
