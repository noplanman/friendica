<?php
/**
 * @file include/identity.php
 */

use Friendica\App;
use Friendica\Core\Cache;
use Friendica\Core\Config;
use Friendica\Core\PConfig;
use Friendica\Core\System;
use Friendica\Core\Worker;
use Friendica\Database\DBM;

require_once 'include/ForumManager.php';
require_once 'include/bbcode.php';
require_once 'mod/proxy.php';

/**
 *
 * @brief Loads a profile into the page sidebar.
 *
 * The function requires a writeable copy of the main App structure, and the nickname
 * of a registered local account.
 *
 * If the viewer is an authenticated remote viewer, the profile displayed is the
 * one that has been configured for his/her viewing in the Contact manager.
 * Passing a non-zero profile ID can also allow a preview of a selected profile
 * by the owner.
 *
 * Profile information is placed in the App structure for later retrieval.
 * Honours the owner's chosen theme for display.
 *
 * @attention Should only be run in the _init() functions of a module. That ensures that
 *      the theme is chosen before the _init() function of a theme is run, which will usually
 *      load a lot of theme-specific content
 *
 * @param object $a           App
 * @param string $nickname    string
 * @param int    $profile     int
 * @param array  $profiledata array
 */
function profile_load(App $a, $nickname, $profile = 0, $profiledata = array())
{
	$user = q(
		"SELECT `uid` FROM `user` WHERE `nickname` = '%s' LIMIT 1",
		dbesc($nickname)
	);

	if (!$user && count($user) && !count($profiledata)) {
		logger('profile error: ' . $a->query_string, LOGGER_DEBUG);
		notice(t('Requested account is not available.') . EOL);
		$a->error = 404;
		return;
	}

	$pdata = get_profiledata_by_nick($nickname, $user[0]['uid'], $profile);

	if (empty($pdata) && empty($profiledata)) {
		logger('profile error: ' . $a->query_string, LOGGER_DEBUG);
		notice(t('Requested profile is not available.') . EOL);
		$a->error = 404;
		return;
	}

	// fetch user tags if this isn't the default profile

	if (!$pdata['is-default']) {
		$x = q(
			"SELECT `pub_keywords` FROM `profile` WHERE `uid` = %d AND `is-default` = 1 LIMIT 1",
			intval($pdata['profile_uid'])
		);
		if ($x && count($x))
			$pdata['pub_keywords'] = $x[0]['pub_keywords'];
	}

	$a->profile = $pdata;
	$a->profile_uid = $pdata['profile_uid'];

	$a->profile['mobile-theme'] = PConfig::get($a->profile['profile_uid'], 'system', 'mobile_theme');
	$a->profile['network'] = NETWORK_DFRN;

	$a->page['title'] = $a->profile['name'] . " @ " . $a->config['sitename'];

	if (!$profiledata  && !PConfig::get(local_user(), 'system', 'always_my_theme')) {
		$_SESSION['theme'] = $a->profile['theme'];
	}

	$_SESSION['mobile-theme'] = $a->profile['mobile-theme'];

	/*
	 * load/reload current theme info
	 */

	$a->set_template_engine(); // reset the template engine to the default in case the user's theme doesn't specify one

	$theme_info_file = "view/theme/" . current_theme() . "/theme.php";
	if (file_exists($theme_info_file)) {
		require_once $theme_info_file;
	}

	if (! (x($a->page, 'aside'))) {
		$a->page['aside'] = '';
	}

	if (local_user() && local_user() == $a->profile['uid'] && $profiledata) {
		$a->page['aside'] .= replace_macros(
			get_markup_template('profile_edlink.tpl'),
			array(
				'$editprofile' => t('Edit profile'),
				'$profid' => $a->profile['id']
			)
		);
	}

	$block = (((Config::get('system', 'block_public')) && (! local_user()) && (! remote_user())) ? true : false);

	/**
	 * @todo
	 * By now, the contact block isn't shown, when a different profile is given
	 * But: When this profile was on the same server, then we could display the contacts
	 */
	if ($profiledata) {
		$a->page['aside'] .= profile_sidebar($profiledata, true);
	} else {
		$a->page['aside'] .= profile_sidebar($a->profile, $block);
	}

	/*if (! $block)
	 $a->page['aside'] .= contact_block();*/

	return;
}


