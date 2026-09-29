# TravelMate discovery, trips, photos, and statistics API

This implementation lives entirely in the **Laravel `travelmate-finder` repository**. It does not edit the separate Vue `travelmate` repository. The APIs are ready for those views to consume; navigation, file inputs, button handlers, and reactive state still need to be connected in Vue.

## Packages and approach

- Existing **Laravel 12**: routing, validation, Eloquent queries, pagination, file storage, JSON Resources.
- Existing **Sanctum 4**: Bearer-token authentication for account changes and trip creation/update.
- Existing **Carbon** (Laravel dependency): dates, age boundaries, inclusive trip duration.
- Existing **Pest 3**: feature tests with an isolated in-memory SQLite database and fake file storage.
- **No new Composer or npm package installed.** No external dummy-user service. Demo travelers are ordinary local database records served by the same API as real users.

## Run locally

```powershell
cd D:\Internship\travelmate-finder
php artisan migrate
php artisan db:seed --class=DiscoveryDemoSeeder
php artisan serve --host=127.0.0.1 --port=8000
```

Base URL: `http://127.0.0.1:8000/api`.

The demo seeder creates 12 named demo users and 12 trips across 6 destinations. It can be rerun without duplicates, only runs in local/testing, and does not overwrite real accounts. Dates are relative to the day it runs. Demo accounts have random passwords; register your own account to test authenticated operations. It is not added to the default production seeder.

Photos are streamed through an API route. **No `storage:link` or external image-hosting package is required.** On deployment, use the real API host in requests and configure Laravel's trusted proxy/URL settings for that host.

All JSON requests should send `Accept: application/json`. Protected endpoints additionally require `Authorization: Bearer YOUR_TOKEN`. JSON writes require `Content-Type: application/json`; file uploads use multipart form data instead.

## Endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/travelers` | Public | All eligible users, optionally filtered and paginated |
| GET | `/travelers/{id}` | Public | Public profile for a selected traveler |
| GET | `/travelers/{id}/photo` | Public | Current profile image, or 404 if absent |
| GET | `/trips` | Public | Open trips, optionally filtered and paginated |
| GET | `/trips/{id}` | Public/owner | Open trip details; non-open trips visible only to their owner with token |
| POST | `/trips` | Required | Create a trip for the signed-in user |
| PATCH | `/trips/{id}` | Owner | Edit trip fields or change its status |
| GET | `/user/trips` | Required | Your trips in every status, paginated |
| GET | `/stats` | Public | Live traveler, open-trip, and destination counts |
| GET | `/filter-options` | Public | Destination/style values and numeric range options for dropdowns |
| GET | `/user` | Required | Full signed-in account, preferences, and photo URL |
| PATCH | `/user` | Required | Existing profile text edit: name, bio, date_of_birth, gender |
| POST | `/user/photo` | Required | Upload/replace the signed-in user's picture |
| DELETE | `/user/photo` | Required | Remove the signed-in user's picture |
| PUT | `/travel-preferences` | Required | Existing six answers plus optional structured search fields |
| POST | `/register`, `/login`, `/logout` | Existing rules | Existing authentication APIs remain available |

Public traveler/organizer responses use an explicit field allowlist. They do not return emails, exact dates of birth, passwords, tokens, or account roles. Blocked users and admin accounts are excluded from discovery. Their trips are excluded as well. Users without preferences still appear in unfiltered results. Travelers includes the current user when they are eligible.

## 1. Show everyone first; filter only on Search/Apply

Opening the traveler list page:

```http
GET /api/travelers
```

This returns **all eligible travelers across pages**, not every record in one large response. Default page size is 12, maximum 50.

After the user clicks Search/Apply:

```http
GET /api/travelers?destination=Sylhet&travel_style=Adventure&min_age=25&max_age=34&page=1
```

Additional example (URL-encode values using Axios `params`, Postman Params, or URLSearchParams):

```http
GET /api/travelers?destination=Sylhet&date=2027-01-02&min_duration=3&max_duration=4&min_budget=5000&max_budget=10000
```

