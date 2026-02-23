#!/bin/bash
set -euo pipefail

# ==============================
# Instalador completo Enginex con TLS autofirmado
# ==============================
echo "===== Iniciando instalación de Enginex ====="

# ------------------------------
# 1. Instalar dependencias
# ------------------------------
echo "Instalando dependencias básicas..."
sudo apt update && apt upgrade -y
sudo apt install -y software-properties-common lsb-release apt-transport-https ca-certificates curl unzip openssl

sudo add-apt-repository ppa:ondrej/php -y
sudo add-apt-repository ppa:ondrej/nginx -y
sudo apt update

sudo apt install -y nginx mariadb-server php8.4 php8.4-cli php8.4-common php8.4-fpm \
php8.4-mysql php8.4-curl php8.4-mbstring php8.4-xml php8.4-zip php8.4-bcmath php8.4-gd php8.4-sqlite3

# ------------------------------
# 2. Configurar sudoers para nginx-dash
# ------------------------------
echo "Configurando sudoers para NGINX Dashboard..."
cat <<EOF | sudo tee /etc/sudoers.d/nginx-dash
# Permisos para el panel NGINX Dashboard
# SIEMPRE ACTIVO
www-data ALL=(ALL) NOPASSWD: /bin/sed, /usr/bin/sed
www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t, /usr/sbin/nginx -s reload
# ===== BLOQUE TEMPORAL ===== 
# TEMPORALES - DESHABILITADOS
# www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx *, /bin/ln, /bin/rm, /bin/mv, /usr/bin/systemctl *, /usr/bin/cp
# ===== FIN BLOQUE TEMPORAL =====
EOF
sudo chmod 440 /etc/sudoers.d/nginx-dash

# ------------------------------
# 3. Crear certificados TLS autofirmados
# ------------------------------
SSL_DIR="/etc/nginx/ssl"
sudo mkdir -p "$SSL_DIR"

if [ ! -f "$SSL_DIR/server.crt" ] || [ ! -f "$SSL_DIR/server.key" ]; then
    echo "Generando certificados TLS autofirmados..."
    sudo openssl req -x509 -nodes -days 365 \
        -newkey rsa:2048 \
        -keyout "$SSL_DIR/server.key" \
        -out "$SSL_DIR/server.crt" \
        -subj "/C=MX/ST=CDMX/L=CDMX/O=Enginex/OU=Dev/CN=localhost"
else
    echo "Certificados TLS ya existentes, se usarán los actuales."
fi

# ------------------------------
# 4. Configurar Nginx
# ------------------------------
echo "Eliminando sitio default de Nginx..."
sudo rm -f /etc/nginx/sites-enabled/default

echo "Creando sitio Enginex..."
sudo mkdir -p /etc/nginx/sites-available
sudo tee /etc/nginx/sites-available/enginex > /dev/null <<'NGINX_CONF'
server {
    listen 443 ssl;
    server_name _;
    server_tokens off;
    autoindex off;

    # SSL Configuration
    ssl_certificate     /etc/nginx/ssl/server.crt;
    ssl_certificate_key /etc/nginx/ssl/server.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         'TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-AES128-SHA256:ECDHE-RSA-AES256-SHA384';
    ssl_prefer_server_ciphers on;

    # HSTS (Strict Transport Security)
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # Root and index settings
    root /var/www/html/enginex;
    index index.php index.html;

    # Client max body size
    client_max_body_size 20M;

    # Main location
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP handling
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Deny access to sensitive files (e.g., .htaccess, .git, .env)
    location ~ /\.(ht|git|env) {
        deny all;
    }

    # Deny access to hidden files (files starting with a dot)
    location ~ ^/\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    # Restrict HTTP methods to avoid unsafe ones (like DELETE, TRACE)
    if ($request_method !~ ^(GET|POST|HEAD)$) {
        return 405;
    }

    # Security headers to protect against XSS, clickjacking, etc.
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-XSS-Protection "1; mode=block" always;
#    add_header Content-Security-Policy "default-src 'self';" always;

    # Static files caching for improved performance
    location ~* \.(jpg|jpeg|png|gif|css|js|woff|woff2|ttf|svg|eot)$ {
        expires 365d;
        access_log off;
    }

    # Gzip compression for performance
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css application/javascript application/json image/svg+xml;
}
NGINX_CONF