/**
 * @brief Get all profil data of a local user
 *
 * If the viewer is an authenticated remote viewer, the profile displayed is the
 * one that has been configured for his/her viewing in the Contact manager.
 * Passing a non-zero profile ID can also allow a preview of a selected profile
 * by the owner
 *
 * @param string $nickname nick
 * @param int    $uid      uid
 * @param int    $profile  ID of the profile
 * @returns array
 *	Includes all available profile data
 */
function get_profiledata_by_nick($nickname, $uid = 0, $profile = 0)
{
	if (remote_user() && count($_SESSION['remote'])) {
		foreach ($_SESSION['remote'] as $visitor) {
			if ($visitor['uid'] == $uid) {
				$r = dba::select('contact', array('profile-id'), array('id' => $visitor['cid']), array('limit' => 1));
				if (DBM::is_result($r)) {
					$profile = $r['profile-id'];
				}
				break;
			}
		}
	}

	$r = null;

	if ($profile) {
		$profile_int = intval($profile);
		$r = dba::fetch_first(
			"SELECT `contact`.`id` AS `contact_id`, `contact`.`photo` AS `contact_photo`,
				`contact`.`thumb` AS `contact_thumb`, `contact`.`micro` AS `contact_micro`,
				`profile`.`uid` AS `profile_uid`, `profile`.*,
				`contact`.`avatar-date` AS picdate, `contact`.`addr`, `user`.*
			FROM `profile`
			INNER JOIN `contact` on `contact`.`uid` = `profile`.`uid` AND `contact`.`self`
			INNER JOIN `user` ON `profile`.`uid` = `user`.`uid`
			WHERE `user`.`nickname` = ? AND `profile`.`id` = ? LIMIT 1",
			$nickname,
			$profile_int
		);
	}
	if (!DBM::is_result($r)) {
		$r = dba::fetch_first(
			"SELECT `contact`.`id` AS `contact_id`, `contact`.`photo` as `contact_photo`,
				`contact`.`thumb` AS `contact_thumb`, `contact`.`micro` AS `contact_micro`,
				`profile`.`uid` AS `profile_uid`, `profile`.*,
				`contact`.`avatar-date` AS picdate, `contact`.`addr`, `user`.*
			FROM `profile`
			INNER JOIN `contact` ON `contact`.`uid` = `profile`.`uid` AND `contact`.`self`
			INNER JOIN `user` ON `profile`.`uid` = `user`.`uid`
			WHERE `user`.`nickname` = ? AND `profile`.`is-default` LIMIT 1",
			$nickname
		);
	}

	return $r;
}


/**
 * @brief Formats a profile for display in the sidebar.
 *
 * It is very difficult to templatise the HTML completely
 * because of all the conditional logic.
 *
 * @param array $profile
 * @param int $block
 *
 * @return HTML string stuitable for sidebar inclusion
 *
 * @note Returns empty string if passed $profile is wrong type or not populated
 *
 * @hooks 'profile_sidebar_enter'
 *      array $profile - profile data
 * @hooks 'profile_sidebar'
 *      array $arr
 */
