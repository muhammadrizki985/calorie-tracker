import streamlit as st
import requests
from PIL import Image
import io
import json
from datetime import datetime, timedelta

from db import init_db, get_daily_goal, set_daily_goal, get_today_meals, get_all_meals
from db import insert_meal, update_meal, delete_meal, clear_database

init_db()


# --- ANTARMUKA STREAMLIT ---
st.set_page_config(page_title="Jejak Kalori", page_icon="🍱", layout="centered")

st.markdown("""
    <style>
    #MainMenu {visibility: hidden;}
    footer {visibility: hidden;}
    header {visibility: hidden;}
    .block-container {padding-top: 1rem;}
    </style>
""", unsafe_allow_html=True)

if "uploader_key" not in st.session_state:
    st.session_state.uploader_key = 1

st.title("🍱 Jejak Kalori")
st.write("Upload foto, dapatkan estimasi kalori & makro dalam hitungan detik.")

API_URL = "http://127.0.0.1:8282/analyze"
RECALCULATE_URL = "http://127.0.0.1:8282/recalculate"

uploaded_files = st.file_uploader(
    "Pilih gambar...",
    type=["jpg", "jpeg", "png"],
    accept_multiple_files=True,
    key=st.session_state.uploader_key
)

st.info(
    "💡 **Tips:** Sertakan sendok atau garpu di foto untuk estimasi porsi yang lebih akurat."
)

if uploaded_files:
    if st.button("Analisis & Simpan Makanan", type="primary"):
        progress_text = "Memproses makanan Anda..."
        progress_bar = st.progress(0, text=progress_text)

        for index, file in enumerate(uploaded_files):
            try:
                img = Image.open(file)
                if img.mode in ("RGBA", "P"):
                    img = img.convert("RGB")

                img.thumbnail((1024, 1024))
                img_buffer = io.BytesIO()
                img.save(img_buffer, format="JPEG", quality=70, optimize=True)
                compressed_image_bytes = img_buffer.getvalue()

                files_payload = {"image": (file.name, compressed_image_bytes, "image/jpeg")}
                response = requests.post(API_URL, files=files_payload)

                if response.status_code == 200:
                    data = response.json()
                    insert_meal(
                        file_name=file.name,
                        food_name=data.get('nama_makanan', 'Makanan Tidak Dikenal'),
                        calories=int(data.get('total_kalori', 0)),
                        protein=int(data.get('protein_g', 0)),
                        carbs=int(data.get('karbohidrat_g', 0)),
                        fat=int(data.get('lemak_g', 0)),
                        ingredients=data.get('bahan_makanan', []),
                        image_blob=compressed_image_bytes
                    )
                else:
                    st.error(f"Gagal menganalisis {file.name}. Kode status: {response.status_code}")

            except Exception as e:
                st.error(f"Terjadi kesalahan saat memproses {file.name}: {str(e)}")

            progress_bar.progress((index + 1) / len(uploaded_files), text=f"Menganalisis {index + 1} dari {len(uploaded_files)} makanan...")

        progress_bar.empty()
        st.success("Analisis Selesai & Tersimpan!")
        st.session_state.uploader_key += 1
        st.rerun()

# --- PENGATURAN TARGET HARIAN ---
st.divider()
st.header("🎯 Target Harian")
goal_col1, goal_col2 = st.columns([1, 2])
with goal_col1:
    daily_goal = get_daily_goal()
    new_goal = st.number_input(
        "Target Kalori (kkal):",
        min_value=500,
        max_value=10000,
        value=daily_goal,
        step=50,
        key="daily_calorie_goal_input"
    )
    if new_goal != daily_goal:
        set_daily_goal(new_goal)
        st.rerun()

# --- DATA HARI INI ---
today_meals = get_today_meals()
today_cals = int(sum(meal[3] for meal in today_meals))
today_protein = int(sum(meal[4] for meal in today_meals))
today_carbs = int(sum(meal[5] for meal in today_meals))
today_fat = int(sum(meal[6] for meal in today_meals))
remaining = daily_goal - today_cals

st.progress(min(today_cals / daily_goal, 1.0), text=f"{today_cals} / {daily_goal} kkal")

col1, col2, col3, col4 = st.columns(4)
col1.metric("🔥 Terkonsumsi", f"{today_cals} kkal")
col2.metric("🍗 Protein", f"{today_protein} g")
col3.metric("🍚 Karbohidrat", f"{today_carbs} g")
col4.metric("🥑 Lemak", f"{today_fat} g")

if remaining >= 0:
    st.success(f"Sisa hari ini: **{remaining} kkal**")
else:
    st.error(f"Melebihi target: **{abs(remaining)} kkal**")

# --- RIWAYAT SEMUA MAKANAN ---
st.divider()
st.header("🍽️ Catatan Makan")

saved_meals = get_all_meals()

if saved_meals:
    for meal in saved_meals:
        meal_id, timestamp, file_name, food_name, cals, pro, carbs, fat, ingredients_json, image_blob = meal
        meal_time = (datetime.fromisoformat(timestamp) + timedelta(hours=7)).strftime("%H:%M")
        ingredients = json.loads(ingredients_json)

        with st.expander(f"**{food_name}** · {meal_time}", expanded=True):
            img_col, stats_col = st.columns([1, 2])

            with img_col:
                st.image(image_blob, width='stretch')

            with stats_col:
                nutrition_data = {
                    "Kalori": [f"{int(cals)} kkal"],
                    "Protein": [f"{int(pro)}g"],
                    "Karbo": [f"{int(carbs)}g"],
                    "Lemak": [f"{int(fat)}g"]
                }
                st.table(nutrition_data)
                st.markdown(f"**Bahan-bahan:** {', '.join(ingredients)}")
                st.markdown("<hr style='margin: 0.5rem 0; border: 0; border-top: 1px solid #ccc;'>", unsafe_allow_html=True)
                new_food_name = st.text_input("Koreksi Nama Makanan:", value=food_name, key=f"edit_name_{meal_id}")

                btn_col1, btn_col2 = st.columns(2)

                with btn_col1:
                    if st.button("🔄 Hitung Ulang", key=f"recalc_{meal_id}", width='stretch'):
                        with st.spinner("Menghitung ulang..."):
                            try:
                                payload = {"food_name": new_food_name}
                                res = requests.post(RECALCULATE_URL, json=payload)

                                if res.status_code == 200:
                                    new_data = res.json()
                                    update_meal(
                                        meal_id=meal_id,
                                        food_name=new_data.get('nama_makanan', new_food_name),
                                        calories=int(new_data.get('total_kalori', 0)),
                                        protein=int(new_data.get('protein_g', 0)),
                                        carbs=int(new_data.get('karbohidrat_g', 0)),
                                        fat=int(new_data.get('lemak_g', 0)),
                                        ingredients=new_data.get('bahan_makanan', [])
                                    )
                                    st.rerun()
                                else:
                                    st.error(f"Gagal menghitung ulang. Kode status: {res.status_code}")
                            except Exception as e:
                                st.error(f"Terjadi kesalahan: {str(e)}")

                with btn_col2:
                    if st.button("❌ Hapus", key=f"del_{meal_id}", width='stretch'):
                        delete_meal(meal_id)
                        st.rerun()
else:
    st.info("Belum ada makanan yang tersimpan. Unggah beberapa gambar di atas untuk memulai!")

st.divider()
if st.button("🗑️ Hapus Semua Catatan Makan", type="secondary"):
    clear_database()
    st.rerun()
