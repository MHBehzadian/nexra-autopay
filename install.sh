#!/bin/bash
set -e

# ============================================================================
# Nexra Mirza Bot installer - clones straight from this repo (already has
# the Nexra integration + username-choice customizations baked in, no
# overlay/patch steps needed).
#
# Usage:
#   N=7 TOKEN='123:abc' DOMAIN='botN.example.com' ADMIN='123456789' \
#   CERT_EMAIL='you@example.com' bash install.sh
# ============================================================================

REPO_URL="https://github.com/MHBehzadian/nexra-autopay.git"

N="${N:?set N= (bot number, e.g. 7)}"
TOKEN="${TOKEN:?set TOKEN= (telegram bot token from @BotFather)}"
DOMAIN="${DOMAIN:?set DOMAIN= (the domain of this bot, A record must already point here)}"
ADMIN="${ADMIN:?set ADMIN= (your numeric telegram id)}"
DBPASS="${DBPASS:-$(openssl rand -hex 8)}"
NEXRA_SECRET="${NEXRA_SECRET:?set NEXRA_SECRET= (the secret code that gates panel management)}"
CERT_EMAIL="${CERT_EMAIL:?set CERT_EMAIL= (email for the SSL certificate)}"
BACKUP_MINUTE="${BACKUP_MINUTE:-$((RANDOM % 60))}"

DBNAME="mirzabot${N}"
DBUSER="mirza${N}user"
BOTDIR="/var/www/html/botmirzapanel${N}"

echo ">>> checking DNS for $DOMAIN"
getent hosts "$DOMAIN" || { echo "DNS not set for $DOMAIN yet. Point the A record here first."; exit 1; }

echo ">>> cloning $REPO_URL"
rm -rf "$BOTDIR"
git clone --depth 1 "$REPO_URL" "$BOTDIR"
rm -rf "$BOTDIR/.git"
chown -R www-data:www-data "$BOTDIR"

echo ">>> creating database $DBNAME"
mysql <<EOF
CREATE DATABASE IF NOT EXISTS $DBNAME CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '$DBUSER'@'localhost' IDENTIFIED BY '$DBPASS';
GRANT ALL PRIVILEGES ON $DBNAME.* TO '$DBUSER'@'localhost';
FLUSH PRIVILEGES;
EOF

echo ">>> resolving bot username from token"
BOT_USER=$(curl -s "https://api.telegram.org/bot$TOKEN/getMe" | grep -oP '"username":"\K[^"]+')
if [ -z "$BOT_USER" ]; then
    echo "Could not resolve bot username - check the token."
    exit 1
fi
echo "bot username: $BOT_USER"

sed -i "s|{DATABASE_NAME}|$DBNAME|"        "$BOTDIR/config.php"
sed -i "s|{DATABASE_USERNAME}|$DBUSER|"    "$BOTDIR/config.php"
sed -i "s|{DATABASE_PASSOWRD}|$DBPASS|"    "$BOTDIR/config.php"
sed -i "s|{DOMAIN.COM/PATH/BOT}|$DOMAIN|"  "$BOTDIR/config.php"
sed -i "s|{BOT_TOKEN}|$TOKEN|"             "$BOTDIR/config.php"
sed -i "s|{BOT_USERNAME}|$BOT_USER|"       "$BOTDIR/config.php"
sed -i "s|{ADMIN_#ID}|$ADMIN|"             "$BOTDIR/config.php"
sed -i "s|{NEXRA_SECRET}|$NEXRA_SECRET|"   "$BOTDIR/config.php"
chown www-data:www-data "$BOTDIR/config.php"

echo ">>> nginx vhost (php8.1-fpm)"
cat > "/etc/nginx/sites-available/bot${N}" <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $BOTDIR;
    index index.php;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
    }
    location ~ /\.ht { deny all; }
}
EOF
ln -sf "/etc/nginx/sites-available/bot${N}" "/etc/nginx/sites-enabled/bot${N}"
nginx -t && systemctl reload nginx

echo ">>> SSL"
certbot --nginx -d "$DOMAIN" --agree-tos --redirect --no-eff-email -m "$CERT_EMAIL"

echo ">>> creating tables (includes the Nexra dual-credential columns)"
curl -s "https://$DOMAIN/table.php" > /dev/null
echo "  tables: $(mysql "$DBNAME" -e 'SHOW TABLES;' | wc -l)"

echo ">>> setting webhook"
curl -s "https://api.telegram.org/bot$TOKEN/setWebhook?url=https://$DOMAIN/index.php" > /dev/null
curl -s "https://api.telegram.org/bot$TOKEN/getWebhookInfo"; echo

echo ">>> setting up automatic hourly backup"
cat > "/root/bot${N}_backup.sh" <<EOF
#!/bin/bash
FILE="/root/mirzabot${N}_\$(date +%Y%m%d_%H%M%S).sql"
mysqldump -u $DBUSER -p'$DBPASS' $DBNAME > "\$FILE"
curl -s -F chat_id="$ADMIN" -F document=@"\$FILE" -F caption="بکاپ خودکار $BOT_USER 🗄" \\
  "https://api.telegram.org/bot$TOKEN/sendDocument" >/dev/null
rm -f "\$FILE"
EOF
chmod +x "/root/bot${N}_backup.sh"
(crontab -l 2>/dev/null | grep -v "bot${N}_backup"; echo "$BACKUP_MINUTE * * * * bash /root/bot${N}_backup.sh") | crontab -

echo ""
echo "=========================================="
echo "done."
echo "bot:      $BOT_USER"
echo "url:      https://$DOMAIN"
echo "database: $DBNAME / $DBUSER / $DBPASS"
echo "backup:   every hour at minute $BACKUP_MINUTE, sent to admin $ADMIN"
echo "=========================================="
