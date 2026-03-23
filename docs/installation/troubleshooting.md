# Troubleshooting Guide - Belimbing

Common issues and solutions during installation and operation.

## Installation Issues

### "Command not found" Errors

**Problem:** Setup script can't find required commands (php, composer, etc.)

**Solution:**
```bash
# Run requirements check first
./scripts/setup-steps/01-requirements.sh local

# Install missing dependencies
./scripts/setup-steps/20-php.sh local  # For PHP/Composer
./scripts/setup-steps/30-js.sh local   # For JavaScript runtime
```

### Port Already in Use

**Problem:** Port 8000, 5432, 6379, or 443 is already in use

**Solution:**
```bash
# Find what's using the port
sudo lsof -i :8000
sudo lsof -i :5432

# Option 1: Stop the conflicting service
sudo systemctl stop <service-name>

# Option 2: Change ports in .env
# Edit .env and update APP_PORT, DB_PORT, REDIS_PORT
```

### Database Connection Failed

**Problem:** Cannot connect to PostgreSQL

**Solutions:**

1. **Check PostgreSQL is running:**
   ```bash
   sudo systemctl status postgresql
   sudo systemctl start postgresql
   ```

2. **Verify credentials in .env:**
   ```bash
   # Check .env file
   cat .env | grep DB_

   # Test connection manually
   psql -h localhost -U belimbing_app -d blb
   ```

3. **Recreate database:**
   ```bash
   ./scripts/setup-steps/40-database.sh local
   ```

### Redis Connection Failed

**Problem:** Cannot connect to Redis

**Solutions:**

1. **Check Redis is running:**
   ```bash
   sudo systemctl status redis-server
   sudo systemctl start redis-server
   ```

2. **Test Redis connection:**
   ```bash
   redis-cli ping
   # Should return: PONG
   ```

### Permission Denied Errors

**Problem:** Cannot write to storage or cache directories

**Solution:**
```bash
# Fix ownership and permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Or if using your user
sudo chown -R $USER:$USER storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### Composer Install Fails

**Problem:** `composer install` fails with memory or dependency errors

**Solutions:**

1. **Increase PHP memory limit:**
   ```bash
   php -d memory_limit=512M /usr/local/bin/composer install
   ```

2. **Clear Composer cache:**
   ```bash
   composer clear-cache
   composer install --no-cache
   ```

3. **Update Composer:**
   ```bash
   composer self-update
   ```

### APP_KEY Not Generated

**Problem:** APP_KEY is empty after setup

**Solution:**
```bash
# Generate APP_KEY manually
php artisan key:generate

# Or re-run Laravel setup step
./scripts/setup-steps/25-laravel.sh local
```

## Runtime Issues

### Application Won't Start

**Problem:** `./scripts/start-app.sh` fails

**Solutions:**

1. **Check all dependencies:**
   ```bash
   ./scripts/setup-steps/01-requirements.sh local
   ```

2. **Check logs:**
   ```bash
   tail -f storage/logs/laravel.log
   tail -f storage/logs/scripts/start-app.log
   ```

3. **Verify .env file exists:**
   ```bash
   ls -la .env
   # If missing, copy from example
   cp .env.example .env
   php artisan key:generate
   ```

### Caddy SSL Errors

**Problem:** HTTPS not working, certificate errors

**Solutions:**

1. **For local development, use mkcert:**
   ```bash
   # Install mkcert (if not already installed)
   # Then generate certificates
   mkcert -install
   mkcert local.blb.lara
   ```

2. **Check Caddyfile:**
   ```bash
   cat Caddyfile
   # Verify domain and port configuration
   ```

3. **Restart Caddy:**
   ```bash
   caddy reload --config Caddyfile
   ```

### Queue Workers Not Processing

**Problem:** Jobs stuck in queue

**Solutions:**

1. **Check queue worker is running:**
   ```bash
   ps aux | grep "queue:work"
   ```

2. **Restart queue worker:**
   ```bash
   php artisan queue:restart
   php artisan queue:work
   ```

3. **Check queue connection:**
   ```bash
   # Verify database queue table exists
   php artisan migrate
   ```

### High Memory Usage

**Problem:** Application using too much memory

**Solutions:**

1. **Check current usage:**
   ```bash
   php artisan belimbing:health
   # Or visit /api/health/dashboard
   ```

2. **Optimize PHP settings:**
   ```bash
   # Edit php.ini or create custom.ini
   memory_limit = 256M
   opcache.enable = 1
   ```

3. **Clear caches:**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan view:clear
   ```

