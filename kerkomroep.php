<?php

if(!defined('ABSPATH')) exit; // Exit if accessed directly

class sermonsNL_kerkomroep{
    
    // DATA OBJECT METHODS
    
    private static $items = null;
    private static $items_by_event = array();

    private $data = null;
    
    public function __construct($object){
        $this->data = get_object_vars($object);
    }
    
    public function __get($key){
        switch($key){
            case  'dt_end':
                $dt = new DateTime(sprintf('%s +%d seconds', $this->dt, $this->duration), SermonsNL::$timezone_db);
                return $dt->format('Y-m-d H:i:s');
            case 'event':
                if($this->data['event_id'] === null) return null;
                return sermonsNL_event::get_by_id($this->data['event_id']);
            case 'type':
                return 'kerkomroep';
        }
        if(array_key_exists($key, $this->data)) return $this->data[$key];
        return null;
    }
    
    public function __set($key, $value){
        if(array_key_exists($key, $this->data)) $this->update(array($key => $value));
        else wp_trigger_warning("sermonsNL_kerkomroep::__set", "Trying to set non-existing key `$key` in object of class sermonsNL_kerkomroep.", E_USER_WARNING);
    }
    
    public function update($data){
        global $wpdb;
        $update = false;
        foreach($data as $key => $value){
            if($key == 'id') continue;
            if(array_key_exists($key, $this->data)){
                if($this->$key != $value){
                    $update = true;
                    $this->data[$key] = $value;
                }
            }else{
                unset($data[$key]);
                wp_trigger_error("sermonsNL_kerkomroep::update", "Trying to update non-existing key `$key` in object of sermonsNL_kerkomroep.", E_USER_WARNING);
            }
        }
        if($update){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update($wpdb->prefix.'sermonsNL_kerkomroep', $data, array('id' => $this->id));
            return true;
        }
        return false;
    }
    
    public function delete(){
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($wpdb->prefix.'sermonsNL_kerkomroep', array('id' => $this->id));
        unset(self::$items[$this->id]);
    }
	
	public static function get_all(){
	    if(self::$items === null){
	        self::$items = array();
    	    global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermonsNL_kerkomroep ORDER BY dt", OBJECT_K);
	        foreach($data as $id => $object){
	            self::$items[$id] = new self($object);
	            if($object->event_id) self::$items_by_event[$object->event_id] = self::$items[$id];
	        }
	    }
	    return self::$items;
	}
	
	public static function get_by_id($id){
	    $items = self::get_all();
	    if(!isset($items[$id])){
	        global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sermonsNL_kerkomroep where id=%d",$id), OBJECT_K);
	        if(empty($data)){
	            return null;
	        }
	        $object = $data[$id];
            self::$items[$id] = new self($object);
            if($object->event_id) self::$items_by_event[$object->event_id] = self::$items[$id];
	    }
	    return self::$items[$id];
	}
	
	public static function get_by_event_id($event_id){
	    $items = self::get_all();
	    if(isset(self::$items_by_event[$event_id])) return self::$items_by_event[$event_id];
	    return null;
	}
	
	public static function get_all_by_event_id(int $event_id){
	    global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sermonsNL_kerkomroep WHERE event_id=%d",$event_id));
    	$ret = array();
    	foreach($data as $row){
    	    $ret[] = self::get_by_id($row->id);
    	}
    	return $ret;
	}
	
