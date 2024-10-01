<?php

class sermonsNL_kerktijden{
    
    // DATA OBJECT METHODS
    
    private static $items = null;
    private static $items_by_event = array();

    private $data = null;

    public function __construct($object){
        $this->data = get_object_vars($object);
    }
    
    public function __get($key){
        if(array_key_exists($key, $this->data)) return $this->data[$key];
        if($key == 'pastor'){
            if($this->pastor_id){
                return sermonsNL_kerktijdenpastors::get_by_id($this->pastor_id)->pastor;
            }
        }
        if($key == 'town'){
            if($this->pastor_id){
                return sermonsNL_kerktijdenpastors::get_by_id($this->pastor_id)->town;
            }
        }
        if($key == 'variables'){
            return array_keys($this->data);
        }
        if($key == 'dt_end'){
            // there is only a start time. Fallback to dt for generic use of the object
            return $this->data['dt'];
        }
        return null;
    }
    
    public function __set($key, $value){
        if(array_key_exists($key, $this->data)) $this->update(array($key => $value));
        else wp_trigger_warning("sermonsNL_kerktijden::__set", "Trying to set non-existing key `$key` in object of class sermonsNL_kerktijden.", E_USER_WARNING);
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
            }elseif($key == 'pastor'){
                if(!empty($data['pastor_id'])){
                    sermonsNL_kerktijdenpastors::add_if_not_exists(array('id'=>$data['pastor_id'], 'pastor'=>$data['pastor']));
                }
                unset($data[$key]);
            }else{
                unset($data[$key]);
                wp_trigger_error("sermonsNL_kerkomroep::update", "Trying to update non-existing key `$key` in object of sermonsNL_kerkomroep.", E_USER_WARNING);
            }
        }
        if($update){
            $wpdb->update($wpdb->prefix.'sermonsNL_kerktijden', $data, array('id' => $this->id));
            return true;
        }
        return false;
    }
    
    public function delete(){
        global $wpdb;
        $wpdb->delete($wpdb->prefix.'sermonsNL_kerktijden', array('id' => $this->id));
        unset(self::$items[$this->id]);
    }
	
	public static function get_all(){
	    if(self::$items === null){
	        self::$items = array();
    	    global $wpdb;
	        $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermonsNL_kerktijden ORDER BY dt", OBJECT_K);
	        foreach($data as $id => $object){
	            self::$items[$id] = new self($object);
	            if($object->event_id) self::$items_by_event[$object->event_id] = self::$items[$id];
	        }
	    }
	    return self::$items;
	}
	
	public static function get_by_id($id){
	    $kt = self::get_all();
	    if(!isset($kt[$id])){
	        global $wpdb;
	        $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermonsNL_kerktijden where id=$id", OBJECT_K);
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
    	$data = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}sermonsNL_kerktijden WHERE event_id=$event_id");
    	$ret = array();
    	foreach($data as $row){
    	    $ret[] = self::get_by_id($row->id);
    	}
    	return $ret;
	}

	public static function add_record($data){
	    global $wpdb;
	    // the pastor name is saved separately
	    if(!empty($data['pastor_id'])){
    	    sermonsNL_kerktijdenpastors::add_if_not_exists(array('id'=>$data['pastor_id'], 'pastor'=>$data['pastor']));
	    }
	    unset($data['pastor']);
	    $ok = $wpdb->insert($wpdb->prefix.'sermonsNL_kerktijden', $data);
	    if($ok){
	        return self::get_by_id($wpdb->insert_id);
	    }
	    return null;
	}
	
	public static function query_create_table($prefix, $charset_collate){
	    global $wpdb;
	    return "CREATE TABLE {$wpdb->prefix}sermonsNL_kerktijden (
        id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id int(10) UNSIGNED NULL,
        dt datetime DEFAULT '1970-01-01 01:00:00' NOT NULL,
        sermontype varchar(255) DEFAULT '' NOT NULL,
        pastor_id int(10) UNSIGNED NULL,
        cancelled tinyint(1) UNSIGNED DEFAULT 0 NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY dt (dt)
        ) $charset_collate;";
	}

    public static function change_id($old_value, $value){
        if($old_value != $value){
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}sermonsNL_kerktijden");
            if(!empty($value)){
                self::get_remote_data_forward();
                self::get_remote_data_backward();
            }
        }
    }

    // METHODS TO LOAD NEW DATA FROM kerktijden.nl
    
	public static function get_remote_data_forward(){
	    $kt_id = get_option('sermonsNL_kerktijden_id');
	    $weeks = get_option('sermonsNL_kerktijden_weeksahead');
		$url = "https://api.kerktijden.nl/api/gathering/GetGatheringsForWidget?communityId=" . $kt_id . "&weeks=" . $weeks;
		$data = self::get_remote_data($url);
		return self::compare_remote_to_local_data($data);
	}

	public static function get_remote_data_backward(){
	    $kt_id = get_option('sermonsNL_kerktijden_id');
	    $weeks = get_option('sermonsNL_kerktijden_weeksback');
	    $months = ceil($weeks / 13 * 3);
		$data = array();
		for($m=0; $m<=$months; $m++){
			$month = date('Y-m-d', strtotime("first day of -$m month"));
			$url = "https://api.kerktijden.nl/api/gathering/GetGatherings?communityId=" . $kt_id . "&month=" . $month;
			$data_m = self::get_remote_data($url);
			if(!empty($data_m)) $data = array_merge($data_m, $data);
		}
		return self::compare_remote_to_local_data($data);
	}

	public static function get_remote_data($url){
		$api_str = file_get_contents($url);
		$data = array();
		if(!empty($api_str)){
			$api_data = json_decode($api_str, true);
			// if empty there are no sermons in that month
			if(!empty($api_data)){
				foreach($api_data as $item){
				    $dt = new DateTime($item['startTime'], sermonsNL::$timezone_kt);
				    $dt->setTimeZone(sermonsNL::$timezone_db); // save in UTC
					$data[count($data)] = array(
						'dt' => $dt->format("Y-m-d H:i:s"),
						'sermontype' => $item['gatheringTypes'][0]['name'],
						'pastor' => (!empty($item['persons'][0]) ? sermonsNL_kerktijdenpastors::extract_pastor($item['persons'][0]) : NULL),
						'pastor_id' => (empty($item['persons'][0]['id']) ? NULL : $item['persons'][0]['id']),
						'cancelled' => $item['cancelled']
					);
				}
			}
		}
		return $data;
	}
	
	public static function compare_remote_to_local_data($remote_data){
	    $local_data = self::get_all();
	    // fill this with date range of obtained data and remember all the timestamps
	    $date_min = date("%Y-%m-%d");
	    $date_max = "1970-01-01";
	    $dt_list = array();
	    // loop through remote data array
        foreach($remote_data as $row){
            $i = array_search($row['dt'], array_map(function($x){ return $x->dt; }, $local_data));
            if(false === $i){
                // it is new, check for existing events
                $event = sermonsNL_event::get_by_dt($row['dt']);
                if(null === $event){
                   $event = sermonsNL_event::add_record($row['dt']);
                }
                // create a new record
                $row['event_id'] = $event->id;
                self::add_record($row);
            }
            else{
                // update record (the update function will check if an update is needed)
                $local_data[$i]->update($row);
            }
            $date = substr($row['dt'], 0, 10);
            $date_min = min($date_min, $date);
            $date_max = max($date_max, $date);
            $dt_list[count($dt_list)] = $row['dt'];
        }
        return true;
	}

}

