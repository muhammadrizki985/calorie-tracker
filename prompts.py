ANALYZE_IMAGE_PROMPT_BASE = (
    "Anda adalah ahli gizi profesional. Analisis gambar makanan ini dengan teliti dan AKURAT.\n"
    "\n"
    "LANGKAH ANALISIS:\n"
    "1. Identifikasi makanan dan perkirakan porsi berdasarkan referensi visual (piring, sendok, mangkuk, dll).\n"
    "2. Pecah menjadi komponen bahan yang TERLIHAT di gambar saja (nasi, lauk, sayur, saus, garnish).\n"
    "   - JANGAN menambahkan bahan yang tidak terlihat jelas di gambar.\n"
    "   - Minyak goreng hanya ditambahkan jika makanan JELAS digoreng, dan estimasi minyak secara konservatif.\n"
    "3. Untuk tiap komponen, estimasi berat dalam gram berdasarkan porsi umum Indonesia:\n"
    "   - Nasi putih 1 porsi = ~150g (~195 kkal, 3.6g protein, 43g karbo, 0.4g lemak)\n"
    "   - Ayam goreng 1 potong sedang = ~80g daging (~154 kkal, 20g protein, 0g karbo, 8g lemak)\n"
    "   - Tempe goreng 1 potong = ~25g (~50 kkal, 4g protein, 1g karbo, 3.5g lemak)\n"
    "   - Tahu goreng 1 potong = ~40g (~68 kkal, 5g protein, 1g karbo, 5g lemak)\n"
    "   - Telur goreng 1 butir = ~60g (~90 kkal, 6g protein, 0.5g karbo, 7g lemak)\n"
    "   - Sayur/lalapan 1 porsi = ~50g (~15 kkal)\n"
    "   - Sambal 1 sdm = ~15g (~15 kkal)\n"
    "   - Minyak goreng (jika digoreng) = ~5-10g per item (~45-90 kkal)\n"
    "4. Hitung kalori dan makro (protein, karbohidrat, lemak) per komponen.\n"
    "5. Jumlahkan semua komponen untuk mendapatkan total.\n"
    "\n"
    "VALIDASI WAJIB (lakukan sebelum output):\n"
    "- total_kalori HARUS = jumlah kalori semua bahan_makanan (jangan membulatkan terlalu jauh)\n"
    "- Cross-check: (protein_g × 4) + (karbohidrat_g × 4) + (lemak_g × 9) harus mendekati total_kalori (toleransi ±10%)\n"
    "- Jika tidak cocok, perbaiki angka sebelum output.\n"
    "\n"
    "CATATAN PENTING:\n"
    "- Jika gambar kurang jelas, berikan estimasi KONSERVATIF (lebih rendah, bukan lebih tinggi)\n"
    "- JANGAN over-estimasi. Lebih baik sedikit under-estimate daripada over-estimate.\n"
    "- Perhatikan metode masak (goreng = tambahkan minyak secukupnya, BUKAN berlebihan)\n"
    "- Gunakan referensi nilai gizi makanan Indonesia (FatSecret Indonesia, TKP Kemenkes) untuk estimasi yang akurat\n"
    "- Untuk 1 porsi makanan lengkap (nasi + lauk + sayur), total biasanya 300-600 kkal. Jika hasil Anda jauh di atas range ini, periksa ulang estimasi.\n"
)

ANALYZE_IMAGE_ADDITIONAL_INFO = (
    "\nINFORMASI TAMBAHAN DARI PENGGUNA:\n"
    "Pengguna memberikan deskripsi: '{additional_info}'\n"
    "Gunakan informasi ini HANYA untuk mengidentifikasi makanan dengan lebih akurat "
    "(misalnya: nama makanan, metode masak, jumlah porsi).\n"
    "Informasi tambahan ini BUKAN alasan untuk menaikkan estimasi kalori secara tidak proporsional. "
    "Tetap gunakan nilai gizi referensi yang akurat per komponen.\n"
)

