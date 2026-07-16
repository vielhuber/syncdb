<?php
declare(strict_types=1);

namespace vielhuber\syncdb;

require_once syncdb::getBasepath() . '/vendor/autoload.php';

use vielhuber\magicreplace\magicreplace;

final class syncdb
{
    public static bool $debug = false;

    public static ?int $session_id = null;

    public static function getOs(): string
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

    public static function escapePassword(string $password, mixed $ssh = null, mixed $cmd = null): string
    {
        if (preg_match('/[\0\r\n]/', $password) === 1) {
            throw new \RuntimeException('unsafe password value');
        }
        $hasSsh = $ssh !== null && $ssh !== false && $ssh !== '';
        if (self::getOs() === 'windows') {
            if ($hasSsh) {
                $password = '""' . $password . '""';
            } else {
                $password = '"' . $password . '"';
            }
        } else {
            if ($hasSsh && $ssh !== true) {
                if (self::isRemoteWindows($ssh, $cmd)) {
                    return str_replace(['\\', '"', '$', '`'], ['\\\\', '\\"', '\\$', '\\`'], $password);
                }
                return str_replace(['$', '`'], ['\\$', '\\`'], escapeshellarg($password));
            }
            foreach (['\\', '"', '*', '?', '[', '<', '>', '&', ';', '!', '|', '$', '(', ')', ' '] as $escapeCharacter) {
                $password = str_replace($escapeCharacter, '\\' . $escapeCharacter, $password);
            }
            if (!$hasSsh) {
                $password = '"' . $password . '"';
            }
        }
        return $password;
    }

    private static function isRemoteWindows(mixed $ssh, mixed $cmd = null): bool
    {
        if ($ssh === null || $ssh === false || $ssh === '' || $ssh === true) {
            return false;
        }
        if (is_object($ssh) && isset($ssh->os) && strtolower((string) $ssh->os) === 'windows') {
            return true;
        }
        if (is_object($ssh) && isset($ssh->tmp_dir) && preg_match('/^[A-Z]:[\/\\\\]/i', (string) $ssh->tmp_dir) === 1) {
            return true;
        }
        if (is_string($cmd) && preg_match('/(^|[\/\\\\\s])[^\/\\\\\s]+\.exe(\s|$)/i', $cmd) === 1) {
            return true;
        }
        return false;
    }

    public static function escapeCmd(string $cmd): string
    {
        if (preg_match('/[\0\r\n;&|`$<>]/', $cmd) === 1) {
            throw new \RuntimeException('unsafe cmd value');
        }
        if (strpos($cmd, '--') !== false) {
            $cmd = "\"" . trim(substr($cmd, 0, strpos($cmd, '--'))) . "\" " . trim(substr($cmd, strpos($cmd, '--')));
        } elseif (strpos($cmd, '.sh') !== false && strpos($cmd, ' ') !== false) {
            $cmd = "\"" . trim(substr($cmd, 0, strpos($cmd, ' '))) . "\" " . trim(substr($cmd, strpos($cmd, ' ')));
        } else {
            $cmd = "\"" . $cmd . "\"";
        }
        return $cmd;
    }

    public static function getBasepath(): string
    {
        // if this is installed via composer, then we have to go up 4 levels
        if (file_exists(dirname(__DIR__, 4) . '/vendor/autoload.php')) {
            return dirname(__DIR__, 4);
        }
        // if otherwise the git repo is cloned, we go up 1 level
        if (file_exists(dirname(__DIR__, 1) . '/vendor/autoload.php')) {
            return dirname(__DIR__, 1);
        }

        throw new \RuntimeException('wrong path');
    }

    public static function cleanUp(): void
    {
        if (self::$debug === true) {
            return;
        }
        foreach (glob('{,.}*', GLOB_BRACE) as $file) {
            if (is_file($file) && !in_array($file, ['syncdb', 'syncdb.php', 'syncdb.bat'])) {
                $filename = substr($file, 0, strpos($file, '.'));
                $filename = substr($file, 0, 13);
                // other files (log file)
                if (!is_numeric($filename)) {
                    if (is_writable($file)) {
                        unlink($file);
                    }
                }
                // current session files
                elseif ($filename === (string) self::$session_id) {
                    if (is_writable($file)) {
                        unlink($file);
                    }
                }
                // abandoned, old files (if this file is older than 10 minutes)
                elseif (intval(self::$session_id) - intval($filename) > 60 * 10 * 1000) {
                    if (is_writable($file)) {
                        unlink($file);
                    }
                }
            }
        }
    }

