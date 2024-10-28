<?php
/*
Plugin Name: AttachFile
Plugin URI: http://gordon.knoppe.net/articles/category/attach-files/
Description: Allows wordpress authors to attach file(s) to individual posts
Version: 1.2.2-devel
Author: Gordon Knoppe
Author URI: http://gordon.knoppe.net/
*/


#---------------------------------------------------
# Install MySQL Tables
#---------------------------------------------------
if ( !af_mysql_table_exists($wpdb, $table_prefix."attached_files_meta") ) af_mysql_install($wpdb, $table_prefix);


#---------------------------------------------------
# Only proceed with the plugin if MySQL Tables are setup properly
#---------------------------------------------------
if ( af_mysql_table_exists($wpdb, $table_prefix."attached_files_meta") ) {

	#---------------------------------------------------
        # Initialize Configuration Variables
        #---------------------------------------------------
	af_check_config();

	#---------------------------------------------------
	# Wordpress Hooks
	#---------------------------------------------------
	add_action('edit_form_advanced', 'af_show_existing');
	add_action('edit_form_advanced', 'af_insert_input');
	add_action('simple_edit_form', 'af_insert_input');
	add_action('save_post', 'af_process_upload');
	add_action('edit_post', 'af_process_upload');
	add_action('delete_post', 'af_delete_postid');
	add_filter('the_content', 'af_display_attachments');
	add_action('admin_menu', 'af_add_pages');

	#---------------------------------------------------
	# Modify form tag in post.php to multipart enctype
	#---------------------------------------------------
	if ( basename($_SERVER["SCRIPT_FILENAME"]) == 'post.php' ) {

		add_action('admin_head', 'af_head_javascript');
		ob_start('af_modify_the_form_tag');

	}

	#---------------------------------------------------
	# Delete File
	#---------------------------------------------------
	if ( isset($_GET['delete_file']) ) {

		add_action('init', 'af_request_delete');

	}

	#---------------------------------------------------
	# Stream File
	#---------------------------------------------------
	if ( isset($_GET['file_id']) AND $_GET['action'] == "download" ) {

		add_action('init', 'af_stream_fileid');

	}

	#---------------------------------------------------
	# Update Options
	#---------------------------------------------------
	if ( isset($_POST['af_submit']) ) {

		add_action('init', 'af_options_submit');

	}

	#---------------------------------------------------
	# Update Options
	#---------------------------------------------------
	if ( isset($_POST['af_uninstall']) ) {

		add_action('init', 'af_plugin_uninstall');

	}

	// Load Options
	$af_config = get_option('af_config');

	// Determine plugin filename
	$af_scriptname = basename(__FILE__);

} // End of Plugin Actions









/*=============================================================

  Plugin Functions

=============================================================*/


/*-------------------------------------------------------------
 Name:      af_mysql_install

 Purpose:   Create/Update mysql table when plugin is activated
 Receive:   -None-
 Return:    boolean

 Credits:   MySQL file storage solution adopted from
            http://php.dreamwerx.net/forums/viewtopic.php?t=6
-------------------------------------------------------------*/
function af_mysql_install ( $wpdb, $table_prefix ) {

	require_once(ABSPATH . 'wp-admin/upgrade-functions.php');

	$table_name = $table_prefix . "attached_files_meta";

	$sql = "CREATE TABLE ".$table_name." (
		file_id mediumint(8) unsigned NOT NULL auto_increment,
		post_id mediumint(8) NOT NULL,
		user_level int(1) NOT NULL default '0',
		datatype varchar(60) NOT NULL default 'application/octet-stream',
		file_name varchar(120) NOT NULL default '',
		file_size bigint(20) unsigned NOT NULL default '1024',
		file_date datetime NOT NULL default '0000-00-00 00:00:00',
		hit_count int(10) NOT NULL default '0',
		PRIMARY KEY (file_id)
		);";

	dbDelta($sql);

	$table_name = $table_prefix . "attached_files_data";

	$sql = "CREATE TABLE ".$table_name." (
		id mediumint(8) unsigned NOT NULL auto_increment,
		masterid mediumint(8) unsigned NOT NULL default '0',
		file_data blob NOT NULL,
		PRIMARY KEY (id)
		);";

	dbDelta($sql);

	if ( !af_mysql_table_exists( $wpdb, $table_name ) ) {

		add_action('admin_menu', 'af_mysql_warning');

	}

}

