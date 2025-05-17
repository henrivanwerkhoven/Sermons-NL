<?php

if(!defined('ABSPATH')) exit; // Exit if accessed directly

class sermonsNL_event{
    	// EVENT FUNCTIONS
	
	private static $events = null;
	
    private $data = null;
    
    public function __construct(stdClass $object){
        $this->data = get_object_vars($object);
    }
    
    public function __get(string $key){
        // to do: add get_by_event_id to the other classes
        if(array_key_exists($key, $this->data)) return $this->data[$key];
        switch($key){
            case 'variables': return array_keys($this->data);
            case 'kerktijden': return sermonsNL_kerktijden::get_by_event_id($this->id);
            case 'kerkomroep': return sermonsNL_kerkomroep::get_by_event_id($this->id);
            case 'youtube': return sermonsNL_youtube::get_by_event_id($this->id);
            case 'items' : return array(
                    'kerktijden' => $this->kerktijden,
                    'kerkomroep' => $this->kerkomroep,
                    'youtube' => $this->youtube
                );
            case 'dt': 
            case 'dt_start':
                switch($this->data['dt_from']){
                    case 'manual': return $this->data['dt_manual'];
                    case 'kerktijden': return ($this->kerktijden ? $this->kerktijden->dt : null);
                    case 'kerkomroep': return ($this->kerkomroep ? $this->kerkomroep->dt : null);
                    case 'youtube': return ($this->youtube ? ($this->youtube->dt_planned ? $this->youtube->dt_planned : $this->youtube->dt_actual) : null);
                    default: 
                        if($this->kerktijden) return $this->kerktijden->dt;
                        if($this->youtube && $this->youtube->dt_planned) return $this->youtube->dt_planned;
                        if($this->kerkomroep) return $this->kerkomroep->dt;
                        if($this->youtube) return $this->youtube->dt_actual;
                        return $this->dt_min; // fallback
                }
            case 'pastor':
                if($this->pastor_from=='manual') return $this->pastor_manual;
                if($this->pastor_from=='kerktijden' && $this->kerktijden) return $this->kerktijden->pastor;
                if($this->pastor_from=='kerkomroep' && $this->kerkomroep) return $this->kerkomroep->pastor;
                if($this->kerktijden) return $this->kerktijden->pastor;
                if($this->kerkomroep) return $this->kerkomroep->pastor;
                return '';
            case 'sermontype':
                if($this->sermontype_from=='manual') return $this->sermontype_manual;
                if($this->kerktijden) return $this->kerktijden->sermontype;
                return '';
            case 'description':
                if($this->description_from=='manual') return $this->description_manual;
                if($this->description_from=='youtube' && $this->youtube) return $this->youtube->description;
                if($this->description_from=='kerkomroep' && $this->kerkomroep) return $this->kerkomroep.description;
                if($this->youtube) return $this->youtube->description;
                if($this->kerkomroep) return $this->kerkomroep->description;
                return '';
            case 'has_audio':
                return ($this->kerkomroep && $this->kerkomroep->audio_url);
            case 'live':
                return ($this->audio_live || $this->video_live);
            case 'audio_live':
                return ($this->kerkomroep && $this->kerkomroep->audio_url && $this->kerkomroep->live);
            case 'has_video':
                return ($this->kerkomroep && $this->kerkomroep->video_url) || ($this->youtube);
            case 'video_planned':
                return ($this->youtube && $this->youtube->planned) && !($this->kerkomroep && $this->kerkomroep->video_url && $this->kerkomroep->live);
            case 'video_live':
                return ($this->kerkomroep && $this->kerkomroep->video_url && $this->kerkomroep->live) || ($this->youtube && $this->youtube->live);
            case 'ko_id':
                if(!$this->kerkomroep) return null;
                return $this->kerkomroep->id;
            case 'yt_video_id':
                if(!$this->youtube) return null;
                return $this->youtube->video_id;
            default: return null;
        }
    }
    