    public static function readConfig(string $profile): \stdClass
    {
        return json_decode(
            (string) file_get_contents(self::getBasepath() . '/profiles/' . $profile . '.json'),
            false,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    private static function validateProfileValue(mixed $value, string $field, bool $allowSpaces = false): void
    {
        if ($value === null || $value === false || $value === true || is_int($value) || is_float($value)) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                self::validateProfileValue($item, $field . '.' . $key, $allowSpaces);
            }
            return;
        }
        if (!is_string($value)) {
            return;
        }
        $pattern = $allowSpaces ? '/[\0\r\n;&|`$<>]/' : '/[\0\r\n;&|`$<>"\'\s]/';
        if (preg_match($pattern, $value) === 1) {
            throw new \RuntimeException('unsafe profile value in ' . $field);
        }
    }

    private static function validateEndpointConfig(\stdClass $endpoint, string $prefix): void
    {
        foreach (['host', 'port', 'username', 'database'] as $field) {
            if (isset($endpoint->{$field})) {
                self::validateProfileValue($endpoint->{$field}, $prefix . '.' . $field);
            }
        }
        if (isset($endpoint->cmd)) {
            self::validateProfileValue($endpoint->cmd, $prefix . '.cmd', true);
        }
        if (property_exists($endpoint, 'sql_log_bin') && !is_bool($endpoint->sql_log_bin)) {
            throw new \RuntimeException('invalid profile value in ' . $prefix . '.sql_log_bin');
        }
        if (!isset($endpoint->ssh) || $endpoint->ssh === false) {
            return;
        }
        foreach (['host', 'port', 'username', 'key', 'tmp_dir'] as $field) {
            if (isset($endpoint->ssh->{$field})) {
                self::validateProfileValue($endpoint->ssh->{$field}, $prefix . '.ssh.' . $field);
            }
        }
        if (isset($endpoint->ssh->rm)) {
            self::validateProfileValue($endpoint->ssh->rm, $prefix . '.ssh.rm', true);
        }
    }

    private static function validateConfig(\stdClass $config): void
    {
        foreach (['source', 'target'] as $endpoint) {
            if (isset($config->{$endpoint}) && $config->{$endpoint} instanceof \stdClass) {
                self::validateEndpointConfig($config->{$endpoint}, $endpoint);
            }
        }
        if (isset($config->ignore_table_data) && is_array($config->ignore_table_data)) {
            self::validateProfileValue($config->ignore_table_data, 'ignore_table_data');
        }
    }