function profile_sidebar($profile, $block = 0)
{
	$a = get_app();

	$o = '';
	$location = false;
	$address = false;
	// $pdesc = true;

	// This function can also use contact information in $profile
	$is_contact = x($profile, 'cid');

	if ((! is_array($profile)) && (! count($profile))) {
		return $o;
	}

	$profile['picdate'] = urlencode($profile['picdate']);

	if (($profile['network'] != "") && ($profile['network'] != NETWORK_DFRN)) {
		$profile['network_name'] = format_network_name($profile['network'], $profile['url']);
	} else {
		$profile['network_name'] = "";
	}

	call_hooks('profile_sidebar_enter', $profile);


	// don't show connect link to yourself
	$connect = (($profile['uid'] != local_user()) ? t('Connect')  : false);

	// don't show connect link to authenticated visitors either
	if (remote_user() && count($_SESSION['remote'])) {
		foreach ($_SESSION['remote'] as $visitor) {
			if ($visitor['uid'] == $profile['uid']) {
				$connect = false;
				break;
			}
		}
	}

	// Is the local user already connected to that user?
	if ($connect && local_user()) {
		if (isset($profile["url"])) {
			$profile_url = normalise_link($profile["url"]);
		} else {
			$profile_url = normalise_link(System::baseUrl()."/profile/".$profile["nickname"]);
		}

		if (dba::exists('contact', array('pending' => false, 'uid' => local_user(), 'nurl' => $profile_url))) {
			$connect = false;
		}
	}

	if ($connect && ($profile['network'] != NETWORK_DFRN) && !isset($profile['remoteconnect']))
		$connect = false;

	$remoteconnect = null;
	if (isset($profile['remoteconnect']))
		$remoteconnect = $profile['remoteconnect'];

	if ($connect && ($profile['network'] == NETWORK_DFRN) && !isset($remoteconnect))
		$subscribe_feed = t("Atom feed");
	else
		$subscribe_feed = false;

	if (remote_user() || (get_my_url() && $profile['unkmail'] && ($profile['uid'] != local_user()))) {
		$wallmessage = t('Message');
		$wallmessage_link = "wallmessage/".$profile["nickname"];

		if (remote_user()) {
			$r = q(
				"SELECT `url` FROM `contact` WHERE `uid` = %d AND `id` = '%s' AND `rel` = %d",
				intval($profile['uid']),
				intval(remote_user()),
				intval(CONTACT_IS_FRIEND)
			);
		} else {
			$r = q(
				"SELECT `url` FROM `contact` WHERE `uid` = %d AND `nurl` = '%s' AND `rel` = %d",
				intval($profile['uid']),
				dbesc(normalise_link(get_my_url())),
				intval(CONTACT_IS_FRIEND)
			);
		}
		if ($r) {
			$remote_url = $r[0]["url"];
			$message_path = preg_replace("=(.*)/profile/(.*)=ism", "$1/message/new/", $remote_url);
			$wallmessage_link = $message_path.base64_encode($profile["addr"]);
		}
	} else {
		$wallmessage = false;
		$wallmessage_link = false;
	}

	// show edit profile to yourself
	if (!$is_contact && $profile['uid'] == local_user() && feature_enabled(local_user(), 'multi_profiles')) {
		$profile['edit'] = array(System::baseUrl(). '/profiles', t('Profiles'),"", t('Manage/edit profiles'));
		$r = q(
			"SELECT * FROM `profile` WHERE `uid` = %d",
			local_user()
		);

		$profile['menu'] = array(
			'chg_photo' => t('Change profile photo'),
			'cr_new' => t('Create New Profile'),
			'entries' => array(),
		);

		if (DBM::is_result($r)) {
			foreach ($r as $rr) {
				$profile['menu']['entries'][] = array(
					'photo' => $rr['thumb'],
					'id' => $rr['id'],
					'alt' => t('Profile Image'),
					'profile_name' => $rr['profile-name'],
					'isdefault' => $rr['is-default'],
					'visibile_to_everybody' =>  t('visible to everybody'),
					'edit_visibility' => t('Edit visibility'),
				);
			}
		}
	}
	if (!$is_contact && $profile['uid'] == local_user() && !feature_enabled(local_user(), 'multi_profiles')) {
		$profile['edit'] = array(System::baseUrl(). '/profiles/'.$profile['id'], t('Edit profile'),"", t('Edit profile'));
		$profile['menu'] = array(
			'chg_photo' => t('Change profile photo'),
			'cr_new' => null,
			'entries' => array(),
		);
	}

	// Fetch the account type
	$account_type = account_type($profile);

	if ((x($profile, 'address') == 1)
		|| (x($profile, 'location') == 1)
		|| (x($profile, 'locality') == 1)
		|| (x($profile, 'region') == 1)
		|| (x($profile, 'postal-code') == 1)
		|| (x($profile, 'country-name') == 1)
	) {
		$location = t('Location:');
	}

	$gender = ((x($profile, 'gender') == 1) ? t('Gender:') : false);


	$marital = ((x($profile, 'marital') == 1) ?  t('Status:') : false);

	$homepage = ((x($profile, 'homepage') == 1) ?  t('Homepage:') : false);

	$about = ((x($profile, 'about') == 1) ?  t('About:') : false);

	$xmpp = ((x($profile, 'xmpp') == 1) ?  t('XMPP:') : false);

	if (($profile['hidewall'] || $block) && (! local_user()) && (! remote_user())) {
		$location = $pdesc = $gender = $marital = $homepage = $about = false;
	}

	$firstname = ((strpos($profile['name'], ' '))
			? trim(substr($profile['name'], 0, strpos($profile['name'], ' '))) : $profile['name']);
	$lastname = (($firstname === $profile['name']) ? '' : trim(substr($profile['name'], strlen($firstname))));

	if ($profile['guid'] != "") {
		$diaspora = array(
			'guid' => $profile['guid'],
			'podloc' => System::baseUrl(),
			'searchable' => (($profile['publish'] && $profile['net-publish']) ? 'true' : 'false' ),
			'nickname' => $profile['nickname'],
			'fullname' => $profile['name'],
			'firstname' => $firstname,
			'lastname' => $lastname,
			'photo300' => $profile['contact_photo'],
			'photo100' => $profile['contact_thumb'],
			'photo50' => $profile['contact_micro'],
		);
	} else {
		$diaspora = false;
	}

	if (!$block) {
		$contact_block = contact_block();

		if (is_array($a->profile) && !$a->profile['hide-friends']) {
			$r = q(
				"SELECT `gcontact`.`updated` FROM `contact` INNER JOIN `gcontact` WHERE `gcontact`.`nurl` = `contact`.`nurl` AND `self` AND `uid` = %d LIMIT 1",
				intval($a->profile['uid'])
			);
			if (DBM::is_result($r)) {
				$updated =  date("c", strtotime($r[0]['updated']));
			}

			$r = q(
				"SELECT COUNT(*) AS `total` FROM `contact`
				WHERE `uid` = %d
					AND NOT `self` AND NOT `blocked` AND NOT `pending`
					AND NOT `hidden` AND NOT `archive`
					AND `network` IN ('%s', '%s', '%s', '')",
				intval($profile['uid']),
				dbesc(NETWORK_DFRN),
				dbesc(NETWORK_DIASPORA),
				dbesc(NETWORK_OSTATUS)
			);
			if (DBM::is_result($r)) {
				$contacts = intval($r[0]['total']);
			}
		}
	}

	$p = array();
	foreach ($profile as $k => $v) {
		$k = str_replace('-', '_', $k);
		$p[$k] = $v;
	}

	if (isset($p["about"])) {
		$p["about"] = bbcode($p["about"]);
	}

	if (isset($p["address"])) {
		$p["address"] = bbcode($p["address"]);
	} else {
		$p["address"] = bbcode($p["location"]);
	}

	if (isset($p["photo"])) {
		$p["photo"] = proxy_url($p["photo"], false, PROXY_SIZE_SMALL);
	}

	if ($a->theme['template_engine'] === 'internal') {
		$location = template_escape($location);
	}

	$tpl = get_markup_template('profile_vcard.tpl');
	$o .= replace_macros(
		$tpl,
		array(
		'$profile' => $p,
		'$xmpp' => $xmpp,
		'$connect'  => $connect,
		'$remoteconnect'  => $remoteconnect,
		'$subscribe_feed' => $subscribe_feed,
		'$wallmessage' => $wallmessage,
		'$wallmessage_link' => $wallmessage_link,
		'$account_type' => $account_type,
		'$location' => $location,
		'$gender'   => $gender,
		// '$pdesc'	=> $pdesc,
		'$marital'  => $marital,
		'$homepage' => $homepage,
		'$about' => $about,
		'$network' =>  t('Network:'),
		'$contacts' => $contacts,
		'$updated' => $updated,
		'$diaspora' => $diaspora,
		'$contact_block' => $contact_block,
		)
	);

	$arr = array('profile' => &$profile, 'entry' => &$o);

	call_hooks('profile_sidebar', $arr);

	return $o;
}


