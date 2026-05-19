@echo off
REM save current dir
set OLDDIR=%CD%
REM change to script dir
@cd /d "%~dp0"
set PHP_BIN=%SYNCDB_PHP_BIN%
if "%PHP_BIN%"=="" if exist "..\.phprc" (
    set /p PHP_VERSION=<"..\.phprc"
    set PHP_BIN=php%PHP_VERSION%
)
if "%PHP_BIN%"=="" set PHP_BIN=php
"%PHP_BIN%" syncdb.php %*
REM change back
chdir /d %OLDDIR%
