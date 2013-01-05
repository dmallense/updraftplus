<?php
/*
Plugin Name: UpdraftPlus - Backup/Restore
Plugin URI: http://wordpress.org/extend/plugins/updraftplus
Description: Uploads, themes, plugins, and your DB can be automatically backed up to Amazon S3, Google Drive, FTP, or emailed, on separate schedules.
Author: David Anderson.
Version: 1.1.12
Donate link: http://david.dw-perspective.org.uk/donate
License: GPLv3 or later
Author URI: http://wordshell.net
*/ 

/*
TODO
//Add DropBox and Microsoft Skydrive support
//improve error reporting / pretty up return messages in admin area
//?? On 'backup now', open up a Lightbox, count down 5 seconds, then start examining the log file (if it can be found)

Encrypt filesystem, if memory allows (and have option for abort if not); split up into multiple zips when needed
// Does not delete old custom directories upon a restore?
// Re-do making of zip files to allow resumption (every x files, store the state in a transient)
*/

/*  Portions copyright 2010 Paul Kehrer
Portions copyright 2011-12 David Anderson
Other portions copyright as indicated authors in the relevant files
Particular thanks to Sorin Iclanzan, author of the "Backup" plugin, from which much Google Drive code was taken under the GPLv3+

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// 15 minutes
@set_time_limit(900);

if (!isset($updraftplus)) $updraftplus = new UpdraftPlus();

if (!$updraftplus->memory_check(192)) {
# TODO: Better solution is to split the backup set into manageable chunks based on this limit
	@ini_set('memory_limit', '192M'); //up the memory limit for large backup files
}

define('UPDRAFTPLUS_DIR', dirname(__FILE__));
define('UPDRAFT_DEFAULT_OTHERS_EXCLUDE','upgrade,cache,updraft,index.php');

class UpdraftPlus {

	var $version = '1.1.12';

	// Choices will be shown in the admin menu in the order used here
	var $backup_methods = array (
		"s3" => "Amazon S3",
		"googledrive" => "Google Drive",
		"ftp" => "FTP",
		"email" => "Email"
	);

	var $dbhandle;
	var $errors = array();
	var $nonce;
	var $cronrun_type = "none";
	var $logfile_name = "";
	var $logfile_handle = false;
	var $backup_time;

	function __construct() {
		// Initialisation actions - takes place on plugin load
		# Create admin page
		add_action('admin_menu', array($this,'add_admin_pages'));
		add_action('admin_init', array($this,'admin_init'));
		add_action('updraft_backup', array($this,'backup_files'));
		add_action('updraft_backup_database', array($this,'backup_database'));
		# backup_all is used by the manual "Backup Now" button
		add_action('updraft_backup_all', array($this,'backup_all'));
		# this is our runs-after-backup event, whose purpose is to see if it succeeded or failed, and resume/mom-up etc.
		add_action('updraft_backup_resume', array($this,'backup_resume'));
		add_action('wp_ajax_updraft_download_backup', array($this, 'updraft_download_backup'));
		# http://codex.wordpress.org/Plugin_API/Filter_Reference/cron_schedules
		add_filter('cron_schedules', array($this,'modify_cron_schedules'));
		add_filter('plugin_action_links', array($this, 'plugin_action_links'), 10, 2);
		add_action('init', array($this, 'handle_url_actions'));
	}

	// Handle actions passed on to method plugins; e.g. Google OAuth 2.0 - ?page=updraftplus&action=updraftmethod-googledrive-auth
	// Also handle action=downloadlog
	function handle_url_actions() {
		// First, basic security check: must be an admin page, with ability to manage options, with the right parameters
		if ( is_admin() && current_user_can('manage_options') && isset( $_GET['page'] ) && $_GET['page'] == 'updraftplus' && isset($_GET['action']) ) {
			if (preg_match("/^updraftmethod-([a-z]+)-([a-z]+)$/", $_GET['action'], $matches) && file_exists(UPDRAFTPLUS_DIR.'/methods/'.$matches[1].'.php')) {
				$method = $matches[1];
				require_once(UPDRAFTPLUS_DIR.'/methods/'.$method.'.php');
				$call_class = "UpdraftPlus_BackupModule_".$method;
				$call_method = "action_".$matches[2];
				if (method_exists($call_class, $call_method)) call_user_func(array($call_class,$call_method));
			} elseif ($_GET['action'] == 'downloadlog' && isset($_GET['updraftplus_backup_nonce']) && preg_match("/^[0-9a-f]{12}$/",$_GET['updraftplus_backup_nonce'])) {
				$updraft_dir = $this->backups_dir_location();
				$log_file = $updraft_dir.'/log.'.$_GET['updraftplus_backup_nonce'].'.txt';
				if (is_readable($log_file)) {
					header('Content-type: text/plain');
					readfile($log_file);
					exit;
				} else {
					add_action('admin_notices', array($this,'show_admin_warning_unreadablelog') );
				}
			}
		}
	}

	# Adds the settings link under the plugin on the plugin screen.
	function plugin_action_links($links, $file) {
		if ($file == plugin_basename(__FILE__)){
			$settings_link = '<a href="'.site_url().'/wp-admin/options-general.php?page=updraftplus">'.__("Settings", "UpdraftPlus").'</a>';
			array_unshift($links, $settings_link);
			$settings_link = '<a href="http://david.dw-perspective.org.uk/donate">'.__("Donate","UpdraftPlus").'</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	function backup_time_nonce() {
		$this->backup_time = time();
		$nonce = substr(md5(time().rand()), 20);
		$this->nonce = $nonce;
		// Short-lived, as we only use this for detecting a race condition
		set_transient("updraftplus_runtype_$nonce", $this->cronrun_type, 300);
	}

	# Logs the given line, adding date stamp and newline
	function log($line) {
		if ($this->logfile_handle) fwrite($this->logfile_handle,date('r')." ".$line."\n");
	}
	
	function backup_resume($resumption_no) {
		@ignore_user_abort(true);
		// This is scheduled for 5 minutes after a backup job starts
		$bnonce = get_transient('updraftplus_backup_job_nonce');
		if (!$bnonce) return;
		$this->nonce = $bnonce;
		$this->logfile_open($bnonce);
		$this->log("Resume backup ($resumption_no): begin run (will check for any remaining jobs)");
		$btime = get_transient('updraftplus_backup_job_time');
		if (!$btime) {
			$this->log("Did not find stored time setting - aborting");
			return;
		}
		$this->log("Resuming backup: resumption=$resumption_no, nonce=$bnonce, begun at=$btime");
		// Schedule again, to run in 5 minutes again, in case we again fail
		$resume_delay = 300;
		// A different argument than before is needed otherwise the event is ignored
		$next_resumption = $resumption_no+1;
		if ($next_resumption < 10) {
			wp_schedule_single_event(time()+$resume_delay, 'updraft_backup_resume' ,array($next_resumption));
		} else {
			$this->log("This is our tenth attempt - will not try again");
		}
		$this->backup_time = $btime;

		// Returns an array, most recent first, of backup sets
		$backup_history = $this->get_backup_history();
		if (!isset($backup_history[$btime])) $this->log("Error: Could not find a record in the database of a backup with this timestamp");

		$our_files=$backup_history[$btime];
		$undone_files = array();

		// Potentially encrypt the database if it is not already
		if (isset($our_files['db']) && !preg_match("/\.crypt$/", $our_files['db'])) {
			$our_files['db'] = $this->encrypt_file($our_files['db']);
			$this->save_backup_history($our_files);
		}

		foreach ($our_files as $key => $file) {

			$hash = md5($file);
			$fullpath = trailingslashit(get_option('updraft_dir')).$file;
			if (get_transient('updraft_'.$hash) === "yes") {
				$this->log("$file: $key: This file has been successfully uploaded in the last 3 hours");
			} elseif (is_file($fullpath)) {
				$this->log("$file: $key: This file has NOT been successfully uploaded in the last 3 hours: will retry");
				$undone_files[$key] = $file;
			} else {
				$this->log("$file: Note: This file was not marked as successfully uploaded, but does not exist on the local filesystem");
				$this->uploaded_file($file);
			}
		}

		if (count($undone_files) == 0) {
			$this->log("There were no files that needed uploading; backup job is finished");
			return;
		}

		$this->log("Requesting backup of the files that were not successfully uploaded");
		$this->cloud_backup($undone_files);
		$this->cloud_backup_finish($undone_files);

		$this->log("Resume backup ($resumption_no): finish run");

		$this->backup_finish($next_resumption, true);

	}

	function backup_all() {
		$this->backup(true,true);
	}
	
	function backup_files() {
		# Note that the "false" for database gets over-ridden automatically if they turn out to have the same schedules
		$this->cronrun_type = "files";
		$this->backup(true,false);
	}
	
	function backup_database() {
		# Note that nothing will happen if the file backup had the same schedule
		$this->cronrun_type = "database";
		$this->backup(false,true);
	}

	function logfile_open($nonce) {
		//set log file name and open log file
		$updraft_dir = $this->backups_dir_location();
		$this->logfile_name =  $updraft_dir. "/log.$nonce.txt";
		// Use append mode in case it already exists
		$this->logfile_handle = fopen($this->logfile_name, 'a');
	}

	function check_backup_race( $to_delete = false ) {
		// Avoid caching
		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", "_transient_updraftplus_backup_job_nonce"));
		$cur_trans = ( is_object( $row ) ) ? $row->option_value : "";
		// Check if another backup job ID is stored in the transient
		if ($cur_trans != "" && $cur_trans != $this->nonce) {
			// Also check if that job is of the same type as ours, as two cron jobs could legitimately fire at the same time
			$otherjob_crontype = get_transient("updraftplus_runtype_".$cur_trans);
			// $this->cronrun_type should be "files", "database" or blank (if we were not run via a cron job)
			if ($otherjob_crontype == $this->cronrun_type) {
				$this->log("Another backup job ($cur_trans) of the same type ($otherjob_crontype) appears to now be running - terminating our run (apparent race condition)");
				$bdir = $this->backups_dir_location();
				if (is_array($to_delete)) {
					foreach ($to_delete as $key => $file) {
						if (is_file($bdir.'/'.$file)) {
							$this->log("Deleting the file we created: ".$file);
							@unlink($bdir.'/'.$file);
						}
					}
				}
				exit;
			}
		}
	}

	function backup($backup_files, $backup_database) {

		@ignore_user_abort(true);
		//generate backup information
		$this->backup_time_nonce();

		$this->logfile_open($this->nonce);

		// Log some information that may be helpful
		global $wp_version;
		$this->log("PHP version: ".phpversion()." WordPress version: ".$wp_version." Updraft version: ".$this->version." PHP Max Execution Time: ".ini_get("max_execution_time")." Backup files: $backup_files (schedule: ".get_option('updraft_interval','unset').") Backup DB: $backup_database (schedule: ".get_option('updraft_interval_database','unset').")");

		# If the files and database schedules are the same, and if this the file one, then we rope in database too.
		# On the other hand, if the schedules were the same and this was the database run, then there is nothing to do.
		if (get_option('updraft_interval') == get_option('updraft_interval_database') || get_option('updraft_interval_database','xyz') == 'xyz' ) {
			$backup_database = ($backup_files == true) ? true : false;
		}

		$this->log("Processed schedules. Tasks now: Backup files: $backup_files Backup DB: $backup_database");

		$clear_nonce_transient = false;

		# Possibly now nothing is to be done, except to close the log file
		if ($backup_files || $backup_database) {

			$clear_nonce_transient = true;

			// Do not set the transient or schedule the resume event until now, when we know there is something to do - otherwise 'vacatated' runs (when the database is on the same schedule as the files, and they get combined, leading to an empty run) can over-write the resume event and prevent resumption (because it is 'successful' - there was nothing to do).
			// If we don't finish in 3 hours, then we won't finish
			// This transient indicates the identity of the current backup job (which can be used to find the files and logfile)
			set_transient("updraftplus_backup_job_nonce",$this->nonce,3600*3);
			set_transient("updraftplus_backup_job_time",$this->backup_time,3600*3);
			// Schedule the even to run later, which checks on success and can resume the backup
			// We save the time to a variable because it is needed for un-scheduling
			$resume_delay = 300;
			wp_schedule_single_event(time()+$resume_delay, 'updraft_backup_resume', array(1));
			$this->log("In case we run out of time, scheduled a resumption at: $resume_delay seconds from now");

			$backup_contains = "";

			$backup_array = array();

			$this->check_backup_race();

			//backup directories and return a numerically indexed array of file paths to the backup files
			if ($backup_files) {
				$this->log("Beginning backup of directories");
				$backup_array = $this->backup_dirs();
				$backup_contains = "Files only (no database)";
			}

			$this->check_backup_race($backup_array);

			//backup DB and return string of file path
			if ($backup_database) {
				$this->log("Beginning backup of database");
				$db_backup = $this->backup_db();
				// add db path to rest of files
				if(is_array($backup_array)) $backup_array['db'] = $db_backup;
				$backup_contains = ($backup_files) ? "Files and database" : "Database only (no files)";
			}

			$this->check_backup_race($backup_array);
			set_transient("updraftplus_backupcontains", $backup_contains, 3600*3);

			//save this to our history so we can track backups for the retain feature
			$this->log("Saving backup history");
			// This is done before cloud despatch, because we want a record of what *should* be in the backup. Whether it actually makes it there or not is not yet known.
			$this->save_backup_history($backup_array);

			// Now encrypt the database, and re-save
			if ($backup_database && isset($backup_array['db'])) {
				$backup_array['db'] = $this->encrypt_file($backup_array['db']);
				// Re-save with the possibly-altered database filename
				$this->save_backup_history($backup_array);
			}

			//cloud operations (S3,Google Drive,FTP,email,nothing)
			//this also calls the retain (prune) feature at the end (done in this method to reuse existing cloud connections)
			if(is_array($backup_array) && count($backup_array) >0) {
				$this->log("Beginning dispatch of backup to remote");
				$this->cloud_backup($backup_array);
			}

			//save the last backup info, including errors, if any
			$this->log("Saving last backup information into WordPress db");
			$this->save_last_backup($backup_array);

			// Send the email
			$this->cloud_backup_finish($backup_array, $clear_nonce_transient);

		}

		// Close log file; delete and also delete transients if not in debug mode
		$this->backup_finish(1, $clear_nonce_transient);

	}

	// Encrypts the file if the option is set; returns the basename of the file (according to whether it was encrypted or nto)
	function encrypt_file($file) {
		$encryption = get_option('updraft_encryptionphrase');
		if (strlen($encryption) > 0) {
			$this->log("$file: applying encryption");
			$encryption_error = 0;
			require_once(UPDRAFTPLUS_DIR.'/includes/Rijndael.php');
			$rijndael = new Crypt_Rijndael();
			$rijndael->setKey($encryption);
			$updraft_dir = $this->backups_dir_location();
			$in_handle = @fopen($updraft_dir.'/'.$file,'r');
			$buffer = "";
			while (!feof ($in_handle)) {
				$buffer .= fread($in_handle, 16384);
			}
			fclose ($in_handle);
			$out_handle = @fopen($updraft_dir.'/'.$file.'.crypt','w');
			if (!fwrite($out_handle, $rijndael->encrypt($buffer))) {$encryption_error = 1;}
			fclose ($out_handle);
			if (0 == $encryption_error) {
				$this->log("$file: encryption successful");
				# Delete unencrypted file
				@unlink($updraft_dir.'/'.$file);
				return basename($file.'.crypt');
			} else {
				$this->log("Encryption error occurred when encrypting database. Encryption aborted.");
				$this->error("Encryption error occurred when encrypting database. Encryption aborted.");
				return basename($file);
			}
		} else {
			return basename($file);
		}
	}

	function backup_finish($cancel_event, $clear_nonce_transient) {

		// In fact, leaving the hook to run (if debug is set) is harmless, as the resume job should only do tasks that were left unfinished, which at this stage is none.
		if (empty($this->errors)) {
			if ($clear_nonce_transient) {
				$this->log("There were no errors in the uploads, so the 'resume' event is being unscheduled");
				wp_clear_scheduled_hook('updraft_backup_resume', array($cancel_event));
				delete_transient("updraftplus_backup_job_nonce");
				delete_transient("updraftplus_backup_job_time");
			}
		} else {
			$this->log("There were errors in the uploads, so the 'resume' event is remaining scheduled");
		}

		@fclose($this->logfile_handle);

		// Don't delete the log file now; delete it upon rotation
 		//if (!get_option('updraft_debug_mode')) @unlink($this->logfile_name);

	}

	function cloud_backup_finish($backup_array) {

		// Send the results email if requested
		if(get_option('updraft_email') != "" && get_option('updraft_service') != 'email') $this->send_results_email();

	}

	function send_results_email() {

		$sendmail_to = get_option('updraft_email');

		$this->log("Sending email report to: ".$sendmail_to);

		$append_log = (get_option('updraft_debug_mode') && $this->logfile_name != "") ? "\r\nLog contents:\r\n".file_get_contents($this->logfile_name) : "" ;

		wp_mail($sendmail_to,'Backed up: '.get_bloginfo('name').' (UpdraftPlus '.$this->version.') '.date('Y-m-d H:i',time()),'Site: '.site_url()."\r\nUpdraftPlus WordPress backup is complete.\r\nBackup contains: ".get_transient("updraftplus_backupcontains")."\r\n\r\n".$this->wordshell_random_advert(0)."\r\n".$append_log);

	}

	function save_last_backup($backup_array) {
		$success = (empty($this->errors)) ? 1 : 0;

		$last_backup = array('backup_time'=>$this->backup_time, 'backup_array'=>$backup_array, 'success'=>$success, 'errors'=>$this->errors, 'backup_nonce' => $this->nonce);

		update_option('updraft_last_backup', $last_backup);
	}

	// This should be called whenever a file is successfully uploaded
	function uploaded_file($file, $id = false) {
		# We take an MD5 hash because set_transient wants a name of 45 characters or less
		$hash = md5($file);
		$this->log("$file: $hash: recording as successfully uploaded");
		set_transient("updraft_".$hash, "yes", 3600*4);
		if ($id) {
			$ids = get_option('updraft_file_ids', array() );
			$ids[$file] = $id;
			update_option('updraft_file_ids',$ids);
			$this->log("Stored file<->id correlation in database ($file <-> $id)");
		}
		// Delete local files if the option is set
		$this->delete_local($file);

	}

	// Dispatch to the relevant function
	function cloud_backup($backup_array) {
		$service = get_option('updraft_service');
		$this->log("Cloud backup selection: ".$service);
		@set_time_limit(900);

		$method_include = UPDRAFTPLUS_DIR.'/methods/'.$service.'.php';
		if (file_exists($method_include)) require_once($method_include);

		$objname = "UpdraftPlus_BackupModule_${service}";
		if (method_exists($objname, "backup")) {
			// New style - external, allowing more plugability
			$remote_obj = new $objname;
			$remote_obj->backup($backup_array);
		} else {
			$this->prune_retained_backups("local", null, null);
		}
	}

	function prune_file($updraft_service, $dofile, $method_object = null, $object_passback = null ) {
		$this->log("Delete this file: $dofile, service=$updraft_service");
		$fullpath = trailingslashit(get_option('updraft_dir')).$dofile;
		// delete it if it's locally available
		if (file_exists($fullpath)) {
			$this->log("Deleting local copy ($fullpath)");
			@unlink($fullpath);
		}

		// Despatch to the particular method's deletion routine
		if (!is_null($method_object)) $method_object->delete($dofile, $object_passback);
	}

	// Carries out retain behaviour. Pass in a valid S3 or FTP object and path if relevant.
	function prune_retained_backups($updraft_service, $backup_method_object = null, $backup_passback = null) {

		$this->log("Retain: beginning examination of existing backup sets");

		// Number of backups to retain
		$updraft_retain = get_option('updraft_retain', 1);
		$retain = (is_numeric($updraft_retain)) ? $updraft_retain : 1;
		$this->log("Retain: user setting: number to retain = $retain");

		// Returns an array, most recent first, of backup sets
		$backup_history = $this->get_backup_history();
		$db_backups_found = 0;
		$file_backups_found = 0;
		$this->log("Number of backup sets in history: ".count($backup_history));

		foreach ($backup_history as $backup_datestamp => $backup_to_examine) {
			// $backup_to_examine is an array of file names, keyed on db/plugins/themes/uploads
			// The new backup_history array is saved afterwards, so remember to unset the ones that are to be deleted
			$this->log("Examining backup set with datestamp: $backup_datestamp");

			if (isset($backup_to_examine['db'])) {
				$db_backups_found++;
				$this->log("$backup_datestamp: this set includes a database (".$backup_to_examine['db']."); db count is now $db_backups_found");
				if ($db_backups_found > $retain) {
					$this->log("$backup_datestamp: over retain limit; will delete this database");
					$dofile = $backup_to_examine['db'];
					if (!empty($dofile)) $this->prune_file($updraft_service, $dofile, $backup_method_object, $backup_passback);
					unset($backup_to_examine['db']);
				}
			}
			if (isset($backup_to_examine['plugins']) || isset($backup_to_examine['themes']) || isset($backup_to_examine['uploads']) || isset($backup_to_examine['others'])) {
				$file_backups_found++;
				$this->log("$backup_datestamp: this set includes files; fileset count is now $file_backups_found");
				if ($file_backups_found > $retain) {
					$this->log("$backup_datestamp: over retain limit; will delete this file set");
					$file = isset($backup_to_examine['plugins']) ? $backup_to_examine['plugins'] : "";
					$file2 = isset($backup_to_examine['themes']) ? $backup_to_examine['themes'] : "";
					$file3 = isset($backup_to_examine['uploads']) ? $backup_to_examine['uploads'] : "";
					$file4 = isset($backup_to_examine['others']) ? $backup_to_examine['others'] : "";
					foreach (array($file, $file2, $file3, $file4) as $dofile) {
						if (!empty($dofile)) $this->prune_file($updraft_service, $dofile, $backup_method_object, $backup_passback);
					}
					unset($backup_to_examine['plugins']);
					unset($backup_to_examine['themes']);
					unset($backup_to_examine['uploads']);
					unset($backup_to_examine['others']);
				}
			}
			// Delete backup set completely if empty, o/w just remove DB
			if (count($backup_to_examine) == 0 || (count($backup_to_examine) == 1 && isset($backup_to_examine['nonce']))) {
				$this->log("$backup_datestamp: this backup set is now empty; will remove from history");
				unset($backup_history[$backup_datestamp]);
				if (isset($backup_to_examine['nonce'])) {
					$fullpath = trailingslashit(get_option('updraft_dir')).'log.'.$backup_to_examine['nonce'].'.txt';
					if (is_file($fullpath)) {
						$this->log("$backup_datestamp: deleting log file (log.".$backup_to_examine['nonce'].".txt)");
						@unlink($fullpath);
					} else {
						$this->log("$backup_datestamp: corresponding log file not found - must have already been deleted");
					}
				} else {
					$this->log("$backup_datestamp: no nonce record found in the backup set, so cannot delete any remaining log file");
				}
			} else {
				$this->log("$backup_datestamp: this backup set remains non-empty; will retain in history");
				$backup_history[$backup_datestamp] = $backup_to_examine;
			}
		}
		$this->log("Retain: saving new backup history (sets now: ".count($backup_history).") and finishing retain operation");
		update_option('updraft_backup_history',$backup_history);
	}

	function delete_local($file) {
		if(get_option('updraft_delete_local')) {
			$this->log("Deleting local file: $file");
		//need error checking so we don't delete what isn't successfully uploaded?
			$fullpath = trailingslashit(get_option('updraft_dir')).$file;
			return unlink($fullpath);
		}
		return true;
	}
	
	function backup_dirs() {
		if(!$this->backup_time) $this->backup_time_nonce();
		$wp_themes_dir = WP_CONTENT_DIR.'/themes';
		$wp_upload_dir = wp_upload_dir();
		$wp_upload_dir = $wp_upload_dir['basedir'];
		$wp_plugins_dir = WP_PLUGIN_DIR;

		if(!class_exists('PclZip')) require_once(ABSPATH.'/wp-admin/includes/class-pclzip.php');

		$updraft_dir = $this->backups_dir_location();
		if(!is_writable($updraft_dir)) $this->error('Backup directory is not writable, or does not exist.','fatal');

		//get the blog name and rip out all non-alphanumeric chars other than _
		$blog_name = str_replace(' ','_',get_bloginfo());
		$blog_name = preg_replace('/[^A-Za-z0-9_]/','', $blog_name);
		if(!$blog_name) $blog_name = 'non_alpha_name';

		$backup_file_base = $updraft_dir.'/backup_'.date('Y-m-d-Hi',$this->backup_time).'_'.$blog_name.'_'.$this->nonce;

		$backup_array = array();

		# Plugins
		@set_time_limit(900);
		if (get_option('updraft_include_plugins', true)) {
			$this->log("Beginning backup of plugins");
			$full_path = $backup_file_base.'-plugins.zip';
			$plugins = new PclZip($full_path);
			# The paths in the zip should then begin with 'plugins', having removed WP_CONTENT_DIR from the front
			if (!$plugins->create($wp_plugins_dir,PCLZIP_OPT_REMOVE_PATH,WP_CONTENT_DIR)) {
				$this->error('Could not create plugins zip. Error was '.$php_errmsg,'fatal');
				$this->log('ERROR: PclZip failure: Could not create plugins zip');
			} else {
				$this->log("Created plugins zip - file size is ".filesize($full_path)." bytes");
			}
			$backup_array['plugins'] = basename($full_path);
		} else {
			$this->log("No backup of plugins: excluded by user's options");
		}

		$this->check_backup_race($backup_array);

		# Themes
		@set_time_limit(900);
		if (get_option('updraft_include_themes', true)) {
			$this->log("Beginning backup of themes");
			$full_path = $backup_file_base.'-themes.zip';
			$themes = new PclZip($full_path);
			if (!$themes->create($wp_themes_dir,PCLZIP_OPT_REMOVE_PATH,WP_CONTENT_DIR)) {
				$this->error('Could not create themes zip. Error was '.$php_errmsg,'fatal');
				$this->log('ERROR: PclZip failure: Could not create themes zip');
			} else {
				$this->log("Created themes zip - file size is ".filesize($full_path)." bytes");
			}
			$backup_array['themes'] = basename($full_path);
		} else {
			$this->log("No backup of themes: excluded by user's options");
		}

		$this->check_backup_race($backup_array);

		# Uploads
		@set_time_limit(900);
		if (get_option('updraft_include_uploads', true)) {
			$this->log("Beginning backup of uploads");
			$full_path = $backup_file_base.'-uploads.zip';
			$uploads = new PclZip($full_path);
			if (!$uploads->create($wp_upload_dir,PCLZIP_OPT_REMOVE_PATH,WP_CONTENT_DIR)) {
				$this->error('Could not create uploads zip. Error was '.$php_errmsg,'fatal');
				$this->log('ERROR: PclZip failure: Could not create uploads zip');
			} else {
				$this->log("Created uploads zip - file size is ".filesize($full_path)." bytes");
			}
			$backup_array['uploads'] = basename($full_path);
		} else {
			$this->log("No backup of uploads: excluded by user's options");
		}

		$this->check_backup_race($backup_array);

		# Others
		@set_time_limit(900);
		if (get_option('updraft_include_others', true)) {
			$this->log("Beginning backup of other directories found in the content directory");
			$full_path=$backup_file_base.'-others.zip';
			$others = new PclZip($full_path);
			// http://www.phpconcept.net/pclzip/user-guide/53
			/* First parameter to create is:
				An array of filenames or dirnames,
				or
				A string containing the filename or a dirname,
				or
				A string containing a list of filename or dirname separated by a comma.
			*/
			// First, see what we can find. We always want to exclude these:
			$wp_themes_dir = WP_CONTENT_DIR.'/themes';
			$wp_upload_dir = wp_upload_dir();
			$wp_upload_dir = $wp_upload_dir['basedir'];
			$wp_plugins_dir = WP_PLUGIN_DIR;
			$updraft_dir = untrailingslashit(get_option('updraft_dir'));

			# Initialise
			$other_dirlist = array(); 
			
			$others_skip = preg_split("/,/",get_option('updraft_include_others_exclude',UPDRAFT_DEFAULT_OTHERS_EXCLUDE));
			# Make the values into the keys
			$others_skip = array_flip($others_skip);

			$this->log('Looking for candidates to back up in: '.WP_CONTENT_DIR);
			if ($handle = opendir(WP_CONTENT_DIR)) {
				while (false !== ($entry = readdir($handle))) {
					$candidate = WP_CONTENT_DIR.'/'.$entry;
					if ($entry == "." || $entry == "..") { ; }
					elseif ($candidate == $updraft_dir) { $this->log("$entry: skipping: this is the updraft directory"); }
					elseif ($candidate == $wp_themes_dir) { $this->log("$entry: skipping: this is the themes directory"); }
					elseif ($candidate == $wp_upload_dir) { $this->log("$entry: skipping: this is the uploads directory"); }
					elseif ($candidate == $wp_plugins_dir) { $this->log("$entry: skipping: this is the plugins directory"); }
					elseif (isset($others_skip[$entry])) { $this->log("$entry: skipping: excluded by options"); }
					else { $this->log("$entry: adding to list"); array_push($other_dirlist,$candidate); }
				}
			} else {
				$this->log('ERROR: Could not read the content directory: '.WP_CONTENT_DIR);
			}

			if (count($other_dirlist)>0) {
				if (!$others->create($other_dirlist,PCLZIP_OPT_REMOVE_PATH,WP_CONTENT_DIR)) {
					$this->error('Could not create other zip. Error was '.$php_errmsg,'fatal');
					$this->log('ERROR: PclZip failure: Could not create other zip');
				} else {
					$this->log("Created other directories zip - file size is ".filesize($full_path)." bytes");
				}
				$backup_array['others'] = basename($full_path);
			} else {
				$this->log("No backup of other directories: there was nothing found to back up");
			}
		} else {
			$this->log("No backup of other directories: excluded by user's options");
		}
		return $backup_array;
	}

	function save_backup_history($backup_array) {
		if(is_array($backup_array)) {
			$backup_history = get_option('updraft_backup_history');
			$backup_history = (is_array($backup_history)) ? $backup_history : array();
			$backup_array['nonce'] = $this->nonce;
			$backup_history[$this->backup_time] = $backup_array;
			update_option('updraft_backup_history',$backup_history);
		} else {
			$this->error('Could not save backup history because we have no backup array. Backup probably failed.');
		}
	}
	
	function get_backup_history() {
		//$backup_history = get_option('updraft_backup_history');
		//by doing a raw DB query to get the most up-to-date data from this option we slightly narrow the window for the multiple-cron race condition
		global $wpdb;
		$backup_history = @unserialize($wpdb->get_var($wpdb->prepare("SELECT option_value from $wpdb->options WHERE option_name='updraft_backup_history'")));
		if(is_array($backup_history)) {
			krsort($backup_history); //reverse sort so earliest backup is last on the array.  this way we can array_pop
		} else {
			$backup_history = array();
		}
		return $backup_history;
	}
	
	
	/*START OF WB-DB-BACKUP BLOCK*/

	function backup_db() {

		$total_tables = 0;

		global $table_prefix, $wpdb;
		if(!$this->backup_time) $this->backup_time_nonce();

		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);
		
		$updraft_dir = $this->backups_dir_location();
		//get the blog name and rip out all non-alphanumeric chars other than _
		$blog_name = str_replace(' ','_',get_bloginfo());
		$blog_name = preg_replace('/[^A-Za-z0-9_]/','', $blog_name);
		if (!$blog_name) $blog_name = 'non_alpha_name';

		$backup_file_base = $updraft_dir.'/backup_'.date('Y-m-d-Hi',$this->backup_time).'_'.$blog_name.'_'.$this->nonce;
		if (is_writable($updraft_dir)) {
			if (function_exists('gzopen')) {
				$this->dbhandle = @gzopen($backup_file_base.'-db.gz','w');
			} else {
				$this->dbhandle = @fopen($backup_file_base.'-db.gz', 'w');
			}
			if(!$this->dbhandle) {
				//$this->error(__('Could not open the backup file for writing!','wp-db-backup'));
			}
		} else {
			//$this->error(__('The backup directory is not writable!','wp-db-backup'));
		}
		
		//Begin new backup of MySql
		$this->stow("# " . __('WordPress MySQL database backup','wp-db-backup') . "\n");
		$this->stow("#\n");
		$this->stow("# " . sprintf(__('Generated: %s','wp-db-backup'),date("l j. F Y H:i T")) . "\n");
		$this->stow("# " . sprintf(__('Hostname: %s','wp-db-backup'),DB_HOST) . "\n");
		$this->stow("# " . sprintf(__('Database: %s','wp-db-backup'),$this->backquote(DB_NAME)) . "\n");
		$this->stow("# --------------------------------------------------------\n");
		

		if (defined("DB_CHARSET")) {
			$this->stow("/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n");
			$this->stow("/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n");
			$this->stow("/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n");
			$this->stow("/*!40101 SET NAMES " . DB_CHARSET . " */;\n");
		}
		$this->stow("/*!40101 SET foreign_key_checks = 0 */;\n");

		foreach ($all_tables as $table) {
			$total_tables++;
			// Increase script execution time-limit to 15 min for every table.
			if ( !ini_get('safe_mode') || strtolower(ini_get('safe_mode')) == "off") @set_time_limit(15*60);
			# === is needed, otherwise 'false' matches (i.e. prefix does not match)
			if ( strpos($table, $table_prefix) === 0 ) {
				// Create the SQL statements
				$this->stow("# --------------------------------------------------------\n");
				$this->stow("# " . sprintf(__('Table: %s','wp-db-backup'),$this->backquote($table)) . "\n");
				$this->stow("# --------------------------------------------------------\n");
				$this->backup_table($table);
			} else {
				$this->stow("# --------------------------------------------------------\n");
				$this->stow("# " . sprintf(__('Skipping non-WP table: %s','wp-db-backup'),$this->backquote($table)) . "\n");
				$this->stow("# --------------------------------------------------------\n");				
			}
		}

		if (defined("DB_CHARSET")) {
			$this->stow("/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n");
			$this->stow("/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n");
			$this->stow("/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n");
		}

		$this->close($this->dbhandle);

		if (count($this->errors)) {
			return false;
		} else {
			# We no longer encrypt here - because the operation can take long, we made it resumable and moved it to the upload loop
			$this->log("Total database tables backed up: $total_tables");
			return basename($backup_file_base.'-db.gz');
		}
		
	} //wp_db_backup

	/**
	 * Taken partially from phpMyAdmin and partially from
	 * Alain Wolf, Zurich - Switzerland
	 * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
	 * Modified by Scott Merrill (http://www.skippy.net/) 
	 * to use the WordPress $wpdb object
	 * @param string $table
	 * @param string $segment
	 * @return void
	 */
	function backup_table($table, $segment = 'none') {
		global $wpdb;

		$total_rows = 0;

		$table_structure = $wpdb->get_results("DESCRIBE $table");
		if (! $table_structure) {
			//$this->error(__('Error getting table details','wp-db-backup') . ": $table");
			return false;
		}
	
		if(($segment == 'none') || ($segment == 0)) {
			// Add SQL statement to drop existing table
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Delete any existing table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			$this->stow("DROP TABLE IF EXISTS " . $this->backquote($table) . ";\n");
			
			// Table structure
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('Table structure of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
			$this->stow("\n");
			
			$create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
			if (false === $create_table) {
				$err_msg = sprintf(__('Error with SHOW CREATE TABLE for %s.','wp-db-backup'), $table);
				//$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
			$this->stow($create_table[0][1] . ' ;');
			
			if (false === $table_structure) {
				$err_msg = sprintf(__('Error getting table structure of %s','wp-db-backup'), $table);
				//$this->error($err_msg);
				$this->stow("#\n# $err_msg\n#\n");
			}
		
			// Comment in SQL-file
			$this->stow("\n\n");
			$this->stow("#\n");
			$this->stow('# ' . sprintf(__('Data contents of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("#\n");
		}
		
		if(($segment == 'none') || ($segment >= 0)) {
			$defs = array();
			$ints = array();
			foreach ($table_structure as $struct) {
				if ( (0 === strpos($struct->Type, 'tinyint')) ||
					(0 === strpos(strtolower($struct->Type), 'smallint')) ||
					(0 === strpos(strtolower($struct->Type), 'mediumint')) ||
					(0 === strpos(strtolower($struct->Type), 'int')) ||
					(0 === strpos(strtolower($struct->Type), 'bigint')) ) {
						$defs[strtolower($struct->Field)] = ( null === $struct->Default ) ? 'NULL' : $struct->Default;
						$ints[strtolower($struct->Field)] = "1";
				}
			}
			
			
			// Batch by $row_inc
			if ( ! defined('ROWS_PER_SEGMENT') ) {
				define('ROWS_PER_SEGMENT', 100);
			}
			
			if($segment == 'none') {
				$row_start = 0;
				$row_inc = ROWS_PER_SEGMENT;
			} else {
				$row_start = $segment * ROWS_PER_SEGMENT;
				$row_inc = ROWS_PER_SEGMENT;
			}
			do {

				if ( !ini_get('safe_mode') || strtolower(ini_get('safe_mode')) == "off") @set_time_limit(15*60);
				$table_data = $wpdb->get_results("SELECT * FROM $table $where LIMIT {$row_start}, {$row_inc}", ARRAY_A);
				$entries = 'INSERT INTO ' . $this->backquote($table) . ' VALUES (';	
				//    \x08\\x09, not required
				$search = array("\x00", "\x0a", "\x0d", "\x1a");
				$replace = array('\0', '\n', '\r', '\Z');
				if($table_data) {
					foreach ($table_data as $row) {
						$total_rows++;
						$values = array();
						foreach ($row as $key => $value) {
							if ($ints[strtolower($key)]) {
								// make sure there are no blank spots in the insert syntax,
								// yet try to avoid quotation marks around integers
								$value = ( null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
								$values[] = ( '' === $value ) ? "''" : $value;
							} else {
								$values[] = "'" . str_replace($search, $replace, $this->sql_addslashes($value)) . "'";
							}
						}
						$this->stow(" \n" . $entries . implode(', ', $values) . ');');
					}
					$row_start += $row_inc;
				}
			} while((count($table_data) > 0) and ($segment=='none'));
		}
		
		if(($segment == 'none') || ($segment < 0)) {
			// Create footer/closing comment in SQL-file
			$this->stow("\n");
			$this->stow("#\n");
			$this->stow("# " . sprintf(__('End of data contents of table %s','wp-db-backup'),$this->backquote($table)) . "\n");
			$this->stow("# --------------------------------------------------------\n");
			$this->stow("\n");
		}
 		$this->log("Table $table: Total rows added: $total_rows");

	} // end backup_table()


	function stow($query_line) {
		if (function_exists('gzopen')) {
			if(! @gzwrite($this->dbhandle, $query_line)) {
				//$this->error(__('There was an error writing a line to the backup script:','wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg);
			}
		} else {
			if(false === @fwrite($this->dbhandle, $query_line)) {
				//$this->error(__('There was an error writing a line to the backup script:','wp-db-backup') . '  ' . $query_line . '  ' . $php_errormsg);
			}
		}
	}


	function close($handle) {
		if (function_exists('gzopen')) {
			gzclose($handle);
		} else {
			fclose($handle);
		}
	}

	function error($error,$severity='') {
		$this->errors[] = $error;
		return true;
	}

	/**
	 * Add backquotes to tables and db-names in
	 * SQL queries. Taken from phpMyAdmin.
	 */
	function backquote($a_name) {
		if (!empty($a_name) && $a_name != '*') {
			if (is_array($a_name)) {
				$result = array();
				reset($a_name);
				while(list($key, $val) = each($a_name)) 
					$result[$key] = '`' . $val . '`';
				return $result;
			} else {
				return '`' . $a_name . '`';
			}
		} else {
			return $a_name;
		}
	}

	/**
	 * Better addslashes for SQL queries.
	 * Taken from phpMyAdmin.
	 */
	function sql_addslashes($a_string = '', $is_like = false) {
		if ($is_like) $a_string = str_replace('\\', '\\\\\\\\', $a_string);
		else $a_string = str_replace('\\', '\\\\', $a_string);
		return str_replace('\'', '\\\'', $a_string);
	} 

	/*END OF WP-DB-BACKUP BLOCK */

	/*
	this function is both the backup scheduler and ostensibly a filter callback for saving the option.
	it is called in the register_setting for the updraft_interval, which means when the admin settings 
	are saved it is called.  it returns the actual result from wp_filter_nohtml_kses (a sanitization filter) 
	so the option can be properly saved.
	*/
	function schedule_backup($interval) {
		//clear schedule and add new so we don't stack up scheduled backups
		wp_clear_scheduled_hook('updraft_backup');
		switch($interval) {
			case 'every4hours':
			case 'every8hours':
			case 'twicedaily':
			case 'daily':
			case 'weekly':
			case 'fortnightly':
			case 'monthly':
				wp_schedule_event(time()+30, $interval, 'updraft_backup');
			break;
		}
		return wp_filter_nohtml_kses($interval);
	}

	function schedule_backup_database($interval) {
		//clear schedule and add new so we don't stack up scheduled backups
		wp_clear_scheduled_hook('updraft_backup_database');
		switch($interval) {
			case 'every4hours':
			case 'every8hours':
			case 'twicedaily':
			case 'daily':
			case 'weekly':
			case 'fortnightly':
			case 'monthly':
				wp_schedule_event(time()+30, $interval, 'updraft_backup_database');
			break;
		}
		return wp_filter_nohtml_kses($interval);
	}

	//wp-cron only has hourly, daily and twicedaily, so we need to add some of our own
	function modify_cron_schedules($schedules) {
		$schedules['weekly'] = array( 'interval' => 604800, 'display' => 'Once Weekly' );
		$schedules['fortnightly'] = array( 'interval' => 1209600, 'display' => 'Once Each Fortnight' );
		$schedules['monthly'] = array( 'interval' => 2592000, 'display' => 'Once Monthly' );
		$schedules['every4hours'] = array( 'interval' => 14400, 'display' => 'Every 4 hours' );
		$schedules['every8hours'] = array( 'interval' => 28800, 'display' => 'Every 8 hours' );
		return $schedules;
	}
	
	function backups_dir_location() {
		$updraft_dir = untrailingslashit(get_option('updraft_dir'));
		$default_backup_dir = WP_CONTENT_DIR.'/updraft';
		//if the option isn't set, default it to /backups inside the upload dir
		$updraft_dir = ($updraft_dir)?$updraft_dir:$default_backup_dir;
		//check for the existence of the dir and an enumeration preventer.
		if(!is_dir($updraft_dir) || !is_file($updraft_dir.'/index.html') || !is_file($updraft_dir.'/.htaccess')) {
			@mkdir($updraft_dir,0777,true); //recursively create the dir with 0777 permissions. 0777 is default for php creation.  not ideal, but I'll get back to this
			@file_put_contents($updraft_dir.'/index.html','Nothing to see here.');
			@file_put_contents($updraft_dir.'/.htaccess','deny from all');
		}
		return $updraft_dir;
	}
	
	function updraft_download_backup() {
		$type = $_POST['type'];
		$timestamp = (int)$_POST['timestamp'];
		$backup_history = $this->get_backup_history();
		$file = $backup_history[$timestamp][$type];
		$fullpath = trailingslashit(get_option('updraft_dir')).$file;
		if(!is_readable($fullpath)) {
			//if the file doesn't exist and they're using one of the cloud options, fetch it down from the cloud.
			$this->download_backup($file);
		}
		if(@is_readable($fullpath) && is_file($fullpath)) {
			$len = filesize($fullpath);

			$filearr = explode('.',$file);
// 			//we've only got zip and gz...for now
			$file_ext = array_pop($filearr);
			if($file_ext == 'zip') {
				header('Content-type: application/zip');
			} else {
				// This catches both when what was popped was 'crypt' (*-db.gz.crypt) and when it was 'gz' (unencrypted)
				header('Content-type: application/x-gzip');
			}
			header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
			header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
			header("Content-Length: $len;");
			if ($file_ext == 'crypt') {
				header("Content-Disposition: attachment; filename=\"".substr($file,0,-6)."\";");
			} else {
				header("Content-Disposition: attachment; filename=\"$file\";");
			}
			ob_end_flush();
			if ($file_ext == 'crypt') {
				$encryption = get_option('updraft_encryptionphrase');
				if ($encryption == "") {
					$this->error('Decryption of database failed: the database file is encrypted, but you have no encryption key entered.');
				} else {
					require_once(dirname(__FILE__).'/includes/Rijndael.php');
					$rijndael = new Crypt_Rijndael();
					$rijndael->setKey($encryption);
					$in_handle = fopen($fullpath,'r');
					$ciphertext = "";
					while (!feof ($in_handle)) {
						$ciphertext .= fread($in_handle, 16384);
					}
					fclose ($in_handle);
					print $rijndael->decrypt($ciphertext);
				}
			} else {
				readfile($fullpath);
			}
			$this->delete_local($file);
			exit; //we exit immediately because otherwise admin-ajax appends an additional zero to the end
		} else {
			echo 'Download failed. File '.$fullpath.' did not exist or was unreadable. If you delete local backups then remote retrieval may have failed.';
		}
	}
	
	function download_backup($file) {
		$service = get_option('updraft_service');

		$method_include = UPDRAFTPLUS_DIR.'/methods/'.$service.'.php';
		if (file_exists($method_include)) require_once($method_include);

		$objname = "UpdraftPlus_BackupModule_${service}";
		if (method_exists($objname, "download")) {
			$remote_obj = new $objname;
			$remote_obj->download($file);
		} else {
			$this->error('Automatic backup restoration is not available with the method: $service.');
		}

	}
		
	function restore_backup($timestamp) {
		global $wp_filesystem;
		$backup_history = get_option('updraft_backup_history');
		if(!is_array($backup_history[$timestamp])) {
			echo '<p>This backup does not exist in the backup history - restoration aborted. Timestamp: '.$timestamp.'</p><br/>';
			return false;
		}

		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_restore&backup_timestamp=$timestamp"); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message )
				show_message($message); 
			exit; 
		}
		
		//if we make it this far then WP_Filesystem has been instantiated and is functional (tested with ftpext, what about suPHP and other situations where direct may work?)
		echo '<span style="font-weight:bold">Restoration Progress </span><div id="updraft-restore-progress">';

		$updraft_dir = trailingslashit(get_option('updraft_dir'));
		foreach($backup_history[$timestamp] as $type=>$file) {
			$fullpath = $updraft_dir.$file;
			if(!is_readable($fullpath) && $type != 'db') {
				$this->download_backup($file);
			}
			# Types: uploads, themes, plugins, others, db
			if(is_readable($fullpath) && $type != 'db') {
				if(!class_exists('WP_Upgrader')) {
					require_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );
				}
				require_once(UPDRAFTPLUS_DIR.'/includes/updraft-restorer.php');
				$restorer = new Updraft_Restorer();
				$val = $restorer->restore_backup($fullpath,$type);
				if(is_wp_error($val)) {
					print_r($val);
					echo '</div>'; //close the updraft_restore_progress div even if we error
					return false;
				}
			}
		}
		echo '</div>'; //close the updraft_restore_progress div
		# The 'off' check is for badly configured setups - http://wordpress.org/support/topic/plugin-wp-super-cache-warning-php-safe-mode-enabled-but-safe-mode-is-off
		if(ini_get('safe_mode') && strtolower(ini_get('safe_mode')) != "off") {
			echo "<p>DB could not be restored because PHP safe_mode is active on your server.  You will need to manually restore the file via phpMyAdmin or another method.</p><br/>";
			return false;
		}
		return true;
	}

	//deletes the -old directories that are created when a backup is restored.
	function delete_old_dirs() {
		global $wp_filesystem;
		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_delete_old_dirs"); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message )
				show_message($message); 
			exit; 
		}
		
		$to_delete = array('themes-old','plugins-old','uploads-old','others-old');

		foreach($to_delete as $name) {
			//recursively delete
			if(!$wp_filesystem->delete(WP_CONTENT_DIR.'/'.$name, true)) {
				return false;
			}
		}
		return true;
	}
	
	//scans the content dir to see if any -old dirs are present
	function scan_old_dirs() {
		$dirArr = scandir(WP_CONTENT_DIR);
		foreach($dirArr as $dir) {
			if(strpos($dir,'-old') !== false) {
				return true;
			}
		}
		return false;
	}
	
	
	function retain_range($input) {
		$input = (int)$input;
		if($input > 0 && $input < 3650) {
			return $input;
		} else {
			return 1;
		}
	}
	
	function create_backup_dir() {
		global $wp_filesystem;
		$credentials = request_filesystem_credentials("options-general.php?page=updraftplus&action=updraft_create_backup_dir"); 
		WP_Filesystem($credentials);
		if ( $wp_filesystem->errors->get_error_code() ) { 
			foreach ( $wp_filesystem->errors->get_error_messages() as $message ) show_message($message); 
			exit; 
		}

		$updraft_dir = untrailingslashit(get_option('updraft_dir'));
		$default_backup_dir = WP_CONTENT_DIR.'/updraft';
		$updraft_dir = ($updraft_dir)?$updraft_dir:$default_backup_dir;

		//chmod the backup dir to 0777. ideally we'd rather chgrp it but i'm not sure if it's possible to detect the group apache is running under (or what if it's not apache...)
		if(!$wp_filesystem->mkdir($updraft_dir, 0777)) return false;

		return true;
	}

	function memory_check_current() {
		# Returns in megabytes
		$memory_limit = ini_get('memory_limit');
		$memory_unit = $memory_limit[strlen($memory_limit)-1];
		$memory_limit = substr($memory_limit,0,strlen($memory_limit)-1);
		switch($memory_unit) {
			case 'K':
				$memory_limit = $memory_limit/1024;
			break;
			case 'G':
				$memory_limit = $memory_limit*1024;
			break;
			case 'M':
				//assumed size, no change needed
			break;
		}
		return $memory_limit;
	}

	function memory_check($memory) {
		$memory_limit = $this->memory_check_current();
		return ($memory_limit >= $memory)?true:false;
	}

	function execution_time_check($time) {
		return (ini_get('max_execution_time') >= $time)?true:false;
	}

	function admin_init() {
		if(get_option('updraft_debug_mode')) {
			ini_set('display_errors',1);
			error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
			ini_set('track_errors',1);
		}
		wp_enqueue_script('jquery');
		register_setting( 'updraft-options-group', 'updraft_interval', array($this,'schedule_backup') );
		register_setting( 'updraft-options-group', 'updraft_interval_database', array($this,'schedule_backup_database') );
		register_setting( 'updraft-options-group', 'updraft_retain', array($this,'retain_range') );
		register_setting( 'updraft-options-group', 'updraft_encryptionphrase', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_service', 'wp_filter_nohtml_kses' );

		register_setting( 'updraft-options-group', 'updraft_s3_login', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_s3_pass', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_s3_remote_path', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_googledrive_clientid', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_googledrive_secret', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_googledrive_remotepath', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_ftp_login', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_ftp_pass', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_ftp_remote_path', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_server_address', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_dir', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_email', 'wp_filter_nohtml_kses' );
		register_setting( 'updraft-options-group', 'updraft_delete_local', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_debug_mode', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_include_plugins', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_include_themes', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_include_uploads', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_include_others', 'absint' );
		register_setting( 'updraft-options-group', 'updraft_include_others_exclude', 'wp_filter_nohtml_kses' );

		if (current_user_can('manage_options') && get_option('updraft_service') == "googledrive" && get_option('updraft_googledrive_clientid') != "" && get_option('updraft_googledrive_token','xyz') == 'xyz') {
			add_action('admin_notices', array($this,'show_admin_warning_googledrive') );
		}
	}

	function add_admin_pages() {
		add_submenu_page('options-general.php', "UpdraftPlus", "UpdraftPlus", "manage_options", "updraftplus",
		array($this,"settings_output"));
	}

	function url_start($urls,$url) {
		return ($urls) ? '<a href="http://'.$url.'">' : "";
	}

	function url_end($urls,$url) {
		return ($urls) ? '</a>' : " (http://$url)";
	}

	function wordshell_random_advert($urls) {
		$rad = rand(0,5);
		switch ($rad) {
		case 0:
			return "Like automating WordPress operations? Use the CLI? ".$this->url_start($urls,'wordshell.net')."You will love WordShell".$this->url_end($urls,'www.wordshell.net')." - saves time and money fast.";
			break;
		case 1:
			return "Find UpdraftPlus useful? ".$this->url_start($urls,'david.dw-perspective.org.uk/donate')."Please make a donation.".$this->url_end($urls,'david.dw-perspective.org.uk/donate');
		case 2:
			return $this->url_start($urls,'wordshell.net')."Check out WordShell".$this->url_end($urls,'www.wordshell.net')." - manage WordPress from the command line - huge time-saver";
			break;
		case 3:
			return "Want some more useful plugins? ".$this->url_start($urls,'profiles.wordpress.org/DavidAnderson/')."See my WordPress profile page for others.".$this->url_end($urls,'profiles.wordpress.org/DavidAnderson/');
			break;
		case 4:
			return $this->url_start($urls,'www.simbahosting.co.uk')."Need high-quality WordPress hosting from WordPress specialists? (Including automatic backups and 1-click installer). Get it from the creators of UpdraftPlus.".$this->url_end($urls,'www.simbahosting.co.uk');
			break;
		case 5:
			return "Need custom WordPress services from experts (including bespoke development)?".$this->url_start($urls,'www.simbahosting.co.uk/s3/products-and-services/wordpress-experts/')." Get them from the creators of UpdraftPlus.".$this->url_end($urls,'www.simbahosting.co.uk/s3/products-and-services/wordpress-experts/');
			break;
		}
	}

	function settings_output() {

		/*
		we use request here because the initial restore is triggered by a POSTed form. we then may need to obtain credentials 
		for the WP_Filesystem. to do this WP outputs a form that we can't insert variables into (apparently). So the values are 
		passed back in as GET parameters. REQUEST covers both GET and POST so this weird logic works.
		*/
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'updraft_restore' && isset($_REQUEST['backup_timestamp'])) {
			$backup_success = $this->restore_backup($_REQUEST['backup_timestamp']);
			if(empty($this->errors) && $backup_success == true) {
				echo '<p>Restore successful!</p><br/>';
				echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus&updraft_restore_success=true">Return to Updraft Configuration</a>.';
				return;
			} else {
				echo '<p>Restore failed...</p><ul>';
				foreach ($this->errors as $err) {
					echo "<li>";
					if (is_string($err)) { echo htmlspecialchars($err); } else {
						print_r($err);
					}
					echo "</li>";
				}
				echo '</ul><b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
				return;
			}
			//uncomment the below once i figure out how i want the flow of a restoration to work.
			//echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
		}
		$deleted_old_dirs = false;
		if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'updraft_delete_old_dirs') {
			if($this->delete_old_dirs()) {
				$deleted_old_dirs = true;
			} else {
				echo '<p>Old directory removal failed for some reason. You may want to do this manually.</p><br/>';
			}
			echo '<p>Old directories successfully removed.</p><br/>';
			echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
			return;
		}
		
		if(isset($_GET['error'])) {
			echo "<p><strong>ERROR:</strong> ".htmlspecialchars($_GET['error'])."</p>";
		}
		if(isset($_GET['message'])) {
			echo "<p><strong>Note:</strong> ".htmlspecialchars($_GET['message'])."</p>";
		}

		if(isset($_GET['action']) && $_GET['action'] == 'updraft_create_backup_dir') {
			if(!$this->create_backup_dir()) {
				echo '<p>Backup directory could not be created...</p><br/>';
			}
			echo '<p>Backup directory successfully created.</p><br/>';
			echo '<b>Actions:</b> <a href="options-general.php?page=updraftplus">Return to Updraft Configuration</a>.';
			return;
		}
		
		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup') {
			echo '<div class="updated fade" style="max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;"><strong>Schedule backup:</strong> ';
			if (wp_schedule_single_event(time()+5, 'updraft_backup_all') === false) {
				echo "Failed.";
			} else {
				echo "OK. Now load a page from your site to make sure the schedule can trigger.";
			}
			echo '</div>';
		}

		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_all') $this->backup(true,true);

		if(isset($_POST['action']) && $_POST['action'] == 'updraft_backup_debug_db') $this->backup_db();

		?>
		<div class="wrap">
			<h1>UpdraftPlus - Backup/Restore</h1>

			Maintained by <b>David Anderson</b> (<a href="http://david.dw-perspective.org.uk">Homepage</a> | <a href="http://wordshell.net">WordShell - WordPress command line</a> | <a href="http://david.dw-perspective.org.uk/donate">Donate</a> | <a href="http://wordpress.org/extend/plugins/updraftplus/faq/">FAQs</a> | <a href="http://profiles.wordpress.org/davidanderson/">My other WordPress plugins</a>). Version: <?php echo $this->version; ?>
			<br>
			<?php
			if(isset($_GET['updraft_restore_success'])) {
				echo "<div style=\"color:blue\">Your backup has been restored.  Your old themes, uploads, and plugins directories have been retained with \"-old\" appended to their name.  Remove them when you are satisfied that the backup worked properly.  At this time Updraft does not automatically restore your DB.  You will need to use an external tool like phpMyAdmin to perform that task.</div>";
			}

			$ws_advert = $this->wordshell_random_advert(1);
			echo <<<ENDHERE
<div class="updated fade" style="max-width: 800px; font-size:140%; line-height: 140%; padding:14px; clear:left;">${ws_advert}</div>
ENDHERE;

			if($deleted_old_dirs) {
				echo '<div style="color:blue">Old directories successfully deleted.</div>';
			}
			if(!$this->memory_check(96)) {?>
				<div style="color:orange">Your PHP memory limit is too low. UpdraftPlus attempted to raise it but was unsuccessful. This plugin may not work properly with a memory limit of less than 96 Mb (though on the other hand, it has been used successfully with a 32Mb limit - your mileage may vary, but don't blame us!). Current limit is: <?php echo $this->memory_check_current(); ?> Mb</div>
			<?php
			}
			if(!$this->execution_time_check(300)) {?>
				<div style="color:orange">Your PHP max_execution_time is less than 300 seconds. This probably means you're running in safe_mode. Either disable safe_mode or modify your php.ini to set max_execution_time to a higher number. If you do not, there is a chance Updraft will be unable to complete a backup. Present limit is: <?php echo ini_get('max_execution_time'); ?> seconds.</div>
			<?php
			}

			if($this->scan_old_dirs()) {?>
				<div style="color:orange">You have old directories from a previous backup. Click to delete them after you have verified that the restoration worked.</div>
				<form method="post" action="<?php echo remove_query_arg(array('updraft_restore_success','action')) ?>">
					<input type="hidden" name="action" value="updraft_delete_old_dirs" />
					<input type="submit" class="button-primary" value="Delete Old Dirs" onclick="return(confirm('Are you sure you want to delete the old directories?  This cannot be undone.'))" />
				</form>
			<?php
			}
			if(!empty($this->errors)) {
				foreach($this->errors as $error) {
					// ignoring severity
					echo '<div style="color:red">'.$error['error'].'</div>';
				}
			}
			?>

			<h2 style="clear:left;">Existing Schedule And Backups</h2>
			<table class="form-table" style="float:left; clear: both; width:475px">
				<tr>
					<?php
					$updraft_dir = $this->backups_dir_location();
					$next_scheduled_backup = wp_next_scheduled('updraft_backup');
					$next_scheduled_backup = ($next_scheduled_backup) ? date('D, F j, Y H:i T',$next_scheduled_backup) : 'No backups are scheduled at this time.';
					$next_scheduled_backup_database = wp_next_scheduled('updraft_backup_database');
					if (get_option('updraft_interval_database',get_option('updraft_interval')) == get_option('updraft_interval')) {
						$next_scheduled_backup_database = "Will take place at the same time as the files backup.";
					} else {
						$next_scheduled_backup_database = ($next_scheduled_backup_database) ? date('D, F j, Y H:i T',$next_scheduled_backup_database) : 'No backups are scheduled at this time.';
					}
					$current_time = date('D, F j, Y H:i T',time());
					$updraft_last_backup = get_option('updraft_last_backup');
					if($updraft_last_backup) {
						$last_backup = ($updraft_last_backup['success']) ? date('D, F j, Y H:i T',$updraft_last_backup['backup_time']) : print_r($updraft_last_backup['errors'],true);
						$last_backup_color = ($updraft_last_backup['success']) ? 'green' : 'red';
						if (!empty($updraft_last_backup['backup_nonce'])) {
							$potential_log_file = $updraft_dir."/log.".$updraft_last_backup['backup_nonce'].".txt";
							if (is_readable($potential_log_file)) $last_backup .= "<br><a href=\"?page=updraftplus&action=downloadlog&updraftplus_backup_nonce=".$updraft_last_backup['backup_nonce']."\">Download log file</a>";
						}
					} else {
						$last_backup = 'No backup has been completed.';
						$last_backup_color = 'blue';
					}

					if(is_writable($updraft_dir)) {
						$dir_info = '<span style="color:green">Backup directory specified is writable, which is good.</span>';
						$backup_disabled = "";
					} else {
						$backup_disabled = 'disabled="disabled"';
						$dir_info = '<span style="color:red">Backup directory specified is <b>not</b> writable, or does not exist. <span style="font-size:110%;font-weight:bold"><a href="options-general.php?page=updraftplus&action=updraft_create_backup_dir">Click here</a></span> to attempt to create the directory and set the permissions.  If that is unsuccessful check the permissions on your server or change it to another directory that is writable by your web server process.</span>';
					}
					?>

					<th>The Time Now:</th>
					<td style="color:blue"><?php echo $current_time?></td>
				</tr>
				<tr>
					<th>Next Scheduled Files Backup:</th>
					<td style="color:blue"><?php echo $next_scheduled_backup?></td>
				</tr>
				<tr>
					<th>Next Scheduled DB Backup:</th>
					<td style="color:blue"><?php echo $next_scheduled_backup_database?></td>
				</tr>
				<tr>
					<th>Last Backup:</th>
					<td style="color:<?php echo $last_backup_color ?>"><?php echo $last_backup?></td>
				</tr>
			</table>
			<div style="float:left; width:200px; padding-top: 100px;">
				<form method="post" action="">
					<input type="hidden" name="action" value="updraft_backup" />
					<p><input type="submit" <?php echo $backup_disabled ?> class="button-primary" value="Backup Now!" style="padding-top:7px;padding-bottom:7px;font-size:24px !important" onclick="return(confirm('This will schedule a one-time backup.  To trigger the backup you should go ahead, then wait 10 seconds, then load a page on your site.'))" /></p>
				</form>
				<div style="position:relative">
					<div style="position:absolute;top:0;left:0">
						<?php
						$backup_history = get_option('updraft_backup_history');
						$backup_history = (is_array($backup_history))?$backup_history:array();
						$restore_disabled = (count($backup_history) == 0) ? 'disabled="disabled"' : "";
						?>
						<input type="button" class="button-primary" <?php echo $restore_disabled ?> value="Restore" style="padding-top:7px;padding-bottom:7px;font-size:24px !important" onclick="jQuery('#backup-restore').fadeIn('slow');jQuery(this).parent().fadeOut('slow')" />
					</div>
					<div style="display:none;position:absolute;top:0;left:0" id="backup-restore">
						<form method="post" action="">
							<b>Choose: </b>
							<select name="backup_timestamp" style="display:inline">
								<?php
								foreach($backup_history as $key=>$value) {
									echo "<option value='$key'>".date('Y-m-d G:i',$key)."</option>\n";
								}
								?>
							</select>

							<input type="hidden" name="action" value="updraft_restore" />
							<input type="submit" <?php echo $restore_disabled ?> class="button-primary" value="Restore Now!" style="padding-top:7px;margin-top:5px;padding-bottom:7px;font-size:24px !important" onclick="return(confirm('Restoring from backup will replace this site\'s themes, plugins, uploads and other content directories (according to what is contained in the backup set which you select). Database restoration cannot be done through this process - you must download the database and import yourself (e.g. through PHPMyAdmin). Do you wish to continue with the restoration process?'))" />
						</form>
					</div>
				</div>
			</div>
			<br style="clear:both" />
			<table class="form-table">
				<tr>
					<th>Download Backups</th>
					<td><a href="#" title="Click to see available backups" onclick="jQuery('.download-backups').toggle();return false;"><?php echo count($backup_history)?> available</a></td>
				</tr>
				<tr>
					<td></td><td class="download-backups" style="display:none">
						<em>Click on a button to download the corresponding file to your computer. If you are using the <a href="http://opera.com">Opera web browser</a> then you should turn Turbo mode off.</em>
						<table>
							<?php
							foreach($backup_history as $key=>$value) {
							?>
							<tr>
								<td><b><?php echo date('Y-m-d G:i',$key)?></b></td>
								<td>
							<?php if (isset($value['db'])) { ?>
									<form action="admin-ajax.php" method="post">
										<input type="hidden" name="action" value="updraft_download_backup" />
										<input type="hidden" name="type" value="db" />
										<input type="hidden" name="timestamp" value="<?php echo $key?>" />
										<input type="submit" value="Database" />
									</form>
							<?php } else { echo "(No database)"; } ?>
								</td>
								<td>
							<?php if (isset($value['plugins'])) { ?>
									<form action="admin-ajax.php" method="post">
										<input type="hidden" name="action" value="updraft_download_backup" />
										<input type="hidden" name="type" value="plugins" />
										<input type="hidden" name="timestamp" value="<?php echo $key?>" />
										<input type="submit" value="Plugins" />
									</form>
							<?php } else { echo "(No plugins)"; } ?>
								</td>
								<td>
							<?php if (isset($value['themes'])) { ?>
									<form action="admin-ajax.php" method="post">
										<input type="hidden" name="action" value="updraft_download_backup" />
										<input type="hidden" name="type" value="themes" />
										<input type="hidden" name="timestamp" value="<?php echo $key?>" />
										<input type="submit" value="Themes" />
									</form>
							<?php } else { echo "(No themes)"; } ?>
								</td>
								<td>
							<?php if (isset($value['uploads'])) { ?>
									<form action="admin-ajax.php" method="post">
										<input type="hidden" name="action" value="updraft_download_backup" />
										<input type="hidden" name="type" value="uploads" />
										<input type="hidden" name="timestamp" value="<?php echo $key?>" />
										<input type="submit" value="Uploads" />
									</form>
							<?php } else { echo "(No uploads)"; } ?>
								</td>
								<td>
							<?php if (isset($value['others'])) { ?>
									<form action="admin-ajax.php" method="post">
										<input type="hidden" name="action" value="updraft_download_backup" />
										<input type="hidden" name="type" value="others" />
										<input type="hidden" name="timestamp" value="<?php echo $key?>" />
										<input type="submit" value="Others" />
									</form>
							<?php } else { echo "(No others)"; } ?>
								</td>
								<td>
							<?php if (isset($value['nonce']) && preg_match("/^[0-9a-f]{12}$/",$value['nonce']) && is_readable($updraft_dir.'/log.'.$value['nonce'].'.txt')) { ?>
									<form action="options-general.php" method="get">
										<input type="hidden" name="action" value="downloadlog" />
										<input type="hidden" name="page" value="updraftplus" />
										<input type="hidden" name="updraftplus_backup_nonce" value="<?php echo $value['nonce']; ?>" />
										<input type="submit" value="Backup Log" />
									</form>
							<?php } else { echo "(No backup log)"; } ?>
								</td>
							</tr>
							<?php }?>
						</table>
					</td>
				</tr>
			</table>
			<form method="post" action="options.php">
			<?php settings_fields('updraft-options-group'); ?>
			<h2>Configure Backup Contents And Schedule</h2>
				<table class="form-table" style="width:850px;">
				<tr>
					<th>File Backup Intervals:</th>
					<td><select name="updraft_interval">
						<?php
						$intervals = array ("manual" => "Manual", 'every4hours' => "Every 4 hours", 'every8hours' => "Every 8 hours", 'twicedaily' => "Every 12 hours", 'daily' => "Daily", 'weekly' => "Weekly", 'fortnightly' => "Fortnightly", 'monthly' => "Monthly");
						foreach ($intervals as $cronsched => $descrip) {
							echo "<option value=\"$cronsched\" ";
							if ($cronsched == get_option('updraft_interval','manual')) echo 'selected="selected"';
							echo ">$descrip</option>\n";
						}
						?>
						</select></td>
				</tr>
				<tr>
					<th>Database Backup Intervals:</th>
					<td><select name="updraft_interval_database">
						<?php
						foreach ($intervals as $cronsched => $descrip) {
							echo "<option value=\"$cronsched\" ";
							if ($cronsched == get_option('updraft_interval_database',get_option('updraft_interval'))) echo 'selected="selected"';
							echo ">$descrip</option>\n";
						}
						?>
						</select></td>
				</tr>
				<tr class="backup-interval-description">
					<td></td><td>If you would like to automatically schedule backups, choose schedules from the dropdown above. Backups will occur at the interval specified starting just after the current time.  If you choose manual you must click the &quot;Backup Now!&quot; button whenever you wish a backup to occur. If the two schedules are the same, then the two backups will take place together.</td>
				</tr>
				<?php
					# The true (default value if non-existent) here has the effect of forcing a default of on.
					$include_themes = (get_option('updraft_include_themes',true)) ? 'checked="checked"' : "";
					$include_plugins = (get_option('updraft_include_plugins',true)) ? 'checked="checked"' : "";
					$include_uploads = (get_option('updraft_include_uploads',true)) ? 'checked="checked"' : "";
					$include_others = (get_option('updraft_include_others',true)) ? 'checked="checked"' : "";
					$include_others_exclude = get_option('updraft_include_others_exclude',UPDRAFT_DEFAULT_OTHERS_EXCLUDE);
				?>
				<tr>
					<th>Include in Files Backup:</th>
					<td>
					<input type="checkbox" name="updraft_include_plugins" value="1" <?php echo $include_plugins; ?> /> Plugins<br>
					<input type="checkbox" name="updraft_include_themes" value="1" <?php echo $include_themes; ?> /> Themes<br>
					<input type="checkbox" name="updraft_include_uploads" value="1" <?php echo $include_uploads; ?> /> Uploads<br>
					<input type="checkbox" name="updraft_include_others" value="1" <?php echo $include_others; ?> /> Any other directories found inside wp-content - but exclude these directories: <input type="text" name="updraft_include_others_exclude" size="32" value="<?php echo htmlspecialchars($include_others_exclude); ?>"/><br>
					Include all of these, unless you are backing them up separately. Note that presently UpdraftPlus backs up these directories only - which is usually everything (except for WordPress core itself which you can download afresh from WordPress.org). But if you have made customised modifications outside of these directories, you need to back them up another way.<br>(<a href="http://wordshell.net">Use WordShell</a> for automatic backup, version control and patching).<br></td>
					</td>
				</tr>
				<tr>
					<th>Retain Backups:</th>
					<?php
					$updraft_retain = get_option('updraft_retain');
					$retain = ((int)$updraft_retain > 0)?get_option('updraft_retain'):1;
					?>
					<td><input type="text" name="updraft_retain" value="<?php echo $retain ?>" style="width:50px" /></td>
				</tr>
				<tr class="backup-retain-description">
					<td></td><td>By default only the most recent backup is retained. If you'd like to preserve more, specify the number here. (This many of <strong>both</strong> files and database backups will be retained.)</td>
				</tr>
				<tr>
					<th>Email:</th>
					<td><input type="text" style="width:260px" name="updraft_email" value="<?php echo get_option('updraft_email'); ?>" /> <br>Enter an address here to have a report sent (and the whole backup, if you choose) to it.</td>
				</tr>
				<tr class="deletelocal">
					<th>Delete local backup:</th>
					<td><input type="checkbox" name="updraft_delete_local" value="1" <?php $delete_local = (get_option('updraft_delete_local')) ? 'checked="checked"' : "";
echo $delete_local; ?> /> <br>Check this to delete the local backup file (only sensible if you have enabled a remote backup (below), otherwise you will have no backup remaining).</td>
				</tr>

				<tr>
					<th>Database encryption phrase:</th>
					<?php
					$updraft_encryptionphrase = get_option('updraft_encryptionphrase');
					?>
					<td><input type="text" name="updraft_encryptionphrase" value="<?php echo $updraft_encryptionphrase ?>" style="width:132px" /></td>
				</tr>
				<tr class="backup-crypt-description">
					<td></td><td>If you enter a string here, it is used to encrypt backups (Rijndael). Do not lose it, or all your backups will be useless. Presently, only the database file is encrypted. This is also the key used to decrypt backups from this admin interface (so if you change it, then automatic decryption will not work until you change it back). You can also use the file example-decrypt.php from inside the UpdraftPlus plugin directory to decrypt manually.</td>
				</tr>
				</table>

				<h2>Copying Your Backup To Remote Storage</h2>

				<table class="form-table" style="width:850px;">
				<tr>
					<th>Remote backup:</th>
					<td><select name="updraft_service" id="updraft-service">
						<?php
						$debug_mode = (get_option('updraft_debug_mode')) ? 'checked="checked"' : "";

						$set = 'selected="selected"';

						// Should be one of s3, ftp, googledrive, email, or whatever else is added
						$active_service = get_option('updraft_service');

						?>
						<option value="none" <?php
							if ($active_service == "none") echo $set; ?>>None</option>
						<?php
						foreach ($this->backup_methods as $method => $description) {
							echo "<option value=\"$method\"";
							if ($active_service == $method) echo ' '.$set;
							echo '>'.$description;
							echo "</option>\n";
						}
						?>
						</select></td>
				</tr>
				<tr class="backup-service-description">
					<td></td><td>Choose your backup method. If choosing &quot;E-Mail&quot;, then be aware that mail servers tend to have size limits; typically around 10-20Mb; backups larger than any limits will not arrive.</td>
				
				</tr>
				<?php
					foreach ($this->backup_methods as $method => $description) {
						require_once(UPDRAFTPLUS_DIR.'/methods/'.$method.'.php');
						$call_method = "UpdraftPlus_BackupModule_$method";
						call_user_func(array($call_method, 'config_print'));
					}
				?>
				</table>
				<script type="text/javascript">
				/* <![CDATA[ */
					jQuery(document).ready(function() {
						jQuery('.updraftplusmethod').hide();
						<?php
							if ($active_service) echo "jQuery('.${active_service}').show();";
						?>
					});
				/* ]]> */
				</script>
				<table class="form-table" style="width:850px;">
				<tr>
					<td colspan="2"><h2>Advanced / Debugging Settings</h2></td>
				</tr>
				<tr>
					<th>Backup Directory:</th>
					<td><input type="text" name="updraft_dir" style="width:525px" value="<?php echo htmlspecialchars($updraft_dir); ?>" /></td>
				</tr>
				<tr>
					<td></td><td><?php echo $dir_info ?> This is where Updraft Backup/Restore will write the zip files it creates initially.  This directory must be writable by your web server.  Typically you'll want to have it inside your wp-content folder (this is the default).  <b>Do not</b> place it inside your uploads dir, as that will cause recursion issues (backups of backups of backups of...).</td>
				</tr>
				<tr>
					<th>Debug mode:</th>
					<td><input type="checkbox" name="updraft_debug_mode" value="1" <?php echo $debug_mode; ?> /> <br>Check this to receive more information on the backup process - useful if something is going wrong. You <strong>must</strong> send me this log if you are filing a bug report.</td>
				</tr>
				<tr>
				<td></td>
				<td>
					<p style="margin: 10px 0; padding: 10px; font-size: 140%; background-color: lightYellow; border-color: #E6DB55; border: 1px solid; border-radius: 4px;">
					<?php
					echo $this->wordshell_random_advert(1);
					?>
					</p>
					</td>
				</tr>
				<tr>
					<td></td>
					<td>
						<input type="hidden" name="action" value="update" />
						<input type="submit" class="button-primary" value="Save Changes" />
					</td>
				</tr>
			</table>
			</form>
			<?php
			if(get_option('updraft_debug_mode')) {
			?>
			<div style="padding-top: 40px;">
				<hr>
				<h3>Debug Information</h3>
				<?php
				$peak_memory_usage = memory_get_peak_usage(true)/1024/1024;
				$memory_usage = memory_get_usage(true)/1024/1024;
				echo 'Peak memory usage: '.$peak_memory_usage.' MB<br/>';
				echo 'Current memory usage: '.$memory_usage.' MB<br/>';
				echo 'PHP memory limit: '.ini_get('memory_limit').' <br/>';
				?>
				<form method="post" action="">
					<input type="hidden" name="action" value="updraft_backup_debug_all" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="Debug Backup" onclick="return(confirm('This will cause an immediate backup.  The page will stall loading until it finishes (ie, unscheduled).  Use this if you\'re trying to see peak memory usage.'))" /></p>
				</form>
				<form method="post" action="">
					<input type="hidden" name="action" value="updraft_backup_debug_db" />
					<p><input type="submit" class="button-primary" <?php echo $backup_disabled ?> value="Debug DB Backup" onclick="return(confirm('This will cause an immediate DB backup.  The page will stall loading until it finishes (ie, unscheduled). The backup will remain locally despite your prefs and will not go into the backup history or up into the cloud.'))" /></p>
				</form>
			</div>
			<?php } ?>

			<p><em>UpdraftPlus is based on the original Updraft by <b>Paul Kehrer</b> (<a href="http://langui.sh" target="_blank">Blog</a> | <a href="http://twitter.com/reaperhulk" target="_blank">Twitter</a> )</em></p>


			<script type="text/javascript">
			/* <![CDATA[ */
				jQuery(document).ready(function() {
					jQuery('#updraft-service').change(function() {
						jQuery('.updraftplusmethod').hide();
						var active_class = jQuery(this).val();
						jQuery('.'+active_class).show();
					})
				})
				jQuery(window).load(function() {
					//this is for hiding the restore progress at the top after it is done
					setTimeout('jQuery("#updraft-restore-progress").toggle(1000)',3000)
					jQuery('#updraft-restore-progress-toggle').click(function() {
						jQuery('#updraft-restore-progress').toggle(500)
					})
				})
			/* ]]> */
			</script>
			<?php
	}
	
	/*array2json provided by bin-co.com under BSD license*/
	function array2json($arr) { 
		if(function_exists('json_encode')) return stripslashes(json_encode($arr)); // PHP >= 5.2 already has this functionality. 
		$parts = array(); 
		$is_list = false; 

		//Find out if the given array is a numerical array 
		$keys = array_keys($arr); 
		$max_length = count($arr)-1; 
		if(($keys[0] == 0) and ($keys[$max_length] == $max_length)) {//See if the first key is 0 and last key is length - 1 
			$is_list = true; 
			for($i=0; $i<count($keys); $i++) { //See if each key correspondes to its position 
				if($i != $keys[$i]) { //A key fails at position check. 
					$is_list = false; //It is an associative array. 
					break; 
				} 
			} 
		} 

		foreach($arr as $key=>$value) { 
			if(is_array($value)) { //Custom handling for arrays 
				if($is_list) $parts[] = $this->array2json($value); /* :RECURSION: */ 
				else $parts[] = '"' . $key . '":' . $this->array2json($value); /* :RECURSION: */ 
			} else { 
				$str = ''; 
				if(!$is_list) $str = '"' . $key . '":'; 

				//Custom handling for multiple data types 
				if(is_numeric($value)) $str .= $value; //Numbers 
				elseif($value === false) $str .= 'false'; //The booleans 
				elseif($value === true) $str .= 'true'; 
				else $str .= '"' . addslashes($value) . '"'; //All other things 
				// :TODO: Is there any more datatype we should be in the lookout for? (Object?) 

				$parts[] = $str; 
			} 
		} 
		$json = implode(',',$parts); 

		if($is_list) return '[' . $json . ']';//Return numerical JSON 
		return '{' . $json . '}';//Return associative JSON 
	}

	function show_admin_warning($message) {
		echo '<div id="updraftmessage" class="updated fade">'."<p>$message</p></div>";
	}

	function show_admin_warning_unreadablelog() {
		$this->show_admin_warning('<strong>UpdraftPlus notice:</strong> The log file could not be read.</a>');
	}

	function show_admin_warning_googledrive() {
		$this->show_admin_warning('<strong>UpdraftPlus notice:</strong> <a href="?page=updraftplus&action=updraftmethod-googledrive-auth&updraftplus_googleauth=doit">Click here to authenticate your Google Drive account (you will not be able to back up to Google Drive without it).</a>');
	}


}


?>
