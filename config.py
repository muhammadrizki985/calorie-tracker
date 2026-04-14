import os
import logging
from dotenv import load_dotenv
from fastapi import FastAPI
from google import genai

load_dotenv()

# ── Provider Selection ────────────────────────────────────────────────────────
# Options: "gemini", "kilo", "auto" (auto = fallback to kilo if gemini fails)
AI_PROVIDER = os.getenv("AI_PROVIDER", "gemini").lower()

# ── Gemini Configuration ─────────────────────────────────────────────────────
if not os.environ.get("GEMINI_API_KEY"):
    raise ValueError("GEMINI_API_KEY environment variable not set.")

client = genai.Client()
app = FastAPI(title="Food Nutrition Analyzer API")

GEMINI_MODEL = os.getenv("GEMINI_MODEL", "gemini-3-flash-preview")
GEMINI_TEMPERATURE = 0.1
GEMINI_MIME_TYPE = "application/json"

# ── Kilo.ai Configuration ────────────────────────────────────────────────────
KILO_API_KEY = os.getenv("KILO_API_KEY", "")
KILO_API_BASE = "https://api.kilo.ai/api/gateway"
# Default model: Gemini 3.1 Flash Lite Preview (cheapest Google multimodal model)
KILO_MODEL = os.getenv("KILO_MODEL", "google/gemini-3.1-flash-lite-preview")
KILO_TEMPERATURE = 0.1
KILO_MAX_TOKENS = int(os.getenv("KILO_MAX_TOKENS", "8000"))

# ── Server Configuration ─────────────────────────────────────────────────────
API_HOST = "127.0.0.1"
API_PORT = 8282

# ── Logging ──────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s"
)
logger = logging.getLogger(__name__)

# Validate config at startup
if AI_PROVIDER == "kilo" and not KILO_API_KEY:
    logger.warning("KILO_API_KEY not set. Kilo.ai provider will not work.")
elif AI_PROVIDER == "auto" and not KILO_API_KEY:
    logger.warning("KILO_API_KEY not set. Auto fallback to Kilo.ai will not work.")
