# Windows Development

Native Windows PHP does not provide the Unix-only `pcntl` and `posix`
extensions required by Laravel Horizon. Composer emulates those two extensions
for dependency resolution so Horizon can remain installed for Linux production.

Use Laravel's standard queue worker during Windows development:

```powershell
php artisan queue:work
```

Run Horizon workers only on Linux, WSL, Docker, or the production environment:

```bash
php artisan horizon
```

`composer check-platform-reqs` intentionally checks the real machine and will
still report `pcntl` and `posix` as missing on Windows. Normal Composer install,
update, and require commands use the project platform configuration.
