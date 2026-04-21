import json
import time
from fastapi import FastAPI, File, Form, UploadFile, HTTPException
from pydantic import BaseModel
from typing import Optional

from config import app, client, GEMINI_MODEL, GEMINI_TEMPERATURE, GEMINI_MIME_TYPE, API_HOST, API_PORT, logger, AI_PROVIDER
from config import KILO_API_KEY
from prompts import ANALYZE_IMAGE_PROMPT, RECALCULATE_PROMPT_TEMPLATE, build_analyze_prompt, build_recalculate_prompt
from google.genai import types
from kilo_client import analyze_image as kilo_analyze_image, recalculate_text as kilo_recalculate_text, KiloClientError

MAX_RETRIES = 3
RETRY_DELAY = 1.0

class RecalculateRequest(BaseModel):
    food_name: str
    ingredients: Optional[list] = None


def _try_gemini_analyze(image_bytes: bytes, image_content_type: str, prompt_text: str) -> str:
    """Try Gemini analyze, return response text. Raises exception on failure."""
    response = client.models.generate_content(
        model=GEMINI_MODEL,
        contents=[
            types.Part.from_bytes(data=image_bytes, mime_type=image_content_type),
            prompt_text
        ],
        config=types.GenerateContentConfig(
            response_mime_type=GEMINI_MIME_TYPE,
            temperature=GEMINI_TEMPERATURE,
        )
    )
    return response.text


def _try_gemini_recalculate(prompt_text: str) -> str:
    """Try Gemini recalculate, return response text. Raises exception on failure."""
    response = client.models.generate_content(
        model=GEMINI_MODEL,
        contents=prompt_text,
        config=types.GenerateContentConfig(
            response_mime_type=GEMINI_MIME_TYPE,
            temperature=GEMINI_TEMPERATURE,
        )
    )
    return response.text


def _analyze_with_provider(provider: str, image_bytes: bytes, image_content_type: str, prompt_text: str) -> str:
    """Analyze image with specified provider, with optional fallback."""
    last_error = None
    
    # Try primary provider
    providers_to_try = [provider]
    if provider == "auto":
        providers_to_try = ["gemini", "kilo"]
    
    for prov in providers_to_try:
        try:
            if prov == "gemini":
                logger.info(f"Using Gemini API (primary)")
                for attempt in range(MAX_RETRIES):
                    try:
                        logger.info(f"Sending request to Gemini API ({GEMINI_MODEL})... (attempt {attempt + 1}/{MAX_RETRIES})")
                        return _try_gemini_analyze(image_bytes, image_content_type, prompt_text)
                    except Exception as api_error:
                        last_error = api_error
                        error_str = str(api_error).lower()
                        if '503' in error_str or 'high demand' in error_str or 'unavailable' in error_str:
                            if attempt < MAX_RETRIES - 1:
                                logger.warning(f"Gemini API unavailable (attempt {attempt + 1}/{MAX_RETRIES}), retrying in {RETRY_DELAY}s...")
                                time.sleep(RETRY_DELAY)
                                continue
                        logger.error(f"Gemini API error on attempt {attempt + 1}: {api_error}")
                        raise
            
            elif prov == "kilo":
                if not KILO_API_KEY:
                    logger.warning("Kilo.ai skipped: KILO_API_KEY not configured")
                    continue
                logger.info(f"Using Kilo.ai API (fallback from Gemini)")
                return kilo_analyze_image(image_bytes, image_content_type, prompt_text)
        
        except Exception as e:
            last_error = e
            logger.warning(f"Provider '{prov}' failed: {e}")
            continue
    
    # All providers failed
    raise last_error


def _recalculate_with_provider(provider: str, prompt_text: str) -> str:
    """Recalculate text with specified provider, with optional fallback."""
    last_error = None
    
    # Try primary provider
    providers_to_try = [provider]
    if provider == "auto":
        providers_to_try = ["gemini", "kilo"]
    
    for prov in providers_to_try:
        try:
            if prov == "gemini":
                logger.info(f"Using Gemini API (primary)")
                for attempt in range(MAX_RETRIES):
                    try:
                        logger.info(f"Sending request to Gemini API ({GEMINI_MODEL})... (attempt {attempt + 1}/{MAX_RETRIES})")
                        return _try_gemini_recalculate(prompt_text)
                    except Exception as api_error:
                        last_error = api_error
                        error_str = str(api_error).lower()
                        if '503' in error_str or 'high demand' in error_str or 'unavailable' in error_str:
                            if attempt < MAX_RETRIES - 1:
                                logger.warning(f"Gemini API unavailable (attempt {attempt + 1}/{MAX_RETRIES}), retrying in {RETRY_DELAY}s...")
                                time.sleep(RETRY_DELAY)
                                continue
                        logger.error(f"Gemini API error on attempt {attempt + 1}: {api_error}")
                        raise
            
            elif prov == "kilo":
                if not KILO_API_KEY:
                    logger.warning("Kilo.ai skipped: KILO_API_KEY not configured")
                    continue
                logger.info(f"Using Kilo.ai API (fallback from Gemini)")
                return kilo_recalculate_text(prompt_text)
        
        except Exception as e:
            last_error = e
            logger.warning(f"Provider '{prov}' failed: {e}")
            continue
    
    # All providers failed
    raise last_error