class sermonsNL_kerktijdenpastors{
    
    private static $items = null;
    
    private $data = null;
    
    public function __construct($object){
        $this->data = get_object_vars($object);
    }
    
    public function __get($key){
        if(array_key_exists($key, $this->data)) return $this->data[$key];
        return null;
    }
    
    public function __set($key, $value){
        if(array_key_exists($key, $this->data)) $this->data[$key] = $value;
        else wp_trigger_warning("sermonsNL_kerktijdenpastors::__set", "Trying to set non-existing key `$key` in object of class sermonsNL_kerktijden.", E_USER_WARNING);
    }

    public function update($data){
        if($data['pastor'] != $this->pastor || $data['town'] != $this->town){
            global $wpdb;
            // change pastor or town
            $wpdb->update($wpdb->prefix.'sermonsNL_kerktijdenpastors', $data, array('id' => $this->id));
            $this->pastor = $data['pastor'];
    	    $this->town = $data['town'];
        }
    }
	
	public static function get_all(){
	    if(self::$items === null){
	        self::$items = array();
    	    global $wpdb;
	        $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermonsNL_kerktijdenpastors", OBJECT_K);
	        foreach($data as $id => $object){
	            self::$items[$id] = new self($object);
	        }
	    }
	    return self::$items;
	}
	
