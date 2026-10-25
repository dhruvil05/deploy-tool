@echo off
cd /d "%~dp0"
echo Deploy tool: http://127.0.0.1:8090
php -S 127.0.0.1:8090 router.php