The example date is illustrative: use a date present in a user's saved availability or in the current demo data.

Supported query parameters:

| Parameter | Meaning |
|---|---|
| `destination` | Exact destination from filter-options; use its original spelling/capitalization |
| `date` | `YYYY-MM-DD`; falls within the saved travel_start/travel_end interval, inclusive |
| `travel_style` | Exact style from filter-options |
| `min_age`, `max_age` | Inclusive current age limits; travelers only |
| `min_budget`, `max_budget` | BDT; inclusive overlap with a traveler's saved budget range |
| `min_duration`, `max_duration` | Inclusive day limits on saved duration_days |
| `page` | Page number starting at 1 |
| `per_page` | 1–50, default 12 |

All supplied filters combine with **AND**. Missing/empty filters are ignored. Missing availability/budget/duration does not match a corresponding numeric/date filter. No matches gives HTTP 200 with `data: []` and `meta.total: 0`.

Do not send `Any age`, `Any budget`, or `Anywhere` as actual values. Omit their params. `/filter-options` returns ready-to-use `params` for every range, including `{}` for Any. For example, `18–24` maps to `{min_age:18,max_age:24}`.

Paginated response shape:

```json
{
  "data": [
    {
      "id": 1,
      "name": "Amina Demo",
      "bio": "...",
      "profile_photo_url": null,
      "age": 20,
      "verification_status": "unverified",
      "preferences": {
        "destinations": ["Sylhet"],
        "travel_style": "Adventure",
        "interests": ["Photography"],
        "min_budget": "5000.00",
        "max_budget": "10000.00",
        "travel_start": "2027-01-01",
        "travel_end": "2027-01-04",
        "duration_days": 4,
        "answers": {"destination":"Sylhet", "date":"Next month", "budget":"BDT 5000–10000", "style":"Adventure", "companions":"Friends", "interests":"Photography"}
      }
    }
  ],
  "links": {"first":"...", "last":"...", "prev":null, "next":null},
  "meta": {"current_page":1, "last_page":1, "per_page":12, "total":1}
}
```

The actual `meta` also includes Laravel's standard pagination links/from/to/path fields. Detail responses use `{ "data": {...} }`.

Suggested Vue list logic, using your existing Axios instance:

```js
const travelers = ref([])
const pagination = ref(null)
const loading = ref(false)
const error = ref('')
const draftFilters = reactive({ destination: '', travel_style: '' })
const appliedFilters = ref({})
let requestNumber = 0

async function loadTravelers(page = 1) {
  const currentRequest = ++requestNumber
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/travelers', {
      params: { ...appliedFilters.value, page }
    })
    if (currentRequest !== requestNumber) return
    travelers.value = data.data
    pagination.value = data.meta
  } catch (failure) {
    if (currentRequest === requestNumber) {
      error.value = failure.response?.data?.message || 'Could not load travelers.'
    }
  } finally {
    if (currentRequest === requestNumber) loading.value = false
  }
}
function applyFilters() {
  appliedFilters.value = Object.fromEntries(
    Object.entries(draftFilters).filter(([, value]) => value !== '' && value != null)
  )
  return loadTravelers(1)
}
function resetFilters() {
  for (const key of Object.keys(draftFilters)) draftFilters[key] = ''
  appliedFilters.value = {}
  return loadTravelers(1)
}
onMounted(() => loadTravelers())
```

Keep dropdown edits in `draftFilters`; do not watch them to fetch automatically. Bind Apply/Search to `applyFilters()`. Disable Apply while loading, show errors separately from empty results, and keep applied params when changing pages. The request number prevents a slower old response from replacing newer results.

## 2. Save searchable preferences

The six existing text answers still work. Exact date, duration, and numeric budget filtering need structured values in addition to display text; the API does not guess an exact date from “Next month” or a duration from a free-text answer.

```http
PUT /api/travel-preferences
Authorization: Bearer TOKEN
Content-Type: application/json
```

