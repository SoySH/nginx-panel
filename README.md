# 🛡️ Nginx Secure Dash

![Bash](https://img.shields.io/badge/Installer-Bash-121011?style=for-the-badge&logo=gnubash)
![PHP](https://img.shields.io/badge/Backend-PHP-777BB4?style=for-the-badge&logo=php)
![Nginx](https://img.shields.io/badge/Server-Nginx-009639?style=for-the-badge&logo=nginx)
![Security](https://img.shields.io/badge/Security-High-red?style=for-the-badge)
![Internal Use](https://img.shields.io/badge/Recommended-Internal_Use-orange?style=for-the-badge)

Panel web para la gestión **segura** de archivos de configuración de Nginx, diseñado con múltiples capas de protección desde el inicio de sesión hasta la habilitación temporal de privilegios `sudo`.

> ⚠️ Proyecto recomendado para uso interno (VPN / túnel / red privada).

---

# 🚀 Características

- 🔐 Sistema de autenticación con control de intentos
- 📲 Validación en dos pasos mediante Telegram
- 🛡️ Habilitación temporal de privilegios `sudo`
- ⏳ Expiración automática de sesión
- 🔑 Generación automática de contraseña robusta en `.env`
- ⚙️ Instalación semi-automática con script
- 📂 Gestión controlada de archivos `.conf` de Nginx

---

# 🔐 Arquitectura de Seguridad

El sistema implementa múltiples capas de protección:

---

## 1️⃣ Protección de Login

Archivo: `home.php`

- Máximo **5 intentos fallidos** de inicio de sesión.
- Si se supera el límite:
  - 🔒 Se bloquea **solo el usuario afectado**
  - ⏱ Bloqueo de **30 minutos**
  - El estado se almacena en la base de datos
- No afecta a otros usuarios.

---

## 2️⃣ Validación mediante Telegram (2FA)

Antes de habilitar la edición de archivos:

- Se genera un código temporal.
- Se envía al bot configurado en:
  
/var/www/panel/telegram.php


- El archivo **no está expuesto públicamente**.
- El usuario debe validar el código para continuar.

---

## 3️⃣ Habilitación Temporal de `sudo`

Una vez validado el código:

- Se habilita acceso a:

/etc/sudoers.d/nginx-dash


- El acceso tiene un **tiempo de vida de 10 minutos**.
- Permite edición controlada de archivos `.conf`.
- Tras 10 minutos:
- ❌ Se revocan los privilegios automáticamente.
- 🔒 Se vuelve a bloquear `sudoers`.

---

## 4️⃣ Expiración de Sesión

- La sesión del panel expira automáticamente tras **10 minutos**.
- Si expira:
- Se requiere nuevo login.
- Se requiere nueva validación por Telegram.
- Se vuelve a aplicar el ciclo de habilitación temporal.

---

# ⚙️ Instalación

El proyecto incluye:

- `instalador-enginex.sh`
- Carpeta `www/` con todo el sistema

## Instalación

```bash
chmod +x instalador-enginex.sh
sudo ./instalador-enginex.sh

## Durante la primera instalación:

🗝 Se genera automáticamente el archivo .env

🔐 La contraseña de la base de datos:

Es generada aleatoriamente

No es corta

No es predecible

No existen credenciales hardcodeadas

## 🤖 Configuración de Telegram

Se debe configurar manualmente:

/var/www/panel/telegram.php

Este archivo:

Contiene el token del bot

No está expuesto públicamente

Debe editarse antes del primer uso

## 🌐 Recomendaciones de Uso

Este panel gestiona archivos críticos de Nginx.

Aunque el sistema es robusto, se recomienda:

🔒 Usarlo solo en red interna

🛡️ Acceso mediante VPN

🚫 No exponer directamente a Internet

🧠 Flujo de Seguridad Resumido

Login (máx.intentos)

Validación Telegram

Habilitación temporal de sudo (10 min)

Edición controlada

Expiración automática

Revocación de privilegios

## ⚠️ Advertencia

Este panel manipula configuraciones críticas del servidor.

Está diseñado como:

Herramienta administrativa privada

Utilidad interna

Panel de uso propio

No se recomienda como solución multiusuario pública ni como panel expuesto a internet.

## 📜 Licencia

Uso interno / privado.
Distribución bajo responsabilidad del administrador.

🔥 Diseñado para minimizar superficie de ataque
🛡️ Seguridad por tiempo limitado
⚙️ Control granular de privilegios

