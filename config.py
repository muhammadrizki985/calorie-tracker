import os
import logging
from dotenv import load_dotenv
from fastapi import FastAPI
from google import genai

load_dotenv()

if not os.environ.get("GEMINI_API_KEY"):
    raise ValueError("GEMINI_API_KEY environment variable not set.")

client = genai.Client()
app = FastAPI(title="Food Nutrition Analyzer API")

GEMINI_MODEL = "gemini-3.1-flash-lite-preview"
GEMINI_TEMPERATURE = 0.1
GEMINI_MIME_TYPE = "application/json"

API_HOST = "127.0.0.1"
API_PORT = 8282

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s - %(levelname)s - %(message)s"
)
logger = logging.getLogger(__name__)
