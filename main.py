import json
from fastapi import FastAPI, File, UploadFile, HTTPException
from pydantic import BaseModel

from config import app, client, GEMINI_MODEL, GEMINI_TEMPERATURE, GEMINI_MIME_TYPE, API_HOST, API_PORT, logger
from prompts import ANALYZE_IMAGE_PROMPT, RECALCULATE_PROMPT_TEMPLATE
from google.genai import types

class RecalculateRequest(BaseModel):
    food_name: str

@app.post("/analyze")
async def analyze_food(image: UploadFile = File(...)):
    logger.info(f"Received image: {image.filename} ({image.content_type})")

    if not image.content_type.startswith("image/"):
        logger.warning(f"Invalid file type: {image.content_type}")
        raise HTTPException(status_code=400, detail="File provided is not an image.")

    try:
        image_bytes = await image.read()
        logger.info(f"Image loaded, size: {len(image_bytes)} bytes")

        logger.info(f"Sending request to Gemini API ({GEMINI_MODEL})...")
        response = client.models.generate_content(
            model=GEMINI_MODEL,
            contents=[
                types.Part.from_bytes(data=image_bytes, mime_type=image.content_type),
                ANALYZE_IMAGE_PROMPT
            ],
            config=types.GenerateContentConfig(
                response_mime_type=GEMINI_MIME_TYPE,
                temperature=GEMINI_TEMPERATURE,
            )
        )
        logger.info(f"API response received: {response.text[:200]}...")

        result = json.loads(response.text)
        logger.info(f"Analysis complete: {result.get('nama_makanan', 'Unknown')} - {result.get('total_kalori', 0)} kcal")
        return result

    except json.JSONDecodeError as e:
        logger.error(f"JSON decode error: {e}")
        raise HTTPException(status_code=500, detail="The model did not return a valid JSON format.")
    except Exception as e:
        logger.error(f"Error during analysis: {e}")
        raise HTTPException(status_code=500, detail=f"An error occurred: {str(e)}")

@app.post("/recalculate")
async def recalculate_food(request: RecalculateRequest):
    logger.info(f"Recalculate request for: {request.food_name}")
    try:
        prompt_text = RECALCULATE_PROMPT_TEMPLATE.format(food_name=request.food_name)

        logger.info(f"Sending request to Gemini API ({GEMINI_MODEL})...")
        response = client.models.generate_content(
            model=GEMINI_MODEL,
            contents=prompt_text,
            config=types.GenerateContentConfig(
                response_mime_type=GEMINI_MIME_TYPE,
                temperature=GEMINI_TEMPERATURE,
            )
        )
        logger.info(f"Raw API response: {response.text}")

        result = json.loads(response.text)
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
    except Exception as e:
        logger.error(f"Error during recalculation: {e}")
        import traceback
        logger.error(traceback.format_exc())
        raise HTTPException(status_code=500, detail=f"An error occurred: {str(e)}")

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host=API_HOST, port=API_PORT)
