# Arena — Gestion de tournois

Application monorepo pour organiser des tournois (poules optionnelles, bracket simple ou double élimination, solo/duo), avec interface d’administration et écran de projection.

## Stack

- Docker Compose (PHP 8.4 / Symfony 7.4, Nginx, PostgreSQL 16, Node/Vite)
- API Symfony + Doctrine
- Frontend Vue 3 (thème sombre)

## Démarrage

```bash
docker compose up -d --build
```

Services :

| Service   | URL                      |
|-----------|--------------------------|
| Admin UI  | http://localhost:5173    |
| API / prod UI | http://localhost:8080 |
| PostgreSQL | `localhost:5432` (interne) |

Identifiants admin par défaut (variables d’environnement) :

- `ADMIN_USER=admin`
- `ADMIN_PASSWORD=admin`

Modifiables dans [`docker-compose.yml`](docker-compose.yml) ou via un fichier `.env` à la racine.

## Utilisation

1. Se connecter sur http://localhost:5173
2. Créer un tournoi (poules oui/non, solo/duo, bracket simple/double)
3. Inscrire les joueurs (ou former des duos par tirage)
4. Lancer le **tirage au sort**, générer les **poules** puis le **bracket**
5. Saisir le vainqueur de chaque match (score optionnel)
6. Bouton **Projeter** pour définir le match en cours
7. Ouvrir l’onglet **Projection** (`/display/:id`) pour l’affichage public (rafraîchi toutes les 3 s)

## Build frontend pour Nginx (port 8080)

```bash
docker compose run --rm frontend sh -c "npm install && npm run build"
```

Le dossier `frontend/dist` est servi par Nginx avec l’API sous `/api`.

## API utile

- `POST /api/login` — `{ "username", "password" }` → token
- Header admin : `X-Admin-Token: <token>`
- `GET /api/public/tournaments/{id}/display` — données projection (public)

## Variables

| Variable | Description |
|----------|-------------|
| `ADMIN_USER` / `ADMIN_PASSWORD` | Compte admin unique |
| `APP_SECRET` | Secret Symfony (token admin dérivé) |
| `DATABASE_URL` | Connexion PostgreSQL |
| `CORS_ALLOW_ORIGIN` | Regex origines autorisées |
