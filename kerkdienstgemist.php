<?php

if(!defined('ABSPATH')) exit; // Exit if accessed directly

class sermons_nl_kerkdienstgemist{

    // DATA OBJECT METHODS

    private static $items = null;
    private static $items_by_event = array();
    private static $items_by_remote_id = array();
    private static $items_by_media_id = array();

    private $data = null;

    public function __construct($object){
        $this->data = get_object_vars($object);
        if($this->event_id) self::$items_by_event[$this->event_id] = $this;
        if($this->remote_id) self::$items_by_remote_id[$this->remote_id] = $this;
        if($this->audio_id) self::$items_by_media_id[$this->audio_id] = $this;
        if($this->video_id) self::$items_by_media_id[$this->video_id] = $this;
    }

    public function __get($key){
        switch($key){
            case 'event':
                if($this->data['event_id'] === null) return null;
                return sermons_nl_event::get_by_id($this->data['event_id']);
            case 'type':
                return 'kerkdienstgemist';
        }
        if(array_key_exists($key, $this->data)) return $this->data[$key];
        return null;
    }

    public function __set($key, $value){
        if(array_key_exists($key, $this->data)) $this->update(array($key => $value));
        else wp_die("In sermons_nl_kerkomroep::__set: Trying to set non-existing key `".esc_html($key)."`.", "An error occurred");
    }

    public function update($data){
        global $wpdb;
        $update = false;
        foreach($data as $key => $value){
            if($key == 'id'){
                unset($data[$key]);
            }elseif(array_key_exists($key, $this->data)){
                if($this->$key != $value){
                    if($key == 'event_id'){
                        if($this->$key != 0) unset(self::$items_by_event[$this->$key]);
                        if($value != 0) self::$items_by_event[$value] = $this;
                    }elseif($key == 'remote_id'){
                        if($this->$key != 0) unset(self::$items_by_remote_id[$this->$key]);
                        if($value != 0) self::$items_by_remote_id[$value] = $this;
                    }elseif($key == 'audio_id' || $key == 'video_id'){
                        if($this->$key != 0) unset(self::$items_by_media_id[$this->$key]);
                        if($value != 0) self::$items_by_media_id[$value] = $this;
                    }
                    $update = true;
                    $this->data[$key] = $value;
                }
            }else{
                unset($data[$key]);
                wp_die("In sermons_nl_kerkdienstgemist::update: Trying to update non-existing key `".esc_html($key)."`.", "An error occurred");
            }
        }
        if($update){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update($wpdb->prefix.'sermons_nl_kerkdienstgemist', $data, array('id' => $this->id));
            return true;
        }
        return false;
    }

    public function delete(){
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($wpdb->prefix.'sermons_nl_kerkdienstgemist', array('id' => $this->id));
        if($this->event_id && isset(self::$items_by_event[$this->event_id])) unset(self::$items_by_event[$this->event_id]);
        if($this->remote_id && isset(self::$items_by_remote_id[$this->remote_id])) unset(self::$items_by_remote_id[$this->remote_id]);
        if($this->audio_id && isset(self::$items_by_media_id[$this->audio_id])) unset(self::$items_by_media_id[$this->audio_id]);
        if($this->video_id && isset(self::$items_by_media_id[$this->video_id])) unset(self::$items_by_media_id[$this->video_id]);
        unset(self::$items[$this->id]);
    }

    public static function get_all(){
        if(self::$items === null){
            self::$items = array();
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermons_nl_kerkdienstgemist ORDER BY dt", OBJECT_K);
            foreach($data as $id => $object){
                self::$items[$id] = new self($object);
            }
        }
        return self::$items;
    }

    public static function get_by_id($id){
        $items = self::get_all();
        if(!isset($items[$id])){
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sermons_nl_kerkdienstgemist where id=%d",$id), OBJECT_K);
            if(empty($data)){
                return null;
            }
            self::$items[$id] = new self($data[$id]);
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
        $data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sermons_nl_kerkdienstgemist WHERE event_id=%d",$event_id));
        $ret = array();
        foreach($data as $row){
            if(!isset(self::$items[$row->id])) self::$items[$row->id] = new self($row);
            $ret[] = self::$items[$row->id];
        }
        return $ret;
    }

    public static function get_live(){
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $data = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}sermons_nl_kerkdienstgemist WHERE audio_live=1 OR video_live=1");
        if(!empty($data)){
            $id = $data[0]->id;
            if(!isset(self::$items[$id])) self::$items[$id] = new self($data[0]);
            return self::$items[$id];
        }
        return null;
    }

    public static function get_by_remote_id(int $remote_id){
        self::get_all();
        if(isset(self::$items_by_remote_id[$remote_id])) return self::$items_by_remote_id[$remote_id];
        return null;
    }

