# Pail and Logging Troubleshooting Guide

This guide explains how Laravel Pail works, common issues, and troubleshooting steps based on real-world debugging experiences.

## Table of Contents

1. [Understanding Laravel Pail](#understanding-laravel-pail)
2. [How Pail Works Internally](#how-pail-works-internally)
3. [Common Issues and Solutions](#common-issues-and-solutions)
4. [Logging Configuration](#logging-configuration)
5. [Best Practices](#best-practices)

---

## Understanding Laravel Pail

### What is Pail?

Laravel Pail is a real-time log viewer that displays application logs in your terminal. Unlike traditional log tailing, Pail intercepts logs at the application level for instant display.

### Key Characteristics

- **Real-time**: Logs appear instantly (milliseconds latency)
- **Event-based**: Intercepts `MessageLogged` events, not file reads
- **JSON format required**: Needs structured JSON for parsing
- **Filtering support**: Can filter by level, message, user, etc.

---

## How Pail Works Internally

### Architecture Overview

```
Your Code: Log::info('Hello')
    ↓ (immediate)
Laravel Logging System
    ↓ (immediate)
MessageLogged Event Fired
    ↓ (immediate)
┌─────────────────┬─────────────────┐
│                 │                 │
│  Log Channel    │   Pail Handler  │
│  (writes to     │  (writes to     │
│   laravel.log)  │   temp file)    │
│                 │                 │
└─────────────────┴─────────────────┘
                        ↓
                  tail -F watches
                        ↓
                   Your Terminal
```

### Step-by-Step Process

1. **Event Interception** (`PailServiceProvider.php`)
   - Pail listens to Laravel's `MessageLogged` event
   - This fires whenever `Log::info()`, `Log::error()`, etc. are called

2. **Real-time Capture** (`Handler.php`)
   - When a log message is created, Pail receives it immediately
   - It doesn't read from `laravel.log` - it intercepts before writing

3. **Temporary Files** (`PailCommand.php`)
   - Creates temporary files in `storage/pail/`
   - Example: `storage/pail/65abc123.pail`
   - Each running `pail` command gets its own file

4. **Tail Watching** (`ProcessFactory.php`)
   - Uses `tail -F` to watch the temporary file
   - Displays new lines as they're written

### Why Pail Doesn't Need Log File Path

Pail doesn't read from log files directly. Instead:
- It intercepts logs at the application level via events
- Writes to its own temp files in `storage/pail/`
- Uses `tail -F` to stream those temp files

This means Pail works regardless of your log channel configuration (`single`, `daily`, `stack`, etc.).

---

## Common Issues and Solutions

### Issue 1: Logs Not Appearing in Pail

#### Symptoms

- Pail starts but shows no log entries
- Logs are being written to `laravel.log` but not visible in Pail

#### Root Cause

Pail intercepts logs via `MessageLogged` events. If logs aren't appearing:
- Pail process may not be running
- Log channel may not be firing events properly
- JSON parsing errors may be silently failing

#### Solution

1. Verify Pail is running:
```bash
ps aux | grep "php artisan pail"
```

2. Verify `MessageLogged` events are being fired (check if logs are being written to confirm events should be firing):
```bash
tail -f storage/logs/laravel.log
```
Note: This checks if logging is working, which indicates events should be firing. Pail doesn't read from this file.

3. Check Pail's temp files:
```bash
ls -la storage/pail/
tail -f storage/pail/*.pail
```

4. Restart Pail:
```bash
php artisan pail
```

### Issue 2: JSON Parsing Errors in Pail

#### Symptoms

```
JsonException: Syntax error
at vendor/laravel/pail/src/ValueObjects/MessageLogged.php:30
```

#### Root Cause

Pail expects structured JSON log entries in its temp files (`storage/pail/*.pail`). Malformed JSON can occur from:
- Incomplete log entries (buffer boundaries when `tail -F` reads partial lines)
- Pail's internal JSON serialization issues
- Corrupted temp files in `storage/pail/`

**Note**: This is NOT related to `laravel.log` - Pail doesn't read from that file. Pail intercepts events and writes to its own temp files.

#### Solution

1. Clear Pail's temp files:
```bash
rm -rf storage/pail/*
```

2. Restart Pail:
```bash
php artisan pail
```

3. If the issue persists, check Pail's temp files for malformed JSON:
```bash
tail -f storage/pail/*.pail
```

4. Ensure your log channel configuration is correct (affects what events are fired, not Pail's parsing)

---

### Viewing Pail Output

To view logs in real-time with Pail, run it separately:

```bash
php artisan pail
```

Pail output appears in the terminal:

```
INFO  Tailing application logs. Press Ctrl+C to exit
... (log entries here)
```

Alternatively, you can tail the log file directly:
```bash
tail -f storage/logs/laravel.log
```

---

## Logging Configuration

### Environment Variables

From `.env`:

```env
LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug
```

**Explanation:**

1. **`LOG_CHANNEL=stack`**: Default log channel (uses `stack` driver)
2. **`LOG_STACK=single`**: Stack channel writes to `single` channel only
3. **`LOG_DEPRECATIONS_CHANNEL=null`**: Disables deprecation logging
4. **`LOG_LEVEL=debug`**: Logs everything (debug and above)

### Log Channels

**Stack Channel**: Combines multiple channels
- Reads `LOG_STACK` (comma-separated list)
- Forwards to specified channels

**Single Channel**: All logs to one file
- Path: `storage/logs/laravel.log`
- Uses JSON formatter (for Pail compatibility)

**Daily Channel**: Rotates logs daily
- Creates files like `laravel-2026-01-06.log`
- Keeps 14 days by default

### JSON Formatter for Pail

**Note**: The JSON formatter in `config/logging.php` is **YAGNI** for fixing Pail errors because:

- Pail uses its own `JsonFormatter` for temp files
- Pail doesn't read from `laravel.log`
- The crash happens in Pail's parsing, not file format

However, JSON formatting is still useful for:
- Log aggregation tools (ELK, Datadog)
- Programmatic parsing
- Consistent structured logs

---

## Best Practices

### 1. Run Pail Separately When Needed

Pail is not included in the `dev:all` script. Run it separately when you need real-time log viewing:

```bash
php artisan pail
```

This keeps the main dev environment simpler and prevents Pail issues from affecting other services.

### 2. Monitor Log File Sizes

Large log files can cause performance issues. Use daily rotation in production:

```php
'default' => env('LOG_CHANNEL', 'daily'),
```

### 3. Set Appropriate Log Levels

**Development:**
```env
LOG_LEVEL=debug  # See everything
```

**Production:**
```env
LOG_LEVEL=error  # Only critical issues
```

### 4. Clean Up Old Logs

Regularly clear or rotate log files:
```bash
> storage/logs/laravel.log  # Clear current log
```

### 5. Use Pail Filters

Filter logs to find specific issues:
```bash
php artisan pail --level=error
php artisan pail --message="database"
php artisan pail --user=123
```

---

## Troubleshooting Checklist

- [ ] Logs not appearing in Pail? → Check if Pail process is running, verify logs are being written
- [ ] JSON parsing errors? → Check for malformed log entries, clear corrupted logs
- [ ] Too many logs? → Adjust `LOG_LEVEL` or use Pail filters
- [ ] Pail crashes? → Check for malformed JSON in log entries
- [ ] Logs not real-time? → Verify Pail is intercepting `MessageLogged` events

---

## Summary

1. **Pail is real-time**: Intercepts logs via events, not file reads
2. **Pail requires JSON**: For parsing structured data
3. **Pail is optional**: Run separately when needed for real-time log viewing
4. **JSON formatter**: Useful for structured logs and log aggregation tools
5. **Log channels**: Configure in `config/logging.php` to control where logs are written

For more information, see:
- [Laravel Logging Documentation](https://laravel.com/docs/logging)
- [Laravel Pail Documentation](https://laravel.com/docs/pail)
