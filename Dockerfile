FROM alpine:3.21

# Install PHP 8.3 + GD + SQLite + cURL
RUN apk add --no-cache \
    php83 php83-gd php83-sqlite3 php83-curl php83-mbstring php83-session php83-fileinfo \
    python3 py3-pip

# Create app directory
WORKDIR /app

# Increase upload limits (PHP built-in server defaults are tiny)
RUN echo 'upload_max_filesize = 50M' >> /etc/php83/php.ini \
    && echo 'post_max_size = 55M' >> /etc/php83/php.ini
COPY . .

# Install Python dependencies
RUN pip3 install --no-cache-dir --break-system-packages -r requirements.txt

# Ensure data and backup dirs exist
RUN mkdir -p data backups

# Entrypoint starts both servers
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
