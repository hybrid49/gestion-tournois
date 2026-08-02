# Gestion de tournois — La Routine

Application pour organiser des tournois (poules optionnelles, bracket simple ou double élimination, solo/duo), avec interface d’administration, gestion mobile et écran de projection.

## Stack

- Docker Compose (PHP 8.4 / Symfony 7.4, Nginx, PostgreSQL 16, Node)
- API Symfony + Doctrine
- Frontend Vue 3

| Service | Port (hôte) | Rôle |
|---------|-------------|------|
| Nginx | `8080` | API + UI buildée (`frontend/dist`) |
| Frontend Vite | `5173` | Dev hot-reload (optionnel en prod) |
| PostgreSQL | interne | Base de données |

---

## Installation VPS Debian (depuis zéro)

Guide pour un serveur Debian 12 (Bookworm) ou 13, avec Docker.  
Exemple : domaine `tournois.example.com` pointant vers l’IP du VPS.

### 1. Prérequis système

Connectez-vous en SSH :

```bash
ssh root@VOTRE_IP
```

Mise à jour et paquets de base :

```bash
apt update && apt upgrade -y
apt install -y ca-certificates curl git ufw
```

Créez un utilisateur non-root (recommandé) :

```bash
adduser deploy
usermod -aG sudo deploy
# puis reconnectez-vous en tant que deploy
```

### 2. Installer Docker (Engine + Compose)

```bash
# Dépôt officiel Docker
sudo apt install -y apt-transport-https gnupg
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/debian/gpg \
  | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo usermod -aG docker "$USER"
```

Déconnectez-vous / reconnectez-vous pour que le groupe `docker` soit pris en compte, puis vérifiez :

```bash
docker --version
docker compose version
```

### 3. Pare-feu

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
# Optionnel : accès direct à l’app sans reverse-proxy
# sudo ufw allow 8080/tcp
sudo ufw enable
sudo ufw status
```

### 4. Cloner le projet

```bash
cd /opt   # ou ~/apps
sudo mkdir -p /opt/gestion-tournois
sudo chown "$USER:$USER" /opt/gestion-tournois
git clone https://github.com/hybrid49/gestion-tournois.git /opt/gestion-tournois
cd /opt/gestion-tournois
```

### 5. Configurer les secrets (production)

Éditez `docker-compose.yml` (ou adaptez les variables d’environnement du service `php`) :

| Variable | À faire |
|----------|---------|
| `ADMIN_USER` / `ADMIN_PASSWORD` | **Changer** (compte admin unique) |
| `APP_SECRET` | Chaîne longue aléatoire (≥ 32 caractères) |
| `POSTGRES_PASSWORD` | Mot de passe fort (aligné dans `DATABASE_URL`) |
| `CORS_ALLOW_ORIGIN` | Autoriser votre domaine (voir ci-dessous) |
| `APP_ENV` | Mettre `prod` en production |

Générer un secret :

```bash
openssl rand -hex 32
```

Exemple de CORS pour `https://tournois.example.com` :

```text
CORS_ALLOW_ORIGIN: '^https://tournois\.example\.com$'
```

En développement local uniquement :

```text
CORS_ALLOW_ORIGIN: '^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$'
```

> Les fichiers `backend/.env` ne sont **pas** versionnés. Les variables du conteneur `php` dans `docker-compose.yml` suffisent pour Docker.

### 6. Build frontend + démarrage

Sur le VPS, on sert surtout le build Nginx (port 8080). Pas besoin de laisser Vite (5173) ouvert en prod.

```bash
cd /opt/gestion-tournois

# Build de l’UI
docker compose run --rm frontend sh -c "npm install && npm run build"

# Lancer Postgres + PHP + Nginx (sans le service de dev frontend)
docker compose up -d --build postgres php nginx
```

Vérifications :

```bash
docker compose ps
curl -sS -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/
curl -sS http://127.0.0.1:8080/api/public/tournaments
```

L’entrypoint PHP lance automatiquement `composer install` (si besoin) et les migrations Doctrine.

### 7. HTTPS avec Caddy (recommandé)

Caddy obtient et renouvelle les certificats Let’s Encrypt automatiquement.

```bash
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
  | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
  | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update
sudo apt install -y caddy
```

Créez `/etc/caddy/Caddyfile` :

```caddy
tournois.example.com {
	encode gzip
	reverse_proxy 127.0.0.1:8080
}
```

```bash
sudo systemctl enable --now caddy
sudo systemctl reload caddy
```

DNS : un enregistrement **A** (ou AAAA) `tournois.example.com` → IP du VPS.

Accès :

- Admin / app : `https://tournois.example.com`
- Projection : `https://tournois.example.com/display/{id}`
- Gestion mobile : `https://tournois.example.com/manage/{id}`

### 8. Première connexion

1. Ouvrir l’URL HTTPS
2. Se connecter avec `ADMIN_USER` / `ADMIN_PASSWORD`
3. Créer un tournoi, inscrire les joueurs, tirage → poules → bracket
4. Saisir les résultats (ou via **Téléphone** / `/manage/{id}`)
5. Ouvrir la **Projection** sur un écran dédié

---

## Mises à jour

```bash
cd /opt/gestion-tournois
git pull
docker compose run --rm frontend sh -c "npm install && npm run build"
docker compose up -d --build postgres php nginx
```

Les migrations s’appliquent au redémarrage du conteneur PHP.

Sauvegarde base :

```bash
docker compose exec -T postgres \
  pg_dump -U tournois tournois > backup-$(date +%F).sql
```

Restauration :

```bash
cat backup-YYYY-MM-DD.sql | docker compose exec -T postgres \
  psql -U tournois -d tournois
```

---

## Développement local

```bash
git clone https://github.com/hybrid49/gestion-tournois.git
cd gestion-tournois
docker compose up -d --build
docker compose run --rm frontend sh -c "npm install && npm run build"
```

| URL | Usage |
|-----|--------|
| http://localhost:5173 | Admin (Vite, hot-reload) |
| http://localhost:8080 | API + UI buildée |
| Identifiants défaut | `admin` / `admin` |

---

## Utilisation rapide

1. Connexion admin
2. Créer un tournoi (poules oui/non, solo/duo, bracket simple/double)
3. Inscrire les joueurs (autocomplete + stats conservées)
4. Tirage au sort → générer poules → générer bracket
5. Saisir le vainqueur (score optionnel) ; corriger un match tant que les équipes n’ont pas rejoué
6. **Projection** pour l’écran public ; **Téléphone** pour saisir depuis le mobile

---

## API utile

- `POST /api/login` — `{ "username", "password" }` → token
- Header admin : `X-Admin-Token: <token>`
- `GET /api/public/tournaments/{id}/display` — données projection (public)

---

## Dépannage

| Problème | Piste |
|----------|--------|
| Page blanche / 404 assets | Relancer le build frontend (`npm run build`) |
| API 500 au démarrage | `docker compose logs -f php` (DB / migrations) |
| CORS bloqué | Ajuster `CORS_ALLOW_ORIGIN` au domaine HTTPS |
| Login refusé | Vérifier `ADMIN_*` et `APP_SECRET` du service `php` |
| Certificat Caddy | DNS propagé ? ports 80/443 ouverts ? |

Logs :

```bash
docker compose logs -f nginx php postgres
```

Arrêt / redémarrage :

```bash
docker compose stop
docker compose start
# ou
docker compose down    # garde le volume postgres_data
```
