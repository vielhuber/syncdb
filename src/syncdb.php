<?php
namespace vielhuber\syncdb;
require_once(syncdb::getBasepath().'/vendor/autoload.php');
use vielhuber\magicreplace\magicreplace;
class syncdb
{
	public static $debug = false;

	public static function getOs()
	{
		if( stristr(PHP_OS, 'DAR') ) { return 'mac'; }
		if( stristr(PHP_OS, 'WIN') ) { return 'windows'; }
		if( stristr(PHP_OS, 'LINUX') ) { return 'linux'; }
		return 'unknown';
	}

	public static function getDoubleQuote()
	{
		if( self::getOs() === 'windows' ) { return '""'; }
		return '\'';
	}

    public static function getBasepath()
    {
        // if this is installed via composer, then we have to go up 4 levels
        if( file_exists( dirname(__DIR__,4).'/vendor/autoload.php' ) )
        {
            return dirname(__DIR__,4);
        }
        // if otherwise the git repo is cloned, we go up 1 level
        elseif( file_exists( dirname(__DIR__,1).'/vendor/autoload.php' ) )
        {
           return dirname(__DIR__,1);
        }
        else
        {
            throw new \Exception('wrong path');
        }
    }

	public static function sync($profile)
	{

		$config = json_decode(file_get_contents(syncdb::getBasepath().'/profiles/' . $profile . '.json'));

		if( self::getOs() !== 'windows' )
		{
			unset($config->source->cmd);
			unset($config->source->ssh->key);
			unset($config->target->cmd);
			unset($config->target->ssh->key);
		}

        echo '- PROFILE: '.$profile . PHP_EOL;

		$tmp_filename = 'db_' . md5(uniqid()) . '.sql';

		// clean up files
        /*
		foreach(glob('db_*.sql') as $file){
		  if(is_file($file)) { unlink($file); }
		}
        */

		if ($config->engine == 'mysql')
		{
			
			// dump

			$command = '';
			if (isset($config->source->ssh) && $config->source->ssh !== false)
			{
				$command .= "ssh -o StrictHostKeyChecking=no " . ((isset($config->source->ssh->port)) ? (" -p \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"";
			}

			$command .= "\"".(isset($config->source->cmd) ? ($config->source->cmd) : ("mysqldump")) . "\" -h " . $config->source->host . " --port " . $config->source->port . " -u " . $config->source->username . " -p".self::getDoubleQuote()."" . $config->source->password . "".self::getDoubleQuote()." --skip-add-locks --skip-comments --extended-insert=false --disable-keys=false --quick " . $config->source->database . "";
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

			self::executeCommand($command, '--- DUMPING DATABASE...', true);

			// fetch

			if (
				isset($config->source->ssh) && $config->source->ssh !== false &&
				isset($config->source->ssh->type) && $config->source->ssh->type == 'fast'
			)
			{
				if( isset($config->source->ssh) && isset($config->source->ssh->zip) && $config->source->ssh->zip === true )
				{
					$command = "ssh -o StrictHostKeyChecking=no " . ((isset($config->source->ssh->port)) ? (" -p \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"" . "cd ".((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/'))." && zip -j -9 " . $tmp_filename . ".zip " . $tmp_filename . "\"";
					self::executeCommand($command, "--- ZIPPING DATABASE...", true);
					$command = "scp -o StrictHostKeyChecking=no -q -r " . ((isset($config->source->ssh->port)) ? (" -P \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . ":" . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . ".zip " . $tmp_filename . ".zip";
					self::executeCommand($command, "--- COPYING DATABASE TO SOURCE...", true);
					$command = "unzip -j ".$tmp_filename.".zip";
					self::executeCommand($command, "--- UNZIPPING ZIP FILE...");
					$command = ((isset($config->source->ssh->rm))?($config->source->ssh->rm):('rm -f'))." ".$tmp_filename.".zip";
					self::executeCommand($command, "--- DELETING LOCAL ZIP...");
					$command = "ssh -o StrictHostKeyChecking=no " . ((isset($config->source->ssh->port)) ? (" -p \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"" . ((isset($config->source->ssh->rm))?($config->source->ssh->rm):('rm -f'))." " . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . ".zip\"";
					self::executeCommand($command, "--- DELETING REMOTE TMP ZIP...", true);
					$command = "ssh -o StrictHostKeyChecking=no " . ((isset($config->source->ssh->port)) ? (" -p \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"" . ((isset($config->source->ssh->rm))?($config->source->ssh->rm):('rm -f'))." " . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . "\"";
					self::executeCommand($command, "--- DELETING REMOTE TMP DATABASE...", true);
				}
				else
				{
					$command = "scp -o StrictHostKeyChecking=no -q -r " . ((isset($config->source->ssh->port)) ? (" -P \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . ":" . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . " " . $tmp_filename . "";
					self::executeCommand($command, "--- COPYING DATABASE TO SOURCE...", true);
					$command = "ssh -o StrictHostKeyChecking=no " . ((isset($config->source->ssh->port)) ? (" -p \"" . $config->source->ssh->port . "\"") : ("")) . " " . ((isset($config->source->ssh->key)) ? (" -i \"" . $config->source->ssh->key . "\"") : ("")) . " " . $config->source->ssh->username . "@" . $config->source->ssh->host . " \"" . ((isset($config->source->ssh->rm))?($config->source->ssh->rm):('rm -f'))." " . ((isset($config->source->ssh->tmp_dir)) ? ($config->source->ssh->tmp_dir) : ('/tmp/')) . $tmp_filename . "\"";
					self::executeCommand($command, "--- DELETING REMOTE TMP DATABASE...", true);
				}
			}

			// replacing corrupt collations
			$search_replace_collation = file_get_contents($tmp_filename);
			$search_replace_collation = str_replace('CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci','CHARSET=utf8 COLLATE=utf8_general_ci',$search_replace_collation);
			$search_replace_collation = str_replace('COLLATE utf8mb4_unicode_520_ci','COLLATE utf8_general_ci',$search_replace_collation);
			file_put_contents($tmp_filename,$search_replace_collation);

			// search / replace
			if (isset($config->replace))
			{
				magicreplace::run($tmp_filename,$tmp_filename,$config->replace);
				echo '--- SEARCH/REPLACE...'.PHP_EOL;
			}

			// delete

			$command = '';
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "ssh -o StrictHostKeyChecking=no " . ((isset($config->target->ssh->port)) ? (" -p \"" . $config->target->ssh->port . "\"") : ("")) . " " . ((isset($config->target->ssh->key)) ? (" -i \"" . $config->target->ssh->key . "\"") : ("")) . " " . $config->target->ssh->username . "@" . $config->target->ssh->host . " \"";
			}


			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$escape = '\\';
			}
			else
			{
				$escape = '';
			}
			if ( self::getOs() === 'windows' )
			{
				$quote = "\"";
			}
			else
			{
				$quote = "'";
			}

			$command .= "\"" . (isset($config->target->cmd) ? ($config->target->cmd) : ("mysql")) . "\" ";
			$command .= "-h " . $config->target->host . " ";
			$command .= "--port " . $config->target->port . " ";
			$command .= "-u " . $config->target->username . " ";
			$command .= "-p".self::getDoubleQuote()."" . $config->target->password . "".self::getDoubleQuote()." ";
			$command .= "-e ".$escape."".$quote."drop database if exists ".$escape."`".$config->target->database."".$escape."`; create database ".$escape."`".$config->target->database."".$escape."`;".$escape."".$quote."";

			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "\"";
			}

			self::executeCommand($command, "--- DELETING TARGET DATABASE...", true);

			// push

			$command = '';
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "ssh -o StrictHostKeyChecking=no " . ((isset($config->target->ssh->port)) ? (" -p \"" . $config->target->ssh->port . "\"") : ("")) . " " . ((isset($config->target->ssh->key)) ? (" -i \"" . $config->target->ssh->key . "\"") : ("")) . " " . $config->target->ssh->username . "@" . $config->target->ssh->host . " \"";
			}
			$command .= "\"" . (isset($config->target->cmd) ? ($config->target->cmd) : ("mysql")) . "\" -h " . $config->target->host . " --port " . $config->target->port . " -u " . $config->target->username . " -p".self::getDoubleQuote()."" . $config->target->password . "".self::getDoubleQuote()." " . $config->target->database . " --default-character-set=utf8";
			if (isset($config->target->ssh) && $config->target->ssh !== false)
			{
				$command .= "\"";
			}
			$command .= " < \"" . $tmp_filename . "\"";

			self::executeCommand($command, '--- PUSHING NEW DATABASE...', true);

		}

		if ($config->engine == 'pgsql')
		{

			// TODO

		}

		@unlink($tmp_filename);
	}

	public static function executeCommand($command, $message, $suppress_output = false)
	{
		echo $message . PHP_EOL;

		// remove newlines
		$command = trim(preg_replace('/\s+/', ' ', $command));

		if( $suppress_output === true )
		{
			if ( self::getOs() === 'windows' )
			{
				$command .= ' 2> nul';
			}
			else {
				$command .= ' 2>/dev/null';
			}
		}

		if (self::$debug === true)
		{
			print_r($command);
		}

		return shell_exec($command);
	}

}

// usage from command line

if (!isset($argv) || empty($argv) || !isset($argv[1]) || !file_exists(syncdb::getBasepath().'/profiles/' . $argv[1] . '.json'))
{
	die('missing profile'.PHP_EOL);
}

syncdb::sync($argv[1]);