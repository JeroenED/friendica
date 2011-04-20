<?php

/**
 * Render actions localized
 */
function localize_item(&$item){
	
	if ($item['verb']=="http://activitystrea.ms/schema/1.0/like" ||
		$item['verb']=="http://activitystrea.ms/schema/1.0/dislike"){

		$r = q("SELECT * from `item`,`contact` WHERE 
				`item`.`contact-id`=`contact`.`id` AND `item`.`uri`='%s';",
				 dbesc($item['parent-uri']));
		if(count($r)==0) return;
		$obj=$r[0];
		
		$author	 = '[url=' . $item['author-link'] . ']' . $item['author-name'] . '[/url]';
		$objauthor =  '[url=' . $obj['author-link'] . ']' . $obj['author-name'] . '[/url]';
		
		$post_type = (($obj['resource-id']) ? t('photo') : t('status'));		
		$plink = '[url=' . $obj['plink'] . ']' . $post_type . '[/url]';
                
		switch($item['verb']){
			case "http://activitystrea.ms/schema/1.0/like":
				$bodyverb = t('%1$s likes %2$s\'s %3$s');
				break;
			case "http://activitystrea.ms/schema/1.0/dislike":
				$bodyverb = t('%1$s doesn\'t like %2$s\'s %3$s');
				break;
		}
		$item['body'] = sprintf($bodyverb, $author, $objauthor, $plink);
			
	}
	if ($item['verb']=='http://activitystrea.ms/schema/1.0/make-friend'){

		if ($item['object-type']=="" || $item['object-type']!='http://activitystrea.ms/schema/1.0/person') return;

		$Aname = $item['author-name'];
		$Alink = $item['author-link'];
		
		$xmlhead="<"."?xml version='1.0' encoding='UTF-8' ?".">";
		
		$obj = parse_xml_string($xmlhead.$item['object']);
		$links = parse_xml_string($xmlhead."<links>".unxmlify($obj->link)."</links>");
		
		$Bname = $obj->title;
		$Blink = ""; $Bphoto = "";
		foreach ($links->link as $l){
			$atts = $l->attributes();
			switch($atts['rel']){
				case "alternate": $Blink = $atts['href'];
				case "photo": $Bphoto = $atts['href'];
			}
			
		}
		
		$A = '[url=' . $Alink . ']' . $Aname . '[/url]';
		$B = '[url=' . $Blink . ']' . $Bname . '[/url]';
		if ($Bphoto!="") $Bphoto = '[url=' . $Blink . '][img]' . $Bphoto . '[/img][/url]';

		$item['body'] = sprintf( t('%1$s is now friends with %2$s'), $A, $B)."\n\n\n".$Bphoto;

	}
        
}

/**
 * "Render" a conversation or list of items for HTML display.
 * There are two major forms of display:
 *      - Sequential or unthreaded ("New Item View" or search results)
 *      - conversation view
 * The $mode parameter decides between the various renderings and also
 * figures out how to determine page owner and other contextual items 
 * that are based on unique features of the calling module.
 *
 */