function get_birthdays()
{
	$a = get_app();
	$o = '';

	if (! local_user() || $a->is_mobile || $a->is_tablet) {
		return $o;
	}

	/*
	 * $mobile_detect = new Mobile_Detect();
	 * $is_mobile = $mobile_detect->isMobile() || $mobile_detect->isTablet();
	 *		if ($is_mobile)
	 *			return $o;
	 */

	$bd_format = t('g A l F d'); // 8 AM Friday January 18
	$bd_short = t('F d');

	$cachekey = "get_birthdays:".local_user();
	$r = Cache::get($cachekey);
	if (is_null($r)) {
		$s = dba::p(
			"SELECT `event`.*, `event`.`id` AS `eid`, `contact`.* FROM `event`
			INNER JOIN `contact` ON `contact`.`id` = `event`.`cid`
			WHERE `event`.`uid` = ? AND `type` = 'birthday' AND `start` < ? AND `finish` > ?
			ORDER BY `start` ASC ",
			local_user(),
			datetime_convert('UTC', 'UTC', 'now + 6 days'),
			datetime_convert('UTC', 'UTC', 'now')
		);
		if (DBM::is_result($s)) {
			$r = dba::inArray($s);
			Cache::set($cachekey, $r, CACHE_HOUR);
		}
	}
	if (DBM::is_result($r)) {
		$total = 0;
		$now = strtotime('now');
		$cids = array();

		$istoday = false;
		foreach ($r as $rr) {
			if (strlen($rr['name'])) {
				$total ++;
			}
			if ((strtotime($rr['start'] . ' +00:00') < $now) && (strtotime($rr['finish'] . ' +00:00') > $now)) {
				$istoday = true;
			}
		}
		$classtoday = $istoday ? ' birthday-today ' : '';
		if ($total) {
			foreach ($r as &$rr) {
				if (! strlen($rr['name'])) {
					continue;
				}

				// avoid duplicates

				if (in_array($rr['cid'], $cids)) {
					continue;
				}
				$cids[] = $rr['cid'];

				$today = (((strtotime($rr['start'] . ' +00:00') < $now) && (strtotime($rr['finish'] . ' +00:00') > $now)) ? true : false);
				$sparkle = '';
				$url = $rr['url'];
				if ($rr['network'] === NETWORK_DFRN) {
					$sparkle = " sparkle";
					$url = System::baseUrl() . '/redir/'  . $rr['cid'];
				}

				$rr['link'] = $url;
				$rr['title'] = $rr['name'];
				$rr['date'] = day_translate(datetime_convert('UTC', $a->timezone, $rr['start'], $rr['adjust'] ? $bd_format : $bd_short)) . (($today) ?  ' ' . t('[today]') : '');
				$rr['startime'] = null;
				$rr['today'] = $today;
			}
		}
	}
	$tpl = get_markup_template("birthdays_reminder.tpl");
	return replace_macros(
		$tpl,
		array(
		'$baseurl' => System::baseUrl(),
		'$classtoday' => $classtoday,
		'$count' => $total,
		'$event_reminders' => t('Birthday Reminders'),
		'$event_title' => t('Birthdays this week:'),
		'$events' => $r,
		'$lbr' => '{',  // raw brackets mess up if/endif macro processing
		'$rbr' => '}'
		)
	);
}


