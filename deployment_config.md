# Proxmox / Linux Deployment Guide

This document provides the necessary configuration and steps to deploy the Antigravity Billing System on a Proxmox environment (LXC Container or VM) using Nginx and PHP-FPM.

## 1. Server Environment Requirements
- **OS**: Ubuntu 22.04 LTS / Debian 11+
- **Web Server**: Nginx
- **PHP**: PHP 8.1 or 8.2 (with `php-fpm`, `php-sqlite3`, `php-mbstring`, `php-curl`)
- **Permissions**: The web server user (`www-data`) must have write access to the project root for SQLite functionality.

## 2. Nginx Virtual Host Configuration
Create a new file `/etc/nginx/sites-available/einvabill` and paste the following:

```nginx
server {
    listen 80;
    server_name your-domain.com; # Change this to your domain or IP
    root /var/www/html/einvabill;
    index index.php index.html;

    # High Concurrency & Speed Optimizations
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript;
    
    # Hide hidden files (including .sqlite database)
    location ~ /\.(?!well-known).* {
        deny all;
    }

    # Protect the SQLite database file explicitly
    location ~ \.sqlite$ {
        deny all;
    }

    # Main Application Logic
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM Handling
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock; # Ensure version matches
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Buffer optimizations for high load
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    # Static Assets Caching (Browsers will cache for 1 month)
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, no-transform";
        access_log off;
    }
}
```

## 3. Post-Deployment Checklist (Terminal Proxmox)

> [!IMPORTANT]
> **Essential Permission Setup**:
> Run these commands inside your LXC/VM terminal to ensure the database can be written and assets can be uploaded:
> ```bash
> chown -R www-data:www-data /var/www/html/einvabill
> chmod -R 775 /var/www/html/einvabill
> ```

## 4. PHP-FPM Fine-Tuning
For many simultaneous users, edit `/etc/php/8.1/fpm/pool.d/www.conf`:
- `pm = dynamic`
- `pm.max_children = 50` (Adjust based on RAM, 50 is safe for 2GB RAM)
- `pm.start_servers = 10`
- `pm.min_spare_servers = 5`
- `pm.max_spare_servers = 20`

## 5. Reverse Proxy Notes (SSL Offloading)
If you use a **Proxmox Proxy** or **Cloudflare** for SSL, add this to the top of your `index.php` or `init.php` if detection fails:
```php
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}
```
*(This is already partially handled by our dynamic protocol helper in `init.php`)*.
