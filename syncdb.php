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
			
			// get

			$command = "";
			if (isset($config->source->ssh) && $config->source->ssh !== false)
			{
				$command .= "ssh " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"";
			}

			$command .= (isset($config->source->cmd) ? ($config->source->cmd) : ("mysqldump")) . " -h " . $config->source->host . " --port " . $config->source->port . " -u " . $config->source->username . " -p\"" . $config->source->password . "\" " . $config->source->database . "";
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

			executeCommand($command, "STEP 1: GETTING DB FROM SOURCE...");
			if (isset($config->source->ssh) && $config->source->ssh !== false && isset($config->source->ssh->type) && $config->source->ssh->type == 'fast')
			{
				$command = "scp -r " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . ":" . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . " " . $tmp_filename . "";
				executeCommand($command, "STEP 1B: COPYING DB TO SOURCE...");
				$command = "ssh " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"" . "rm " . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . "\"";
				executeCommand($command, "STEP 1C: DELETING TMP DB...");
			}

			// delete

			$command = "";
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "ssh " . ((isset($config->target->ssh->key)) ? (" -i \"" . $config->target->ssh->key . "\"") : ("")) . " " . $config->target->ssh->username . "@" . $config->target->ssh->host . " \"";
			}

			$command .= "" . (isset($config->target->cmd) ? ($config->target->cmd) : ("mysql")) . " -h " . $config->target->host . " --port " . $config->target->port . " -u " . $config->target->username . " -p\"" . $config->target->password . "\" -e \"drop database if exists `" . $config->target->database . "`; create database `" . $config->target->database . "`;\"";
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "\"";
			}

			executeCommand($command, "STEP 2: DELETING CURRENT DB...");

			// push

			$command = "";
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "ssh " . ((isset($config->target->ssh->key)) ? (" -i \"" . $config->target->ssh->key . "\"") : ("")) . " " . $config->target->ssh->username . "@" . $config->target->ssh->host . " \"";
			}

			$command .= "" . (isset($config->target->cmd) ? ($config->target->cmd) : ("mysql")) . " -h " . $config->target->host . " --port " . $config->target->port . " -u " . $config->target->username . " -p\"" . $config->target->password . "\" " . $config->target->database . " --default-character-set=utf8 < \"" . $tmp_filename . "\"";
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "\"";
			}

			executeCommand($command, "STEP 3: PUSHING NEW DB...");

			// replace e.g. with the help of https://github.com/interconnectit/Search-Replace-DB
			// therefore place this script inside search-replace-db
			if (isset($config->replace))
			{
				if (isset($config->target->ssh) && $config->target->ssh !== false)
				{
					$command = "ssh " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " -M -S my-ctrl-socket -fnNT -L 50000:localhost:" . $config->target->port . " " . $config->target->ssh->username . "@" . $config->target->ssh->host . "";
					executeCommand($command, "STEP 4: OPENING UP SSH TUNNEL...");
				}

				foreach($config->replace as $search => $replace)
				{
					$command = "php search-replace-db/srdb.cli.php -h " . $config->target->host . " -n " . $config->target->database . " -u " . $config->target->username . " -p \"" . $config->target->password . "\" --port " . $config->target->port . " -s \"" . $search . "\" -r \"" . $replace . "\"";
					executeCommand($command, "STEP 5: SEARCH/REPLACE...");
				}

				if (isset($config->target->ssh) && $config->target->ssh !== false)
				{
					$command = "ssh " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " -S my-ctrl-socket -O exit " . $config->target->ssh->username . "@" . $config->target->ssh->host . "";
					executeCommand($command, "STEP 6: CLOSING SSH TUNNEL...");
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
			echo '<pre>';
			print_r($command);
			echo '</pre>';
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