function get_events()
{
	require_once 'include/bbcode.php';

	$a = get_app();

	if (! local_user() || $a->is_mobile || $a->is_tablet) {
		return $o;
	}

	/*
	 *	$mobile_detect = new Mobile_Detect();
	 *		$is_mobile = $mobile_detect->isMobile() || $mobile_detect->isTablet();
	 *		if ($is_mobile)
	 *			return $o;
	 */

	$bd_format = t('g A l F d'); // 8 AM Friday January 18
	$bd_short = t('F d');

	$s = dba::p(
		"SELECT `event`.* FROM `event`
		WHERE `event`.`uid` = ? AND `type` != 'birthday' AND `start` < ? AND `start` >= ?
		ORDER BY `start` ASC ",
		local_user(),
		datetime_convert('UTC', 'UTC', 'now + 7 days'),
		datetime_convert('UTC', 'UTC', 'now - 1 days')
	);

	$r = array();

	if (DBM::is_result($s)) {
		$now = strtotime('now');
		$istoday = false;

		while ($rr = dba::fetch($s)) {
			if (strlen($rr['name'])) {
				$total ++;
			}

			$strt = datetime_convert('UTC', $rr['convert'] ? $a->timezone : 'UTC', $rr['start'], 'Y-m-d');
			if ($strt === datetime_convert('UTC', $a->timezone, 'now', 'Y-m-d')) {
				$istoday = true;
			}

			$title = strip_tags(html_entity_decode(bbcode($rr['summary']), ENT_QUOTES, 'UTF-8'));

			if (strlen($title) > 35) {
				$title = substr($title, 0, 32) . '... ';
			}

			$description = substr(strip_tags(bbcode($rr['desc'])), 0, 32) . '... ';
			if (! $description) {
				$description = t('[No description]');
			}

			$strt = datetime_convert('UTC', $rr['convert'] ? $a->timezone : 'UTC', $rr['start']);

			if (substr($strt, 0, 10) < datetime_convert('UTC', $a->timezone, 'now', 'Y-m-d')) {
				continue;
			}

			$today = ((substr($strt, 0, 10) === datetime_convert('UTC', $a->timezone, 'now', 'Y-m-d')) ? true : false);

			$rr['title'] = $title;
			$rr['description'] = $desciption;
			$rr['date'] = day_translate(datetime_convert('UTC', $rr['adjust'] ? $a->timezone : 'UTC', $rr['start'], $bd_format)) . (($today) ?  ' ' . t('[today]') : '');
			$rr['startime'] = $strt;
			$rr['today'] = $today;

			$r[] = $rr;
		}
		dba::close($s);
		$classtoday = (($istoday) ? 'event-today' : '');
	}
	$tpl = get_markup_template("events_reminder.tpl");
	return replace_macros(
		$tpl,
		array(
		'$baseurl' => System::baseUrl(),
		'$classtoday' => $classtoday,
		'$count' => count($r),
		'$event_reminders' => t('Event Reminders'),
		'$event_title' => t('Events this week:'),
		'$events' => $r,
		)
	);
}

