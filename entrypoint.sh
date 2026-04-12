#!/bin/sh
set -e

echo "┌─────────────────────────────────────┐"
echo "│  Jejak Kalori — Starting Services   │"
echo "└─────────────────────────────────────┘"

# Start FastAPI backend (binds to 0.0.0.0 inside container)
echo "  📡 Backend  → http://0.0.0.0:8282"
uvicorn main:app --host 0.0.0.0 --port 8282 &

# Start PHP built-in server
echo "  🌐 Frontend → http://0.0.0.0:8501"
php83 -S 0.0.0.0:8501 -t /app &

# Wait for any child to exit, then bring everything down
wait -n
echo "  ⚠️  A service stopped — shutting down"
kill 0
wait
