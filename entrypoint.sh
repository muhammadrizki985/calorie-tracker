#!/bin/sh
set -e

echo "┌─────────────────────────────────────┐"
echo "│  Jejak Kalori — Starting Services   │"
echo "└─────────────────────────────────────┘"

# Start FastAPI backend (binds to 0.0.0.0 inside container)
echo "  📡 Backend  → http://0.0.0.0:8282"
uvicorn main:app --host 0.0.0.0 --port 8282 &

# Wait until backend is accepting connections
echo "  ⏳ Waiting for backend to be ready..."
for i in 1 2 3 4 5 6 7 8 9 10; do
    if wget -q -O /dev/null http://127.0.0.1:8282/docs 2>/dev/null; then
        echo "  ✅ Backend is ready"
        break
    fi
    sleep 1
done

# Start PHP built-in server
echo "  🌐 Frontend → http://0.0.0.0:8501"
php83 -S 0.0.0.0:8501 -t /app &

# Wait for any child to exit, then bring everything down
wait -n
echo "  ⚠️  A service stopped — shutting down"
kill 0
wait
