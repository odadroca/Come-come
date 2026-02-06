#!/bin/bash
#
# Come-Come VPS Deployment Script
# Ubuntu 24.04 LTS + nginx + PHP 8.3 + SQLite + Certbot
#

set -e  # Exit on error

echo "=========================================="
echo "Come-Come VPS Deployment Script v0.04"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "Error: Please run as root (use sudo)"
    exit 1
fi

# Prompt for configuration
read -p "Enter domain name (e.g., comecome.example.com): " DOMAIN
read -p "Enter admin email for SSL certificate: " EMAIL
read -p "Enter installation directory [/var/www/comecome]: " INSTALL_DIR
INSTALL_DIR=${INSTALL_DIR:-/var/www/comecome}

echo ""
echo "Configuration:"
echo "  Domain: $DOMAIN"
echo "  Email: $EMAIL"
echo "  Install directory: $INSTALL_DIR"
echo ""
read -p "Proceed with installation? (y/n): " CONFIRM

if [ "$CONFIRM" != "y" ]; then
    echo "Installation cancelled."
    exit 0
fi

echo ""
echo "Step 1: Installing dependencies..."
apt update
apt install -y nginx php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-gd \
               certbot python3-certbot-nginx git unzip

echo ""
echo "Step 2: Downloading Come-Come..."
cd /tmp
# In production, replace with actual GitHub release URL
wget -O comecome.zip https://github.com/youruser/comecome/releases/download/v0.04/comecome-v0.04.zip || {
    echo "Error: Failed to download Come-Come"
    echo "Please download manually and extract to $INSTALL_DIR"
    exit 1
}

echo ""
echo "Step 3: Extracting files..."
unzip -q comecome.zip
mkdir -p $INSTALL_DIR
cp -r comecome-v0.04/* $INSTALL_DIR/
chown -R www-data:www-data $INSTALL_DIR

echo ""
echo "Step 4: Setting up permissions..."
chmod 750 $INSTALL_DIR/data
chmod 750 $INSTALL_DIR/config
chmod 640 $INSTALL_DIR/config/config.php

echo ""
echo "Step 5: Configuring nginx..."
cat > /etc/nginx/sites-available/comecome << EOF
server {
    listen 80;
    server_name $DOMAIN;
    
    root $INSTALL_DIR/public;
    index index.php app.html;
    
    # Security headers
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
    
    # Deny access to sensitive directories
    location ~ ^/(config|data|sql|src|docs|deploy|tests) {
        deny all;
        return 404;
    }
    
    # Static assets
    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
    
    # Route all requests through index.php
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    
    # PHP-FPM
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
}
EOF

# Enable site
ln -sf /etc/nginx/sites-available/comecome /etc/nginx/sites-enabled/
nginx -t
systemctl reload nginx

echo ""
echo "Step 6: Installing SSL certificate..."
certbot --nginx -d $DOMAIN --non-interactive --agree-tos -m $EMAIL || {
    echo "Warning: SSL certificate installation failed"
    echo "You can run manually: certbot --nginx -d $DOMAIN"
}

echo ""
echo "Step 7: Setting up cron jobs..."
# Add backup cron (daily at 2 AM)
(crontab -u www-data -l 2>/dev/null; echo "0 2 * * * cp $INSTALL_DIR/data/comecome.db $INSTALL_DIR/data/backups/comecome_\$(date +\\%Y\\%m\\%d).db") | crontab -u www-data -

# Add vacuum cron (weekly, Sunday at 4 AM)
(crontab -u www-data -l 2>/dev/null; echo "0 4 * * 0 sqlite3 $INSTALL_DIR/data/comecome.db 'VACUUM;'") | crontab -u www-data -

echo ""
echo "=========================================="
echo "Installation complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "  1. Visit https://$DOMAIN/install.php"
echo "  2. Create first guardian account"
echo "  3. Change UNLOCK_CODE in $INSTALL_DIR/config/config.php"
echo ""
echo "Backup location: $INSTALL_DIR/data/backups/"
echo "Database location: $INSTALL_DIR/data/comecome.db"
echo ""
echo "To uninstall:"
echo "  rm -rf $INSTALL_DIR"
echo "  rm /etc/nginx/sites-enabled/comecome"
echo "  certbot delete --cert-name $DOMAIN"
echo ""