function af_mysql_table_exists( $wpdb, $table_name ) {

	if ( !$wpdb->get_results("SHOW TABLES LIKE '%$table_name%'") ) return FALSE;
	else return TRUE;

}

function af_mysql_warning() {

	echo '<div class="updated"><h3>WARNING! The AttachFiles MySQL databases were not created!</h3></div>';

}

/*-------------------------------------------------------------
 Name:      af_show_existing

 Purpose:   List any attachments that are found in the database
 Receive:   -None-
 Return:    -None-
-------------------------------------------------------------*/
function af_show_existing() {

	if ( isset($_GET['post']) ) {

		// Initialize variables
		global $wpdb, $table_prefix;

		// Existing files will only be available when "Editing", $_GET will hold Post ID
		// Look up existing files from database
		$sql = "SELECT * FROM ".$table_prefix."attached_files_meta WHERE post_id = $_GET[post] ORDER BY file_name ASC";
		$results = $wpdb->get_results($sql);

		if ( count($results) > 0 ) {
			// Output file listing
			print '<div>
  <fieldset>
    <legend><a id="attached">Attached Files</a></legend>
    <table cellpadding="3" cellspacing="3" width="100%">
      <tbody>
        <tr>
          <th scope="col">Filename</th>
          <th scope="col">Filesize (Bytes)</th>
          <th scope="col"></th>
          <th scope="col"></th>
        </tr>'."\n";

			// Loop through attached files
			$i = 1;
			foreach ( $results as $file ) {
				if ( af_isEven($i) ) $class = ""; else $class = "alternate";
				echo '        <tr class="'.$class.'">
          <th scope="row">'.$file->file_name.'</th>
          <td><span style="display: block; text-align: center;">'.$file->file_size.'</span></td>
          <td><a class="edit" href="'.get_settings('siteurl').'/?action=download&amp;file_id='.$file->file_id.'&amp;record=no">View/Download</a></td>
          <td><a class="delete" href="http://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'].'&amp;delete_file='.$file->file_id.'">Delete</a></td>
        </tr>'."\n";
				$i++;
			}
			print '      </tbody>
    </table>
  </fieldset>
</div>'."\n";
		}

	}

}


/*-------------------------------------------------------------
 Name:      af_insert_input

 Purpose:   Prepare input form on advanced edit post page
 Receive:   -None-
 Return:    TRUE
-------------------------------------------------------------*/
function af_insert_input() {

	global $af_config, $wpdb, $table_prefix, $user_level;

	if ( $user_level >= $af_config['minlevel'] ) {

		$upload_max = strlen($af_config['maxfilesize']) > 0 ? $af_config['maxfilesize'].'K' : ini_get('upload_max_filesize');

		// Get Number of Uploads available
		if ( isset($_GET['post']) ) {
			$num_uploads = ( $af_config['maxfiles'] == '' ) ? 5 : ($af_config['maxfiles'] - $wpdb->get_var("SELECT COUNT(*) FROM ".$table_prefix."attached_files_meta WHERE post_id = $_GET[post]"));
		}
		else $num_uploads = ( $af_config['maxfiles'] == '' ) ? 5 : $af_config['maxfiles'];

?>
<h2>Attach Files</h2>
<div id="af_input">
  <fieldset>
    <legend><a id="attach" onclick="morefiles()">Add File(s) - (<?=$upload_max?> bytes maximum)</a></legend>
<?

		// If restriction on extensions show message to user
		if ( strlen($af_config['extensions']) > 0 ) {

			print '    <p>You can upload files with the extension <code>'.$af_config['extensions'].'</code></p>'."\n";
	
		}

		for ( $i = 0; $i < $num_uploads; $i++ ) {

?>
    <div class="fileattachment" id="fileatt<?=$i?>" style="display:none">
      Attach File #<?=($i + 1)?> <input type="file" name="af_file<?=$i?>" size="35" <?=(strlen($af_config['extensions']) > 0) ? 'OnChange="TestFileType(this.form.af_file'.$i.'.value);"' : '' ?> />
    </div>
<?

		} // End For Loop

?>
    <noscript><input type="file" name="af_file<?=$i?>" size="35" /></noscript>
    <p><a class="f" id="lessfiles" onkeypress="lessfiles()" onclick="lessfiles()"></a>&nbsp;<a class="f" id="morefiles" onkeypress="morefiles()" onclick="morefiles()">Attach a File</a></p>
  </fieldset>
</div>
<?
		return TRUE;

	}

}