sudo ln -sf /etc/nginx/sites-available/enginex /etc/nginx/sites-enabled/enginex

# ------------------------------
# 5. Copiar carpeta www
# ------------------------------
echo "Copiando carpeta www..."
sudo cp -r ./www /var/
sudo chown -R www-data:www-data /var/www/html/enginex || true
sudo chmod -R 755 /var/www/html/enginex || true

# ------------------------------
# 6. Crear base de datos y usuario seguro
# ------------------------------
echo "===== Configuración base de datos ====="
read -s -p "Ingrese la contraseña root de MariaDB: " ROOT_PASS
echo

DB_NAME="usuarios"
DB_USER="mysupersu"
DB_HOST="localhost"
DB_PASS=$(openssl rand -base64 24 | tr -d "=+/")

ROOT_CNF=$(mktemp)
chmod 600 "$ROOT_CNF"
cat > "$ROOT_CNF" <<EOF
[client]
user=root
password=$ROOT_PASS
host=localhost
EOF

mysql --defaults-extra-file="$ROOT_CNF" <<SQL
SET GLOBAL sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE DATABASE IF NOT EXISTS $DB_NAME
CHARACTER SET utf8mb4
COLLATE utf8mb4_general_ci;

CREATE USER IF NOT EXISTS '$DB_USER'@'$DB_HOST'
IDENTIFIED BY '$DB_PASS';

GRANT SELECT, INSERT, UPDATE, DELETE
ON $DB_NAME.*
TO '$DB_USER'@'$DB_HOST';

FLUSH PRIVILEGES;

USE $DB_NAME;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  failed_attempts INT(11) DEFAULT 0,
  locked_until DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS actividades (
  id INT(11) NOT NULL AUTO_INCREMENT,
  tipo VARCHAR(50) NOT NULL,
  descripcion TEXT NOT NULL,
  usuario_id INT(11) NOT NULL,
  fecha TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY usuario_id (usuario_id),
  CONSTRAINT actividades_ibfk_1
    FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tokens_autenticacion (
  id INT(11) NOT NULL AUTO_INCREMENT,
  usuario_id INT(11) NOT NULL,
  token VARCHAR(255) NOT NULL,
  expiracion DATETIME NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY usuario_id (usuario_id),
  CONSTRAINT tokens_autenticacion_ibfk_1
    FOREIGN KEY (usuario_id)
    REFERENCES usuarios (id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
SQL

rm -f "$ROOT_CNF"

# ------------------------------
# 7. Crear usuario inicial
# ------------------------------
read -p "Ingrese username inicial: " USERNAME
read -s -p "Ingrese contraseña inicial: " PASSWORD
echo

HASHED_PASSWORD=$(php -r "echo password_hash('$PASSWORD', PASSWORD_BCRYPT);")

APP_CNF=$(mktemp)
chmod 600 "$APP_CNF"
cat > "$APP_CNF" <<EOF
[client]
user=$DB_USER
password=$DB_PASS
host=$DB_HOST
database=$DB_NAME
EOF

mysql --defaults-extra-file="$APP_CNF" <<SQL
INSERT INTO usuarios (username, password)
VALUES ('$USERNAME', '$HASHED_PASSWORD');
SQL

rm -f "$APP_CNF"

# ------------------------------
# 8. Crear .env
# ------------------------------
ENV_FILE="/var/www/html/enginex/.env"
cat > "$ENV_FILE" <<EOF
DB_HOST=$DB_HOST
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
EOF
sudo chown www-data:www-data "$ENV_FILE"
sudo chmod 600 "$ENV_FILE"

# ------------------------------
# 9. Permisos panel
# ------------------------------
sudo chown -R www-data:www-data /var/www/panel || true
sudo chmod -R 755 /var/www/panel || true

# ------------------------------
# 10. Reiniciar servicios
# ------------------------------
sudo systemctl restart php8.4-fpm
sudo systemctl restart nginx

# ------------------------------
# 11. Mostrar credenciales finales
# ------------------------------
echo
echo "========================================"
echo "Instalación completada correctamente!"
echo
echo "== Base de datos =="
echo "Usuario DB: $DB_USER"
echo "Contraseña DB: $DB_PASS"
echo "Nombre DB: $DB_NAME"
echo "Host DB: $DB_HOST"
echo
echo "== Usuario inicial de la app =="
echo "Username: $USERNAME"
echo "Password: $PASSWORD"
echo "========================================"
