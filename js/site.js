var sermons_nl = {
	toggledetails : function(elm){
		// check if any element has classname 'sermons-nl-open'
		x = document.getElementById("sermons_nl_list").getElementsByClassName("sermons-nl-open");
		if(x.length > 0){
			closeOnly = (elm == x[0]);
			d = x[0].getElementsByClassName("sermons-nl-details")[0];
			d.style.height = "0";
			x[0].className = "";
			if(closeOnly) return false;
		}
		d = elm.getElementsByClassName("sermons-nl-details")[0];
		d.style.height = d.firstChild.clientHeight + "px";
		elm.className = "sermons-nl-open";
		// check if element is in the viewport 
		const rect = elm.getBoundingClientRect();
		if(rect.top < 0){
			window.scrollBy(0,rect.top);
		}else if(rect.top > 0 && rect.bottom > (window.innerHeight || document.documentElement.clientHeight)){
			window.scrollBy(0,Math.min(rect.top, rect.bottom - (window.innerHeight || document.documentElement.clientHeight)));
		}
		return true;
	},
	stopPropagation : function(){
		var d = document.getElementById("sermons_nl_list").getElementsByClassName("sermons-nl-details");
		for(var i=0; i<d.length; i++){
			d[i].onclick = function(e){ e.stopPropagation(); };
		}
	},
	showmore : function(direction){
		// get attributes
		var data = {
			action : "sermons_nl_showmore",
			direction: direction,
			datefmt : self.datefmt
		};
		x = document.getElementById("sermons_nl_list");
		if(direction=="up"){
			data.current = x.firstChild.getAttribute("id");
		}
		else if(direction=="down"){
			data.current = x.childNodes[x.childNodes.length-1].getAttribute("id");
		}
		else{
		    console.log("Error in sermons_nl.showmore(): wrong direction '" + direction + "' given.")
			return false;
		}
		this.showmorebutton(direction, "disable");
		jQuery.get(sermons_nl.admin_url, data, function(response){
			retval = JSON.parse(response);
			if(!retval || retval.call != 'sermons_nl_showmore'){
			    console.log("sermons-nl: unexpected response from sermons_nl.showmore()");
			    return false;
			}
			if(retval.error){
			    console.log("sermons-nl: " + retval.error);
			    return false;
			}else if(direction != retval.direction){
			    console.log("sermons-nl error: direction submitted is not the same as direction received back.");
			    return false;
			}else{
    			ul = document.createElement("ul");
	    		ul.innerHTML = retval.html;
	    		if(!ul.firstChild || ul.firstChild.tagName.toLowerCase() != 'li'){
	    		    alert(ul.firstChild.tagName);
	    		    console.log("sermons-nl error: response does not contain list items.");
	    		    return false;
	    		}
    			n = ul.childNodes.length;
    			if(direction == "up"){
    				for(i=n-1; i>= 0; i--){
		    			x.insertBefore(ul.childNodes[i], x.firstChild);
			    	}
			    }else{
	    			for(i=0; i<n; i++){
			    		x.appendChild(ul.firstChild);
				    }
    			}
    			if(retval.num_more_rec <= 0){
    			    // there are no more records in this direction
    			    sermons_nl.showmorebutton(direction, 'remove');
    			}
    			sermons_nl.stopPropagation();
    			jQuery(document.body).trigger("post-load");
			}
		}, 'text').fail(function(jqXHR, textStatus, errorThrown){
			console.log("Error " + textStatus + ": " + errorThrown);
		}).always(function() {
			sermons_nl.showmorebutton(direction, "enable");
		});
	},
	showmorebutton : function(direction, what){
		elm = document.getElementById("sermons_nl_more_"+(direction == "up" ? "up" : "down"));
		if(elm){
			switch(what){
				case "remove":
					elm.parentNode.removeChild(elm);
				break;
				case "disable":
					elm.className="disabled";
					elm.onclick = null;
				break;
				case "enable":
					elm.className="";
					elm.onclick=function(event){ sermons_nl.showmore(direction); };
				break;
			}
		}
	},
	checkstatus_ids : [],
	checkstatus : function(){
		// add to data which sermons are currently live. server will return data for these irrespective of whether they are still live
		function _(s){
    		for(var i=0; i<s.length; i++){
    		    if(s[i].hasAttribute('id') && !sermons_nl.checkstatus_ids.includes(s[i].getAttribute("id"))){
    		        sermons_nl.checkstatus_ids[sermons_nl.checkstatus_ids.length] = s[i].getAttribute("id");
    			}
    		}
		}
		_(document.getElementsByClassName('sermons-nl-audio-live'));
		_(document.getElementsByClassName('sermons-nl-video-live'));
		
		data = {
		    action : 'sermons_nl_checkstatus',
		    live : sermons_nl.checkstatus_ids,
			check_list : (sermons_nl.check_list ? 1 : 0),
			check_lone : (sermons_nl.check_lone ? 1 : 0),
			datefmt : sermons_nl.datefmt
		};
		
		jQuery.get(sermons_nl.admin_url, data, function(response){
			if(response.call != 'sermons_nl_checkstatus'){
			    console.log("sermons-nl: unexpected response from sermons_nl.checkstatus()");
			    return false;
			}
			if(response.events_list != null){
				for(i=0; i<response.events_list.length; i++){
					links_obj = document.getElementById(response.events_list[i].id);
					if(links_obj){
						links_obj.innerHTML = response.events_list[i].html;
						li_obj = document.getElementById(response.events_list[i].id.replace('_links',''));
						if(li_obj){
							span_objs = li_obj.getElementsByTagName('span');
							if(span_objs.length >= 2){
								span_objs[0].className = response.events_list[i].audio_class;
								span_objs[1].className = response.events_list[i].video_class;
							}else{
								console.log('Sermons-NL error: Missing span tags?');
							}
						}
						else{
							console.log('Sermons-NL error: Incorrect id for li element?');
						}
					}
					else if(response.events_list[i].event_html){
						new_timestamp = response.events_list[i].event_timestamp;
						ul_obj = document.getElementById('sermons_nl_list');
						tmp_ul_obj = document.createElement('ul');
						tmp_ul_obj.innerHTML = response.events_list[i].event_html;
						new_li_obj = tmp_ul_obj.firstChild;
						if(ul_obj){
							li_objs = ul_obj.getElementsByTagName('li');
							added = false;
							for(const li_obj of li_objs){
								this_timestamp = li_obj.getAttribute('event-timestamp');
								if(this_timestamp > new_timestamp){
									ul_obj.insertBefore(new_li_obj, li_obj);
									added = true;
									break;
								}
							}
							if(!added){
								ul_obj.append(new_li_obj);
							}
							sermons_nl.stopPropagation();
						}
					}
				}
			}
			if(response.events_lone != null){
				for(i=0; i<response.events_lone.length; i++){
					links_obj = document.getElementById(response.events_lone[i].id);
					if(links_obj){
						links_obj.innerHTML = response.events_lone[i].html;
					}
				}
			}
			if(response.items_lone != null){
				for(i=0; i<response.items_lone.length; i++){
					links_obj = document.getElementById(response.items_lone[i].id);
					if(links_obj){
						links_obj.innerHTML = response.items_lone[i].html;
					}
				}
			}
			return true;
		}, 'json').fail(function(jqXHR, textStatus, errorThrown){
    		console.log("Error " + textStatus + ": " + errorThrown);
    	}).always(function() {
    	    
    	});
	},
	playmedia : function(elm, mimetype, service, standalone=false){
		var isVideoLivestream = false;
		var type = mimetype.split("/")[0];
		if(type == 'application'){
			isVideoLivestream = true;
			type = 'video'; // create video elm if a playlist is provided (video livestream)
		}
		if(type != 'audio' && type != 'video'){
		    console.log('Sermons-NL: No audio or video type.');
			return false; // open url
		}
		
		if(standalone){
			x = elm.parentNode.parentNode.parentNode;
			li = null;
		}else{
			// identify container where to add the audio element
			x = elm;
			do{
				x = x.parentNode;
			}while(x.parentNode.className != "sermons-nl-details");

			// identify the containing list item
			li = x;
			do{
				li = li.parentNode;
			}while(li.tagName.toLowerCase() != 'li');
		}

		// check for already playing audio and video elements
		var player_id = elm.getAttribute('id');
		if(!player_id){
		    console.log('Sermons-NL: the clicked link should have id.');
		    return false;
		}
		if(this.playing !== null){
			if(player_id === this.playing){
				// it's the same one and it is already active/visible, just play it (if not already playing) and quit
				switch(this.players[this.playing].service){
					case 'yt-video':
						this.players[this.playing].player.playVideo();
						return true;
					case 'kg-video':
						// check if live. If not, follow default behavior
						if(isVideoLiveStream){
							// not possible to play using javascript
							return true;
						}
					default:
						this.players[this.playing].player.play();
						return true;
				}
			}else{
				// stop and hide it and continue to find or create the active one
				switch(this.players[this.playing].service){
					case 'yt-video':
						this.players[this.playing].player.pauseVideo();
						// remove this player because the same video can be on the page twice and they can't both be created at the same time
						this.players[this.playing].div.parentNode.removeChild(this.players[this.playing].div);
						delete this.players[this.playing];
					break;
					case 'kg-video':
						// check if live. If not, follow default behavior
						if(this.players[this.playing].isVideoLivestream){
							// not possible to pause using javascript -- Needs to be deleted.
							this.players[this.playing].div.parentNode.removeChild(this.players[this.playing].div);
							delete this.players[this.playing];
							break;
						}
					default:
						this.players[this.playing].player.pause();
						this.players[this.playing].div.style.display = 'none';
				}
			}
		}

		// if audio/video element already exists, play it, else create one
		this.playing = player_id;
		if(this.players[this.playing]){
			this.players[this.playing].div.style.display = 'block';
			switch(service){
				case 'yt-video':
					this.players[this.playing].player.playVideo();
				break;
				case 'kg-video':
					// check if live. If not, follow default behavior
					if(isVideoLivestream){
						// not possible to play using javascript. this should never happen since the elements are deleted upon starting other media
						break;
					}
				default:
					this.players[this.playing].player.play();
			}
		}
		else{
			d = document.createElement('div');
			d.setAttribute('class','media-'+service);
			if(service == 'yt-video'){
				var yt_videoid = elm.href.match(/(?<=v=)[^#&]*/);
				if(!yt_videoid){
					return false; // corrupt link?
				}

				c = document.createElement('div');
				container_id = 'yt_' + yt_videoid;
				c.setAttribute('id',container_id);
				d.appendChild(c);
				x.appendChild(d);

				this.players[this.playing] = {div: d, li: li, player: null, service: service};

				if(!this.ytapi_loaded){
					window.onYouTubePlayerAPIReady = function(){sermons_nl.createYTplayer(player_id,yt_videoid,x,container_id,standalone);};
					// Load the IFrame Player API code asynchronously
					var js = document.createElement('script');
					js.src = "https://www.youtube.com/player_api";
					document.head.appendChild(js);
					this.ytapi_loaded = true;
				}else{
					sermons_nl.createYTplayer(player_id,yt_videoid,x,container_id,standalone);
				}
			}else if(service == 'kg-video' && isVideoLivestream){
				i = document.createElement('iframe');
				i.setAttribute("scrolling","no");
				i.setAttribute("width", x.clientWidth);
				i.setAttribute("height", Math.round(x.clientWidth * 9 / 16));
				i.setAttribute("allowTransparency","true");
				i.setAttribute("frameborder","0");
				i.setAttribute("borderwidth","0");
				i.setAttribute("borderheight","0");
				i.setAttribute("src", elm.href.replace(/\.m3u(8|)/,"/embed")); 
				i.setAttribute("allowfullscreen","allowfullscreen");
				i.setAttribute("allowTransparency","true");
				i.setAttribute("mozallowfullscreen","true");
				i.setAttribute("webkitallowfullscreen","true");
				i.setAttribute("allow","autoplay; fullscreen");
				d.appendChild(i);
				x.appendChild(d);
				this.players[this.playing] = {div: d, li: li, player: i, service: service, isVideoLivestream: true};
			}else{
				m = document.createElement(type);
				m.setAttribute('controls','controls');
				m.setAttribute('autoplay','autoplay');
				d.appendChild(m);
				s = document.createElement('source');
				s.setAttribute('src',elm.href);
				s.setAttribute('type',mimetype);
				m.appendChild(s);
				m.appendChild(document.createTextNode(type + " afspelen wordt niet ondersteund door uw browser."));
				if(m.canPlayType(mimetype)){
					x.appendChild(d);
				}else if(type=='video'){
					m.setAttribute('width', x.clientWidth);
					m.setAttribute('class','video-js');
					if(this.vjs_loaded){
						x.appendChild(d);
						videojs(m, {});
					}else{
						// add video-js framework
						var css = document.createElement("link");
						css.setAttribute("rel","stylesheet");
						css.setAttribute("type","text/css");
						css.setAttribute("href",sermons_nl.plugin_url+"/css/video-js.css");
						document.head.appendChild(css);
						var js = document.createElement('script');
						js.onload = function(){
							x.appendChild(d);
							videojs(m, {});
						}
						js.src = sermons_nl.plugin_url+"/js/video.min.js";
						document.head.appendChild(js);
						this.vjs_loaded = true;
					}
				}else{
					return false; // cannot play
				}
				this.players[this.playing] = {div: d, li: li, player: m, service: service};
			}
		}
		if(type == 'video' && service != 'yt-video' && !(service == 'kg-video' && isVideoLivestream)){
			m.addEventListener('canplay', function(){x.parentNode.style.height = x.clientHeight + 'px';});
		}
		if(!standalone){
			x.parentNode.style.height = x.clientHeight + "px";
		}
		return true;
	},
	createYTplayer : function(player_id,yt_videoid,x,container_id,standalone){
		// create the player
		sermons_nl.players[player_id].player = new YT.Player(container_id, {
			height: Math.round(x.clientWidth * 9/16),
			width: x.clientWidth,
			videoId: yt_videoid,
			playerVars: {autoplay : 1}
		});
		if(!standalone){
			x.parentNode.style.height = x.clientHeight + "px";
		}
	},
	responsive_list : function(){
		x = document.getElementById("sermons_nl_list");
		if(x.clientWidth < 750) x.className = "style-narrow";
		else x.className = "";
	},
	players : {},
	playing : null,
	vjs_loaded : false,
	ytapi_loaded : false,
	timer : null,
	check_list : false,
	check_lone : false,
	onclick : null,
	check_interval : Infinity,
	admin_url : null,
	plugin_url : null,
	datefmt : 'short'
}
jQuery(document).ready(function($){
	x_list = document.getElementById("sermons_nl_list");
	x_events = document.getElementsByClassName("sermons_nl_event_lone");
	x_items = document.getElementsByClassName("sermons_nl_item_lone");

	if((x_list != null || x_events.length > 0 || x_items.length > 0) && sermons_nl.check_interval > 0 && sermons_nl.check_interval != Infinity){
		sermons_nl.check_list = (x_list != null);
		sermons_nl.check_lone = (x_items.length > 0 || x_events.length > 0);
		sermons_nl.timer = setInterval(sermons_nl.checkstatus, sermons_nl.check_interval*1000);
	}
	if(x_list != null){
		// adjust width if needed
		sermons_nl.responsive_list();
		// this is to prevent closure of an item of the list when clicking on child elements
		sermons_nl.stopPropagation();
		// get the right date format
		if(x_list.hasAttribute('list-datefmt')){
			sermons_nl.datefmt = x_list.getAttribute('list-datefmt');
		}
		// on resize check if narrow or normal style should be used
		jQuery(window).resize(sermons_nl.responsive_list);
	}
});
