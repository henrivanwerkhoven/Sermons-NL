<?php

/*
	Plugin Name: Sermons Netherlands
	Plugin URI: 
	Description: List planned and broadcasted Dutch church services in a convenient way
	Version: 0.1
	Author: Henri van Werkhoven
	Author URI: 
	License: GPL2
	Text Domain: sermons
	Domain Path: NULL
*/

class sermonsNL{

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
        "sermonsNL_kerkdienstgemist_rssid"         => array('type' => 'integer', 'default' => null),
        "sermonsNL_kerkdienstgemist_audiostreamid" => array('type' => 'integer', 'default' => null),
        "sermonsNL_kerkdienstgemist_videostreamid" => array('type' => 'integer', 'default' => null),
        "sermonsNL_youtube_channel"                => array('type' => 'string',  'default' => null),
        "sermonsNL_youtube_key"                    => array('type' => 'string',  'default' => null)
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
                  end) as dt_start,
            (case when type_from='manual' then e.type_manual
                  when type_from='kerktijden' then kt.sermontype
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
	            self::kerktijden_scripts();
	            return sermonsNL_kerktijden::get_by_id($id);
	        case 'kerkomroep':
	            self::kerkomroep_scripts();
	            return sermonsNL_kerkomroep::get_by_id($id);
	        case 'youtube':
	            self::youtube_scripts();
	            return sermonsNL_youtube::get_by_id($id);
	        default:
	            wp_trigger_error(__CLASS__."::get_item_by_type", "Wrong type '$type' parsed.", E_USER_ERROR);
	            return null;
	    }
	}
	
	
	// KERKTIJDEN FUNCTIONS
	
	// dynamically load additional scripts
	public static function kerktijden_scripts(){
	    require_once(plugin_dir_path(__FILE__) . 'kerktijden.php');
	}
	
	// trigger a reset when the kerktijden_id setting changes
	public static function kerktijden_change_id($old_value, $value){
	    self::kerktijden_scripts();
	    sermonsNL_kerktijden::change_id($old_value, $value);
	}
	
	
	// KERKOMROEP FUNCTIONS
	
	// dynamically load additional scripts
	public static function kerkomroep_scripts(){
	    require_once(plugin_dir_path(__FILE__) . 'kerkomroep.php');
	}
	
	// trigger a reset when the kerkomroep_mountpoint setting changes
	public static function kerkomroep_change_mountpoint($old_value, $value){
	    self::kerkomroep_scripts();
	    sermonsNL_kerkomroep::change_mountpoint($old_value, $value);
	}
	
	
	// YOUTUBE FUNCTIONS
	
	// dynamically load additional scripts
	public static function youtube_scripts(){
	    require_once(plugin_dir_path(__FILE__) . 'youtube.php');
	}
	
	// trigger a reset when the kerkomroep_mountpoint setting changes
	public static function youtube_change_channel($old_value, $value){
	    self::youtube_scripts();
	    sermonsNL_youtube::change_channel($old_value, $value);
	}
	

    // ADMIN PAGE HANDLING
    public static function add_admin_menu(){
        $num_issues = count(self::get_events_with_issues());
        $tag_issue = ($num_issues ? ' <span class="awaiting-mod">'.$num_issues.'</span>' : '');
        add_menu_page(__('SermonsNL','sermons-nl'), __('SermonsNL','sermons-nl') . $tag_issue, self::$capability, 'sermons-nl', array('sermonsNL','admin_overview_page'), 'dashicons-admin-media', 7);
        add_submenu_page('sermons-nl', __('SermonsNL administration','sermons-nl'), __('Administration','sermons-nl'), self::$capability, 'sermons-nl-admin', array('sermonsNL','admin_administration_page'));
	    add_submenu_page('sermons-nl', __('SermonsNL configuration','sermons-nl'), __('Configuration','sermons-nl'), self::$capability, 'sermons-nl-config', array('sermonsNL','admin_edit_config_page'));
	    add_submenu_page('sermons-nl', __('SermonsNL log','sermons-nl'), __('Log','sermons-nl'), self::$capability, 'sermons-nl-log', array('sermonsNL','admin_view_log_page'));
	    add_submenu_page('sermons-nl', __('SermonsNL testpage','sermons-nl'), __('Test page','sermons-nl'), self::$capability, 'sermons-nl-test', array('sermonsNL','admin_test_page'));
	}
	
	public static function add_admin_scripts_and_styles($hook){
	    if(strpos($hook,'sermons-nl') === false) return;
	    wp_enqueue_style('sermonsNL-admin-css', plugin_dir_url(__FILE__).'css/admin.css', array(), '0.4');
		wp_enqueue_script('sermonsNL-admin-js', plugin_dir_url(__FILE__) . 'js/admin.js', array('jquery'), '0.5');
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
		
		self::kerktijden_scripts();
		self::kerkomroep_scripts();
		self::youtube_scripts();
		$events = sermonsNL_event::get_all();
		$kerktijden = sermonsNL_kerktijden::get_all();
		$kerkomroep = sermonsNL_kerkomroep::get_all();
		$youtube = sermonsNL_youtube::get_all();
		
        $issues = self::get_events_with_issues();

		print '
		<div class="sermonsnl-overview">
		    <h2>
		        ' . __("SermonsNL overview page","sermons-nl") . '
	            <img src="' . plugin_dir_url(__FILE__) . 'img/waiting.gif" id="sermonsnl_waiting"/><!-- icon freely available at https://icons8.com/preloaders/en/circular/floating-rays/ -->
    	    </h2>
		    <div class="sermonsnl-container">
		        <h3>' . __('Status','sermons-nl') . ':</h3>
		        <div>
		            <p>' . 
                    sprintf(__('Your site includes a total of %d sermons.','sermons-nl'), count($events)) . 
                    ' ' .
                    sprintf(__('These are based on %d entries from Kerktijden, %d from Kerkomroep, and %d from Youtube.', 'sermons-nl'), count($kerktijden), count($kerkomroep), count($youtube)) . 
                    '</p>
                    <p>';
        if(!empty($issues)){
            print sprintf(__("There are %d issues that require your attention.", 'sermons-nl'), count($issues)); 
        }else{
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
                                <td><a href="javascript:;" onclick="sermonsnl_admin.show_details(' . $event->id . ');">' . self::datefmt('short', $event->dt_start) . '</a></td>
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
            </div>
            <div class="sermonsnl-container">
                <h3>' . __("Shortcode builder","sermons-nl") . ':</h3>
                <div>
                    <p>' . __("Build a shortcode to insert the list of sermons to your page.","sermons-nl") . '</p>
                    <p><small>' . 
            sprintf(__('For start and end date, you can enter any text that can be interpreted as a date based on supported %s formats.', 'sermons-nl'), '<a href="https://www.php.net/manual/en/datetime.formats.php" target="_blank">DateTime</a>') . 
            ' ' .
            sprintf(__('The date format, that is used for printing the sermon dates can be either "long" or "short" (which will print a long or short date format followed by hours and minutes) or a suitable date-time format, see %s.', 'sermons-nl'), '<a href="https://www.php.net/manual/en/datetime.format.php" target="_blank">DateTime::format()</a>') .
            '</small></p>
                    <p>
                        <a class="sermonsnl-copyshort" onclick="sermonsnl_admin.copy_shortcode(this);" title="' . __('Click to copy the shortcode','sermons-nl') . '">
        		            <img src="https://zandbak.cgkzeist.nl/wp-content/plugins/sermons-nl/img/copy.png"> 
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
                            <td>' . __("Start date",'sermons-nl') . '</td>
                            <td><input type="text" id="sermonsnl_start_date" value="now -13 days"/></td>
                        </tr>
                        <tr>
                            <td>' . __("End date",'sermons-nl') . '</td>
                            <td><input type="text" id="sermonsnl_end_date" value="now +8 days"/></td>
                        </tr>
                        <tr>
                            <td>' . __("Number of events,'sermons-nl'") . '</td>
                            <td><input type="text" id="sermonsnl_count" value="10" disabled/></td>
                        </tr>
                        <tr>
                            <td>' . __('Date format (default: "long")',"sermons-nl") . '</td>
                            <td><input type="text" id="sermonsnl_datefmt", value="long"/></td>
                        </tr>
                        <tr>
                            <td>' . __("Buttons","sermons-nl") . '</td>
                            <td><input type="checkbox" id="sermonsnl_more-buttons" checked/><label for="sermonsnl_more-buttons"> ' . __('Include buttons to load earlier and later sermons (default: on)','sermons-nl') . '</label></td>
                        </tr>
                    </table>
                </div>
            </div>
            <div class="sermonsnl-container">
                <h3>' . __("Frequently asked questions","sermons-nl") . ':</h3>
                <div>
                    <h4>Why does Sermons-NL not support Kerkdienst Gemist?</h4>
                    <p>Kerkdienst Gemist is a service similar to Kerkomroep. Initially, only Kerkomroep was included, because the church for which the plugin was first developed uses that service. However, support for Kerkdienst Gemist is possible and planned in one of the next releases. We welcome volunteers to test this functionality in a beta version. Please visit the issue page and add your reaction or send an e-mail to the developer.</p>
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
    		    <h3 id="sermonsnl_admin_month">' . __(date("F", strtotime($dt1)), "sermons-nl") . ' ' . date("Y", strtotime($dt1)) . '</h3>';
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
	                <td>' . ($rec->include ? '' : '<img src="' . plugin_dir_url(__FILE__) . 'img/not_included.gif" alt="X" title="' . __("Not included","sermons-nl") . '"/>') . '</td>
	                <td><a href="javascript:;" onclick="sermonsnl_admin.show_details('.$rec->id.');">' . self::datefmt("short", $rec->dt_start) . '</a></td>
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
		            <img src="' . plugin_dir_url(__FILE__) . 'img/close.gif" class="sermonsnl-closebtn" title="'.__("Cancel","sermons-nl").'" onclick="if(confirm(\''.__('Do you want to cancel creating this new event?') . '\')) sermonsnl_admin.hide_details(null);"/>
		            Create event manually
		        </h3>
		        <form method="post" onsubmit="return !sermonsNL_admin.create_new_event(this);">
		            <input type="hidden" name="action" value="sermonsnl_new_event"/>
		            <input type="hidden" name="_wpnonce" value="' . wp_create_nonce('sermonsnl-administration') . '"/>
    		        <table>
	    	            <tbody>
		                    <tr>
		                        <td>' . __('Date','sermons-nl') . ':</td>
		                        <td><input type="text" name="date" value=""/></td>
		                    </tr>
		                    <tr>
		                        <td>' . __('Settings','sermons-nl') . ':</td>
		                        <td><input type="checkbox" name="include" id="sermonsnl-new-include" checked/><label for="sermonsnl-new-include"> Include in sermons list</label></td>
	                        </tr>
	                        <tr>
	                            <td></td>
	                            <td><input type="checkbox" name="protected" id="sermonsnl-new-protected" checked/><label for="sermonsnl-new-protected"> Protect from automatic deletion*</label></td>
	                        </tr>
		                    <tr>
	                            <td></td>
	                            <td><input type="submit" value="' . __('Save','sermons-nl') . '"/></td>
                            </tr>
                        </tbody>
                    </table>
                </form>';
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
        		        <li>Or copy the shortcode for using this single item in a message or page.</li>
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
            		            <td>' . self::datefmt("short", $item->dt) . '</td>
            		            <td>
            		                <a onclick="sermonsnl_admin.copy_shortcode(this);" title="' . __('Click to copy the shortcode','sermons-nl') . '" class="sermonsnl-copyshort">
            		                    <img src="' . plugin_dir_url(__FILE__) . 'img/copy.png"/>
            		                    Shortcode
            		                    <div>';
            		    $html .= '[sermons-nl-item type="' . $item->item_type . '" id="' . $item->id . '"]';
            		    $html .= '</div>
            		                </a>
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
            		                $html .= self::datefmt('short', $event->dt);
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
        		    $html .= self::datefmt("short", $event->dt) . '</h3>
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
        		                $html .= '<tr><td>' . ucfirst($type) . '</td><td>' . self::datefmt("short", $item->dt) . '</td>';
        		                $html .= '<td>
        		                    <a onclick="sermonsnl_admin.copy_shortcode(this);" title="' . __('Click to copy the shortcode','sermons-nl') . '" class="sermonsnl-copyshort">
        		                        <img src="' . plugin_dir_url(__FILE__) . 'img/copy.png"/>
        		                        Shortcode
        		                        <div>';
            		    $html .= '[sermons-nl-item type="' . $type . '" id="' . $item->id . '"]';
            		    $html .= '</div>
            		                </a>
            		            </td>';
        		                $html .= '<td><a class="sermonsnl-linktoevent" href="javascript:;" onclick="sermonsnl_admin.unlink_item(\'' . $type . '\', ' . $item->id . ');"><img src="' . plugin_dir_url(__FILE__) . 'img/link.png"/> ' . __('Unlink item','sermons-nl') . '</a></td>';
        		                $html .= '</tr>';
        		            }
        		        }
        		        $html .= '</table>';
        		    }
        		    //  class="wp-list-table widefat fixed striped pages"
        		    $html .= '
        		    <p><b>' . __('Event settings', 'sermons-nl') . '</b></p>
        		    <form id="sermonsnl_update_event">
        		        <table>
        		            <tr>
        		                <td>Date time:</td>
        		                <td><select name="dt_from">';
        		    foreach(array('auto','manual','kerktijden','kerkomroep','youtube') as $value){
        		        $html .= '<option value="'.$value.'"' . ($value == $event->dt_from ? ' selected="selected"' : '') . '>' . $value . '</option>';
        		    }
        		    $html .= '</select></td>
        		            </tr>
        		            <tr>
        		                <td>' . __('Manual date:') . '</td>
        		                <td><input type="text" name="dt_manual" value="' . ($event->dt_manual === null ? "" : $event->dt_manual) . '"/></td>
        		            </tr>
        		        </table>
        		    </form>';
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

		// return settings form
		print '
		<div class="sermonsNL_settings">
			<h2>' . __("Settings for church services") . '</h2>
			<form method="post" action="options.php">';
		settings_fields('sermonsNL_options_group'); 
		//  class="wp-list-table widefat fixed striped pages"
		print '
				<table>
				
					<tbody id="kerktijden_settings"' . ($kt_id ? '' : ' class="settings_disabled"') . '>
						<tr>
							<th colspan="2">Kerktijden.nl</th>
						</tr>
						<tr class="always_visible">
						    <td colspan="2"><input type="checkbox"' . ($kt_id ? ' checked="checked"' : '') . ' id="kerktijden_checkbox" onclick="sermonsnl_admin.toggle_kerktijden(this);"/><label for="kerktijden_checkbox">' . __("Enable Kerktijden","sermons-nl") . '</label></td>
						</tr>
						<tr class="collapsible_setting condition">
						    <td colspan="2">' . __("The use of data from this tool on your own website is permitted, provided that the link and logo are provided. The plugin will add it for you, please do not hide it in any way.", "sermons-nl") . '</td>
						</tr>
						<tr class="collapsible_setting">
							<td>' . __("Kerktijden identifier", "sermons-nl") . ': 
								<div class="help">
									<figure><img src="' . plugin_dir_url(__FILE__) . 'img/kt_identifier.jpg"/><figcaption>' . __("Browser to our church's page on","sermons-nl") . ' <a href="https://www.kerktijden.nl" target="_blank">www.kerktijden.nl</a> ' . __("and copy the number from the url, e.g. 999 in this figure.", "sermons-nl") . '</figcaption></figure>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_kerktijden_id" id="input_kerktijden_id" value="'. ($kt_id ? $kt_id : '') . '"/></td>
						</tr>
						<tr class="collapsible_setting">
						    <td>' . __("Number of weeks back","sermons-nl") . ':
						        <div class="help"><div>' . __("How many weeks to look back when loading the kerktijden archive","sermons-nl") . '</div></div>
						    </td>
						    <td><input type="text" name="sermonsNL_kerktijden_weeksback" value="'. ($kt_weeksback ? $kt_weeksback : '') . '""/></td>
						</tr>
						<tr class="collapsible_setting">
							<td>' . __("Number of weeks ahead","sermons-nl") . ':
								<div class="help"><div>' . __("How many weeks ahead in time to load kerktijden data","sermons-nl") . '</div></div>
							</td>
							<td><input type="text" name="sermonsNL_kerktijden_weeksahead" value="'. ($kt_weeksahead ? $kt_weeksahead : '') . '""/></td>
						</tr>
					</tbody>
					
					<tbody id="kerkomroep_settings"' . ($ko_mp ? '' : ' class="settings_disabled"') . '>
					    <tr>
							<th colspan="2">Kerkomroep.nl</th>
						</tr>
						<tr class="always_visible">
						    <td colspan="2"><input type="checkbox"' . ($ko_mp ? ' checked="checked"' : '') . ' id="kerkomroep_checkbox" onclick="sermonsnl_admin.toggle_kerkomroep(this);"/><label for="kerkomroep_checkbox">' . __("Enable Kerkomroep","sermons-nl") . '</label></td>
						</tr>
						<tr class="collapsible_setting condition">
						    <td colspan="2">' . __("The use of data from this tool on your own website is permitted, provided that the link and logo are provided. The plugin will add it for you, please do not hide it in any way.", "sermons-nl") . '</td>
						</tr>
						<tr class="collapsible_setting">
							<td>' . __("Mount point", "sermons-nl") . ':
								<div class="help">
									<figure><img src="' . plugin_dir_url(__FILE__) . 'img/ko_url.jpg"/><figcaption>' . __("Browse to your church's page on","sermons-nl") . ' <a href="https://www.kerkomroep.nl" target="_blank">kerkomroep.nl</a> ' . __("and copy the number from the end of the url, e.g. 99999 in this figure.") . '</figcaption></figure>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_kerkomroep_mountpoint" id="input_kerkomroep_id" value="' . ($ko_mp ? $ko_mp : '') . '"/></td>
						</tr>
					</tbody>
					
                    <tbody id="kerkdienstgemist_settings"' . ($kg_rss_id ? '' : ' class="settings_disabled"') . '>
						<tr>
							<th colspan="2">Kerkdienstgemist.nl</th>
						</tr>
						<tr class="always_visible">
						    <td colspan="2"><input type="checkbox"' . ($kg_rss_id ? ' checked="checked"' : '') . ' id="kerkdienstgemist_checkbox" onclick="sermonsnl_admin.toggle_kerkdienstgemist(this);"/><label for="kerkdienstgemist_checkbox">' . __("Enable Kerkdienst gemist","sermons-nl") . '</label></td>
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
                    </tbody>
                    
                    <tbody id="youtube_settings"' . ($yt_channel ? '' : ' class="settings_disabled"') . '>
						<tr>
							<th colspan="2">YouTube.com</th>
						</tr>
						<tr class="always_visible">
						    <td colspan="2"><input type="checkbox"' . ($yt_channel ? ' checked="checked"' : '') . ' id="youtube_checkbox" onclick="sermonsnl_admin.toggle_youtube(this);"/><label for="youtube_checkbox">' . __("Enable YouTube","sermons-nl") . '</label></td>
						</tr>
						<tr class="collapsible_setting condition">
						    <td colspan="2">' . __("Setting the channel may take a while depending on the number of available videos.", "sermons-nl") . '</td>
						</tr>
						<tr class="collapsible_setting">
							<td>YouTube channel ID: 
								<div class="help">
									<figure><img src="' . plugin_dir_url(__FILE__) . '/img/yt_channel.jpg"/><figcaption>Browse to your church\'s YouTube channel and copy the youtube channel ID from the url.</figcaption></figure>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_youtube_channel" id="input_youtube_id" value="' . $yt_channel . '"/></td>
						</tr>
						<tr class="collapsible_setting">
							<td>YouTube api key: 
								<div class="help">
									<div>Visit <a href="https://developers.google.com/youtube/v3/getting-started" target="_blank">https://developers.google.com/youtube/v3/getting-started</a> to learn how to obtain a YouTube api key.</div>
								</div>
							</td>
							<td><input type="text" name="sermonsNL_youtube_key" value="' . $yt_key . '"/></td>
						</tr>
					</tbody>
				</table>';
		submit_button();
		print '
			</form>
		</div>';
	}
	
	public static function admin_view_log_page(){
		// check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}

        print '<div>
        <h2>SermonsNL '.__('Log','sermons-nl').'</h2>
        <p>' . __('Updating data from the sources happens mostly during background processes. To identify a potential cause of issues that you encounter, you can scroll through the logged messages of these update functions.') . '</p>
        ';
        
	    $log = file(plugin_dir_path(__FILE__) . 'log.log');
	    if(empty($log)){
	        print '<p>' . __('There are no logged messages available.') . '</p>';
	    }else{
	        $log = array_reverse($log);
	        print '<p class="sermonsnl-log">';
	        $n = count($log);
    	    foreach($log as $r => $line){
    	        print '<span>' . ($n-$r) . ':</span> ' . esc_html($line) . '<br/>';
    	    }
    	    print '</p>';
	    }
	    print '
	    <p>' . __('End of log file.') . '</p>';

	}
	
	public static function admin_test_page(){
	    // check permission
		if(!current_user_can(self::$capability)){
			wp_die("No permission");
		}

	    self::kerktijden_scripts();
	    self::kerkomroep_scripts();
	    self::youtube_scripts();
	    
	    print '<p>Show events with issues:</p><p>';
        $rec = self::get_events_with_issues();
        foreach($rec as $event){
            print '#' . $event->id . ' (nrec='.$event->n_rec.')<br/>';
        }
        print 'EOF</p>';
        
        print get_locale();

	}

    // UPDATE FUNCTIONS HANDLED BY CRON JOBS
    
    // handles (1) verifying data of all youtube broadcasts (2) get/update additional data about pastors (name, town) (3) delete old sermons if there is no broadcast; which should be done daily to avoid exceeding the limit (of youtube) and spare recourses
    public static function update_daily(){
        // kerktijden: update the archive
        if(get_option('sermonsNL_kerktijden_id')){
            self::log('update_daily', 'updating kerktijden (backward + pastors)');
            self::kerktijden_scripts();
            sermonsNL_kerktijden::get_remote_data_backward();
            sermonsNL_kerktijdenpastors::get_remote_data();
        }
        // kerkomroep: update the archive and check whether all items are present
        if(get_option('sermonsNL_kerkomroep_mountpoint')){
            self::log('update_daily', 'updating kerkoproep (all + url validation)');
            self::kerkomroep_scripts();
            sermonsNL_kerkomroep::get_remote_data();
            sermonsNL_kerkomroep::validate_remote_urls();
        }
        // youtube: update the entire archive, don't search for new items
        if(get_option('sermonsNL_youtube_channel')){
            self::log('update_daily', 'updating youtube (update all known records)');
            self::youtube_scripts();
            sermonsNL_youtube::get_remote_update_all();
        }
        // delete events that have become redundant
        $rec = self::get_complete_redundant_records();
        self::log('update_daily', 'removing redundant records (n='.count($rec).')');
        foreach($rec as $e){
            $event = sermonsNL_event::get_by_id($e->id);
            $event->delete();
        }
        self::log('update_daily', 'done');
        return true;
    }

    // handles updates of the available sources that need to be done frequently but not immediately
    public static function update_quarterly(){
        // kerktijden
        if(get_option('sermonsNL_kerktijden_id')){
            self::log('update_quarterly', 'updating kerktijden (forward)');
            self::kerktijden_scripts();
            sermonsNL_kerktijden::get_remote_data_forward();
        }
        // kerkomroep
        if(get_option('sermonsNL_kerkomroep_mountpoint')){
            self::log('update_quarterly','updating kerkomroep (all)');
            self::kerkomroep_scripts();
            sermonsNL_kerkomroep::get_remote_data();
        }
        // youtube: get recent ones. it will include new planned broadcasts, new live broadcases, and update recent items
        if(get_option('sermonsNL_youtube_channel')){
            self::log('update_quarterly','updating youtube (last 10)');
            self::youtube_scripts();
            sermonsNL_youtube::get_remote_data(10);
        }
        self::log('update_quarterly', 'done');
        return true;
    }
    
    // UPDATE FUNCTION CALLED BY THE SITE FUNCTIONS
    
    // only checks for live broadcasts. in case of youtube it only does so when a sermon is close to start
    private static function update_now(){
        $file_last_update_now_time = plugin_dir_path(__FILE__) . "last_update_now.time";
        if(file_exists($file_last_update_now_time)){
            $last_update_now_time = file_get_contents($file_last_update_now_time);
        }else{
            $last_update_now_time = 0;
        }
         // first check if the last check was >60 seconds ago. to avoid running out of quota for the youtube api.
        // perhaps the interval will be a setting later
        if(time() - $last_update_now_time >= 60){
            file_put_contents($file_last_update_now_time, time());
            if(get_option('sermonsNL_kerkomroep_mountpoint')){
                self::log('update_now', 'updating kerkomroep (most recent record)');
                self::kerkomroep_scripts();
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
                        self::youtube_scripts();
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
    }

    // SITE FUNCTIONS
    
    // shortcode function to list sermons
    public static function html_sermons_list(array $atts=[], ?string $content=null){
        // default attributes
		$atts = shortcode_atts( array(
			'offset' => null, /* date string for the first record to be included in the initial view, e.g. "now -14 days". At least one of the parameters offset or ending should be provided*/
			'ending' => null, /* date string for the last record to be included in the initial view, e.g. "now +14 days". */
			'count' => 10, /* The number of items to show. This is ignored if both parameters offset and ending are provided. */
			'more-buttons' => 1, /* whether to include the show-more buttons; set to 0 to remove these buttons */
			'datefmt' => 'long' /* either "long" (weekday and month written out) or "short" (abbreviated day and numeric month) or a valid date format for the php date function */
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
		$checkInterval = 30; /* check each x seconds with javascript query; this might become a setting later */
		$datefmt = esc_html((string)$atts['datefmt']);
		$morebuttons = (int)$atts['more-buttons'];

		if($atts['offset'] !== null && !$dt1) return __("Error: Parameter `offset` is an invalid date string in the shortcode.");
		if($atts['ending'] !== null && !$dt2) return __("Error: Parameter `ending` is an invalid date string in the shortcode.");
		if(!$dt1 && !$dt2) return __("Error: At least one of the parameters `offset` and/or `ending` should be provided in the shortcode.");
		if((!$count || $count <= 0) && (!$dt1 || !$dt2)) return __("Error: If one of the parameters `offset` or `ending` is not provided in the shortcode, parameter `count` should be a positive number.");
		
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
        $html = '<script type="text/javascript">
        sermonsnl.count = ' . $count . ';
		sermonsnl.datefmt = "' . $datefmt . '";
		sermonsnl.admin_url = "' . admin_url( 'admin-ajax.php') . '";
		sermonsnl.check_interval = ' . $checkInterval . ';
		</script>';
		
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
    			$html .= '<div><a href="javascript:;" id="sermonsnl_more_up" onclick="sermonsnl.showmore(\'up\');">' . __("Load earlier sermons") . '</a></div>';
		    }
		}

		$html .= '<ul id="sermonsnl_list">';
		
		$html .= self::html_list_items($data, $count, (string)$atts['datefmt']);
	
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
    			$html .= '<div><a href="javascript:;" id="sermonsnl_more_down" onclick="sermonsnl.showmore(\'down\');">' . __("Load later sermons") . '</a></div>';
		    }
		}
		
		// add logos
		$logos = array();
		if(($kt_id = get_option("sermonsnl_kerktijden_id"))) $logos["Kerktijden.nl"] = array("url" => "https://www.kerktijden.nl/gemeente/$kt_id/", "img" => "logo_kerktijden.svg");
		if(($ko_mp = get_option("sermonsnl_kerkomroep_mountpoint"))) $logos["Kerkomroep.nl"] = array("url" => "https://www.kerkomroep.nl/#/kerken/$ko_mp", "img" => "logo_kerkomroep.png");
		if(($yt_ch = get_option("sermonsnl_youtube_channel"))) $logos["YouTube.com"] = array("url" => "https://www.youtube.com/channel/$yt_ch", "img" => "logo_youtube.jpg");
		$html .= '<div class="sermonsnl-logos">';
		foreach($logos as $name => $link){
		    $html .= '<a href="' . $link["url"] . '" target="_blank" title="'.$name.'"><img src="' . plugin_dir_url(__FILE__) . 'img/' . $link['img'] . '" alt="' . $name . '"/></a>';
		}
		$html .= '<img src="' . plugin_dir_url(__FILE__) . 'img/logo_sermonsnl_' . (get_locale() == 'nl_NL' ? 'NL' : 'EN') . '.png" alt="Sermons-NL: ' . __("bring Dutch church services to your site") . '"/>';
		$html .= '</div>';

        return $html;
    }
    
	// ajax server handler to check current status of live broadcasts and new live broadcasts
	public static function check_status(){
	    
	    // tmp for testing
	    /*
	    global $wpdb;
	    $ran = rand(0,2);
	    $live = $planned = 0;
	    if($ran == 1) $live = 1;
	    if($ran == 2) $planned = 1;
	    $wpdb->query("UPDATE `wp_sermonsNL_youtube` SET `planned` = $planned, `live` = $live WHERE `wp_sermonsNL_youtube`.`id` = 1;");
	    */
	   
	    // end tmp
		ob_clean();
		
		// check new live broadcasts
		self::update_now();
		
		// get data
		$event_ids = array();
        if(!empty($_GET['live'])){
            foreach($_GET['live'] as $id_str){
                preg_match_all("/^sermonsnl_([a-z]+)_(audio_|video_|)([0-9]+)$/", $id_str, $matches);
                $type = $matches[1][0];
                $id = $matches[3][0];
                $item = self::get_item_by_type($type, $id);
                $event_ids[] = $item->event_id;
            }
        }
        $server_live = self::get_complete_records_by_live();
        foreach($server_live as $event){
            $event_ids[] = $event->id;
        }
        $events = self::get_complete_records_by_ids(array_unique($event_ids));
        
        // get html of all selected events
		$json = array(
		    'call' => "sermonsnl_checkstatus",
		    'count' => count($events),
		    'events' => array()
		);
		
		foreach($events as $event){
		    $event_obj = sermonsNL_event::get_by_id($event->id);
		    $json['events'][] = array(
		        'id' => 'sermonsnl_event_'.$event->id, 
		        'html' => self::html_event_links($event),
		        'audio_class' => 'sermonsnl-av' . ($event_obj->has_audio ? ' sermonsnl-audio' . ($event_obj->audio_live ? '-live' : '') : ''),
		        'video_class' => 'sermonsnl-av' . ($event_obj->has_video ? ' sermonsnl-video' . ($event_obj->video_live ? '-live' : ($event_obj->video_planned ? '-planned' : '')) : '')
		    );
		}
		print json_encode($json);
		wp_die();
	}

	// AJAX RESPONSE TO CLICK FOR MORE ACTION
	public static function show_more(){
		ob_clean();
		$json = array('call' => "sermonsnl_showmore");
		if(!isset($_GET['direction']) || !isset($_GET['count']) || !isset($_GET['current'])){
		    $json["error"] = "insufficient parameters provided";
		}else{
    		$datefmt = (empty($_GET['datefmt']) ? 'long' : (string)$_GET['datefmt']);
    		$direction = (string) $_GET['direction'];
    		$json['direction'] = $direction;
    		$count = (int) $_GET['count']; 
    		if(!($count > 0)) $count = 10;
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

    // html list items - function used by above site functions
	private static function html_list_items(array $data, int $count, string $datefmt){
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
			$html .= '<li id="sermonsnl_event_' . $event->id . '"' . (!empty($event->display_open) ? ' class="sermonsnl-open"' : '') . ' onclick="sermonsnl.toggledetails(this);">';
			$html .= '<span class="sermonsnl-av' . ($event->ko_audio_url ? ' sermonsnl-audio' . ($event->ko_live ? '-live' : '') : '') . '"></span>';
			$html .= '<span class="sermonsnl-av' . ($event->yt_video_id || $event->ko_video_url ? ' sermonsnl-video' . ($event->yt_live || ($event->ko_video_url && $event->ko_live) ? '-live' : ($event->yt_planned ? '-planned' : '')) : '') . '"></span>';
			$html .= '<span class="sermonsnl-dt">' . self::datefmt($datefmt, $event->dt_start) . ' </span><span class="sermonsnl-pastor">' . $event->pastor . ' </span><span class="sermonsnl-type">' . ($event->sermontype == "Reguliere dienst" ? "" : $event->sermontype) . '</span>';
			$html .= '<div class="sermonsnl-details"><div>';
			if(!($event->yt_video_id || $event->ko_id)){
				$html .= '<p>' . ($event->kt_cancelled ? __('This sermon has been cancelled.') : __('There are no broadcasts for this sermon (yet).')) . '</p>';
			}
			else{
				$html .= '<div class="sermonsnl-links">';
				$html .= self::html_event_links($event);
				$html .= '</div>';
    			$html .= '<div class="sermonsnl-description">' . nl2br(esc_html($event->description)) . '</div>';
			}
			$html .= '</div></div></li>';
		}
		return $html;
	}
	
	private static function html_event_links($event){
	    $html = '';
	    if($event->ko_audio_url){
			$html .= '<p id="sermonsnl_kerkomroep_audio_'.$event->ko_id.'" class="sermonsnl-audio' . ($event->ko_live ? '-live' : '') . '"><a id="ko_audio_'.$event->ko_id.'" href="' . $event->ko_audio_url . '" target="_blank" title="' . __("Kerkomroep audio broadcast","sermons-nl") . '" onclick="return !sermonsnl.playmedia(this, \'' . $event->ko_audio_mimetype . '\', \'ko-audio\');">Kerkomroep' . ($event->ko_live ? ' (live)' : '') . '</a></p>';
		}
		if($event->ko_video_url){
			$html .= '<p id="sermonsnl_kerkomroep_video_'.$event->ko_id.'" class="sermonsnl-video' . ($event->ko_live ? '-live' : '') . '"><a id="ko_video_'.$event->ko_id.'" href="' . $event->ko_video_url . '" target="_blank" title="' . __('Kerkomroep video broadcast','sermons-nl') . '" onclick="return !sermonsnl.playmedia(this, \'' . $event->ko_video_mimetype . '\', \'ko-video\');">Kerkomroep' . ($event->ko_live ? ' (live)' : '') . '</a></p>';
		}
		/* needs change for implementing kerkdienst gemist
		if($event->kg_audio_url){
			$html .= '<p class="audio' . ($event->kg_a['live'] ? ' is-live' : '') . '"><a' . ($event->kg_a['link'] ? ' href="' . $event->kg_a['link'] . '" target="_blank" id="kg_a_'.$dt.'" title="Luisteren via kerkdienstgemist" onclick="return !kerkdiensten.playmedia(this, \'' . $event->kg_a['mimetype'] . '\',\'kg-audio\');"' : '') . '>Kerkdienstgemist' . ($event->kg_a['live'] ? ($event->kg_a['link'] ? ' (live)' : ' (archiveren)') : '') . '</a></p>';
		}
		if($event->kg_video_url){
			$html .= '<p class="video' . ($event->kg_v['live'] ? ' is-live' : '') . '"><a' . ($event->kg_v['link'] ? ' href="' . $event->kg_v['link'] . '" target="_blank" id="kg_v_'.$dt.'" title="Kijken via kerkdienstgemist" onclick="return !kerkdiensten.playmedia(this, \'' . $event->kg_v['mimetype'] . '\',\'kg-video\');"' : '') . '>Kerkdienstgemist' . ($event->kg_v['live'] ? ($event->kg_v['link'] ? ' (live)' : ' (archiveren)') : '') . '</a></p>';
		}
		*/
		if($event->yt_id){
			$html .= '<p id="sermonsnl_youtube_'.$event->yt_id.'" class="sermonsnl-video' . ($event->yt_live ? '-live' : ($event->yt_planned ? '-planned' : '')) . '"><a id="yt_video_'.$event->yt_id.'" href="http://www.youtube.com/watch?v='.$event->yt_video_id.'" target="_blank" title="' . __("YouTube video broadcast","sermons-nl") . '" onclick="return !sermonsnl.playmedia(this, \'video/youtube\',\'yt-video\');">YouTube';
			if($event->yt_live) $html .= ' (live)';
			elseif($event->yt_planned) $html .= ' (gepland)';
			$html .= ' <a href="http://www.youtube.com/watch?v='.$event->yt_video_id.'" target="_blank" title="' . __("Open YouTube in a new window","sermons-nl") . '"><img src="' . plugin_dir_url(__FILE__) . 'img/icon_newwindow.png" style="height:15px;"/></a>'; // open in new window icon: https://commons.wikimedia.org/wiki/File:OOjs_UI_icon_newWindow-ltr.svg
			// $html .= '<br/><img src="'.$event->yt_thumb_url.'" class="yt_thumb" alt="Video miniatuur"/>'; // thumbnails currently not included in data model
			$html .= '</a></p>'; 
		}
        return $html;
	}
	
	// formats the date including the possible use of "long" and "short" as date formats. 
	// For "long" and "short" it will output days (and months) in the local language if available.
	// The function assumes that $datetime is in UTC (set at the end of this script) and will output the datetime in the local time zone (wp setting).
	private static function datefmt(string $fmt, string $datetime){
	    $time = new DateTime($datetime, self::$timezone_db);
	    $time->setTimeZone(wp_timezone());
		if($fmt == 'long'){
		    $day = __($time->format('l'), 'sermons-nl');
		    $month = __($time->format('F'), 'sermons-nl');
			return $day . $time->format(" j ") . ' ' . $month . ' ' . $time->format('Y H:i');
		}elseif($fmt == 'short'){
			$day = __($time->format('D'), 'sermons-nl');
			return $day . $time->format(" d-m-Y H:i");
		}else{
		    return $time->format($fmt);
		}
	}
    
    // shortcode function to list a single item (t.b.d.)
    public static function html_sermons_item(array $atts=[], ?string $content=null){
        
    }
    
    // adds js and css to the site
    // to do: remove rand part and set fixed version
    public static function add_site_scripts_and_styles(){
		wp_enqueue_style('sermonsnl-stylesheet', plugin_dir_url(__FILE__) . 'css/site.css', array(), '1.'.rand(10000,99999));
		wp_enqueue_script('sermonsnl-javascript', plugin_dir_url(__FILE__) . 'js/site.js', array('jquery'), '1.'.rand(10000,99999));
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
        self::kerktijden_scripts();
        $sql = sermonsNL_kerktijden::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        $sql = sermonsNL_kerktijdenpastors::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        
        // create table for kerkomroep broadcasts
        self::kerkomroep_scripts();
        $sql = sermonsNL_kerkomroep::query_create_table($prefix, $charset_collate);
        dbDelta($sql);
        
        // create table for youtube broadcasts
        self::youtube_scripts();
        $sql = sermonsNL_youtube::query_create_table($prefix, $charset_collate);
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
                "youtube"
            ) as $surname){
            $table_name = $wpdb->prefix . "sermonsNL_" . $surname;
            $wpdb->query( "DROP TABLE IF EXISTS $table_name" );
        }

        // delete plugin options
        foreach(self::OPTION_NAMES as $opt_name => $args){
            delete_option($opt_name);
        }
    }
    
    // RECORD ACTIVITIES RELATED TO LOADING REMOTE DATA
    public static function log(string $fun, string $str){
		$fname = plugin_dir_path( __FILE__ ) . 'log.log';
		$c = array();
		if(is_readable($fname)){
			$c = file($fname);
			$c = array_slice($c, -999); // leave a max of 1000 records
		}
		$log = (new DateTime("now", wp_timezone()))->format("Y-m-d H:i:s") . " $fun: $str\n";
		$c[count($c)] = $log;
		file_put_contents($fname, implode($c));
		return $log;
	}
    
}