function advanced_profile(App $a)
{
	$o = '';
	$uid = $a->profile['uid'];

	$o .= replace_macros(
		get_markup_template('section_title.tpl'),
		array(
		'$title' => t('Profile')
		)
	);

	if ($a->profile['name']) {
		$tpl = get_markup_template('profile_advanced.tpl');

		$profile = array();

		$profile['fullname'] = array( t('Full Name:'), $a->profile['name'] ) ;

		if ($a->profile['gender']) {
			$profile['gender'] = array( t('Gender:'),  $a->profile['gender'] );
		}

		if (($a->profile['dob']) && ($a->profile['dob'] > '0001-01-01')) {
			$year_bd_format = t('j F, Y');
			$short_bd_format = t('j F');


			$val = ((intval($a->profile['dob']))
				? day_translate(datetime_convert('UTC', 'UTC', $a->profile['dob'] . ' 00:00 +00:00', $year_bd_format))
				: day_translate(datetime_convert('UTC', 'UTC', '2001-' . substr($a->profile['dob'], 5) . ' 00:00 +00:00', $short_bd_format)));

			$profile['birthday'] = array( t('Birthday:'), $val);
		}
		if (!empty($a->profile['dob'])
			&& $a->profile['dob'] > '0001-01-01'
			&& $age = age($a->profile['dob'], $a->profile['timezone'], '')
		) {
			$profile['age'] = array( t('Age:'), $age );
		}

		if ($a->profile['marital']) {
			$profile['marital'] = array( t('Status:'), $a->profile['marital']);
		}

		/// @TODO Maybe use x() here, plus below?
		if ($a->profile['with']) {
			$profile['marital']['with'] = $a->profile['with'];
		}

		if (strlen($a->profile['howlong']) && $a->profile['howlong'] >= NULL_DATE) {
			$profile['howlong'] = relative_date($a->profile['howlong'], t('for %1$d %2$s'));
		}

		if ($a->profile['sexual']) {
			$profile['sexual'] = array( t('Sexual Preference:'), $a->profile['sexual'] );
		}

		if ($a->profile['homepage']) {
			$profile['homepage'] = array( t('Homepage:'), linkify($a->profile['homepage']) );
		}

		if ($a->profile['hometown']) {
			$profile['hometown'] = array( t('Hometown:'), linkify($a->profile['hometown']) );
		}

		if ($a->profile['pub_keywords']) {
			$profile['pub_keywords'] = array( t('Tags:'), $a->profile['pub_keywords']);
		}

		if ($a->profile['politic']) {
			$profile['politic'] = array( t('Political Views:'), $a->profile['politic']);
		}

		if ($a->profile['religion']) {
			$profile['religion'] = array( t('Religion:'), $a->profile['religion']);
		}

		if ($txt = prepare_text($a->profile['about'])) {
			$profile['about'] = array( t('About:'), $txt );
		}

		if ($txt = prepare_text($a->profile['interest'])) {
			$profile['interest'] = array( t('Hobbies/Interests:'), $txt);
		}

		if ($txt = prepare_text($a->profile['likes'])) {
			$profile['likes'] = array( t('Likes:'), $txt);
		}

		if ($txt = prepare_text($a->profile['dislikes'])) {
			$profile['dislikes'] = array( t('Dislikes:'), $txt);
		}

		if ($txt = prepare_text($a->profile['contact'])) {
			$profile['contact'] = array( t('Contact information and Social Networks:'), $txt);
		}

		if ($txt = prepare_text($a->profile['music'])) {
			$profile['music'] = array( t('Musical interests:'), $txt);
		}

		if ($txt = prepare_text($a->profile['book'])) {
			$profile['book'] = array( t('Books, literature:'), $txt);
		}

		if ($txt = prepare_text($a->profile['tv'])) {
			$profile['tv'] = array( t('Television:'), $txt);
		}

		if ($txt = prepare_text($a->profile['film'])) {
			$profile['film'] = array( t('Film/dance/culture/entertainment:'), $txt);
		}

		if ($txt = prepare_text($a->profile['romance'])) {
			$profile['romance'] = array( t('Love/Romance:'), $txt);
		}

		if ($txt = prepare_text($a->profile['work'])) {
			$profile['work'] = array( t('Work/employment:'), $txt);
		}

		if ($txt = prepare_text($a->profile['education'])) {
			$profile['education'] = array( t('School/education:'), $txt );
		}

		//show subcribed forum if it is enabled in the usersettings
		if (feature_enabled($uid, 'forumlist_profile')) {
			$profile['forumlist'] = array( t('Forums:'), ForumManager::profile_advanced($uid));
		}

		if ($a->profile['uid'] == local_user()) {
			$profile['edit'] = array(System::baseUrl(). '/profiles/'.$a->profile['id'], t('Edit profile'),"", t('Edit profile'));
		}

		return replace_macros(
			$tpl,
			array(
			'$title' => t('Profile'),
			'$basic' => t('Basic'),
			'$advanced' => t('Advanced'),
			'$profile' => $profile
			)
		);
	}

	return '';
}