function conversation(&$a, $items, $mode, $update) {

	require_once('bbcode.php');

	$profile_owner = 0;
	$page_writeable      = false;

	if($mode === 'network') {
		$profile_owner = local_user();
		$page_writeable = true;
	}

	if($mode === 'profile') {
		$profile_owner = $a->profile['profile_uid'];
		$page_writeable = can_write_wall($a,$profile_owner);
	}

	if($mode === 'display') {
		$profile_owner = $a->profile['uid'];
		$page_writeable = can_write_wall($a,$profile_owner);
	}

	if($update)
		$return_url = $_SESSION['return_url'];
	else
		$return_url = $_SESSION['return_url'] = $a->cmd;


	// find all the authors involved in remote conversations
	// We will use a local profile photo if they are one of our contacts
	// otherwise we have to get the photo from the item owner's site

	$author_contacts = extract_item_authors($items,local_user());


	$cmnt_tpl    = load_view_file('view/comment_item.tpl');
	$like_tpl    = load_view_file('view/like.tpl');
	$noshare_tpl = load_view_file('view/like_noshare.tpl');
	$tpl         = load_view_file('view/wall_item.tpl');
	$wallwall    = load_view_file('view/wallwall_item.tpl');

	$alike = array();
	$dlike = array();
	
	if(count($items)) {

		if($mode === 'network-new' || $mode === 'search') {

			// "New Item View" on network page or search page results 
			// - just loop through the items and format them minimally for display

			$tpl = load_view_file('view/search_item.tpl');
			$droptpl = load_view_file('view/wall_fake_drop.tpl');

			foreach($items as $item) {

				$comment     = '';
				$owner_url   = '';
				$owner_photo = '';
				$owner_name  = '';
				$sparkle     = '';

				if($mode === 'search') {
					if(((activity_match($item['verb'],ACTIVITY_LIKE)) || (activity_match($item['verb'],ACTIVITY_DISLIKE))) 
						&& ($item['id'] != $item['parent']))
						continue;
					$nickname = $item['nickname'];
				}
				else
					$nickname = $a->user['nickname'];
			
				$profile_name   = ((strlen($item['author-name']))   ? $item['author-name']   : $item['name']);
				$profile_avatar = ((strlen($item['author-avatar'])) ? $item['author-avatar'] : $item['thumb']);
				$profile_link   = ((strlen($item['author-link']))   ? $item['author-link']   : $item['url']);
				if($profile_link === 'mailbox')
					$profile_link = '';

				$redirect_url = $a->get_baseurl() . '/redir/' . $item['cid'] ;

				if(strlen($item['author-link'])) {
					if(link_compare($item['author-link'],$item['url']) && ($item['network'] === 'dfrn') && (! $item['self'])) {
						$profile_link = $redirect_url;
						$sparkle = ' sparkle';
					}
					elseif(isset($author_contacts[$item['author-link']])) {
						$profile_link = $a->get_baseurl() . '/redir/' . $author_contacts[$item['author-link']];
						$sparkle = ' sparkle';
					}
				}

				$location = (($item['location']) ? '<a target="map" title="' . $item['location'] . '" href="http://maps.google.com/?q=' . urlencode($item['location']) . '">' . $item['location'] . '</a>' : '');
				$coord = (($item['coord']) ? '<a target="map" title="' . $item['coord'] . '" href="http://maps.google.com/?q=' . urlencode($item['coord']) . '">' . $item['coord'] . '</a>' : '');
				if($coord) {
					if($location)
						$location .= '<br /><span class="smalltext">(' . $coord . ')</span>';
					else
						$location = '<span class="smalltext">' . $coord . '</span>';
				}

				$drop = '';
				$dropping = false;

				if((intval($item['contact-id']) && $item['contact-id'] == remote_user()) || ($item['uid'] == local_user()))
					$dropping = true;

	            $drop = replace_macros((($dropping)? $droptpl : $fakedrop), array('$id' => $item['id'], '$delete' => t('Delete')));

				// 
				localize_item($item);

				$drop = replace_macros($droptpl,array('$id' => $item['id']));
				$lock = '<div class="wall-item-lock"></div>';
				
				$o .= replace_macros($tpl,array(
					'$id' => $item['item_id'],
					'$linktitle' => sprintf( t('View %s\'s profile'), $profile_name),
					'$profile_url' => $profile_link,
					'$item_photo_menu' => item_photo_menu($item),
					'$name' => $profile_name,
					'$sparkle' => $sparkle,
					'$lock' => $lock,
					'$thumb' => $profile_avatar,
					'$title' => $item['title'],
					'$body' => smilies(bbcode($item['body'])),
					'$ago' => relative_date($item['created']),
					'$location' => $location,
					'$indent' => '',
					'$owner_url' => $owner_url,
					'$owner_photo' => $owner_photo,
					'$owner_name' => $owner_name,
					'$drop' => $drop,
					'$conv' => '<a href="' . $a->get_baseurl() . '/display/' . $nickname . '/' . $item['id'] . '">' . t('View in context') . '</a>'
				));

			}

			return $o;
		}




		// Normal View


		// Figure out how many comments each parent has
		// (Comments all have gravity of 6)
		// Store the result in the $comments array

		$comments = array();
		foreach($items as $item) {
			if(intval($item['gravity']) == 6) {
				if(! x($comments,$item['parent']))
					$comments[$item['parent']] = 1;
				else
					$comments[$item['parent']] += 1;
			}
		}

		// map all the like/dislike activities for each parent item 
		// Store these in the $alike and $dlike arrays

		foreach($items as $item) {
			like_puller($a,$item,$alike,'like');
			like_puller($a,$item,$dlike,'dislike');
		}

		$comments_collapsed = false;
		$blowhard = 0;
		$blowhard_count = 0;

		foreach($items as $item) {

			$comment = '';
			$template = $tpl;
			$commentww = '';
			$sparkle = '';
			$owner_url = $owner_photo = $owner_name = '';

			// We've already parsed out like/dislike for special treatment. We can ignore them now

			if(((activity_match($item['verb'],ACTIVITY_LIKE)) 
				|| (activity_match($item['verb'],ACTIVITY_DISLIKE))) 
				&& ($item['id'] != $item['parent']))
				continue;

			$toplevelpost = (($item['id'] == $item['parent']) ? true : false);


			// Take care of author collapsing and comment collapsing
			// If a single author has more than 3 consecutive top-level posts, squash the remaining ones.
			// If there are more than two comments, squash all but the last 2.

			if($toplevelpost) {

				$item_writeable = (($item['writable'] || $item['self']) ? true : false);

				if($blowhard == $item['cid'] && (! $item['self']) && ($mode != 'profile')) {
					$blowhard_count ++;
					if($blowhard_count == 3) {
						$o .= '<div class="icollapse-wrapper fakelink" id="icollapse-wrapper-' . $item['parent'] 
							. '" onclick="openClose(' . '\'icollapse-' . $item['parent'] . '\');" >' 
							. t('See more posts like this') . '</div>' . '<div class="icollapse" id="icollapse-' 
							. $item['parent'] . '" style="display: none;" >';
					}
				}
				else {
					$blowhard = $item['cid'];					
					if($blowhard_count >= 3)
						$o .= '</div>';
					$blowhard_count = 0;
				}

				$comments_seen = 0;
				$comments_collapsed = false;
			}
			else
				$comments_seen ++;


			$show_comment_box = ((($page_writeable) && ($item_writeable) && ($comments_seen == $comments[$item['parent']])) ? true : false);

			if(($comments[$item['parent']] > 2) && ($comments_seen <= ($comments[$item['parent']] - 2)) && ($item['gravity'] == 6)) {
				if(! $comments_collapsed) {
					$o .= '<div class="ccollapse-wrapper fakelink" id="ccollapse-wrapper-' . $item['parent'] 
						. '" onclick="openClose(' . '\'ccollapse-' . $item['parent'] . '\');" >' 
						. sprintf( t('See all %d comments'), $comments[$item['parent']]) . '</div>'
						. '<div class="ccollapse" id="ccollapse-' . $item['parent'] . '" style="display: none;" >';
					$comments_collapsed = true;
				}
			}
			if(($comments[$item['parent']] > 2) && ($comments_seen == ($comments[$item['parent']] - 1))) {
				$o .= '</div>';
			}

			$redirect_url = $a->get_baseurl() . '/redir/' . $item['cid'] ;

			$lock = ((($item['private']) || (($item['uid'] == local_user()) && (strlen($item['allow_cid']) || strlen($item['allow_gid']) 
				|| strlen($item['deny_cid']) || strlen($item['deny_gid']))))
				? '<div class="wall-item-lock"><img src="images/lock_icon.gif" class="lockview" alt="' . t('Private Message') . '" onclick="lockview(event,' . $item['id'] . ');" /></div>'
				: '<div class="wall-item-lock"></div>');


			// Top-level wall post not written by the wall owner (wall-to-wall)
			// First figure out who owns it. 

			$osparkle = '';

			if(($toplevelpost) && (! $item['self']) && ($mode !== 'profile')) {

				if($item['type'] === 'wall') {

					// On the network page, I am the owner. On the display page it will be the profile owner.
					// This will have been stored in $a->page_contact by our calling page.
					// Put this person on the left of the wall-to-wall notice.

					$owner_url = $a->page_contact['url'];
					$owner_photo = $a->page_contact['thumb'];
					$owner_name = $a->page_contact['name'];
					$template = $wallwall;
					$commentww = 'ww';	
				}
				if(($item['type'] === 'remote') && (strlen($item['owner-link'])) && ($item['owner-link'] != $item['author-link'])) {

					// Could be anybody. 

					$owner_url = $item['owner-link'];
					$owner_photo = $item['owner-avatar'];
					$owner_name = $item['owner-name'];
					$template = $wallwall;
					$commentww = 'ww';
					// If it is our contact, use a friendly redirect link
					if((link_compare($item['owner-link'],$item['url'])) 
						&& ($item['network'] === 'dfrn')) {
						$owner_url = $redirect_url;
						$osparkle = ' sparkle';
					}
				}
			}


			$likebuttons = '';

			if($page_writeable) {
				if($toplevelpost) {
					$likebuttons = replace_macros((($item['private']) ? $noshare_tpl : $like_tpl),array(
						'$id' => $item['id'],
						'$likethis' => t("I like this \x28toggle\x29"),
						'$nolike' => t("I don't like this \x28toggle\x29"),
						'$share' => t('Share'),
						'$wait' => t('Please wait') 
					));
				}

				if(($show_comment_box) || (($show_comment_box == false) && ($item['last-child']))) {
					$comment = replace_macros($cmnt_tpl,array(
						'$return_path' => '', 
						'$jsreload' => (($mode === 'display') ? $_SESSION['return_url'] : ''),
						'$type' => (($mode === 'profile') ? 'wall-comment' : 'net-comment'),
						'$id' => $item['item_id'],
						'$parent' => $item['parent'],
						'$profile_uid' =>  $profile_owner,
						'$mylink' => $a->contact['url'],
						'$mytitle' => t('This is you'),
						'$myphoto' => $a->contact['thumb'],
						'$comment' => t('Comment'),
						'$submit' => t('Submit'),
						'$ww' => (($mode === 'network') ? $commentww : '')
					));
				}
			}

			$edpost = ((($profile_owner == local_user()) && ($toplevelpost) && (intval($item['wall']) == 1))
					? '<a class="editpost" href="' . $a->get_baseurl() . '/editpost/' . $item['id'] 
						. '" title="' . t('Edit') . '"><img src="images/pencil.gif" /></a>'
					: '');
			$drop = replace_macros(load_view_file('view/wall_item_drop.tpl'), array('$id' => $item['id'], '$delete' => t('Delete')));

			$photo = $item['photo'];
			$thumb = $item['thumb'];

			// Post was remotely authored.

			$diff_author    = ((link_compare($item['url'],$item['author-link'])) ? false : true);

			$profile_name   = (((strlen($item['author-name']))   && $diff_author) ? $item['author-name']   : $item['name']);
			$profile_avatar = (((strlen($item['author-avatar'])) && $diff_author) ? $item['author-avatar'] : $thumb);

			if($mode === 'profile') {
				if(local_user() && ($item['contact-uid'] == local_user()) && ($item['network'] === 'dfrn') && (! $item['self'] )) {
	                $profile_link = $redirect_url;
    	            $sparkle = ' sparkle';
        	    }
				else {
					$profile_link = $item['url'];
					$sparkle = '';
				}
			}
			elseif(strlen($item['author-link'])) {
				$profile_link = $item['author-link'];
				if(link_compare($item['author-link'],$item['url']) && ($item['network'] === 'dfrn') && (! $item['self'])) {
					$profile_link = $redirect_url;
					$sparkle = ' sparkle';
				}
				elseif(isset($author_contacts[$item['author-link']])) {
					$profile_link = $a->get_baseurl() . '/redir/' . $author_contacts[$item['author-link']];
					$sparkle = ' sparkle';
				}
			}
			else 
				$profile_link = $item['url'];

			if($profile_link === 'mailbox')
				$profile_link = '';

			$like    = ((x($alike,$item['id'])) ? format_like($alike[$item['id']],$alike[$item['id'] . '-l'],'like',$item['id']) : '');
			$dislike = ((x($dlike,$item['id'])) ? format_like($dlike[$item['id']],$dlike[$item['id'] . '-l'],'dislike',$item['id']) : '');

			$location = (($item['location']) ? '<a target="map" title="' . $item['location'] 
				. '" href="http://maps.google.com/?q=' . urlencode($item['location']) . '">' . $item['location'] . '</a>' : '');
			$coord = (($item['coord']) ? '<a target="map" title="' . $item['coord'] 
				. '" href="http://maps.google.com/?q=' . urlencode($item['coord']) . '">' . $item['coord'] . '</a>' : '');
			if($coord) {
				if($location)
					$location .= '<br /><span class="smalltext">(' . $coord . ')</span>';
				else
					$location = '<span class="smalltext">' . $coord . '</span>';
			}

			$indent = (($toplevelpost) ? '' : ' comment');

			if(strcmp(datetime_convert('UTC','UTC',$item['created']),datetime_convert('UTC','UTC','now - 12 hours')) > 0)
				$indent .= ' shiny'; 

			// 
			localize_item($item);

			// Build the HTML

			$tmp_item = replace_macros($template,array(
				'$id' => $item['item_id'],
				'$linktitle' => sprintf( t('View %s\'s profile'), $profile_name),
				'$olinktitle' => sprintf( t('View %s\'s profile'), $owner_name),
				'$to' => t('to'),
				'$wall' => t('Wall-to-Wall'),
				'$vwall' => t('via Wall-To-Wall:'),
				'$profile_url' => $profile_link,
				'$item_photo_menu' => item_photo_menu($item),
				'$name' => $profile_name,
				'$thumb' => $profile_avatar,
				'$osparkle' => $osparkle,
				'$sparkle' => $sparkle,
				'$title' => $item['title'],
				'$body' => smilies(bbcode($item['body'])),
				'$ago' => relative_date($item['created']),
				'$lock' => $lock,
				'$location' => $location,
				'$indent' => $indent,
				'$owner_url' => $owner_url,
				'$owner_photo' => $owner_photo,
				'$owner_name' => $owner_name,
				'$plink' => get_plink($item),
				'$edpost' => $edpost,
				'$drop' => $drop,
				'$vote' => $likebuttons,
				'$like' => $like,
				'$dislike' => $dislike,
				'$comment' => $comment
			));

			$arr = array('item' => $item, 'output' => $tmp_item);
			call_hooks('display_item', $arr);

			$o .= $arr['output'];

		}
	}


	// if author collapsing is in force but didn't get closed, close it off now.

	if($blowhard_count >= 3)
		$o .= '</div>';

	return $o;
} 