    public static function sync(string $profile): void
    {
        $time = microtime(true);

        $config = self::readConfig($profile);
        self::validateConfig($config);

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

        $tmp_filename = self::$session_id . ($config->engine === 'sqlite' ? '.sqlite' : '.sql');

        self::cleanUp();

        if ($config->engine === 'mysql') {
            $command = '';
            $command .=
                (isset($config->source->ssh) && $config->source->ssh !== false
                    ? (isset($config->source->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword((string) $config->source->ssh->password, true) . ' '
                            : '') .
                        'ssh -o StrictHostKeyChecking=no ' .
                        (isset($config->source->ssh->port) ? " -p \"" . $config->source->ssh->port . "\"" : '') .
                        ' ' .
                        (isset($config->source->ssh->key) ? " -i \"" . $config->source->ssh->key . "\"" : '') .
                        " -l \"" .
                        $config->source->ssh->username .
                        "\" " .
                        $config->source->ssh->host .
                        " \""
                    : '') .
                (isset($config->source->cmd) ? self::escapeCmd($config->source->cmd) : "\"mysqldump\"") .
                ' --version' .
                (isset($config->source->ssh) && $config->source->ssh !== false ? "\"" : '') .
                (self::getOs() === 'windows' ? ' 2> nul' : ' 2> /dev/null');
            if (self::$debug === true) {
                print_r($command);
                echo PHP_EOL;
                echo PHP_EOL;
            }
            $ver_output_source = shell_exec($command);
            if ($ver_output_source === null) {
                $ver_output_source = '';
            }
            $command = '';
            $command .=
                (isset($config->target->ssh) && $config->target->ssh !== false
                    ? (isset($config->target->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword((string) $config->target->ssh->password, true) . ' '
                            : '') .
                        'ssh -o StrictHostKeyChecking=no ' .
                        (isset($config->target->ssh->port) ? " -p \"" . $config->target->ssh->port . "\"" : '') .
                        ' ' .
                        (isset($config->target->ssh->key) ? " -i \"" . $config->target->ssh->key . "\"" : '') .
                        " -l \"" .
                        $config->target->ssh->username .
                        "\" " .
                        $config->target->ssh->host .
                        " \""
                    : '') .
                (isset($config->target->cmd) ? self::escapeCmd($config->target->cmd) : "\"mysql\"") .
                ' --version' .
                (isset($config->target->ssh) && $config->target->ssh !== false ? "\"" : '') .
                (self::getOs() === 'windows' ? ' 2> nul' : ' 2> /dev/null');
            if (self::$debug === true) {
                print_r($command);
                echo PHP_EOL;
                echo PHP_EOL;
            }
            $ver_output_target = shell_exec($command);
            if ($ver_output_target === null) {
                $ver_output_target = '';
            }

            // dump

            $command = '';
            if (isset($config->source->ssh) && $config->source->ssh !== false) {
                $command .=
                    (isset($config->source->ssh->password)
                        ? 'sshpass -p ' . self::escapePassword((string) $config->source->ssh->password, true) . ' '
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
            $add_ssl_mode = false;

            if (
                strpos($ver_output_source, ' 5.') === false &&
                !preg_match('/CYGWIN/', $ver_output_source) &&
                strpos($ver_output_source, 'MariaDB') === false
            ) {
                $add_disable_column_statistics = true;
            }
            // --ssl-mode is only available for mysqldump >= 5.7.11
            if (
                !preg_match('/ [1-5]\.[1-6]/', $ver_output_source) &&
                !preg_match('/CYGWIN/', $ver_output_source) &&
                strpos($ver_output_source, 'MariaDB') === false
            ) {
                $add_ssl_mode = true;
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
                self::escapePassword(
                    (string) $config->source->password,
                    $config->source->ssh ?? null,
                    $config->source->cmd ?? null
                ) .
                ($add_disable_column_statistics === true ? ' --column-statistics=0 ' : '') .
                ($add_ssl_mode === true ? ' --ssl-mode=DISABLED ' : '') .
                ' --skip-add-locks --skip-comments --extended-insert=true --disable-keys=false --quick --default-character-set=utf8mb4 ' .
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

            if (
                isset($config->ignore_table_data) &&
                is_array($config->ignore_table_data) &&
                !empty($config->ignore_table_data)
            ) {
                self::executeCommand(
                    str_replace('--quick', '--quick --no-data --skip-triggers', $command),
                    '--- DUMPING DATABASE (SCHEMAS)...'
                );

                self::executeCommand(
                    str_replace(
                        ' > ',
                        ' >> ',
                        str_replace(
                            '--quick',
                            '--quick --no-create-info ' .
                                implode(
                                    ' ',
                                    array_map(function ($ignore_table_data__value) use ($config) {
                                        return '--ignore-table="' .
                                            $config->source->database .
                                            '.' .
                                            $ignore_table_data__value .
                                            '"';
                                    }, $config->ignore_table_data)
                                ),
                            $command
                        )
                    ),
                    '--- DUMPING DATABASE (DATA)...'
                );
            } else {
                self::executeCommand($command, '--- DUMPING DATABASE...');
            }

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
                            ? 'sshpass -p ' . self::escapePassword((string) $config->source->ssh->password, true) . ' '
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
                            ? 'sshpass -p ' . self::escapePassword((string) $config->source->ssh->password, true) . ' '
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
                    $command = (self::getOs() === 'windows' ? 'del' : 'rm') . ' -f ' . $tmp_filename . '.zip';
                    self::executeCommand($command, '--- DELETING LOCAL ZIP...');
                    $command =
                        (isset($config->source->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword((string) $config->source->ssh->password, true) . ' '
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
                        ' \"' .
                        (isset($config->source->ssh->tmp_dir) ? $config->source->ssh->tmp_dir : '/tmp/') .
                        $tmp_filename .
                        '.zip\\""';
                    self::executeCommand($command, '--- DELETING REMOTE TMP ZIP...', true, true);
                    $command =
                        (isset($config->source->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword((string) $config->source->ssh->password, true) . ' '
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
                        ' \"' .
                        (isset($config->source->ssh->tmp_dir) ? $config->source->ssh->tmp_dir : '/tmp/') .
                        $tmp_filename .
                        '\\""';
                    self::executeCommand($command, '--- DELETING REMOTE TMP DATABASE...', true, true);
                } else {
                    $command =
                        (isset($config->source->ssh->password)
                            ? 'sshpass -p ' . self::escapePassword((string) $config->source->ssh->password, true) . ' '
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
                            ? 'sshpass -p ' . self::escapePassword((string) $config->source->ssh->password, true) . ' '
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
                        ' \"' .
                        (isset($config->source->ssh->tmp_dir) ? $config->source->ssh->tmp_dir : '/tmp/') .
                        $tmp_filename .
                        '\\""';
                    self::executeCommand($command, '--- DELETING REMOTE TMP DATABASE...', true, true);
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
            $sql_log_bin_before = '';
            $sql_log_bin_after = '';
            if (($config->target->sql_log_bin ?? true) === true) {
                $sql_log_bin_before = '\;SET @OLD_SQL_LOG_BIN=@@SESSION.SQL_LOG_BIN\;SET SESSION SQL_LOG_BIN=0';
                $sql_log_bin_after = 'SET SESSION SQL_LOG_BIN=@OLD_SQL_LOG_BIN\;';
            }
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
                    '1s;^;' .
                    $sql_log_bin_before .
                    '\;SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, AUTOCOMMIT = 0\;SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS = 0\;SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0\;;' .
                    $sed_quote .
                    ' -e ' .
                    $sed_quote .
                    '$ a' .
                    (self::getOs() === 'mac' ? "\'$'\\n''" : '') .
                    ' \;SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS\;SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS\;SET AUTOCOMMIT = @OLD_AUTOCOMMIT\;COMMIT\;' .
                    $sql_log_bin_after .
                    $sed_quote .
                    ' ' .
                    $tmp_filename .
                    ''
            );
            // handle error "Variable 'sql_mode' can't be set to the value of 'NO_AUTO_CREATE_USER' Operation failed with exitcode 1"
            // https://stackoverflow.com/questions/50336378/variable-sql-mode-cant-be-set-to-the-value-of-no-auto-create-user
            if (
                !preg_match('/ [1-7]\.[0-9]/', $ver_output_target) &&
                !preg_match('/CYGWIN/', $ver_output_target) &&
                strpos($ver_output_target, 'MariaDB') === false
            ) {
                shell_exec(
                    'sed -i' .
                        (self::getOs() === 'mac' ? " ''" : '') .
                        ' -e ' .
                        $sed_quote .
                        's/,NO_AUTO_CREATE_USER//g' .
                        $sed_quote .
                        ' -e ' .
                        $sed_quote .
                        's/NO_AUTO_CREATE_USER,//g' .
                        $sed_quote .
                        ' -e ' .
                        $sed_quote .
                        's/NO_AUTO_CREATE_USER//g' .
                        $sed_quote .
                        ' ' .
                        $tmp_filename .
                        ''
                );
            }
            echo ' (' . number_format(microtime(true) - $time_tmp, 2) . 's)';
            echo PHP_EOL;

            // search / replace
            if (isset($config->replace)) {
                $time_tmp = microtime(true);
                echo '--- SEARCH/REPLACE...';
                magicreplace::run($tmp_filename, $tmp_filename, (array) $config->replace);
                echo ' (' . number_format(microtime(true) - $time_tmp, 2) . 's)';
                echo PHP_EOL;
            }

            // delete

            $command = '';
            if (isset($config->target->ssh) && $config->target->ssh !== false) {
                $command .=
                    (isset($config->target->ssh->password)
                        ? 'sshpass -p ' . self::escapePassword((string) $config->target->ssh->password, true) . ' '
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
                self::escapePassword(
                    (string) $config->target->password,
                    $config->target->ssh ?? null,
                    $config->target->cmd ?? null
                ) .
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
                        ? 'sshpass -p ' . self::escapePassword((string) $config->target->ssh->password, true) . ' '
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
                self::escapePassword(
                    (string) $config->target->password,
                    $config->target->ssh ?? null,
                    $config->target->cmd ?? null
                ) .
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

        if ($config->engine === 'pgsql') {
            // TODO
        }

        if ($config->engine === 'sqlite') {
            $sourceFile = (string) ($config->source->database ?? '');
            $targetFile = (string) ($config->target->database ?? '');

            if ($sourceFile === '' || $targetFile === '') {
                echo '--- AN ERROR OCCURED!' . PHP_EOL;
                self::cleanUp();
                die();
            }

            if (isset($config->source->ssh) && $config->source->ssh !== false) {
                $remoteTmpFile =
                    rtrim((string) ($config->source->ssh->tmp_dir ?? '/tmp/'), '/') . '/' . basename($tmp_filename);
                $sshCommand =
                    (isset($config->source->ssh->password)
                        ? 'sshpass -p ' . self::escapePassword((string) $config->source->ssh->password, true) . ' '
                        : '') .
                    'ssh -o StrictHostKeyChecking=no ' .
                    (isset($config->source->ssh->port)
                        ? ' -p ' . escapeshellarg((string) $config->source->ssh->port)
                        : '') .
                    ' ' .
                    (isset($config->source->ssh->key)
                        ? ' -i ' . escapeshellarg((string) $config->source->ssh->key)
                        : '') .
                    ' -l ' .
                    escapeshellarg((string) $config->source->ssh->username) .
                    ' ' .
                    escapeshellarg((string) $config->source->ssh->host);
                $sqliteBackupCommand =
                    self::escapeCmd((string) ($config->source->cmd ?? 'sqlite3')) .
                    ' ' .
                    escapeshellarg($sourceFile) .
                    ' ' .
                    escapeshellarg('.backup ' . escapeshellarg($remoteTmpFile));

                self::executeCommand(
                    $sshCommand .
                        ' ' .
                        escapeshellarg(
                            $sqliteBackupCommand .
                                ' && cat ' .
                                escapeshellarg($remoteTmpFile) .
                                ' && rm -f ' .
                                escapeshellarg($remoteTmpFile)
                        ) .
                        ' > ' .
                        escapeshellarg($tmp_filename),
                    '--- BACKING UP DATABASE...'
                );
            } else {
                $time_tmp = microtime(true);
                echo '--- BACKING UP DATABASE...';

                if (!is_file($sourceFile) || !is_readable($sourceFile)) {
                    echo PHP_EOL;
                    echo '--- AN ERROR OCCURED!' . PHP_EOL;
                    self::cleanUp();
                    die();
                }

                if (!class_exists(\SQLite3::class)) {
                    echo PHP_EOL;
                    echo '--- SQLITE3 EXTENSION IS MISSING' . PHP_EOL;
                    self::cleanUp();
                    die();
                }

                if (file_exists($tmp_filename)) {
                    unlink($tmp_filename);
                }

                try {
                    $sourceDatabase = new \SQLite3($sourceFile, SQLITE3_OPEN_READONLY);
                    $targetDatabase = new \SQLite3($tmp_filename);
                    $backupSuccessful = $sourceDatabase->backup($targetDatabase);
                    $sourceDatabase->close();
                    $targetDatabase->close();
                } catch (\SQLite3Exception) {
                    $backupSuccessful = false;
                }

                if ($backupSuccessful !== true || !file_exists($tmp_filename) || filesize($tmp_filename) === 0) {
                    echo PHP_EOL;
                    echo '--- AN ERROR OCCURED!' . PHP_EOL;
                    self::cleanUp();
                    die();
                }

                echo ' (' . number_format(microtime(true) - $time_tmp, 2) . 's)';
                echo PHP_EOL;
            }

            if (!file_exists($tmp_filename) || filesize($tmp_filename) === 0) {
                echo '--- AN ERROR OCCURED!' . PHP_EOL;
                self::cleanUp();
                die();
            }

            if (isset($config->replace)) {
                $time_tmp = microtime(true);
                $tmp_sql_filename = self::$session_id . '.sqlite.sql';
                $sqliteCmd = isset($config->target->cmd) ? self::escapeCmd((string) $config->target->cmd) : '"sqlite3"';

                self::executeCommand(
                    $sqliteCmd . ' ' . escapeshellarg($tmp_filename) . ' .dump > ' . escapeshellarg($tmp_sql_filename),
                    '--- DUMPING SQLITE DATABASE...'
                );

                echo '--- SEARCH/REPLACE...';
                magicreplace::run($tmp_sql_filename, $tmp_sql_filename, (array) $config->replace);
                echo ' (' . number_format(microtime(true) - $time_tmp, 2) . 's)';
                echo PHP_EOL;

                unlink($tmp_filename);

                self::executeCommand(
                    $sqliteCmd . ' ' . escapeshellarg($tmp_filename) . ' < ' . escapeshellarg($tmp_sql_filename),
                    '--- RESTORING SQLITE DATABASE...'
                );
            }

            if (isset($config->target->ssh) && $config->target->ssh !== false) {
                $remoteTmpFile =
                    rtrim((string) ($config->target->ssh->tmp_dir ?? '/tmp/'), '/') . '/' . basename($tmp_filename);
                $sshCommand =
                    (isset($config->target->ssh->password)
                        ? 'sshpass -p ' . self::escapePassword((string) $config->target->ssh->password, true) . ' '
                        : '') .
                    'ssh -o StrictHostKeyChecking=no ' .
                    (isset($config->target->ssh->port)
                        ? ' -p ' . escapeshellarg((string) $config->target->ssh->port)
                        : '') .
                    ' ' .
                    (isset($config->target->ssh->key)
                        ? ' -i ' . escapeshellarg((string) $config->target->ssh->key)
                        : '') .
                    ' -l ' .
                    escapeshellarg((string) $config->target->ssh->username) .
                    ' ' .
                    escapeshellarg((string) $config->target->ssh->host);

                self::executeCommand(
                    'cat ' .
                        escapeshellarg($tmp_filename) .
                        ' | ' .
                        $sshCommand .
                        ' ' .
                        escapeshellarg('cat > ' . escapeshellarg($remoteTmpFile)),
                    '--- COPYING DATABASE TO TARGET...'
                );

                self::executeCommand(
                    $sshCommand .
                        ' ' .
                        escapeshellarg(
                            'rm -f ' .
                                escapeshellarg($targetFile . '-wal') .
                                ' ' .
                                escapeshellarg($targetFile . '-shm') .
                                ' && mv ' .
                                escapeshellarg($remoteTmpFile) .
                                ' ' .
                                escapeshellarg($targetFile)
                        ),
                    '--- MOVING DATABASE INTO PLACE...'
                );
            } else {
                $time_tmp = microtime(true);
                echo '--- COPYING DATABASE TO TARGET...';

                foreach ([$targetFile . '-wal', $targetFile . '-shm'] as $sidecarFile) {
                    if (is_file($sidecarFile)) {
                        unlink($sidecarFile);
                    }
                }

                if (
                    copy($tmp_filename, $targetFile) !== true ||
                    !file_exists($targetFile) ||
                    filesize($targetFile) === 0
                ) {
                    echo PHP_EOL;
                    echo '--- AN ERROR OCCURED!' . PHP_EOL;
                    self::cleanUp();
                    die();
                }

                echo ' (' . number_format(microtime(true) - $time_tmp, 2) . 's)';
                echo PHP_EOL;
            }

            echo '- FINISHED (' . number_format(microtime(true) - $time, 2) . 's)' . PHP_EOL;
        }

        self::cleanUp();
    }

    public static function executeCommand(
        string $command,
        string $message,
        bool $suppress_output = true,
        bool $ignore_errors = false
    ): void {
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

        $exitCode = 0;
        exec($command, $output, $exitCode);

        echo ' (' . number_format(microtime(true) - $time, 2) . 's)';
        echo PHP_EOL;

        if ($ignore_errors === true) {
            return;
        }

        if ($exitCode !== 0 || self::logHasError()) {
            echo '--- AN ERROR OCCURED!' . PHP_EOL;
            echo PHP_EOL;
            echo 'command:';
            echo PHP_EOL;
            echo $command;
            echo PHP_EOL;
            echo PHP_EOL;
            echo 'output:';
            echo PHP_EOL;
            echo file_exists('log.txt') ? file_get_contents('log.txt') : '';
            self::cleanUp();
            die();
        }
    }

    public static function logHasError(): bool
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

        return false;
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
ini_set('memory_limit', '3000M');
syncdb::sync($argv[1]);
