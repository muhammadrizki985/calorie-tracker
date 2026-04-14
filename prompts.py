ANALYZE_IMAGE_PROMPT_BASE = (
    "Anda adalah ahli gizi profesional. Analisis gambar makanan ini dengan teliti.\n"
    "\n"
    "LANGKAH ANALISIS:\n"
    "1. Identifikasi makanan dan perkirakan porsi (kecil/sedang/besar) berdasarkan referensi visual (piring, sendok, dll).\n"
    "2. Pecah menjadi komponen bahan (nasi, lauk, sayur, minyak, saus, garnish).\n"
    "3. Untuk tiap komponen, estimasi berat dalam gram dan kalori berdasarkan porsi umum Indonesia:\n"
    "   - Nasi 1 porsi = ~150g (~180 kkal)\n"
    "   - Ayam 1 potong kecil = ~80g (~130 kkal)\n"
    "   - Minyak 1 sdt = ~5g (~45 kkal)\n"
    "4. Hitung kalori dan makro per komponen, lalu jumlahkan.\n"
    "\n"
    "CATATAN PENTING:\n"
    "- Minyak goreng = 9 kkal/g (sangat padat kalori, jangan diabaikan!)\n"
    "- Jika gambar kurang jelas, berikan estimasi konservatif\n"
    "- Perhatikan metode masak (goreng = lebih tinggi kalori daripada kukus/rebus)\n"
    "- Estimasi HARUS spesifik berdasarkan gambar yang diberikan, jangan gunakan nilai default atau perkiraan generik\n"
    "- Gunakan referensi nilai gizi makanan Indonesia (FatSecret Indonesia / database gizi umum) untuk estimasi yang akurat\n"
)

ANALYZE_IMAGE_ADDITIONAL_INFO = (
    "\nINFORMASI TAMBAHAN DARI PENGGUNA:\n"
    "Pengguna memberikan deskripsi tambahan berikut tentang makanan ini: '{additional_info}'\n"
    "Gunakan informasi ini untuk menyesuaikan estimasi Anda (misalnya: ukuran porsi, "
    "bahan tambahan, jumlah porsi, metode masak, atau detail lain yang relevan).\n"
)

ANALYZE_IMAGE_OUTPUT_FORMAT = (
    "\nOutput HANYA JSON valid:\n"
    "{\"nama_makanan\": \"...\", \"bahan_makanan\": [{\"nama\": \"...\", \"berat_g\": X, \"kalori\": X}], \"total_kalori\": X, \"protein_g\": X, \"karbohidrat_g\": X, \"lemak_g\": X}"
)

# Default prompt without additional info (for backward compatibility)
ANALYZE_IMAGE_PROMPT = (
    ANALYZE_IMAGE_PROMPT_BASE
    + "\nOutput HANYA JSON valid:\n"
    + "{\"nama_makanan\": \"...\", \"bahan_makanan\": [{\"nama\": \"...\", \"berat_g\": X, \"kalori\": X}], \"total_kalori\": X, \"protein_g\": X, \"karbohidrat_g\": X, \"lemak_g\": X}"
)

def build_analyze_prompt(additional_info: str = "") -> str:
    """Build the analyze prompt, optionally including additional user info."""
    prompt = ANALYZE_IMAGE_PROMPT_BASE
    if additional_info:
        prompt += ANALYZE_IMAGE_ADDITIONAL_INFO.format(additional_info=additional_info)
    prompt += ANALYZE_IMAGE_OUTPUT_FORMAT
    return prompt

RECALCULATE_PROMPT_TEMPLATE = (
    "Anda adalah ahli gizi profesional. Berikan estimasi kalori dan makronutrien "
    "untuk porsi standar dari makanan berikut: '{food_name}'.\n"
    "\n"
    "LANGKAH ANALISIS:\n"
    "1. Pecah makanan menjadi komponen bahan (nasi, lauk, sayur, minyak, saus, garnish).\n"
    "2. Untuk tiap komponen, estimasi berat dalam gram dan kalori berdasarkan porsi umum Indonesia:\n"
    "   - Nasi 1 porsi = ~150g (~180 kkal)\n"
    "   - Ayam 1 potong kecil = ~80g (~130 kkal)\n"
    "   - Minyak 1 sdt = ~5g (~45 kkal)\n"
    "3. Hitung kalori dan makro per komponen, lalu jumlahkan.\n"
    "\n"
    "CATATAN PENTING:\n"
    "- Minyak goreng = 9 kkal/g (sangat padat kalori, jangan diabaikan!)\n"
    "- Perhatikan metode masak (goreng = lebih tinggi kalori daripada kukus/rebus)\n"
    "- Estimasi HARUS spesifik berdasarkan makanan yang disebutkan, jangan gunakan nilai default atau perkiraan generik\n"
    "- Gunakan referensi nilai gizi makanan Indonesia (FatSecret Indonesia / database gizi umum) untuk estimasi yang akurat\n"
    "\n"
    "Output HANYA JSON valid:\n"
    "{{\"nama_makanan\": \"...\", \"bahan_makanan\": [{{\"nama\": \"...\", \"berat_g\": X, \"kalori\": X}}], \"total_kalori\": X, \"protein_g\": X, \"karbohidrat_g\": X, \"lemak_g\": X}}"
)