    public function update(array $data){
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
                wp_trigger_error("sermonsNL::update", "Trying to update non-existing key `$key` in object of sermonsNL.", E_USER_WARNING);
            }
        }
        if($update){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update($wpdb->prefix.'sermonsNL_events', $data, array('id' => $this->id));
            return true;
        }
        return false;
    }
    
    public function update_dt_min_max(string $dt_min, string $dt_max){
        // note that the function doesn't try whether dt_min should become higher or dt_max become lower.
        $dt_min = (null === $this->dt_min ? $dt_min : min($dt_min, $this->dt_min));
        $dt_max = (null === $this->dt_max ? $dt_max : max($dt_max, $this->dt_max));
        $this->update(array('dt_min'=>$dt_min, 'dt_max'=>$dt_max));
    }
    
    public function delete(){
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($wpdb->prefix.'sermonsNL_events', array('id' => $this->id));
        unset(self::$events[$this->id]);
    }
    
    public function get_all_items(){
        $ret = array();
        $kt = sermonsNL_kerktijden::get_all_by_event_id($this->id);
        if(!empty($kt)) $ret['kerktijden'] = $kt;
        $ko = sermonsNL_kerkomroep::get_all_by_event_id($this->id);
        if(!empty($ko)) $ret['kerkomroep'] = $ko;
        $yt = sermonsNL_youtube::get_all_by_event_id($this->id);
        if(!empty($yt)) $ret['youtube'] = $yt;
        return $ret;
    }

	public static function get_all(){
	    if(self::$events === null){
	        self::$events = array();
    	    global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermonsNL_events ORDER BY dt_min", OBJECT_K);
	        foreach($data as $key => $object){
	            self::$events[$key] = new self($object);
	        }
	    }
	    return self::$events;
	}

	public static function get_by_id(int $id){
	    $events = self::get_all();
	    if(!isset($events[$id])){
	        global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $record = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sermonsNL_events where id=%d", $id), OBJECT_K);
	        if(empty($record)){
	            return null;
	        }
	        self::$events[$id] = new self($record[$id]);
	    }
	    return self::$events[$id];
	}
	
	public static function get_by_dt(string $dt, ?string $dt2=null, bool $include_all=false){
	    global $wpdb;
	    if(null === $dt2) $dt2 = $dt;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $data = $wpdb->get_results($wpdb->prepare("SELECT id FROM {$wpdb->prefix}sermonsNL_events WHERE dt_min<=%s AND dt_max>=%s", $dt2, $dt), ARRAY_A);
	    if(empty($data)) return null;
	    if($include_all){
	        $ret = array();
	        foreach($data as $row){
	            $ret[] = self::get_by_id($row['id']);
	        }
	        return $ret;
	    }
	    return self::get_by_id($data[0]['id']);
	} 
	
	public static function add_record(string $dt, ?string $dt2=null){
	    global $wpdb;
	    if($dt2 === null) $dt2 = $dt;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->insert($wpdb->prefix.'sermonsNL_events', array('dt_min'=>$dt, 'dt_max'=>$dt2));
	    if($ok){
	        return self::get_by_id($wpdb->insert_id);
	    }
	    return null;
	}
	
	// SQL for creating database table
	public static function query_create_table($prefix, $charset_collate){
        return "CREATE TABLE {$prefix}sermonsNL_events (
        id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        dt_from enum('auto','manual','kerktijden','kerkomroep','youtube') DEFAULT 'auto' NOT NULL,
        dt_manual datetime NULL,
        dt_min datetime NULL,
        dt_max datetime NULL,
        pastor_from enum('auto','manual','kerktijden','kerkomroep') DEFAULT 'auto' NOT NULL,
        pastor_manual varchar(255) NULL,
        sermontype_from enum('auto','manual', 'kerktijden') DEFAULT 'auto' NOT NULL,
        sermontype_manual varchar(255) NULL,
        description_from enum('auto','manual','kerkomroep','youtube') DEFAULT 'auto' NOT NULL,
        description_manual varchar(65535) NULL,
        include tinyint(1) DEFAULT 1 NOT NULL,
        protected TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY  (id)
        ) $charset_collate;";
    }

}

?>
