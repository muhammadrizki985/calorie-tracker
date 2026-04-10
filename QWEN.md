# Calorie Tracker — Project Context

## Project Overview

**Jejak Kalori** is an AI-powered food calorie and macronutrient tracker that uses Google Gemini (`gemini-3.1-flash-lite-preview`) to analyze food photos and estimate nutritional information. The project has a hybrid architecture:

- **Backend API** — Python FastAPI server (`main.py`) that receives food images and sends them to the Gemini API for analysis. Returns structured JSON with calorie, protein, carbs, fat, and ingredient estimates.
- **Frontend** — PHP-based single-page application (`index.php` + `actions.php`) with a polished, mobile-first UI styled in terracotta/olive/cream tones. Handles image upload, meal logging, daily calorie goals, and meal history.
- **Database** — SQLite (`data/food_tracker.db`) storing meals (with embedded image blobs) and user settings (daily calorie goal, default 2000 kcal).

There is also a Python database module (`db.py`) and a Streamlit reference in the README, but no `frontend.py` file exists in the repo — the active frontend is the PHP application.

## Key Files

| File | Purpose |
|------|---------|
| `main.py` | FastAPI backend with `/analyze` (image → Gemini) and `/recalculate` (text → Gemini) endpoints. Includes retry logic for 503 errors. |
| `config.py` | App configuration: Gemini client, model name, temperature, API host/port, logging setup. |
| `prompts.py` | Gemini prompt templates (in Indonesian) for image analysis and text-based recalculation. |
| `db.py` | Python SQLite helper module (appears unused by the PHP frontend; possibly for a Streamlit frontend that was removed). |
| `index.php` | Main PHP frontend — HTML/CSS/JS single page with upload zone, daily goal tracker, meal cards, and history. |
| `actions.php` | PHP AJAX handler for all frontend actions: analyze, recalculate, delete, set_goal, clear. Includes image compression via GD library before sending to FastAPI. |
| `requirements.txt` | Python dependencies (FastAPI, uvicorn, streamlit, google-genai, etc.). |

## Architecture

```
User (browser)
  │
  ▼
index.php  ◄─── AJAX ───►  actions.php
                                │
                                ▼  (HTTP POST multipart/JSON)
                            main.py  (FastAPI :8282)
                                │
                                ▼
                          Google Gemini API
```

1. User uploads a food photo in the PHP frontend.
2. `actions.php` compresses the image (max 1024px, JPEG quality 70) and POSTs it to FastAPI `/analyze`.
3. `main.py` forwards the image to Gemini with a structured prompt requesting JSON output.
4. Gemini returns nutritional data (calories, protein, carbs, fat, ingredients).
5. `actions.php` stores the result in SQLite and returns the meal ID to the frontend.

## Building and Running

### Prerequisites
- Python 3.10+
- PHP 8.0+ with SQLite3 and GD extensions enabled
- Google Gemini API key

### Setup

```bash
# Python virtual environment
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

# Create .env file with your API key
echo "GEMINI_API_KEY=your_key_here" > .env
```

### Running

```bash
# Terminal 1 — Start FastAPI backend
python main.py
# Serves on http://127.0.0.1:8282

# Terminal 2 — Start PHP built-in server
php -S localhost:8000
# Open http://localhost:8000 in browser
```

## API Endpoints (FastAPI)

| Method | Path | Description |
|--------|------|-------------|
| POST | `/analyze` | Accepts multipart `image` file. Returns JSON: `nama_makanan`, `bahan_makanan[]`, `total_kalori`, `protein_g`, `karbohidrat_g`, `lemak_g`. |
| POST | `/recalculate` | Accepts JSON `{ "food_name": "..." }`. Returns same schema as above. |

## PHP Actions (AJAX)

All POST requests to `actions.php` with an `action` field:

| Action | Parameters | Description |
|--------|-----------|-------------|
| `analyze` | `image` (file) | Compress image, call Gemini API, store meal in DB. |
| `recalculate` | `meal_id`, `food_name` | Call Gemini API with food name, update DB row. |
| `delete` | `meal_id` | Remove a single meal entry. |
| `set_goal` | `goal` (500–10000) | Update daily calorie goal. |
| `clear` | — | Delete all meal records. |

## Database Schema

**meals** table:
- `id`, `timestamp`, `file_name`, `food_name`, `calories`, `protein`, `carbs`, `fat`, `ingredients` (JSON), `image_blob` (BLOB)

**settings** table:
- `key` (TEXT PRIMARY KEY), `value` (TEXT)
- Default: `daily_calorie_goal` = "2000"

## Configuration

| Env Var / Constant | Default | Description |
|---|---|---|
| `GEMINI_API_KEY` | *(required)* | Google Gemini API key in `.env` file |
| `GEMINI_MODEL` | `gemini-3.1-flash-lite-preview` | Model used for analysis |
| `GEMINI_TEMPERATURE` | `0.1` | Low temperature for deterministic output |
| `API_HOST` | `127.0.0.1` | FastAPI bind address |
| `API_PORT` | `8282` | FastAPI port |
| `MAX_IMG_SIDE` | `1024` | Max image dimension (PHP side) |
| `IMG_QUALITY` | `70` | JPEG compression quality |

## Development Notes

- **Language**: The AI prompts and UI are in **Indonesian** (Bahasa Indonesia). Response field names use Indonesian (`nama_makanan`, `bahan_makanan`, etc.).
- **Image pipeline**: PHP compresses images before sending to Python to reduce API payload size and cost.
- **Retry logic**: Both the Python and PHP layers retry Gemini API calls up to 3 times with a 1-second delay for 503/unavailable errors.
- **The `db.py` module** appears to be a leftover from an earlier Streamlit-based frontend (referenced in README but `frontend.py` does not exist). The active frontend is entirely PHP-based.
- **Git-ignored**: `.env`, database file, `.venv/`, `__pycache__/`, `backups/`, `.streamlit/`.