	public static function get_live(){
	    global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $data = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}sermonsNL_kerkomroep where live=1", ARRAY_A);
	    if(empty($data)){
	        return null;
	    }
	    return self::get_by_id($data[0]['id']);
	}

	public static function add_record($data){
	    global $wpdb;
        // use replace because sometimes two parallel processes want to save the new item at the same time
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->replace($wpdb->prefix.'sermonsNL_kerkomroep', $data);
        if($ok){
	        return self::get_by_id($wpdb->insert_id);
	    }
	    return null;
	}
	
	private function validate_remote_urls(){
        if($this->live) return true; // do not check here whether still live
        $audio_url_valid = ($this->audio_url && preg_match('/^HTTP\/1\.(0|1) (2|3)/', @get_headers($this->audio_url)[0]));
        $video_url_valid = ($this->video_url && preg_match('/^HTTP\/1\.(0|1) (2|3)/', @get_headers($this->video_url)[0]));
        return ($audio_url_valid || $video_url_valid);
    }

    public static function query_create_table($prefix, $charset_collate){
	    return "CREATE TABLE {$prefix}sermonsNL_kerkomroep (
        id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id int(10) UNSIGNED NULL,
        dt datetime NULL,
        duration smallint(5) UNSIGNED DEFAULT NULL,
        pastor varchar(255) NULL,
        theme varchar(255) NULL,
        scripture varchar(255) NULL,
        description text(65535) NOT NULL,
        audio_url varchar(255) NULL,
        audio_mimetype varchar(255) NULL,
        video_url varchar(255) NULL,
        video_mimetype varchar(255) NULL,
        live tinyint(1) DEFAULT 0 NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY dt  (dt)
        ) $charset_collate;";
	}

    // METHODS TO LOAD NEW DATA FROM kerktijden.nl
    
    public static function post_request($command, $args=array()){
        $host = "www.kerkomroep.nl";
		$port = 443;
		$protocol = 'ssl://';

        /* load data from the kerktijden api using native php functions for writing to and reading from an ssl socket */
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fsockopen
        $fp = fsockopen($protocol . $host, $port, $errno, $errstr, 30);
		if(!$fp){
	        sermonsNL::log("sermonsNL_kerkomroep::get_remote_data", "Error: could not establish connection with $host: (#$errno): $errstr");
    		return false;
		}
		
		$postdata = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n" . 
			"<ko><request><command>$command</command><arguments>";
        foreach($args as $name => $value){
            $postdata .= "<argument><name>$name</name><value>$value</value></argument>";
        }
        $postdata .= "</arguments></request></ko>";
		$out = "POST /xml/index.php HTTP/1.1\r\n" . 
			"Host: " . $host . "\r\n" .
			"Accept: application/xml;charset=utf-8\r\n" . 
			"Content-Type: application/xml; charset=utf-8\r\n" .
			"Content-Length: " . strlen($postdata) . "\r\n" .
			"Connection: Close\r\n" .
			"\r\n" .
			$postdata;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputs
		fputs($fp, $out);

        // read status
        $line = fgets($fp);
        if(strpos($line, '200') === false){
            sermonsNL::log("sermonsNL_kerkomroep::get_remote_data","Status error while loading kerkomroep data: $line");
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($fp);
		    return false;
        }
		// read headers
		$headers = array();
		while(!feof($fp)){
		    $line = fgets($fp, 128);
		    if(empty(trim($line))) break;
		    $colon = strpos($line, ':');
		    $hname = strtolower(substr($line, 0, $colon));
		    $hvalue = trim(substr($line, $colon+1));
			$headers[$hname] = $hvalue;
		}
		$chunked = (isset($headers['transfer-encoding']) && $headers['transfer-encoding'] == "chunked");

		// read content
		$content = "";
		if($chunked){
			$chunksize = false;
		    while(!feof($fp)){
		        if($chunksize === false){
		            $line = fgets($fp, 128);
		            $chunksize = hexdec(trim($line));
		        }elseif($chunksize > 0){
		            $readsize = min(512, $chunksize);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		            $content .= fread($fp, $readsize);
		            $chunksize -= $readsize;
		            if($chunksize <= 0){
		                fgets($fp, 3); // skip newline at end of chunk
		                $chunksize = false;
		            }
		        }else{
		            break;
		        }
		    }
		}else{
		    while(!feof($fp)){
		        $line = fgets($fp);
		        if($line !== false) $content .= $line;
		    }
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose($fp);
        return $content;
    }

	// this recourse is not needed (yet);  I keep it in case ever needed.
	// It includes a.o. the church name and other data, including whether a live broadcast is ongoing.
	// Slightly faster than get_remote_data(true) but difference quite negligible.
	/*
    public static function get_is_live(){
        $mp = get_option("sermonsNL_kerkomroep_mountpoint");

        $content = self::post_request("getkerkinfo", array("mountpoint" => $mp,  "type" => "1"));
        if(!$content) return false;

        $obj = simplexml_load_string($content, null, LIBXML_NOCDATA);

		return $obj;
    }
    */

    public static function get_remote_data($check_live_only=false){
        $mp = get_option("sermonsNL_kerkomroep_mountpoint");
        
        $content= self::post_request("getstreams", array("command" => "getstreams", "target" => "uitzendingen.uitzending", "mountpoint" => $mp, "isArray" => "true"));
        if(!$content) return false;

        $obj = simplexml_load_string($content, null, LIBXML_NOCDATA);
        if(empty($obj->response)){
            sermonsNL::log("sermonsNL_kerkomroep::get_remote_data", "XML->response expected but received something else.");
            return false;
        }
        $data = $obj->response->uitzendingen->uitzending;
        if($check_live_only){
            return self::compare_live_broadcast($data[0]);
        }else{
            // if first remote item is not live but we still have a live broadcast locally, we need to fix that
            // (it the remote is live, compare_remote_to_local_data will call this function)
            if(!$data[0]->is_live && self::get_live()) self::compare_live_broadcast($data[0]);
            // now compare all remote items to the local items
            return self::compare_remote_to_local_data($data, true);
        }
    }

    
    public static function compare_live_broadcast($remote_item){
        $item = self::get_live(); // live item from database
        if((int)$remote_item->is_live){
            // currently broadcasting
            $now = new DateTime('now', sermonsNL::$timezone_db);
            if($item !== null){
                // live broadcast already existing in database, update item
                $item_dt = new DateTime($item->dt, sermonsNL::$timezone_db);
                $item->duration = $now->getTimestamp() - $item_dt->getTimestamp(); // seconds
                if($item->event) $item->event->update_dt_min_max($item->dt, $item->dt_end);
            }else{
                // create new live broadcasting item
                $new_data = array(
                    'dt' => $now->format("Y-m-d H:i:s"),
                    'duration' => 0,
                    'audio_url' => (empty($remote_item->audio_url) ? null : (string)$remote_item->audio_url), 
                    'audio_mimetype' => (empty($remote_item->audio_mimetype) ? null : (string)$remote_item->audio_mimetype), 
                    'video_url' => (empty($remote_item->video_url) ? null : (string)$remote_item->video_url), 
                    'video_mimetype' => (empty($remote_item->video_mimetype) ? null : (string)$remote_item->video_mimetype), 
                    'live' => 1
                );
                $item = self::add_record($new_data);
                // allow linking of the live event, even if the broadcasting starts one hour ahead of the scheduled time, or if the plugin detects it up to 30 minutes later
                $event = sermonsNL_event::get_by_dt(
                    (clone $now)->sub(new DateInterval('PT30M'))->format("Y-m-d H:i:s"),
                    (clone $now)->add(new DateInterval('PT60M'))->format("Y-m-d H:i:s")
                );
                if(null === $event){
                    $event = sermonsNL_event::add_record($item->dt, $item->dt_end);
                }else{
                    // dt_min and dt_max of the event may need update
                    $event->update_dt_min_max($item->dt, $item->dt_end);
                }
                // attach the kerkomroep item to the event
                $item->update(array('event_id' => $event->id));
                sermonsNL::log("sermonsNL_kerkomroep::compare_live_broadcast","New live broadcasting item added.");
            }
        }else{
            if($item !== null){
                // live broadcast is no longer available remotely but still exists locally. Delete it. The broadcast will later be added from the archive.
                sermonsNL::log("sermonsNL_kerkomroep::compare_live_broadcast","No longer broadcasting; item deleted.");
                $item->delete();
            }
            // check if $remote_item already exists; this could be the (yet unsaved) broadcasted item
            self::compare_remote_to_local_data(array($remote_item), false);
        }
    }
    
    private static function compare_remote_to_local_data($remote_data, $delete_if_not_exists=true){
        // protect the local data from getting an empty record (maybe connection error)
        if(empty($remote_data)) return false;
	    $local_data = self::get_all();
        $found_items = array();
	    // loop through remote data array
	    $prev_item = null;
	    $DI15m = new DateInterval("PT15M");
        foreach($remote_data as $remote_item){
            if((int)$remote_item->is_live){
                self:: compare_live_broadcast($remote_item);
                continue;
            }
            $dt = new DateTime($remote_item->datum . ' ' . $remote_item->tijd, sermonsNL::$timezone_ko);
            $dt->setTimeZone(sermonsNL::$timezone_db);
            $duration = (int)$remote_item->tijdsduur; // duration in seconds
            global $dt_range; // $dt_range is used for matching with local data
            $dt_range = array(
                $dt->format("Y-m-d H:i:s"),
                (clone $dt)->add(new DateInterval("PT{$duration}S"))->format("Y-m-d H:i:s")
            );
            $new_data = array(
                'dt' => $dt->format("Y-m-d H:i:s"),
                'duration' => $duration,
                'pastor' => (string)$remote_item->voorganger, 
                'theme' => (string)$remote_item->thema,
                'scripture' => (string)$remote_item->schriftlezing,
                'description' => (string)$remote_item->samenvatting, 
                'audio_url' => (empty($remote_item->audio_url) ? null : (string)$remote_item->audio_url), 
                'audio_mimetype' => (empty($remote_item->audio_mimetype) ? null : (string)$remote_item->audio_mimetype), 
                'video_url' => (empty($remote_item->video_url) ? null : (string)$remote_item->video_url), 
                'video_mimetype' => (empty($remote_item->video_mimetype) ? null : (string)$remote_item->video_mimetype), 
                'live' => 0
            );
            // NB: an item of kerkomroep can have dt + duration to be later than dt of the next item
            // the archive always has most recent at the top (so $prev_item is later!)
            if($prev_item && strtotime($prev_item->dt) <= strtotime($new_data['dt']) + $new_data['duration']){
                // reduce duration to avoid overlap -- otherwise next updates will create chaos
                $new_data['duration'] = strtotime($prev_item->dt) - strtotime($new_data['dt']) - 1;
            }

            // we have a match if the remote start time ($dt_range[0]) is smaller then the items end time and the remote end time ($dt_range[1]) is larger than the items start time.
            // This is because the broadcast can be trimmed at both ends later.
            // $i is the index of $local_data that matches the $new_data time slot, or false if there is no match
            $i = array_search(true, array_map(function($item){ global $dt_range; return ($dt_range[1] >= $item->dt && $dt_range[0] <= $item->dt_end); }, $local_data));
            if(false === $i){
                // create new record
                $item = self::add_record($new_data);
                // check for existing events with matching / overlapping dt
                // take some margin because the broadcast may have started earlier or be detected later
                $dt1 = (new DateTime($item->dt, sermonsNL::$timezone_db))->sub($DI15m)->format("Y-m-d H:i:s"); // margin for delayed start
                $dt2 = (new DateTime($item->dt_end, sermonsNL::$timezone_db))->format("Y-m-d H:i:s"); // from the archive, a margin for early start is not needed
                $event = sermonsNL_event::get_by_dt($dt1, $dt2);
                if(null === $event){
                   $event = sermonsNL_event::add_record($item->dt, $item->dt_end);
                }else{
                    // dt_min and dt_max of the event may need update
                    $event->update_dt_min_max($item->dt, $item->dt_end);
                }
                // attach the event to the kerkomroep item
                $item->update(array('event_id' => $event->id));
                sermonsNL::log("sermonsNL_kerkomroep::compare_remote_to_local_data","New item {$item->dt} created.");
            }
            else{
                // update record (the update function will check if an update is needed)
                $item = $local_data[$i];
                $updated = $item->update($new_data);
                if($updated && $item->event){
                    // dt_min and dt_max of the event may need update
                    $item->event->update_dt_min_max($item->dt, $item->dt_end);
                }
                if($updated) sermonsNL::log("sermonsNL_kerkomroep::compare_remote_to_local_data","Existing item {$item->dt} updated.");
                // record which items are found
                $found_items[] = $i;
            }
            $prev_item = $item;
        }
        // delete (if audio/video urls also not available) the items that were not found in the archive
        if($delete_if_not_exists){
            $not_found = array_diff(array_keys($local_data), $found_items);
            sermonsNL::log("sermonsNL_kerkomroep::compare_remote_to_local_data","Found: ".print_r($found_items,true)."; Local: " . print_r(array_keys($local_data),true) . "; Not found: " . print_r($not_found,true));
            foreach($not_found as $i){
                $item = $local_data[$i];
                if(!$item->validate_remote_urls()){
                    sermonsNL::log("sermonsNL_kerkomroep::compare_remote_to_local_data","Item {$item->dt} no longer exists; item deleted.");
                    $item->delete();
                }else{
                    sermonsNL::log("sermonsNL_kerkomroep::compare_remote_to_local_data","Item {$item->dt} not in archive but url is valid; item retained.");
                }
            }
        }
        // done
        return true;
	}
    
}
?>