if(! function_exists('extract_item_authors')) {
function extract_item_authors($arr,$uid) {

	if((! $uid) || (! is_array($arr)) || (! count($arr)))
		return array();
	$urls = array();
	foreach($arr as $rr) {
		if(! in_array("'" . dbesc($rr['author-link']) . "'",$urls))
			$urls[] = "'" . dbesc($rr['author-link']) . "'";
	}

	// pre-quoted, don't put quotes on %s
	if(count($urls)) {
		$r = q("SELECT `id`,`network`,`url` FROM `contact` WHERE `uid` = %d AND `url` IN ( %s )  AND `self` = 0 AND `blocked` = 0 ",
			intval($uid),
			implode(',',$urls)
		);
		if(count($r)) {
			$ret = array();
			$authors = array();
			foreach($r as $rr){
				if ($rr['network']=='dfrn')
					$ret[$rr['url']] = $rr['id'];
				$authors[$r['url']]= $rr;
			}
			$a->authors = $authors;
			return $ret;
		}
	}
	return array();		
}}

if(! function_exists('item_photo_menu')){
function item_photo_menu($item){
	$a = get_app();
	
	if (!isset($a->authors)){
		$rr = q("SELECT `id`, `network`, `url` FROM `contact` WHERE `uid`=%d AND `self`=0 AND `blocked`=0 ", intval(local_user()));
		$authors = array();
		foreach($rr as $r) $authors[$r['url']]= $r;
		$a->authors = $authors;
	}
	
	$contact_url="";
	$pm_url="";

	$status_link="";
	$photos_link="";
	$posts_link="";
	$profile_link   = ((strlen($item['author-link']))   ? $item['author-link'] : $item['url']);
	$redirect_url = $a->get_baseurl() . '/redir/' . $item['cid'] ;

	if($profile_link === 'mailbox')
		$profile_link = '';

	// $item['contact-uid'] is only set on profile page and indicates the uid of the user who owns the profile.

	$profile_owner = ((x($item,'contact-uid')) && intval($item['contact-uid']) ? intval($item['contact-uid']) : 0);	

	// So we are checking that this is a logged in user on some page that *isn't* a profile page
	// OR a profile page where the viewer owns the profile. 
	// Then check if we can use a sparkle (redirect) link to the profile by virtue of it being our contact
	// or a friend's contact that we both have a connection to. 

	if((local_user() && ($profile_owner == 0)) 
		|| ($profile_owner && $profile_owner == local_user())) {

		if(strlen($item['author-link']) && link_compare($item['author-link'],$item['url'])) {
			$redir = $redirect_url;
			$cid = $item['cid'];
		}
		elseif(isset($a->authors[$item['author-link']])) {
			$redir = $a->get_baseurl() . '/redir/' . $a->authors[$item['author-link']]['id'];
			$cid = $a->authors[$item['author-link']]['id'];
		}
		if($item['author-link'] === 'mailbox')
			$cid = $item['cid'];

		if((isset($cid)) && (! $item['self'])) {
			$contact_url = $a->get_baseurl() . '/contacts/' . $cid;
			$posts_link = $a->get_baseurl() . '/network/?cid=' . $cid;
			if($item['network'] === 'dfrn') {
				$status_link = $redir . "?url=status";
				$profile_link = $redir . "?url=profile";
				$photos_link = $redir . "?url=photos";
				$pm_url = $a->get_baseurl() . '/message/new/' . $cid;
			}
		}
	}


	$menu = Array(
		t("View status") => $status_link,
		t("View profile") => $profile_link,
		t("View photos") => $photos_link,		
		t("View recent") => $posts_link, 
		t("Edit contact") => $contact_url,
		t("Send PM") => $pm_url,
	);
	
	
	$args = array($item, &$menu);
	
	call_hooks('item_photo_menu', $args);
	
	$o = "";
	foreach($menu as $k=>$v){
		if ($v!="") $o .= "<li><a href='$v'>$k</a></li>\n";
	}
	return $o;
}}

