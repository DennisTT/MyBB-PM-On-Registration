<?php
// PM On Registration
// By DennisTT - http://www.dennistt.net
// Version 1.2.0

// This plugin (C) DennisTT 2008.  You may not redistribute this plugin without the permission from DennisTT.

if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}


function pmonreg_info()
{
	global $lang;
	pmonreg_load_language();
	
	return array(
		"name"			=> $lang->pmonreg,
		"description"	=> $lang->pmonreg_desc,
		"website"		=> "http://www.dennistt.net",
		"author"		=> "DennisTT",
		"authorsite"	=> "http://www.dennistt.net",
		"version"		=> "1.2.0",
		"guid"			  => "eacf7cafb1fc90c53a27cbf2f4687449",
		"compatibility"   => "14*",
		
		// DennisTT custom info
		"codename" => 'pmonreg',
	);
}

// Helper function to load the language variables
function pmonreg_load_language()
{
	global $lang;
	if(!defined('DENNISTT_PMONREG_LANG_LOADED'))
	{
		$lang->load('pmonreg', false, true);
		
		if(!isset($lang->pmonreg))
		{
			$lang->pmonreg = 'PM On Registration';
			$lang->pmonreg_desc = 'Automatically send a private message to newly registered users. For MyBB 1.4.x';
		}
		
		define('DENNISTT_PMONREG_LANG_LOADED', 1);
	}
}

// This function runs when the plugin is activated.
function pmonreg_activate()
{
	global $db, $cache;
	$info = pmonreg_info();
	
	// Deactivate to remove any existing settings
	pmonreg_deactivate();

	$setting_group_array = array(
		'name' => str_replace(' ', '_', 'dennistt_'.strtolower($info['codename'])),
		'title' => "$info[name] (DennisTT)",
		'description' => "Settings for the $info[name] plugin",
		'disporder' => 1,
		'isdefault' => 0,
		);
	$db->insert_query('settinggroups', $setting_group_array);
	$group = $db->insert_id();
	
	$settings = array(
		'pmonreg_switch' => array('PM On Registration Main Switch', 'Send a private message on registration to new users?  This is the main switch; if this is disabled, none of the below settings will take effect, and PMs will not be sent.', 'onoff', '1'),
		'pmonreg_uid' => array('Sent By Whom?', 'Enter the user ID of the author of these automated private messages.', 'text', '1'),
		'pmonreg_cc_uids' => array('Carbon Copy To Users', 'Enter the user ID of any users that should be CC\'ed on each PM.  Separate by commas.', 'text', ''),
		'pmonreg_bcc_uids' => array('Blind Carbon Copy To Users', 'Enter the user ID of any users that should be BCC\'ed on each PM.  Separate by commas.', 'text', ''),
		'pmonreg_subject' => array('PM Subject', 'Enter the subject of the automated private messages.<br /><br />{username} will be replaced with the username of the recipient', 'text', 'Welcome!'),
		'pmonreg_message' => array('PM Message', 'Enter the message of the automated private messages.<br /><br />{username} will be replaced with the username of the recipient', 'textarea', 'Hello {username}, and welcome to my MyBB forum!'),
		'pmonreg_showsignature' => array('Show Signature?', 'Show the sender\'s signature?', 'yesno', 1),
		'pmonreg_disablesmilies' => array('Disable Smilies?', 'Disable smilies from showing?', 'yesno', 0),
		'pmonreg_savecopy' => array('Save A Copy?', 'Save a copy in the sender\'s Sent Items folder?', 'yesno', 0),
		'pmonreg_receipt' => array('Request Read Receipt?', 'Request read receipt from recipient?', 'yesno', 0),
		);

	$i = 1;
	foreach($settings as $name => $sinfo)
	{
		$insert_array = array(
			'name' => $name,
			'title' => $db->escape_string($sinfo[0]),
			'description' => $db->escape_string($sinfo[1]),
			'optionscode' => $db->escape_string($sinfo[2]),
			'value' => $db->escape_string($sinfo[3]),
			'gid' => $group,
			'disporder' => $i,
			'isdefault' => 0,
			);
		$db->insert_query('settings', $insert_array);
		$i++;
	}
	rebuild_settings();

	$cache->update('pmonreg_errors', 0);
	
	// Make sure activation usergroup has permission to read PMs...
	$update_group = array('canusepms' => 1, 'pmquota' => 20);
	$db->update_query('usergroups', $update_group, 'gid=5');
	$cache->update_usergroups();
}

