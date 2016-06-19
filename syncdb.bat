@echo off
REM change to current dir
@cd /d "%~dp0"
php syncdb.php %*