    public static function get_by_audio_or_video_id(?int $audio_id, ?int $video_id){
        self::get_all();
        if($audio_id !== null && isset(self::$items_by_media_id[$audio_id])) return self::$items_by_media_id[$audio_id];
        if($video_id !== null && isset(self::$items_by_media_id[$video_id])) return self::$items_by_media_id[$video_id];
        return null;
    }

    public static function get_by_dt(string $dt, ?string $dt_end=null){
        if($dt_end === null) $dt_end = $dt;
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $data = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}sermons_nl_kerkdienstgemist WHERE dt <= %s AND dt_end >= %s",$dt_end, $dt));
        if(!empty($data)){
            $id = $data[0]->id;
            if(!isset(self::$items[$id])) self::$items[$id] = new self($data[0]);
            return self::$items[$id];
        }
        return null;
    }

    public static function add_record($data, $format=null){
        global $wpdb;
        // use replace because sometimes two parallel processes want to save the new item at the same time
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->insert($wpdb->prefix.'sermons_nl_kerkdienstgemist', $data, $format);
        if($ok){
            // force a select query to add all the relationships
            return self::get_by_id($wpdb->insert_id);
        }
        sermons_nl::log("sermons_nl_kerkdienstgemist::add_record", "MySQL Error: " . $wpdb->last_error);
        wp_die("In sermons_nl_kerkdienstgemist::add_record: ". esc_html($wpdb->last_error), "An error occurred");
        return null;
    }

    // METHODS TO LOAD NEW DATA FROM kerkdienstgemist.nl

    private static $remote_headers = array(
        "Accept" => "application/vnd.api+json",
        // hardcoded, it seems to be a constant at least if one is not logged in. It seems unrelated to the session cookie.
        "Authorization" => "Bearer eyJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJrZGdtIiwiYXVkIjoia2RnbTphbm9ueW1vdXMiLCJpYXQiOjE1OTcyMzY5NTcsImp0aSI6ImIzYzIzZWY0OGIxZDc4ZTg3ZWFkNTMyZjg1MWI2MmY1In0.K0eQrCdMN3HDr-ytHECPs3jHDpgfz5IPM2bhJrgbezQ"
    );

    public static function get_remote_data($multi_page=false){
        $kg_id = get_option('sermons_nl_kerkdienstgemist_id');
        $nothing_found = true;
        $next_page = "https://api.kerkdienstgemist.nl/api/v2/stations/{$kg_id}/recordings?include=media&page=1&size=20";
        do{
            $response = wp_remote_get($next_page, array("headers" => self::$remote_headers));
            $api_str = wp_remote_retrieve_body($response);
            if(empty($api_str)){
                sermons_nl::log("sermons_nl_kerkdienstgemist::get_remote_data", "Error: empty response.");
                return false;
            }
            $api_data = json_decode($api_str);
            $next_page = $api_data->links->next;
            $medias = array();
            foreach($api_data->included as $media){
                // note that if $media->attributes->private equals true, then $media->attributes->sources is empty
                foreach($media->attributes->sources as $source){
                    if($source->type == 'mp3' || $source->type == 'mp4'){
                        $medias[$media->id] = array(
                            "type" => str_replace("_files","",$media->type),
                            "url" => $source->file,
                            "mimetype" => $media->attributes->content_type
                        );
                        break;
                    }
                }
            }
            foreach($api_data->data as $remote_item){
                $video_id = $video_url = $video_mimetype = $audio_id = $audio_url = $audio_mimetype = null;
                if($remote_item->attributes->{'private'}) continue;
                foreach($remote_item->relationships->media->data as $media){
                    if(isset($medias[$media->id])){
                        if($media->type == 'video_files'){
                            $video_id = $media->id;
                            $video_url = $medias[$media->id]['url'];
                            $video_mimetype = $medias[$media->id]['mimetype'];
                        }elseif($media->type == 'audio_files'){
                            $audio_id = $media->id;
                            $audio_url = $medias[$media->id]['url'];
                            $audio_mimetype = $medias[$media->id]['mimetype'];
                        }
                    }
                }
                $dt = self::remote_dt_to_local($remote_item->attributes->start_at);
                $dt_end = self::remote_dt_to_local($remote_item->attributes->end_at);
                $item_data = array(
                    "remote_id" => $remote_item->id,
                    "dt" => $dt,
                    "dt_end" => $dt_end,
                    "title" => $remote_item->attributes->title,
                    "description" => wp_strip_all_tags($remote_item->attributes->description),
                    "pastor" => $remote_item->attributes->artist,
                    "audio_id" => $audio_id,
                    "audio_url" => $audio_url,
                    "audio_mimetype" => $audio_mimetype,
                    "audio_live" => 0,
                    "video_id" => $video_id,
                    "video_url" => $video_url,
                    "video_mimetype" => $video_mimetype,
                    "video_live" => 0
                );
                self::remote_match_and_save($item_data);
                $nothing_found = false;
            }
        }while($multi_page && $next_page);

        if($nothing_found){
            // log this and end function to avoid deleting existing records
            sermons_nl::log("sermons_nl_kerkdienstgemist::get_remote_data", "Archives received from Kerkdienstgemist are empty.");
            return false;
        }

        // delete the items that are not existing anymore
        // only if all pages are loaded
        if($multi_page){

        }

        // done
        return true;
    }

    public static function get_remote_planned_live(){
        $kg_id = get_option('sermons_nl_kerkdienstgemist_id');
        $page = "https://api.kerkdienstgemist.nl/api/v2/stations/{$kg_id}?include=streams,events";
        $response = wp_remote_get($page, array("headers" => self::$remote_headers));
        $api_str = wp_remote_retrieve_body($response);
        if(empty($api_str)){
            sermons_nl::log("sermons_nl_kerkdienstgemist::get_remote_planned_live", "Error: empty response.");
            return false;
        }
        $api_data = json_decode($api_str);
        $has_audio_stream = $has_video_stream = $audio_live = $video_live = false;
        $remote_events = array();
        $now = (new DateTime('now',sermons_nl::$timezone_db))->format("Y-m-d H:i:s");
        foreach($api_data->included as $obj){
            if($obj->type == 'audio_streams'){
                $has_audio_stream = true;
                $audio_live = ($obj->attributes->source->status == 'online');
                if($audio_live){
                    $audio_live_data = array(
                        'id' => (int)$obj->id,
                        'dt' => self::remote_dt_to_local($obj->attributes->source->connected_at),
                        'dt_end' => $now,
                        'contenttype' => $obj->attributes->content_type,
                        'url' => $obj->attributes->source->mp3
                    );
                }
            }elseif($obj->type == 'video_streams'){
                $has_video_stream = true;
                $video_live = ($obj->attributes->source->status == 'online');
                if($video_live){
                    $video_live_data = array(
                        'id' => (int)$obj->id,
                        'dt' => self::remote_dt_to_local($obj->attributes->source->connected_at),
                        'dt_end' => $now,
                        'contenttype' => $obj->attributes->content_type,
                        'url' => $obj->attributes->source->hls # hls or rtmp
                    );
                }
            }elseif($obj->type == 'events'){
                $remote_events[count($remote_events)] = array(
                    'remote_id' => (int)$obj->id,
                    'dt' => self::remote_dt_to_local($obj->attributes->start_at),
                    'dt_end' => self::remote_dt_to_local($obj->attributes->end_at),
                    'title' => $obj->attributes->title,
                    'description' => wp_strip_all_tags($obj->attributes->description),
                    'pastor' => $obj->attributes->artist,
                    'audio_id' => null,
                    'audio_live' => 0,
                    'video_id' => null,
                    'video_live' => 0
                );
            }
        }
        // kerkdienstgemist api has a quirk that if the church has a video stream, it also has an audio stream, even if they don't use it
        if($has_video_stream){
            $audio_video_ratio = self::get_audio_video_ratio();
            if($audio_video_ratio < .5){
                // mostly not audio broadcasting
                $has_audio_stream = false;
            }
        }
        // assign live audio to right event or create new one
        if($audio_live){
            $assigned = false;
            foreach($remote_events as $i => $e){
                $dt1 = self::dt_sub($e['dt'], (int)get_option('sermons_nl_kerkdienstgemist_min_delay'));
                $dt2 = self::dt_add($e['dt_end'], (int)get_option('sermons_nl_kerkdienstgemist_min_ahead'));
                if($dt1 <= $audio_live_data['dt_end'] && $dt2 >= $audio_live_data['dt']){
                    $remote_events[$i]['audio_id'] = $audio_live_data['id'];
                    $remote_events[$i]['audio_url'] = $audio_live_data['url'];
                    $remote_events[$i]['audio_mimetype'] = $audio_live_data['contenttype'];
                    $remote_events[$i]['audio_live'] = true;
                    $assigned = true;
                    break;
                }
            }
            if(!$assigned){
                $remote_events[count($remote_events)] = array(
                    'dt' => $audio_live_data['dt'],
                    'dt_end' => $audio_live_data['dt_end'],
                    'audio_id' => $audio_live_data['id'],
                    'audio_url' => $audio_live_data['url'],
                    'audio_mimetype' => $audio_live_data['contenttype'],
                    'audio_live' => true,
                    'video_id' => null,
                    'video_live' => false
                );
            }
        }
        // assign live video to the right event or create new one
        if($video_live){
            $assigned = false;
            foreach($remote_events as $i => $e){
                $dt1 = self::dt_sub($e['dt'], (int)get_option('sermons_nl_kerkdienstgemist_min_delay'));
                $dt2 = self::dt_add($e['dt_end'], (int)get_option('sermons_nl_kerkdienstgemist_min_ahead'));
                if($dt1 <= $video_live_data['dt_end'] && $dt2 >= $video_live_data['dt']){
                    $remote_events[$i]['video_id'] = $video_live_data['id'];
                    $remote_events[$i]['video_url'] = $video_live_data['url'];
                    $remote_events[$i]['video_mimetype'] = $video_live_data['contenttype'];
                    $remote_events[$i]['video_live'] = true;
                    $assigned = true;
                    break;
                }
            }
            if(!$assigned){
                $remote_events[count($remote_events)] = array(
                    'dt' => $video_live_data['dt'],
                    'dt_end' => $video_live_data['dt_end'],
                    'video_id' => $video_live_data['id'],
                    'video_url' => $video_live_data['url'],
                    'video_mimetype' => $video_live_data['contenttype'],
                    'video_live' => true,
                    'audio_id' => null,
                    'audio_live' => false
                );
            }
        }
        // set non-live events at planned if stream type exists - only if the item is not broadcasting at all
        foreach($remote_events as $i => $e){
            if(!$e['audio_live'] && !$e['video_live']){
                if($has_audio_stream) $remote_events[$i]['audio_planned'] = true;
                if($has_video_stream) $remote_events[$i]['video_planned'] = true;
            }
        }
        // save event data
        foreach($remote_events as $item_data){
            self::remote_match_and_save($item_data);
        }
        // If not live broadcasting, check if any local item is live; if so,
        // set local item to not live, remove url and obtain archive
        if(!$audio_live && !$video_live){
            $live_item = self::get_live();
            if(null !== $live_item){
                $live_item->audio_live = 0;
                $live_item->audio_url = null;
                $live_item->video_live = 0;
                $live_item->video_url = null;
                self::get_remote_data();
            }
        }

    }

    private static function remote_match_and_save($item_data){
        // check if already exists by remote_id
        $local_item = (!empty($item_data['remote_id']) ? self::get_by_remote_id($item_data['remote_id']) : null);
        if(!$local_item){
            // alternatively by audio or video id
            $local_item = self::get_by_audio_or_video_id($item_data['audio_id'], $item_data['video_id']);
            if(!$local_item){
                // alternatively match by date time
                $dt1 = self::dt_sub($item_data['dt'], (int)get_option('sermons_nl_kerkdienstgemist_min_delay'));
                $dt2 = self::dt_add($item_data['dt_end'], (int)get_option('sermons_nl_kerkdienstgemist_min_ahead'));
                $local_item = self::get_by_dt($dt1, $dt2);
                // if local_item found but it already has an audio or video url, skip it and create a new one
                if(null !== $local_item && $local_item->audio_url || $local_item->video_url){
                    $local_item = null;
                }
            }
        }
        if($local_item){
            $local_item->update($item_data);
        }else{
            // create new item
            $local_item = self::add_record($item_data);
            // for a new item, see if it can be linked to an existing event, else create one
            $event = sermons_nl_event::get_by_dt($local_item->dt, $local_item->dt_end);
            if(null === $event){
                $event = sermons_nl_event::add_record($local_item->dt, $local_item->dt_end);
            }
            $local_item->event_id = $event->id;
            $event->update_dt_min_max($local_item->dt, $local_item->dt_end);
        }
    }

    private static function remote_dt_to_local(string $dt){
        return (new DateTime($dt))->setTimeZone(sermons_nl::$timezone_db)->format("Y-m-d H:i:s");
    }

    private static function dt_sub(string $dt, int $m){
        return (new DateTime($dt, sermons_nl::$timezone_db))->sub(new DateInterval('PT'.$m.'M'))->format("Y-m-d H:i:s");
    }

    private static function dt_add(string $dt, int $m){
        return (new DateTime($dt, sermons_nl::$timezone_db))->add(new DateInterval('PT'.$m.'M'))->format("Y-m-d H:i:s");
    }

    private static function get_audio_video_ratio(){
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->get_results("SELECT COUNT(audio_id) AS num_audio, COUNT(video_id) AS num_video FROM `{$wpdb->prefix}sermons_nl_kerkdienstgemist` WHERE dt < CURDATE() AND dt > DATE_SUB(CURDATE(), INTERVAL 1 MONTH);");
        if($result[0]->num_video == 0) return 1; // no videos in the past month, assume there will be audio
        return $result[0]->num_audio / $result[0]->num_video;
    }

}
