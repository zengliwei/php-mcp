@echo off
chcp 65001 >nul
cd /d %~dp0
start /b php8 client.php