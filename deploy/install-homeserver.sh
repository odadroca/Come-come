#!/bin/bash
#
# Come-Come Home Server Deployment Script
# Raspberry Pi / NAS + Caddy + PHP 8.1 + SQLite + DuckDNS
#

set -e  # Exit on error

echo "=========================================="
echo "Come-Come Home Server Setup v0.04"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "Error: Please run as root (use sudo)"
    exit 1
fi

# Prompt for configuration
read -p "Enter domain name (e.g., comecome.duckdns.org): " DOMAIN
read -p "Enter installation directory [/var/www/comecome]: " INSTALL_DIR
INSTALL_DIR=${INSTALL_DIR:-/var/www/comecome}

echo ""
echo "Configuration:"
echo "  Domain: $DOMAIN"
echo "  Install directory: $INSTALL_DIR"
echo ""
read -p "Proceed with installation? (y/n): " CONFIRM

if [ "$CONFIRM" != "y" ]; then
    echo "Installation cancelled."
    exit 0
fi

echo ""
echo "Step 1: Installing Caddy..."
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/setup.deb.sh' | bash
apt install -y caddy

echo ""
echo "Step 2: Installing PHP..."
apt install -y php8.1-fpm php8.1-sqlite3 php8.1-mbstring php8.1-gd unzip

echo ""
echo "Step 3: Downloading Come-Come..."
cd /tmp
# In production, replace with actual download URL
wget -O comecome.zip https://github.com/youruser/comecome/releases/download/v0.04/comecome-v0.04.zip || {
    echo "Error: Failed to download Come-Come"
    echo "Please download manually and extract to $INSTALL_DIR"
    exit 1
}

echo ""
echo "Step 4: Extracting files..."
unzip -q comecome.zip
mkdir -p $INSTALL_DIR
cp -r comecome-v0.04/* $INSTALL_DIR/
chown -R www-data:www-data $INSTALL_DIR

echo ""
echo "Step 5: Setting up permissions..."
chmod 750 $INSTALL_DIR/data
chmod 750 $INSTALL_DIR/config
chmod 640 $INSTALL_DIR/config/config.php

echo ""
echo "Step 6: Configuring Caddy..."
cat > /etc/caddy/Caddyfile << EOF
$DOMAIN {
    root * $INSTALL_DIR/public
    php_fastcgi unix//run/php/php8.1-fpm.sock
    file_server
    
    # Deny access to sensitive directories
    @notpublic {
        path /config/* /data/* /sql/* /src/* /docs/* /deploy/* /tests/*
    }
    respond @notpublic 404
    
    # Security headers
    header {
        X-Content-Type-Options "nosniff"
        X-Frame-Options "SAMEORIGIN"
        X-XSS-Protection "1; mode=block"
    }
}
EOF

systemctl restart caddy

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
echo "  1. Set up DuckDNS (if using dynamic IP):"
echo "     Visit https://www.duckdns.org"
echo "     Add cron: */5 * * * * curl 'https://www.duckdns.org/update?domains=YOURDOMAIN&token=YOURTOKEN'"
echo ""
echo "  2. Configure router port forwarding:"
echo "     Forward port 443 -> $(hostname -I | awk '{print $1}'):443"
echo ""
echo "  3. Visit https://$DOMAIN/install.php"
echo "     Create first guardian account"
echo ""
echo "  4. Change UNLOCK_CODE in $INSTALL_DIR/config/config.php"
echo ""
echo "Backup location: $INSTALL_DIR/data/backups/"
echo "Database location: $INSTALL_DIR/data/comecome.db"
echo ""
