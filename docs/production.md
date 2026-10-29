# Running in production

There's no build step or cache to warm. Install the code, run migrations, and check this list:

- [ ] Keep `debug` false, for example with `APP_ENV=production`.
- [ ] Point the web server at `public/`, keep only `index.php` and static files there, and serve over HTTPS.
- [ ] Lint the app before deploying, for example with `find app -name '*.php' -exec php -l {} \;`. One broken file in `app/` breaks every request.
- [ ] Use real environment variables, not `.env`. php-fpm needs `env[NAME]` for each, since `clear_env = yes`, and [commands run from cron or a shell](commands.md#running-commands) need them too.
- [ ] Run `composer install --no-dev` and `php bin/console migrations:run`.
- [ ] Keep `app_key` out of database backups.
- [ ] Back up SQLite with `sqlite3 app.db ".backup backup.db"`, not by copying the file while the app writes.
- [ ] Behind a proxy, list its exact address in [`trusted_proxies`](requests.md#accessing-the-request), or `$request->ip` is the proxy's address.
- [ ] Set php.ini's `expose_php = Off`, or every response names PHP's exact version in an `X-Powered-By` header. `php.ini-production` leaves it on.
- [ ] On Debian and Ubuntu, set php.ini's `session.gc_maxlifetime` to match `session_lifetime`.
- [ ] Add scheduled commands to the crontab, such as `0 3 * * * php /path/to/app/bin/console notes:prune`.
- [ ] Clear old log files from cron if you [log to files](errors.md#where-the-log-goes), such as with `find logs -name 'app-*.log' -mtime +14 -delete`.
