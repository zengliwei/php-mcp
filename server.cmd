@echo off
chcp 65001 >nul
php8 -S 127.0.0.1:3001 -t %~dp0/public