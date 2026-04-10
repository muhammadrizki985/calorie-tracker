# Jejak Kalori 🍱

AI-powered food calorie and macronutrient tracker using **Google Gemini** (`gemini-3.1-flash-lite-preview`). Upload a photo of your meal and get instant estimates for calories, protein, carbs, fat, and ingredient breakdowns — all in **Bahasa Indonesia**.

## Features

- 📸 **Image-based analysis** — Upload a food photo, get calorie & macro estimates via Gemini AI
- ✏️ **Text-based recalculation** — Correct food names and re-estimate nutrition from text
- 📊 **Daily goal tracking** — Set a calorie target and track your daily intake with visual progress bars
- 🕐 **Meal history** — Browse all past meals with expandable cards showing macros, ingredients, and photos
- 📱 **Mobile-first UI** — Responsive design with drag-and-drop upload, image preview, and toast notifications
- 🗄️ **SQLite storage** — Lightweight, zero-configuration database with embedded image blobs

## Architecture

```
Browser (PHP frontend)
  │
  │  AJAX POST (multipart/form-data)
  ▼
actions.php  ── compresses image via GD ──►  FastAPI backend (main.py :8282)
                                                        │
                                                        ▼
                                                 Google Gemini API
                                                        │
                                                        ▼
                                                 JSON nutrition data
                                                        │
                                                        ▼
                                              Store in SQLite → response
```

1. User uploads a food photo in the PHP frontend (`index.php`).
2. `actions.php` compresses the image (max 1024px, JPEG quality 70) and POSTs it to FastAPI `/analyze`.
3. `main.py` forwards the image to Gemini with a structured prompt requesting JSON output.
4. Gemini returns nutritional data (calories, protein, carbs, fat, ingredients).
5. `actions.php` stores the result in SQLite and returns the meal ID to the frontend.

## Prerequisites

- **Python 3.10+**
- **PHP 8.0+** with `sqlite3` and `gd` extensions enabled
- **Google Gemini API key**

## Setup

### 1. Python environment

```bash
python -m venv .venv
source .venv/bin/activate   # Linux/macOS
# .venv\Scripts\activate    # Windows
pip install -r requirements.txt
```

### 2. Configure API key

Create a `.env` file in the project root:

```
GEMINI_API_KEY=your_gemini_api_key_here
```

### 3. Verify PHP extensions

Ensure your PHP installation has the following extensions enabled:

- `sqlite3`
- `gd`
- `curl`

You can check with: `php -m | grep -E 'sqlite3|gd|curl'`

## Running the App

### Start the FastAPI backend

```bash
python main.py
# Serves on http://127.0.0.1:8282
```

### Start the PHP frontend (separate terminal)

```bash
php -S localhost:8000
# Open http://localhost:8000 in your browser
```

## API Endpoints (FastAPI)

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/analyze` | Accepts multipart `image` file. Returns JSON: `nama_makanan`, `bahan_makanan[]`, `total_kalori`, `protein_g`, `karbohidrat_g`, `lemak_g`. |
| `POST` | `/recalculate` | Accepts JSON `{ "food_name": "..." }`. Returns same schema as `/analyze`. |

## PHP Actions (AJAX)

All requests are `POST` to `actions.php` with an `action` field.

| Action | Parameters | Description |
|--------|-----------|-------------|
| `analyze` | `image` (file) | Compress image, call Gemini API, store meal in DB. Returns `meal_id`. |
| `recalculate` | `meal_id`, `food_name` | Call Gemini API with food name, update DB row. |
| `delete` | `meal_id` | Remove a single meal entry. |
| `set_goal` | `goal` (500–10000) | Update daily calorie goal. |
| `clear` | — | Delete all meal records. |

## Database Schema

**`meals`** table:

| Column | Type | Description |
|--------|------|-------------|
| `id` | INTEGER | Auto-incrementing primary key |
| `timestamp` | DATETIME | Record creation time (default: `CURRENT_TIMESTAMP`) |
| `file_name` | TEXT | Original uploaded file name |
| `food_name` | TEXT | AI-identified food name |
| `calories` | INTEGER | Total estimated calories (kkal) |
| `protein` | INTEGER | Protein (grams) |
| `carbs` | INTEGER | Carbohydrates (grams) |
| `fat` | INTEGER | Fat (grams) |
| `ingredients` | TEXT | JSON array of ingredient strings with weight estimates |
| `image_blob` | BLOB | Compressed JPEG image data |

**`settings`** table:

| Column | Type | Description |
|--------|------|-------------|
| `key` | TEXT | Setting identifier (primary key) |
| `value` | TEXT | Setting value |
| Default row: `('daily_calorie_goal', '2000')` |

## Configuration

| Env Var / Constant | Default | Description |
|---|---|---|
| `GEMINI_API_KEY` | *(required)* | Google Gemini API key (set in `.env`) |
| `GEMINI_MODEL` | `gemini-3.1-flash-lite-preview` | Gemini model used for analysis |
| `GEMINI_MIME_TYPE` | `application/json` | Response format requested from Gemini |
| `API_HOST` | `127.0.0.1` | FastAPI bind address |
| `API_PORT` | `8282` | FastAPI port |
| `MAX_IMG_SIDE` | `1024` | Maximum image dimension in pixels (PHP compression) |
| `IMG_QUALITY` | `70` | JPEG compression quality (0–100) |

## Project Structure

```
.
├── main.py            # FastAPI backend — /analyze, /recalculate
├── config.py          # App config: Gemini client, model, host/port, logging
├── prompts.py         # Gemini prompt templates (Indonesian) for image & text analysis
├── requirements.txt   # Python dependencies
├── index.php          # PHP frontend — single-page UI with upload, goals, meal cards
├── actions.php        # PHP AJAX handler — image compression, API calls, DB operations
├── data/
│   └── food_tracker.db   # SQLite database (git-ignored)
├── backups/           # Database backups (git-ignored)
└── .env               # API key (git-ignored)
```

## Response Format

Gemini returns structured JSON in **Indonesian**:

```json
{
  "nama_makanan": "Nasi Goreng Spesial",
  "bahan_makanan": [
    "nasi putih (est. 150g)",
    "ayam suwir (est. 80g)",
    "telur ceplok (est. 50g)",
    "minyak goreng (est. 14g)",
    "kecap manis (est. 10g)"
  ],
  "total_kalori": 520,
  "protein_g": 22,
  "karbohidrat_g": 65,
  "lemak_g": 18
}
```

## Key Design Decisions

- **Temperature default**: Uses Gemini 3's default temperature of 1.0 (recommended by Google for optimal reasoning performance).
- **Image compression**: PHP compresses images before sending to Python to reduce API payload size and cost.
- **Retry logic**: Both the Python and PHP layers retry Gemini API calls up to 3 times with a 1-second delay for 503/unavailable errors.
- **Embedded images**: Meal photos are stored as BLOBs in SQLite for a self-contained, zero-infrastructure setup.
- **Indonesian language**: All prompts, UI text, and response field names are in Bahasa Indonesia, targeting Indonesian users and using Indonesian nutritional references (e.g., FatSecret Indonesia).
