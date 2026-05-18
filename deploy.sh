#!/usr/bin/env bash
set -e

SSH_HOST="u767148652@46.202.172.42"
SSH_PORT="65002"
REMOTE="$SSH_HOST:~/domains/filmoclub.org"
DIR="$(cd "$(dirname "$0")" && pwd)"

SKIP_BUILD=false
SKIP_FRONTEND=false
SKIP_BACKEND=false
WITH_VENDOR=false
RUN_MIGRATE=false

for arg in "$@"; do
  case $arg in
    --no-build)       SKIP_BUILD=true ;;
    --only-frontend)  SKIP_BACKEND=true ;;
    --only-backend)   SKIP_FRONTEND=true; SKIP_BUILD=true ;;
    --with-vendor)    WITH_VENDOR=true ;;
    --migrate)        RUN_MIGRATE=true ;;
    --status)
      echo "--- Estado de producción ---"
      ssh -p "$SSH_PORT" "$SSH_HOST" bash << 'ENDSSH'
        cd ~/domains/filmoclub.org/laravel
        echo "== Migraciones (pendientes/ejecutadas) =="
        php artisan migrate:status
        echo ""
        echo "== PHP version =="
        php -v | head -1
        echo ""
        echo "== Último error en el log de Laravel =="
        # Extrae el último bloque de log (desde el último [20 hasta el final)
        grep -o '\[20[0-9][0-9]-[0-9][0-9]-[0-9][0-9].*' storage/logs/laravel.log 2>/dev/null | tail -5 || \
          tail -30 storage/logs/laravel.log 2>/dev/null || echo "(sin log)"
ENDSSH
      exit 0
      ;;
  esac
done

# ── Frontend ──────────────────────────────────────────────────────────────────
if [ "$SKIP_FRONTEND" = false ]; then
  if [ "$SKIP_BUILD" = false ]; then
    echo "--- Building frontend ---"
    cd "$DIR/frontend"
    npm run build
    cd "$DIR"
  fi

  echo "--- Uploading frontend (public/) ---"
  rsync -avz --delete \
    --exclude='index.php' \
    --exclude='backend-api/' \
    --exclude='storage/' \
    -e "ssh -p $SSH_PORT" \
    "$DIR/public/" \
    "$REMOTE/public_html/"

  echo "--- Uploading backend-api entry point ---"
  scp -P "$SSH_PORT" \
    "$DIR/public/index.php" \
    "$REMOTE/public_html/backend-api/index.php"
fi

# ── Backend ───────────────────────────────────────────────────────────────────
if [ "$SKIP_BACKEND" = false ]; then
  echo "--- Uploading Laravel app ---"

  EXCLUDES=(
    --exclude='.git/'
    --exclude='frontend/'
    --exclude='public/'
    --exclude='node_modules/'
    --exclude='.env'
    --exclude='*.sql'
    --exclude='storage/logs/'
    --exclude='storage/framework/cache/'
    --exclude='storage/framework/sessions/'
    --exclude='storage/framework/views/'
    --exclude='storage/framework/testing/'
    --exclude='bootstrap/cache/'
  )

  if [ "$WITH_VENDOR" = false ]; then
    EXCLUDES+=(--exclude='vendor/')
  fi

  rsync -avz \
    "${EXCLUDES[@]}" \
    -e "ssh -p $SSH_PORT" \
    "$DIR/" \
    "$REMOTE/laravel/"

  echo "--- Running post-deploy commands on server ---"
  ssh -p "$SSH_PORT" "$SSH_HOST" bash << 'ENDSSH'
    set -e
    cd ~/domains/filmoclub.org/laravel
    # Limpiar caché de bootstrap antes de composer para evitar conflictos
    # si el packages.php local y el vendor del servidor están desincronizados
    rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
    composer install --no-dev --optimize-autoloader
    php artisan optimize:clear
ENDSSH

  if [ "$RUN_MIGRATE" = true ]; then
    echo ""
    echo "--- Migraciones pendientes en producción ---"
    ssh -p "$SSH_PORT" "$SSH_HOST" bash << 'ENDSSH'
      cd ~/domains/filmoclub.org/laravel
      php artisan migrate:status
ENDSSH
    echo ""
    read -p "¿Ejecutar php artisan migrate --force? [s/N] " CONFIRM
    if [[ "$CONFIRM" =~ ^[sS]$ ]]; then
      ssh -p "$SSH_PORT" "$SSH_HOST" bash << 'ENDSSH'
        cd ~/domains/filmoclub.org/laravel
        php artisan migrate --force
ENDSSH
      echo "Migraciones ejecutadas."
    else
      echo "Migraciones omitidas."
    fi
  fi
fi

echo ""
echo "Deploy completado."