sermonsNL::$timezone_db = new DateTimeZone("UTC");
sermonsNL::$timezone_ko = sermonsNL::$timezone_kt = new DateTimeZone("Europe/Amsterdam");

require_once(plugin_dir_path(__FILE__) . 'event.php');

// ACTIVATION, DEACTIVATION AND UNINSTALL HOOKS
register_activation_hook(__FILE__, array('sermonsNL', 'activate_plugin'));
register_deactivation_hook(__FILE__, array('sermonsNL', 'deactivate_plugin'));
register_uninstall_hook(__FILE__, array('sermonsNL', 'uninstall_plugin'));

// STUFF TO GET THE CRON JOBS DONE (UPDATING FROM THE SOURCES)
add_filter('cron_schedules', array('sermonsNL','add_cron_interval'));
add_action('sermonsNL_cron_quarterly', array('sermonsNL','update_quarterly'));
add_action('sermonsNL_cron_daily', array('sermonsNL','update_daily'));

// STUFF FOR WP-ADMIN
add_action('admin_init', array('sermonsNL','register_settings'));
add_action('admin_menu', array('sermonsNL','add_admin_menu'));
add_action('admin_enqueue_scripts', array('sermonsNL','add_admin_scripts_and_styles'));
add_action('admin_head', array("sermonsNL","add_admin_custom_script"));
add_action('update_option_sermonsNL_kerktijden_id', array('sermonsNL', 'kerktijden_change_id'), 10, 2);
add_action('update_option_sermonsNL_kerkomroep_mountpoint', array('sermonsNL', 'kerkomroep_change_mountpoint'), 10, 2);
add_action('update_option_sermonsNL_youtube_channel', array('sermonsNL', 'youtube_change_channel'), 10, 2);

