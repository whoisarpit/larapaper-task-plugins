# Google Tasks for LaraPaper

A read-only Google Tasks feed and LaraPaper recipe. It shows open tasks,
subtasks, and tasks completed in the last 24 hours. Credentials stay in the
connector; LaraPaper receives only the task feed.

## Set up Google

1. In a Google Cloud project, enable the Google Tasks API and configure an
   OAuth consent screen. Add your Google account as a test user if the app is
   in testing mode.
2. Create a **Web application** OAuth client with the redirect URI
   `http://localhost:8081/callback`.
3. Copy `.env.example` to `.env`, fill in the client ID and secret, and run
   `chmod 600 .env`.
4. Make sure the LaraPaper Docker network exists. The example uses
   `larapaper_default`; set `LARAPAPER_NETWORK` when yours differs.
5. Run `docker compose -f compose.example.yml up -d --build`.
6. Open `http://localhost:8081/connect` on the Docker host and authorize
   access. The connector requests only `tasks.readonly`. If connecting from
   another computer, use an SSH port forward for `localhost:8081`.
7. Open `http://localhost:8081/lists` to find a list ID if needed.

The connector runs at `http://google-tasks:8080/tasks` inside the LaraPaper
network. The browser-facing OAuth page is bound to localhost. Protect the
OAuth page with authentication if you choose to expose it elsewhere. Tokens
are stored in a private Docker volume.

## Install the recipe

Run `./scripts/package-recipe.sh` and import the ZIP from `dist/` into
LaraPaper. Set **Connector Feed URL** to
`http://google-tasks:8080/tasks` and optionally set **Google Tasks list ID**.

The recipe polls every 15 minutes. `recipe/settings.yml` and
`recipe/full.liquid` can be customized before packaging.

The feed also exposes `/health`. `/disconnect` clears its token store.
