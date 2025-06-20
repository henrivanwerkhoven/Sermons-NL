var sermons_nl_admin = {
    
    kt_id : '',
    
    toggle_kerktijden : function(obj){
        tbody_obj = document.getElementById("kerktijden_settings");
        input_obj = document.getElementById("input_kerktijden_id");
        if(!obj.checked){
            tbody_obj.className = "settings-disabled";
            this.kt_id = input_obj.value;
            input_obj.value = "";
        }else{
            tbody_obj.className = "";
            input_obj.value = this.kt_id;
        }
    },
    
    ko_id : '',

    toggle_kerkomroep : function(obj){
        tbody_obj = document.getElementById("kerkomroep_settings");
        input_obj = document.getElementById("input_kerkomroep_id");
        if(!obj.checked){
            tbody_obj.className = "settings-disabled";
            this.ko_id = input_obj.value;
            input_obj.value = "";
        }else{
            tbody_obj.className = "";
            input_obj.value = this.ko_id;
        }
    },
    
    kg_id : '',

    toggle_kerkdienstgemist : function(obj){
        tbody_obj = document.getElementById("kerkdienstgemist_settings");
        input_obj = document.getElementById("input_kerkdienstgemist_id");
        if(!obj.checked){
            tbody_obj.className = "settings-disabled";
            this.kg_id = input_obj.value;
            input_obj.value = "";
        }else{
            tbody_obj.className = "";
            input_obj.value = this.kg_id;
        }
    },
    
    yt_id : '',
    
    toggle_youtube : function(obj){
        tbody_obj = document.getElementById("youtube_settings");
        input_obj = document.getElementById("input_youtube_id");
        if(!obj.checked){
            tbody_obj.className = "settings-disabled";
            this.yt_id = input_obj.value;
            input_obj.value = "";
        }else{
            tbody_obj.className = "";
            input_obj.value = this.yt_id;
        }
    },

    config_submit : function(form_obj){
        // get attributes
        var data = new FormData(form_obj);
        var msg_obj = document.getElementById('sermons_nl_config_save_msg');
        msg_obj.className = "";
        msg_obj.innerText = "";
        this.wait_a_sec(1);

        jQuery.ajax({
            type: "POST",
            url: this.admin_url,
            data: data,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response){
                // scroll to top to show the notice
                window.scrollTo(0,0);
                if(!response || response.ok === null || !response.action){
                    msg_obj.innerText = "Error: did not receive expected json response.";
                    msg_obj.className = "notice notice-error";
                    return false;
                }
                if(response.action != "sermons_nl_config_submit"){
                    msg_obj.innerText = "Error: json action is not the same as requested.";
                    msg_obj.className = "notice notice-error";
                    return false;
                }
                if(response.ok === true){
                    if(response.resources != ""){
                        // obtain data in background. Don't worry about a response, if something goes wrong it will be logged.
                        jQuery.ajax({
                            type : "GET",
                            url : sermons_nl_admin.admin_url,
                            data : {
                                action : 'sermons_nl_get_remote_data_in_background',
                                resources : response.resources,
                                _wpnonce : response.nonce
                            },
                            dataType : 'json'
                        });
                    }
                    msg_obj.innerText = response.sucMsg;
                    msg_obj.className = "notice notice-success keep5sec";
                    return true;
                }else{
                    msg_obj.innerText = response.errMsg;
                    msg_obj.className = "notice notice-error";
                    return false;
                }
            },
            error: function(jqXHR, textStatus, errorThrown){
                msg_obj.innerText = textStatus + ": " + errorThrown;
                msg_obj.className = "notice notice-error";
            },
            complete: function() {
                sermons_nl_admin.wait_a_sec(0);
            }
        });
        return true;
    },

    show_details : function(eventid){
    	this.wait_a_sec(1);
        // get attributes
    	var data = {
    		action : "sermons_nl_admin_show_details",
    		event_id : eventid
    	};
    	jQuery.get(this.admin_url, data, function(response){
    		if(!response.action || !response.html){
    		    console.log("Error: unexpected json response");
    		    return false;
    		}
    		obj = document.getElementById('sermons_nl_details_view');
    		obj.innerHTML = response.html;
    		obj.className = 'shown';
            // scroll to show the details object (in case needed)
            rect = obj.getBoundingClientRect();
            window.scrollBy(rect.left-40, rect.top-40);
    		return true;
    	}, 'json').fail(function(jqXHR, textStatus, errorThrown){
    		console.log("Error " + textStatus + ": " + errorThrown);
    	}).always(function() {
    	    sermons_nl_admin.wait_a_sec(0);
    	});
    },
    
    hide_details : function(eventid){
        // hide element
        obj = document.getElementById('sermons_nl_details_view');
        obj.innerHTML = '';
    	obj.className = '';
    },
    
    table_month : 0,

    navigate : function(m){
    	this.wait_a_sec(1);
        this.table_month += m;
        // get attributes
    	var data = {
    		action : "sermons_nl_admin_navigate_table",
    		month : this.table_month
    	};
    	jQuery.get(this.admin_url, data, function(response){
    		if(!response || !response.html || !response.action){
    		    console.log("Error: no json response received or did not receive parameters as expected.");
    		    return false;
    		}
    		if(response.action != "sermons_nl_admin_navigate_table"){
    		    console.log("Error: json action is not the same as requested.");
    		    return false;
    		}
    		if(response.month == sermons_nl_admin.table_month){
    		    obj = document.getElementById("sermons_nl_admin_table");
    		    if(!obj){
    		        console.log("Error: Object #sermons_nl_admin_table not found.");
    		        return false;
    		    }
    		    obj.innerHTML = response.html;
    		    return true;
    		}
    	}, 'json').fail(function(jqXHR, textStatus, errorThrown){
    		console.log("Error " + textStatus + ": " + errorThrown);
    	}).always(function() {
    	    sermons_nl_admin.wait_a_sec(0);
    	});
    },
    
    pending_req : 0,
    
    wait_a_sec : function(on){
        if(on==1){
            this.pending_req ++;
            obj = document.getElementById('sermons_nl_waiting');
            obj.className = "shown";
        }else{
            this.pending_req --;
            if(this.pending_req === 0){
                obj = document.getElementById('sermons_nl_waiting');
                obj.className = "";
            }
        }
    },
    
    copy_shortcode : function(obj){
        textobj = obj.querySelectorAll('div,span')[0];
        if(typeof textobj !== 'undefined' && navigator && navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(textobj.innerText);
            textobj.className = 'copied';
            setTimeout(function(){textobj.className = '';}, 500);
        }else{
            // perhaps in non-secure environment (firefox only supports this on ssl secured sites)
            // or browser not supporting writing to clipboard. Take alternative approach
            console.log("Sermons-nl: Browser does not support copying to clipboard. Using alternative approach.");
            txt = document.createElement('input');
            txt.setAttribute('type','text');
            txt.setAttribute('value',textobj.innerText);
            txt.style.width = '100%';
            textobj.parentNode.append(txt);
            msg = document.createTextNode('Press Ctrl+C to copy the shortcode.');
            textobj.parentNode.append(msg);
            txt.select();
            const txtdrop = () => {msg.parentNode.removeChild(msg); txt.parentNode.removeChild(txt);};
            txt.addEventListener("copy",(event) => {txt.style.backgroundColor = '#00ff00'; setTimeout(txtdrop, 100);});
            txt.addEventListener("focusout", txtdrop);
            txt.addEventListener("click", (event) => {event.stopPropagation();});
        }
    },
    
    link_item_to_event : function(item_type, item_id, event_id, from_event_id=0){
        this.wait_a_sec(1);
        // get attributes
    	var data = {
    		action : "sermons_nl_admin_link_item_to_event",
    		item_type : item_type,
    		item_id : item_id,
    		event_id : event_id,
    		_wpnonce : this.nonce
    	};
    	jQuery.post(this.admin_url, data, function(response){
    		if(!response || !response.ok || !response.action){
    		    console.log("Error: did not receive expected json response.");
    		    return false;
    		}
    		if(response.action != "sermons_nl_admin_link_item_to_event"){
    		    console.log("Error: json action is not the same as requested.");
    		    return false;
    		}
    		if(response.ok === true){
    		    // reload current view
    		    sermons_nl_admin.show_details(from_event_id);
    		    // reload current month view (even though it may not be needed if the changed event if from another month)
    		    sermons_nl_admin.navigate(0);
    		    // update number
    		    if(from_event_id == 0) sermons_nl_admin.update_unlinked_num(response.unlinked_num);
    		    return true;
    		}
    		console.log("Linking item to event gave error: " + response.errMsg);
    		return false;
    	}, 'json').fail(function(jqXHR, textStatus, errorThrown){
    		console.log("Error " + textStatus + ": " + errorThrown);
    	}).always(function() {
    	    sermons_nl_admin.wait_a_sec(0);
    	});
    },
    
    unlink_item : function(item_type, item_id){
        this.wait_a_sec(1);
        // get attributes
        var data = {
            action : "sermons_nl_admin_unlink_item",
            item_type : item_type,
            item_id : item_id,
            _wpnonce : this.nonce
        };
        jQuery.post(this.admin_url, data, function(response){
            if(!response || !response.ok || !response.action){
    		    console.log("Error: did not receive expected json response.");
    		    return false;
    		}
    		if(response.action != "sermons_nl_admin_unlink_item"){
    		    console.log("Error: json action is not the same as requested.");
    		    return false;
    		}
    		if(response.ok === true){
    		    if(document.getElementById('sermons_nl_admin_table')){
        		    // reload views when in the administration page
        		    sermons_nl_admin.show_details(response.event_id);
        		    sermons_nl_admin.navigate(0);
        		    // update number of unlinked items
        		    sermons_nl_admin.update_unlinked_num(response.unlinked_num);
        		    return true;
    		    }else if(document.getElementById('sermons_nl_issues_table')){
    		        // respond with a page refresh when in main page
    		        window.location.replace(window.location.pathname + window.location.search + window.location.hash);
    		        return true;
    		    }
    		    console.log('Sermons-NL: this action is called from an unknown page.');
    		    return false;
    		}
    		console.log("Linking item to event gave error: " + response.errMsg);
    		return false;
        }, 'json').fail(function(jqXHR, textStatus, errorThrown){
    		console.log("Error " + textStatus + ": " + errorThrown);
    	}).always(function() {
    	    sermons_nl_admin.wait_a_sec(0);
    	});
    },
    
    update_unlinked_num : function(num){
        obj = document.getElementById('sermons_nl_unlinked_num');
        if(obj) obj.innerText = num;
    },
    
    delete_event : function(event_id){
        this.wait_a_sec(1);
        // get attributes
        var data = {
            action : "sermons_nl_admin_delete_event",
            event_id : event_id,
            _wpnonce : this.nonce
        };
        jQuery.post(this.admin_url, data, function(response){
            if(!response || !response.ok || !response.action){
    		    console.log("Error: did not receive expected json response.");
    		    return false;
    		}
    		if(response.action != "sermons_nl_admin_delete_event"){
    		    console.log("Error: json action is not the same as requested.");
    		    return false;
    		}
    		if(response.ok === true){
    		    // close detail view
    		    sermons_nl_admin.hide_details(response.event_id);
    		    // reload current month view (even though it may not be needed if the changed event if from another month)
    		    sermons_nl_admin.navigate(0);
    		    return true;
    		}
    		console.log("Linking item to event gave error: " + response.errMsg);
    		return false;
        }, 'json').fail(function(jqXHR, textStatus, errorThrown){
    		console.log("Error " + textStatus + ": " + errorThrown);
    	}).always(function() {
    	    sermons_nl_admin.wait_a_sec(0);
    	});
    },
    
    build_shortcode : function(){
        target_obj = document.getElementById("sermons_nl_shortcode");
        method_obj = document.getElementById("sermons_nl_selection_method");
        start_obj = document.getElementById("sermons_nl_start_date");
        end_obj = document.getElementById("sermons_nl_end_date");
        count_obj = document.getElementById("sermons_nl_count");
        fmt_obj = document.getElementById("sermons_nl_datefmt");
        more_obj = document.getElementById("sermons_nl_more_buttons");
        logo_obj = document.getElementById("sermons_nl_show_logo");
        method = method_obj.options[method_obj.selectedIndex].value;
        switch(method){
            case 'start-stop-date':
                shortcode = '[sermons-nl-list offset="' + start_obj.value + '" ending="' + end_obj.value + '"]';
                start_obj.disabled = false;
                end_obj.disabled = false;
                count_obj.disabled = true;
                break;
            case 'start-date-count': 
                shortcode = '[sermons-nl-list offset="' + start_obj.value + '" count=' + count_obj.value + ']';
                start_obj.disabled = false;
                end_obj.disabled = true;
                count_obj.disabled = false;
                break;
            case 'stop-date-count':
                shortcode = '[sermons-nl-list ending="' + end_obj.value + '" count=' + count_obj.value + ']';
                start_obj.disabled = true;
                end_obj.disabled = false;
                count_obj.disabled = false;
                break;
            default:
                console.log('Sermons-nl: Incorrect method');
                return false;
        }
        if(fmt_obj.value != 'long' && fmt_obj.value != ''){
            shortcode = shortcode.replace(']', ' datefmt="' + fmt_obj.value + '"]');
        }
        if(more_obj.checked){
            shortcode = shortcode.replace(']', ' more-buttons=1]');
        }
        if(logo_obj.checked){
            shortcode = shortcode.replace(']', ' plugin-logo=1]');
        }
        target_obj.innerText = shortcode;
        return true;
    },

    toggle_manual_row : function(dt_obj,varname){
        row = document.getElementById('sermons_nl_'+varname+'_manual');
        if(dt_obj.options[dt_obj.selectedIndex].value == 'manual'){
            row.className = '';
        }else{
            row.className = 'sermons-nl-manual-closed';
        }
    },

    submit_update_event : function(form_obj){
        this.wait_a_sec(1);
        // get attributes
        var data = new FormData(form_obj);
        var errmsg_obj = document.getElementById('sermons_nl_event_errmsg');
        errmsg_obj.innerText = ""; // to hide previous error upon the next save attempt
        jQuery.ajax({
            type: "POST",
            url: this.admin_url,
            data: data,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response){
                if(!response || response.ok === null || !response.action){
                    errmsg_obj.innerText = "Error: did not receive expected json response.";
                    return false;
                }
                if(response.action != "sermons_nl_submit_update_event"){
                    errmsg_obj.innerText = "Error: json action is not the same as requested.";
                    return false;
                }
                if(response.ok === true){
                    if(document.getElementById('sermons_nl_events_table')){
                        sermons_nl_admin.navigate(0);
                    }else if(document.getElementById('sermons_nl_issues_table')){
                        // respond with a page refresh when in main page
                        window.location.replace(window.location.pathname + window.location.search + window.location.hash);
                    }else{
                        console.log('Sermons-NL: this action is called from an unknown page.');
                        return false;
                    }
                    return true;
                }
                errmsg_obj.innerText = response.errMsg;
                return false;
            },
            error : function(jqXHR, textStatus, errorThrown){
                errmsg_obj.innerText = "Error " + textStatus + ": " + errorThrown;
            },
            complete : function() {
                sermons_nl_admin.wait_a_sec(0);
            }
        });
        return true;
    }
    
};
jQuery(document).ready(function($){
    if(typeof $('#sermons_nl_shortcode') != 'undefined'){
        $('#sermons_nl_selection_method').change(sermons_nl_admin.build_shortcode);
        $('#sermons_nl_start_date').on('input', sermons_nl_admin.build_shortcode);
        $('#sermons_nl_end_date').on('input', sermons_nl_admin.build_shortcode);
        $('#sermons_nl_count').on('input', sermons_nl_admin.build_shortcode);
        $('#sermons_nl_datefmt').on('input', sermons_nl_admin.build_shortcode);
        $('#sermons_nl_more_buttons').click(sermons_nl_admin.build_shortcode);
        $('#sermons_nl_show_logo').click(sermons_nl_admin.build_shortcode);
        $('.sermons-nl-colorpicker').wpColorPicker();
    }
});