if(! function_exists('like_puller')) {
function like_puller($a,$item,&$arr,$mode) {

	$url = '';
	$sparkle = '';
	$verb = (($mode === 'like') ? ACTIVITY_LIKE : ACTIVITY_DISLIKE);

	if((activity_match($item['verb'],$verb)) && ($item['id'] != $item['parent'])) {
		$url = $item['author-link'];
		if((local_user()) && (local_user() == $item['uid']) && ($item['network'] === 'dfrn') && (! $item['self']) && (link_compare($item['author-link'],$item['url']))) {
			$url = $a->get_baseurl() . '/redir/' . $item['contact-id'];
			$sparkle = ' class="sparkle" ';
		}
		if(! ((isset($arr[$item['parent'] . '-l'])) && (is_array($arr[$item['parent'] . '-l']))))
			$arr[$item['parent'] . '-l'] = array();
		if(! isset($arr[$item['parent']]))
			$arr[$item['parent']] = 1;
		else	
			$arr[$item['parent']] ++;
		$arr[$item['parent'] . '-l'][] = '<a href="'. $url . '"'. $sparkle .'>' . $item['author-name'] . '</a>';
	}
	return;
}}

// Format the like/dislike text for a profile item
// $cnt = number of people who like/dislike the item
// $arr = array of pre-linked names of likers/dislikers
// $type = one of 'like, 'dislike'
// $id  = item id
// returns formatted text

