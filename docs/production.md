# Running in production

There's no build step or cache to warm. Install the code, run migrations, and check this list:

- [ ] Keep `debug` false, for example with `APP_ENV=production`.
- [ ] Point the web server at `public/`, keep only `index.php` there, and serve over HTTPS.
- [ ] Run `composer install --no-dev` and `php bin/console migrations:run`.
- [ ] Lint the app before deploying, for example with `find app -name '*.php' -exec php -l {} \;`. One broken file in `app/` breaks every request.
- [ ] Use real environment variables, not `.env`. php-fpm needs `env[NAME]` for each, since `clear_env = yes`.
- [ ] Keep `app_key` out of database backups.
- [ ] Behind a proxy, `$request->ip` is the proxy's address.
- [ ] On Debian and Ubuntu, set php.ini's `session.gc_maxlifetime` to match `session_lifetime`.
- [ ] Add scheduled commands to the crontab, such as `0 3 * * * php /path/to/app/bin/console notes:prune`.
- [ ] Clear old log files from cron if you [log to files](errors.md#where-the-log-goes).
