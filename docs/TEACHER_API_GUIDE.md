# Teacher API handoff

The complete API is restored in this checkout. Import Teacher-Postman.postman_collection.json into Postman. It covers authentication, preferences, discovery, trips, photos and statistics, with automatic token capture.

Base URL: http://127.0.0.1:8000/api

Use Accept: application/json. Register or log in to obtain a token for protected requests. Public traveler/trip searches, filter options and stats do not require a token.

See [DISCOVERY_API.md](DISCOVERY_API.md) for every endpoint, request body and response. Run php artisan route:list --path=api to verify the current routes.

The missing-route problem came from checking out older main (ba0b8cd) instead of master (f47b9da), which contained the full implementation. Do not replace these files with the older branch.