add_action('wp_ajax_sermonsnl_admin_navigate_table', array('sermonsNL','admin_navigate_table'));
add_action('wp_ajax_sermonsnl_admin_show_details', array('sermonsNL','admin_show_details'));
add_action('wp_ajax_sermonsnl_admin_link_item_to_event', array('sermonsNL','link_item_to_event'));
add_action('wp_ajax_sermonsnl_admin_unlink_item', array('sermonsNL','unlink_item'));
add_action('wp_ajax_sermonsnl_admin_delete_event', array('sermonsNL','delete_event'));

// STUFF FOR SHOWING CONTENT ON THE WEBSITE
// html, js and css
add_shortcode('sermons-nl-list', array('sermonsNL', 'html_sermons_list'));
add_shortcode('sermons-nl-event', array('sermonsNL', 'html_sermons_event'));
add_shortcode('sermons-nl-item', array('sermonsNL', 'html_sermons_item'));
add_action('wp_enqueue_scripts', array('sermonsNL','add_site_scripts_and_styles'));
// ajax: show more button
add_action('wp_ajax_sermonsnl_showmore', array('sermonsNL','show_more'));
add_action('wp_ajax_nopriv_sermonsnl_showmore', array('sermonsNL','show_more'));
// ajax: check status
add_action('wp_ajax_sermonsnl_checkstatus', array('sermonsNL','check_status'));
add_action('wp_ajax_nopriv_sermonsnl_checkstatus', array('sermonsNL','check_status'));


?>