@app.post("/analyze")
async def analyze_food(image: UploadFile = File(...), additional_info: Optional[str] = Form(None)):
    logger.info(f"Received image: {image.filename} ({image.content_type})")
    if additional_info:
        logger.info(f"Additional info provided: {additional_info}")

    if not image.content_type.startswith("image/"):
        logger.warning(f"Invalid file type: {image.content_type}")
        raise HTTPException(status_code=400, detail="File provided is not an image.")

    try:
        image_bytes = await image.read()
        logger.info(f"Image loaded, size: {len(image_bytes)} bytes")

        # Build prompt with optional additional info
        prompt_text = build_analyze_prompt(additional_info or "")
        logger.info(f"Using AI provider: {AI_PROVIDER}")
        logger.info(f"Using prompt with additional_info={'yes' if additional_info else 'no'}")

        # Use provider routing logic
        response_text = _analyze_with_provider(AI_PROVIDER, image_bytes, image.content_type, prompt_text)
        logger.info(f"API response received: {response_text[:200]}...")

        result = json.loads(response_text)
        logger.info(f"Analysis complete: {result.get('nama_makanan', 'Unknown')} - {result.get('total_kalori', 0)} kcal")
        return result

    except json.JSONDecodeError as e:
        logger.error(f"JSON decode error: {e}")
        raise HTTPException(status_code=500, detail="The model did not return a valid JSON format.")
    except HTTPException:
        raise
    except KiloClientError as e:
        logger.error(f"Kilo.ai error during analysis: {e}")
        raise HTTPException(status_code=500, detail=f"Kilo.ai analysis failed: {str(e)}")
    except Exception as e:
        logger.error(f"Error during analysis: {e}")
        if '503' in str(e) or 'high demand' in str(e).lower():
            raise HTTPException(status_code=503, detail="AI API is currently unavailable. Please try again in a few moments.")
        raise HTTPException(status_code=500, detail=f"An error occurred: {str(e)}")

@app.post("/recalculate")
async def recalculate_food(request: RecalculateRequest):
    logger.info(f"Recalculate request for: {request.food_name}")
    try:
        prompt_text = build_recalculate_prompt(request.food_name, request.ingredients)
        logger.info(f"Using AI provider: {AI_PROVIDER}")
        logger.info(f"Using prompt with ingredients={'yes' if request.ingredients else 'no'}")

        # Use provider routing logic
        response_text = _recalculate_with_provider(AI_PROVIDER, prompt_text)
        logger.info(f"Raw API response: {response_text}")

        result = json.loads(response_text)
        logger.info(f"Parsed JSON keys: {list(result.keys())}")
        logger.info(f"Parsed JSON: {result}")

        safe_result = {
            "nama_makanan": result.get("nama_makanan", request.food_name),
            "bahan_makanan": result.get("bahan_makanan", []),
            "total_kalori": result.get("total_kalori", 0),
            "protein_g": result.get("protein_g", 0),
            "karbohidrat_g": result.get("karbohidrat_g", 0),
            "lemak_g": result.get("lemak_g", 0),
        }

        logger.info(f"Recalculation complete: {safe_result['nama_makanan']} - {safe_result['total_kalori']} kcal")
        return safe_result

    except json.JSONDecodeError as e:
        logger.error(f"JSON decode error: {e}")
        raise HTTPException(status_code=500, detail="The model did not return a valid JSON format.")
    except HTTPException:
        raise
    except KiloClientError as e:
        logger.error(f"Kilo.ai error during recalculation: {e}")
        raise HTTPException(status_code=500, detail=f"Kilo.ai recalculation failed: {str(e)}")
    except Exception as e:
        logger.error(f"Error during recalculation: {e}")
        import traceback
        logger.error(traceback.format_exc())
        if '503' in str(e) or 'high demand' in str(e).lower():
            raise HTTPException(status_code=503, detail="AI API is currently unavailable. Please try again in a few moments.")
        raise HTTPException(status_code=500, detail=f"An error occurred: {str(e)}")

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host=API_HOST, port=API_PORT)