	public static function get_by_id($id){
	    $pastors = self::get_all();
	    if(!isset($pastors[$id])){
	        global $wpdb;
	        $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermonsNL_kerktijdenpastors where id=$id", OBJECT_K);
	        if(empty($data)){
	            return null;
	        }
	        $object = $data[$id];
            self::$items[$id] = new self($object);
	    }
	    return self::$items[$id];
	}
	
	public static function add_if_not_exists($data){
	    $pastor = self::get_by_id($data['id']);
    	if(!$pastor){
    	    self::add_record($data);
    	}
	}
	
	public static function add_record($data){
	    global $wpdb;
	    $ok = $wpdb->insert($wpdb->prefix."sermonsNL_kerktijdenpastors", $data);
	    if($ok){
	        return self::get_by_id($data['id']);
	    }
	    return null;
	}
	
	public static function query_create_table($prefix, $charset_collate){
	    global $wpdb;
	    return "CREATE TABLE {$wpdb->prefix}sermonsNL_kerktijdenpastors (
        id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        pastor varchar(255) default '' NOT NULL,
        town varchar(255) default '' NOT NULL,
        PRIMARY KEY  (id)
        ) $charset_collate;";
	}
	
	// METHOD TO VERIFY ALL PASTOR DATA (to be run regularly, i.e. with the daily update)
	public static function get_remote_data(){
	    $kt_id = get_option('sermonsNL_kerktijden_id');
	    // delete pastors that are not linked to a sermon
	    global $wpdb;
	    $data = $wpdb->get_results("SELECT p.id FROM {$wpdb->prefix}sermonsNL_kerktijdenpastors AS p LEFT JOIN {$wpdb->prefix}sermonsNL_kerktijden AS k ON k.pastor_id = p.id WHERE k.pastor_id IS NULL;", ARRAY_A);
	    foreach($data as $row){
	        $wpdb->delete($wpdb->prefix . "sermonsNL_kerktijdenpastors", array('id' => $row['id']));
	        // in case self::$items is alraedy defined, this avoid an attemt to next try to update a non-existing record
	        unset(self::$items[$row['id']]); 
	    }
	    // update remaining pastors
	    $pastors = self::get_all();
	    foreach($pastors as $id => $pastor){
    		$url = "https://api.kerktijden.nl/api/person/getperson?id=". (int)$id;
    		$api_str = file_get_contents($url);
    		if(!empty($api_str)){
    			$api_data = json_decode($api_str, true);
    			if(!empty($api_data)){
    				$new_data = array(
    				    'pastor' => self::extract_pastor($api_data),
    				    'town' => self::extract_town($api_data, $kt_id)
    				);
    	        	$pastor->update($new_data);
    			}
			}
		}
	}

    // these functions pull pastor name and town, respectively, from the api data
	public static function extract_pastor($d){
	    if(empty($d['lastname']) | $d['lastname'] == 'system') return NULL;
	    return $d['status']['statusShort'] . ' ' . $d['initials'] . (empty($d['insertion']) ? '' : ' ' . $d['insertion']) . ' ' . $d['lastname'];
	}
	
	public static function extract_town($d, $kt_id){
	    if(!empty($d['community']['id'])){
	        // only record the town if the pastor is not linked to the our own community ($kt_id)
	        if($d['community']['id'] != $kt_id & $d['community']['name'] != 'Test Gemeente') return $d['community']['name'];
	    }
	    else{
    	    if(!empty($d['location']['town'])) return $d['location']['town'];
	    }
	    return NULL;
	}
	
}

?>