<?php
class SyncDB
{
	public static $debug = false;

	public static function sync($profile)
	{
		$config = json_decode(file_get_contents('profiles/' . $profile . '.json'));
		$tmp_filename = "db_" . md5(uniqid()) . ".sql";
		if ($config->engine == "mysql")
		{
			
			// dump

			$command = "";
			if (isset($config->source->ssh) && $config->source->ssh !== false)
			{
				$command .= "ssh -o StrictHostKeyChecking=no " . ((isset($config->source->ssh->port)) ? (" -p \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"";
			}

			$command .= "\"".(isset($config->source->cmd) ? ($config->source->cmd) : ("mysqldump")) . "\" -h " . $config->source->host . " --port " . $config->source->port . " -u " . $config->source->username . " -p\"" . $config->source->password . "\" " . $config->source->database . "";
			if (isset($config->source->ssh) && $config->source->ssh !== false && isset($config->source->ssh->type) && $config->source->ssh->type == 'fast')
			{
				$command .= " > " . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . "\"";
			}
			else
			{
				if (isset($config->source->ssh) && $config->source->ssh !== false)
				{
					$command .= "\"";
				}

				$command .= " > \"" . $tmp_filename . "\"";
			}

			self::executeCommand($command, "STEP 1: DUMPING DATABASE...");

			// fetch

			if (
				isset($config->source->ssh) && $config->source->ssh !== false &&
				isset($config->source->ssh->type) && $config->source->ssh->type == 'fast'
			)
			{
				if( isset($config->source->ssh) && isset($config->source->ssh->zip) && $config->source->ssh->zip === true ) {
					$command = "ssh -o StrictHostKeyChecking=no " . ((isset($config->source->ssh->port)) ? (" -p \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"" . "zip " . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . ".zip " . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . "\"";
					self::executeCommand($command, "STEP 1B: ZIPPING DB...");
					$command = "scp -r " . ((isset($config->source->ssh->port)) ? (" -P \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . ":" . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . ".zip " . $tmp_filename . ".zip";
					self::executeCommand($command, "STEP 1C: COPYING DB TO SOURCE...");
					$command = "unzip -j ".$tmp_filename.".zip";
					self::executeCommand($command, "STEP 1C: UNZIPPING...");
					$command = "rm ".$tmp_filename.".zip";
					self::executeCommand($command, "STEP 1C: DELETING LOCAL ZIP...");
					$command = "ssh -o StrictHostKeyChecking=no " . ((isset($config->source->ssh->port)) ? (" -p \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"" . "rm " . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . ".zip\"";
					self::executeCommand($command, "STEP 1D: DELETING REMOTE TMP ZIP DB...");
					$command = "ssh -o StrictHostKeyChecking=no " . ((isset($config->source->ssh->port)) ? (" -p \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"" . "rm " . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . "\"";
					self::executeCommand($command, "STEP 1D: DELETING REMOTE TMP DB...");
				}
				else {
					$command = "scp -r " . ((isset($config->source->ssh->port)) ? (" -P \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . ":" . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . " " . $tmp_filename . "";
					self::executeCommand($command, "STEP 1B: COPYING DB TO SOURCE...");
					$command = "ssh -o StrictHostKeyChecking=no " . ((isset($config->source->ssh->port)) ? (" -p \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"" . "rm " . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . "\"";
					self::executeCommand($command, "STEP 1C: DELETING REMOTE TMP DB...");
				}
			}

			// replacing corrupt collations
			$search_replace_collation = file_get_contents($tmp_filename);
			$search_replace_collation = str_replace("CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci","CHARSET=utf8 COLLATE=utf8_general_ci",$search_replace_collation);
			$search_replace_collation = str_replace("COLLATE utf8mb4_unicode_520_ci","COLLATE utf8_general_ci",$search_replace_collation);
			file_put_contents($tmp_filename,$search_replace_collation);

			// delete

			$command = "";
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "ssh -o StrictHostKeyChecking=no " . ((isset($config->target->ssh->port)) ? (" -p \"" . $config->target->ssh->port . "\"") : ("")) . " " . ((isset($config->target->ssh->key)) ? (" -i \"" . $config->target->ssh->key . "\"") : ("")) . " " . $config->target->ssh->username . "@" . $config->target->ssh->host . " \"";
			}

			$command .= "\"" . (isset($config->target->cmd) ? ($config->target->cmd) : ("mysql")) . "\" -h " . $config->target->host . " --port " . $config->target->port . " -u " . $config->target->username . " -p\"" . $config->target->password . "\" -e \"drop database if exists `" . $config->target->database . "`; create database `" . $config->target->database . "`;\"";
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "\"";
			}

			self::executeCommand($command, "STEP 2: DELETING CURRENT DB...");

			// push

			$command = "";
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "ssh -o StrictHostKeyChecking=no " . ((isset($config->target->ssh->port)) ? (" -p \"" . $config->target->ssh->port . "\"") : ("")) . " " . ((isset($config->target->ssh->key)) ? (" -i \"" . $config->target->ssh->key . "\"") : ("")) . " " . $config->target->ssh->username . "@" . $config->target->ssh->host . " \"";
			}

			$command .= "\"" . (isset($config->target->cmd) ? ($config->target->cmd) : ("mysql")) . "\" -h " . $config->target->host . " --port " . $config->target->port . " -u " . $config->target->username . " -p\"" . $config->target->password . "\" " . $config->target->database . " --default-character-set=utf8 < \"" . $tmp_filename . "\"";
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "\"";
			}

			self::executeCommand($command, "STEP 3: PUSHING NEW DB...");

			// replace e.g. with the help of https://github.com/interconnectit/Search-Replace-DB
			// therefore place this script inside search-replace-db
			if (isset($config->replace))
			{
				if (isset($config->target->ssh) && $config->target->ssh !== false)
				{
					$command = "ssh -o StrictHostKeyChecking=no " . ((isset($config->target->ssh->port)) ? (" -p \"" . $config->target->ssh->port . "\"") : ("")) . " " . ((isset($config->target->ssh->key)) ? (" -i \"" . $config->target->ssh->key . "\"") : ("")) . " -M -S my-ctrl-socket -fnNT -L 50000:localhost:" . $config->target->port . " " . $config->target->ssh->username . "@" . $config->target->ssh->host . "";
					self::executeCommand($command, "STEP 4: OPENING UP SSH TUNNEL...");
				}

				// old
				foreach($config->replace as $search => $replace)
				{
					$command = "php search-replace-db/srdb.cli.php -h " . $config->target->host . " -n " . $config->target->database . " -u " . $config->target->username . " -p \"" . $config->target->password . "\" --port " . $config->target->port . " -s \"" . $search . "\" -r \"" . $replace . "\"";
					self::executeCommand($command, "STEP 5: SEARCH/REPLACE...");
				}

				// new
				/*
				{
					$search = '["'.implode('","',array_keys((array)$config->replace)).'"]';
					$replace = '["'.implode('","',array_values((array)$config->replace)).'"]';
					$command = "php search-replace-db/srdb.cli.php -h " . $config->target->host . " -n " . $config->target->database . " -u " . $config->target->username . " -p \"" . $config->target->password . "\" --port " . $config->target->port . " -s '" . $search . "' -r '" . $replace . "'";
					self::executeCommand($command, "STEP 5: SEARCH/REPLACE...");
				}
				*/

				if (isset($config->target->ssh) && $config->target->ssh !== false)
				{
					$command = "ssh -o StrictHostKeyChecking=no " . ((isset($config->target->ssh->port)) ? (" -p \"" . $config->target->ssh->port . "\"") : ("")) . " " . ((isset($config->target->ssh->key)) ? (" -i \"" . $config->target->ssh->key . "\"") : ("")) . " -S my-ctrl-socket -O exit " . $config->target->ssh->username . "@" . $config->target->ssh->host . "";
					self::executeCommand($command, "STEP 6: CLOSING SSH TUNNEL...");
				}
			}
		}

		if ($config->engine == "pgsql")
		{

			// TODO

		}

		unlink($tmp_filename);
	}

	public static function executeCommand($command, $message)
	{
		echo $message . "\n";
		if (self::$debug === true)
		{
			print_r($command);
		}

		// remove newlines

		$command = trim(preg_replace('/\s+/', ' ', $command));
		return shell_exec($command);
	}
}

// usage from command line

if (!isset($argv) || empty($argv) || !isset($argv[1]) || !file_exists('profiles/' . $argv[1] . '.json'))
{
	die('missing profile');
}

SyncDB::sync($argv[1]);
