#!/bin/sh
# Wird nur beim ersten Start mit leerem Volume ausgeführt (offizielles MySQL-Image).
# Legt die Testdatenbank an; der App-Benutzer erhält Rechte nur auf app und app_test.
set -eu
mysql -u root -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_test\`.* TO '${MYSQL_USER}'@'%';
FLUSH PRIVILEGES;
SQL
