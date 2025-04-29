<?php

/*
	Plugin Name: Sermons-NL
	Plugin URI: 
	Description: List planned and broadcasted Dutch church services in a convenient way
	Version: 0.1
	Author: Henri van Werkhoven
	Author URI: https://github.com/henrivanwerkhoven/Sermons-NL
	License: GPL2
	Text Domain: sermons-nl
	Domain Path: /languages
*/

class sermonsNL{

	const PLUGIN_URL = "https://github.com/henrivanwerkhoven/Sermons-NL";
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
        "sermonsNL_kerktijden_id"                  => array('type' => 'integer', 'default' => null),
        "sermonsNL_kerktijden_weeksback"           => array('type' => 'integer', 'default' => 52),
        "sermonsNL_kerktijden_weeksahead"          => array('type' => 'integer', 'default' => 52),
        "sermonsNL_kerkomroep_mountpoint"          => array('type' => 'integer', 'default' => null),
        //"sermonsNL_kerkdienstgemist_rssid"         => array('type' => 'integer', 'default' => null),
        //"sermonsNL_kerkdienstgemist_audiostreamid" => array('type' => 'integer', 'default' => null),
        //"sermonsNL_kerkdienstgemist_videostreamid" => array('type' => 'integer', 'default' => null),
        "sermonsNL_youtube_channel"                => array('type' => 'string',  'default' => null),
        "sermonsNL_youtube_key"                    => array('type' => 'string',  'default' => null),
		"sermonsNL_youtube_weeksback"              => array('type' => 'integer', 'default' => 52),
		"sermonsNL_last_update_time"               => array('type' => 'integer', 'default' => 0)
    );

	public static function register_settings(){
		foreach(self::OPTION_NAMES as $optname => $args){
			register_setting('sermonsNL_options_group', $optname, $args);
		}
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
	    FROM {$wpdb->prefix}sermonsNL_events AS e 
	    LEFT JOIN {$wpdb->prefix}sermonsNL_kerktijden AS kt ON e.id = kt.event_id
	    LEFT JOIN {$wpdb->prefix}sermonsNL_kerktijdenpastors AS ktp ON ktp.id = kt.pastor_id
	    LEFT JOIN {$wpdb->prefix}sermonsNL_kerkomroep AS ko ON e.id = ko.event_id
	    LEFT JOIN {$wpdb->prefix}sermonsNL_youtube AS yt ON e.id = yt.event_id";
	    if(!$include_non_included){
    	    $sql .= "
    	    WHERE e.include = 1";
	    }
	    
	    if($where !== null){
	        $sql = "SELECT n.* FROM ($sql) AS n WHERE $where";
	    }
	    
	    $sql .= "
	    ORDER BY dt_start ASC";
	    
	    $data = $wpdb->get_results($sql);
	    
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
        FROM {$wpdb->prefix}sermonsNL_events AS e
	    LEFT JOIN {$wpdb->prefix}sermonsNL_kerktijden AS kt ON e.id = kt.event_id
	    LEFT JOIN {$wpdb->prefix}sermonsNL_kerktijdenpastors AS ktp ON ktp.id = kt.pastor_id
	    LEFT JOIN {$wpdb->prefix}sermonsNL_kerkomroep AS ko ON e.id = ko.event_id
	    LEFT JOIN {$wpdb->prefix}sermonsNL_youtube AS yt ON e.id = yt.event_id";
	    if(!$include_non_included){
    	    $sql .= "
    	    WHERE e.include=1";
	    }
	    $sql .= "
	    ) as n WHERE n.dt_start " . ($direction == 'up' ? '<' : '>') . " '$dt'";
	    $res = $wpdb->get_results($sql);
	    return (int)$res[0]->nrec;
	}
	
	private static function get_events_with_issues(){
	    // get ids from the events that have multiple items of the same type
	    global $wpdb;
	    $sql = "SELECT * FROM (
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
    	    FROM {$wpdb->prefix}sermonsNL_events AS e 
    	    LEFT JOIN {$wpdb->prefix}sermonsNL_kerktijden AS kt ON e.id = kt.event_id
    	    LEFT JOIN {$wpdb->prefix}sermonsNL_kerkomroep AS ko ON e.id = ko.event_id
    	    LEFT JOIN {$wpdb->prefix}sermonsNL_youtube AS yt ON e.id = yt.event_id
    	    GROUP BY e.id
    	) as e
	    WHERE n_rec > 1";

	    $data = $wpdb->get_results($sql);
	    
	    return $data;
	}
	
	
	
	// ITEM FUNCTIONS
	// i.e. kerktijden, kerkomroep, youtube

    // get all items that are not linked to an event
	private static function get_unlinked_items($sort=true){
	    global $wpdb;
	    $sql = "SELECT * FROM {$wpdb->prefix}sermonsNL_kerktijden WHERE event_id IS NULL ORDER BY dt";
	    $kt = $wpdb->get_results($sql);
	    $kt = array_map(function($item){$item->item_type = "kerktijden"; return $item;}, $kt);
	    $sql = "SELECT * FROM {$wpdb->prefix}sermonsNL_kerkomroep WHERE event_id IS NULL ORDER BY dt";
	    $ko = $wpdb->get_results($sql);
	    $ko = array_map(function($item){$item->item_type = "kerkomroep"; return $item;}, $ko);
	    $sql = "SELECT y.*, 
	    (case when dt_planned IS NOT NULL then dt_planned else dt_actual end) as dt
	    FROM {$wpdb->prefix}sermonsNL_youtube as y WHERE event_id IS NULL ORDER BY dt";
	    $yt = $wpdb->get_results($sql);
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
	            return sermonsNL_kerktijden::get_by_id($id);
	        case 'kerkomroep':
	            return sermonsNL_kerkomroep::get_by_id($id);
	        case 'youtube':
	            return sermonsNL_youtube::get_by_id($id);
	        default:
	            wp_trigger_error(__CLASS__."::get_item_by_type", "Wrong type '$type' parsed.", E_USER_ERROR);
	            return null;
	    }
	}


	// this can be called for youtube and kerktijden to load the archive in the background when the
	// settings have changed, because these processes are slow and we don't want the user to wait for it
	public static function get_remote_data_in_background(){

		// check that this is called in the background
		check_ajax_referer('sermonsnl-background-action');

		ignore_user_abort();
		set_time_limit(999);

		$resources = explode(",", $_GET['resources']);

		$done = array();

		// load kerktijden archive
		if(array_search('kt', $resources) !== false){
			sermonsNL_kerktijden::get_remote_data_forward();
			sermonsNL_kerktijden::get_remote_data_backward();
			$done[] = 'kt';
		}

		// load kerkomroep archive
		if(array_search('ko', $resources) !== false){
			sermonsNL_kerkomroep::get_remote_data();
			$done[] = 'ko';
		}

		// load youtube archive
		if(array_search('yt', $resources) !== false){
			sermonsNL_youtube::get_remote_data();
			$done[] = 'yt';
		}

		print json_encode(array('action' => 'sermonsNL_get_remote_data_in_background', 'done' => $done));
		wp_die();
	}

    // ADMIN PAGE HANDLING
    public static function add_admin_menu(){
        $num_issues = count(self::get_events_with_issues());
        $tag_issue = ($num_issues ? ' <span class="awaiting-mod">'.$num_issues.'</span>' : '');
        add_menu_page('Sermons-NL', 'Sermons-NL' . $tag_issue, self::$capability, 'sermons-nl', array('sermonsNL','admin_overview_page'), 'dashicons-admin-media', 7);
        add_submenu_page('sermons-nl', __('Sermons-NL administration','sermons-nl'), __('Administration','sermons-nl'), self::$capability, 'sermons-nl-admin', array('sermonsNL','admin_administration_page'));
	    add_submenu_page('sermons-nl', __('Sermons-NL configuration','sermons-nl'), __('Configuration','sermons-nl'), self::$capability, 'sermons-nl-config', array('sermonsNL','admin_edit_config_page'));
	    add_submenu_page('sermons-nl', __('Sermons-NL log','sermons-nl'), __('Log','sermons-nl'), self::$capability, 'sermons-nl-log', array('sermonsNL','admin_view_log_page'));
	}

	public static function add_admin_scripts_and_styles($hook){
	    if(strpos($hook,'sermons-nl') === false) return;
	    wp_enqueue_style('sermonsNL-admin-css', plugin_dir_url(__FILE__).'css/admin.css', array(), '1.0.1');
		wp_enqueue_script('sermonsNL-admin-js', plugin_dir_url(__FILE__) . 'js/admin.js', array('jquery'), '1.0.1');
	}
	
	public static function add_admin_custom_script($hook){
	    // if(strpos($hook,'sermons-nl') === false) return;
	    print '<script type="text/javascript">if(typeof sermonsnl_admin != "undefined"){sermonsnl_admin.admin_url = "' . admin_url( 'admin-ajax.php') . '";sermonsnl_admin.nonce = "' . wp_create_nonce('sermonsnl-administration') . '";}</script>' . PHP_EOL;
	}
	
    public static function admin_overview_page(){
        // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}

		global $wpdb;
		
		$events = sermonsNL_event::get_all();
		$kerktijden = sermonsNL_kerktijden::get_all();
		$kerkomroep = sermonsNL_kerkomroep::get_all();
		$youtube = sermonsNL_youtube::get_all();
		
        $issues = self::get_events_with_issues();

		$cron_msg = (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON);

		$log_dt = $wpdb->get_results("SELECT max(dt) AS dt FROM {$wpdb->prefix}sermonsNL_log");
		$log_time = time() - (new DateTime($log_dt[0]->dt, self::$timezone_db))->getTimestamp();
		$cron_fail = ($log_time > 24 * 3600);

		print '
		<div class="sermonsnl-overview">
		    <h2>
		        ' . __("Sermons-NL overview page","sermons-nl") . '
	            <img src="' . plugin_dir_url(__FILE__) . 'img/waiting.gif" id="sermonsnl_waiting"/><!-- icon freely available at https://icons8.com/preloaders/en/circular/floating-rays/ -->
    	    </h2>
		    <div class="sermonsnl-container">
		        <h3>' . __('Status','sermons-nl') . ':</h3>
		        <div>
		            <p>' . 
                    sprintf(__('Your site includes a total of %d sermons.','sermons-nl'), count($events)) . 
                    ' ' .
                    sprintf(__('These are based on %d entries from Kerktijden, %d from Kerkomroep, and %d from Youtube.', 'sermons-nl'), count($kerktijden), count($kerkomroep), count($youtube)) . 
                    '</p>';
		if($cron_msg){
			print '
					<p>' .
				sprintf(__('<strong>Note: It is recommended to disable WordPress cron.</strong> Sermons-NL will regularly update data in the background. This can slow down your website. To optimize performance, check if your hosting server allows you to use cron jobs. The recommended frequency of cron jobs is once every 15 minutes. %s Please refer to this instruction. %s','sermons-nl'),'<a href="https://www.wpbeginner.com/wp-tutorials/how-to-disable-wp-cron-in-wordpress-and-set-up-proper-cron-jobs/" target="_blank">','</a>') .
				'</p>';
		}elseif($cron_fail){
			print '
					<p>' .
					sprintf(__('<strong>Note: the last check for updates is %d hours ago. It seems that the cron job is not correctly configured. %s Please refer to this instruction. %s', 'sermons-nl'), round($log_time / 3600), '<a href="https://www.wpbeginner.com/wp-tutorials/how-to-disable-wp-cron-in-wordpress-and-set-up-proper-cron-jobs/" target="_blank">', '</a>') .
					'</p>';
		}
		print '
                    <p>';
        if(!empty($issues)){
            print '<strong>' . sprintf(__("There are %d issues that require your attention.", 'sermons-nl'), count($issues)) . '</strong>';
        }
        if(empty($issues) && !$cron_msg && !$cron_fail){
            print __("There are currently no issues to resolve.","sermons-nl");
        }
        print '</p>
	            </div>';

        if(count($issues)){
    		print '
    		    <h3>' . sprintf(__("Resolve %d issues","sermons-nl"), count($issues)) . ':</h3>
    		    <div>
            		<p>
            		    ' . __("These events have more than one items of the same type. Open the event to unlink one of the items. You can find the unlinked items in the administration tab if you want to create a new event from this item.","sermons-nl") . '
    		        </p>
    		        <p>
                		<table id="sermonsnl_events_table">'; //  class="wp-list-table widefat fixed striped pages"
        	print '
                		    <tr>
            	    	        <th>Date/time</th>
            		            <th>Number of kerktijden items</th>
            		            <th>Number of kerkomroep items</th>
            		            <th>Number of youtube items</th>
                	        </tr>';
            foreach($issues as $event){
                print '
                            <tr>
                                <td><a href="javascript:;" onclick="sermonsnl_admin.show_details(' . $event->id . ');">' . ucfirst(self::datefmt('short', $event->dt_start)) . '</a></td>
                                <td>' . $event->n_kt . '</td>
                                <td>' . $event->n_ko . '</td>
                                <td>' . $event->n_yt . '</td>
                            </tr>';
            }
            print '
                            </tr>
                        </table>
                    </p>
                    <div id="sermonsnl_details_view"></div>
                </div>';
        }
        
        print '
                <h3>' . __("Shortcode builder","sermons-nl") . ':</h3>
                <div>
                    <p>' . __("Build a shortcode to insert the list of sermons to your page.","sermons-nl") . '</p>
                    <p>
                        <a class="sermonsnl-copyshort" onclick="sermonsnl_admin.copy_shortcode(this);" title="' . __('Click to copy the shortcode','sermons-nl') . '">
        		            <img src="' . plugin_dir_url(__FILE__) . 'img/copy.png">
        		            <span id="sermonsnl_shortcode">[sermons-nl-list offset="now -13 days" ending="now +8 days"]</span>
        		        </a>
    		        </p>
                    <table>
                        <tr>
                            <td>' . __("Selection method","sermons-nl") . ':</td>
                            <td>
                                <select id="sermonsnl_selection_method">
                                    <option value="start-stop-date">' . __('Start and end date','sermons-nl') . '</option>
                                    <option value="start-date-count">' . __('Fixed number from start date','sermons-nl') . '</option>
                                    <option value="stop-date-count">' . __('Fixed number back from end date','sermons-nl') . '</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td>' . __("Start date",'sermons-nl') . ':<sup>1</sup></td>
                            <td><input type="text" id="sermonsnl_start_date" value="now -13 days"/></td>
                        </tr>
                        <tr>
                            <td>' . __("End date",'sermons-nl') . ':<sup>1</sup></td>
                            <td><input type="text" id="sermonsnl_end_date" value="now +8 days"/></td>
                        </tr>
                        <tr>
                            <td>' . __("Number of events",'sermons-nl') . ':</td>
                            <td><input type="text" id="sermonsnl_count" value="10" disabled/></td>
                        </tr>
                        <tr>
                            <td>' . __("Date format",'sermons-nl') . ':<sup>2</sup></td>
                            <td><input type="text" id="sermonsnl_datefmt", value="long"/></td>
                        </tr>
                        <tr>
                            <td>' . __("Buttons","sermons-nl") . ':</td>
                            <td><input type="checkbox" id="sermonsnl_more-buttons" checked/><label for="sermonsnl_more-buttons"> ' . __('Include buttons to load earlier and later sermons','sermons-nl') . '</label></td>
                        </tr>
                        <tr>
							<td>' . __("Sermons-NL logo","sermons-nl") . ':<sup>3</sup></td>
							<td><input type="checkbox" id="sermonsnl_show-logo"/><label for="sermonsnl_show-logo"> ' . __('Display the Sermons-NL logo next to obligatory logos.','sermons-nl') . '</label></td>
						</tr>
                    </table>
                    <p><small>
						<sup>1</sup> ' .
                    sprintf(__('For start and end date, you can enter any text that can be interpreted as a date based on supported %s formats.', 'sermons-nl'), '<a href="https://www.php.net/manual/en/datetime.formats.php" target="_blank">DateTime</a>') .
					'<br/>
						<sup>2</sup> ' .
					sprintf(__('The date format, that is used for printing the sermon dates can be either "long" or "short" (which will print a long or short date format followed by hours and minutes) or a suitable date-time format, see %s.', 'sermons-nl'), '<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank">DateTime::format()</a>') .
					'<br/>
						<sup>3</sup>' .
					__('The Sermons-NL logo will be displayed next to the (anyway obligatory) logos of services that are used. Thank you if you keep this checked to help others find the plugin!', 'sermons-nl') .
					'</small></p>
                </div>
            </div>
            <div class="sermonsnl-container">
                <h3>' . __("Frequently asked questions","sermons-nl") . ':</h3>
                <div>';
		$readme = file_get_contents(plugin_dir_path(__FILE__) . "readme.txt");
		preg_match("/== Frequently Asked Questions ==(.*?)==/is", $readme, $matches);
		$qa = preg_split("( =|= )", $matches[1]);
		for($i=1; $i < count($qa); $i += 2){
			print '
					<h4>' . $qa[$i] . '</h4>
					<p>' . nl2br(trim($qa[$i+1])) . '</p>';
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
		<div class="sermonsnl_admin">
		    <h2>' . __("Manage church service calendar and broadcasts","sermons-nl") . '</h2>
		    <p class="sermonsnl-abuttons">
		        <a href="javascript:;" onclick="sermonsnl_admin.navigate(-1);">' . __('Previous month','sermons-nl') . '</a>
		        <a href="javascript:;" onclick="sermonsnl_admin.navigate(1);">' . __('Next month','sermons-nl') . '</a>
		        <a href="javascript:;" onclick="sermonsnl_admin.show_details(0);">' . __('Unlinked items','sermons-nl') . ' (<span id="sermonsnl_unlinked_num">' . $num_unlinked . '</span>)</a>
		        <a href="javascript:;" onclick="sermonsnl_admin.show_details(null);">' . __('Create new event','sermons-nl') . '</a>
		        <img src="' . plugin_dir_url(__FILE__) . 'img/waiting.gif" id="sermonsnl_waiting"/><!-- icon freely available at https://icons8.com/preloaders/en/circular/floating-rays/ -->
		    </p>
		    <div id="sermonsnl_admin_table">';
		
		print self::admin_edit_sermons_page_get_table();

		print '
		    </div>
		    <div id="sermonsnl_details_view"></div>
		</div>';
		
	}
	
	public static function admin_navigate_table(){
		// check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		ob_clean();
	    $m = (int)$_GET['month'];
	    $html = self::admin_edit_sermons_page_get_table($m);
	    $json = array(
	        'action' => 'sermonsnl_admin_navigate_table',
	        'month' => $m,
	        'html' => $html
	        );
	    print json_encode($json);
		wp_die();
	}
	
	private static function admin_edit_sermons_page_get_table($m=0){
	    if($m == 0) $m = 'this';
	    $dt1 = date("Y-m-d", strtotime("first day of $m month"));
		$dt2 = date("Y-m-d", strtotime("last day of $m month"));
		
		$data = self::get_complete_records_by_dates($dt1, $dt2, true, true);

        $html = '
    		    <h3 id="sermonsnl_admin_month">' . ucfirst(wp_date("F Y", strtotime($dt1))) . '</h3>';
		if(empty($data)){
		    $html .= '<p>' . __('No records found.','sermons-nl') . '</p>';
		}else{
		    //  class="wp-list-table widefat fixed striped pages"
    		$html .= '
    	      <table id="sermonsnl_events_table">
    	        <thead>
    	          <tr>
    	            <th></th>
    	            <th>' . __("Date","sermons-nl") . '</th>
    	            <th>' . __("Pastor","sermons-nl") . '</th>
    	            <th colspan="3">' . __("Items","sermons-nl") . '</th>
    	          </tr>
    	        </thead>
    	        <tbody>';
	        foreach($data as $rec){
	            $html .= '
	              <tr>
	                <td>' . ($rec->include ? '' : '<img src="' . plugin_dir_url(__FILE__) . 'img/not_included.gif" alt="X" title="' . __("Not included","sermons-nl") . '"/>') .
					($rec->protected ? '<img src="' . plugin_dir_url(__FILE__) . 'img/protected.png" alt="8" title="' . __("Protected","sermons-nl") . '" height="20"/>' : '') . '</td>
	                <td><a href="javascript:;" onclick="sermonsnl_admin.show_details('.$rec->id.');">' . ucfirst(self::datefmt("short", $rec->dt_start)) . '</a></td>
	                <td>' . $rec->pastor . '</td>
	                <td>' . ($rec->kt_id ? '<img src="' . plugin_dir_url(__FILE__) . 'img/has_kt.gif" alt="KT" title="' . __("Uses Kerktijden source","sermons-nl") . '"/>' : '') . '</td>
	                <td>' . ($rec->ko_id ? '<img src="' . plugin_dir_url(__FILE__) . 'img/has_ko.gif" alt="KO" title="' . __("Uses Kerkomroep source","sermons-nl") . '"/>' : '') . '</td>
	                <td>' . ($rec->yt_video_id ? '<img src="' . plugin_dir_url(__FILE__) . 'img/has_yt.gif" alt="YT" title="' . __("Uses YouTube source","sermons-nl") . '"/>' : '') . '</td>
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
		if($_GET['event_id'] === ""){
		    $html = '
		        <h3>
		            <img src="' . plugin_dir_url(__FILE__) . 'img/close.gif" class="sermonsnl-closebtn" title="'.__("Cancel","sermons-nl").'" onclick="sermonsnl_admin.hide_details(null);"/>
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
		    $event_id = (int)$_GET['event_id'];
    		$html = '
    		    <h3><img src="' . plugin_dir_url(__FILE__) . 'img/close.gif" class="sermonsnl-closebtn" title="'.__("Close","sermons-nl").'" onclick="sermonsnl_admin.hide_details('.$event_id.');"/>';
    		if($event_id == 0){
    		    $items = self::get_unlinked_items();
    		    $html .= __("Unlinked items","sermons-nl") . '</h3>
        		    <ul>
        		        <li>Items not linked to an event will not be shown in the sermons listing.</li>
            		    <li>Link the item to an existing event by clicking the date or create a new one.</li>
            		    <li>For new events, the date and time of the linked item is used.</li>
        		        <li>Or copy the shortcode for adding the single item to your website.</li>
        		    </ul>';
    		    if(empty($items)){
    		        $html .= '
    		        <p><em>There are no unlinked items.</em></p>';
    		    }else{
    		        //  class="wp-list-table widefat fixed striped pages"
    		        $html .= '
        		    <table>
        		        <thead>
            		        <tr>
            		            <th>'.__('Type','sermons-nl').':</th>
            		            <th>'.__('Date / time','sermons-nl').':</th>
            		            <th colspan="2">'.__('Actions','sermons-nl').':</th>
            		        </tr>
            		    </thead>
            		    <tbody>';
            		foreach($items as $item){
            		    $html .= '
            		        <tr>
            		            <td>' . $item->item_type . '</td>
            		            <td>' . ucfirst(self::datefmt("short", $item->dt)) . '</td>
            		            <td>';
						if($item->item_type != 'kerktijden'){
							$html .= '
            		                <a onclick="sermonsnl_admin.copy_shortcode(this);" title="' . __('Click to copy the shortcode','sermons-nl') . '" class="sermonsnl-copyshort">
            		                    <img src="' . plugin_dir_url(__FILE__) . 'img/copy.png"/>
            		                    Shortcode
            		                    <div>';
							$html .= '[sermons-nl-item type="' . $item->item_type . '" id="' . $item->id . '"]';
							$html .= '</div>
            		                </a>';
						}
						$html .= '
            		            </td>
            		            <td>
            		                <a class="sermonsnl-linktoevent"><img src="' . plugin_dir_url(__FILE__) . 'img/link.png"/> ' . __('Link to event','sermons-nl') . '<div><ul>';
            		    $dt1 = date("Y-m-d 00:00:00",strtotime($item->dt));
            		    $dt2 = date("Y-m-d 23:59:59",strtotime($item->dt));
            		    $events = sermonsNL_event::get_by_dt($dt1, $dt2, true);
            		    if(empty($events)) $html .= '<em>' . __('No existing event on this date','sermons-nl') . '</em>';
            		    else{
                		    $first = true;
                		    usort($events, function($d1, $d2){return strtotime($d1->dt) - strtotime($d2->dt);});
                		    foreach($events as $event){
                		        if($first) $first = false;
                		        else $html .= '<br/>';
                		        $html .= '<li onclick="sermonsnl_admin.link_item_to_event(\''.$item->item_type.'\', '.$item->id.', '.$event->id.');">';
                		        $dt = $event->dt;
            		            if($dt){
            		                $html .= ucfirst(self::datefmt('short', $event->dt));
            		            }
            		            $html .= '</li>';
            		        }
        		        }
        	    	    $html .= '<br/><li onclick="sermonsnl_admin.link_item_to_event(\''.$item->item_type.'\', '.$item->id.', null);">' . __('Create new event','sermons-nl') . '</li>';
        	    	    $html .= '</ul></div></a>
            		                </td>
            		            </tr>'; 
        	    	}
        	    	$html .= '
            		    </tbody>
            		</table>';
    		    }
    		}else{
    		    $event = sermonsNL_event::get_by_id($event_id);
    		    if(!$event){
    		        $html = "Error: no event with id #$event_id.</h3>";
    		    }else{
        		    $html .= ucfirst(self::datefmt("short", $event->dt)) . '</h3>
        		    <div>
            		    <a class="sermonsnl-copyshort" onclick="sermonsnl_admin.copy_shortcode(this);" title="' . __('Click to copy the shortcode','sermons-nl') . '">
        		            <img src="' . plugin_dir_url(__FILE__) . 'img/copy.png" /> 
        		            ' . __('Copy event shortcode','sermons-nl') . '
        		            <div>';
            		$html .= '[sermons-nl-event id="' . $event_id . '"]';
            		$html .= '</div>
            		    </a>
            		</div>';
        		    $items = $event->get_all_items();
        		    if(empty($items)){
        		        $html .= '
        		        <p>
        		            <em>' . __("This event has no linked items.","sermons-nl") . '</em> 
        		            <a href="javascript:;" onclick="if(confirm(\'' . __('Are you sure you want to delete this event?','sermons-nl') . '\')){sermonsnl_admin.delete_event('.$event->id.');}">' . __('Delete event','sermons-nl') . '</a>
        		        </p>';
        		    }else{
        		        $abbr = array('kerktijden'=>'kt', 'kerkomroep'=>'ko', 'youtube'=>'yt');
        		        //  class="wp-list-table widefat fixed striped pages"
        		        $html .= '
        		        <p><b>' . __("This event has the following linked items:", "sermons-nl") . '</b></p>
        		        <table>';
        		        foreach($items as $type => $subitems){
        		            foreach($subitems as $item){
        		                $html .= '
        		                <tr>
									<td>' . ucfirst($type) . '</td><td>' . ucfirst(self::datefmt("short", $item->dt)) . '</td>
									<td>';
								if($type != 'kerktijden'){
									$html .= '
										<a onclick="sermonsnl_admin.copy_shortcode(this);" title="' . __('Click to copy the shortcode','sermons-nl') . '" class="sermonsnl-copyshort">
											<img src="' . plugin_dir_url(__FILE__) . 'img/copy.png"/>
											Shortcode
											<div>';
									$html .= '[sermons-nl-item type="' . $type . '" id=' . $item->id . ']';
									$html .= '</div>
										</a>';
								}
								$html .= '
									</td>
									<td><a class="sermonsnl-linktoevent" href="javascript:;" onclick="sermonsnl_admin.unlink_item(\'' . $type . '\', ' . $item->id . ');"><img src="' . plugin_dir_url(__FILE__) . 'img/link.png"/> ' . __('Unlink item','sermons-nl') . '</a></td>
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
		    'action' => "sermonsnl_admin_show_details",
		    'event_id' => $event_id,
		    'html' => $html
		);
		ob_clean();
		print json_encode($json);
		wp_die();
	}

	private static function html_form_update_event($event){
		$html = '
		<form id="sermonsnl_update_event" onsubmit="return !sermonsnl_admin.submit_update_event(this);">
			<input type="hidden" name="_wpnonce" value="' . wp_create_nonce('sermonsnl-administration') . '"/>
			<input type="hidden" name="action" value="sermonsnl_submit_update_event"/>
			<input type="hidden" name="event_id" value="' . $event->id . '"/>
			<div id="sermonsnl_event_errmsg"></div>
			<table>
				<tr>
					<td><h4>' . __('Event settings', 'sermons-nl') . '</h4></td>
					<td><input type="submit" value="' . __('Save settings','sermons-nl') . '"/></td>
				</tr>
				<tr>
					<td>' . __('Select date-time from','sermons-nl') . ': <sup>1</sup></td>
					<td><select name="dt_from" onchange="sermonsnl_admin.toggle_manual_row(this,\'dt\');">';
		foreach(array('auto','manual','kerktijden','kerkomroep','youtube') as $value){
			$html .= '<option value="'.$value.'"' . ($value == $event->dt_from ? ' selected="selected"' : '') . '>' . $value . '</option>';
		}
		$html .= '</select></td>
				</tr>
				<tr id="sermonsnl_dt_manual"' . ($event->dt_from == 'manual' ? '' : ' class="sermonsnl-manual-closed"') . '>
					<td></td>
					<td><input type="datetime-local" name="dt_manual"';
		if($event->dt_manual !== null){
			$dt = new DateTime($event->dt_manual, self::$timezone_db);
			$dt->setTimeZone(wp_timezone());
			$html .= ' value="' . $dt->format("Y-m-d\TH:i") . '"';
		}
		$html .= '/></td>
				</tr>
				<tr>
					<td>' . __('Select sermon type from','sermons-nl') . ': <sup>2</sup></td>
					<td><select name="sermontype_from" onchange="sermonsnl_admin.toggle_manual_row(this,\'sermontype\');">';
		foreach(array('auto','manual','kerktijden') as $value){
			$html .= '<option value="'.$value.'"' . ($value == $event->sermontype_from ? ' selected="selected"' : '') . '>' . $value . '</option>';
		}
		$html .= '</select></td>
				</tr>
				<tr id="sermonsnl_sermontype_manual"' . ($event->sermontype_from == 'manual' ? '' : ' class="sermonsnl-manual-closed"') . '>
					<td></td>
					<td><input type="text" name="sermontype_manual"' . ($event->sermontype_manual === null ? '' : ' value="' . esc_html($event->sermontype_manual)) . '"/></td>
				</tr>
				<tr>
					<td>' . __('Select pastor name from','sermons-nl') . ': <sup>3</sup></td>
					<td><select name="pastor_from" onchange="sermonsnl_admin.toggle_manual_row(this,\'pastor\');">';
		foreach(array('auto','manual','kerktijden','kerkomroep') as $value){
			$html .= '<option value="'.$value.'"' . ($value == $event->pastor_from ? ' selected="selected"' : '') . '>' . $value . '</option>';
		}
		$html .= '</select></td>
				</tr>
				<tr id="sermonsnl_pastor_manual"' . ($event->pastor_from == 'manual' ? '' : ' class="sermonsnl-manual-closed"') . '>
					<td></td>
					<td><input type="text" name="pastor_manual"' . ($event->pastor_manual === null ? '' : ' value="' . esc_html($event->pastor_manual)) . '"/></td>
				</tr>
				<tr>
					<td>' . __('Select description from','sermons-nl') . ': <sup>4</sup></td>
					<td><select name="description_from" onchange="sermonsnl_admin.toggle_manual_row(this,\'description\');">';
		foreach(array('auto','manual','kerktijden','kerkomroep') as $value){
			$html .= '<option value="'.$value.'"' . ($value == $event->description_from ? ' selected="selected"' : '') . '>' . $value . '</option>';
		}
		$html .= '</select></td>
				</tr>
				<tr id="sermonsnl_description_manual"' . ($event->description_from == 'manual' ? '' : ' class="sermonsnl-manual-closed"') . '>
					<td></td>
					<td><textarea name="description_manual">' . esc_html($event->description_manual) . '</textarea></td>
				</tr>
				<tr>
					<td>' . __('Other settings','sermons-nl') . ':</td>
					<td><input type="checkbox" name="include" id="sermonsnl_include"' . ($event->include ? ' checked' : '') . '/><label for="sermonsnl_include"> Include in sermons list</label></td>
				</tr>
				<tr>
					<td></td>
					<td><input type="checkbox" name="protected" id="sermonsnl_protected"' . ($event->protected ? ' checked' : '') . '/><label for="sermonsnl_protected"> Protect from automated deletion <sup>5</sup></label></td>
				</tr>
			</table>
			<p class="sermonsnl-footnotes">
				<sup>1.</sup> If auto is selected or if an item is selected that has no date-time, the order of picking the date-time is (based on availability): kerktijden, youtube (if it has a planned date), kerkomroep, youtube (if it has been broadcasted).<br/>
				<sup>2.</sup> If auto is selected, sermons type will be selected from kerktijden.<br/>
				<sup>3.</sup> If auto is selected, pastor name will be selected from kerktijden or kerkomroep (in this order based on availability).<br/>
				<sup>4.</sup> If auto is selected, description will be seelcted from youtube or kerkomroep (in this order, based on availability).<br/>
				<sup>5.</sup> Events that have no linked items are deleted over  night. Tick this box to prevent that from happening.
			</p>
		</form>';
		return $html;
	}

	public static function sermonsnl_submit_update_event(){
		// check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		// check nonce
		check_ajax_referer('sermonsnl-administration');
		// prep json function
		function returnIt(bool $ok, $errMsg = null){
			$json = array("action" => "sermonsnl_submit_update_event", "ok" => $ok);
			if($errMsg) $json['errMsg'] = $errMsg;
			ob_clean();
			print json_encode($json);
			wp_die();
		}
		// process posted data
		$event_id = (int)$_POST['event_id'];
		if(empty($_POST['dt_manual'])){
			$dt_manual = null;
		}else{
			$dt_manual = new DateTime($_POST['dt_manual'], wp_timezone());
			if($dt_manual) $dt_manual = $dt_manual->setTimeZone(self::$timezone_db)->format("Y-m-d H:i:s");
		}
		$data = array(
			'dt_from' => $_POST['dt_from'],
			'dt_manual' => $dt_manual,
			'sermontype_from' => $_POST['sermontype_from'],
			'sermontype_manual' => (empty($_POST['sermontype_manual']) ? null : $_POST['sermontype_manual']),
			'pastor_from' => $_POST['pastor_from'],
			'pastor_manual' => (empty($_POST['pastor_manual']) ? null : $_POST['pastor_manual']),
			'description_from' => $_POST['description_from'],
			'description_manual' => (empty($_POST['description_manual']) ? null : $_POST['description_manual']),
			'include' => (empty($_POST['include']) ? 0 : 1),
			'protected' => (empty($_POST['protected']) ? 0 : 1)
		);
		// data checks
		if($data['dt_from'] == 'manual' && empty($data['dt_manual'])){
			returnIt(false, __('An incorrect manual date is entered.', 'sermons-nl'));
		}
		if($event_id > 0){
			if(!($event = sermonsNL_event::get_by_id($event_id))){
				returnIt(false, sprintf(__('Failed to save event settings: no event with id %d found.', 'sermons-nl'), $event_id));
			}
			$items = $event->get_all_items();
			// check that the manual date is not out of range of the items, if there are any
			if($data['dt_from'] == 'manual' && !empty($items)){
				// rely on dt_min and dt_max for ease
				if($data['dt_manual'] < $event->dt_min || $data['dt_manual'] > $event->dt_max){
					returnIt(false, __('The manual date and time should not be out of the range of the linked items.', 'sermons-nl'));
				}
			}elseif($data['dt_from'] != 'manual' && empty($items)){
				returnIt(false, __('A manual date must be set when the event has no linked items.', 'sermons-nl'));
			}
		}else{
			// check that date is manual, it has to be manual for new events!
			if($data['dt_from'] != 'manual'){
				returnIt(false, __('When adding a new event, the date and time has to be set manually.'));
			}
			$event = sermonsNL_event::add_record($data['dt_manual']);
			if(!$event){
				returnIt(false, __('Sorry, failed to create a new event due to an unknown error.', 'sermons-nl'));
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
	    check_ajax_referer('sermonsnl-administration');
	    
	    // check input
	    if(!array_key_exists('item_type',$_POST) || !array_key_exists('item_id',$_POST) || !array_key_exists('event_id',$_POST)){
	        wp_trigger_error(__CLASS__."::link_item_to_event", "Missing post parameters.", E_USER_ERROR);
	        wp_die(-1);
	    }
	    
	    // prepare return
	    $json = array('action' => 'sermonsnl_admin_link_item_to_event');
	    
	    // get values
	    $item_type = (string)$_POST['item_type'];
	    $item_id = (int)$_POST['item_id'];
	    $event_id = ($_POST['event_id'] == "" ? null : (int)$_POST['event_id']);
	    
	    // get item
	    $item = self::get_item_by_type($item_type, $item_id);
	    if($item === null){
	        // no such item
	        $json['ok'] = false;
	        $json['errMsg'] = "Item #$item_id doesn't exist.";
	    }else{
	        if($event_id === null){
	            $event = sermonsNL_event::add_record($item->dt, $item->dt_end);
	            $item->event_id = $event->id;
	            $json['ok'] = true;
	        }else{
	            $event = sermonsNL_event::get_by_id($event_id);
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
		print json_encode($json);
		wp_die();
	}

	public static function unlink_item(){
	    // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		// check nonce
	    check_ajax_referer('sermonsnl-administration');
	    
	    // check input
	    if(!array_key_exists('item_type',$_POST) || !array_key_exists('item_id',$_POST)){
	        wp_trigger_error(__CLASS__."::unlink_item_to", "Missing post parameters.", E_USER_ERROR);
	        wp_die(-1);
	    }
	    
	    // prepare return
	    $json = array('action' => 'sermonsnl_admin_unlink_item');
	    
	    // get values
	    $item_type = (string)$_POST['item_type'];
	    $item_id = (int)$_POST['item_id'];
	    
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
		print json_encode($json);
		wp_die();
	}
	
	public static function delete_event(){
	    // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		// check nonce
	    check_ajax_referer('sermonsnl-administration');
	    
	    // check input
	    if(!array_key_exists('event_id',$_POST)){
	        wp_trigger_error(__CLASS__."::delete_event", "Missing post parameters.", E_USER_ERROR);
	        wp_die(-1);
	    }
	    
	    // prepare return
	    $json = array('action' => 'sermonsnl_admin_delete_event');
	    
	    // get values
	    $event_id = (int)$_POST['event_id'];
	    
	    // get event
	    $event = sermonsNL_event::get_by_id($event_id);
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
		print json_encode($json);
		wp_die();
	}
	
	public static function admin_edit_config_page(){
		// check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}
		
		$kt_id = get_option('sermonsNL_kerktijden_id');
		$kt_weeksback = get_option('sermonsNL_kerktijden_weeksback');
		$kt_weeksahead = get_option('sermonsNL_kerktijden_weeksahead');
        $ko_mp = get_option('sermonsNL_kerkomroep_mountpoint');
        // to check if sources is also needed (whether video or audio is used) - perhaps just instruct to leave stream id's empty if not used.
		$kg_rss_id = get_option('sermonsNL_kerkdienstgemist_rssid');
		$kg_audio_stream_id = get_option('sermonsNL_kerkdienstgemist_audiostreamid');
		$kg_video_stream_id = get_option('sermonsNL_kerkdienstgemist_videostreamid');
		$yt_channel = get_option('sermonsNL_youtube_channel');
		$yt_key = get_option('sermonsNL_youtube_key');
		$yt_weeksback = get_option('sermonsNL_youtube_weeksback');

		// return settings form
		print '
		<div class="sermonsNL_settings">
			<h2>
				' . __("Settings for church services", 'sermons-nl') . '
				<img src="' . plugin_dir_url(__FILE__) . 'img/waiting.gif" id="sermonsnl_waiting"/><!-- icon freely available at https://icons8.com/preloaders/en/circular/floating-rays/ -->
			</h2>
			<div id="sermonsnl_config_save_msg"></div>';
		print '
			<form method="post" onsubmit="sermonsnl_admin.config_submit(this); return false;">
				<input type="hidden" name="_wpnonce" value="' . wp_create_nonce('sermonsnl-administration') . '"/>
				<input type="hidden" name="action" value="sermonsnl_config_submit"/>';
		//  class="wp-list-table widefat fixed striped pages"
		print '
				<table>
				
					<tbody id="kerktijden_settings"' . ($kt_id ? '' : ' class="settings_disabled"') . '>
						<tr>
							<th colspan="2">Kerktijden.nl</th>
						</tr>
						<tr class="always_visible">
						    <td colspan="2"><input type="checkbox"' . ($kt_id ? ' checked="checked"' : '') . ' id="kerktijden_checkbox" onclick="sermonsnl_admin.toggle_kerktijden(this);"/><label for="kerktijden_checkbox">' . sprintf(__("Enable %s","sermons-nl"), "Kerktijden") . '</label></td>
						</tr>
						<tr class="collapsible_setting condition">
						    <td colspan="2">' . __("The use of data from this tool on your own website is permitted, provided that the link and logo are provided. The plugin will add it for you, please do not hide it.", "sermons-nl") . '</td>
						</tr>
						<tr class="collapsible_setting">
							<td>' . __("Kerktijden identifier", "sermons-nl") . ': 
								<div class="help">
									<figure><img src="' . plugin_dir_url(__FILE__) . 'img/kt_identifier.jpg"/><figcaption>' .
									sprintf(__("Browse to your church's page on %s and copy the number from the url, e.g. 999 in this figure.", "sermons-nl"), '<a href="https://www.kerktijden.nl" target="_blank">www.kerktijden.nl</a>') .
									'</figcaption></figure>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_kerktijden_id" id="input_kerktijden_id" value="'. ($kt_id ? $kt_id : '') . '"/></td>
						</tr>
						<tr class="collapsible_setting">
						    <td>' . __("Number of weeks back","sermons-nl") . ':
						        <div class="help"><div>' . sprintf(__("How many weeks to look back when loading the %s archive","sermons-nl"), "Kerktijden") . '</div></div>
						    </td>
						    <td><input type="text" name="sermonsNL_kerktijden_weeksback" value="'. ($kt_weeksback ? $kt_weeksback : '') . '""/></td>
						</tr>
						<tr class="collapsible_setting">
							<td>' . __("Number of weeks ahead","sermons-nl") . ':
								<div class="help"><div>' . sprintf(__("How many weeks ahead in time to load %s data","sermons-nl"), "Kerktijden") . '</div></div>
							</td>
							<td><input type="text" name="sermonsNL_kerktijden_weeksahead" value="'. ($kt_weeksahead ? $kt_weeksahead : '') . '""/></td>
						</tr>
					</tbody>
					
					<tbody id="kerkomroep_settings"' . ($ko_mp ? '' : ' class="settings_disabled"') . '>
					    <tr>
							<th colspan="2">Kerkomroep.nl</th>
						</tr>
						<tr class="always_visible">
						    <td colspan="2"><input type="checkbox"' . ($ko_mp ? ' checked="checked"' : '') . ' id="kerkomroep_checkbox" onclick="sermonsnl_admin.toggle_kerkomroep(this);"/><label for="kerkomroep_checkbox">' . sprintf(__("Enable %s","sermons-nl"), "Kerkomroep") . '</label></td>
						</tr>
						<tr class="collapsible_setting condition">
						    <td colspan="2">' . __("The use of data from this tool on your own website is permitted, provided that the link and logo are provided. The plugin will add it for you, please do not hide it.", "sermons-nl") . '</td>
						</tr>
						<tr class="collapsible_setting">
							<td>' . __("Mount point", "sermons-nl") . ':
								<div class="help">
									<figure><img src="' . plugin_dir_url(__FILE__) . 'img/ko_url.jpg"/><figcaption>' . sprintf(__("Browse to your church's page on %s and copy the number from the end of the url, e.g. 99999 in this figure.", 'sermons-nl'), '<a href="https://www.kerkomroep.nl" target="_blank">kerkomroep.nl</a>') . '</figcaption></figure>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_kerkomroep_mountpoint" id="input_kerkomroep_id" value="' . ($ko_mp ? $ko_mp : '') . '"/></td>
						</tr>
					</tbody>';

		/*
		print '
                    <tbody id="kerkdienstgemist_settings"' . ($kg_rss_id ? '' : ' class="settings_disabled"') . '>
						<tr>
							<th colspan="2">Kerkdienstgemist.nl</th>
						</tr>
						<tr class="always_visible">
						    <td colspan="2"><input type="checkbox"' . ($kg_rss_id ? ' checked="checked"' : '') . ' id="kerkdienstgemist_checkbox" onclick="sermonsnl_admin.toggle_kerkdienstgemist(this);"/><label for="kerkdienstgemist_checkbox">' . sprintf(__("Enable %s","sermons-nl"),"Kerkdienst gemist") . '</label></td>
						</tr>
						<tr class="collapsible_setting condition">
						    <td colspan="2">' . __("The use of data from this tool on your own website is permitted, provided that the link and logo are provided. The plugin will add it for you, please do not hide it in any way.", "sermons-nl") . '</td>
						</tr>
						<tr class="collapsible_setting">
							<td>' . __("Archive ID","sermons-nl") . ':
								<div class="help">
									<div>
										Go to <a href="https://admin.kerkdienstgemist.nl/" target="_blank">https://admin.kerkdienstgemist.nl/</a> and log in.<br/>
										Open the archive menu: <img src="' . plugin_dir_url(__FILE__) . 'img/kg_archive_btn.jpg"/><br/>
										Click the RSS button: <img src="' . plugin_dir_url(__FILE__) . 'img/kg_rss_btn.jpg"/><br/>
										<img src="' . plugin_dir_url(__FILE__) . 'img/kg_rss_link.jpg"/><br/>
										Copy the number from one of the RSS URLs.
									</div>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_kerkdienstgemist_rssid" id="input_kerkdienstgemist_id" value="' . ($kg_rss_id ? $kg_rss_id : '') . '"/></td>
						</tr>
						<tr class="collapsible_setting">
							<td>Audio livestream ID:
								<div class="help">
									<div>
										Go to <a href="https://admin.kerkdienstgemist.nl/" target="_blank">https://admin.kerkdienstgemist.nl/</a> and log in.<br/>
										Go to the Channels menu: <img src="' . plugin_dir_url(__FILE__) . 'img/kg_channels_btn.jpg"/><br/>
										Choose "Kerkradio" in the sub-menu: <img src="' . plugin_dir_url(__FILE__) . 'img/kg_channels_sub.jpg"/><br/>
										Click the "streams" button: <img src="' . plugin_dir_url(__FILE__) . 'img/kg_streams_btn.JPG"/><br/>
										<img src="' . plugin_dir_url(__FILE__) . 'img/kg_audio_stream.jpg"/><br/>
										Copy the number from the public audio stream URL.<br/>
										If you don\'t want to use the audio livestream, you can just leave this field empty.
									</div>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_kerkdienstgemist_audiostreamid" value="' . ($kg_audio_stream_id ? $kg_audio_stream_id : '') . '"/></td>
						</tr>
						<tr class="collapsible_setting">
							<td>Video livestream ID:
								<div class="help">
									<div>
										Go to <a href="https://admin.kerkdienstgemist.nl/" target="_blank">https://admin.kerkdienstgemist.nl/</a> and log in.<br/>
										Go to the Channels menu: <img src="' . plugin_dir_url(__FILE__) . 'img/kg_channels_btn.jpg"/><br/>
										Choose "Kerk TV" in the sub-menu: <img src="' . plugin_dir_url(__FILE__) . 'img/kg_channels_sub.jpg"/><br/>
										Click the "streams" button: <img src="' . plugin_dir_url(__FILE__) . 'img/kg_streams_btn.JPG"/><br/>
										<img src="' . plugin_dir_url(__FILE__) . 'img/kg_video_stream.jpg"/><br/>
										Copy the number from the public video stream URL.<br/>
										If you don\'t want to use the video livestream, you can just leave this field empty.
									</div>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_kerkdienstgemist_videostreamid" value="' . ($kg_video_stream_id ? $kg_video_stream_id : '') . '"/></td>
						</tr>
                    </tbody>';
		*/
		print '
                    <tbody id="youtube_settings"' . ($yt_channel ? '' : ' class="settings_disabled"') . '>
						<tr>
							<th colspan="2">YouTube.com</th>
						</tr>
						<tr class="always_visible">
						    <td colspan="2"><input type="checkbox"' . ($yt_channel ? ' checked="checked"' : '') . ' id="youtube_checkbox" onclick="sermonsnl_admin.toggle_youtube(this);"/><label for="youtube_checkbox">' . sprintf(__("Enable %s","sermons-nl"),"YouTube") . '</label></td>
						</tr>
						<tr class="collapsible_setting condition">
						    <td colspan="2">' . __("Setting the channel may take a while depending on the number of available videos.", "sermons-nl") . '</td>
						</tr>
						<tr class="collapsible_setting">
							<td>YouTube channel ID: 
								<div class="help">
									<figure><img src="' . plugin_dir_url(__FILE__) . '/img/yt_channel.jpg"/><figcaption>' . __("Browse to your church's YouTube channel and copy the youtube channel ID from the url.","sermons-nl") . '</figcaption></figure>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_youtube_channel" id="input_youtube_id" value="' . $yt_channel . '"/></td>
						</tr>
						<tr class="collapsible_setting">
							<td>YouTube api key: 
								<div class="help">
									<div>' . sprintf(__('Visit %s to learn how to obtain a YouTube api key.','sermons-nl'),'<a href="https://developers.google.com/youtube/v3/getting-started" target="_blank">https://developers.google.com/youtube/v3/getting-started</a>') . '</div>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_youtube_key" value="' . $yt_key . '"/></td>
						</tr>
						<tr class="collapsible_setting">
							<td>' . __("Number of weeks back","sermons-nl") . ':
								<div class="help"><div>' . sprintf(__("How many weeks to look back when loading the %s archive","sermons-nl"), "YouTube") . '</div></div>
							</td>
							<td><input type="text" name="sermonsNL_youtube_weeksback" value="'. ($yt_weeksback ? $yt_weeksback : '') . '""/></td>
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
		check_ajax_referer('sermonsnl-administration');
		// may be needed
		global $wpdb;
		// loop through const OPTION_NAMES: if name is found in _POST, it can be saved.
		// check for some if they changed, if so an action is required
		$get_data = array();
		foreach(self::OPTION_NAMES as $option_name => $attr){
			if(array_key_exists($option_name, $_POST)){
				$old_value = get_option($option_name);
				$new_value = $_POST[$option_name];
				if($attr['default'] === null && $new_value === ""){
					$new_value = null;
				}elseif($attr['type'] == 'integer'){
					$new_value = (int)$new_value;
				}
				if($old_value != $new_value){
					update_option($option_name, $new_value);
					switch($option_name){
						case "sermonsNL_kerktijden_id":
							// clean kerktijden data when the kerktijden_id changes
							$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sermonsNL_kerktijden");
							if(!empty($new_value)){
								$get_data[] = 'kt';
							}
							break;
						case "sermonsNL_kerktijden_weeksback":
							if($new_value > $old_value){
								$get_data[] = 'kt';
							}
							break;
						case "sermonsNL_kerktijden_weeksahead":
							if($old_value > $old_value){
								$get_data[] = 'kt';
							}
							break;
						case "sermonsNL_kerkomroep_mountpoint":
							// clean kerkomroep data when the mountpoint changes
							$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sermonsNL_kerkomroep");
							if(!empty($new_value)){
								$get_data[] = 'ko';
							}
							break;
						case "sermonsNL_youtube_channel":
							// clean youtube data when the channel id changes
							$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sermonsNL_youtube");
							if(!empty($new_value)){
								$get_data[] = 'yt';
							}
							break;
						case "sermonsNL_youtube_key":
							if(!empty($new_value)){
								// it is uncertain whether this is needed, but if the initial yt key was wrong
								// and is now corrected, updating the key needs to trigger loading the archive.
								$get_data[] = 'yt';
							}
							break;
						case "sermonsNL_youtube_weeksback":
							if($new_value > $old_value){
								$get_data[] = 'yt';
							}
							break;
					}
				}
			}
		}
		$msg = __('Successfully saved. ', 'sermons-nl') .
			(empty($get_data) ? "" : __('It may take some seconds before new data are loaded from the different archives. If data doesn\'t load as expected, check the log first.','sermons-nl'));
		// return success
		$json = array(
			'ok' => true,
			'action' => 'sermonsnl_config_submit',
			'sucMsg' => $msg,
			'resources' => implode(',', array_unique($get_data)),
			'nonce' => wp_create_nonce('sermonsnl-background-action')
		);
		print json_encode($json);
		wp_die();
	}
	
	public static function admin_view_log_page(){
		// check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}

        print '<div>
        <h2>SermonsNL '.__('Log page','sermons-nl').'</h2>
        <p>' . sprintf(__('Updating data from the sources happens mostly during background processes. To identify a potential cause of issues that you encounter, you can scroll through the logged messages of these update functions from the past %d days.','sermons-nl'), self::LOG_RETENTION_DAYS) . '</p>
        ';
		global $wpdb;
		$log = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermonsNL_log ORDER BY id DESC");
        
	    if(empty($log)){
	        print '<p>' . __('There are no logged messages available.','sermons-nl') . '</p>';
	    }else{
	        print '<p class="sermonsnl-log">';
	        foreach($log as $r => $line){
				$dt = (new DateTime($line->dt, self::$timezone_db))->setTimeZone(wp_timezone())->format("Y-m-d H:i:s");
				print '<span>' . ($r) . ':</span> ' . $dt . ' (' . esc_html($line->fun) . ') ' . esc_html($line->log). '<br/>';
    	    }
    	    print '</p>';
	    }
	}
	
    // UPDATE FUNCTIONS HANDLED BY CRON JOBS
    
    // handles (1) verifying data of all youtube broadcasts (2) get/update additional data about pastors (name, town) (3) delete old sermons if there is no broadcast; which should be done daily to avoid exceeding the limit (of youtube) and spare resources
    public static function update_daily(){
        // kerktijden: update the archive
        if(get_option('sermonsNL_kerktijden_id')){
            self::log('update_daily', 'updating kerktijden (backward + pastors)');
            sermonsNL_kerktijden::get_remote_data_backward();
            sermonsNL_kerktijdenpastors::get_remote_data();
        }
        // kerkomroep: update the archive and check whether all items are present
        if(get_option('sermonsNL_kerkomroep_mountpoint')){
            self::log('update_daily', 'updating kerkoproep (all + url validation)');
            $ok = sermonsNL_kerkomroep::get_remote_data();
			if(false !== $ok){
				sermonsNL_kerkomroep::validate_remote_urls();
			}
        }
        // youtube: update the entire archive, don't search for new items
        if(get_option('sermonsNL_youtube_channel')){
            self::log('update_daily', 'updating youtube (update all known records)');
            sermonsNL_youtube::get_remote_update_all();
        }
        // delete events that have become redundant
        $rec = self::get_complete_redundant_records();
        self::log('update_daily', 'removing redundant records (n='.count($rec).')');
        foreach($rec as $e){
            $event = sermonsNL_event::get_by_id($e->id);
            $event->delete();
        }
        // delete log items >LOG_RETENTION_DAYS days old
        global $wpdb;
		$wpdb->query("DELETE FROM {$wpdb->prefix}sermonsNL_log WHERE DATEDIFF(CURDATE(),dt) > ".self::LOG_RETENTION_DAYS.";");
        self::log('update_daily', 'done');
        return true;
    }

    // handles updates of the available sources that need to be done frequently but not immediately
    public static function update_quarterly(){
        // kerktijden
        if(get_option('sermonsNL_kerktijden_id')){
            self::log('update_quarterly', 'updating kerktijden (forward)');
            sermonsNL_kerktijden::get_remote_data_forward();
        }
        // kerkomroep
        if(get_option('sermonsNL_kerkomroep_mountpoint')){
            self::log('update_quarterly','updating kerkomroep (all)');
            sermonsNL_kerkomroep::get_remote_data();
        }
        // youtube: get recent ones. it will include new planned broadcasts, new live broadcases, and update recent items
        if(get_option('sermonsNL_youtube_channel')){
            self::log('update_quarterly','updating youtube (last 10)');
            sermonsNL_youtube::get_remote_data(10);
        }
        self::log('update_quarterly', 'done');
        return true;
    }
    
    // UPDATE FUNCTION CALLED BY THE SITE FUNCTIONS
    
    // only checks for live broadcasts. in case of youtube it only does so when a sermon is close to start
    private static function update_now(){
        $last_update_now_time = get_option("sermonsNL_last_update_time", 0);
		// first check if the last check was >60 seconds ago. to avoid running out of quota for the youtube api.
        // perhaps the interval will be a setting later
        if(time() - $last_update_now_time >= 60){
            update_option('sermonsNL_last_update_time', time(), false);
            if(get_option('sermonsNL_kerkomroep_mountpoint')){
                self::log('update_now', 'updating kerkomroep (most recent record)');
                // checking only the first records is reasonably fast (<0.05 sec) so we can do it during page load
                sermonsNL_kerkomroep::get_remote_data(true);
            }
            if(get_option('sermonsNL_youtube_channel')){
                // check if any sermon is close to start (30 min), or that 
                // should have started, and is not broadcasting yet, or that is
                // live. include overnight broadcasts: check yesterday and today
                $data = self::get_complete_records_by_dates(date("Y-m-d", strtotime("now -1 day")), date("Y-m-d"), true);
                foreach($data as $item){
                    $time_to = strtotime($item->dt_start) - time();
                    if($item->yt_live || ($item->yt_planned && $time_to < 1800 && $time_to > -3600 && !$item->yt_dt_actual)){
                        // update the last items. 
                        // live and planned ones should be on top of the list, so we only need those planned + 1 that may be live
                        $planned = sermonsNL_youtube::get_planned(true); 
                        $n = count($planned) + 1;
                        self::log('update_now', 'updating youtube (last '.$n.')');
                        sermonsNL_youtube::get_remote_data($n); 
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
			'more-buttons' => 1, // whether to include the show-more buttons; set to 0 to remove these buttons
			'sermonsnl-logo' => 1 // whether to display the plugin logo; set to 0 to remove the logo. Note that other logos are obligatory.
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
		
		$dt1 = $get_date($atts['offset']);
		$dt2 = $get_date($atts['ending']);
		$count = (int) $atts['count'];
		$datefmt = esc_html((string)$atts['datefmt']);
		$morebuttons = (int)$atts['more-buttons'];

		if($atts['offset'] !== null && !$dt1) return __("Error: Parameter `offset` is an invalid date string in the shortcode.",'sermons-nl');
		if($atts['ending'] !== null && !$dt2) return __("Error: Parameter `ending` is an invalid date string in the shortcode.",'sermons-nl');
		if(!$dt1 && !$dt2) return __("Error: At least one of the parameters `offset` and/or `ending` should be provided in the shortcode.",'sermons-nl');
		if((!$count || $count <= 0) && (!$dt1 || !$dt2)) return __("Error: If one of the parameters `offset` or `ending` is not provided in the shortcode, parameter `count` should be a positive number.",'sermons-nl');
		
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
		    elseif($item->dt_start < date("Y-m-d H:i:s")) $latest = $i;
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
    			$html .= '<div><a href="javascript:;" id="sermonsnl_more_up" onclick="sermonsnl.showmore(\'up\', \''.$datefmt.'\');">' . __("Load earlier sermons", 'sermons-nl') . '</a></div>';
		    }
		}

		$html .= '<ul id="sermonsnl_list">';
		
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
					<a href="javascript:;" id="sermonsnl_more_down" onclick="sermonsnl.showmore(\'down\',\''.$datefmt.'\');">' . __("Load later sermons", 'sermons-nl') . '</a>
				</div>';
		    }
		}
		
		// add logos to general source pages
		$html .= self::add_logos(
			get_option("sermonsNL_kerktijden_id"),
			get_option("sermonsNL_kerkomroep_mountpoint"),
			null,
			get_option("sermonsNL_youtube_channel"),
			$atts['sermonsnl-logo'] != 0
		);

		$html .= '
			</div>';

        return $html;
    }

    private static function add_logos(?int $kt_id, ?int $ko_mp, ?string $yt_vid, ?string $yt_ch, bool $plugin_logo=false, bool $div_embed=true){
		$html = '';
		$logos = array();
		if($kt_id){
			$logos[] = array("url" => "https://www.kerktijden.nl/gemeente/$kt_id/", "img" => "logo_kerktijden.svg", "txt" => sprintf(__("Open the %s website","sermons-nl"), "Kerktijden"));
		}
		if($ko_mp){
			$logos[] = array("url" => "https://www.kerkomroep.nl/kerken/$ko_mp", "img" => "logo_kerkomroep.png", "txt" => sprintf(__("Open the %s website","sermons-nl"), "Kerkomroep"));
		}
		if(($yt_vid)){
			$logos[] = array("url" => "https://www.youtube.com/watch?v=$yt_vid", "img" => "logo_youtube.jpg", "txt" => __("Watch this video on YouTube","sermons-nl"));
		}
		if($yt_ch){
			$logos[] = array("url" => "https://www.youtube.com/channel/$yt_ch", "img" => "logo_youtube.jpg", "txt" => __("Open the YouTube channel","sermons-nl"));
		}
		$html .= '
				<' . ($div_embed ? 'div':'span') . ' class="sermonsnl-logos">
					' . __("Source:", "sermons-nl") . '<br/>';
		foreach($logos as $link){
			$html .= '
					<a href="' . $link["url"] . '" target="_blank" title="'.$link["txt"].'"><img src="' . plugin_dir_url(__FILE__) . 'img/' . $link['img'] . '" alt="' . $link["txt"] . '"/></a>';
		}
		if($plugin_logo){
			$html .= '
					<a href="'.self::PLUGIN_URL.'" target="_blank" title="' . __("Find out more about the Sermons-NL plugin","sermons-nl") . '"><img src="' . plugin_dir_url(__FILE__) . 'img/logo_sermonsnl_' . (get_locale() == 'nl_NL' ? 'NL' : 'EN') . '.png" alt="' . __("Sermons-NL logo","sermons-nl") . '"/></a>';
		}
		$html .= '
				</' . ($div_embed ? 'div':'span') . '>';
		return $html;
	}

	// ajax server handler to check current status of live broadcasts and new live broadcasts
	public static function check_status(){
	    
		ob_clean();
		
		// check new live broadcasts
		self::update_now();
		
		// which to update
		$check_list = (int)$_GET['check_list'];
		$check_lone = (int)$_GET['check_lone'];

		// get data
		$items = array();
		$events = array();
        if(!empty($_GET['live'])){
            foreach($_GET['live'] as $id_str){
                preg_match_all("/^sermonsnl_([a-z]+)_(audio_|video_|)([0-9]+)(_lone|)$/", $id_str, $matches);
                $type = $matches[1][0];
                $id = $matches[3][0];
				$item = self::get_item_by_type($type, $id);
                $items[] = $item;
				//if($check_list && $matches[4][0] == '' && $item->event_id){
				//	$event_ids[] = $item->event_id;
				//}
            }
        }
        $yt_live = sermonsNL_youtube::get_live();
		if($yt_live !== null){
			$items[] = $yt_live;
		}
		$ko_live = sermonsNL_kerkomroep::get_live();
		if($ko_live !== null){
			$items[] = $ko_live;
		}

		$items = array_unique($items, SORT_REGULAR);

		foreach($items as $item){
			if($item->event_id && array_search($item->event, $events) === false) $events[] = $item->event;
		}

		// get html of all selected events
		$json = array(
			'call' => "sermonsnl_checkstatus",
			'events_list' => (count($events) > 0 && $check_list ? array() : null),
			'events_lone' => (count($events) > 0 && $check_lone ? array() : null),
			'items_lone' => (count($items) > 0 && $check_lone ? array() : null)
		);
		if($check_lone){
			foreach($events as $event){
				$json['events_lone'][] = array(
					'id' => 'sermonsnl_event_'.$event->id.'_lone_links',
					'html' => self::html_event_links($event, true)
				);
			}
			foreach($items as $item){
				$id = 'sermonsnl_item_'.$item->type.'_'.$item->id.'_lone';
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
					'id' => 'sermonsnl_event_'.$event->id.'_links',
					'html' => self::html_event_links($event, false),
					'audio_class' => self::css_audio_class($event),
					'video_class' => self::css_video_class($event)
				);
			}
		}
		print json_encode($json);
		wp_die();
	}

	// AJAX RESPONSE TO CLICK FOR MORE ACTION
	public static function show_more(){
		ob_clean();
		$json = array('call' => "sermonsnl_showmore");
		if(!isset($_GET['direction']) || !isset($_GET['current'])){
		    $json["error"] = "insufficient parameters provided";
		}else{
    		$datefmt = (empty($_GET['datefmt']) ? 'long' : (string)$_GET['datefmt']);
    		$direction = (string) $_GET['direction'];
    		$json['direction'] = $direction;
    		$count = 10; // fixed (optional to make setting later)
    		$json['current'] = $_GET['current'];
    		$current = (int) str_replace("sermonsnl_event_", "", $_GET['current']);
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
		print json_encode($json);
		wp_die();
	}

	private static function css_audio_class($event){
		if($event instanceof sermonsNL_event){
			$item = $event->kerkomroep;
			$url = ($item ? $item->audio_url : null);
			$live = ($item ? $item->live : null);
		}else{
			$url = $event->ko_audio_url;
			$live = $event->ko_live;
		}
		return 'sermonsnl-av' . ($url ? ' sermonsnl-audio' . ($live ? '-live' : '') : '');
	}

	private static function css_video_class($event){
		if($event instanceof sermonsNL_event){
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
		return 'sermonsnl-av' . ($yt || $ko_url ? ' sermonsnl-video' . ($yt_live || $ko_live ? '-live' : ($yt_planned ? '-planned' : '')) : '');
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
			// to do: check how to deal with cancelled sermons
			// $cancelled = !empty($event->kt['cancelled']);
			if($standalone){
				$html .= '<div>';
			}else{
				$html .= '<li id="sermonsnl_event_' . $event->id . '"' . (!empty($event->display_open) ? ' class="sermonsnl-open"' : '') . ' onclick="sermonsnl.toggledetails(this);">';
				$html .= '<span class="'.self::css_audio_class($event).'"></span>';
				$html .= '<span class="'.self::css_video_class($event).'"></span>';
			}
			$html .= '<span class="sermonsnl-dt">' . ucfirst(self::datefmt($datefmt, $event->dt_start)) . ' </span><span class="sermonsnl-pastor">' . $event->pastor . ' </span><span class="sermonsnl-type">' . ($event->sermontype == "Reguliere dienst" ? "" : $event->sermontype) . '</span>';
			if(!$standalone){
				$html .= '<div class="sermonsnl-details"><div>';
			}
			if(!($event->yt_video_id || $event->ko_id)){
				$html .= ($event->kt_cancelled ? __('This sermon has been cancelled.','sermons-nl') : __('There are no broadcasts for this sermon.','sermons-nl'));
			}
			else{
				$html .= '<div class="sermonsnl-links" id="sermonsnl_event_'.$event->id.($standalone?'_lone':'').'_links">';
				$html .= self::html_event_links($event, $standalone);
				$html .= '</div>';
    			$html .= '<div class="sermonsnl-description">' . nl2br(esc_html($event->description)) . '</div>';
			}
			if($standalone){
				$html .= '</div>';
			}else{
				$html .= '</div></div>';
				$html .= '</li>';
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
		<div class="sermonsnl_item_lone">
		<div>
		<p>' . ucfirst($dt) . ' (';
		if($item_type == 'kerkomroep'){
			$html .= esc_html($item->pastor);
		}elseif($item_type == 'youtube'){
			$html .= esc_html($item->title);
		}
		$html .= ')</p>
		<div id="sermonsnl_item_'.$item_type.'_'.$item_id.'_lone" class="sermonsnl-links">';

		if($item_type == 'kerkomroep'){
			$html .= self::html_ko_audio_video_link($item, true);
		}elseif($item_type == 'youtube'){
			$html .= self::html_yt_video_link($item, true);
		}

		$html .= '
		</div>
		</div>';

		// add logo
		$html .= self::add_logos(
			null,
			($item_type == 'kerkomroep' ? get_option('sermonsNL_kerkomroep_mountpoint') : null),
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
		<div class="sermonsnl_event_lone">';

		$html .= self::html_list_items($event, 1, 'long', true);

		$html .= self::add_logos(
			($items['kerktijden'] ? get_option('sermonsNL_kerktijden_id') : null),
								 ($items['kerkomroep'] ? get_option('sermonsNL_kerkomroep_mountpoint') : null),
								 ($items['youtube'] ? $event[0]->yt_video_id : null),
								 null, // no yt channel
						   false // no logo
		);

		$html .= '
		</div>';
			return $html;
	}

	private static function html_event_links($event, bool $standalone=false){
		if($event instanceof sermonsNL_event){
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
			$html .= self::html_ko_audio_video_link($event, $standalone);
		}
		// NB: old code, this needs change when implementing kerkdienst gemist
		//if($event->kg_audio_url){
		//	$html .= '<p class="audio' . ($event->kg_a['live'] ? ' is-live' : '') . '"><a' . ($event->kg_a['link'] ? ' href="' . $event->kg_a['link'] . '" target="_blank" id="kg_a_'.$dt.'" title="Luisteren via kerkdienstgemist" onclick="return !kerkdiensten.playmedia(this, \'' . $event->kg_a['mimetype'] . '\',\'kg-audio\');"' : '') . '>Kerkdienstgemist' . ($event->kg_a['live'] ? ($event->kg_a['link'] ? ' (live)' : ' (archiveren)') : '') . '</a></p>';
		//}
		//if($event->kg_video_url){
		//	$html .= '<p class="video' . ($event->kg_v['live'] ? ' is-live' : '') . '"><a' . ($event->kg_v['link'] ? ' href="' . $event->kg_v['link'] . '" target="_blank" id="kg_v_'.$dt.'" title="Kijken via kerkdienstgemist" onclick="return !kerkdiensten.playmedia(this, \'' . $event->kg_v['mimetype'] . '\',\'kg-video\');"' : '') . '>Kerkdienstgemist' . ($event->kg_v['live'] ? ($event->kg_v['link'] ? ' (live)' : ' (archiveren)') : '') . '</a></p>';
		//}
		if($yt_id){
			$html .= self::html_yt_video_link($event, $standalone);
		}
        return $html;
	}

	private static function html_ko_audio_video_link($data, $standalone){
		if($data instanceof sermonsNL_event){
			$item = $data->kerkomroep;
			if(!$item) return '';
		}elseif($data instanceof sermonsNL_kerkomroep){
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
			$html .= '
				<p id="sermonsnl_kerkomroep_audio_'.$ko_id.($standalone?'_lone':'').'" class="sermonsnl-audio' . ($ko_live ? '-live' : '') . '">
					<a id="ko_audio_'.$ko_id.($standalone?'_lone':'').'" href="' . $ko_audio_url . '" target="_blank" title="' . sprintf(__("Listen to %s audio","sermons-nl"),"Kerkomroep") . '" onclick="return !sermonsnl.playmedia(this, \'' . $ko_audio_mimetype . '\', \'ko-audio\''.($standalone?',true':'').');">
						Kerkomroep' . ($ko_live ? ' (' . __('live','sermons-nl') . ')' : '') . '
					</a>
				</p>';
		}
		if($ko_video_url){
			$html .= '
				<p id="sermonsnl_kerkomroep_video_'.$ko_id.($standalone?'_lone':'').'" class="sermonsnl-video' . ($ko_live ? '-live' : '') . '">
					<a id="ko_video_'.$ko_id.($standalone?'_lone':'').'" href="' . $ko_video_url . '" target="_blank" title="' . sprintf(__('Watch %s video','sermons-nl'),"Kerkomroep") . '" onclick="return !sermonsnl.playmedia(this, \'' . $ko_video_mimetype . '\', \'ko-video\''.($standalone?',true':'').');">
						Kerkomroep' . ($ko_live ? ' (' . __('live','sermons-nl') . ')' : '') . '
					</a>
				</p>';
		}
		return $html;
	}

	private static function html_yt_video_link($data, $standalone){
		if($data instanceof sermonsNL_event){
			$item = $data->youtube;
			if(!$item) return '';
		}elseif($data instanceof sermonsNL_youtube){
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
		$html .= '
				<p id="sermonsnl_youtube_'.$yt_id.($standalone?'_lone':'').'" class="sermonsnl-video' . ($yt_live ? '-live' : ($yt_planned ? '-planned' : '')) . '">
					<a id="yt_video_'.$yt_id.($standalone?'_lone':'').'" href="https://www.youtube.com/watch?v='.$yt_video_id.'" target="_blank" title="' . sprintf(__("Watch %s video","sermons-nl"), "YouTube") . '" onclick="return !sermonsnl.playmedia(this, \'video/youtube\',\'yt-video\''.($standalone?',true':'').');">
						YouTube' . ($yt_live ? ' (' . __('live','sermons-nl') . ')' : ($yt_planned ? ' (' . __('planned','sermons-nl') . ')' : '')) . '
					</a>';
		if(!$standalone){
			$html .= '
					<a href="https://www.youtube.com/watch?v='.$yt_video_id.'" target="_blank" title="' . __("Open video on YouTube","sermons-nl") . '">
						<img src="' . plugin_dir_url(__FILE__) . 'img/icon_newwindow.png" style="height:15px;"/>
					</a>';
		}
		$html .= '
				</p>'; // open in new window icon: https://commons.wikimedia.org/wiki/File:OOjs_UI_icon_newWindow-ltr.svg
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
    // to do: remove rand part and set fixed version
    public static function add_site_scripts_and_styles(){
		wp_enqueue_style('sermonsnl-stylesheet', plugin_dir_url(__FILE__) . 'css/site.css', array(), '1.0.1');
		wp_enqueue_script('sermonsnl-javascript', plugin_dir_url(__FILE__) . 'js/site.js', array('jquery'), '1.0.1');
	}
	public static function add_site_custom_script(){
		print '
		<script type="text/javascript">
		sermonsnl.admin_url = "' . admin_url( 'admin-ajax.php') . '";
		sermonsnl.check_interval = ' . self::CHECK_INTERVAL . ';
		</script>';
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
        $sql = sermonsNL_event::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        
        // create tables for kerktijden scheduled sermons and pastors
        $sql = sermonsNL_kerktijden::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        $sql = sermonsNL_kerktijdenpastors::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        
        // create table for kerkomroep broadcasts
        $sql = sermonsNL_kerkomroep::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        
        // create table for youtube broadcasts
        $sql = sermonsNL_youtube::query_create_table($prefix, $charset_collate);
        dbDelta($sql);

		// create table for log
		$sql = "CREATE TABLE {$prefix}sermonsNL_log (
			id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
			dt datetime NULL,
			fun varchar(255) DEFAULT '' NOT NULL,
			log varchar(255) DEFAULT '' NOT NULL,
			PRIMARY KEY  (id)
			) $charset_collate;";
		dbDelta($sql);

        // set plugin options to null if they don't exist
        // this tells that none of the broadcast media is active
        foreach(self::OPTION_NAMES as $opt_name => $args){
            if(null === get_option($opt_name, null)){
                update_option($opt_name, (isset($args['default']) ? $args['default'] : null));
            }
        }

        // set scheduled jobs
        if(!wp_next_scheduled('sermonsNL_cron_quarterly'))
            wp_schedule_event(time(), 'fifteen_minutes', "sermonsNL_cron_quarterly");
        if(!wp_next_scheduled('sermonsNL_cron_daily'))
    		wp_schedule_event(strtotime('tomorrow 03:00:00'), 'daily', 'sermonsNL_cron_daily');

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
        $timestamp = wp_next_scheduled('sermonsNL_cron_quarterly');
        if($timestamp) wp_unschedule_event($timestamp, 'sermonsNL_cron_quarterly');
        $timestamp = wp_next_scheduled('sermonsNL_cron_daily');
        if($timestamp) wp_unschedule_event($timestamp, 'sermonsNL_cron_daily');
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
                "kerkdienstgemist",
                "youtube",
				"log"
            ) as $surname){
            $table_name = $wpdb->prefix . "sermonsNL_" . $surname;
            $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
        }

        // delete plugin options
        foreach(self::OPTION_NAMES as $opt_name => $args){
            delete_option($opt_name);
        }
    }

    // LOAD TRANSLATION
    public static function load_translation(){
		 load_plugin_textdomain( 'sermons-nl', false, dirname(plugin_basename( __FILE__ )) . '/languages');
	}
    
    // RECORD ACTIVITIES RELATED TO LOADING REMOTE DATA
    public static function log(string $fun, string $log){
		$data = array(
			'dt' => (new DateTime("now", self::$timezone_db))->format("Y-m-d H:i:s"),
			'fun' => $fun,
			'log' => $log
		);
		global $wpdb;
		$wpdb->insert($wpdb->prefix . 'sermonsNL_log', $data);
		return $data;
	}
    
}

sermonsNL::$timezone_db = new DateTimeZone("UTC");
sermonsNL::$timezone_ko = sermonsNL::$timezone_kt = new DateTimeZone("Europe/Amsterdam");

// include other classes
require_once(plugin_dir_path(__FILE__) . 'event.php');
require_once(plugin_dir_path(__FILE__) . 'kerktijden.php');
require_once(plugin_dir_path(__FILE__) . 'kerkomroep.php');
require_once(plugin_dir_path(__FILE__) . 'youtube.php');

// ACTIVATION, DEACTIVATION AND UNINSTALL HOOKS
register_activation_hook(__FILE__, array('sermonsNL', 'activate_plugin'));
register_deactivation_hook(__FILE__, array('sermonsNL', 'deactivate_plugin'));
register_uninstall_hook(__FILE__, array('sermonsNL', 'uninstall_plugin'));

// LOAD TRANSLATION
add_action('plugins_loaded', array('sermonsNL','load_translation'));

// FILTERS TO GET THE CRON JOBS DONE (UPDATING FROM THE SOURCES)
add_filter('cron_schedules', array('sermonsNL','add_cron_interval'));
add_action('sermonsNL_cron_quarterly', array('sermonsNL','update_quarterly'));
add_action('sermonsNL_cron_daily', array('sermonsNL','update_daily'));

// ACTION NEEDED TO LET THE YOUTUBE / KERKOMROEP / KERKTIJDEN ARCHIVES BE LOADED IN THE BACKGROUND
add_action('wp_ajax_sermonsnl_get_remote_data_in_background', array('sermonsNL', 'get_remote_data_in_background'));

// ACTIONS FOR ADMIN
add_action('admin_init', array('sermonsNL','register_settings'));
add_action('admin_menu', array('sermonsNL','add_admin_menu'));
add_action('admin_enqueue_scripts', array('sermonsNL','add_admin_scripts_and_styles'));
add_action('admin_head', array("sermonsNL","add_admin_custom_script"));
add_action('wp_ajax_sermonsnl_admin_navigate_table', array('sermonsNL','admin_navigate_table'));
add_action('wp_ajax_sermonsnl_admin_show_details', array('sermonsNL','admin_show_details'));
add_action('wp_ajax_sermonsnl_admin_link_item_to_event', array('sermonsNL','link_item_to_event'));
add_action('wp_ajax_sermonsnl_admin_unlink_item', array('sermonsNL','unlink_item'));
add_action('wp_ajax_sermonsnl_admin_delete_event', array('sermonsNL','delete_event'));
add_action('wp_ajax_sermonsnl_submit_update_event', array('sermonsNL','sermonsnl_submit_update_event'));
add_action('wp_ajax_sermonsnl_config_submit', array('sermonsNL','config_submit'));

// ACTIONS AND SHORTCODES FOR SHOWING CONTENT ON THE WEBSITE
// shortcodes
add_shortcode('sermons-nl-list', array('sermonsNL', 'html_sermons_list'));
add_shortcode('sermons-nl-event', array('sermonsNL', 'html_sermons_event'));
add_shortcode('sermons-nl-item', array('sermonsNL', 'html_sermons_item'));
// html, js and css
add_action('wp_enqueue_scripts', array('sermonsNL','add_site_scripts_and_styles'));
add_action('wp_head', array('sermonsNL','add_site_custom_script'));

// ajax: show more button
add_action('wp_ajax_sermonsnl_showmore', array('sermonsNL','show_more'));
add_action('wp_ajax_nopriv_sermonsnl_showmore', array('sermonsNL','show_more'));
// ajax: check status
add_action('wp_ajax_sermonsnl_checkstatus', array('sermonsNL','check_status'));
add_action('wp_ajax_nopriv_sermonsnl_checkstatus', array('sermonsNL','check_status'));

?>