function profile_tabs($a, $is_owner = false, $nickname = null)
{
	//echo "<pre>"; var_dump($a->user); killme();

	if (is_null($nickname)) {
		$nickname  = $a->user['nickname'];
	}

	if (x($_GET, 'tab')) {
		$tab = notags(trim($_GET['tab']));
	}

	$url = System::baseUrl() . '/profile/' . $nickname;

	$tabs = array(
		array(
			'label'=>t('Status'),
			'url' => $url,
			'sel' => ((!isset($tab) && $a->argv[0]=='profile') ? 'active' : ''),
			'title' => t('Status Messages and Posts'),
			'id' => 'status-tab',
			'accesskey' => 'm',
		),
		array(
			'label' => t('Profile'),
			'url' 	=> $url.'/?tab=profile',
			'sel'	=> ((isset($tab) && $tab=='profile') ? 'active' : ''),
			'title' => t('Profile Details'),
			'id' => 'profile-tab',
			'accesskey' => 'r',
		),
		array(
			'label' => t('Photos'),
			'url'	=> System::baseUrl() . '/photos/' . $nickname,
			'sel'	=> ((!isset($tab) && $a->argv[0]=='photos') ? 'active' : ''),
			'title' => t('Photo Albums'),
			'id' => 'photo-tab',
			'accesskey' => 'h',
		),
		array(
			'label' => t('Videos'),
			'url'	=> System::baseUrl() . '/videos/' . $nickname,
			'sel'	=> ((!isset($tab) && $a->argv[0]=='videos') ? 'active' : ''),
			'title' => t('Videos'),
			'id' => 'video-tab',
			'accesskey' => 'v',
		),
	);

	// the calendar link for the full featured events calendar
	if ($is_owner && $a->theme_events_in_profile) {
			$tabs[] = array(
				'label' => t('Events'),
				'url'	=> System::baseUrl() . '/events',
				'sel' 	=>((!isset($tab) && $a->argv[0]=='events') ? 'active' : ''),
				'title' => t('Events and Calendar'),
				'id' => 'events-tab',
				'accesskey' => 'e',
			);
		// if the user is not the owner of the calendar we only show a calendar
		// with the public events of the calendar owner
	} elseif (! $is_owner) {
		$tabs[] = array(
				'label' => t('Events'),
				'url'	=> System::baseUrl() . '/cal/' . $nickname,
				'sel' 	=>((!isset($tab) && $a->argv[0]=='cal') ? 'active' : ''),
				'title' => t('Events and Calendar'),
				'id' => 'events-tab',
				'accesskey' => 'e',
			);
	}

	if ($is_owner) {
		$tabs[] = array(
			'label' => t('Personal Notes'),
			'url'	=> System::baseUrl() . '/notes',
			'sel' 	=>((!isset($tab) && $a->argv[0]=='notes') ? 'active' : ''),
			'title' => t('Only You Can See This'),
			'id' => 'notes-tab',
			'accesskey' => 't',
		);
	}

	if ((! $is_owner) && ((count($a->profile)) || (! $a->profile['hide-friends']))) {
		$tabs[] = array(
			'label' => t('Contacts'),
			'url'	=> System::baseUrl() . '/viewcontacts/' . $nickname,
			'sel'	=> ((!isset($tab) && $a->argv[0]=='viewcontacts') ? 'active' : ''),
			'title' => t('Contacts'),
			'id' => 'viewcontacts-tab',
			'accesskey' => 'k',
		);
	}

	$arr = array('is_owner' => $is_owner, 'nickname' => $nickname, 'tab' => (($tab) ? $tab : false), 'tabs' => $tabs);
	call_hooks('profile_tabs', $arr);

	$tpl = get_markup_template('common_tabs.tpl');

	return replace_macros($tpl, array('$tabs' => $arr['tabs']));
}

