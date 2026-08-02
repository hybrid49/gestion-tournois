#!/bin/sh
set -e

cd /var/www/backend

# Symfony exige un fichier .env (même si Docker injecte déjà les variables)
if [ ! -f .env ]; then
  cat > .env <<'EOF'
APP_ENV=dev
APP_SECRET=change_me_in_production_please_32chars
DATABASE_URL="postgresql://tournois:tournois@postgres:5432/tournois?serverVersion=16&charset=utf8"
ADMIN_USER=admin
ADMIN_PASSWORD=admin
CORS_ALLOW_ORIGIN='^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$'
EOF
fi

if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

mkdir -p var/cache var/log
chmod -R 777 var || true

# Wait for DB then migrate
php -r '
$ok = false;
for ($i = 0; $i < 30; $i++) {
  try {
    $dsn = getenv("DATABASE_URL");
    if (!$dsn) { sleep(1); continue; }
    // parse postgres url roughly
    $parts = parse_url(str_replace("postgresql://", "postgres://", $dsn));
    $host = $parts["host"] ?? "postgres";
    $port = $parts["port"] ?? 5432;
    $user = $parts["user"] ?? "tournois";
    $pass = $parts["pass"] ?? "tournois";
    $db = ltrim($parts["path"] ?? "/tournois", "/");
    $db = explode("?", $db)[0];
    new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);
    $ok = true;
    break;
  } catch (Throwable $e) {
    sleep(1);
  }
}
exit($ok ? 0 : 1);
' || true

php bin/console doctrine:migrations:migrate --no-interaction || true

exec docker-php-entrypoint "$@"
