<?php
namespace vielhuber\syncdb;
require_once syncdb::getBasepath() . '/vendor/autoload.php';
use vielhuber\magicreplace\magicreplace;
class syncdb
{
    public static $debug = false;

    public static $session_id = null;

    public static function getOs()
    {
        if (stristr(PHP_OS, 'DAR')) {
            return 'mac';
        }
        if (stristr(PHP_OS, 'WIN') || stristr(PHP_OS, 'CYGWIN')) {
            return 'windows';
        }
        if (stristr(PHP_OS, 'LINUX')) {
            return 'linux';
        }
        return 'unknown';
    }

    public static function escapePassword($password, $ssh = null)
    {
        if (self::getOs() === 'windows') {
            if (isset($ssh) && $ssh !== null && $ssh != '') {
                $password = '""' . $password . '""';
            } else {
                $password = '"' . $password . '"';
            }
        } else {
            foreach (['*', '?', '[', '<', '>', '&', ';', '!', '|', '$', '(', ')', ' '] as $escape__value) {
                $password = str_replace($escape__value, '\\' . $escape__value, $password);
            }
            if (isset($ssh) && $ssh !== null && $ssh != '') {
                // this is currently disabled (try out on other shells)
                //$password = '\\"' . $password . '\\"';
            } else {
                $password = '"' . $password . '"';
            }
        }
        return $password;
    }

    public static function escapeCmd($cmd)
    {
        if (strpos($cmd, '--') === false) {
            $cmd = "\"" . $cmd . "\"";
        } else {
            $cmd = "\"" . trim(substr($cmd, 0, strpos($cmd, '--'))) . "\" " . trim(substr($cmd, strpos($cmd, '--')));
        }
        return $cmd;
    }

    public static function getBasepath()
    {
        // if this is installed via composer, then we have to go up 4 levels
        if (file_exists(dirname(__DIR__, 4) . '/vendor/autoload.php')) {
            return dirname(__DIR__, 4);
        }
        // if otherwise the git repo is cloned, we go up 1 level
        elseif (file_exists(dirname(__DIR__, 1) . '/vendor/autoload.php')) {
            return dirname(__DIR__, 1);
        } else {
            throw new \Exception('wrong path');
        }
    }

    public static function cleanUp()
    {
        foreach (glob('{,.}*', GLOB_BRACE) as $file) {
            if (is_file($file) && !in_array($file, ['syncdb', 'syncdb.php', 'syncdb.bat'])) {
                $filename = substr($file, 0, strpos($file, '.'));
                $filename = substr($file, 0, 13);
                // other files (log file)
                if (!is_numeric($filename)) {
                    @unlink($file);
                }
                // current session files
                elseif ($filename == self::$session_id) {
                    @unlink($file);
                }
                // abandoned, old files (if this file is older than 10 minutes)
                elseif (intval(self::$session_id) - intval($filename) > 60 * 10 * 1000) {
                    @unlink($file);
                }
            }
        }
    }

    public static function readConfig($profile)
    {
        return json_decode(file_get_contents(self::getBasepath() . '/profiles/' . $profile . '.json'));
    }