function get_my_url()
{
	if (x($_SESSION, 'my_url')) {
		return $_SESSION['my_url'];
	}
	return false;
}

function zrl_init(App $a)
{
	$tmp_str = get_my_url();
	if (validate_url($tmp_str)) {
		// Is it a DDoS attempt?
		// The check fetches the cached value from gprobe to reduce the load for this system
		$urlparts = parse_url($tmp_str);

		$result = Cache::get("gprobe:" . $urlparts["host"]);
		if ((!is_null($result)) && (in_array($result["network"], array(NETWORK_FEED, NETWORK_PHANTOM)))) {
			logger("DDoS attempt detected for " . $urlparts["host"] . " by " . $_SERVER["REMOTE_ADDR"] . ". server data: " . print_r($_SERVER, true), LOGGER_DEBUG);
			return;
		}

		Worker::add(PRIORITY_LOW, 'gprobe', $tmp_str);
		$arr = array('zrl' => $tmp_str, 'url' => $a->cmd);
		call_hooks('zrl_init', $arr);
	}
}

function zrl($s, $force = false)
{
	if (! strlen($s)) {
		return $s;
	}
	if ((! strpos($s, '/profile/')) && (! $force)) {
		return $s;
	}
	if ($force && substr($s, -1, 1) !== '/') {
		$s = $s . '/';
	}
	$achar = strpos($s, '?') ? '&' : '?';
	$mine = get_my_url();
	if ($mine && ! link_compare($mine, $s)) {
		return $s . $achar . 'zrl=' . urlencode($mine);
	}
	return $s;
}

/**
 * @brief Get the user ID of the page owner
 *
 * Used from within PCSS themes to set theme parameters. If there's a
 * puid request variable, that is the "page owner" and normally their theme
 * settings take precedence; unless a local user sets the "always_my_theme"
 * system pconfig, which means they don't want to see anybody else's theme
 * settings except their own while on this site.
 *
 * @return int user ID
 *
 * @note Returns local_user instead of user ID if "always_my_theme"
 *      is set to true
 */
function get_theme_uid()
{
	$uid = ((!empty($_REQUEST['puid'])) ? intval($_REQUEST['puid']) : 0);
	if ((local_user()) && ((PConfig::get(local_user(), 'system', 'always_my_theme')) || (! $uid))) {
		return local_user();
	}

	return $uid;
}
