import sqlite3
import json

DB_NAME = "data/food_tracker.db"

def _get_conn():
    return sqlite3.connect(DB_NAME)

def init_db():
    conn = _get_conn()
    c = conn.cursor()
    c.execute('''
        CREATE TABLE IF NOT EXISTS meals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            file_name TEXT,
            food_name TEXT,
            calories INTEGER,
            protein INTEGER,
            carbs INTEGER,
            fat INTEGER,
            ingredients TEXT,
            image_blob BLOB
        )
    ''')
    c.execute('''
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )
    ''')
    c.execute('''
        INSERT OR IGNORE INTO settings (key, value) VALUES ('daily_calorie_goal', '2000')
    ''')
    conn.commit()
    conn.close()

def get_daily_goal():
    conn = _get_conn()
    c = conn.cursor()
    c.execute("SELECT value FROM settings WHERE key = 'daily_calorie_goal'")
    row = c.fetchone()
    conn.close()
    return int(row[0]) if row else 2000

def set_daily_goal(goal):
    conn = _get_conn()
    c = conn.cursor()
    c.execute(
        "INSERT OR REPLACE INTO settings (key, value) VALUES ('daily_calorie_goal', ?)",
        (str(goal),)
    )
    conn.commit()
    conn.close()

def get_today_meals():
    conn = _get_conn()
    c = conn.cursor()
    c.execute('''
        SELECT id, file_name, food_name, calories, protein, carbs, fat, ingredients, image_blob
        FROM meals WHERE DATE(timestamp) = DATE('now')
        ORDER BY timestamp DESC
    ''')
    rows = c.fetchall()
    conn.close()
    return rows

def get_all_meals():
    conn = _get_conn()
    c = conn.cursor()
    c.execute('''
        SELECT id, timestamp, file_name, food_name, calories, protein, carbs, fat, ingredients, image_blob
        FROM meals ORDER BY timestamp DESC
    ''')
    rows = c.fetchall()
    conn.close()
    return rows

def insert_meal(file_name, food_name, calories, protein, carbs, fat, ingredients, image_blob):
    conn = _get_conn()
    c = conn.cursor()
    ingredients_json = json.dumps(ingredients)
    c.execute('''
        INSERT INTO meals (file_name, food_name, calories, protein, carbs, fat, ingredients, image_blob)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ''', (file_name, food_name, calories, protein, carbs, fat, ingredients_json, image_blob))
    conn.commit()
    conn.close()

def update_meal(meal_id, food_name, calories, protein, carbs, fat, ingredients):
    conn = _get_conn()
    c = conn.cursor()
    ingredients_json = json.dumps(ingredients)
    c.execute('''
        UPDATE meals
        SET food_name = ?, calories = ?, protein = ?, carbs = ?, fat = ?, ingredients = ?
        WHERE id = ?
    ''', (food_name, calories, protein, carbs, fat, ingredients_json, meal_id))
    conn.commit()
    conn.close()

def delete_meal(meal_id):
    conn = _get_conn()
    c = conn.cursor()
    c.execute('DELETE FROM meals WHERE id = ?', (meal_id,))
    conn.commit()
    conn.close()

def clear_database():
    conn = _get_conn()
    c = conn.cursor()
    c.execute('DELETE FROM meals')
    conn.commit()
    conn.close()