/*-------------------------------------------------------------
 Name:      af_head_javascript

 Purpose:   Insert file attachment javascript. Javascript adopted from
            url: http://www.peterbe.com/plog/file-attachment-widget

 Receive:   -None-
 Return:    TRUE
-------------------------------------------------------------*/
function af_head_javascript() {

	global $af_config, $wpdb, $table_prefix;

	if ( isset($_GET['post']) ) {
		$num_uploads = ( $af_config['maxfiles'] == '' ) ? 5 : ($af_config['maxfiles'] - $wpdb->get_var("SELECT COUNT(*) FROM ".$table_prefix."attached_files_meta WHERE post_id = $_GET[post]"));
	}
	else $num_uploads = ( $af_config['maxfiles'] == '' ) ? 5 : $af_config['maxfiles'];

	print '<script type="text/javascript">
<!--
var nr_files='.$num_uploads.';

// Strings
var L1="Attach another file";
var L2="Attach a file";
var L3="";
 // privates
var _current = 0;
var _fileatts = new Array();

function morefiles() {
  if (document.getElementById){
    if (_current < nr_files) {
      var el = document.getElementById(_fileatts[_current]);
      el.style.display="";
      _current++;
      if (_current < nr_files )
        document.getElementById("morefiles").innerHTML = L1;
      else
        document.getElementById("morefiles").innerHTML = "";

      document.getElementById("lessfiles").innerHTML = L3;
    }
  }
}

function lessfiles() {
  if (document.getElementById) {
    var input = document.post[\'fileatt0\'][_current-1];
    input.value=="";
    var el = document.getElementById(_fileatts[_current-1]);
    el.style.display="none";
    _current--;
    if (_current==0) {
      document.getElementById("morefiles").innerHTML=L2;
      document.getElementById("lessfiles").innerHTML="";
    }
    else
      document.getElementById("morefiles").innerHTML=L1;

  }
}

for (i=0;i<nr_files;i++) _fileatts[i] = "fileatt"+i;
init = function() {
  document.getElementById("morefiles").innerHTML=L2;
}
window.onload=init;
';

if ( strlen($af_config['extensions']) > 0 ) {

	// Build Javascript array of valid extensions
	$js_ext = explode(',', $af_config['extensions']);
	$js_array = '[\'\',';

	foreach ( $js_ext as $ext ) $js_array .= "'".trim($ext)."',";

	$js_array .= ']';

	print '
function TestFileType( fileName ) {
  fileTypes = '.$js_array.';
  if (!fileName) return;
  dots = fileName.split(".")
  //get the part AFTER the LAST period.
  fileType = "." + dots[dots.length-1];

  return (fileTypes.join(".").indexOf(fileType) != -1) ?
    "" :
    alert("Please only upload files that end in types: \n\n" + (fileTypes.join(" .")) + "\n\nPlease select a new file and try again.");
}
';
} // End If Validate

print '
-->

</script>'."\n";
	return TRUE;
}

/*-------------------------------------------------------------
 Name:      af_process_upload

 Purpose:   Process uploaded files. Move files to permanent location
            on filesystem and update mysql database.

 Receive:   upload directory, array of uploaded filenames
 Return:    boolean
-------------------------------------------------------------*/
function af_process_upload($post_id) {

	global $table_prefix, $wpdb, $af_config, $af_scriptname;

	$STARTFILE = 0;
	$ONFILE = 'af_file'.$STARTFILE;

	// Loop through uploaded files
	while ( isset($_FILES[$ONFILE]) ) {

		$SrcPathFile = $_FILES[$ONFILE]["tmp_name"];
		$SrcFileType = $_FILES[$ONFILE]["type"];
		$DstFileName = $_FILES[$ONFILE]["name"];

		if ( af_valid_extension(array_pop(explode('.', $DstFileName))) ) {

			/* --------------------------------------------------------
			Check file against size restrictions
			---------------------------------------------------------*/

			if ( $af_config['maxfilesize'] > 0 ) {

				// File size in bytes
				$filesize[$ONFILE] = filesize($SrcPathFile);

				if ( $filesize[$ONFILE] > ( $af_config['maxfilesize'] * 1000 ) ) {

					header('Location: '.get_settings('siteurl') . "/wp-admin/options-general.php?page=$af_scriptname&error=fsize");
					die();

				}

			}

			if ( $af_config['maxpp'] > 0 ) {

				if ( !isset($filesize[$ONFILE]) ) $filesize[$ONFILE] = filesize($SrcPathFile);

				// Determine combined size of files for this post
				$SQL = "SELECT SUM(file_size) FROM ".$table_prefix."attached_files_meta WHERE post_id = '".$post_id."';";
				$post_size = $wpdb->get_var($SQL);

				if ( ( ( $filesize[$ONFILE] + $post_size ) / 1000 ) > $af_config['maxpp'] ) {

					header('Location: '.get_settings('siteurl') . "/wp-admin/options-general.php?page=$af_scriptname&error=psize");
                                        die();

				}

                        }

			if ( $af_config['sysquota'] > 0 ) {

				if ( !isset($filesize[$ONFILE]) ) $filesize[$ONFILE] = filesize($SrcPathFile);

				// Determine combined size of files system-wide
				$SQL = "SELECT SUM(file_size) FROM ".$table_prefix."attached_files_meta";
				$system_size = $wpdb->get_var($SQL);

				if ( ( ( $filesize[$ONFILE] + $system_size ) / 1000000 ) > $af_config['sysquota'] ) {

					header('Location: '.get_settings('siteurl') . "/wp-admin/options-general.php?page=$af_scriptname&error=quota");
					die();

				}


                        }

			clearstatcache();

			$FileTime = filemtime($SrcPathFile);
			$storedate = date("Y-m-d H:i:s", $FileTime);

			// File Processing
			if ( file_exists($SrcPathFile) ) {

				// Insert meta data into meta table
				$SQL  = "INSERT INTO ".$table_prefix."attached_files_meta
	  				( post_id, datatype, file_name, file_size, file_date )
					values ( '$post_id', '$SrcFileType', '$DstFileName', '".filesize($SrcPathFile)."', '$storedate' )";

				if ( !$wpdb->query($SQL) ) {

					die("Failure while inserting file into table!");

				}

				$fileid = $wpdb->insert_id;

				// Insert into the filedata table
				$fp = fopen($SrcPathFile, "rb");
				while ( !feof($fp) ) {

					// Make the data mysql insert safe
					$binarydata = addslashes(fread($fp, 65535));

					$SQL = "INSERT INTO ".$table_prefix."attached_files_data
						( masterid, file_data )
						values ( '$fileid' , '$binarydata' )";

					if ( !$wpdb->query($SQL) ) {

						die("Failure to insert binary inode data row!");

					}

				}

				fclose($fp);

			}

		}

		$STARTFILE ++;
		$ONFILE = "af_file" . $STARTFILE;

	}

}

/*-------------------------------------------------------------
 Name:      af_request_delete

 Purpose:   Remove file requested in the URI
-------------------------------------------------------------*/
function af_request_delete () {

	global $userdata, $table_prefix, $wpdb;
	get_currentuserinfo();

	$file_id = $_GET['delete_file'];

	if ( $file_id > 0 ) {

		$SQL = "SELECT ".$table_prefix."attached_files_meta.file_id, ".$table_prefix."users.ID as user_id, ".$table_prefix."users.user_level as user_level FROM ".$table_prefix."users, ".$table_prefix."attached_files_meta, ".$table_prefix."posts WHERE ".$table_prefix."attached_files_meta.post_id = ".$table_prefix."posts.ID AND ".$table_prefix."posts.post_author = ".$table_prefix."users.ID AND ".$table_prefix."attached_files_meta.file_id = $file_id;";
		
		$file = $wpdb->get_row($SQL);

		// Only allow delete if file is lower user permissions or users own
		if ( $file->user_level < $userdata->user_level OR $file->user_id == $userdata->ID ) {

			af_delete_fileid($file_id);

		}

	}

	header('Location: '.$_SERVER['HTTP_REFERER']);

}

/*-------------------------------------------------------------
 Name:      af_delete_postid

 Purpose:   Remove all files from database for given post
 Receive:   post id
 Return:    boolean
-------------------------------------------------------------*/
function af_delete_postid ($post_id) {

	global $wpdb, $table_prefix;

	// Gather list of files to be deleted
	$SQL = "SELECT file_id, post_id FROM ".$table_prefix."attached_files_meta WHERE post_id = '$post_id'";

	$files = $wpdb->get_results($SQL);

	foreach ( $files as $file ) {

		af_delete_fileid($file->file_id);

	}

}

/*-------------------------------------------------------------
 Name:      af_delete_fileid

 Purpose:   Remove file from database
 Receive:   file id
 Return:    boolean
-------------------------------------------------------------*/
function af_delete_fileid ($file_id) {

	if ( $file_id > 0 ) {

		global $wpdb, $table_prefix;

		// Delete Record from meta and data table
		$SQL = "DELETE FROM ".$table_prefix."attached_files_meta WHERE file_id = '$file_id'";
		if ( !$wpdb->query($SQL) ) {

			die("Failure to delete file meta data from meta table");

		}

		$SQL = "DELETE FROM ".$table_prefix."attached_files_data WHERE masterid = '$file_id'";
		if ( !$wpdb->query($SQL) ) {

                        die("Failure to delete file data from data table");

		}

		return TRUE;

 	}

}

/*-------------------------------------------------------------
 Name:      af_stream_fileid

 Purpose:   Stream file from database
 Receive:   file id
 Return:    boolean
-------------------------------------------------------------*/
function af_stream_fileid () {

	global $userdata, $table_prefix, $wpdb;
        get_currentuserinfo();

	$file_id = $_GET['file_id'];

	$nodelist = array();

	// Pull file meta-data
	$SQL = "SELECT * FROM ".$table_prefix."attached_files_meta WHERE file_id = $file_id";
	if ( !$file_info = $wpdb->get_row($SQL) ) {

		die("<h1>Error:  File Not Found!</h1><p>There was a problem retrieving your file from our database.  Please try again later or contact the website admin.</p><hr><p><b>".date('m/d/y H:i:s')." URI: </b> <i>http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']."</i></p>");

	}

	// Pull the list of file inodes
	$SQL = "SELECT id FROM ".$table_prefix."attached_files_data WHERE masterid = $file_id ORDER BY id";
	if ( !$file_nodes = $wpdb->get_results($SQL) ) {

		die("Failure to retrive list of file inodes");

	}

	// Send down the header to the client
	Header ( "Content-Type:   " . $file_info->datatype );
	Header ( "Content-Length: " . $file_info->file_size );
	Header ( "Content-Disposition: attachment; filename=\"" . $file_info->file_name . "\"" );

	// Loop thru and stream the nodes 1 by 1
	foreach ( $file_nodes as $node ) {

		$SQL = "SELECT file_data FROM ".$table_prefix."attached_files_data WHERE id = ".$node->id;
		if ( !$node_data = $wpdb->get_var($SQL) ) {

			die("Failure to retrive file node data");

		}

		echo $node_data;

	}

	// Record as hit in DB
	if ( $_GET['record'] != 'no' ) {

		$SQL = "UPDATE ".$table_prefix."attached_files_meta SET hit_count = hit_count + 1 WHERE file_id = '$file_id'";
		$wpdb->query($SQL);

	}

	exit();

}

/*-------------------------------------------------------------
 Name:      af_display_attachments

 Purpose:   Lists the file(s) attached to post after main content
 Receive:   content
 Return:    content
-------------------------------------------------------------*/
function af_display_attachments($content) {
	global $wp_query, $wpdb, $table_prefix, $af_config;

	// Get current post ID
	$post = $wp_query->post;
	$id = $post->ID;

	if ( empty($id) AND isset($_GET['post']) ) $id = $_GET['post'];

	// Load current attachments
	$SQL = "SELECT * FROM ".$table_prefix."attached_files_meta WHERE post_id = $id ORDER BY file_name ASC";
	$results = $wpdb->get_results($SQL);

	if ( count($results) > 0 ) {
		// Head of output
		$content .= '<div class="AF_PostFiles">
  <h3>'.$af_config['heading'].':</h3>'."\n";

		// Loop thru attachments
		foreach ( $results as $file ) {

			$content .= '  <div class="AF_File">
    <p><span class="AF_Filename"><a href="'.get_settings('siteurl').'/?action=download&amp;file_id='.$file->file_id.'">'.$file->file_name.'</a></span> <span class="AF_Filesize">'.number_format(round(($file->file_size / 1000))).'K</span></p>
  </div>'."\n";

		}

		$content .= "<br style=\"clear: both;\" /></div>";

	}

	return $content;
}


function af_modify_the_form_tag ($buffer) {

	$buffer = str_replace('<form name="post" action="post.php" method="post"', '<form name="post" action="post.php" method="post" enctype="multipart/form-data"', $buffer);
	return $buffer;

}

/*-------------------------------------------------------------
 Name:      af_isEven

 Purpose:   Determine if a number is even or not
 Receive:   number
 Return:    boolean
-------------------------------------------------------------*/
function af_isEven ( $num ) {

	return !($num % 2);

}

/*-------------------------------------------------------------
 Name:      af_add_pages

 Purpose:   Add pages to admin menus
 Receive:   number
 Return:    boolean
-------------------------------------------------------------*/
function af_add_pages() {

	global $af_config;

	add_management_page('File-Attachments', 'File-Attachments', $af_config['minlevel'], __FILE__, 'af_manage_page');
	add_options_page('File-Attachments', 'File-Attachments', 8, __FILE__, 'af_options_page');

}

function af_manage_page() {

	global $table_prefix, $wpdb, $userdata;

?>
<div class="wrap">
  <h2>File-Attachments</h2>
  <p>Use this screen to manage your attached files.</p>
  <table width="100%" cellpadding="3" cellspacing="3">
    <tr>
      <th scope="col">ID</th>
      <th scope="col">When</th>
      <th scope="col">Post Title</th>
      <th scope="col">Author</th>
      <th scope="col">Filename</th>
      <th scope="col">File Size</th>
      <th scope="col">Downloads</th>
      <th scope="col"></th>
      <th scope="col"></th>
    </tr>
<?

	// Load All Files from users below current user and from current user
	$SQL = "SELECT 	".$table_prefix."attached_files_meta.file_id, 
			".$table_prefix."posts.ID, 
			DATE_FORMAT(".$table_prefix."posts.post_date, '%c-%e-%Y') as post_date, 
			".$table_prefix."posts.post_title, 
			".$table_prefix."attached_files_meta.file_name, 
			".$table_prefix."attached_files_meta.file_size, 
			".$table_prefix."attached_files_meta.hit_count, 
			".$table_prefix."users.user_login 
		FROM 	".$table_prefix."posts, ".$table_prefix."attached_files_meta, ".$table_prefix."users 
		WHERE 	".$table_prefix."posts.ID = ".$table_prefix."attached_files_meta.post_id 
			AND ".$table_prefix."posts.post_author = ".$table_prefix."users.ID
			AND ( 
				".$table_prefix."users.user_level < ".$userdata->user_level."
				OR 
				".$table_prefix."users.ID = ".$userdata->ID."
			)
		ORDER BY ".$table_prefix."attached_files_meta.file_id DESC";
	$files = $wpdb->get_results($SQL);

	$i = 1;

	// If no files in database
	if ( count($files) == 0 ) echo '    <tr><td style="text-align: center;" colspan="9"><i>No Files in Database</i></td></tr>'."\n";

	else {
		foreach ( $files as $file ) {
			if ( af_isEven($i) ) $class = ""; else $class = "alternate";
?>
    <tr class="<?=$class?>">
      <td style="text-align: center;"><?=$file->file_id?></td>
      <td style="text-align: center;"><?=$file->post_date?></td>
      <td><a href="<?=get_settings('siteurl').'/?p='.$file->ID?>"><?=$file->post_title?></a></td>
      <td><?=$file->user_login?></td>
      <td><?=$file->file_name?></td>
      <td style="text-align: right;"><?=round($file->file_size / 1000, 0)?> KB</td>
      <td style="text-align: right;"><?=$file->hit_count?></td>
      <td><a class="edit" href="<?=get_settings('siteurl').'/?action=download&amp;file_id='.$file->file_id.'&amp;record=no'?>">View</a></td>
      <td><a href="http://<?=$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'].'&amp;delete_file='.$file->file_id?>" class="delete" onclick="return confirm('You are about to delete this file \'<?=$file->file_name?>\'\n  \'OK\' to delete, \'Cancel\' to stop.')">Delete</a></td>
    </tr>
<?
			// Increment row count for class "alternate"
			$i++;

		}

	}

	$system_data 	= $wpdb->get_row("SELECT COUNT(*) as count, SUM(file_size) as total_usage, AVG(file_size) as average_fsize, MAX(file_size) as max_file FROM ".$table_prefix."attached_files_meta");

?>
  </table>
  <h3>System Summary</h3>
  <table cellpadding="3" cellspacing="3" width="40%">
    <tr>
      <th style="text-align: left;" scope="row">Total No. of Files</th>
      <td width="30%" style="text-align: right;"><?=$system_data->count?></td>
    </tr>
    <tr>
      <th style="text-align: left;" scope="row">Total Disk Usage</th>
      <td style="text-align: right;"><?=round($system_data->total_usage / 1000000, 2)?> MB</td>
    </tr>
    <tr>
      <th style="text-align: left;" scope="row">Average File Size</th>
      <td style="text-align: right;"><?=round($system_data->average_fsize / 1000, 1)?> KB</td>
    </tr>
    <tr>
      <th style="text-align: left;" scope="row">Largest File</th>
      <td style="text-align: right;"><?=round($system_data->max_file / 1000, 1)?> KB</td>
    </tr>
  </table>
</div>
<?
}

function af_options_page() {

	// Make sure we have the freshest copy of the options
	$af_config = get_option('af_config');
	global $user_level;


	// Default options configuration page
	if ( !isset($_GET['error']) && $user_level >= 8 ) {
?>
<div class="wrap">
  <h2>File-Attachments</h2>
  <form method="post" action="<?=$_SERVER['REQUEST_URI']?>&amp;updated=true">
    <input type="hidden" name="af_submit" value="true" />
    <table width="100%" cellspacing="2" cellpadding="5" class="editform">
      <tr>
        <th width="33%" scope="row">Post Heading:</th>
        <td><input name="af_heading" type="text" value="<?=$af_config['heading']?>" size="30" /></td>
      </tr>
    </table>
    <fieldset>
      <legend>File Restrictions:  (Leave blank for no restriction)</legend>
      <table width="100%" cellspacing="2" cellpadding="5" class="editform">
        <tr>
          <th width="33%" scope="row">Valid Extensions:</th>
          <td><input name="af_extensions" type="text" value="<?=$af_config['extensions']?>" size="40" /><br />Comma separated list of allowed file formats.  Ex: (pdf, csv, txt)</td>
        </tr>
        <tr>
          <th width="33%" scope="row">Max. Files per Post:</th>
          <td><input name="af_maxfiles" type="text" value="<?=$af_config['maxfiles']?>" size="5" /></td>
        </tr>
        <tr>
          <th width="33%" scope="row">Max. File Size:</th>
          <td><input name="af_maxfilesize" type="text" value="<?=$af_config['maxfilesize']?>" size="5" /> KB<br />Cannot exceed server maximum set in the php.ini file.  Your server is set to <?=ini_get('upload_max_filesize')?> Bytes</td>
        </tr>
        <tr>
          <th width="33%" scope="row">Max. Combined File Size per Post:</th>
          <td><input name="af_maxpp" type="text" value="<?=$af_config['maxpp']?>" size="5" /> KB</td>
        </tr>
        <tr>
          <th width="33%" scope="row">System-wide Disk Usage Restriction:</th>
          <td><input name="af_sysquota" type="text" value="<?=$af_config['sysquota']?>" size="5" /> MB</td>
        </tr>
        <tr>
          <th width="33%" scope="row">Minimum User Level for Upload:</th>
          <td><input name="af_minlevel" type="text" value="<?=$af_config['minlevel']?>" size="3" /></td>
        </tr>
      </table>
    </fieldset>
    <p class="submit">
      <input type="submit" name="Submit" value="Update Options &raquo;" />
    </p>
  </form>
  <h2>AttachFiles Uninstall</h2>
  <p>Because AttachFiles creates its own tables in your mysql database for file storage simply disabling the plugin will not delete the files and free up your disk space. To disable the AttachFiles plugin and completely remove the data from your mysql tables you will need to click the button below.</p>
  <p><b>WARNING!</b> -- This process is irreversible!</p>
  <p>No other parts of your wordpress installation will be harmed.</p>
  <form method="post" action="<?=$_SERVER['REQUEST_URI']?>">
  <p class="submit">
    <input type="hidden" name="af_uninstall" value="true" />
    <input onclick="return confirm('You are about to uninstall the attach-files plugin\n  All uploaded data will be lost!\n\'OK\' to continue, \'Cancel\' to stop.')" type="submit" name="Submit" value="Uninstall Plugin &raquo;" />
  </p>
  </form>
</div>
<?

	} // End If


	// If file exceeds maximum single file limit
	else if ( $_GET['error'] == 'fsize' ) {

		$maxfilesize = $af_config['maxfilesize'];

?>
<div class="wrap">
  <h2>Error!</h2>
  <p>There was a problem with your file upload.  The file you were trying to upload exceeds the maximum size limit of <b><?=$maxfilesize?>KB</b></p>
</div>
<?

	}

	// If file exceeds combined files per post size limit
	else if ( $_GET['error'] == 'psize' ) {

?>
<div class="wrap">
  <h2>Error!</h2>
  <p>There was a problem with your file upload.  The file you are trying to upload exceeds the maximum combined file size per-post limit of <b><?=$af_config['maxpp']?>KB</b></p>
</div>
<?

	}

	// If file exceeds the system-wide quota
	else if ( $_GET['error'] == 'quota' ){

?>
<div class="wrap">
  <h2>Error!</h2>
  <p>There was a problem with your file upload.  This system has a quota set at <b><?=$af_config['sysquota']?>MB</b> and your file would exceed this amount so it was not allowed.  Please clear some space or increase your quota and try again.</p>
</div>
<?

	}

}

function af_check_config() {

	if ( !$option = get_option('af_config') ) {

		// Default Options
		$option['heading'] = 'Attached Files';
		$option['extensions'] = '';
		$option['maxfiles'] = '';
		$option['maxfilesize'] = '';
		$option['maxpp'] = '';
		$option['sysquota'] = '';
		$option['minlevel'] = 6;

		update_option('af_config', $option);

	}

	// If value not assigned insert default (upgrades)
	if ( $option['minlevel'] < 1 ) {

		$option['minlevel'] = 6;
		update_option('af_config', $option);

	}

}

function af_options_submit() {

	global $user_level;
	get_currentuserinfo();

	if ( $user_level >= 8 ) {

		$option['heading'] = $_POST['af_heading'];
		$option['extensions'] = $_POST['af_extensions'];
		$option['maxfiles'] = $_POST['af_maxfiles'];
		$option['maxfilesize'] = $_POST['af_maxfilesize'];
		$option['maxpp'] = $_POST['af_maxpp'];
		$option['sysquota'] = $_POST['af_sysquota'];
		$option['minlevel'] = $_POST['af_minlevel'];

		update_option('af_config', $option);

	}

}

function af_valid_extension($extension) {

	global $af_config;
	
	// If no limits are present always return true
	if ( strlen($af_config['extensions']) == 0 ) return TRUE;
	
	// Get array of valid extensions
	$valid_ext = array_map('trim', explode(',', $af_config['extensions']));
	return in_array($extension, $valid_ext);

}

function af_plugin_uninstall() {

	global $wpdb, $table_prefix, $af_scriptname, $user_level;
	get_currentuserinfo();

	if ( $user_level >= 8 ) {
		// Drop MySQL Tables
		$SQL = "DROP TABLE ".$table_prefix."attached_files_meta";
		$wpdb->query($SQL);

		$SQL = "DROP TABLE ".$table_prefix."attached_files_data";
        	$wpdb->query($SQL);

		// Delete Option
		delete_option('af_config');

		// Deactivate Plugin
		$current = get_settings('active_plugins');
        	array_splice($current, array_search( "$af_scriptname", $current), 1 ); // Array-fu!
		update_option('active_plugins', $current);
		header('Location: plugins.php?deactivate=true');

		die();

	}

}
?>
