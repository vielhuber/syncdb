@echo off
REM save current dir
set OLDDIR=%CD%
REM change to script dir
@cd /d "%~dp0"
php syncdb %*
REM change back
chdir /d %OLDDIR%