ANALYZE_IMAGE_OUTPUT_FORMAT = (
    "\nOutput HANYA JSON valid:\n"
    "{\"nama_makanan\": \"...\", \"bahan_makanan\": [{\"nama\": \"...\", \"berat_g\": X, \"kalori\": X}], \"total_kalori\": X, \"protein_g\": X, \"karbohidrat_g\": X, \"lemak_g\": X}"
)

# Default prompt without additional info (for backward compatibility)
ANALYZE_IMAGE_PROMPT = ANALYZE_IMAGE_PROMPT_BASE + ANALYZE_IMAGE_OUTPUT_FORMAT

def build_analyze_prompt(additional_info: str = "") -> str:
    """Build the analyze prompt, optionally including additional user info."""
    prompt = ANALYZE_IMAGE_PROMPT_BASE
    if additional_info:
        prompt += ANALYZE_IMAGE_ADDITIONAL_INFO.format(additional_info=additional_info)
    prompt += ANALYZE_IMAGE_OUTPUT_FORMAT
    return prompt

def build_recalculate_prompt(food_name: str, ingredients: list = None) -> str:
    """Build the recalculate prompt, optionally including original ingredients for context."""
    if ingredients:
        ingredients_list = "\n".join(
            f"- {ing.get('nama', '?')}: {ing.get('berat_g', '?')}g ({ing.get('kalori', '?')} kkal)"
            for ing in ingredients
        )
        return RECALCULATE_WITH_INGREDIENTS_TEMPLATE.format(
            food_name=food_name,
            ingredients_list=ingredients_list,
        )
    return RECALCULATE_PROMPT_TEMPLATE.format(food_name=food_name)

RECALCULATE_PROMPT_TEMPLATE = (
    "Anda adalah ahli gizi profesional. Hitung ulang estimasi kalori dan makronutrien "
    "untuk makanan berikut: '{food_name}'.\n"
    "\n"
    "LANGKAH ANALISIS:\n"
    "1. Pecah makanan menjadi komponen bahan yang umum untuk makanan ini.\n"
    "2. Untuk tiap komponen, estimasi berat dalam gram berdasarkan porsi umum Indonesia:\n"
    "   - Nasi putih 1 porsi = ~150g (~195 kkal, 3.6g protein, 43g karbo, 0.4g lemak)\n"
    "   - Ayam goreng 1 potong sedang = ~80g daging (~154 kkal, 20g protein, 0g karbo, 8g lemak)\n"
    "   - Tempe goreng 1 potong = ~25g (~50 kkal, 4g protein, 1g karbo, 3.5g lemak)\n"
    "   - Tahu goreng 1 potong = ~40g (~68 kkal, 5g protein, 1g karbo, 5g lemak)\n"
    "   - Telur goreng 1 butir = ~60g (~90 kkal, 6g protein, 0.5g karbo, 7g lemak)\n"
    "   - Sayur/lalapan 1 porsi = ~50g (~15 kkal)\n"
    "   - Sambal 1 sdm = ~15g (~15 kkal)\n"
    "   - Minyak goreng (jika digoreng) = ~5-10g per item (~45-90 kkal)\n"
    "3. Hitung kalori dan makro (protein, karbohidrat, lemak) per komponen.\n"
    "4. Jumlahkan semua komponen untuk mendapatkan total.\n"
    "\n"
    "VALIDASI WAJIB (lakukan sebelum output):\n"
    "- total_kalori HARUS = jumlah kalori semua bahan_makanan (jangan membulatkan terlalu jauh)\n"
    "- Cross-check: (protein_g × 4) + (karbohidrat_g × 4) + (lemak_g × 9) harus mendekati total_kalori (toleransi ±10%)\n"
    "- Jika tidak cocok, perbaiki angka sebelum output.\n"
    "\n"
    "CATATAN PENTING:\n"
    "- JANGAN over-estimasi. Lebih baik sedikit under-estimate daripada over-estimate.\n"
    "- Perhatikan metode masak (goreng = tambahkan minyak secukupnya, BUKAN berlebihan)\n"
    "- Gunakan referensi nilai gizi makanan Indonesia (FatSecret Indonesia, TKP Kemenkes) untuk estimasi yang akurat\n"
    "- Untuk 1 porsi makanan lengkap (nasi + lauk + sayur), total biasanya 300-600 kkal. Jika hasil Anda jauh di atas range ini, periksa ulang estimasi.\n"
    "\n"
    "Output HANYA JSON valid:\n"
    "{{\"nama_makanan\": \"...\", \"bahan_makanan\": [{{\"nama\": \"...\", \"berat_g\": X, \"kalori\": X}}], \"total_kalori\": X, \"protein_g\": X, \"karbohidrat_g\": X, \"lemak_g\": X}}"
)