if(! function_exists('format_like')) {
function format_like($cnt,$arr,$type,$id) {
	$o = '';
	if($cnt == 1)
		$o .= (($type === 'like') ? sprintf( t('%s likes this.'), $arr[0]) : sprintf( t('%s doesn\'t like this.'), $arr[0])) . EOL ;
	else {
		$spanatts = 'class="fakelink" onclick="openClose(\'' . $type . 'list-' . $id . '\');"';
		$o .= (($type === 'like') ? 
					sprintf( t('<span  %1$s>%2$d people</span> like this.'), $spanatts, $cnt)
					 : 
					sprintf( t('<span  %1$s>%2$d people</span> don\'t like this.'), $spanatts, $cnt) ); 
		$o .= EOL ;
		$total = count($arr);
		if($total >= MAX_LIKERS)
			$arr = array_slice($arr, 0, MAX_LIKERS - 1);
		if($total < MAX_LIKERS)
			$arr[count($arr)-1] = t('and') . ' ' . $arr[count($arr)-1];
		$str = implode(', ', $arr);
		if($total >= MAX_LIKERS)
			$str .= sprintf( t(', and %d other people'), $total - MAX_LIKERS );
		$str = (($type === 'like') ? sprintf( t('%s like this.'), $str) : sprintf( t('%s don\'t like this.'), $str));
		$o .= "\t" . '<div id="' . $type . 'list-' . $id . '" style="display: none;" >' . $str . '</div>';
	}
	return $o;
}}


