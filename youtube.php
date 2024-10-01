<?php
class sermonsNL_youtube{
    
    // DATA OBJECT METHODS
    
    private static $items = null;
    private static $items_by_event = array();
    private static $items_by_video_id = array();
    public static $timezone = null;
    public static $utc = null;
    
    private $data = null;
    
    public function __construct($object){
        $this->data = get_object_vars($object);
    }
    
    public function __get($key){
        if($key == 'dt'){
            return (!empty($this->data['dt_planned']) ? $this->data['dt_planned'] : $this->data['dt_actual']);
        }
        if($key == 'event'){
            if($this->data['event_id'] === null) return null;
            return sermonsNL_event::get_by_id($this->data['event_id']);
        }
        if(array_key_exists($key, $this->data)) return $this->data[$key];
        return null;
    }
    
    public function __set($key, $value){
        if(array_key_exists($key, $this->data)) $this->update(array($key => $value));
        else wp_trigger_warning(__CLASS__."::__set", "Trying to set non-existing key `$key` in object of class ".__CLASS__, E_USER_WARNING);
    }
    
    public function update($data){
        global $wpdb;
        $update = false;
        foreach($data as $key => $value){
            if(array_key_exists($key, $this->data)){
                if($this->$key != $value){
                    $update = true;
                    $this->data[$key] = $value;
                }
            }
            else{
                unset($data[$key]);
                wp_trigger_error(__CLASS__."::update", "Trying to update non-existing key `$key` in object of ".__CLASS__, E_USER_WARNING);
            }
        }
        if($update){
            $wpdb->update($wpdb->prefix.'sermonsNL_youtube', $data, array('id' => $this->id));
            return true;
        }
        return false;
    }
    
    public function delete(){
        global $wpdb;
        $wpdb->delete($wpdb->prefix.'sermonsNL_youtube', array('id' => $this->id));
        unset(self::$items[$this->id]);
    }
	
	public static function get_all(){
	    if(self::$items === null){
	        self::$items = array();
    	    global $wpdb;
	        $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermonsNL_youtube ORDER BY dt_actual,dt_planned", OBJECT_K);
	        foreach($data as $id => $object){
	            self::$items[$id] = new self($object);
	            self::$items_by_video_id[$object->video_id] = self::$items[$id];
	            if($object->event_id) self::$items_by_event[$object->event_id] = self::$items[$id];
	        }
	    }
	    return self::$items;
	}
	
	public static function get_by_id($id){
	    $items = self::get_all();
	    if(!isset($items[$id])){
	        global $wpdb;
	        $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermonsNL_youtube where id=$id", OBJECT_K);
	        if(empty($data)){
	            return null;
	        }
	        $object = $data[$id];
            self::$items[$id] = new self($object);
            self::$items_by_video_id[$object->video_id] = self::$items[$id];
            if($object->event_id) self::$items_by_event[$object->event_id] = self::$items[$id];
	    }
	    return self::$items[$id];
	}
	
	public static function get_by_video_id($video_id){
	  $items = self::get_all();
	  if(isset(self::$items_by_video_id[$video_id])) return self::$items_by_video_id[$video_id];
	  return null;
	}
	
	public static function get_by_event_id($event_id){
	    $items = self::get_all();
	    if(isset(self::$items_by_event[$event_id])) return self::$items_by_event[$event_id];
	    return null;
	}
	
	public static function get_all_by_event_id(int $event_id){
	    global $wpdb;
    	$data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermonsNL_youtube WHERE event_id=$event_id");
    	$ret = array();
    	foreach($data as $row){
    	    $ret[] = self::get_by_id($row->id);
    	}
    	return $ret;
	}
	
