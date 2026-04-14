"""
Kilo.ai API client for food image analysis and recalculation.
Uses the OpenAI-compatible API endpoint at https://api.kilo.ai/api/gateway
"""

import base64
import json
import httpx
from config import KILO_API_KEY, KILO_API_BASE, KILO_MODEL, KILO_TEMPERATURE, KILO_MAX_TOKENS, logger


class KiloClientError(Exception):
    """Exception raised for Kilo.ai API errors."""
    pass


def _build_image_message(image_bytes: bytes, mime_type: str, prompt: str) -> dict:
    """Build a multimodal message with base64-encoded image."""
    image_b64 = base64.b64encode(image_bytes).decode("utf-8")
    return {
        "role": "user",
        "content": [
            {"type": "text", "text": prompt},
            {
                "type": "image_url",
                "image_url": {
                    "url": f"data:{mime_type};base64,{image_b64}",
                    "detail": "high"
                }
            }
        ]
    }


def _build_text_message(prompt: str) -> dict:
    """Build a text-only message."""
    return {
        "role": "user",
        "content": [{"type": "text", "text": prompt}]
    }


def _call_kilo_api(
    messages: list[dict],
    model: str = KILO_MODEL,
    temperature: float = KILO_TEMPERATURE,
    max_tokens: int = KILO_MAX_TOKENS,
) -> str:
    """
    Make a request to the Kilo.ai Gateway API.
    Returns the response text content.
    """
    if not KILO_API_KEY:
        raise KiloClientError("KILO_API_KEY is not configured.")

    url = f"{KILO_API_BASE}/chat/completions"
    
    payload = {
        "model": model,
        "messages": messages,
        "temperature": temperature,
        "max_tokens": max_tokens,
    }

    headers = {
        "Authorization": f"Bearer {KILO_API_KEY}",
        "Content-Type": "application/json"
    }

    logger.info(f"Sending request to Kilo.ai API (model: {model})...")
    
    try:
        with httpx.Client(timeout=180.0) as client:
            response = client.post(url, json=payload, headers=headers)
            
            if response.status_code != 200:
                error_detail = response.text
                logger.error(f"Kilo.ai API error: HTTP {response.status_code} - {error_detail}")
                raise KiloClientError(f"Kilo.ai API returned HTTP {response.status_code}: {error_detail}")
            
            data = response.json()
            
            # Extract response text from OpenAI-compatible format
            if not data.get("choices"):
                raise KiloClientError("Kilo.ai API response has no choices field.")
            
            response_text = data["choices"][0]["message"]["content"]
            
            # Strip markdown code fences if present (```json ... ```)
            response_text = response_text.strip()
            if response_text.startswith("```"):
                # Remove opening fence (```json or ```)
                first_newline = response_text.find("\n")
                if first_newline != -1:
                    response_text = response_text[first_newline + 1:]
                else:
                    response_text = response_text[3:]
                # Remove closing fence
                if response_text.endswith("```"):
                    response_text = response_text[:-3].strip()
            
            logger.info(f"Kilo.ai API response received: {response_text[:200]}...")
            return response_text
            
    except httpx.RequestError as e:
        raise KiloClientError(f"Failed to connect to Kilo.ai API: {e}")
    except KiloClientError:
        raise
    except Exception as e:
        raise KiloClientError(f"Unexpected error during Kilo.ai API call: {e}")


def analyze_image(image_bytes: bytes, mime_type: str, prompt: str) -> str:
    """
    Analyze a food image using Kilo.ai API.
    Returns the response text (expected to be JSON).
    """
    message = _build_image_message(image_bytes, mime_type, prompt)
    return _call_kilo_api([message])


def recalculate_text(prompt: str) -> str:
    """
    Recalculate food nutrition using Kilo.ai API (text-only).
    Returns the response text (expected to be JSON).
    """
    message = _build_text_message(prompt)
    return _call_kilo_api([message])