    public static function sync($profile)
    {
        $time = microtime(true);

        $config = self::readConfig($profile);

        /*
        if (self::getOs() !== 'windows') {
            if (!isset($config->source->ssh) || $config->source->ssh == false) {
                unset($config->source->cmd);
            }
            unset($config->source->ssh->key);
            if (!isset($config->target->ssh) || $config->target->ssh == false) {
                unset($config->target->cmd);
            }
            unset($config->target->ssh->key);
        }
        */

        echo '- PROFILE: ' . $profile . PHP_EOL;

        self::$session_id = intval(microtime(true) * 1000); // this is important for cleaning up

        $tmp_filename = self::$session_id . '.sql';

        self::cleanUp();

        if ($config->engine == 'mysql') {
            // dump

            $command = '';
            if (isset($config->source->ssh) && $config->source->ssh !== false) {
                $command .=
                    (isset($config->source->ssh->password)
                        ? 'sshpass -p ' . self::escapePassword($config->source->ssh->password) . ' '
                        : '') .
                    'ssh -o StrictHostKeyChecking=no ' .
                    (isset($config->source->ssh->port) ? " -p \"" . $config->source->ssh->port . "\"" : '') .
                    ' ' .
                    (isset($config->source->ssh->key) ? " -i \"" . $config->source->ssh->key . "\"" : '') .
                    " -l \"" .
                    $config->source->ssh->username .
                    "\" " .
                    $config->source->ssh->host .
                    " \"";
            }

            $tablespaces = false;
            if (
                isset($config->source->tablespaces) &&
                ($config->source->tablespaces === true ||
                    $config->source->tablespaces === '1' ||
                    $config->source->tablespaces === 1)
            ) {
                $tablespaces = true;
            }

            $add_disable_column_statistics = false;
            $ver_output = shell_exec(
                (isset($config->source->cmd) ? self::escapeCmd($config->source->cmd) : "\"mysqldump\"") . ' --version'
            );
            if (strpos($ver_output, ' 5.') === false) {
                $add_disable_column_statistics = true;
            }

            $command .=
                (isset($config->source->cmd) ? self::escapeCmd($config->source->cmd) : "\"mysqldump\"") .
                ($tablespaces === false ? ' --no-tablespaces ' : '') .
                ' -h ' .
                $config->source->host .
                ' --port ' .
                $config->source->port .
                ' -u ' .
                $config->source->username .
                ' -p' .
                self::escapePassword($config->source->password, @$config->source->ssh) .
                ($add_disable_column_statistics === true ? ' --column-statistics=0 ' : '') .
                ' --ssl-mode=DISABLED --skip-add-locks --skip-comments --extended-insert=false --disable-keys=false --quick --default-character-set=utf8mb4 ' .
                $config->source->database .
                '';

            if (
                isset($config->source->ssh) &&
                $config->source->ssh !== false &&
                isset($config->source->ssh->type) &&
                $config->source->ssh->type == 'fast'
            ) {
                $command .=
                    ' > ' .
                    (isset($config->source->ssh->tmp_dir) ? $config->source->ssh->tmp_dir : '/tmp/') .
                    $tmp_filename .
                    "\"";
            } else {
                if (isset($config->source->ssh) && $config->source->ssh !== false) {
                    $command .= "\"";
                }

                $command .= " > \"" . $tmp_filename . "\"";
            }

            self::executeCommand($command, '--- DUMPING DATABASE...');

            // fetch

            if (
                isset($config->source->ssh) &&
                $config->source->ssh !== false &&
                isset($config->source->ssh->type) &&
                $config->source->ssh->type == 'fast'
            ) {
                if (
                    isset($config->source->ssh) &&
                    isset($config->source->ssh->zip) &&
                    $config->source->ssh->zip === true
                ) {
                    $command =
                        (isset($config->source->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword($config->source->ssh->password) . ' '
                            : '') .
                        'ssh -o StrictHostKeyChecking=no ' .
                        (isset($config->source->ssh->port) ? " -p \"" . $config->source->ssh->port . "\"" : '') .
                        ' ' .
                        (isset($config->source->ssh->key) ? " -i \"" . $config->source->ssh->key . "\"" : '') .
                        " -l \"" .
                        $config->source->ssh->username .
                        "\" " .
                        $config->source->ssh->host .
                        " \"" .
                        'cd ' .
                        (isset($config->source->ssh->tmp_dir) ? $config->source->ssh->tmp_dir : '/tmp/') .
                        ' && zip -j -' .
                        (isset($config->source->ssh->zip_compression) ? $config->source->ssh->zip_compression : '9') .
                        ' ' .
                        $tmp_filename .
                        '.zip ' .
                        $tmp_filename .
                        "\"";
                    self::executeCommand($command, '--- ZIPPING DATABASE...');
                    $command =
                        (isset($config->source->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword($config->source->ssh->password) . ' '
                            : '') .
                        'scp -o StrictHostKeyChecking=no -q -r ' .
                        (isset($config->source->ssh->port) ? " -P \"" . $config->source->ssh->port . "\"" : '') .
                        ' ' .
                        (isset($config->source->ssh->key) ? " -i \"" . $config->source->ssh->key . "\"" : '') .
                        " -o User=\"" .
                        $config->source->ssh->username .
                        "\" " .
                        $config->source->ssh->host .
                        ":\"" .
                        (isset($config->source->ssh->tmp_dir) ? $config->source->ssh->tmp_dir : '/tmp/') .
                        $tmp_filename .
                        ".zip\" " .
                        $tmp_filename .
                        '.zip';
                    self::executeCommand($command, '--- COPYING DATABASE TO SOURCE...');
                    if (!file_exists($tmp_filename . '.zip') || filesize($tmp_filename . '.zip') == 0) {
                        echo '--- AN ERROR OCCURED!' . PHP_EOL;
                        self::cleanUp();
                        die();
                    }
                    $command = 'unzip -j -o ' . $tmp_filename . '.zip';
                    self::executeCommand($command, '--- UNZIPPING ZIP FILE...');
                    $command = (self::getOs() == 'windows' ? 'del' : 'rm') . ' -f ' . $tmp_filename . '.zip';
                    self::executeCommand($command, '--- DELETING LOCAL ZIP...');
                    $command =
                        (isset($config->source->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword($config->source->ssh->password) . ' '
                            : '') .
                        'ssh -o StrictHostKeyChecking=no ' .
                        (isset($config->source->ssh->port) ? " -p \"" . $config->source->ssh->port . "\"" : '') .
                        ' ' .
                        (isset($config->source->ssh->key) ? " -i \"" . $config->source->ssh->key . "\"" : '') .
                        " -l \"" .
                        $config->source->ssh->username .
                        "\" " .
                        $config->source->ssh->host .
                        " \"" .
                        (isset($config->source->ssh->rm) ? $config->source->ssh->rm : 'rm -f') .
                        ' ' .
                        (isset($config->source->ssh->tmp_dir) ? $config->source->ssh->tmp_dir : '/tmp/') .
                        $tmp_filename .
                        ".zip\"";
                    self::executeCommand($command, '--- DELETING REMOTE TMP ZIP...');
                    $command =
                        (isset($config->source->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword($config->source->ssh->password) . ' '
                            : '') .
                        'ssh -o StrictHostKeyChecking=no ' .
                        (isset($config->source->ssh->port) ? " -p \"" . $config->source->ssh->port . "\"" : '') .
                        ' ' .
                        (isset($config->source->ssh->key) ? " -i \"" . $config->source->ssh->key . "\"" : '') .
                        " -l \"" .
                        $config->source->ssh->username .
                        "\" " .
                        $config->source->ssh->host .
                        " \"" .
                        (isset($config->source->ssh->rm) ? $config->source->ssh->rm : 'rm -f') .
                        ' ' .
                        (isset($config->source->ssh->tmp_dir) ? $config->source->ssh->tmp_dir : '/tmp/') .
                        $tmp_filename .
                        "\"";
                    self::executeCommand($command, '--- DELETING REMOTE TMP DATABASE...');
                } else {
                    $command =
                        (isset($config->source->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword($config->source->ssh->password) . ' '
                            : '') .
                        'scp -o StrictHostKeyChecking=no -q -r ' .
                        (isset($config->source->ssh->port) ? " -P \"" . $config->source->ssh->port . "\"" : '') .
                        ' ' .
                        (isset($config->source->ssh->key) ? " -i \"" . $config->source->ssh->key . "\"" : '') .
                        " -o User=\"" .
                        $config->source->ssh->username .
                        "\" " .
                        $config->source->ssh->host .
                        ':' .
                        (isset($config->source->ssh->tmp_dir) ? $config->source->ssh->tmp_dir : '/tmp/') .
                        $tmp_filename .
                        ' ' .
                        $tmp_filename .
                        '';
                    self::executeCommand($command, '--- COPYING DATABASE TO SOURCE...');
                    $command =
                        (isset($config->source->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword($config->source->ssh->password) . ' '
                            : '') .
                        'ssh -o StrictHostKeyChecking=no ' .
                        (isset($config->source->ssh->port) ? " -p \"" . $config->source->ssh->port . "\"" : '') .
                        ' ' .
                        (isset($config->source->ssh->key) ? " -i \"" . $config->source->ssh->key . "\"" : '') .
                        " -l \"" .
                        $config->source->ssh->username .
                        "\" " .
                        $config->source->ssh->host .
                        " \"" .
                        (isset($config->source->ssh->rm) ? $config->source->ssh->rm : 'rm -f') .
                        ' ' .
                        (isset($config->source->ssh->tmp_dir) ? $config->source->ssh->tmp_dir : '/tmp/') .
                        $tmp_filename .
                        "\"";
                    self::executeCommand($command, '--- DELETING REMOTE TMP DATABASE...');
                }
            }

            if (!file_exists($tmp_filename) || filesize($tmp_filename) == 0) {
                echo '--- AN ERROR OCCURED!' . PHP_EOL;
                self::cleanUp();
                die();
            }

            // replacing corrupt collations
            // we do this with sed (because we want not to have php memory limit issues by using file_get_contents)
            // mac sed has a slightly different syntax than unix sed (so inplace editing is a little bit tricky)
            $time_tmp = microtime(true);
            echo '--- DOING OPTIMIZATIONS...';
            $sed_quote = self::getOs() === 'windows' ? '"' : "'";
            shell_exec(
                'sed -i' .
                    (self::getOs() === 'mac' ? " ''" : '') .
                    ' -e ' .
                    $sed_quote .
                    's/CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci/CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci/g' .
                    $sed_quote .
                    ' -e ' .
                    $sed_quote .
                    's/COLLATE utf8mb4_unicode_520_ci/COLLATE utf8mb4_unicode_ci/g' .
                    $sed_quote .
                    ' -e ' .
                    $sed_quote .
                    '1s;^;\;SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, AUTOCOMMIT = 0\;SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS = 0\;SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0\;;' .
                    $sed_quote .
                    ' -e ' .
                    $sed_quote .
                    '$ a' .
                    (self::getOs() === 'mac' ? "\'$'\\n''" : '') .
                    ' \;SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS\;SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS\;SET AUTOCOMMIT = @OLD_AUTOCOMMIT\;COMMIT\;' .
                    $sed_quote .
                    ' ' .
                    $tmp_filename .
                    ''
            );
            echo ' (' . number_format(microtime(true) - $time_tmp, 2) . 's)';
            echo PHP_EOL;

            // search / replace
            if (isset($config->replace)) {
                $time_tmp = microtime(true);
                echo '--- SEARCH/REPLACE...';
                magicreplace::run($tmp_filename, $tmp_filename, $config->replace);
                echo ' (' . number_format(microtime(true) - $time_tmp, 2) . 's)';
                echo PHP_EOL;
            }

            // delete

            $command = '';
            if (isset($config->target->ssh) && $config->target->ssh !== false) {
                $command .=
                    (isset($config->target->ssh->password)
                        ? 'sshpass -p ' . self::escapePassword($config->target->ssh->password) . ' '
                        : '') .
                    'ssh -o StrictHostKeyChecking=no ' .
                    (isset($config->target->ssh->port) ? " -p \"" . $config->target->ssh->port . "\"" : '') .
                    ' ' .
                    (isset($config->target->ssh->key) ? " -i \"" . $config->target->ssh->key . "\"" : '') .
                    " -l \"" .
                    $config->target->ssh->username .
                    "\" " .
                    $config->target->ssh->host .
                    " \"";
            }

            if (isset($config->target->ssh) && $config->target->ssh !== false) {
                $escape = '\\';
            } else {
                $escape = '';
            }
            if (self::getOs() === 'windows') {
                if (isset($config->target->ssh) && $config->target->ssh !== false) {
                    $quote = "\\\"";
                } else {
                    $quote = "\"";
                }
            } else {
                $quote = "'";
            }

            $command .=
                (isset($config->target->cmd) ? self::escapeCmd($config->target->cmd) : "\"mysql\"") .
                ' -h ' .
                $config->target->host .
                ' --port ' .
                $config->target->port .
                ' -u ' .
                $config->target->username .
                ' -p' .
                self::escapePassword($config->target->password, @$config->target->ssh) .
                ' -e ' .
                $quote .
                'drop database if exists ' .
                $escape .
                '`' .
                $config->target->database .
                '' .
                $escape .
                '`; create database ' .
                $escape .
                '`' .
                $config->target->database .
                '' .
                $escape .
                '`;' .
                $quote .
                '';

            if (isset($config->target->ssh) && $config->target->ssh !== false) {
                $command .= "\"";
            }

            self::executeCommand($command, '--- DELETING TARGET DATABASE...');

            // push

            $command = '';
            if (isset($config->target->ssh) && $config->target->ssh !== false) {
                $command .=
                    (isset($config->target->ssh->password)
                        ? 'sshpass -p ' . self::escapePassword($config->target->ssh->password) . ' '
                        : '') .
                    'ssh -o StrictHostKeyChecking=no ' .
                    (isset($config->target->ssh->port) ? " -p \"" . $config->target->ssh->port . "\"" : '') .
                    ' ' .
                    (isset($config->target->ssh->key) ? " -i \"" . $config->target->ssh->key . "\"" : '') .
                    " -l \"" .
                    $config->target->ssh->username .
                    "\" " .
                    $config->target->ssh->host .
                    " \"";
            }
            $progress = self::getOs() === 'linux' && (!isset($config->target->ssh) || $config->target->ssh === false);
            if ($progress === true) {
                $command .= "pv \"" . $tmp_filename . "\" | ";
            }
            $command .=
                (isset($config->target->cmd) ? self::escapeCmd($config->target->cmd) : "\"mysql\"") .
                ' -h ' .
                $config->target->host .
                ' --port ' .
                $config->target->port .
                ' -u ' .
                $config->target->username .
                ' -p' .
                self::escapePassword($config->target->password, @$config->target->ssh) .
                ' ' .
                $config->target->database .
                ' --default-character-set=utf8mb4';
            if (isset($config->target->ssh) && $config->target->ssh !== false) {
                $command .= "\"";
            }
            if ($progress === false) {
                $command .= " < \"" . $tmp_filename . "\"";
            }

            self::executeCommand($command, '--- RESTORING DATABASE...');

            echo '- FINISHED (' . number_format(microtime(true) - $time, 2) . 's)' . PHP_EOL;
        }

        if ($config->engine == 'pgsql') {
            // TODO
        }

        self::cleanUp();
    }

    public static function executeCommand($command, $message, $suppress_output = true)
    {
        $time = microtime(true);

        echo $message;

        // remove newlines
        $command = trim(preg_replace('/\s+/', ' ', $command));

        if ($suppress_output === true) {
            if (self::getOs() === 'windows') {
                $command .= ' 2> log.txt';
                //$command .= ' 2> nul';
            } else {
                $command .= ' 2> log.txt';
                //$command .= ' 2>/dev/null';
            }
        }

        if (self::$debug === true) {
            print_r($command);
            echo PHP_EOL;
            echo PHP_EOL;
        }

        shell_exec($command);

        echo ' (' . number_format(microtime(true) - $time, 2) . 's)';
        echo PHP_EOL;

        if (self::logHasError()) {
            echo '--- AN ERROR OCCURED!' . PHP_EOL;
            echo PHP_EOL;
            echo 'command:';
            echo PHP_EOL;
            echo $command;
            echo PHP_EOL;
            echo PHP_EOL;
            echo 'output:';
            echo PHP_EOL;
            echo file_get_contents('log.txt');
            self::cleanUp();
            die();
        }
    }

    public static function logHasError()
    {
        if (!file_exists('log.txt')) {
            return false;
        }
        $log = file_get_contents('log.txt');
        if (
            (stripos($log, 'error') !== false && stripos($log, 'GTID_PURGED') === false) ||
            stripos($log, 'not found') !== false
        ) {
            return true;
        }
    }
}

// usage from command line
if (
    !isset($argv) ||
    empty($argv) ||
    !isset($argv[1]) ||
    !file_exists(syncdb::getBasepath() . '/profiles/' . $argv[1] . '.json')
) {
    die('missing profile' . PHP_EOL);
}
syncdb::sync($argv[1]);