## Docker Issues

### Docker Build Fails

**Problem:** `docker compose build` fails

**Solutions:**

1. **Check Docker is running:**
   ```bash
   docker ps
   sudo systemctl start docker
   ```

2. **Clear Docker cache:**
   ```bash
   docker system prune -a
   docker compose build --no-cache
   ```

3. **Check disk space:**
   ```bash
   df -h
   # Docker needs at least 10GB free
   ```

### Container Won't Start

**Problem:** Docker containers exit immediately

**Solutions:**

1. **Check logs:**
   ```bash
   docker compose -f docker/docker-compose.yml logs
   ```

2. **Verify environment variables:**
   ```bash
   # Check .env file exists and has required values
   cat .env
   ```

3. **Check container health:**
   ```bash
   docker compose -f docker/docker-compose.yml ps
   ```

### Database Connection in Docker

**Problem:** App container can't connect to database

**Solutions:**

1. **Verify services are on same network:**
   ```bash
   docker network ls
   docker network inspect blb_belimbing-network
   ```

2. **Check database container:**
   ```bash
   docker compose -f docker/docker-compose.yml ps postgres
   docker compose -f docker/docker-compose.yml logs postgres
   ```

3. **Test connection from app container:**
   ```bash
   docker compose -f docker/docker-compose.yml exec app php artisan tinker
   >>> DB::connection()->getPdo();
   ```

## Update Issues

### Update Command Fails

**Problem:** `php artisan belimbing:update` fails

**Solutions:**

1. **Check git status:**
   ```bash
   git status
   # Commit or stash changes before updating
   ```

2. **Run with dry-run first:**
   ```bash
   php artisan belimbing:update --dry-run
   ```

3. **Manual update:**
   ```bash
   git pull origin main
   composer install --no-dev --optimize-autoloader
   php artisan migrate
   php artisan config:cache
   ```

### Migration Errors

**Problem:** Database migrations fail during update

**Solutions:**

1. **Check migration status:**
   ```bash
   php artisan migrate:status
   ```

2. **Rollback and retry:**
   ```bash
   php artisan migrate:rollback --step=1
   php artisan migrate
   ```

3. **Use backup:**
   ```bash
   # Restore from backup created before update
   php artisan belimbing:backup --type=database
   # Then restore manually
   ```

## Performance Issues

### Slow Page Loads

**Problem:** Application is slow

**Solutions:**

1. **Enable OPcache:**
   ```bash
   # Check if OPcache is enabled
   php -i | grep opcache
   ```

2. **Clear and rebuild caches:**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

3. **Check database queries:**
   ```bash
   # Enable query logging in .env
   DB_LOG_QUERIES=true
   ```

### High CPU Usage

**Problem:** Application using too much CPU

**Solutions:**

1. **Check running processes:**
   ```bash
   top
   ps aux | grep php
   ```

2. **Limit queue workers:**
   ```bash
   # Reduce number of queue workers
   # Edit start-app.sh or use supervisor
   ```

3. **Optimize database:**
   ```bash
   # Add indexes, optimize queries
   php artisan db:show
   ```

## Getting More Help

If you're still experiencing issues:

1. **Check logs:**
   - Application: `storage/logs/laravel.log`
   - Scripts: `storage/logs/scripts/`
   - System: `journalctl -u <service-name>`

2. **Run diagnostics:**
   ```bash
   ./scripts/setup-steps/01-requirements.sh local
   curl http://localhost:8000/health
   php artisan belimbing:health
   ```

3. **Collect information:**
   - OS version: `uname -a`
   - PHP version: `php -v`
   - Laravel version: `php artisan --version`
   - Error messages from logs

4. **Open an issue:**
   - Include error messages
   - Include relevant log excerpts
   - Describe steps to reproduce

---

For more detailed information, see the [Architecture Documentation](../architecture/) and [Module Documentation](../modules/).