	public static function get_live(){
	    global $wpdb;
	    $data = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}sermonsNL_youtube where live=1", ARRAY_A);
	    if(empty($data)){
	        return null;
	    }
	    return self::get_by_id($data[0]['id']);
	}
	
	public static function get_planned(bool $include_all = false){
	    global $wpdb;
	    $data = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}sermonsNL_youtube where planned=1", ARRAY_A);
	    if($include_all){
	        $ret = array();
	        foreach($data as $row){
	            $ret[] = self::get_by_id($row['id']);
	        }
	        return $ret;
	    }
	    if(empty($data)){
	        return null;
	    }
	    return self::get_by_id($data[0]['id']);
	}

	public static function add_record($data){
	    global $wpdb;
	    $ok = $wpdb->insert($wpdb->prefix.'sermonsNL_youtube', $data);
	    if($ok){
	        return self::get_by_id($wpdb->insert_id);
	    }
	    return null;
	}
	
	public static function query_create_table($prefix, $charset_collate){
	    global $wpdb;
	    return "CREATE TABLE {$wpdb->prefix}sermonsNL_youtube (
        id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id int(10) UNSIGNED NULL,
        video_id char(11) DEFAULT '' NOT NULL,
        dt_planned datetime NULL,
        dt_actual datetime NULL,
        dt_end datetime NULL,
        title varchar(255) DEFAULT '' NOT NULL,
        description text DEFAULT '' NOT NULL,
        planned tinyint(1) DEFAULT 0 NOT NULL,
        live tinyint(1) DEFAULT 0 NOT NULL,
        PRIMARY KEY  (id)
        ) $charset_collate;";
	}
	
	public static function change_channel($old_value, $value){
        if($old_value != $value){
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sermonsNL_youtube");
            if(!empty($value)){
                // load the entire archive
                self::get_remote_data();
            }
        }
    }

    // METHODS TO LOAD NEW DATA FROM youtube.com
    
    public static function get_remote_data(int $n_records=null){
        $data = self::get_remote_search($n_records === null ? INF : $n_records);
        // logging
        $ok = self::compare_remote_to_local_data($data);
        if($ok) return $data;
    }
    
    public static function get_remote_update(array $video_ids){
        $data = array();
        foreach($video_ids as $video_id){
            $data[count($data)] = array('video_id' => $video_id);
        }
        $newdata = array();
        for($i=0; $i<count($video_ids); $i+=50){
            $subset = array_slice($data, $i, min(50, count($video_ids)-$i));
            self::get_remote_details($subset);
            $newdata = array_merge($newdata, $subset);
        }
        $ok = self::compare_remote_to_local_data($newdata, true);
        if($ok) return $newdata;
        // to do: delete items that are not found. if you search for it and it is not found we may assume it is not there anymore.
        // logging: how many updated / deleted
    }
    
    public static function get_remote_update_all(){
        $data = self::get_all();
        $video_ids = array_map(function($item){return $item->video_id;}, $data);
        if(!empty($video_ids)){
            return self::get_remote_update($video_ids);
        }else{
            // logging: nothing to update
        }
    }
    
    private static function compare_remote_to_local_data(array $remote_data, bool $delete_if_dt_missing=false){
	    $local_data = self::get_all();
	    $_new = 0;
	    $_upd = 0;
	    $_del = 0;
	    $_evt = array();
	    // loop through remote data array
	    foreach($remote_data as $remote_item){
	        $local_item = self::get_by_video_id($remote_item['video_id']);
	        if(!$local_item){
	            // if no (planned) start date, skip
	            if(empty($remote_item['dt_planned']) && empty($remote_item['dt_actual'])) continue; 
	            // create new
	            $local_item = self::add_record($remote_item);
	            $_new ++;
	            // identify event for this item
	            $dt = $local_item->dt;
	            $dt2 = $local_item->dt_end;
	            if(!$dt2) $dt2 = $dt;
	            $event = sermonsNL_event::get_by_dt($dt, $dt2);
	            if(!$event){
	                $event = sermonsNL_event::add_record($dt, $dt2);
	            }else{
	                $event->update_dt_min_max($dt, $dt2);
	            }
	            $_evt[] = $event->id;
	            $local_item->update(array('event_id'=>$event->id));
	        }else{
	            // if no (planned) start date AND $delete_if_dt_missing = true AND video not accessible: delete
	            if(empty($remote_item['dt_planned']) && empty($remote_item['dt_actual'])){
	                if($delete_if_dt_missing){
	                    $local_item->delete();
	                    $_del ++;
	                }
	            }else{
	                // it contains (planned) start date, so it is safe to be updated
	                $ch = $local_item->update($remote_item);
	                if($ch) $_upd ++;
	                $dt = $local_item->dt;
	                $dt2 = $local_item->dt_end;
	                if($local_item->event) $local_item->event->update_dt_min_max($dt, $dt2);
	            }
	        }
        }
        sermonsNL::log("YouTube:remote_to_local", "$_new new items (events #" . implode(",", $_evt) . "), $_upd updated, and $_del deleted.");
        return true;
	}
    
    
    // to retrieve data through the youtube api
	private static function get_remote_search($n_records, $nextPageToken=NULL){
		$max_records = 50;
		$yt_key = get_option('sermonsNL_youtube_key');
		$yt_channel = get_option('sermonsNL_youtube_channel');
		$data = array();
		
		//$url = "https://www.googleapis.com/youtube/v3/search?key=$yt_key&channelId=$yt_channel&part=snippet,id&order=date&maxResults=" . min($n_records,$max_records) . (empty($nextPageToken) ? "" : "&pageToken=$nextPageToken");
        // https://stackoverflow.com/questions/18953499/youtube-api-to-fetch-all-videos-on-a-channel/27872244#27872244
        // playlist associated with youtube channel is simply replacing 'UC' with 'UU' as first characters.
        $playlist = 'UU' . substr($yt_channel, 2); 
        $url = "https://www.googleapis.com/youtube/v3/playlistItems?part=snippet&maxResults=" . min($n_records,$max_records) . "&playlistId=$playlist&key=$yt_key" . (empty($nextPageToken) ? "" : "&pageToken=$nextPageToken");

		$api_str = file_get_contents($url);
		if(empty($api_str)){
		    sermonsNL::log(__CLASS__."::get_remote_data", "Error obtaining data from youtube api: empty response", E_USER_WARNING);
		    return $data;
		}
		$api_data = json_decode($api_str, true);
		if(empty($api_data['items'])){
		    sermonsNL::log(__CLASS__."::get_remote_data", "The youtube api did not provide a valid json response");
		    return $data;
		}
		foreach($api_data['items'] as $item){
		    // note that the search api uses 'id' whereas the playlistItems api uses 'resourceId'
		    $vid = $item['snippet']['resourceId']['videoId'];
			if(empty($vid)) continue; // not a video?
			$data[count($data)] = array(
				'video_id' => $vid//,
				//'title' => $item['snippet']['title'], // I can retrieve this through the update anyway
				//'description' => $item['snippet']['description'], // this is limited in length
				//'thumb_url' => $item['snippet']['thumbnails']['medium']['url'], // not needed
				//'thumb_width' => $item['snippet']['thumbnails']['medium']['width'],
				//'thumb_height' => $item['snippet']['thumbnails']['medium']['height']
			);
		}

		// get more details, particularly (planned) stream time
		if(!empty($data)){
    		self::get_remote_details($data);
		}

		// check if next page needs to be loaded
		if($n_records > $max_records && !empty($api_data['nextPageToken'])){
			// call next page request
			$next_page_data = self::get_remote_search(is_finite($n_records) ? $n_records - $max_records : INF, $api_data['nextPageToken']);
			if(!empty($next_page_data)) $data = array_merge($data, $next_page_data);
		}
		
		return $data;
	}

	private static function get_remote_details(&$data){
		// https://developers.google.com/youtube/v3/docs/videos/list
		$yt_key = get_option('sermonsNL_youtube_key');
        $vids = array_map(function($x){return $x['video_id'];}, $data);
		$url = "https://www.googleapis.com/youtube/v3/videos?part=liveStreamingDetails,snippet&id=".implode(',',$vids)."&key=".$yt_key;
		$api_str = file_get_contents($url);
		if(empty($api_str)){
		    wp_trigger_error(__CLASS__."::get_remote_details", "Error obtaining data from youtube api", E_USER_WARNING);
		    return false;
		}
		$api_details = json_decode($api_str,true);
		if(empty($api_details['items'])){
		    wp_trigger_error(__CLASS__."::get_remote_details", "The youtube api did not provide a valid json response", E_USER_WARNING);
		    return false;
		} 
        foreach($api_details['items'] as $item){
			$vid = $item['id'];
			$i = array_search($vid, $vids);
			if($i === false){ // should not happen
			    wp_trigger_error(__CLASS__."::get_remote_details", "Getting data on a video that wasn't asked for from the youtube api", E_USER_WARNING);
			    continue; 
			}
			
			$sd = $item['liveStreamingDetails'];

			// youtube includes the timezone ('Z') in the data, so no timezone indicator is needed
			$dt_actual = (empty($sd['actualStartTime']) ? null : new DateTime($sd['actualStartTime'])); 
			if($dt_actual) $dt_actual = $dt_actual->setTimezone(sermonsNL::$timezone_db)->format("Y-m-d H:i:s");
			
			$dt_planned = (empty($sd['scheduledStartTime']) ? null : new DateTime($sd['scheduledStartTime']));
			if($dt_planned) $dt_planned = $dt_planned->setTimezone(sermonsNL::$timezone_db)->format("Y-m-d H:i:s"); 
			
			$planned = ($item['snippet']['liveBroadcastContent'] == 'upcoming' ? 1 : 0);
			$live = ($item['snippet']['liveBroadcastContent'] == 'live' ? 1 : 0);

			if(!empty($sd['actualEndTime'])){
			    $dt_end = new DateTime($sd['actualEndTime']);
			    if($dt_end) $dt_end = $dt_end->setTimezone(sermonsNL::$timezone_db)->format("Y-m-d H:i:s");
			}else{
    			$dt_end = ($live ? (new DateTime("now", sermonsNL::$timezone_db))->format("Y-m-d H:i:s") : null);
			} 
			$data[$i]['dt_actual'] = $dt_actual;
			$data[$i]['dt_planned'] = $dt_planned;
			$data[$i]['dt_end'] = $dt_end;
			$data[$i]['title'] = $item['snippet']['title'];
			$data[$i]['description'] = $item['snippet']['description'];
			$data[$i]['planned'] = $planned;
			$data[$i]['live'] = $live;
		}
		return true;
	}

}
?>