RECALCULATE_WITH_INGREDIENTS_TEMPLATE = (
    "Anda adalah ahli gizi profesional. Hitung ulang estimasi kalori dan makronutrien "
    "untuk makanan berikut: '{food_name}'.\n"
    "\n"
    "Sebelumnya, makanan ini dianalisis dan memiliki komponen berikut:\n"
    "{ingredients_list}\n"
    "\n"
    "Tugas Anda: Hitung ulang nilai gizi berdasarkan nama makanan dan komponen di atas. "
    "Anda boleh menyesuaikan komponen jika nama makanan yang dikoreksi berbeda dari analisis sebelumnya.\n"
    "\n"
    "LANGKAH ANALISIS:\n"
    "1. Gunakan komponen bahan di atas sebagai referensi awal.\n"
    "2. Untuk tiap komponen, estimasi berat dalam gram berdasarkan porsi umum Indonesia:\n"
    "   - Nasi putih 1 porsi = ~150g (~195 kkal, 3.6g protein, 43g karbo, 0.4g lemak)\n"
    "   - Ayam goreng 1 potong sedang = ~80g daging (~154 kkal, 20g protein, 0g karbo, 8g lemak)\n"
    "   - Tempe goreng 1 potong = ~25g (~50 kkal, 4g protein, 1g karbo, 3.5g lemak)\n"
    "   - Tahu goreng 1 potong = ~40g (~68 kkal, 5g protein, 1g karbo, 5g lemak)\n"
    "   - Telur goreng 1 butir = ~60g (~90 kkal, 6g protein, 0.5g karbo, 7g lemak)\n"
    "   - Sayur/lalapan 1 porsi = ~50g (~15 kkal)\n"
    "   - Sambal 1 sdm = ~15g (~15 kkal)\n"
    "   - Minyak goreng (jika digoreng) = ~5-10g per item (~45-90 kkal)\n"
    "3. Hitung kalori dan makro (protein, karbohidrat, lemak) per komponen.\n"
    "4. Jumlahkan semua komponen untuk mendapatkan total.\n"
    "\n"
    "VALIDASI WAJIB (lakukan sebelum output):\n"
    "- total_kalori HARUS = jumlah kalori semua bahan_makanan (jangan membulatkan terlalu jauh)\n"
    "- Cross-check: (protein_g × 4) + (karbohidrat_g × 4) + (lemak_g × 9) harus mendekati total_kalori (toleransi ±10%)\n"
    "- Jika tidak cocok, perbaiki angka sebelum output.\n"
    "\n"
    "CATATAN PENTING:\n"
    "- JANGAN over-estimasi. Lebih baik sedikit under-estimate daripada over-estimate.\n"
    "- Perhatikan metode masak (goreng = tambahkan minyak secukupnya, BUKAN berlebihan)\n"
    "- Gunakan referensi nilai gizi makanan Indonesia (FatSecret Indonesia, TKP Kemenkes) untuk estimasi yang akurat\n"
    "- Untuk 1 porsi makanan lengkap (nasi + lauk + sayur), total biasanya 300-600 kkal. Jika hasil Anda jauh di atas range ini, periksa ulang estimasi.\n"
    "\n"
    "Output HANYA JSON valid:\n"
    "{{\"nama_makanan\": \"...\", \"bahan_makanan\": [{{\"nama\": \"...\", \"berat_g\": X, \"kalori\": X}}], \"total_kalori\": X, \"protein_g\": X, \"karbohidrat_g\": X, \"lemak_g\": X}}"
)
