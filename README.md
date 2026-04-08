# Calorie Tracker

AI-powered food calorie and macro tracker using Google Gemini.

## Setup

```bash
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
```

Create a `.env` file with your Gemini API key:

```
GEMINI_API_KEY=your_key_here
```

## Run

Start the backend:

```bash
python main.py
```

Start the frontend (in a separate terminal):

```bash
streamlit run frontend.py
```

Open `http://localhost:8501` in your browser.