function status_editor($a,$x) {

	$o = '';
		
	$geotag = (($x['allow_location']) ? load_view_file('view/jot_geotag.tpl') : '');

		$tpl = load_view_file('view/jot-header.tpl');
	
		$a->page['htmlhead'] .= replace_macros($tpl, array(
			'$baseurl' => $a->get_baseurl(),
			'$geotag' => $geotag,
			'$nickname' => $x['nickname'],
			'$linkurl' => t('Please enter a link URL:'),
			'$utubeurl' => t('Please enter a YouTube link:'),
			'$vidurl' => t("Please enter a video\x28.ogg\x29 link/URL:"),
			'$audurl' => t("Please enter an audio\x28.ogg\x29 link/URL:"),
			'$whereareu' => t('Where are you right now?'),
			'$title' => t('Enter a title for this item') 
		));


		$tpl = load_view_file("view/jot.tpl");
		
		$jotplugins = '';
		$jotnets = '';

		$mail_disabled = ((function_exists('imap_open') && (! get_config('system','imap_disabled'))) ? 0 : 1);

		$mail_enabled = false;
		$pubmail_enabled = false;

		if(($x['is_owner']) && (! $mail_disabled)) {
			$r = q("SELECT * FROM `mailacct` WHERE `uid` = %d AND `server` != '' LIMIT 1",
				intval(local_user())
			);
			if(count($r)) {
				$mail_enabled = true;
				if(intval($r[0]['pubmail']))
					$pubmail_enabled = true;
			}
		}

		if($mail_enabled) {
	       $selected = (($pubmail_enabled) ? ' checked="checked" ' : '');
			$jotnets .= '<div class="profile-jot-net"><input type="checkbox" name="pubmail_enable"' . $selected . 'value="1" /> '
           	. t("Post to Email") . '</div>';
		}

		call_hooks('jot_tool', $jotplugins);
		call_hooks('jot_networks', $jotnets);

		$tpl = replace_macros($tpl,array('$jotplugins' => $jotplugins));	

		$o .= replace_macros($tpl,array(
			'$return_path' => $a->cmd,
			'$action' => 'item',
			'$share' => t('Share'),
			'$upload' => t('Upload photo'),
			'$weblink' => t('Insert web link'),
			'$youtube' => t('Insert YouTube video'),
			'$video' => t('Insert Vorbis [.ogg] video'),
			'$audio' => t('Insert Vorbis [.ogg] audio'),
			'$setloc' => t('Set your location'),
			'$noloc' => t('Clear browser location'),
			'$title' => t('Set title'),
			'$wait' => t('Please wait'),
			'$permset' => t('Permission settings'),
			'$content' => '',
			'$post_id' => '',
			'$baseurl' => $a->get_baseurl(),
			'$defloc' => $x['default-location'],
			'$visitor' => $x['visitor'],
			'$emailcc' => t('CC: email addresses'),
			'$jotnets' => $jotnets,
			'$emtitle' => t('Example: bob@example.com, mary@example.com'),
			'$lockstate' => $x['lockstate'],
			'$acl' => $x['acl'],
			'$bang' => $x['bang'],
			'$profile_uid' => $x['profile_uid'],
		));

	return $o;
}