// This function runs when the plugin is deactivated.
function pmonreg_deactivate()
{
	global $db, $cache;
	$info = pmonreg_info();

	$result = $db->simple_select('settinggroups', 'gid', "name = '".str_replace(' ', '_', 'dennistt_'.strtolower($info['codename']))."'", array('limit' => 1));
	$group = $db->fetch_array($result);
	
	if(!empty($group['gid']))
	{
		$db->delete_query('settinggroups', "gid='{$group['gid']}'");
		$db->delete_query('settings', "gid='{$group['gid']}'");
		rebuild_settings();
	}
	$db->delete_query("datacache", "title='pmonreg_errors'");
}

$plugins->add_hook("member_do_register_end", "pmonreg_run");
function pmonreg_run()
{
	global $mybb, $user_info, $cache, $user, $lang;

	if($mybb->settings['pmonreg_switch'] != 0)
	{
		require_once MYBB_ROOT."inc/datahandlers/pm.php";
	
		$pmhandler = new PMDataHandler();
		
		$recipients_to = array($user_info['uid']);
		$recipients_bcc = array();
		
		// Handle CC list
		$cc_list = explode(',', $mybb->settings['pmonreg_cc_uids']);
		foreach($cc_list as $cc_uid)
		{
			if(intval($cc_uid))
			{
				$recipients_to[] = intval($cc_uid);
			}
		}
		
		// Handle BCC List
		$bcc_list = explode(',', $mybb->settings['pmonreg_bcc_uids']);
		foreach($bcc_list as $bcc_uid)
		{
			if(intval($bcc_uid))
			{
				$recipients_bcc[] = intval($bcc_uid);
			}
		}
		
		// Handle subject/message from language pack
		$subject = $mybb->settings['pmonreg_subject'];
		if(isset($lang->pmonreg_subject))
		{
			$subject = $lang->pmonreg_subject;
		}
		
		$message = $mybb->settings['pmonreg_message'];
		if(isset($lang->pmonreg_message))
		{
			$message = $lang->pmonreg_message;
		}
		

		$pm = array(
			"subject" => str_replace('{username}', $user['username'], $subject),
			"message" => str_replace('{username}', $user['username'], $message),
			"icon" => -1,
			"fromid" => intval($mybb->settings['pmonreg_uid']),
			"toid" => $recipients_to,
			"bccid" => $recipients_bcc,
			"do" => '',
			"pmid" => ''
		);
	
		$pm['options'] = array(
			"signature" => $mybb->settings['pmonreg_showsignature'],
			"disablesmilies" => $mybb->settings['pmonreg_disablesmilies'],
			"savecopy" => $mybb->settings['pmonreg_savecopy'],
			"readreceipt" => $mybb->settings['pmonreg_receipt']
		);
		$pm['saveasdraft'] = 0;
		$pmhandler->admin_override = 1;
		$pmhandler->set_data($pm);
		if($pmhandler->validate_pm())
		{
			$pmhandler->insert_pm();
		}
		else
		{
			pmonreg_handle_error(&$pmhandler);
		}
	}
}

function pmonreg_handle_error(&$pmhandler)
{
	global $cache;
	$errors = $cache->read("rss2post_errors");	
	if($errors === false)
	{
		$errors = '';
	}					
	
	$errors .= 'Date: ' . gmdate('r') . "\n";
	
	$datahandler_errors = $pmhandler->get_errors();
	ob_start();
	print_r($datahandler_errors);
	$errors .= ob_get_clean();
	$errors .= "\n\n===========================================\n\n";
	
	$cache->update('pmonreg_errors', $errors);
}