```json
{
  "destination": "Sylhet",
  "date": "Next month",
  "budget": "BDT 5000–10000",
  "style": "Adventure",
  "companions": "Friends",
  "interests": "Photography",
  "travel_start": "2027-01-01",
  "travel_end": "2027-01-04",
  "duration_days": 4,
  "min_budget": 5000,
  "max_budget": 10000
}
```

This is a full replacement. Omitted structured fields are cleared to avoid retaining stale date/budget data when answers change. Both availability dates must be supplied together; end must not precede start. Duration is 1–365. Budget cannot be negative or reversed. Unknown/open-ended budget limits can be null. To keep structured values when resubmitting the old questionnaire, include them from `GET /user`.

## 3. Make the two hero buttons useful

- **Find a Travel Mate**: Vue routes to a traveler results page (suggested `/travel-mates`), which calls `GET /travelers` before Apply, then calls it with selected filters after Apply.
- **Create a Trip**: Vue routes to a create form (suggested `/trips/new`); unauthenticated visitors first go to login. Submit the form to `POST /trips`, then navigate to the returned trip id or `/user/trips` view.
- **Find Trips / Search Trips**: use `GET /trips`, with the same destination/date/budget/duration/style params. Budget here is the trip's **per-person budget**, not an interval. Age filters are only for travelers.

Create example:

```json
{
  "title": "Sylhet tea garden weekend",
  "destination": "Sylhet",
  "description": "A small group exploring tea gardens and local food.",
  "start_date": "2027-01-01",
  "end_date": "2027-01-04",
  "budget": 7500,
  "travel_style": "Adventure",
  "max_travelers": 4,
  "status": "open"
}
```

Use today or future dates when creating. HTTP 201 returns `{data: {id, title, destination, description, start_date, end_date, duration_days, budget, currency, travel_style, max_travelers, status, organizer}}`. Duration is calculated inclusively: Jan 1–4 = 4 days. Submitted owner ids and duration are ignored; the server derives them.

`PATCH /trips/{id}` accepts any subset of creation fields. Only the owner can update it. Send `{ "status": "cancelled" }` to cancel, or `draft`, `open`, `completed` to change state. Non-open trips remain in `GET /user/trips` but disappear from public search and counts. Trip creation does not automatically change the organizer's travel preferences. This API does not yet implement joining trips, chat, payment, or bookings.

## 4. Profile picture upload and shared UI state

In Postman: `POST /user/photo` → Authorization Bearer → Body **form-data** → key `photo` → change type from Text to **File** → choose an image. Do not manually set Content-Type; Postman supplies the multipart boundary.

Allowed: JPEG, PNG, WebP, at most 5 MB and 6000×6000 pixels. SVG/non-images are rejected. Upload replaces the old stored image. `DELETE /user/photo` removes it.

Upload returns `{ "user": {..., "profile_photo_url": "http://127.0.0.1:8000/api/travelers/1/photo?v=..."} }`. The same field is included in login/register, `GET /user`, profile update, public traveler results, and trip organizers. Null means use your existing avatar fallback. The URL changes after replacement to refresh `<img>` elements. Use `profile_photo_url`, **not** the raw `profile_photo` storage path.

Example upload helper (fetch avoids your Axios instance's default JSON Content-Type):

```js
const body = new FormData()
body.append('photo', selectedFile)
const response = await fetch('http://127.0.0.1:8000/api/user/photo', {
  method: 'POST',
  headers: {
    Accept: 'application/json',
    Authorization: `Bearer ${localStorage.getItem('token')}`
  },
  body
})
const data = await response.json()
if (!response.ok) throw new Error(data.message || 'Upload failed')
setCurrentUser(data.user)
```

Create a shared Vue module (suggested `src/state/account.js`) and import its ref in navbar, edit profile, profile, etc. Updating localStorage alone does not update components reactively:

```js
import { ref } from 'vue'
export const currentUser = ref(null)
export function setCurrentUser(user) {
  currentUser.value = user
  if (user) localStorage.setItem('user', JSON.stringify(user))
  else localStorage.removeItem('user')
}
```

Call `setCurrentUser` after login, register, `GET /user` on app startup, profile save, and photo upload/removal. On logout call `setCurrentUser(null)` and clear the token as before. Render `<img :src="currentUser?.profile_photo_url || fallbackAvatar">` everywhere for the signed-in user. For traveler cards use each returned traveler's URL. Add file input and upload/remove pending/error states in the existing edit section; preserve the page design.

## 5. Dynamic homepage statistics

```http
GET /api/stats
```

```json
{"data":{"travelers":12,"trips":12,"destinations":6}}
```

Counts are actual database queries, with no hardcoded `1200+` values:

- `travelers`: eligible, non-blocked, non-admin accounts.
- `trips`: open trips belonging to eligible travelers.
- `destinations`: distinct destination strings among those open trips.

Refetch on homepage mount and after creating/changing a trip; these are request-time values, not WebSocket live updates. No “+” suffix is necessary for exact counts. Closed/cancelled/draft trips are excluded. Open status is explicit; elapsed dates do not automatically complete a trip.

## Error/status handling

- 200: reads, successful update/removal.
- 201: trip created (existing registration also returns 201).
- 401: missing/invalid Bearer token.
- 403: modifying another user's trip or blocked account attempting a protected creation/upload.
- 404: unknown/hidden traveler, trip, or unavailable photo.
- 422: Laravel validation object with `message` and `errors` keyed by field name.
- Never treat a failed request as an empty successful search; keep error/loading/empty states separate.

## Files changed and why

| File | Responsibility |
|---|---|
| `routes/api.php` | Public discovery and protected trip/photo endpoints |
| `app/Http/Controllers/Api/TravelerController.php` | Traveler listing, combined filters, public details |
| `app/Http/Controllers/Api/TripController.php` | Search/create/read/update and owned trip listing |
| `app/Http/Controllers/Api/ProfilePhotoController.php` | Validate/store/replace/remove/serve photos |
| `app/Http/Controllers/Api/DiscoveryController.php` | Dropdown options and homepage counts |
| `app/Http/Controllers/Api/AuthController.php` | Structured preference validation while preserving six existing answers |
| `app/Http/Requests/DiscoveryRequest.php` | Bounded, validated filter and pagination input |
| `app/Http/Resources/TravelerResource.php` | Public traveler field allowlist, no private account fields |
| `app/Http/Resources/TripResource.php` | Stable trip JSON with public organizer |
| `app/Models/User.php` | Photo URL, eligible-user scope, trip relationship |
| `app/Models/TravelPreference.php` | New searchable fields and casts |
| `app/Models/Trip.php` | Trip fields, casts, relationships, public visibility |
| `database/migrations/2026_09_30_000001_add_discovery_fields_and_trips.php` | Nullable preference fields plus trips table; no existing data deletion |
| `database/seeders/DiscoveryDemoSeeder.php` | Repeatable local test data |
| `tests/Feature/DiscoveryApiTest.php` | Filtering/privacy, ownership, validation, photos, counts, demo data |
| `docs/TravelMate.postman_collection.json` | Importable requests and automatic token/id capture |
| `docs/DISCOVERY_API.md` | This contract and Vue integration guide |

Run feature tests:

```powershell
php artisan test --compact --filter="DiscoveryApi|AccountApi"
```

## Suggested frontend edits (not performed in this backend task)

1. `src/components/home/js/Search.js`: replace console logs with results navigation and the query values above.
2. New traveler and trip result views: paginated API list, draft/applied filters, loading/error/empty states.
3. `src/components/home/js/Hero.js` + `HomeHero.vue`: wire the two separate routes and fetch `/stats`.
4. `src/views/UserProfile.vue`: consume `/travelers/{id}` for someone else's public profile, `/user` for your own; add photo upload/remove in edit mode.
5. `src/components/AccountMenu.vue`: render shared currentUser.profile_photo_url.
6. `src/main.js`: add list/create/detail routes; retain `/profile` and `/edit-profile`. Do not redirect every `/profile/:id` to your own profile when adding public traveler details.
7. `src/state/account.js`: shared account ref as above.
8. Preserve the existing authentication and question-completion flow. Public results endpoints can also serve guests if your UI allows it.
